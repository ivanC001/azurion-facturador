<?php

namespace Tests\Unit;

use App\Infrastructure\Pdf\SimpleDocumentPdfGenerator;
use App\Support\Tenants\TenantContext;
use App\Support\Tenants\TenantIdentity;
use PHPUnit\Framework\TestCase;

class SimpleDocumentPdfGeneratorTest extends TestCase
{
    public function test_generate_returns_valid_pdf_with_enterprise_sections(): void
    {
        $generator = $this->generator();

        $pdf = $generator->generate([
            'estado' => 'RECIBIDO',
            'mensaje' => 'Documento registrado. Facturacion en cola para SUNAT.',
            'hash' => 'abc123hash',
            'ticket' => 'TCK12345',
            'empresa' => [
                'ruc' => '20601234567',
                'razon_social' => 'EMPRESA DEMO SAC',
                'nombre_comercial' => 'DEMO STORE',
                'direccion' => [
                    'direccion' => 'AV. PRINCIPAL 123',
                    'distrito' => 'LIMA',
                    'provincia' => 'LIMA',
                    'departamento' => 'LIMA',
                    'ubigeo' => '150101',
                ],
            ],
            'cliente' => [
                'tipo_doc' => '6',
                'num_doc' => '20100070970',
                'razon_social' => 'CLIENTE UNO SAC',
                'direccion' => 'JR. CLIENTE 456',
            ],
            'documento' => [
                'tipo' => '03',
                'serie' => 'B001',
                'correlativo' => '246',
                'fecha_emision' => '2026-06-01 10:30:00',
                'moneda' => 'PEN',
                'valor_venta' => 100.00,
                'igv_total' => 18.00,
                'total' => 118.00,
                'forma_pago' => [
                    'tipo' => 'CONTADO',
                ],
            ],
            'detalles' => [
                [
                    'codigo' => 'SKU-001',
                    'descripcion' => 'PRODUCTO DEMO A',
                    'unidad' => 'NIU',
                    'cantidad' => 2,
                    'valor_unitario' => 50,
                    'igv' => 18,
                    'total' => 118,
                ],
            ],
        ]);

        $this->assertStringStartsWith('%PDF-1.4', $pdf);
        $this->assertStringContainsString('CLIENTE', $pdf);
        $this->assertStringContainsString('BOLETA DE VENTA ELECTRONICA', $pdf);
        $this->assertStringContainsString('IMPORTE EN LETRAS', $pdf);
        $this->assertStringContainsString('CUENTAS BANCARIAS', $pdf);
        $this->assertStringContainsString('COND. PAGO', $pdf);
        $this->assertStringContainsString('Representacion impresa', $pdf);
        $this->assertStringContainsString('TOTAL', $pdf);
    }

    public function test_generate_ticket_pdf_uses_ticket_format(): void
    {
        $generator = $this->generator();

        $pdf = $generator->generate([
            'estado' => 'REGISTRADO',
            'mensaje' => 'Ticket de venta registrado en facturador.',
            'empresa' => [
                'ruc' => '20601234567',
                'razon_social' => 'EMPRESA DEMO SAC',
            ],
            'cliente' => [
                'tipo_doc' => '0',
                'num_doc' => '-',
                'razon_social' => 'CLIENTES VARIOS',
            ],
            'documento' => [
                'tipo' => 'TK',
                'serie' => 'T001',
                'correlativo' => '45',
                'fecha_emision' => '2026-06-01 12:00:00',
                'moneda' => 'PEN',
                'valor_venta' => 20.00,
                'igv_total' => 0.00,
                'total' => 20.00,
            ],
            'detalles' => [
                [
                    'codigo' => 'SKU-TK',
                    'descripcion' => 'PRODUCTO TICKET',
                    'unidad' => 'NIU',
                    'cantidad' => 1,
                    'valor_unitario' => 20,
                    'igv' => 0,
                    'total' => 20,
                ],
            ],
        ]);

        $this->assertStringStartsWith('%PDF-1.4', $pdf);
        $this->assertStringContainsString('TICKET DE VENTA', $pdf);
        $this->assertStringContainsString('Documento de venta interno - no SUNAT', $pdf);
        $this->assertStringContainsString('TOTAL', $pdf);
    }

