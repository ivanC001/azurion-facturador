<?php

namespace App\Infrastructure\Sunat;

use App\Domain\Documentos\Enums\DocumentStatus;
use App\Models\Documento;
use App\Domain\Pdf\Contracts\DocumentPdfGenerator;
use App\Domain\Sunat\Contracts\SunatSender;
use App\Support\Tenants\TenantContext;
use DateTime;
use Greenter\Model\Client\Client;
use Greenter\Model\Company\Address;
use Greenter\Model\Company\Company;
use Greenter\Model\Despatch\AdditionalDoc;
use Greenter\Model\Despatch\Despatch;
use Greenter\Model\Despatch\DespatchDetail;
use Greenter\Model\Despatch\Direction;
use Greenter\Model\Despatch\Driver;
use Greenter\Model\Despatch\Shipment;
use Greenter\Model\Despatch\Transportist;
use Greenter\Model\Despatch\Vehicle;
use Greenter\Model\DocumentInterface;
use Greenter\Model\Sale\Charge;
use Greenter\Model\Sale\Cuota;
use Greenter\Model\Sale\Detraction;
use Greenter\Model\Sale\Invoice;
use Greenter\Model\Sale\Legend;
use Greenter\Model\Sale\Note;
use Greenter\Model\Sale\Prepayment;
use Greenter\Model\Sale\SaleDetail;
use Greenter\Model\Sale\SalePerception;
use Greenter\Model\Sale\FormaPagos\FormaPagoContado;
use Greenter\Model\Sale\FormaPagos\FormaPagoCredito;
use Greenter\Report\XmlUtils;
use Greenter\See;
use Greenter\Ws\Services\SunatEndpoints;
use Illuminate\Support\Facades\Crypt;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Storage;
use Luecano\NumeroALetras\NumeroALetras;

final class GreenterSunatSender implements SunatSender
{
    private const TEST_SOL_RUC = '20000000001';
    private const TEST_SOL_USER = 'MODDATOS';
    private const TEST_SOL_PASSWORD = 'moddatos';

    public function __construct(
        private readonly TenantContext $tenantContext,
        private readonly DocumentPdfGenerator $documentPdfGenerator,
    ) {}

    public function send(Documento $documento): array
    {
        try {
            $tenant = $this->tenantContext->required();
            $payload = is_array($documento->payload) ? $documento->payload : [];

            $document = $this->buildGreenterDocument($documento, $payload);
            $cfg = $this->resolveConfig($payload, $tenant->ruc, $tenant->sunatMode, (string) $documento->tipo_documento);

            $see = new See();
            $see->setService($cfg['service']);
            $see->setCertificate($cfg['certificate_pem']);
            $see->setClaveSOL($cfg['sol_ruc'], $cfg['sol_user'], $cfg['sol_password']);

            $xmlSigned = (string) $see->getXmlSigned($document);
            $result = $see->send($document);

            if ($result === null) {
                throw new \RuntimeException('Greenter returned null response from SUNAT.');
            }

            $hash = (new XmlUtils())->getHashSign($xmlSigned);
            $hash = $hash !== '' ? $hash : hash('sha256', $xmlSigned);

            if (! $result->isSuccess()) {
                $error = $result->getError();

                return [
                    'estado' => DocumentStatus::REJECTED->value,
                    'ticket' => null,
                    'hash' => $hash,
                    'mensaje' => $error?->getMessage() ?? 'SUNAT returned an unknown error.',
                    'codigo_error' => $error?->getCode(),
                    'xml' => $xmlSigned,
                    'pdf' => $this->documentPdfGenerator->generate($this->buildPdfContext(
                        payload: $payload,
                        documento: $documento,
                        config: $cfg,
                        estado: DocumentStatus::REJECTED->value,
                        hash: $hash,
                        mensaje: $error?->getMessage() ?? 'SUNAT returned an unknown error.',
                    )),
                ];
            }

            $cdrResponse = method_exists($result, 'getCdrResponse') ? $result->getCdrResponse() : null;
            $cdrZip = method_exists($result, 'getCdrZip') ? $result->getCdrZip() : null;
            $accepted = $cdrResponse?->isAccepted() ?? true;

            return [
                'estado' => $accepted ? DocumentStatus::ACCEPTED->value : DocumentStatus::REJECTED->value,
                'ticket' => null,
                'hash' => $hash,
                'mensaje' => $cdrResponse?->getDescription() ?? 'SUNAT processed document.',
                'codigo_error' => $cdrResponse?->getCode(),
                'xml' => $xmlSigned,
                'cdr' => $cdrZip,
                'pdf' => $this->documentPdfGenerator->generate($this->buildPdfContext(
                    payload: $payload,
                    documento: $documento,
                    config: $cfg,
                    estado: $accepted ? DocumentStatus::ACCEPTED->value : DocumentStatus::REJECTED->value,
                    hash: $hash,
                    mensaje: $cdrResponse?->getDescription() ?? 'SUNAT processed document.',
                )),
            ];
        } catch (\Throwable $exception) {
            return [
                'estado' => DocumentStatus::ERROR->value,
                'ticket' => null,
                'mensaje' => $exception->getMessage(),
                'codigo_error' => (string) $exception->getCode(),
            ];
        }
    }

    private function buildGreenterDocument(Documento $documento, array $payload): DocumentInterface
    {
        $tipoDoc = (string) ($documento->tipo_documento ?? data_get($payload, 'documento.tipo', '01'));

        return match ($tipoDoc) {
            '01', '03' => $this->buildInvoice($documento, $payload, $tipoDoc),
            '07', '08' => $this->buildNote($documento, $payload, $tipoDoc),
            '09' => $this->buildDespatch($documento, $payload),
            default => throw new \InvalidArgumentException('Document type not supported for SUNAT Beta send: '.$tipoDoc),
        };
    }

