<?php

namespace Tests\Unit;

use App\Application\Documentos\Services\DocumentoTaxPayloadValidator;
use Illuminate\Validation\ValidationException;
use Tests\TestCase;

final class DocumentoTaxPayloadValidatorTest extends TestCase
{
    private DocumentoTaxPayloadValidator $validator;

    protected function setUp(): void
    {
        parent::setUp();

        $this->validator = new DocumentoTaxPayloadValidator;
    }

    public function test_accepts_custom_tax_percentage_sent_by_azurion_without_changing_it(): void
    {
        $payload = $this->payload(
            affectation: '10',
            taxCode: '1000',
            percentage: 10.0,
            base: 100.0,
            igv: 10.0,
            total: 110.0,
            operationField: 'mto_oper_gravadas',
        );

        $this->assertSame($payload, $this->validator->validate($payload, '01'));
    }

    public function test_accepts_exempt_product_with_zero_igv(): void
    {
        $payload = $this->payload(
            affectation: '20',
            taxCode: '9997',
            percentage: 0.0,
            base: 100.0,
            igv: 0.0,
            total: 100.0,
            operationField: 'mto_oper_exoneradas',
        );

        $this->assertSame($payload, $this->validator->validate($payload, '03'));
    }

    public function test_accepts_unaffected_product_with_zero_igv(): void
    {
        $payload = $this->payload(
            affectation: '30',
            taxCode: '9998',
            percentage: 0.0,
            base: 100.0,
            igv: 0.0,
            total: 100.0,
            operationField: 'mto_oper_inafectas',
        );

        $this->assertSame($payload, $this->validator->validate($payload, 'TK'));
    }

    public function test_rejects_igv_that_does_not_match_sent_base_and_percentage(): void
    {
        $payload = $this->payload(
            affectation: '10',
            taxCode: '1000',
            percentage: 18.0,
            base: 100.0,
            igv: 15.0,
            total: 115.0,
            operationField: 'mto_oper_gravadas',
        );

        try {
            $this->validator->validate($payload, '01');
            $this->fail('Expected tax validation to fail.');
        } catch (ValidationException $exception) {
            $this->assertArrayHasKey('detalles.0.igv', $exception->errors());
        }
    }

    public function test_rejects_document_tax_total_that_does_not_match_details(): void
    {
        $payload = $this->payload(
            affectation: '10',
            taxCode: '1000',
            percentage: 18.0,
            base: 100.0,
            igv: 18.0,
            total: 118.0,
            operationField: 'mto_oper_gravadas',
        );
        $payload['documento']['igv_total'] = 10.0;

        try {
            $this->validator->validate($payload, '01');
            $this->fail('Expected document tax total validation to fail.');
        } catch (ValidationException $exception) {
            $this->assertArrayHasKey('documento.igv_total', $exception->errors());
        }
    }

    /**
     * @return array<string, mixed>
     */
    private function payload(
        string $affectation,
        string $taxCode,
        float $percentage,
        float $base,
        float $igv,
        float $total,
        string $operationField,
    ): array {
        $document = [
            'tipo_operacion' => '0101',
            'mto_oper_gravadas' => 0.0,
            'mto_oper_exoneradas' => 0.0,
            'mto_oper_inafectas' => 0.0,
            'mto_oper_exportacion' => 0.0,
            'igv_total' => $igv,
            'total_impuestos' => $igv,
            'valor_venta' => $base,
            'sub_total' => $total,
            'total' => $total,
        ];
        $document[$operationField] = $base;

        return [
            'empresa' => ['ruc' => '20601234567'],
            'cliente' => [],
            'documento' => $document,
            'detalles' => [[
                'codigo' => 'SKU-001',
                'descripcion' => 'PRODUCTO',
                'cantidad' => 1,
                'mto_valor_venta' => $base,
                'porcentaje_igv' => $percentage,
                'igv' => $igv,
                'tributo_codigo' => $taxCode,
                'tip_afe_igv' => $affectation,
                'total_impuestos' => $igv,
                'total' => $total,
            ]],
        ];
    }
}
