<?php

namespace App\Domain\Pdf\Contracts;

interface DocumentPdfGenerator
{
    /**
     * @param array<string, mixed> $context
     */
    public function generate(array $context): string;
}