    private function buildInvoice(Documento $documento, array $payload, string $tipoDoc): Invoice
    {
        $company = $this->buildCompany($payload);
        $client = $this->buildClient($payload);

        $detailRows = (array) data_get($payload, 'detalles', []);
        $details = [];
        $gratuitasSum = 0.0;
        $igvGratuitasSum = 0.0;
        $iscSum = 0.0;
        $otrosTributosSum = 0.0;
        $icbperSum = 0.0;

        foreach ($detailRows as $idx => $row) {
            $qty = $this->toFloat(data_get($row, 'cantidad', 1));
            $tipAfeIgv = $this->requiredString($row, 'tip_afe_igv', 'detalles.'.$idx);
            $this->requiredString($row, 'tributo_codigo', 'detalles.'.$idx);
            $porcentajeIgv = $this->requiredFloat($row, 'porcentaje_igv', 'detalles.'.$idx);

            $valueUnit = $this->toFloat(data_get($row, 'valor_unitario', data_get($row, 'mto_valor_unitario', 0)));
            $lineBase = $this->requiredFloat($row, 'mto_valor_venta', 'detalles.'.$idx);
            $lineIgv = $this->requiredFloat($row, 'igv', 'detalles.'.$idx);
            $lineIsc = $this->toFloat(data_get($row, 'isc', 0));
            $lineIcbper = $this->toFloat(data_get($row, 'icbper', 0));
            $lineOtroTributo = $this->toFloat(data_get($row, 'otro_tributo', data_get($row, 'mto_otro_tributo', 0)));
            $lineTotalImpuestos = $this->requiredFloat($row, 'total_impuestos', 'detalles.'.$idx);

            $isGratuita = $this->isGratuitaAfectacion($tipAfeIgv);
            $lineTotal = $this->requiredFloat($row, 'total', 'detalles.'.$idx);
            $priceUnit = $this->toFloat(data_get(
                $row,
                'precio_unitario',
                $qty > 0 ? ($lineTotal / $qty) : $lineTotal,
            ));

            $detail = (new SaleDetail())
                ->setCodProducto((string) data_get($row, 'codigo', 'ITEM'.($idx + 1)))
                ->setUnidad((string) data_get($row, 'unidad', 'NIU'))
                ->setCantidad($qty)
                ->setDescripcion((string) data_get($row, 'descripcion', 'ITEM '.($idx + 1)))
                ->setMtoBaseIgv($lineBase)
                ->setPorcentajeIgv($porcentajeIgv)
                ->setIgv($lineIgv)
                ->setTipAfeIgv($tipAfeIgv)
                ->setTotalImpuestos($lineTotalImpuestos)
                ->setMtoValorVenta($lineBase)
                ->setMtoValorUnitario($valueUnit)
                ->setMtoPrecioUnitario($priceUnit);

            $codProdSunat = trim((string) data_get($row, 'codigo_sunat', data_get($row, 'cod_prod_sunat', '')));
            if ($codProdSunat !== '') {
                $detail->setCodProdSunat($codProdSunat);
            }

            if ($isGratuita) {
                $lineValorGratuito = $this->toFloat(data_get(
                    $row,
                    'mto_valor_gratuito',
                    $qty > 0 ? ($lineBase / $qty) : $lineBase,
                ));
                $detail
                    ->setMtoValorGratuito($lineValorGratuito)
                    ->setMtoValorUnitario($this->toFloat(data_get($row, 'valor_unitario', 0)))
                    ->setMtoPrecioUnitario($this->toFloat(data_get($row, 'precio_unitario', 0)));
            }

            if ($lineIsc > 0) {
                $detail
                    ->setMtoBaseIsc($this->toFloat(data_get($row, 'mto_base_isc', $lineBase)))
                    ->setPorcentajeIsc($this->toFloat(data_get($row, 'porcentaje_isc', 0)))
                    ->setIsc($lineIsc)
                    ->setTipSisIsc((string) data_get($row, 'tip_sis_isc', '01'));
            }

            if ($lineOtroTributo > 0) {
                $detail
                    ->setMtoBaseOth($this->toFloat(data_get($row, 'mto_base_oth', $lineBase)))
                    ->setPorcentajeOth($this->toFloat(data_get($row, 'porcentaje_oth', 0)))
                    ->setOtroTributo($lineOtroTributo);
            }

            if ($lineIcbper > 0) {
                $detail
                    ->setIcbper($lineIcbper)
                    ->setFactorIcbper($this->toFloat(data_get($row, 'factor_icbper', 0.50)));
            }

            $lineDiscountRows = (array) data_get($row, 'descuentos', []);
            if ($lineDiscountRows !== []) {
                $lineDiscounts = $this->buildCharges($lineDiscountRows, $lineBase);
                if ($lineDiscounts !== []) {
                    $detail->setDescuentos($lineDiscounts);
                }
            }

            $lineChargeRows = (array) data_get($row, 'cargos', []);
            if ($lineChargeRows !== []) {
                $lineCharges = $this->buildCharges($lineChargeRows, $lineBase);
                if ($lineCharges !== []) {
                    $detail->setCargos($lineCharges);
                }
            }

            $details[] = $detail;
            $igvGratuitasSum += $isGratuita ? $lineIgv : 0;
            $iscSum += $lineIsc;
            $otrosTributosSum += $lineOtroTributo;
            $icbperSum += $lineIcbper;

            if ($isGratuita) {
                $gratuitasSum += $lineBase;
            }
        }

        $currencyCode = (string) data_get($payload, 'documento.moneda', 'PEN');
        $tipoOperacion = $this->resolveTipoOperacion((array) data_get($payload, 'documento', []));

        $documentData = (array) data_get($payload, 'documento', []);
        $mtoOperGravadas = $this->requiredFloat($documentData, 'mto_oper_gravadas', 'documento');
        $mtoOperExoneradas = $this->requiredFloat($documentData, 'mto_oper_exoneradas', 'documento');
        $mtoOperInafectas = $this->requiredFloat($documentData, 'mto_oper_inafectas', 'documento');
        $mtoOperExportacion = $this->requiredFloat($documentData, 'mto_oper_exportacion', 'documento');
        $mtoOperGratuitas = $this->toFloat(data_get($payload, 'documento.mto_oper_gratuitas', $gratuitasSum));
        $mtoIgvGratuitas = $this->toFloat(data_get($payload, 'documento.mto_igv_gratuitas', $igvGratuitasSum));
        $valorVenta = $this->requiredFloat($documentData, 'valor_venta', 'documento');
        $mtoIgv = $this->requiredFloat($documentData, 'igv_total', 'documento');
        $mtoIsc = $this->toFloat(data_get($payload, 'documento.mto_isc', $iscSum));
        $mtoOtrosTributos = $this->toFloat(data_get($payload, 'documento.mto_otros_tributos', $otrosTributosSum));
        $mtoIcbper = $this->toFloat(data_get($payload, 'documento.mto_icbper', $icbperSum));
        $totalImpuestos = $this->requiredFloat($documentData, 'total_impuestos', 'documento');
        $subTotal = $this->requiredFloat($documentData, 'sub_total', 'documento');
        $documentTotal = $this->requiredFloat($documentData, 'total', 'documento');

        $legends = (array) data_get($payload, 'documento.leyendas', []);
        if ($legends === []) {
            $legendCode = $mtoOperGratuitas > 0 ? '1002' : '1000';
            $legendValue = $mtoOperGratuitas > 0
                ? 'TRANSFERENCIA GRATUITA DE UN BIEN Y/O SERVICIO PRESTADO GRATUITAMENTE'
                : $this->resolveMontoLetras($payload, $documentTotal, $currencyCode);
            $legends = [[
                'codigo' => $legendCode,
                'valor' => $legendValue,
            ]];
        }

        $legendModels = [];
        foreach ($legends as $legendRow) {
            if (! is_array($legendRow)) {
                continue;
            }
            $code = trim((string) ($legendRow['codigo'] ?? $legendRow['code'] ?? ''));
            $value = trim((string) ($legendRow['valor'] ?? $legendRow['value'] ?? ''));
            if ($code === '' || $value === '') {
                continue;
            }
            $legendModels[] = (new Legend())
                ->setCode($code)
                ->setValue($value);
        }

        $invoice = (new Invoice())
            ->setUblVersion('2.1')
            ->setTipoOperacion($tipoOperacion)
            ->setTipoDoc($tipoDoc)
            ->setSerie((string) $documento->serie)
            ->setCorrelativo((string) $documento->correlativo)
            ->setFechaEmision($this->parseDate(data_get($payload, 'documento.fecha_emision')))
            ->setTipoMoneda($currencyCode)
            ->setCompany($company)
            ->setClient($client)
            ->setMtoOperGravadas($mtoOperGravadas)
            ->setMtoOperExoneradas($mtoOperExoneradas)
            ->setMtoOperInafectas($mtoOperInafectas)
            ->setMtoOperExportacion($mtoOperExportacion)
            ->setMtoOperGratuitas($mtoOperGratuitas)
            ->setMtoIGVGratuitas($mtoIgvGratuitas)
            ->setMtoIGV($mtoIgv)
            ->setMtoISC($mtoIsc)
            ->setMtoOtrosTributos($mtoOtrosTributos)
            ->setIcbper($mtoIcbper)
            ->setTotalImpuestos($totalImpuestos)
            ->setValorVenta($valorVenta)
            ->setSubTotal($subTotal)
            ->setMtoImpVenta($documentTotal)
            ->setDetails($details)
            ->setLegends($legendModels !== [] ? $legendModels : null);

        $globalDiscountRows = (array) data_get($payload, 'documento.descuentos', []);
        if ($globalDiscountRows !== []) {
            $globalDiscounts = $this->buildCharges($globalDiscountRows, $valorVenta);
            if ($globalDiscounts !== []) {
                $invoice
                    ->setDescuentos($globalDiscounts)
                    ->setMtoDescuentos($this->sumChargeMonto($globalDiscounts))
                    ->setSumOtrosDescuentos($this->sumChargeMonto($globalDiscounts));
            }
        }

        $globalChargeRows = (array) data_get($payload, 'documento.cargos', []);
        if ($globalChargeRows !== []) {
            $globalCharges = $this->buildCharges($globalChargeRows, $valorVenta);
            if ($globalCharges !== []) {
                $invoice
                    ->setCargos($globalCharges)
                    ->setMtoCargos($this->sumChargeMonto($globalCharges))
                    ->setSumOtrosCargos($this->sumChargeMonto($globalCharges));
            }
        }

        $perception = $this->buildPerception((array) data_get($payload, 'documento.percepcion', []), $valorVenta, $documentTotal);
        if ($perception !== null) {
            $invoice->setPerception($perception);
            $invoice->setSumOtrosCargos($perception->getMto() ?? null);
        }

        $anticipos = $this->buildAnticipos((array) data_get($payload, 'documento.anticipos', []));
        if ($anticipos !== []) {
            $invoice
                ->setAnticipos($anticipos)
                ->setTotalAnticipos($this->toFloat(data_get($payload, 'documento.total_anticipos', $this->sumAnticiposTotal($anticipos))));
        }

        $detraccion = $this->buildDetraccion((array) data_get($payload, 'documento.detraccion', []));
        if ($detraccion !== null) {
            $invoice->setDetraccion($detraccion);
        }

        $paymentType = strtoupper((string) data_get($payload, 'documento.forma_pago.tipo', 'CONTADO'));
        if ($paymentType === 'CREDITO') {
            $creditMonto = $this->toFloat(data_get($payload, 'documento.forma_pago.monto', $documentTotal));
            $invoice->setFormaPago(new FormaPagoCredito(
                monto: $creditMonto,
                moneda: (string) data_get($payload, 'documento.moneda', 'PEN'),
            ));

            $cuotas = $this->buildCuotas((array) data_get($payload, 'documento.forma_pago.cuotas', []));
            if ($cuotas !== []) {
                $invoice->setCuotas($cuotas);
            }
        } else {
            $invoice->setFormaPago(new FormaPagoContado());
        }

        return $invoice;
    }

