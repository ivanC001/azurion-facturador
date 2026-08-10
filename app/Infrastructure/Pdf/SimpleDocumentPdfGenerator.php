<?php

namespace App\Infrastructure\Pdf;

use App\Domain\Pdf\Contracts\DocumentPdfGenerator;
use App\Support\Tenants\TenantContext;
use App\Support\Tenants\TenantPrivateFileReference;
use BaconQrCode\Common\ErrorCorrectionLevel;
use BaconQrCode\Encoder\Encoder;
use Illuminate\Support\Facades\Storage;
use Luecano\NumeroALetras\NumeroALetras;

final class SimpleDocumentPdfGenerator implements DocumentPdfGenerator
{
    private const PAGE_WIDTH = 595.0;
    private const PAGE_HEIGHT = 842.0;
    private const MARGIN = 24.0;
    private const TICKET_PAGE_WIDTH = 226.77; // 80mm aprox
    private const TICKET_MARGIN = 10.0;

    private float $currentPageHeight = self::PAGE_HEIGHT;
    private string $currentTenantRuc = '';
    /** @var array<int, array{name: string, width: int, height: int, data: string}> */
    private array $imageXObjects = [];

    public function __construct(private readonly TenantContext $tenantContext)
    {
    }

    /**
     * @param array<string, mixed> $context
     */
    public function generate(array $context): string
    {
        $this->imageXObjects = [];

        $empresa = is_array($context['empresa'] ?? null) ? $context['empresa'] : [];
        $cliente = is_array($context['cliente'] ?? null) ? $context['cliente'] : [];
        $documento = is_array($context['documento'] ?? null) ? $context['documento'] : [];
        $detalles = is_array($context['detalles'] ?? null) ? $context['detalles'] : [];

        $detailData = $this->buildDetailData($detalles);
        $calculated = $detailData['calculated'];
        $rows = $detailData['rows'];

        $tipo = strtoupper($this->txt($documento['tipo'] ?? '01'));
        $tipoLabel = $this->resolveDocumentTypeLabel($tipo);
        $serie = $this->txt($documento['serie'] ?? '-');
        $correlativo = $this->txt($documento['correlativo'] ?? '-');
        $numeroComprobante = $serie.'-'.$correlativo;

        $moneda = strtoupper($this->txt($documento['moneda'] ?? 'PEN'));
        $simbolo = $this->currencySymbol($moneda);

        $subTotal = $this->resolveAmount($documento, ['valor_venta', 'mto_oper_gravadas'], $calculated['sub_total']);
        $igv = $this->resolveAmount($documento, ['igv_total', 'mto_igv', 'mto_igv_total'], $calculated['igv']);
        $descuento = $this->resolveAmount($documento, ['mto_descuentos', 'descuento_total'], $calculated['discount']);
        $otrosCargos = $this->resolveAmount($documento, ['mto_cargos', 'sum_otros_cargos'], $calculated['other_charges']);
        $otrosTributos = $this->resolveAmount($documento, ['mto_otros_tributos', 'otros_tributos'], $calculated['other_taxes']);
        $total = $this->resolveAmount($documento, ['total', 'mto_imp_venta'], $calculated['total']);
        $operExoneradas = $this->resolveAmount($documento, ['mto_oper_exoneradas'], 0.0);
        $operInafectas = $this->resolveAmount($documento, ['mto_oper_inafectas'], 0.0);
        $icbper = $this->resolveAmount($documento, ['mto_icbper', 'icbper'], $calculated['icbper']);

        $estado = strtoupper($this->txt($context['estado'] ?? 'RECIBIDO'));
        $mensajeSunat = $this->txt($context['mensaje'] ?? '-');
        $hash = $this->txt($context['hash'] ?? '-');
        $ticket = $this->txt($context['ticket'] ?? '-');

        $documentoFecha = $this->formatDateTime($this->txt($documento['fecha_emision'] ?? date('Y-m-d H:i:s')));
        $generatedAt = date('Y-m-d H:i:s');
        $formaPago = strtoupper($this->txt($this->arr($documento, 'forma_pago.tipo', $documento['condicion_pago'] ?? 'CONTADO')));
        $tipoOperacion = $this->txt($documento['tipo_operacion'] ?? '0101');

        $empresaDireccion = $this->buildEmpresaDireccion($empresa);
        $empresaRuc = $this->txt($empresa['ruc'] ?? '-');
        $this->currentTenantRuc = trim($this->tenantContext->required()->ruc);
        $empresaRazonSocial = $this->txt($empresa['razon_social'] ?? $empresa['nombre'] ?? '-');
        $empresaComercial = $this->txt($empresa['nombre_comercial'] ?? $empresaRazonSocial);
        $empresaLogoRef = $this->txt($empresa['logo_pdf_url'] ?? $empresa['logo_url'] ?? '-');

        $clienteNombre = $this->txt($cliente['razon_social'] ?? $cliente['nombre'] ?? 'CLIENTES VARIOS');
        $clienteTipoDoc = $this->txt($cliente['tipo_doc'] ?? '0');
        $clienteNumDoc = $this->txt($cliente['num_doc'] ?? '-');
        $clienteDireccion = $this->txt($cliente['direccion'] ?? '-');
        $clienteCorreo = $this->txt($cliente['correo'] ?? $cliente['email'] ?? '-');
        $clienteTelefono = $this->txt($cliente['telefono'] ?? '-');

        $empresaCorreo = $this->txt($empresa['correo'] ?? $empresa['email'] ?? $this->arr($empresa, 'contacto.correo', '-'));
        $empresaTelefono = $this->txt($empresa['telefono'] ?? $this->arr($empresa, 'contacto.telefono', '-'));
        $empresaWebsite = $this->txt($empresa['website'] ?? $empresa['sitio_web'] ?? '-');
        $cuentasBancarias = $this->normalizeBankAccounts(
            is_array($context['cuentas_bancarias'] ?? null)
                ? $context['cuentas_bancarias']
                : (is_array($empresa['cuentas_bancarias'] ?? null) ? $empresa['cuentas_bancarias'] : [])
        );

        $montoLetras = $this->resolveAmountInWords(
            documento: $documento,
            total: $total,
            currencyCode: $moneda,
        );

        $pageData = [
            'tipo' => $tipo,
            'tipo_label' => $tipoLabel,
            'numero' => $numeroComprobante,
            'fecha_emision' => $documentoFecha,
            'moneda' => $moneda,
            'simbolo' => $simbolo,
            'estado' => $estado,
            'mensaje' => $mensajeSunat,
            'hash' => $hash,
            'ticket' => $ticket,
            'generated_at' => $generatedAt,
            'forma_pago' => $formaPago,
            'tipo_operacion' => $tipoOperacion,
            'empresa_ruc' => $empresaRuc,
            'empresa_razon_social' => $empresaRazonSocial,
            'empresa_nombre_comercial' => $empresaComercial,
            'empresa_direccion' => $empresaDireccion,
            'empresa_logo_ref' => $empresaLogoRef,
            'empresa_correo' => $empresaCorreo,
            'empresa_telefono' => $empresaTelefono,
            'empresa_website' => $empresaWebsite,
            'cliente_nombre' => $clienteNombre,
            'cliente_tipo_doc' => $clienteTipoDoc,
            'cliente_num_doc' => $clienteNumDoc,
            'cliente_direccion' => $clienteDireccion,
            'cliente_correo' => $clienteCorreo,
            'cliente_telefono' => $clienteTelefono,
            'cuentas_bancarias' => $cuentasBancarias,
            'detalles' => $rows,
            'sub_total' => $subTotal,
            'igv' => $igv,
            'descuento' => $descuento,
            'otros_cargos' => $otrosCargos,
            'otros_tributos' => $otrosTributos,
            'oper_exoneradas' => $operExoneradas,
            'oper_inafectas' => $operInafectas,
            'icbper' => $icbper,
            'total' => $total,
            'monto_letras' => $montoLetras,
        ];

        $pageData['qr_content'] = $this->buildQrContent($pageData);

        $requestedFormat = strtolower(trim((string) ($context['formato'] ?? '')));
        $renderAsTicket = match ($requestedFormat) {
            'ticket', 'thermal', 'termico', '80mm' => true,
            'a4' => false,
            default => $tipo === 'TK',
        };

        if ($renderAsTicket) {
            $rendered = $this->renderReferenceTicketPage($pageData);
            return $this->buildPdfDocument($rendered['content'], $rendered['width'], $rendered['height']);
        }

        $content = $this->renderReferenceEnterprisePage($pageData);

        return $this->buildPdfDocument($content, self::PAGE_WIDTH, self::PAGE_HEIGHT);
    }

