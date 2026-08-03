<?php

namespace Tests\Unit;

use App\Http\Controllers\Api\DocumentoController;
use PHPUnit\Framework\TestCase;
use ReflectionClass;

class DocumentoControllerSalesRulesTest extends TestCase
{
    public function test_high_value_receipt_accepts_company_identified_with_ruc(): void
    {
        $payload = [
            'documento' => ['total' => 1000],
            'cliente' => [
                'tipo_doc' => '6',
                'num_doc' => '20123456789',
                'razon_social' => 'EMPRESA COMPRADORA SAC',
            ],
        ];

        self::assertSame($payload, $this->applySalesDocumentRules($payload, '03'));
    }

    public function test_high_value_receipt_still_accepts_person_identified_with_dni(): void
    {
        $payload = [
            'documento' => ['total' => 1000],
            'cliente' => [
                'tipo_doc' => '1',
                'num_doc' => '12345678',
                'razon_social' => 'CLIENTE PERSONA',
            ],
        ];

        self::assertSame($payload, $this->applySalesDocumentRules($payload, '03'));
    }

    private function applySalesDocumentRules(array $payload, string $documentType): array
    {
        $reflection = new ReflectionClass(DocumentoController::class);
        $controller = $reflection->newInstanceWithoutConstructor();
        $method = $reflection->getMethod('applySalesDocumentRules');

        return $method->invoke($controller, $payload, $documentType);
    }
}