    private function resolveMontoLetras(array $payload, float $documentTotal, string $currencyCode): string
    {
        $provided = trim((string) data_get($payload, 'documento.monto_letras', ''));
        if ($provided !== '') {
            return $provided;
        }

        $currencyLabel = $this->resolveCurrencyLabel($currencyCode);
        try {
            $formatter = new NumeroALetras();

            return $formatter->toInvoice($documentTotal, 2, $currencyLabel);
        } catch (\Throwable) {
            return 'IMPORTE EN '.$currencyLabel;
        }
    }

    private function resolveCurrencyLabel(string $currencyCode): string
    {
        return match (strtoupper(trim($currencyCode))) {
            'PEN' => 'SOLES',
            'USD' => 'DOLARES',
            'EUR' => 'EUROS',
            default => strtoupper(trim($currencyCode)) !== '' ? strtoupper(trim($currencyCode)) : 'SOLES',
        };
    }

    private function buildNote(Documento $documento, array $payload, string $tipoDoc): Note
    {
        $company = $this->buildCompany($payload);
        $client = $this->buildClient($payload);

        $affectedType = (string) data_get($payload, 'documento.referencia.tipo_doc', data_get($payload, 'documento.tip_doc_afectado', '01'));
        $affectedNumber = (string) data_get($payload, 'documento.referencia.nro_doc', data_get($payload, 'documento.num_doc_afectado', 'F001-1'));
        $reasonCode = (string) data_get($payload, 'documento.codigo_motivo', $tipoDoc === '07' ? '01' : '02');
        $reasonDesc = (string) data_get($payload, 'documento.descripcion_motivo', 'AJUSTE DE DOCUMENTO');

        $invoiceLike = $this->buildInvoice($documento, $payload, $tipoDoc);

        return (new Note())
            ->setUblVersion('2.1')
            ->setTipoDoc($tipoDoc)
            ->setSerie((string) $documento->serie)
            ->setCorrelativo((string) $documento->correlativo)
            ->setFechaEmision($this->parseDate(data_get($payload, 'documento.fecha_emision')))
            ->setTipDocAfectado($affectedType)
            ->setNumDocfectado($affectedNumber)
            ->setCodMotivo($reasonCode)
            ->setDesMotivo($reasonDesc)
            ->setTipoMoneda($invoiceLike->getTipoMoneda())
            ->setCompany($company)
            ->setClient($client)
            ->setMtoOperGravadas($invoiceLike->getMtoOperGravadas())
            ->setMtoOperExoneradas($invoiceLike->getMtoOperExoneradas())
            ->setMtoOperInafectas($invoiceLike->getMtoOperInafectas())
            ->setMtoIGV($invoiceLike->getMtoIGV())
            ->setTotalImpuestos($invoiceLike->getTotalImpuestos())
            ->setValorVenta($invoiceLike->getValorVenta())
            ->setSubTotal($invoiceLike->getSubTotal())
            ->setMtoImpVenta($invoiceLike->getMtoImpVenta())
            ->setDetails($invoiceLike->getDetails())
            ->setLegends($invoiceLike->getLegends());
    }