    /**
     * Formato A4 comercial compacto basado en la referencia aprobada.
     * El logo se presenta libre, sin marco, y el color institucional se usa
     * unicamente para ordenar la lectura del comprobante.
     *
     * @param array<string, mixed> $data
     */
    private function renderReferenceEnterprisePage(array $data): string
    {
        $this->currentPageHeight = self::PAGE_HEIGHT;
        $x = self::MARGIN;
        $w = self::PAGE_WIDTH - (self::MARGIN * 2);
        $teal = [0, 132, 133];
        $tealDark = [0, 91, 94];
        $border = [77, 145, 147];
        $muted = [55, 65, 81];
        $commands = [];

        $this->drawLogo(
            $commands,
            (string) $data['empresa_logo_ref'],
            $x + 2,
            27,
            92,
            70,
            (string) $data['empresa_razon_social'],
            false,
        );

        $companyX = $x + 100;
        $companyW = 232.0;
        $this->drawText($commands, $companyX, 36, $this->fitText((string) $data['empresa_nombre_comercial'], $companyW, 10), 10, true, $tealDark);
        $this->drawText($commands, $companyX, 51, $this->fitText((string) $data['empresa_razon_social'], $companyW, 7.2), 7.2, true, [30, 41, 59]);
        $addressLines = $this->wrapText((string) $data['empresa_direccion'], $companyW, 6.4);
        $addressY = 64.0;
        foreach (array_slice($addressLines, 0, 3) as $line) {
            $this->drawText($commands, $companyX, $addressY, $line, 6.4, false, $muted);
            $addressY += 8.0;
        }
        $contact = $this->joinNonEmpty([
            (string) ($data['empresa_telefono'] ?? '-'),
            (string) ($data['empresa_correo'] ?? '-'),
            (string) ($data['empresa_website'] ?? '-'),
        ]);
        $this->drawText($commands, $companyX, 94, $this->fitText($contact === '' ? '-' : $contact, $companyW, 6.2), 6.2, false, $muted);

        $docX = $x + 341;
        $docW = $w - 341;
        $docCenter = $docX + ($docW / 2);
        $this->drawBox($commands, $docX, 22, $docW, 92, $border, [255, 255, 255], 0.9);
        $this->drawText($commands, $docCenter, 42, 'RUC: '.(string) $data['empresa_ruc'], 10, true, [17, 24, 39], 'center');
        $this->drawLine($commands, $docX, 49, $docX + $docW, 49, $border, 0.7);
        $this->drawBox($commands, $docX, 50, $docW, 25, $teal, $teal, 0.0);
        $this->drawText($commands, $docCenter, 67, (string) $data['tipo_label'], 8.2, true, [255, 255, 255], 'center');
        $this->drawLine($commands, $docX, 76, $docX + $docW, 76, $border, 0.7);
        $this->drawText($commands, $docCenter, 99, (string) $data['numero'], 15, true, [17, 24, 39], 'center');

        $clientY = 121.0;
        $clientW = 341.0;
        $gap = 7.0;
        $metaX = $x + $clientW + $gap;
        $metaW = $w - $clientW - $gap;
        $this->drawBox($commands, $x, $clientY, $clientW, 82, $border, [255, 255, 255], 0.8);
        $clientRows = [
            ['CLIENTE', (string) $data['cliente_nombre']],
            ['DOC.', (string) $data['cliente_tipo_doc'].' - '.(string) $data['cliente_num_doc']],
            ['DIRECCION', (string) $data['cliente_direccion']],
            ['CONTACTO', $this->joinNonEmpty([(string) $data['cliente_telefono'], (string) $data['cliente_correo']])],
            ['UBIGEO', '-'],
        ];
        $clientRowY = $clientY + 14;
        foreach ($clientRows as $row) {
            $this->drawText($commands, $x + 6, $clientRowY, (string) $row[0], 6.4, true, $tealDark);
            $this->drawText($commands, $x + 59, $clientRowY, $this->fitText((string) $row[1], $clientW - 66, 6.8), 6.8, $row[0] === 'CLIENTE', [30, 41, 59]);
            $clientRowY += 14.2;
        }

        $this->drawBox($commands, $metaX, $clientY, $metaW, 82, $border, [255, 255, 255], 0.8);
        $metaRows = [
            ['FEC. EMISION', (string) $data['fecha_emision']],
            ['MONEDA', (string) $data['moneda']],
            ['COND. PAGO', (string) $data['forma_pago']],
            ['ORD. COMPRA', '-'],
            ['ESTADO', (string) $data['estado']],
        ];
        $metaY = $clientY + 14;
        foreach ($metaRows as $row) {
            $this->drawText($commands, $metaX + 7, $metaY, (string) $row[0], 6.2, true, $tealDark);
            $this->drawText($commands, $metaX + 75, $metaY, $this->fitText((string) $row[1], $metaW - 82, 6.4), 6.4, false, [30, 41, 59]);
            $metaY += 14.2;
        }

        $tableY = 210.0;
        $tableH = 336.0;
        $this->drawBox($commands, $x, $tableY, $w, $tableH, $border, [255, 255, 255], 0.8);
        $this->drawBox($commands, $x, $tableY, $w, 21, $teal, $teal, 0.0);
        $colWidths = [286.0, 78.0, 58.0, 60.0, 65.0];
        $colLabels = ['DESCRIPCION', 'MEDIDA', 'CANTIDAD', 'PRECIO', 'IMPORTE'];
        $colX = [$x];
        foreach ($colWidths as $width) {
            $colX[] = end($colX) + $width;
        }
        foreach ($colX as $lineX) {
            $this->drawLine($commands, $lineX, $tableY, $lineX, $tableY + $tableH, [198, 221, 222], 0.45);
        }
        foreach ($colLabels as $index => $label) {
            $this->drawText($commands, $colX[$index] + ($colWidths[$index] / 2), $tableY + 14.5, $label, 7, true, [255, 255, 255], 'center');
        }

        /** @var array<int, array<string, mixed>> $detalles */
        $detalles = is_array($data['detalles'] ?? null) ? $data['detalles'] : [];
        $currentY = $tableY + 24;
        $maxY = $tableY + $tableH - 8;
        $shownRows = 0;
        foreach ($detalles as $row) {
            $description = $this->joinNonEmpty([(string) ($row['codigo'] ?? '-'), (string) ($row['descripcion'] ?? '-')]);
            $descLines = $this->wrapText($description, $colWidths[0] - 8, 7.2);
            $descLines = $descLines === [] ? ['-'] : $descLines;
            $rowHeight = max(15.0, (count($descLines) * 8.2) + 5.0);
            if (($currentY + $rowHeight + 2.0) > $maxY) {
                $this->drawText($commands, $x + 8, $maxY - 3, '... listado truncado por espacio de pagina ...', 6.3, false, [100, 116, 139]);
                break;
            }
            $baseY = $currentY + 10.5;
            $this->drawText($commands, $colX[1] + ($colWidths[1] / 2), $baseY, $this->fitText((string) ($row['unidad'] ?? 'NIU'), $colWidths[1] - 6, 7), 7, false, [30, 41, 59], 'center');
            $this->drawTextWithinWidth($commands, $colX[2] + $colWidths[2] - 4, $baseY, (string) ($row['cantidad_fmt'] ?? '0.00'), $colWidths[2] - 8, 7, false, [30, 41, 59], 'right');
            $this->drawTextWithinWidth($commands, $colX[3] + $colWidths[3] - 4, $baseY, (string) ($row['precio_unitario_fmt'] ?? $row['valor_unitario_fmt'] ?? '0.00'), $colWidths[3] - 8, 7, false, [30, 41, 59], 'right');
            $this->drawTextWithinWidth($commands, $colX[4] + $colWidths[4] - 4, $baseY, (string) ($row['total_fmt'] ?? '0.00'), $colWidths[4] - 8, 7, true, [15, 23, 42], 'right');
            $descY = $currentY + 9.5;
            foreach ($descLines as $line) {
                $this->drawText($commands, $colX[0] + 4, $descY, $line, 7.2, false, [30, 41, 59]);
                $descY += 8.2;
            }
            $this->drawLine($commands, $x, $currentY + $rowHeight, $x + $w, $currentY + $rowHeight, [215, 231, 232], 0.45);
            $currentY += $rowHeight;
            $shownRows++;
        }
        if ($shownRows === 0) {
            $this->drawText($commands, $x + ($w / 2), $tableY + 46, 'Sin items registrados.', 7, false, [100, 116, 139], 'center');
        }

        $summaryY = 554.0;
        $leftSummaryW = 352.0;
        $rightSummaryX = $x + $leftSummaryW + $gap;
        $rightSummaryW = $w - $leftSummaryW - $gap;
        $isInternalTicket = ((string) ($data['tipo'] ?? '')) === 'TK';
        $this->drawBox($commands, $x, $summaryY, $leftSummaryW, 82, $border, [255, 255, 255], 0.8);
        $this->drawBox($commands, $rightSummaryX, $summaryY, $rightSummaryW, 82, $border, [255, 255, 255], 0.8);
        $this->drawText(
            $commands,
            $x + 7,
            $summaryY + 14,
            $isInternalTicket ? 'CONDICIONES DE PAGO' : 'CUENTA DETRACCIONES / CONDICIONES DE PAGO',
            6.4,
            true,
            $tealDark,
        );
        $traceability = $isInternalTicket
            ? (string) $data['numero']
            : $this->joinNonEmpty([(string) $data['ticket'], (string) $data['hash']]);
        $conditionRows = [
            ['FORMA DE PAGO:', (string) $data['forma_pago']],
            ['MONEDA:', (string) $data['moneda']],
            ['ESTADO:', (string) $data['estado']],
            [$isInternalTicket ? 'IDENTIFICADOR:' : 'TICKET / HASH:', $traceability !== '' ? $traceability : 'PENDIENTE DE SUNAT'],
        ];
        $conditionY = $summaryY + 30;
        foreach ($conditionRows as $row) {
            $this->drawText($commands, $x + 7, $conditionY, (string) $row[0], 6.4, true, [30, 41, 59]);
            $this->drawText($commands, $x + 85, $conditionY, $this->fitText((string) $row[1], $leftSummaryW - 92, 6.4), 6.4, false, $muted);
            $conditionY += 14.5;
        }
        $amountRows = [
            ['SUB TOTAL', (float) ($data['sub_total'] ?? 0)],
            ['DSCTO', (float) ($data['descuento'] ?? 0)],
            ['OP. GRAVADA', (float) ($data['sub_total'] ?? 0)],
            ['OP. EXONERADA', (float) ($data['oper_exoneradas'] ?? 0)],
            ['OP. INAFECTA', (float) ($data['oper_inafectas'] ?? 0)],
            ['IGV', (float) ($data['igv'] ?? 0)],
        ];
        $amountX = $rightSummaryX + 7;
        $amountRight = $rightSummaryX + $rightSummaryW - 7;
        $amountY = $summaryY + 11;
        foreach ($amountRows as $row) {
            $this->drawText($commands, $amountX, $amountY, (string) $row[0], 6.2, true, [30, 41, 59]);
            $this->drawText($commands, $amountRight, $amountY, (string) $data['simbolo'].' '.number_format((float) $row[1], 2, '.', ','), 6.4, false, [30, 41, 59], 'right');
            $amountY += 9.2;
        }
        $this->drawLine($commands, $amountX, $summaryY + 68, $amountRight, $summaryY + 68, $border, 0.8);
        $this->drawText($commands, $amountX, $summaryY + 78, 'TOTAL', 7.8, true, [15, 23, 42]);
        $this->drawText($commands, $amountRight, $summaryY + 78, (string) $data['simbolo'].' '.number_format((float) ($data['total'] ?? 0), 2, '.', ','), 8.4, true, $tealDark, 'right');

        $lettersY = 644.0;
        $this->drawBox($commands, $x, $lettersY, $w, 27, $border, [255, 255, 255], 0.8);
        $this->drawText($commands, $x + 7, $lettersY + 17, 'IMPORTE EN LETRAS:', 6.5, true, $tealDark);
        $this->drawText($commands, $x + 104, $lettersY + 17, $this->fitText((string) $data['monto_letras'], $w - 111, 6.6), 6.6, false, [30, 41, 59]);

        $banksY = 677.0;
        $this->drawBox($commands, $x, $banksY, $w, 70, $border, [255, 255, 255], 0.8);
        $this->drawText($commands, $x + 7, $banksY + 13, 'CUENTAS BANCARIAS', 6.4, true, $tealDark);
        $bankColumns = [$x + 7, $x + 150, $x + 226, $x + 395];
        foreach ([['ENTIDAD FINANCIERA', 0], ['MONEDA', 1], ['NUMERO DE CUENTA', 2], ['CODIGO CCI', 3]] as $heading) {
            $this->drawText($commands, $bankColumns[$heading[1]], $banksY + 27, $heading[0], 5.9, true, [30, 41, 59]);
        }
        /** @var array<int, array<string, string>> $bankAccounts */
        $bankAccounts = is_array($data['cuentas_bancarias'] ?? null) ? $data['cuentas_bancarias'] : [];
        $bankY = $banksY + 40;
        foreach (array_slice($bankAccounts, 0, 3) as $account) {
            $this->drawText($commands, $bankColumns[0], $bankY, $this->fitText((string) ($account['banco'] ?? '-'), 136, 5.9), 5.9, false, $muted);
            $this->drawText($commands, $bankColumns[1], $bankY, $this->fitText((string) ($account['moneda'] ?? '-'), 68, 5.9), 5.9, false, $muted);
            $this->drawText($commands, $bankColumns[2], $bankY, $this->fitText((string) ($account['cuenta'] ?? '-'), 160, 5.9), 5.9, false, $muted);
            $this->drawText($commands, $bankColumns[3], $bankY, $this->fitText((string) ($account['cci'] ?? 'NO REGISTRADO'), 145, 5.9), 5.9, false, $muted);
            $bankY += 9.0;
        }
        if ($bankAccounts === []) {
            $this->drawText($commands, $x + 7, $banksY + 45, 'Configura las cuentas en Configuracion > Facturador.', 6.2, false, [100, 116, 139]);
        }

        $footerY = 754.0;
        $qrW = 80.0;
        $this->drawBox($commands, $x, $footerY, $qrW, 66, $border, [255, 255, 255], 0.8);
        $this->drawQrCode($commands, (string) ($data['qr_content'] ?? ''), $x + 7, $footerY + 6, 54);
        $infoX = $x + $qrW + 5;
        $infoW = $w - $qrW - 5;
        $this->drawBox($commands, $infoX, $footerY, $infoW, 66, $border, [255, 255, 255], 0.8);
        $representation = $isInternalTicket
            ? 'Documento de venta interno - No se registra en SUNAT'
            : 'Representacion impresa del comprobante electronico.';
        $this->drawText($commands, $infoX + ($infoW / 2), $footerY + 19, $representation, 7, true, $tealDark, 'center');
        $this->drawText($commands, $infoX + ($infoW / 2), $footerY + 34, $this->fitText('Consulte su comprobante con el numero '.(string) $data['numero'], $infoW - 16, 6.1), 6.1, false, $muted, 'center');
        $footerReference = $isInternalTicket
            ? 'REFERENCIA INTERNA: '.(string) $data['numero']
            : 'HASH: '.(string) $data['hash'];
        $this->drawText($commands, $infoX + 9, $footerY + 50, $this->fitText($footerReference, $infoW - 18, 5.8), 5.8, false, $muted);
        $this->drawText($commands, $infoX + 9, $footerY + 61, $this->fitText((string) $data['mensaje'], $infoW - 18, 5.8), 5.8, false, $muted);
        $this->drawText($commands, $x + ($w / 2), 834, '*** AZURION FACTURADOR ***', 6.3, true, [30, 41, 59], 'center');

        return implode("\n", $commands)."\n";
    }