    public function test_every_sales_document_can_render_as_a4_or_thermal_without_changing_its_number(): void
    {
        $generator = $this->generator();
        $context = [
            'estado' => 'ACEPTADO',
            'mensaje' => 'Comprobante aceptado por SUNAT.',
            'hash' => 'hash-format-test',
            'empresa' => [
                'ruc' => '20601234567',
                'razon_social' => 'EMPRESA DEMO SAC',
                'nombre_comercial' => 'DEMO STORE',
                'correo' => 'ventas@demo.test',
                'telefono' => '999999999',
                'direccion' => ['direccion' => 'AV. PRINCIPAL 123'],
            ],
            'cliente' => [
                'tipo_doc' => '1',
                'num_doc' => '12345678',
                'razon_social' => 'CLIENTE DEMO',
            ],
            'documento' => [
                'tipo' => '03',
                'serie' => 'B001',
                'correlativo' => '246',
                'fecha_emision' => '2026-06-01 10:30:00',
                'moneda' => 'PEN',
                'valor_venta' => 100,
                'igv_total' => 18,
                'total' => 118,
            ],
            'detalles' => [[
                'codigo' => 'SKU-001',
                'descripcion' => 'PRODUCTO DEMO',
                'unidad' => 'NIU',
                'cantidad' => 1,
                'valor_unitario' => 100,
                'igv' => 18,
                'total' => 118,
            ]],
        ];

        $a4 = $generator->generate(array_merge($context, ['formato' => 'a4']));
        $thermal = $generator->generate(array_merge($context, ['formato' => 'ticket']));
        $invoiceContext = $context;
        $invoiceContext['documento']['tipo'] = '01';
        $invoiceContext['documento']['serie'] = 'F001';
        $invoiceContext['documento']['correlativo'] = '108';
        $invoiceA4 = $generator->generate(array_merge($invoiceContext, ['formato' => 'a4']));
        $invoiceThermal = $generator->generate(array_merge($invoiceContext, ['formato' => 'ticket']));
        $ticketContext = $context;
        $ticketContext['documento']['tipo'] = 'TK';
        $ticketContext['documento']['serie'] = 'TK01';
        $ticketContext['documento']['correlativo'] = '25';
        $ticketA4 = $generator->generate(array_merge($ticketContext, ['formato' => 'a4']));

        $this->assertStringContainsString('/MediaBox [0 0 595 842]', $a4);
        $this->assertStringContainsString('/MediaBox [0 0 226.77', $thermal);
        $this->assertStringContainsString('BOLETA DE VENTA ELECTRONICA', $a4);
        $this->assertStringContainsString('BOLETA DE VENTA ELECTRONICA', $thermal);
        $this->assertStringContainsString('B001-246', $a4);
        $this->assertStringContainsString('B001-246', $thermal);
        $this->assertStringContainsString('Representacion impresa', $thermal);
        $this->assertStringContainsString('FACTURA ELECTRONICA', $invoiceA4);
        $this->assertStringContainsString('FACTURA ELECTRONICA', $invoiceThermal);
        $this->assertStringContainsString('F001-108', $invoiceA4);
        $this->assertStringContainsString('F001-108', $invoiceThermal);
        $this->assertStringContainsString('/MediaBox [0 0 595 842]', $ticketA4);
        $this->assertStringContainsString('TICKET DE VENTA', $ticketA4);
        $this->assertStringContainsString('TK01-25', $ticketA4);
    }

    private function generator(): SimpleDocumentPdfGenerator
    {
        $context = new TenantContext();
        $context->set(new TenantIdentity(
            tenantId: 1,
            ruc: '20601234567',
            schema: 'tenant_test',
            sunatMode: 'beta',
            countryCode: 'PE',
            documentMode: 'electronic',
            fiscalStatus: 'active',
        ));

        return new SimpleDocumentPdfGenerator($context);
    }
}