    private function buildDespatch(Documento $documento, array $payload): Despatch
    {
        $guia = (array) data_get($payload, 'guia', []);
        $traslado = (array) data_get($payload, 'traslado', data_get($payload, 'documento.traslado', []));

        $envio = (new Shipment())
            ->setModTraslado((string) data_get($traslado, 'modalidad', '01'))
            ->setCodTraslado((string) data_get($traslado, 'motivo_codigo', '01'))
            ->setDesTraslado((string) data_get($traslado, 'motivo_descripcion', 'VENTA'))
            ->setFecTraslado($this->parseDate(data_get($traslado, 'fecha_inicio', data_get($payload, 'documento.fecha_traslado'))))
            ->setPesoTotal($this->toFloat(data_get($traslado, 'peso_total', 1)))
            ->setUndPesoTotal((string) data_get($traslado, 'unidad_peso', 'KGM'))
            ->setNumBultos((int) data_get($traslado, 'numero_bultos', 1))
            ->setLlegada($this->buildDirection((array) data_get($traslado, 'llegada', []), '150101', 'DIRECCION DE LLEGADA'))
            ->setPartida($this->buildDirection((array) data_get($traslado, 'partida', []), '150101', 'DIRECCION DE PARTIDA'));

        $transportista = $this->buildTransportist((array) data_get($traslado, 'transportista', []));
        if ($transportista !== null) {
            $envio->setTransportista($transportista);
        }

        $vehiculo = $this->buildVehicle((array) data_get($traslado, 'vehiculo', []));
        if ($vehiculo !== null) {
            $envio->setVehiculo($vehiculo);
        }

        $drivers = $this->buildDrivers((array) data_get($traslado, 'conductores', []), (array) data_get($traslado, 'conductor', []));
        if ($drivers !== []) {
            $envio->setChoferes($drivers);
        }

        $despatch = (new Despatch())
            ->setVersion((string) data_get($guia, 'version', '2022'))
            ->setTipoDoc('09')
            ->setSerie((string) $documento->serie)
            ->setCorrelativo((string) $documento->correlativo)
            ->setFechaEmision($this->parseDate(data_get($payload, 'documento.fecha_emision')))
            ->setCompany($this->buildCompany($payload))
            ->setDestinatario($this->buildClient($payload))
            ->setObservacion((string) data_get($guia, 'observacion', data_get($payload, 'documento.observacion', 'GUIA DE REMISION')))
            ->setEnvio($envio)
            ->setDetails($this->buildDespatchDetails((array) data_get($payload, 'detalles', [])));

        $relatedDocs = $this->buildAdditionalDocs((array) data_get($payload, 'documento.documentos_relacionados', []), $payload);
        if ($relatedDocs !== []) {
            $despatch->setAddDocs($relatedDocs);
        }

        return $despatch;
    }