    /**
     * Version termica de 80 mm con la misma jerarquia visual del A4.
     *
     * @param array<string, mixed> $data
     * @return array{content: string, width: float, height: float}
     */
    private function renderReferenceTicketPage(array $data): array
    {
        $width = self::TICKET_PAGE_WIDTH;
        $x = self::TICKET_MARGIN;
        $w = $width - (self::TICKET_MARGIN * 2);
        $teal = [0, 132, 133];
        $tealDark = [0, 91, 94];
        $border = [77, 145, 147];
        $muted = [55, 65, 81];

        $colWidths = [9.0, $w - 131.0, 28.0, 42.0, 52.0];

        /** @var array<int, array<string, mixed>> $detalles */
        $detalles = is_array($data['detalles'] ?? null) ? $data['detalles'] : [];
        $rows = [];
        $rowsHeight = 0.0;
        foreach ($detalles as $row) {
            $description = $this->joinNonEmpty([(string) ($row['codigo'] ?? '-'), (string) ($row['descripcion'] ?? '-')]);
            $descLines = $this->wrapText($description, $colWidths[1] - 4, 6.4);
            $descLines = $descLines === [] ? ['-'] : $descLines;
            $rowHeight = max(10.0, (count($descLines) * 7.2) + 3.0);
            $rowsHeight += $rowHeight;
            $rows[] = ['row' => $row, 'desc_lines' => $descLines, 'row_height' => $rowHeight];
        }
        $tableHeight = max(70.0, $rowsHeight + 20.0);
        $pageHeight = max(500.0, min(1400.0, 429.0 + $tableHeight));
        $this->currentPageHeight = $pageHeight;
        $commands = [];
        $y = self::TICKET_MARGIN;

        $this->drawLogo($commands, (string) ($data['empresa_logo_ref'] ?? ''), $x + 2, $y + 1, 46, 36, (string) ($data['empresa_razon_social'] ?? ''), false);
        $this->drawText($commands, $x + 53, $y + 12, $this->fitText((string) ($data['empresa_nombre_comercial'] ?? 'EMPRESA'), $w - 55, 8.4), 8.4, true, $tealDark);
        $this->drawText($commands, $x + 53, $y + 24, $this->fitText((string) ($data['empresa_razon_social'] ?? '-'), $w - 55, 6.2), 6.2, false, [30, 41, 59]);
        $this->drawText($commands, $x + 53, $y + 35, 'RUC: '.(string) ($data['empresa_ruc'] ?? '-'), 6.5, true, [30, 41, 59]);
        $this->drawText($commands, $x + 2, $y + 48, $this->fitText((string) ($data['empresa_direccion'] ?? '-'), $w - 4, 5.9), 5.9, false, $muted);
        $this->drawBox($commands, $x, $y + 56, $w, 20, $teal, $teal, 0.0);
        $this->drawText($commands, $x + ($w / 2), $y + 70, (string) ($data['tipo_label'] ?? 'COMPROBANTE'), 7.2, true, [255, 255, 255], 'center');
        $this->drawBox($commands, $x, $y + 76, $w, 30, $border, [255, 255, 255], 0.8);
        $this->drawText($commands, $x + ($w / 2), $y + 96, (string) ($data['numero'] ?? '-'), 10.5, true, [15, 23, 42], 'center');

        $y += 113;
        $this->drawBox($commands, $x, $y, $w, 56, $border, [255, 255, 255], 0.8);
        $this->drawText($commands, $x + 6, $y + 13, 'CLIENTE: '.$this->fitText((string) ($data['cliente_nombre'] ?? 'CLIENTES VARIOS'), $w - 48, 6.8), 6.8, true, [30, 41, 59]);
        $this->drawText($commands, $x + 6, $y + 24, 'DOC: '.(string) ($data['cliente_tipo_doc'] ?? '0').' '.(string) ($data['cliente_num_doc'] ?? '-'), 6.5, false, $muted);
        $this->drawText($commands, $x + 6, $y + 35, 'FECHA: '.(string) ($data['fecha_emision'] ?? '-'), 6.5, false, $muted);
        $this->drawText($commands, $x + 6, $y + 46, 'MONEDA: '.(string) ($data['moneda'] ?? 'PEN').'  |  PAGO: '.(string) ($data['forma_pago'] ?? 'CONTADO'), 6.5, false, $muted);

        $y += 62;
        $this->drawBox($commands, $x, $y, $w, $tableHeight, $border, [255, 255, 255], 0.8);
        $this->drawBox($commands, $x, $y, $w, 16, $teal, $teal, 0.0);
        $colLabels = ['#', 'DESCRIPCION', 'CANT', 'P.U.', 'IMP'];
        $colX = [$x];
        foreach ($colWidths as $colWidth) {
            $colX[] = end($colX) + $colWidth;
        }
        foreach ($colX as $lineX) {
            $this->drawLine($commands, $lineX, $y, $lineX, $y + $tableHeight, [202, 224, 225], 0.5);
        }
        foreach ($colLabels as $index => $label) {
            $this->drawText($commands, $colX[$index] + ($colWidths[$index] / 2), $y + 11.5, $label, 6.4, true, [255, 255, 255], 'center');
        }
        $rowY = $y + 20;
        foreach ($rows as $entry) {
            $row = is_array($entry['row'] ?? null) ? $entry['row'] : [];
            $descLines = is_array($entry['desc_lines'] ?? null) ? $entry['desc_lines'] : ['-'];
            $rowHeight = (float) ($entry['row_height'] ?? 10.0);
            $this->drawText($commands, $colX[0] + ($colWidths[0] / 2), $rowY + 7.5, (string) ($row['index'] ?? ''), 6.2, false, [30, 41, 59], 'center');
            $descY = $rowY + 7.5;
            foreach ($descLines as $line) {
                $this->drawText($commands, $colX[1] + 2, $descY, (string) $line, 6.4, false, [30, 41, 59]);
                $descY += 7.2;
            }
            $this->drawTextWithinWidth($commands, $colX[2] + $colWidths[2] - 2, $rowY + 7.5, (string) ($row['cantidad_fmt'] ?? '0'), $colWidths[2] - 4, 6.7, false, [30, 41, 59], 'right');
            $this->drawTextWithinWidth($commands, $colX[3] + $colWidths[3] - 2, $rowY + 7.5, (string) ($row['precio_unitario_fmt'] ?? $row['valor_unitario_fmt'] ?? '0.00'), $colWidths[3] - 4, 6.7, false, [30, 41, 59], 'right');
            $this->drawTextWithinWidth($commands, $colX[4] + $colWidths[4] - 2, $rowY + 7.5, (string) ($row['total_fmt'] ?? '0.00'), $colWidths[4] - 4, 6.7, true, [15, 23, 42], 'right');
            $this->drawLine($commands, $x, $rowY + $rowHeight, $x + $w, $rowY + $rowHeight, [225, 236, 237], 0.45);
            $rowY += $rowHeight;
        }
        if ($rows === []) {
            $this->drawText($commands, $x + ($w / 2), $y + 34, 'Sin items registrados', 7, false, [100, 116, 139], 'center');
        }

        $y += $tableHeight + 8;
        $this->drawBox($commands, $x, $y, $w, 76, $border, [255, 255, 255], 0.8);
        $left = $x + 6;
        $right = $x + $w - 6;
        foreach ([
            ['SUB TOTAL', (float) ($data['sub_total'] ?? 0), 15.0],
            ['IGV', (float) ($data['igv'] ?? 0), 27.0],
            ['DESCUENTO', (float) ($data['descuento'] ?? 0), 39.0],
        ] as $row) {
            $this->drawText($commands, $left, $y + $row[2], $row[0], 7, true, [30, 41, 59]);
            $this->drawText($commands, $right, $y + $row[2], (string) ($data['simbolo'] ?? 'S/').' '.number_format($row[1], 2, '.', ','), 7, false, [30, 41, 59], 'right');
        }
        $this->drawLine($commands, $left, $y + 48, $right, $y + 48, $border, 0.8);
        $this->drawText($commands, $left, $y + 62, 'TOTAL', 8.5, true, [15, 23, 42]);
        $this->drawText($commands, $right, $y + 62, (string) ($data['simbolo'] ?? 'S/').' '.number_format((float) ($data['total'] ?? 0), 2, '.', ','), 9, true, $tealDark, 'right');

        $y += 82;
        $this->drawBox($commands, $x, $y, $w, 36, $border, [255, 255, 255], 0.8);
        $this->drawText($commands, $x + 5, $y + 12, 'IMPORTE EN LETRAS:', 6.1, true, $tealDark);
        $letterLines = $this->wrapText((string) ($data['monto_letras'] ?? '-'), $w - 10, 6.0);
        $letterY = $y + 23;
        foreach (array_slice($letterLines, 0, 2) as $line) {
            $this->drawText($commands, $x + 5, $letterY, $line, 6.0, false, $muted);
            $letterY += 7.0;
        }

        $y += 44;
        $this->drawBox($commands, $x, $y, $w, 105, $border, [255, 255, 255], 0.8);
        $isInternalTicket = ((string) ($data['tipo'] ?? '')) === 'TK';
        $representation = $isInternalTicket ? 'Documento de venta interno - no SUNAT' : 'Representacion impresa del comprobante electronico';
        $representationColor = $isInternalTicket ? [185, 28, 28] : $tealDark;
        $this->drawText($commands, $x + ($w / 2), $y + 13, $representation, 6.8, true, $representationColor, 'center');
        $this->drawQrCode($commands, (string) ($data['qr_content'] ?? ''), $x + 7, $y + 21, 55.0);
        $footerX = $x + 70;
        $footerW = $w - 76;
        $this->drawText($commands, $footerX, $y + 32, $this->fitText((string) ($data['numero'] ?? '-'), $footerW, 7.2), 7.2, true, [30, 41, 59]);
        $footerReference = $isInternalTicket
            ? 'REFERENCIA: '.(string) ($data['numero'] ?? '-')
            : 'HASH: '.(string) ($data['hash'] ?? '-');
        $this->drawText($commands, $footerX, $y + 46, $this->fitText($footerReference, $footerW, 5.9), 5.9, false, $muted);
        $this->drawText($commands, $footerX, $y + 59, $this->fitText((string) ($data['mensaje'] ?? '-'), $footerW, 5.9), 5.9, false, $muted);
        $this->drawText($commands, $footerX, $y + 72, 'Generado: '.(string) ($data['generated_at'] ?? '-'), 5.7, false, [100, 116, 139]);
        $this->drawLine($commands, $x + 6, $y + 82, $x + $w - 6, $y + 82, [202, 224, 225], 0.6);
        $this->drawText($commands, $x + ($w / 2), $y + 96, '*** Gracias por su compra ***', 7, true, [30, 41, 59], 'center');

        return ['content' => implode("\n", $commands)."\n", 'width' => $width, 'height' => $pageHeight];
    }

