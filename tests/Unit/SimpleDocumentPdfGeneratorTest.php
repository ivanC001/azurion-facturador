<?php

namespace Tests\Unit;

use App\Infrastructure\Pdf\SimpleDocumentPdfGenerator;
use PHPUnit\Framework\TestCase;

class SimpleDocumentPdfGeneratorTest extends TestCase
{
    public function test_generate_returns_valid_pdf_with_enterprise_sections(): void
    {
        $generator = new SimpleDocumentPdfGenerator();

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
        $this->assertStringContainsString('EMISOR', $pdf);
        $this->assertStringContainsString('BOLETA DE VENTA ELECTRONICA', $pdf);
        $this->assertStringContainsString('Importe en letras', $pdf);
        $this->assertStringContainsString('TOTAL', $pdf);
    }

    public function test_generate_ticket_pdf_uses_ticket_format(): void
    {
        $generator = new SimpleDocumentPdfGenerator();

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
        $this->assertStringContainsString('ticket no SUNAT', $pdf);
        $this->assertStringContainsString('TOTAL', $pdf);
    }
}