    private function buildDirection(array $data, string $defaultUbigeo, string $defaultAddress): Direction
    {
        return (new Direction(
            (string) ($data['ubigeo'] ?? $defaultUbigeo),
            (string) ($data['direccion'] ?? $defaultAddress),
        ))
            ->setCodLocal((string) ($data['cod_local'] ?? '0000'))
            ->setRuc(isset($data['ruc']) ? (string) $data['ruc'] : null);
    }

    private function buildTransportist(array $data): ?Transportist
    {
        $numDoc = (string) ($data['num_doc'] ?? $data['ruc'] ?? '');
        $name = (string) ($data['razon_social'] ?? $data['nombre'] ?? '');

        if ($numDoc === '' && $name === '') {
            return null;
        }

        return (new Transportist())
            ->setTipoDoc((string) ($data['tipo_doc'] ?? '6'))
            ->setNumDoc($numDoc)
            ->setRznSocial($name !== '' ? $name : 'TRANSPORTISTA')
            ->setNroMtc(isset($data['nro_mtc']) ? (string) $data['nro_mtc'] : null);
    }

    private function buildVehicle(array $data): ?Vehicle
    {
        $placa = (string) ($data['placa'] ?? '');
        if ($placa === '') {
            return null;
        }

        return (new Vehicle())
            ->setPlaca($placa)
            ->setNroCirculacion(isset($data['nro_circulacion']) ? (string) $data['nro_circulacion'] : null)
            ->setNroAutorizacion(isset($data['nro_autorizacion']) ? (string) $data['nro_autorizacion'] : null)
            ->setCodEmisor(isset($data['cod_emisor']) ? (string) $data['cod_emisor'] : null);
    }

    /**
     * @param array<int, array<string, mixed>> $drivers
     * @return array<int, Driver>
     */
    private function buildDrivers(array $drivers, array $singleDriver): array
    {
        if ($drivers === [] && $singleDriver !== []) {
            $drivers = [$singleDriver];
        }

        $items = [];
        foreach ($drivers as $driver) {
            if (! is_array($driver)) {
                continue;
            }

            $doc = (string) ($driver['num_doc'] ?? $driver['nro_doc'] ?? '');
            if ($doc === '') {
                continue;
            }

            $items[] = (new Driver())
                ->setTipo((string) ($driver['tipo'] ?? 'Principal'))
                ->setTipoDoc((string) ($driver['tipo_doc'] ?? '1'))
                ->setNroDoc($doc)
                ->setNombres((string) ($driver['nombres'] ?? 'CONDUCTOR'))
                ->setApellidos((string) ($driver['apellidos'] ?? '-'))
                ->setLicencia((string) ($driver['licencia'] ?? ''));
        }

        return $items;
    }

    /**
     * @param array<int, array<string, mixed>> $rows
     * @return array<int, DespatchDetail>
     */
    private function buildDespatchDetails(array $rows): array
    {
        $details = [];

        foreach ($rows as $idx => $row) {
            if (! is_array($row)) {
                continue;
            }

            $details[] = (new DespatchDetail())
                ->setCodigo((string) data_get($row, 'codigo', 'ITEM'.($idx + 1)))
                ->setCodProdSunat((string) data_get($row, 'codigo_sunat', data_get($row, 'cod_prod_sunat', '')))
                ->setDescripcion((string) data_get($row, 'descripcion', 'ITEM '.($idx + 1)))
                ->setUnidad((string) data_get($row, 'unidad', 'NIU'))
                ->setCantidad($this->toFloat(data_get($row, 'cantidad', 1)));
        }

        if ($details === []) {
            $details[] = (new DespatchDetail())
                ->setCodigo('ITEM1')
                ->setDescripcion('ITEM DE GUIA')
                ->setUnidad('NIU')
                ->setCantidad(1);
        }

        return $details;
    }