    /**
     * @param array<string, mixed> $data
     */
    private function renderEnterprisePage(array $data): string
    {
        $this->currentPageHeight = self::PAGE_HEIGHT;
        $x = self::MARGIN;
        $w = self::PAGE_WIDTH - (self::MARGIN * 2);

        $headerLeftW = 356.0;
        $gap = 8.0;
        $headerRightW = $w - $headerLeftW - $gap;

        $commands = [];
        $y = self::MARGIN;

        $this->drawBox($commands, $x, $y, $headerLeftW, 124, [228, 236, 245], [255, 255, 255], 1.0);
        $this->drawBox($commands, $x + $headerLeftW + $gap, $y, $headerRightW, 124, [79, 157, 173], [243, 251, 253], 1.2);

        $logoX = $x + 14;
        $logoY = $y + 20;
        $logoW = 74.0;
        $logoH = 64.0;
        $textX = $logoX + $logoW + 14.0;
        $textW = $headerLeftW - $logoW - 42.0;

        $this->drawRect($commands, $logoX, $logoY, $logoW, $logoH, [223, 230, 240], [249, 250, 251], 0.8);
        $this->drawLogo(
            $commands,
            (string) $data['empresa_logo_ref'],
            $logoX + 6,
            $logoY + 6,
            $logoW - 12,
            $logoH - 12,
            (string) $data['empresa_razon_social']
        );

        $this->drawText($commands, $textX, $y + 19, 'EMISOR', 8, true, [8, 70, 93]);
        $this->drawText($commands, $textX, $y + 37, $this->fitText((string) $data['empresa_nombre_comercial'], $textW, 12), 12, true, [17, 24, 39]);
        $this->drawText($commands, $textX, $y + 54, $this->fitText((string) $data['empresa_razon_social'], $textW, 8), 8, false, [51, 65, 85]);
        $this->drawText($commands, $textX, $y + 69, 'RUC: '.(string) $data['empresa_ruc'], 9, true, [17, 24, 39]);

        $addressLines = $this->wrapText((string) $data['empresa_direccion'], $textW, 7.4);
        $addressY = $y + 85;
        foreach (array_slice($addressLines, 0, 3) as $line) {
            $this->drawText($commands, $textX, $addressY, $line, 7.4, false, [71, 85, 105]);
            $addressY += 9.5;
        }
        $contact = $this->joinNonEmpty([
            (string) ($data['empresa_telefono'] ?? '-'),
            (string) ($data['empresa_correo'] ?? '-'),
            (string) ($data['empresa_website'] ?? '-'),
        ]);
        if ($contact !== '') {
            $this->drawText($commands, $textX, $y + 115, $this->fitText($contact, $textW, 6.6), 6.6, false, [71, 85, 105]);
        }

        $docX = $x + $headerLeftW + $gap + 12;
        $docRightX = $x + $headerLeftW + $gap + $headerRightW - 12;
        $docCenterX = $docX + (($docRightX - $docX) / 2);
        $this->drawText($commands, $docCenterX, $y + 42, 'RUC '.(string) $data['empresa_ruc'], 10, true, [17, 24, 39], 'center');
        $this->drawText($commands, $docCenterX, $y + 59, (string) $data['tipo_label'], 9, true, [8, 145, 178], 'center');
        $this->drawText($commands, $docCenterX, $y + 82, (string) $data['numero'], 19, true, [15, 23, 42], 'center');
        $this->drawLine($commands, $docX + 6, $y + 91, $docRightX - 6, $y + 91, [203, 213, 225], 0.8);
        $this->drawText($commands, $docX + 6, $y + 106, 'Fecha emision', 7, false, [100, 116, 139]);
        $this->drawText($commands, $docRightX - 6, $y + 106, (string) $data['fecha_emision'], 7.2, true, [51, 65, 85], 'right');
        $this->drawText($commands, $docX + 6, $y + 119, 'Moneda', 7, false, [100, 116, 139]);
        $this->drawText($commands, $docRightX - 6, $y + 119, (string) $data['moneda'], 7.2, true, [51, 65, 85], 'right');

        $estadoText = 'ESTADO: '.(string) $data['estado'];
        $estadoColor = $this->statusColor((string) $data['estado']);
        $estadoWidth = min(158.0, max(86.0, $this->estimateTextWidth($estadoText, 8) + 20));
        $this->drawBox($commands, $docCenterX - ($estadoWidth / 2), $y + 13, $estadoWidth, 18, [206, 222, 236], $estadoColor, 0.8);
        $this->drawText($commands, $docCenterX, $y + 26, $estadoText, 8, true, [255, 255, 255], 'center');

        $y += 124 + 10;

        $this->drawBox($commands, $x, $y, $headerLeftW, 84, [228, 236, 245], [255, 255, 255], 1.0);
        $this->drawBox($commands, $x + $headerLeftW + $gap, $y, $headerRightW, 84, [228, 236, 245], [255, 255, 255], 1.0);

        $this->drawText($commands, $x + 12, $y + 20, 'CLIENTE', 9, true, [15, 23, 42]);
        $this->drawText($commands, $x + 12, $y + 38, $this->fitText((string) $data['cliente_nombre'], 300, 10), 10, true, [17, 24, 39]);
        $this->drawText(
            $commands,
            $x + 12,
            $y + 55,
            'Doc: '.(string) $data['cliente_tipo_doc'].' '.(string) $data['cliente_num_doc'],
            8,
            false,
            [71, 85, 105]
        );
        $this->drawText($commands, $x + 12, $y + 70, 'Direccion: '.$this->fitText((string) $data['cliente_direccion'], 305, 8), 8, false, [71, 85, 105]);
        $clientContact = $this->joinNonEmpty([
            (string) ($data['cliente_telefono'] ?? '-'),
            (string) ($data['cliente_correo'] ?? '-'),
        ]);
        if ($clientContact !== '') {
            $this->drawText($commands, $x + 180, $y + 55, $this->fitText($clientContact, 155, 7), 7, false, [71, 85, 105]);
        }

        $metaX = $x + $headerLeftW + $gap + 12;
        $this->drawText($commands, $metaX, $y + 20, 'FORMA DE PAGO: '.(string) $data['forma_pago'], 8, true, [15, 23, 42]);
        $this->drawText($commands, $metaX, $y + 35, 'TIPO OPERACION: '.(string) $data['tipo_operacion'], 8, false, [51, 65, 85]);
        $this->drawText($commands, $metaX, $y + 50, 'HASH: '.$this->fitText((string) $data['hash'], 162, 7), 7, false, [71, 85, 105]);
        $this->drawText($commands, $metaX, $y + 65, 'TICKET: '.$this->fitText((string) $data['ticket'], 162, 7), 7, false, [71, 85, 105]);

        $y += 84 + 10;

        $tableH = 336.0;
        $this->drawBox($commands, $x, $y, $w, $tableH, [199, 217, 233], [255, 255, 255], 1.0);
        $this->drawBox($commands, $x, $y, $w, 20, [8, 145, 178], [8, 145, 178], 1.0);

        $colWidths = [22.0, 62.0, 189.0, 32.0, 38.0, 52.0, 52.0, 52.0, 48.0];
        $colLabels = ['#', 'Codigo', 'Descripcion', 'Und', 'Cant.', 'V.Unit', 'Dscto', 'IGV', 'Importe'];
        $colX = [$x];
        foreach ($colWidths as $width) {
            $colX[] = end($colX) + $width;
        }

        foreach ($colX as $lineX) {
            $this->drawLine($commands, $lineX, $y, $lineX, $y + $tableH, [213, 225, 237], 0.6);
        }

        $headTextY = $y + 14;
        foreach ($colLabels as $index => $label) {
            $cellCenter = $colX[$index] + ($colWidths[$index] / 2);
            $this->drawText($commands, $cellCenter, $headTextY, $label, 8, true, [255, 255, 255], 'center');
        }

        $currentY = $y + 28;
        $maxY = $y + $tableH - 8;
        $lineHeight = 9.0;
        $shownRows = 0;

        /** @var array<int, array<string, mixed>> $detalles */
        $detalles = is_array($data['detalles'] ?? null) ? $data['detalles'] : [];
        foreach ($detalles as $row) {
            $descLines = $this->wrapText((string) ($row['descripcion'] ?? '-'), 183.0, 8.0);
            if ($descLines === []) {
                $descLines = ['-'];
            }

            $rowHeight = max(14.0, (count($descLines) * $lineHeight) + 4.0);
            if (($currentY + $rowHeight + 2.0) > $maxY) {
                $this->drawText($commands, $x + 10, $maxY - 4, '... listado truncado por espacio de pagina ...', 7, false, [100, 116, 139]);
                break;
            }

            $baseY = $currentY + 10;

            $this->drawText($commands, $colX[0] + ($colWidths[0] / 2), $baseY, (string) ($row['index'] ?? ''), 8, false, [30, 41, 59], 'center');
            $this->drawText($commands, $colX[1] + 3, $baseY, $this->fitText((string) ($row['codigo'] ?? '-'), 56, 8), 8, false, [30, 41, 59]);
            $this->drawText($commands, $colX[3] + ($colWidths[3] / 2), $baseY, $this->fitText((string) ($row['unidad'] ?? 'NIU'), 26, 8), 8, false, [30, 41, 59], 'center');
            $this->drawText($commands, $colX[4] + ($colWidths[4] - 3), $baseY, (string) ($row['cantidad_fmt'] ?? '0.00'), 8, false, [30, 41, 59], 'right');
            $this->drawText($commands, $colX[5] + ($colWidths[5] - 3), $baseY, (string) ($row['valor_unitario_fmt'] ?? '0.00'), 8, false, [30, 41, 59], 'right');
            $this->drawText($commands, $colX[6] + ($colWidths[6] - 3), $baseY, (string) ($row['descuento_fmt'] ?? '0.00'), 8, false, [30, 41, 59], 'right');
            $this->drawText($commands, $colX[7] + ($colWidths[7] - 3), $baseY, (string) ($row['igv_fmt'] ?? '0.00'), 8, false, [30, 41, 59], 'right');
            $this->drawText($commands, $colX[8] + ($colWidths[8] - 3), $baseY, (string) ($row['total_fmt'] ?? '0.00'), 8, true, [15, 23, 42], 'right');

            $descY = $currentY + 9;
            foreach ($descLines as $line) {
                $this->drawText($commands, $colX[2] + 3, $descY, $line, 8, false, [51, 65, 85]);
                $descY += $lineHeight;
            }

            $this->drawLine($commands, $x, $currentY + $rowHeight, $x + $w, $currentY + $rowHeight, [226, 232, 240], 0.5);
            $currentY += $rowHeight;
            $shownRows++;
        }

        if ($shownRows === 0) {
            $this->drawText($commands, $x + ($w / 2), $y + 44, 'Sin items registrados.', 9, false, [100, 116, 139], 'center');
        }

        $y += $tableH + 10;

        $leftSummaryW = 370.0;
        $rightSummaryW = $w - $leftSummaryW - $gap;

        $this->drawBox($commands, $x, $y, $leftSummaryW, 98, [228, 236, 245], [255, 255, 255], 1.0);
        $this->drawBox($commands, $x + $leftSummaryW + $gap, $y, $rightSummaryW, 98, [228, 236, 245], [250, 253, 255], 1.0);

        $this->drawText($commands, $x + 10, $y + 18, 'Importe en letras', 9, true, [15, 23, 42]);
        $letterLines = $this->wrapText((string) $data['monto_letras'], $leftSummaryW - 20, 8.5);
        $lineY = $y + 34;
        foreach (array_slice($letterLines, 0, 3) as $line) {
            $this->drawText($commands, $x + 10, $lineY, $line, 8.5, false, [51, 65, 85]);
            $lineY += 11.0;
        }
        $this->drawText($commands, $x + 10, $y + 72, 'Mensaje SUNAT: '.$this->fitText((string) $data['mensaje'], $leftSummaryW - 20, 7), 7, false, [71, 85, 105]);
        $this->drawText($commands, $x + 10, $y + 86, 'Generado: '.(string) $data['generated_at'], 7, false, [100, 116, 139]);

        $summaryX = $x + $leftSummaryW + $gap + 10;
        $summaryRight = $x + $leftSummaryW + $gap + $rightSummaryW - 10;
        $summaryRows = [
            ['Sub total', (float) ($data['sub_total'] ?? 0)],
            ['Descuento', (float) ($data['descuento'] ?? 0)],
            ['Op. exonerada', (float) ($data['oper_exoneradas'] ?? 0)],
            ['Op. inafecta', (float) ($data['oper_inafectas'] ?? 0)],
            ['IGV', (float) ($data['igv'] ?? 0)],
            ['ICBPER', (float) ($data['icbper'] ?? 0)],
            ['Otros tributos', (float) ($data['otros_tributos'] ?? 0)],
            ['Otros cargos', (float) ($data['otros_cargos'] ?? 0)],
        ];

        $summaryY = $y + 16;
        foreach ($summaryRows as $summaryRow) {
            $this->drawText($commands, $summaryX, $summaryY, (string) $summaryRow[0], 7.5, false, [71, 85, 105]);
            $this->drawText(
                $commands,
                $summaryRight,
                $summaryY,
                (string) $data['simbolo'].' '.number_format((float) $summaryRow[1], 2, '.', ','),
                7.5,
                false,
                [30, 41, 59],
                'right'
            );
            $summaryY += 9.2;
        }

        $this->drawLine($commands, $summaryX, $y + 86, $summaryRight, $y + 86, [203, 213, 225], 0.8);
        $this->drawText($commands, $summaryX, $y + 94, 'TOTAL', 10, true, [15, 23, 42]);
        $this->drawText(
            $commands,
            $summaryRight,
            $y + 94,
            (string) $data['simbolo'].' '.number_format((float) ($data['total'] ?? 0), 2, '.', ','),
            11,
            true,
            [8, 145, 178],
            'right'
        );

        $y += 98 + 10;

        $this->drawBox($commands, $x, $y, $w, 126, [228, 236, 245], [255, 255, 255], 1.0);
        $this->drawBox($commands, $x, $y, $w, 20, [241, 245, 249], [241, 245, 249], 1.0);
        $this->drawText($commands, $x + 10, $y + 14, 'Validacion, cuentas y representacion impresa', 8, true, [30, 41, 59]);

        $qrSize = 76.0;
        $qrX = $x + 10;
        $qrY = $y + 30;
        $this->drawQrCode($commands, (string) ($data['qr_content'] ?? ''), $qrX, $qrY, $qrSize);

        $infoX = $qrX + $qrSize + 14;
        $infoW = $w - $qrSize - 34;
        $this->drawText($commands, $infoX, $y + 36, 'Comprobante: '.(string) $data['numero'], 8, true, [30, 41, 59]);
        $this->drawText($commands, $infoX + 210, $y + 36, 'Estado: '.(string) $data['estado'], 8, true, [8, 145, 178]);
        $this->drawText($commands, $infoX, $y + 50, 'Hash: '.$this->fitText((string) $data['hash'], $infoW, 6.7), 6.7, false, [71, 85, 105]);

        /** @var array<int, array<string, string>> $bankAccounts */
        $bankAccounts = is_array($data['cuentas_bancarias'] ?? null) ? $data['cuentas_bancarias'] : [];
        $bankY = $y + 65;
        foreach (array_slice($bankAccounts, 0, 2) as $account) {
            $bankLine = $this->joinNonEmpty([
                $account['banco'] ?? '',
                $account['moneda'] ?? '',
                $account['cuenta'] ?? '',
                isset($account['cci']) && $account['cci'] !== '' ? 'CCI '.$account['cci'] : '',
            ]);
            $this->drawText($commands, $infoX, $bankY, $this->fitText($bankLine, $infoW, 6.8), 6.8, false, [51, 65, 85]);
            $bankY += 10;
        }

        $representation = ((string) ($data['tipo'] ?? '')) === 'TK'
            ? 'Documento de venta interno. No es comprobante electronico SUNAT.'
            : 'Representacion impresa del comprobante electronico.';
        $this->drawText($commands, $infoX, $y + 92, $representation, 7.5, true, [51, 65, 85]);
        $this->drawText($commands, $infoX, $y + 106, $this->fitText('Mensaje: '.(string) $data['mensaje'], $infoW, 6.8), 6.8, false, [71, 85, 105]);
        $this->drawText($commands, $infoX, $y + 118, 'Generado: '.(string) $data['generated_at'], 6.5, false, [100, 116, 139]);

        return implode("\n", $commands)."\n";
    }

