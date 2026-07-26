<?php

namespace App\Application\Documentos\Services;

use Illuminate\Validation\ValidationException;

final class DocumentoTaxPayloadValidator
{
    private const TAX_DOCUMENT_TYPES = ['01', '03', '07', '08', 'TK'];

    private const TAXABLE_AFFECTATIONS = ['10', '11', '12', '13', '14', '15', '16', '17'];

    private const FREE_AFFECTATIONS = ['11', '12', '13', '14', '15', '16', '17', '21', '31', '32', '33', '34', '35', '36'];

    private const EXEMPT_AFFECTATIONS = ['20', '21'];

    private const UNAFFECTED_AFFECTATIONS = ['30', '31', '32', '33', '34', '35', '36'];

    private const EXPORT_AFFECTATIONS = ['40'];

    private const ALLOWED_AFFECTATIONS = [
        '10', '11', '12', '13', '14', '15', '16', '17',
        '20', '21',
        '30', '31', '32', '33', '34', '35', '36',
        '40',
    ];

    /**
     * Validate the frozen tax result sent by Azurion without choosing tax rules.
     *
     * @param  array<string, mixed>  $payload
     * @return array<string, mixed>
     */
    public function validate(array $payload, string $documentType): array
    {
        if (! in_array($documentType, self::TAX_DOCUMENT_TYPES, true)) {
            return $payload;
        }

        $errors = [];
        $details = data_get($payload, 'detalles');
        if (! is_array($details) || $details === []) {
            $errors['detalles'][] = 'El documento debe incluir al menos un detalle tributario.';
            $this->throwIfInvalid($errors);
        }

        if (trim((string) data_get($payload, 'documento.tipo_operacion', '')) === '') {
            $errors['documento.tipo_operacion'][] = 'Azurion debe enviar el tipo de operacion tributaria.';
        }

        $sums = [
            'mto_oper_gravadas' => 0.0,
            'mto_oper_exoneradas' => 0.0,
            'mto_oper_inafectas' => 0.0,
            'mto_oper_exportacion' => 0.0,
            'mto_oper_gratuitas' => 0.0,
            'mto_igv_gratuitas' => 0.0,
            'igv_total' => 0.0,
            'total_impuestos' => 0.0,
            'valor_venta' => 0.0,
        ];

        foreach ($details as $index => $detail) {
            $path = 'detalles.'.$index;
            if (! is_array($detail)) {
                $errors[$path][] = 'El detalle tributario debe ser un objeto.';

                continue;
            }

            $affectation = trim((string) ($detail['tip_afe_igv'] ?? ''));
            $taxCode = trim((string) ($detail['tributo_codigo'] ?? ''));
            $percentage = $this->requiredNumber($detail, 'porcentaje_igv', $path, $errors);
            $base = $this->requiredNumber($detail, 'mto_valor_venta', $path, $errors);
            $igv = $this->requiredNumber($detail, 'igv', $path, $errors);
            $lineTotal = $this->requiredNumber($detail, 'total', $path, $errors);
            $lineTaxes = $this->requiredNumber($detail, 'total_impuestos', $path, $errors);

            if (! in_array($affectation, self::ALLOWED_AFFECTATIONS, true)) {
                $errors[$path.'.tip_afe_igv'][] = 'Azurion debe enviar un tipo de afectacion IGV SUNAT valido.';
            }
            if ($taxCode === '') {
                $errors[$path.'.tributo_codigo'][] = 'Azurion debe enviar el codigo de tributo.';
            }

            foreach ([
                'porcentaje_igv' => $percentage,
                'mto_valor_venta' => $base,
                'igv' => $igv,
                'total' => $lineTotal,
                'total_impuestos' => $lineTaxes,
            ] as $field => $value) {
                if ($value !== null && $value < 0) {
                    $errors[$path.'.'.$field][] = 'El importe tributario no puede ser negativo.';
                }
            }
            if ($percentage !== null && $percentage > 100) {
                $errors[$path.'.porcentaje_igv'][] = 'El porcentaje tributario no puede ser mayor a 100.';
            }

            if ($percentage === null || $base === null || $igv === null || $lineTaxes === null) {
                continue;
            }

            $isTaxable = in_array($affectation, self::TAXABLE_AFFECTATIONS, true);
            $expectedIgv = $isTaxable ? round($base * ($percentage / 100), 2) : 0.0;
            if (! $this->sameMoney($igv, $expectedIgv)) {
                $errors[$path.'.igv'][] = sprintf(
                    'El IGV enviado (%.2f) no coincide con la base y porcentaje enviados (%.2f).',
                    $igv,
                    $expectedIgv,
                );
            }

            if (! $isTaxable && (! $this->sameMoney($percentage, 0.0) || ! $this->sameMoney($igv, 0.0))) {
                $errors[$path.'.porcentaje_igv'][] = 'Una afectacion exonerada, inafecta o de exportacion debe llegar con IGV 0.';
            }

            $isFree = in_array($affectation, self::FREE_AFFECTATIONS, true);
            if ($isFree) {
                $sums['mto_oper_gratuitas'] += $base;
                $sums['mto_igv_gratuitas'] += $igv;
            } elseif (in_array($affectation, self::EXEMPT_AFFECTATIONS, true)) {
                $sums['mto_oper_exoneradas'] += $base;
            } elseif (in_array($affectation, self::UNAFFECTED_AFFECTATIONS, true)) {
                $sums['mto_oper_inafectas'] += $base;
            } elseif (in_array($affectation, self::EXPORT_AFFECTATIONS, true)) {
                $sums['mto_oper_exportacion'] += $base;
            } else {
                $sums['mto_oper_gravadas'] += $base;
            }

            if (! $isFree) {
                $sums['igv_total'] += $igv;
                $sums['valor_venta'] += $base;
                $sums['total_impuestos'] += $lineTaxes;
            }
        }

        $document = (array) data_get($payload, 'documento', []);
        foreach ([
            'mto_oper_gravadas',
            'mto_oper_exoneradas',
            'mto_oper_inafectas',
            'mto_oper_exportacion',
            'igv_total',
            'total_impuestos',
            'valor_venta',
            'sub_total',
            'total',
        ] as $field) {
            $amount = $this->requiredNumber($document, $field, 'documento', $errors);
            if ($amount !== null && $amount < 0) {
                $errors['documento.'.$field][] = 'El importe tributario no puede ser negativo.';
            }
        }

        foreach ([
            'mto_oper_gravadas',
            'mto_oper_exoneradas',
            'mto_oper_inafectas',
            'mto_oper_exportacion',
            'igv_total',
            'total_impuestos',
            'valor_venta',
        ] as $field) {
            $provided = $document[$field] ?? null;
            if (is_numeric($provided) && ! $this->sameMoney((float) $provided, round($sums[$field], 2), count($details))) {
                $errors['documento.'.$field][] = sprintf(
                    'El total enviado (%.2f) no coincide con la suma de detalles (%.2f).',
                    (float) $provided,
                    round($sums[$field], 2),
                );
            }
        }

        if (is_numeric($document['sub_total'] ?? null)) {
            $expectedSubTotal = round($sums['valor_venta'] + $sums['total_impuestos'], 2);
            if (! $this->sameMoney((float) $document['sub_total'], $expectedSubTotal, count($details))) {
                $errors['documento.sub_total'][] = sprintf(
                    'El subtotal enviado (%.2f) no coincide con valor de venta mas impuestos (%.2f).',
                    (float) $document['sub_total'],
                    $expectedSubTotal,
                );
            }
        }

        if ($sums['mto_oper_gratuitas'] > 0) {
            foreach (['mto_oper_gratuitas', 'mto_igv_gratuitas'] as $field) {
                $provided = $document[$field] ?? null;
                if (! is_numeric($provided)) {
                    $errors['documento.'.$field][] = 'Azurion debe enviar este total para operaciones gratuitas.';
                } elseif (! $this->sameMoney((float) $provided, round($sums[$field], 2), count($details))) {
                    $errors['documento.'.$field][] = sprintf(
                        'El total enviado (%.2f) no coincide con la suma de detalles (%.2f).',
                        (float) $provided,
                        round($sums[$field], 2),
                    );
                }
            }
        }

        $this->throwIfInvalid($errors);

        return $payload;
    }

    /**
     * @param  array<string, mixed>  $source
     * @param  array<string, array<int, string>>  $errors
     */
    private function requiredNumber(array $source, string $field, string $path, array &$errors): ?float
    {
        $value = $source[$field] ?? null;
        if (! is_numeric($value)) {
            $errors[$path.'.'.$field][] = 'Azurion debe enviar un valor numerico.';

            return null;
        }

        return (float) $value;
    }

    private function sameMoney(float $provided, float $expected, int $lineCount = 1): bool
    {
        return abs($provided - $expected) <= max(0.02, $lineCount * 0.01);
    }

    /**
     * @param  array<string, array<int, string>>  $errors
     */
    private function throwIfInvalid(array $errors): void
    {
        if ($errors !== []) {
            throw ValidationException::withMessages($errors);
        }
    }
}