    /**
     * @param array<int, array<string, mixed>> $rows
     * @return array<int, AdditionalDoc>
     */
    private function buildAdditionalDocs(array $rows, array $payload): array
    {
        $fallback = data_get($payload, 'documento.referencia.nro_doc');
        if ($rows === [] && is_string($fallback) && $fallback !== '') {
            $rows = [[
                'tipo' => data_get($payload, 'documento.referencia.tipo_doc', '01'),
                'tipo_descripcion' => 'Factura',
                'numero' => $fallback,
                'emisor' => data_get($payload, 'empresa.ruc'),
            ]];
        }

        $docs = [];
        foreach ($rows as $row) {
            if (! is_array($row)) {
                continue;
            }

            $number = (string) ($row['numero'] ?? $row['nro'] ?? '');
            if ($number === '') {
                continue;
            }

            $docs[] = (new AdditionalDoc())
                ->setTipoDesc((string) ($row['tipo_descripcion'] ?? $row['tipo_desc'] ?? 'Documento relacionado'))
                ->setTipo((string) ($row['tipo'] ?? '01'))
                ->setNro($number)
                ->setEmisor(isset($row['emisor']) ? (string) $row['emisor'] : null);
        }

        return $docs;
    }

    private function buildCompany(array $payload): Company
    {
        $addressData = (array) data_get($payload, 'empresa.direccion', []);

        $address = (new Address())
            ->setUbigueo((string) ($addressData['ubigeo'] ?? '150101'))
            ->setDepartamento((string) ($addressData['departamento'] ?? 'LIMA'))
            ->setProvincia((string) ($addressData['provincia'] ?? 'LIMA'))
            ->setDistrito((string) ($addressData['distrito'] ?? 'LIMA'))
            ->setUrbanizacion((string) ($addressData['urbanizacion'] ?? '-'))
            ->setDireccion((string) ($addressData['direccion'] ?? 'DIRECCION NO ESPECIFICADA'))
            ->setCodigoPais((string) ($addressData['codigo_pais'] ?? 'PE'))
            ->setCodLocal((string) ($addressData['cod_local'] ?? '0000'));

        return (new Company())
            ->setRuc((string) data_get($payload, 'empresa.ruc', '20000000001'))
            ->setRazonSocial((string) data_get($payload, 'empresa.razon_social', data_get($payload, 'empresa.nombre', 'EMPRESA TEST')))
            ->setNombreComercial((string) data_get($payload, 'empresa.nombre_comercial', data_get($payload, 'empresa.razon_social', 'EMPRESA TEST')))
            ->setAddress($address);
    }

    private function buildClient(array $payload): Client
    {
        $client = (new Client())
            ->setTipoDoc((string) data_get($payload, 'cliente.tipo_doc', '6'))
            ->setNumDoc((string) data_get($payload, 'cliente.num_doc', '20100070970'))
            ->setRznSocial((string) data_get($payload, 'cliente.razon_social', data_get($payload, 'cliente.nombre', 'CLIENTE TEST')));

        $direccion = trim((string) data_get($payload, 'cliente.direccion', ''));
        $ubigeo = trim((string) data_get($payload, 'cliente.ubigeo', ''));
        if ($direccion !== '' || $ubigeo !== '') {
            $client->setAddress(
                (new Address())
                    ->setUbigueo($ubigeo !== '' ? $ubigeo : '150101')
                    ->setDireccion($direccion !== '' ? $direccion : 'DIRECCION NO ESPECIFICADA')
                    ->setCodigoPais('PE')
            );
        }

        return $client;
    }

    private function resolveConfig(array $payload, string $tenantRuc, string $tenantSunatMode, string $documentType): array
    {
        $row = DB::table('configuracion_facturacion')->orderBy('id')->first();

        $serviceMode = (string) ($row->modo_sunat ?? $tenantSunatMode ?: 'beta');
        $service = $this->resolveSunatEndpoint($serviceMode, $documentType);

        $solRuc = (string) ($row->ruc_sol ?? data_get($payload, 'empresa.sunat.ruc_sol', self::TEST_SOL_RUC));
        $solUser = (string) ($row->usuario_sol ?? data_get($payload, 'empresa.sunat.usuario_sol', self::TEST_SOL_USER));

        $plainFromPayload = data_get($payload, 'empresa.sunat.clave_sol');
        $encrypted = $row->clave_sol_encrypted ?? null;
        $solPassword = $this->resolveSolPassword($encrypted, is_string($plainFromPayload) ? $plainFromPayload : null);

        $certificateField = (string) ($row->certificado_url ?? data_get($payload, 'empresa.sunat.certificado_url', ''));
        if ($serviceMode === 'production') {
            $this->assertProductionSunatConfig($solRuc, $solUser, $solPassword, $certificateField);
        }
        $certificatePem = $this->resolveCertificatePem($tenantRuc, $certificateField);

        return [
            'service' => $service,
            'sol_ruc' => $solRuc,
            'sol_user' => $solUser,
            'sol_password' => $solPassword,
            'certificate_pem' => $certificatePem,
            'logo_pdf_url' => is_string($row->logo_pdf_url ?? null) ? trim((string) $row->logo_pdf_url) : '',
        ];
    }

    private function assertProductionSunatConfig(string $solRuc, string $solUser, string $solPassword, string $certificateField): void
    {
        if (trim($solRuc) === '' || $solRuc === self::TEST_SOL_RUC) {
            throw new \RuntimeException('Modo production requiere RUC SOL real; no se permite RUC SOL de prueba.');
        }

        if (trim($solUser) === '' || strtoupper(trim($solUser)) === self::TEST_SOL_USER) {
            throw new \RuntimeException('Modo production requiere usuario SOL real; no se permite MODDATOS.');
        }

        if (trim($solPassword) === '' || trim($solPassword) === self::TEST_SOL_PASSWORD) {
            throw new \RuntimeException('Modo production requiere clave SOL real; no se permite clave de prueba.');
        }

        if (trim($certificateField) === '') {
            throw new \RuntimeException('Modo production requiere certificado digital real.');
        }

        $normalizedCert = strtolower(str_replace('\\', '/', $certificateField));
        if (str_contains($normalizedCert, 'cert_test') || str_contains($normalizedCert, 'ejemplo123456789')) {
            throw new \RuntimeException('Modo production no permite certificado de prueba.');
        }
    }