    /**
     * @param array<string, mixed> $data
     * @return array{content: string, width: float, height: float}
     */
    private function renderTicketPage(array $data): array
    {
        $width = self::TICKET_PAGE_WIDTH;
        $x = self::TICKET_MARGIN;
        $w = $width - (self::TICKET_MARGIN * 2);

        /** @var array<int, array<string, mixed>> $detalles */
        $detalles = is_array($data['detalles'] ?? null) ? $data['detalles'] : [];
        $rows = [];
        $rowsHeight = 0.0;
        foreach ($detalles as $row) {
            $descLines = $this->wrapText((string) ($row['descripcion'] ?? '-'), 104.0, 7.2);
            if ($descLines === []) {
                $descLines = ['-'];
            }

            $rowHeight = max(10.0, (count($descLines) * 7.8) + 3.0);
            $rowsHeight += $rowHeight;
            $rows[] = [
                'row' => $row,
                'desc_lines' => $descLines,
                'row_height' => $rowHeight,
            ];
        }

        $tableHeight = max(70.0, $rowsHeight + 20.0);
        $pageHeight = max(430.0, min(1400.0, (self::TICKET_MARGIN * 2) + 164.0 + $tableHeight + 208.0));
        $this->currentPageHeight = $pageHeight;

        $commands = [];
        $y = self::TICKET_MARGIN;

        $this->drawBox($commands, $x, $y, $w, 88, [203, 213, 225], [255, 255, 255], 0.9);
        $this->drawRect($commands, $x + 7, $y + 8, 34, 34, [223, 230, 240], [249, 250, 251], 0.6);
        $this->drawLogo($commands, (string) ($data['empresa_logo_ref'] ?? ''), $x + 10, $y + 11, 28, 28, (string) ($data['empresa_razon_social'] ?? ''), false);
        $this->drawText($commands, $x + 47, $y + 16, $this->fitText((string) ($data['empresa_nombre_comercial'] ?? 'EMPRESA'), $w - 55, 9), 9, true, [15, 23, 42]);
        $this->drawText($commands, $x + 47, $y + 28, (string) ($data['tipo_label'] ?? 'COMPROBANTE'), 7.2, true, [8, 145, 178]);
        $this->drawText($commands, $x + ($w / 2), $y + 44, (string) ($data['numero'] ?? '-'), 9, true, [15, 23, 42], 'center');
        $this->drawText($commands, $x + 6, $y + 55, 'RUC: '.(string) ($data['empresa_ruc'] ?? '-'), 7, false, [51, 65, 85]);
        $this->drawText($commands, $x + 6, $y + 64, 'Fecha: '.(string) ($data['fecha_emision'] ?? '-'), 7, false, [51, 65, 85]);
        $this->drawText($commands, $x + 6, $y + 74, $this->fitText((string) ($data['empresa_direccion'] ?? '-'), $w - 12, 6.3), 6.3, false, [71, 85, 105]);
        $this->drawText($commands, $x + 6, $y + 83, $this->fitText($this->joinNonEmpty([
            (string) ($data['empresa_telefono'] ?? '-'),
            (string) ($data['empresa_correo'] ?? '-'),
        ]), $w - 12, 6.3), 6.3, false, [71, 85, 105]);

        $y += 94;

        $this->drawBox($commands, $x, $y, $w, 56, [226, 232, 240], [255, 255, 255], 0.8);
        $this->drawText($commands, $x + 6, $y + 13, 'Cliente: '.$this->fitText((string) ($data['cliente_nombre'] ?? 'CLIENTES VARIOS'), $w - 12, 7.2), 7.2, true, [30, 41, 59]);
        $this->drawText(
            $commands,
            $x + 6,
            $y + 24,
            'Doc: '.(string) ($data['cliente_tipo_doc'] ?? '0').' '.(string) ($data['cliente_num_doc'] ?? '-'),
            7,
            false,
            [71, 85, 105]
        );
        $this->drawText($commands, $x + 6, $y + 35, 'Moneda: '.(string) ($data['moneda'] ?? 'PEN'), 7, false, [71, 85, 105]);
        $this->drawText($commands, $x + 6, $y + 46, 'Pago: '.(string) ($data['forma_pago'] ?? 'CONTADO'), 7, false, [71, 85, 105]);

        $y += 62;

        $this->drawBox($commands, $x, $y, $w, $tableHeight, [203, 213, 225], [255, 255, 255], 0.8);
        $this->drawBox($commands, $x, $y, $w, 16, [8, 145, 178], [8, 145, 178], 0.8);

        $colWidths = [12.0, $w - 88.0, 24.0, 28.0, 24.0];
        $colLabels = ['#', 'Descripcion', 'Cant', 'P.U.', 'Imp'];
        $colX = [$x];
        foreach ($colWidths as $colWidth) {
            $colX[] = end($colX) + $colWidth;
        }

        foreach ($colX as $lineX) {
            $this->drawLine($commands, $lineX, $y, $lineX, $y + $tableHeight, [226, 232, 240], 0.5);
        }

        foreach ($colLabels as $index => $label) {
            $this->drawText(
                $commands,
                $colX[$index] + ($colWidths[$index] / 2),
                $y + 11.5,
                $label,
                6.8,
                true,
                [255, 255, 255],
                'center'
            );
        }

        $currentY = $y + 20;
        foreach ($rows as $entry) {
            $row = is_array($entry['row'] ?? null) ? $entry['row'] : [];
            $descLines = is_array($entry['desc_lines'] ?? null) ? $entry['desc_lines'] : ['-'];
            $rowHeight = (float) ($entry['row_height'] ?? 10.0);

            $this->drawText($commands, $colX[0] + ($colWidths[0] / 2), $currentY + 7.5, (string) ($row['index'] ?? ''), 7, false, [30, 41, 59], 'center');

            $descY = $currentY + 7.5;
            foreach ($descLines as $line) {
                $this->drawText($commands, $colX[1] + 2, $descY, (string) $line, 7, false, [51, 65, 85]);
                $descY += 7.8;
            }

            $this->drawText($commands, $colX[2] + ($colWidths[2] - 2), $currentY + 7.5, (string) ($row['cantidad_fmt'] ?? '0'), 7, false, [30, 41, 59], 'right');
            $this->drawText($commands, $colX[3] + ($colWidths[3] - 2), $currentY + 7.5, (string) ($row['valor_unitario_fmt'] ?? '0.00'), 7, false, [30, 41, 59], 'right');
            $this->drawText($commands, $colX[4] + ($colWidths[4] - 2), $currentY + 7.5, (string) ($row['total_fmt'] ?? '0.00'), 7, true, [15, 23, 42], 'right');

            $this->drawLine($commands, $x, $currentY + $rowHeight, $x + $w, $currentY + $rowHeight, [241, 245, 249], 0.5);
            $currentY += $rowHeight;
        }

        if ($rows === []) {
            $this->drawText($commands, $x + ($w / 2), $y + 34, 'Sin items registrados', 7, false, [100, 116, 139], 'center');
        }

        $y += $tableHeight + 8;

        $this->drawBox($commands, $x, $y, $w, 76, [203, 213, 225], [250, 253, 255], 0.8);
        $left = $x + 6;
        $right = $x + $w - 6;
        $this->drawText($commands, $left, $y + 15, 'Sub total', 7, false, [71, 85, 105]);
        $this->drawText($commands, $right, $y + 15, (string) ($data['simbolo'] ?? 'S/').' '.number_format((float) ($data['sub_total'] ?? 0), 2, '.', ','), 7, false, [30, 41, 59], 'right');
        $this->drawText($commands, $left, $y + 27, 'IGV', 7, false, [71, 85, 105]);
        $this->drawText($commands, $right, $y + 27, (string) ($data['simbolo'] ?? 'S/').' '.number_format((float) ($data['igv'] ?? 0), 2, '.', ','), 7, false, [30, 41, 59], 'right');
        $this->drawText($commands, $left, $y + 39, 'Descuento', 7, false, [71, 85, 105]);
        $this->drawText($commands, $right, $y + 39, (string) ($data['simbolo'] ?? 'S/').' '.number_format((float) ($data['descuento'] ?? 0), 2, '.', ','), 7, false, [30, 41, 59], 'right');
        $this->drawLine($commands, $left, $y + 48, $right, $y + 48, [203, 213, 225], 0.8);
        $this->drawText($commands, $left, $y + 61, 'TOTAL', 8.5, true, [15, 23, 42]);
        $this->drawText($commands, $right, $y + 61, (string) ($data['simbolo'] ?? 'S/').' '.number_format((float) ($data['total'] ?? 0), 2, '.', ','), 9, true, [8, 145, 178], 'right');

        $y += 82;

        $this->drawBox($commands, $x, $y, $w, 112, [226, 232, 240], [255, 255, 255], 0.8);
        $isInternalTicket = ((string) ($data['tipo'] ?? '')) === 'TK';
        $representation = $isInternalTicket
            ? 'Documento de venta interno - no se registra en SUNAT'
            : 'Representacion impresa del comprobante electronico';
        $representationColor = $isInternalTicket ? [220, 38, 38] : [8, 145, 178];
        $this->drawText($commands, $x + ($w / 2), $y + 13, $representation, 6.8, true, $representationColor, 'center');
        $this->drawQrCode($commands, (string) ($data['qr_content'] ?? ''), $x + 7, $y + 22, 58.0);
        $footerX = $x + 72;
        $footerW = $w - 78;
        $this->drawText($commands, $footerX, $y + 31, $this->fitText((string) ($data['numero'] ?? '-'), $footerW, 7.2), 7.2, true, [30, 41, 59]);
        $this->drawText($commands, $footerX, $y + 43, $this->fitText((string) ($data['monto_letras'] ?? '-'), $footerW, 6.4), 6.4, false, [51, 65, 85]);
        $this->drawText($commands, $footerX, $y + 56, $this->fitText('Hash: '.(string) ($data['hash'] ?? '-'), $footerW, 6.1), 6.1, false, [71, 85, 105]);
        $this->drawText($commands, $footerX, $y + 69, $this->fitText('Mensaje: '.(string) ($data['mensaje'] ?? '-'), $footerW, 6.1), 6.1, false, [71, 85, 105]);
        $this->drawText($commands, $footerX, $y + 82, 'Generado: '.(string) ($data['generated_at'] ?? '-'), 6.0, false, [100, 116, 139]);
        $this->drawText($commands, $x + ($w / 2), $y + 101, 'Gracias por su compra', 7.2, true, [30, 41, 59], 'center');

        return [
            'content' => implode("\n", $commands)."\n",
            'width' => $width,
            'height' => $pageHeight,
        ];
    }

