<?php

namespace App\Infrastructure\Pdf;

/**
 * Medicion y ajuste de texto para las fuentes Helvetica del PDF.
 *
 * Vivia como helpers privados dentro de SimpleDocumentPdfGenerator. Son
 * funciones puras -- solo dependen del texto y del tamano de fuente -- asi que
 * separarlas deja el generador ocupandose de la maquetacion y hace que estas
 * reglas puedan probarse de forma aislada.
 */
final class PdfTextMetrics
{
    /**
     * Anchos relativos aproximados de Helvetica, en unidades de tamano de
     * fuente. No hay tabla de metricas embebida, asi que se estima por familia
     * de caracteres; basta para decidir saltos y recortes de linea.
     */
    private const WIDTH_SPACE = 0.27;

    private const WIDTH_NARROW = 0.24;

    private const WIDTH_WIDE = 0.88;

    private const WIDTH_UPPERCASE = 0.64;

    private const WIDTH_DIGIT = 0.56;

    private const WIDTH_DEFAULT = 0.53;

    private const ELLIPSIS = '...';

    /**
     * Transcribe a ASCII: las fuentes base del PDF no llevan acentos.
     */
    public static function ascii(string $text): string
    {
        $normalized = @iconv('UTF-8', 'ASCII//TRANSLIT//IGNORE', $text);
        if ($normalized === false) {
            return $text;
        }

        return $normalized;
    }

    public static function escapePdfText(string $text): string
    {
        return str_replace(
            ['\\', '(', ')', "\r", "\n"],
            ['\\\\', '\(', '\)', ' ', ' '],
            $text
        );
    }

    public static function estimateTextWidth(string $text, float $fontSize): float
    {
        $widthUnits = 0.0;
        $chars = preg_split('//u', $text, -1, PREG_SPLIT_NO_EMPTY) ?: [];

        foreach ($chars as $char) {
            $widthUnits += self::charWidth($char);
        }

        return $widthUnits * $fontSize;
    }

    private static function charWidth(string $char): float
    {
        return match (true) {
            $char === ' ' => self::WIDTH_SPACE,
            preg_match('/[ilI1\.\,\:\;\|\'`]/', $char) === 1 => self::WIDTH_NARROW,
            preg_match('/[W@%M]/', $char) === 1 => self::WIDTH_WIDE,
            preg_match('/[A-Z]/', $char) === 1 => self::WIDTH_UPPERCASE,
            preg_match('/[0-9]/', $char) === 1 => self::WIDTH_DIGIT,
            default => self::WIDTH_DEFAULT,
        };
    }

    /**
     * Reparte el texto en lineas que caben en $maxWidth.
     *
     * @return array<int, string>
     */
    public static function wrapText(string $text, float $maxWidth, float $fontSize): array
    {
        $value = trim(self::ascii($text));
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
            if (self::estimateTextWidth($candidate, $fontSize) <= $maxWidth) {
                $current = $candidate;

                continue;
            }

            if ($current !== '') {
                $lines[] = $current;
                $current = '';
            }

            if (self::estimateTextWidth($word, $fontSize) <= $maxWidth) {
                $current = $word;

                continue;
            }

            // Una palabra mas ancha que la columna se parte por caracteres.
            $segments = self::splitWordByWidth($word, $maxWidth, $fontSize);
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
    public static function splitWordByWidth(string $word, float $maxWidth, float $fontSize): array
    {
        $chars = preg_split('//u', $word, -1, PREG_SPLIT_NO_EMPTY) ?: [];
        $segments = [];
        $current = '';

        foreach ($chars as $char) {
            $candidate = $current.$char;
            if ($current !== '' && self::estimateTextWidth($candidate, $fontSize) > $maxWidth) {
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

    /**
     * Recorta con puntos suspensivos. Solo debe usarse en textos descriptivos:
     * importes y cantidades se ajustan reduciendo la fuente, nunca cortando.
     */
    public static function fitText(string $text, float $maxWidth, float $fontSize): string
    {
        $value = trim(self::ascii($text));
        if ($value === '') {
            return '-';
        }

        if (self::estimateTextWidth($value, $fontSize) <= $maxWidth) {
            return $value;
        }

        $chars = preg_split('//u', $value, -1, PREG_SPLIT_NO_EMPTY) ?: [];
        $fit = '';
        foreach ($chars as $char) {
            $candidate = $fit.$char;
            if (self::estimateTextWidth($candidate.self::ELLIPSIS, $fontSize) > $maxWidth) {
                break;
            }
            $fit = $candidate;
        }

        return $fit === '' ? self::ELLIPSIS : $fit.self::ELLIPSIS;
    }

    /**
     * Iniciales para el marcador que sustituye al logo cuando no hay imagen.
     */
    public static function initials(string $name): string
    {
        $normalized = trim(self::ascii($name));
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
}