    private function resolveSunatEndpoint(string $serviceMode, string $documentType): string
    {
        if ($documentType === '09') {
            return $serviceMode === 'production'
                ? SunatEndpoints::GUIA_PRODUCCION
                : SunatEndpoints::GUIA_BETA;
        }

        return $serviceMode === 'production'
            ? SunatEndpoints::FE_PRODUCCION
            : SunatEndpoints::FE_BETA;
    }

    private function resolveSolPassword(mixed $encrypted, ?string $plain): string
    {
        if ($plain !== null && $plain !== '') {
            return $plain;
        }

        if (! is_string($encrypted) || $encrypted === '') {
            return self::TEST_SOL_PASSWORD;
        }

        try {
            return Crypt::decryptString($encrypted);
        } catch (\Throwable) {
            return $encrypted;
        }
    }

    private function resolveCertificatePem(string $tenantRuc, string $certRef): string
    {
        if ($certRef !== '') {
            if (str_contains($certRef, '-----BEGIN')) {
                return $certRef;
            }

            if (Storage::disk('tenants')->exists($certRef)) {
                return (string) Storage::disk('tenants')->get($certRef);
            }

            $absolute = $this->normalizeAbsolutePath($certRef);
            if (is_file($absolute)) {
                return (string) file_get_contents($absolute);
            }
        }

        $fallbacks = [
            storage_path('app/tenants/'.$tenantRuc.'/certificados/ejemplo123456789.pem'),
            storage_path('certificates/ejemplo123456789.pem'),
        ];

        foreach ($fallbacks as $file) {
            if (is_file($file)) {
                return (string) file_get_contents($file);
            }
        }

        throw new \RuntimeException('No certificate PEM found. Configure certificado_url or upload tenant certificate.');
    }

    private function normalizeAbsolutePath(string $path): string
    {
        if (preg_match('/^[A-Za-z]:\\\\/', $path) === 1 || str_starts_with($path, '/')) {
            return $path;
        }

        return base_path($path);
    }

    private function parseDate(mixed $value): DateTime
    {
        if (is_string($value) && $value !== '') {
            return new DateTime($value);
        }

        return new DateTime();
    }

    private function toFloat(mixed $value): float
    {
        return (float) (is_numeric($value) ? $value : 0);
    }

    /**
     * @param array<string, mixed> $source
     */
    private function requiredFloat(array $source, string $field, string $path): float
    {
        $value = $source[$field] ?? null;
        if (! is_numeric($value)) {
            throw new \InvalidArgumentException($path.'.'.$field.' debe ser enviado por Azurion como valor numerico.');
        }

        return (float) $value;
    }

    /**
     * @param array<string, mixed> $source
     */
    private function requiredString(array $source, string $field, string $path): string
    {
        $value = trim((string) ($source[$field] ?? ''));
        if ($value === '') {
            throw new \InvalidArgumentException($path.'.'.$field.' debe ser enviado por Azurion.');
        }

        return $value;
    }

    private function isGratuitaAfectacion(string $tipAfeIgv): bool
    {
        return in_array($tipAfeIgv, ['11', '12', '13', '14', '15', '16', '17', '21', '31', '32', '33', '34', '35', '36'], true);
    }

    /**
     * @param array<int, array<string, mixed>> $rows
     * @return array<int, Charge>
     */
    private function buildCharges(array $rows, float $fallbackBase): array
    {
        $items = [];

        foreach ($rows as $row) {
            if (! is_array($row)) {
                continue;
            }

            $codTipo = trim((string) ($row['codigo'] ?? $row['cod_tipo'] ?? $row['codTipo'] ?? ''));
            $monto = $this->toFloat($row['monto'] ?? 0);
            if ($codTipo === '' || $monto <= 0) {
                continue;
            }

            $base = $this->toFloat($row['monto_base'] ?? $row['montoBase'] ?? $fallbackBase);
            $factor = $this->toFloat($row['factor'] ?? 0);
            if ($factor <= 0 && $base > 0) {
                $factor = $monto / $base;
            }

            $items[] = (new Charge())
                ->setCodTipo($codTipo)
                ->setMontoBase($base)
                ->setFactor($factor > 0 ? $factor : null)
                ->setMonto($monto);
        }

        return $items;
    }

    /**
     * @param array<int, Charge> $charges
     */
    private function sumChargeMonto(array $charges): float
    {
        $sum = 0.0;
        foreach ($charges as $charge) {
            $sum += $this->toFloat($charge->getMonto());
        }

        return $sum;
    }

    /**
     * @param array<string, mixed> $percepcion
     */
    private function buildPerception(array $percepcion, float $valorVenta, float $documentTotal): ?SalePerception
    {
        if ($percepcion === []) {
            return null;
        }

        $codigo = trim((string) ($percepcion['codigo_regimen'] ?? $percepcion['cod_reg'] ?? $percepcion['codigo'] ?? ''));
        if ($codigo === '') {
            return null;
        }

        $porcentaje = $this->toFloat($percepcion['porcentaje'] ?? 0);
        $mtoBase = $this->toFloat($percepcion['monto_base'] ?? $percepcion['mto_base'] ?? $valorVenta);
        $mto = $this->toFloat($percepcion['monto'] ?? $percepcion['mto'] ?? round($mtoBase * $porcentaje, 2));
        $mtoTotal = $this->toFloat($percepcion['monto_total'] ?? $percepcion['mto_total'] ?? ($documentTotal + $mto));

        return (new SalePerception())
            ->setCodReg($codigo)
            ->setPorcentaje($porcentaje)
            ->setMtoBase($mtoBase)
            ->setMto($mto)
            ->setMtoTotal($mtoTotal);
    }