    private function buildPdfDocument(string $content, float $pageWidth, float $pageHeight): string
    {
        $imageResourceParts = [];
        foreach ($this->imageXObjects as $index => $image) {
            $imageResourceParts[] = '/'.$image['name'].' '.(6 + $index).' 0 R';
        }

        $contentObjectId = 6 + count($this->imageXObjects);
        $xObjectResource = $imageResourceParts !== []
            ? ' /XObject << '.implode(' ', $imageResourceParts).' >>'
            : '';

        $objects = [];
        $objects[] = '<< /Type /Catalog /Pages 2 0 R >>';
        $objects[] = '<< /Type /Pages /Kids [3 0 R] /Count 1 >>';
        $objects[] = '<< /Type /Page /Parent 2 0 R /MediaBox [0 0 '.$pageWidth.' '.$pageHeight.'] /Resources << /Font << /F1 4 0 R /F2 5 0 R >>'.$xObjectResource.' >> /Contents '.$contentObjectId.' 0 R >>';
        $objects[] = '<< /Type /Font /Subtype /Type1 /BaseFont /Helvetica >>';
        $objects[] = '<< /Type /Font /Subtype /Type1 /BaseFont /Helvetica-Bold >>';

        foreach ($this->imageXObjects as $image) {
            $objects[] = "<< /Type /XObject /Subtype /Image /Width ".$image['width'].' /Height '.$image['height']." /ColorSpace /DeviceRGB /BitsPerComponent 8 /Filter /DCTDecode /Length ".strlen($image['data'])." >>\nstream\n".$image['data']."\nendstream";
        }

        $objects[] = '<< /Length '.strlen($content)." >>\nstream\n".$content."endstream";

        $pdf = "%PDF-1.4\n";
        $offsets = [0];

        foreach ($objects as $index => $object) {
            $offsets[] = strlen($pdf);
            $pdf .= ($index + 1)." 0 obj\n".$object."\nendobj\n";
        }

        $xrefOffset = strlen($pdf);
        $pdf .= "xref\n";
        $pdf .= '0 '.(count($objects) + 1)."\n";
        $pdf .= "0000000000 65535 f \n";

        for ($index = 1; $index <= count($objects); $index++) {
            $pdf .= sprintf("%010d 00000 n \n", $offsets[$index]);
        }

        $pdf .= 'trailer << /Size '.(count($objects) + 1).' /Root 1 0 R >>'."\n";
        $pdf .= "startxref\n".$xrefOffset."\n%%EOF";

        return $pdf;
    }

    /**
     * @param array<int, mixed> $detalles
     * @return array{rows: array<int, array<string, mixed>>, calculated: array<string, float>}
     */
    private function buildDetailData(array $detalles): array
    {
        $rows = [];
        $subTotal = 0.0;
        $igv = 0.0;
        $discount = 0.0;
        $otherCharges = 0.0;
        $otherTaxes = 0.0;
        $icbper = 0.0;
        $total = 0.0;

        foreach ($detalles as $index => $item) {
            if (! is_array($item)) {
                continue;
            }

            $quantity = $this->num($item['cantidad'] ?? 0);
            $valueUnit = $this->num($item['valor_unitario'] ?? ($item['mto_valor_unitario'] ?? 0));
            $lineBase = $this->num($item['mto_valor_venta'] ?? max(0, $quantity * $valueUnit));
            $lineDiscount = $this->num($item['descuento'] ?? ($item['descuento_monto'] ?? 0));
            $lineIgv = $this->num($item['igv'] ?? 0);
            $lineIcbper = $this->num($item['icbper'] ?? 0);
            $lineOtherTax = $this->num($item['otro_tributo'] ?? ($item['mto_otro_tributo'] ?? 0));
            $lineOtherCharge = $this->num($item['cargo'] ?? 0);
            $lineTotal = $this->num($item['total'] ?? max(0, ($lineBase - $lineDiscount) + $lineIgv + $lineIcbper + $lineOtherTax + $lineOtherCharge));

            $subTotal += $lineBase;
            $discount += $lineDiscount;
            $igv += $lineIgv;
            $icbper += $lineIcbper;
            $otherTaxes += $lineOtherTax;
            $otherCharges += $lineOtherCharge;
            $total += $lineTotal;

            $rows[] = [
                'index' => $index + 1,
                'codigo' => $this->txt($item['codigo'] ?? ('ITEM'.($index + 1))),
                'descripcion' => $this->txt($item['descripcion'] ?? ('ITEM '.($index + 1))),
                'unidad' => strtoupper($this->txt($item['unidad'] ?? 'NIU')),
                'cantidad_fmt' => number_format($quantity, 2, '.', ','),
                'valor_unitario_fmt' => number_format($valueUnit, 2, '.', ','),
                'precio_unitario_fmt' => number_format($quantity > 0 ? ($lineTotal / $quantity) : 0, 2, '.', ','),
                'descuento_fmt' => number_format($lineDiscount, 2, '.', ','),
                'igv_fmt' => number_format($lineIgv, 2, '.', ','),
                'total_fmt' => number_format($lineTotal, 2, '.', ','),
            ];
        }

        return [
            'rows' => $rows,
            'calculated' => [
                'sub_total' => $subTotal,
                'igv' => $igv,
                'discount' => $discount,
                'other_charges' => $otherCharges,
                'other_taxes' => $otherTaxes,
                'icbper' => $icbper,
                'total' => $total,
            ],
        ];
    }