    /**
     * @param array<int, array<string, mixed>> $rows
     * @return array<int, Prepayment>
     */
    private function buildAnticipos(array $rows): array
    {
        $items = [];
        foreach ($rows as $row) {
            if (! is_array($row)) {
                continue;
            }

            $tipoDocRel = trim((string) ($row['tipo_doc_rel'] ?? $row['tipoDocRel'] ?? '02'));
            $nroDocRel = trim((string) ($row['nro_doc_rel'] ?? $row['nroDocRel'] ?? ''));
            $total = $this->toFloat($row['total'] ?? 0);

            if ($nroDocRel === '' || $total <= 0) {
                continue;
            }

            $items[] = (new Prepayment())
                ->setTipoDocRel($tipoDocRel)
                ->setNroDocRel($nroDocRel)
                ->setTotal($total);
        }

        return $items;
    }

    /**
     * @param array<int, Prepayment> $anticipos
     */
    private function sumAnticiposTotal(array $anticipos): float
    {
        $sum = 0.0;
        foreach ($anticipos as $anticipo) {
            $sum += $this->toFloat($anticipo->getTotal());
        }

        return $sum;
    }

    /**
     * @param array<string, mixed> $detraccion
     */
    private function buildDetraccion(array $detraccion): ?Detraction
    {
        if ($detraccion === []) {
            return null;
        }

        $codBien = trim((string) ($detraccion['cod_bien_detraccion'] ?? $detraccion['codigo_bien'] ?? ''));
        $codMedioPago = trim((string) ($detraccion['cod_medio_pago'] ?? $detraccion['codigo_medio_pago'] ?? ''));
        $porcentaje = $this->toFloat($detraccion['porcentaje'] ?? $detraccion['percent'] ?? 0);
        $monto = $this->toFloat($detraccion['monto'] ?? $detraccion['mount'] ?? 0);

        if ($codBien === '' || $codMedioPago === '' || $porcentaje <= 0 || $monto <= 0) {
            return null;
        }

        $model = (new Detraction())
            ->setCodBienDetraccion($codBien)
            ->setCodMedioPago($codMedioPago)
            ->setPercent($porcentaje)
            ->setMount($monto)
            ->setCtaBanco(trim((string) ($detraccion['cta_banco'] ?? $detraccion['cuenta_banco'] ?? '')) ?: null);

        $valueRef = $this->toFloat($detraccion['valor_referencial'] ?? $detraccion['value_ref'] ?? 0);
        if ($valueRef > 0) {
            $model->setValueRef($valueRef);
        }

        return $model;
    }

    /**
     * @param array<int, array<string, mixed>> $rows
     * @return array<int, Cuota>
     */
    private function buildCuotas(array $rows): array
    {
        $items = [];
        foreach ($rows as $row) {
            if (! is_array($row)) {
                continue;
            }

            $monto = $this->toFloat($row['monto'] ?? 0);
            $fechaRaw = $row['fecha_pago'] ?? $row['fechaPago'] ?? null;
            if ($monto <= 0 || ! is_string($fechaRaw) || trim($fechaRaw) === '') {
                continue;
            }

            try {
                $cuota = (new Cuota())
                    ->setMonto($monto)
                    ->setFechaPago($this->parseDate($fechaRaw));

                $moneda = trim((string) ($row['moneda'] ?? ''));
                if ($moneda !== '') {
                    $cuota->setMoneda($moneda);
                }

                $items[] = $cuota;
            } catch (\Throwable) {
                continue;
            }
        }

        return $items;
    }

    /**
     * @param array<string, mixed> $documento
     */
    private function resolveTipoOperacion(array $documento): string
    {
        $provided = trim((string) ($documento['tipo_operacion'] ?? ''));
        if ($provided === '') {
            throw new \InvalidArgumentException('documento.tipo_operacion debe ser enviado por Azurion.');
        }

        return $provided;
    }

    private function buildPdfContext(
        array $payload,
        Documento $documento,
        array $config,
        string $estado,
        ?string $hash,
        ?string $mensaje,
    ): array {
        $documentoPayload = (array) data_get($payload, 'documento', []);
        $documentoPayload['tipo'] = (string) ($documento->tipo_documento ?? ($documentoPayload['tipo'] ?? '01'));
        $documentoPayload['serie'] = (string) ($documento->serie ?? ($documentoPayload['serie'] ?? ''));
        $documentoPayload['correlativo'] = (string) ($documento->correlativo ?? ($documentoPayload['correlativo'] ?? ''));
        $empresa = (array) data_get($payload, 'empresa', []);
        if (trim((string) ($empresa['logo_pdf_url'] ?? $empresa['logo_url'] ?? '')) === '' && trim((string) ($config['logo_pdf_url'] ?? '')) !== '') {
            $empresa['logo_pdf_url'] = trim((string) $config['logo_pdf_url']);
        }

        return [
            'estado' => $estado,
            'hash' => $hash,
            'mensaje' => $mensaje,
            'empresa' => $empresa,
            'cliente' => (array) data_get($payload, 'cliente', []),
            'documento' => $documentoPayload,
            'detalles' => (array) data_get($payload, 'detalles', []),
        ];
    }
}