    /**
     * @param array<string, mixed> $documento
     * @param array<int, string> $keys
     */
    private function resolveAmount(array $documento, array $keys, float $default): float
    {
        foreach ($keys as $key) {
            $value = $this->arr($documento, $key);
            if (is_numeric($value)) {
                return (float) $value;
            }
        }

        return $default;
    }

    /**
     * @param array<string, mixed> $documento
     */
    private function resolveAmountInWords(array $documento, float $total, string $currencyCode): string
    {
        $provided = trim((string) ($documento['monto_letras'] ?? ''));
        if ($provided !== '') {
            return strtoupper($provided);
        }

        $currencyLabel = match (strtoupper($currencyCode)) {
            'USD' => 'DOLARES',
            'EUR' => 'EUROS',
            default => 'SOLES',
        };

        try {
            $formatter = new NumeroALetras();
            return strtoupper((string) $formatter->toInvoice($total, 2, $currencyLabel));
        } catch (\Throwable) {
            return 'IMPORTE TOTAL EN '.$currencyLabel;
        }
    }

    /**
     * SUNAT usa estos datos como representacion QR del comprobante. Para tickets
     * internos se conserva la misma estructura como identificador verificable.
     *
     * @param array<string, mixed> $data
     */
    private function buildQrContent(array $data): string
    {
        $date = (string) ($data['fecha_emision'] ?? '');
        try {
            $date = (new \DateTime($date))->format('Y-m-d');
        } catch (\Throwable) {
            $date = substr($date, 0, 10);
        }

        [$serie, $correlativo] = array_pad(explode('-', (string) ($data['numero'] ?? ''), 2), 2, '');

        return implode('|', [
            (string) ($data['empresa_ruc'] ?? ''),
            (string) ($data['tipo'] ?? ''),
            $serie,
            $correlativo,
            number_format((float) ($data['igv'] ?? 0), 2, '.', ''),
            number_format((float) ($data['total'] ?? 0), 2, '.', ''),
            $date,
            (string) ($data['cliente_tipo_doc'] ?? '0'),
            (string) ($data['cliente_num_doc'] ?? ''),
        ]);
    }

    /**
     * @param array<int, mixed> $accounts
     * @return array<int, array{banco: string, moneda: string, cuenta: string, cci: string}>
     */
    private function normalizeBankAccounts(array $accounts): array
    {
        $normalized = [];
        foreach ($accounts as $account) {
            if (! is_array($account)) {
                continue;
            }

            $bank = trim((string) ($account['banco'] ?? $account['entidad'] ?? ''));
            $currency = strtoupper(trim((string) ($account['moneda'] ?? '')));
            $number = trim((string) ($account['cuenta'] ?? $account['numero_cuenta'] ?? ''));
            $cci = trim((string) ($account['cci'] ?? $account['codigo_interbancario'] ?? ''));
            if ($bank === '' && $number === '' && $cci === '') {
                continue;
            }

            $normalized[] = [
                'banco' => $bank,
                'moneda' => $currency,
                'cuenta' => $number,
                'cci' => $cci,
            ];
        }

        return $normalized;
    }

    /**
     * @param array<int, string> $values
     */
    private function joinNonEmpty(array $values): string
    {
        return implode(' | ', array_values(array_filter(
            array_map(static fn (string $value): string => trim($value), $values),
            static fn (string $value): bool => $value !== '' && $value !== '-'
        )));
    }

    /**
     * Dibuja el QR como vectores PDF para no depender de Imagick en produccion.
     *
     * @param array<int, string> $commands
     */
    private function drawQrCode(array &$commands, string $content, float $x, float $yTop, float $size): void
    {
        $payload = trim($content);
        if ($payload === '') {
            return;
        }

        try {
            $matrix = Encoder::encode($payload, ErrorCorrectionLevel::M(), 'UTF-8', null, false)->getMatrix();
        } catch (\Throwable) {
            return;
        }

        $quietZone = 4;
        $modules = $matrix->getWidth() + ($quietZone * 2);
        $moduleSize = $size / max(1, $modules);
        $this->drawBox($commands, $x, $yTop, $size, $size, [255, 255, 255], [255, 255, 255], 0.0);

        for ($row = 0; $row < $matrix->getHeight(); $row++) {
            for ($column = 0; $column < $matrix->getWidth(); $column++) {
                if ($matrix->get($column, $row) !== 1) {
                    continue;
                }

                $rectX = $x + (($column + $quietZone) * $moduleSize);
                $rectYTop = $yTop + (($row + $quietZone) * $moduleSize);
                $rectY = $this->currentPageHeight - $rectYTop - $moduleSize;
                $commands[] = sprintf(
                    '0 0 0 rg %.3f %.3f %.3f %.3f re f',
                    $rectX,
                    $rectY,
                    $moduleSize + 0.04,
                    $moduleSize + 0.04
                );
            }
        }
    }

    /**
     * @param array<string, mixed> $empresa
     */
    private function buildEmpresaDireccion(array $empresa): string
    {
        $direccionData = is_array($empresa['direccion'] ?? null) ? $empresa['direccion'] : [];

        $direccion = trim((string) ($direccionData['direccion'] ?? ($empresa['direccion_fiscal'] ?? '')));
        $distrito = trim((string) ($direccionData['distrito'] ?? ''));
        $provincia = trim((string) ($direccionData['provincia'] ?? ''));
        $departamento = trim((string) ($direccionData['departamento'] ?? ''));
        $ubigeo = trim((string) ($direccionData['ubigeo'] ?? ''));

        $parts = array_values(array_filter([$direccion, $distrito, $provincia, $departamento], fn (string $value): bool => $value !== ''));
        if ($parts === []) {
            $parts[] = '-';
        }

        if ($ubigeo !== '') {
            $parts[] = 'UBIGEO '.$ubigeo;
        }

        return implode(' - ', $parts);
    }

    private function resolveDocumentTypeLabel(string $type): string
    {
        return match (strtoupper($type)) {
            '01' => 'FACTURA ELECTRONICA',
            '03' => 'BOLETA DE VENTA ELECTRONICA',
            '07' => 'NOTA DE CREDITO ELECTRONICA',
            '08' => 'NOTA DE DEBITO ELECTRONICA',
            '09' => 'GUIA DE REMISION ELECTRONICA',
            'TK' => 'TICKET DE VENTA',
            default => 'COMPROBANTE ELECTRONICO',
        };
    }

    private function currencySymbol(string $currencyCode): string
    {
        return match (strtoupper($currencyCode)) {
            'USD' => 'US$',
            'EUR' => 'EUR',
            default => 'S/',
        };
    }

    private function formatDateTime(string $value): string
    {
        $trimmed = trim($value);
        if ($trimmed === '') {
            return date('Y-m-d H:i:s');
        }

        try {
            return (new \DateTime($trimmed))->format('Y-m-d H:i:s');
        } catch (\Throwable) {
            return $trimmed;
        }
    }

    /**
     * @param array<int, string> $commands
     */
    private function drawLogo(
        array &$commands,
        string $logoRef,
        float $x,
        float $yTop,
        float $w,
        float $h,
        string $fallbackText,
        bool $showFallbackLabel = true,
    ): void {
        $image = $this->resolveLogoImage($logoRef);
        if ($image !== null) {
            $scale = min($w / max(1, $image['width']), $h / max(1, $image['height']));
            $drawW = $image['width'] * $scale;
            $drawH = $image['height'] * $scale;
            $drawX = $x + (($w - $drawW) / 2);
            $drawYTop = $yTop + (($h - $drawH) / 2);
            $drawY = $this->currentPageHeight - $drawYTop - $drawH;

            $commands[] = sprintf(
                'q %.2f 0 0 %.2f %.2f %.2f cm /%s Do Q',
                $drawW,
                $drawH,
                $drawX,
                $drawY,
                $image['name']
            );
            return;
        }

        $this->drawText($commands, $x + ($w / 2), $yTop + ($h / 2) + 3, $this->initials($fallbackText), 17, true, [8, 145, 178], 'center');
        if ($showFallbackLabel) {
            $this->drawText($commands, $x + ($w / 2), $yTop + $h - 8, 'LOGO', 7, true, [100, 116, 139], 'center');
        }
    }

    /**
     * @return array{name: string, width: int, height: int}|null
     */
    private function resolveLogoImage(string $logoRef): ?array
    {
        $bytes = $this->readLogoBytes($logoRef);
        if ($bytes === null) {
            return null;
        }

        $info = @getimagesizefromstring($bytes);
        if ($info === false) {
            return null;
        }

        $width = (int) ($info[0] ?? 0);
        $height = (int) ($info[1] ?? 0);
        $mime = strtolower((string) ($info['mime'] ?? ''));
        if ($width <= 0 || $height <= 0) {
            return null;
        }

        $jpegBytes = $mime === 'image/jpeg' ? $bytes : $this->convertImageToJpeg($bytes, $mime);
        if ($jpegBytes === null) {
            return null;
        }

        $jpegInfo = @getimagesizefromstring($jpegBytes);
        if ($jpegInfo !== false) {
            $width = (int) ($jpegInfo[0] ?? $width);
            $height = (int) ($jpegInfo[1] ?? $height);
        }

        $name = 'Im'.(count($this->imageXObjects) + 1);
        $this->imageXObjects[] = [
            'name' => $name,
            'width' => $width,
            'height' => $height,
            'data' => $jpegBytes,
        ];

        return ['name' => $name, 'width' => $width, 'height' => $height];
    }

    private function readLogoBytes(string $logoRef): ?string
    {
        $ref = trim($logoRef);
        if ($ref === '' || $ref === '-') {
            return null;
        }

        $safeKey = TenantPrivateFileReference::safeKey($this->currentTenantRuc, 'logos', $ref);
        if ($safeKey === null) {
            return null;
        }

        if (Storage::disk(config('facturador.storage.disk', 'tenants'))->exists($safeKey)) {
            return (string) Storage::disk(config('facturador.storage.disk', 'tenants'))->get($safeKey);
        }

        return null;
    }

    private function convertImageToJpeg(string $bytes, string $mime): ?string
    {
        if (! function_exists('imagecreatefromstring') || ! function_exists('imagejpeg')) {
            return null;
        }

        $source = @imagecreatefromstring($bytes);
        if ($source === false) {
            return null;
        }

        $width = imagesx($source);
        $height = imagesy($source);
        $canvas = imagecreatetruecolor($width, $height);
        if ($canvas === false) {
            imagedestroy($source);
            return null;
        }

        $white = imagecolorallocate($canvas, 255, 255, 255);
        imagefilledrectangle($canvas, 0, 0, $width, $height, $white);
        imagecopy($canvas, $source, 0, 0, 0, 0, $width, $height);

        // Muchos logos corporativos llegan con un lienzo blanco mucho mayor
        // que el contenido. Recortarlo aqui permite mostrar el logo libre y a
        // escala correcta sin alterar el archivo privado del tenant.
        $output = $canvas;
        $cropped = function_exists('imagecropauto')
            ? @imagecropauto($canvas, IMG_CROP_THRESHOLD, 12.0, $white)
            : false;
        if ($cropped !== false && imagesx($cropped) > 4 && imagesy($cropped) > 4) {
            $output = $cropped;
        }

        ob_start();
        imagejpeg($output, null, 88);
        $jpeg = ob_get_clean();

        imagedestroy($source);
        if ($output !== $canvas) {
            imagedestroy($output);
        }
        imagedestroy($canvas);

        return is_string($jpeg) && $jpeg !== '' ? $jpeg : null;
    }

    /**
     * @param array<int, string> $commands
     */
    private function drawBox(array &$commands, float $x, float $yTop, float $w, float $h, array $strokeRgb, array $fillRgb, float $lineWidth): void
    {
        $y = $this->currentPageHeight - $yTop - $h;
        $stroke = $this->rgb($strokeRgb);
        $fill = $this->rgb($fillRgb);

        $commands[] = sprintf(
            '%.3f %.3f %.3f rg %.3f %.3f %.3f RG %.2f w %.2f %.2f %.2f %.2f re B',
            $fill[0],
            $fill[1],
            $fill[2],
            $stroke[0],
            $stroke[1],
            $stroke[2],
            $lineWidth,
            $x,
            $y,
            $w,
            $h
        );
    }

    /**
     * @param array<int, string> $commands
     */
    private function drawRect(array &$commands, float $x, float $yTop, float $w, float $h, array $strokeRgb, array $fillRgb, float $lineWidth): void
    {
        $this->drawBox($commands, $x, $yTop, $w, $h, $strokeRgb, $fillRgb, $lineWidth);
    }

    /**
     * @param array<int, string> $commands
     */
    private function drawLine(array &$commands, float $x1, float $yTop1, float $x2, float $yTop2, array $strokeRgb, float $lineWidth): void
    {
        $y1 = $this->currentPageHeight - $yTop1;
        $y2 = $this->currentPageHeight - $yTop2;
        $stroke = $this->rgb($strokeRgb);

        $commands[] = sprintf(
            '%.3f %.3f %.3f RG %.2f w %.2f %.2f m %.2f %.2f l S',
            $stroke[0],
            $stroke[1],
            $stroke[2],
            $lineWidth,
            $x1,
            $y1,
            $x2,
            $y2
        );
    }

    /**
     * @param array<int, string> $commands
     */
    private function drawText(
        array &$commands,
        float $x,
        float $yTop,
        string $text,
        float $size = 9,
        bool $bold = false,
        array $rgb = [17, 24, 39],
        string $align = 'left'
    ): void {
        $ascii = $this->ascii($text);
        $safeText = $this->escapePdfText($ascii);
        $font = $bold ? 'F2' : 'F1';

        if ($align === 'right') {
            $x -= $this->estimateTextWidth($ascii, $size);
        } elseif ($align === 'center') {
            $x -= $this->estimateTextWidth($ascii, $size) / 2;
        }

        $color = $this->rgb($rgb);
        $y = $this->currentPageHeight - $yTop;

        $commands[] = sprintf(
            'BT /%s %.2f Tf %.3f %.3f %.3f rg 1 0 0 1 %.2f %.2f Tm (%s) Tj ET',
            $font,
            $size,
            $color[0],
            $color[1],
            $color[2],
            $x,
            $y,
            $safeText
        );
    }

    /**
     * Ajusta solo la tipografia, nunca recorta importes ni cantidades.
     * Esto mantiene cada valor dentro de su columna incluso en papel de 80 mm.
     *
     * @param array<int, string> $commands
     * @param array<int, int> $rgb
     */
    private function drawTextWithinWidth(
        array &$commands,
        float $x,
        float $yTop,
        string $text,
        float $maxWidth,
        float $preferredSize,
        bool $bold = false,
        array $rgb = [17, 24, 39],
        string $align = 'left',
    ): void {
        $fontSize = $preferredSize;
        $estimatedWidth = $this->estimateTextWidth($this->ascii($text), $preferredSize);
        if ($estimatedWidth > $maxWidth && $estimatedWidth > 0.0) {
            $fontSize = floor(($preferredSize * $maxWidth / $estimatedWidth) * 100) / 100;
            $fontSize = max(1.0, $fontSize);
            while ($fontSize > 1.0 && $this->estimateTextWidth($this->ascii($text), $fontSize) > $maxWidth) {
                $fontSize = round($fontSize - 0.01, 2);
            }
        }

        $this->drawText($commands, $x, $yTop, $text, $fontSize, $bold, $rgb, $align);
    }

    /**
     * @param array<int, int> $rgb
     * @return array{0: float, 1: float, 2: float}
     */
    private function rgb(array $rgb): array
    {
        return [
            max(0.0, min(1.0, ((int) ($rgb[0] ?? 0)) / 255)),
            max(0.0, min(1.0, ((int) ($rgb[1] ?? 0)) / 255)),
            max(0.0, min(1.0, ((int) ($rgb[2] ?? 0)) / 255)),
        ];
    }

    /**
     * @return array{0: int, 1: int, 2: int}
     */
    private function statusColor(string $status): array
    {
        return match (strtoupper(trim($status))) {
            'ACEPTADO' => [16, 185, 129],
            'RECHAZADO', 'ERROR' => [220, 38, 38],
            'PENDIENTE', 'RECIBIDO', 'EN_PROCESO', 'PROCESANDO' => [245, 158, 11],
            default => [8, 145, 178],
        };
    }

    /**
     * @return array<int, string>
     */
    private function wrapText(string $text, float $maxWidth, float $fontSize): array
    {
        $value = trim($this->ascii($text));
        if ($value === '') {
            return [];
        }

        $words = preg_split('/\s+/', $value) ?: [];
        $lines = [];
        $current = '';

        foreach ($words as $word) {
            if ($word === '') {
                continue;
            }

            $candidate = $current === '' ? $word : $current.' '.$word;
            if ($this->estimateTextWidth($candidate, $fontSize) <= $maxWidth) {
                $current = $candidate;
                continue;
            }

            if ($current !== '') {
                $lines[] = $current;
                $current = '';
            }

            if ($this->estimateTextWidth($word, $fontSize) <= $maxWidth) {
                $current = $word;
                continue;
            }

            $segments = $this->splitWordByWidth($word, $maxWidth, $fontSize);
            foreach ($segments as $segmentIndex => $segment) {
                if ($segmentIndex === (count($segments) - 1)) {
                    $current = $segment;
                } else {
                    $lines[] = $segment;
                }
            }
        }

        if ($current !== '') {
            $lines[] = $current;
        }

        return $lines;
    }

    /**
     * @return array<int, string>
     */
    private function splitWordByWidth(string $word, float $maxWidth, float $fontSize): array
    {
        $chars = preg_split('//u', $word, -1, PREG_SPLIT_NO_EMPTY) ?: [];
        $segments = [];
        $current = '';

        foreach ($chars as $char) {
            $candidate = $current.$char;
            if ($current !== '' && $this->estimateTextWidth($candidate, $fontSize) > $maxWidth) {
                $segments[] = $current;
                $current = $char;
                continue;
            }
            $current = $candidate;
        }

        if ($current !== '') {
            $segments[] = $current;
        }

        return $segments === [] ? [$word] : $segments;
    }

    private function fitText(string $text, float $maxWidth, float $fontSize): string
    {
        $value = trim($this->ascii($text));
        if ($value === '') {
            return '-';
        }

        if ($this->estimateTextWidth($value, $fontSize) <= $maxWidth) {
            return $value;
        }

        $ellipsis = '...';
        $chars = preg_split('//u', $value, -1, PREG_SPLIT_NO_EMPTY) ?: [];
        $fit = '';
        foreach ($chars as $char) {
            $candidate = $fit.$char;
            if ($this->estimateTextWidth($candidate.$ellipsis, $fontSize) > $maxWidth) {
                break;
            }
            $fit = $candidate;
        }

        return $fit === '' ? $ellipsis : $fit.$ellipsis;
    }

    private function estimateTextWidth(string $text, float $fontSize): float
    {
        $widthUnits = 0.0;
        $chars = preg_split('//u', $text, -1, PREG_SPLIT_NO_EMPTY) ?: [];

        foreach ($chars as $char) {
            if ($char === ' ') {
                $widthUnits += 0.27;
                continue;
            }

            if (preg_match('/[ilI1\.\,\:\;\|\'`]/', $char) === 1) {
                $widthUnits += 0.24;
                continue;
            }

            if (preg_match('/[W@%M]/', $char) === 1) {
                $widthUnits += 0.88;
                continue;
            }

            if (preg_match('/[A-Z]/', $char) === 1) {
                $widthUnits += 0.64;
                continue;
            }

            if (preg_match('/[0-9]/', $char) === 1) {
                $widthUnits += 0.56;
                continue;
            }

            $widthUnits += 0.53;
        }

        return $widthUnits * $fontSize;
    }

    private function initials(string $name): string
    {
        $normalized = trim($this->ascii($name));
        if ($normalized === '') {
            return 'AZ';
        }

        $parts = preg_split('/[\s\.\-_]+/', $normalized) ?: [];
        $letters = '';

        foreach ($parts as $part) {
            if ($part === '') {
                continue;
            }
            $letters .= strtoupper(substr($part, 0, 1));
            if (strlen($letters) >= 2) {
                break;
            }
        }

        return $letters !== '' ? $letters : 'AZ';
    }

    /**
     * @param array<string, mixed> $source
     */
    private function arr(array $source, string $path, mixed $default = null): mixed
    {
        $segments = explode('.', $path);
        $current = $source;

        foreach ($segments as $segment) {
            if (! is_array($current) || ! array_key_exists($segment, $current)) {
                return $default;
            }
            $current = $current[$segment];
        }

        return $current;
    }

    private function txt(mixed $value): string
    {
        $text = is_scalar($value) ? (string) $value : '-';
        $text = trim($text);

        return $text === '' ? '-' : $text;
    }

    private function num(mixed $value): float
    {
        return (float) (is_numeric($value) ? $value : 0);
    }

    private function ascii(string $text): string
    {
        $normalized = @iconv('UTF-8', 'ASCII//TRANSLIT//IGNORE', $text);
        if ($normalized === false) {
            return $text;
        }

        return $normalized;
    }

    private function escapePdfText(string $text): string
    {
        return str_replace(
            ['\\', '(', ')', "\r", "\n"],
            ['\\\\', '\(', '\)', ' ', ' '],
            $text
        );
    }
}
