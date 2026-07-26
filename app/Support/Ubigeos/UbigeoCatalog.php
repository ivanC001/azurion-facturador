<?php

namespace App\Support\Ubigeos;

use Illuminate\Support\Facades\DB;

final class UbigeoCatalog
{
    /**
     * @var array<string, string>|null
     */
    private ?array $normalizedMap = null;

    public function normalize(?string $rawUbigeo): ?string
    {
        $candidate = $this->sanitize($rawUbigeo);
        if ($candidate === null) {
            return null;
        }

        $map = $this->normalizedMap();
        if ($map === []) {
            return $candidate;
        }

        return $map[$candidate] ?? null;
    }

    /**
     * @return array<string, string>
     */
    private function normalizedMap(): array
    {
        if ($this->normalizedMap !== null) {
            return $this->normalizedMap;
        }

        $map = [];

        foreach ($this->loadFromDatabase() as $code) {
            $map[$code] = $code;
        }

        foreach ($this->loadFromCsv() as $from => $to) {
            $map[$from] = $to;
        }

        $this->normalizedMap = $map;

        return $this->normalizedMap;
    }

    /**
     * @return array<int, string>
     */
    private function loadFromDatabase(): array
    {
        try {
            return DB::table('ubigeos')
                ->whereNotNull('codigo')
                ->pluck('codigo')
                ->map(fn (mixed $value): ?string => $this->sanitize((string) $value))
                ->filter()
                ->values()
                ->all();
        } catch (\Throwable) {
            return [];
        }
    }

    /**
     * @return array<string, string>
     */
    private function loadFromCsv(): array
    {
        $path = trim((string) config('facturador.ubigeos.equivalences_csv_path', ''));
        if ($path === '' || ! is_file($path) || ! is_readable($path)) {
            return [];
        }

        $handle = fopen($path, 'rb');
        if (! is_resource($handle)) {
            return [];
        }

        try {
            $header = fgetcsv($handle);
            if (! is_array($header)) {
                return [];
            }

            $header = array_map([$this, 'normalizeHeader'], $header);
            $sunatIdx = array_search('cod_ubigeo_sunat', $header, true);
            $reniecIdx = array_search('cod_ubigeo_reniec', $header, true);
            $ineiIdx = array_search('cod_ubigeo_inei', $header, true);

            if ($sunatIdx === false) {
                return [];
            }

            $map = [];

            while (($row = fgetcsv($handle)) !== false) {
                if (! is_array($row)) {
                    continue;
                }

                $sunat = $this->sanitize((string) ($row[$sunatIdx] ?? ''));
                if ($sunat === null) {
                    continue;
                }

                $map[$sunat] = $sunat;

                if ($reniecIdx !== false) {
                    $reniec = $this->sanitize((string) ($row[$reniecIdx] ?? ''));
                    if ($reniec !== null) {
                        $map[$reniec] = $sunat;
                    }
                }

                if ($ineiIdx !== false) {
                    $inei = $this->sanitize((string) ($row[$ineiIdx] ?? ''));
                    if ($inei !== null) {
                        $map[$inei] = $sunat;
                    }
                }
            }

            return $map;
        } finally {
            fclose($handle);
        }
    }

    private function normalizeHeader(string $value): string
    {
        $value = preg_replace('/^\xEF\xBB\xBF/u', '', $value) ?? $value;

        return trim($value, "\" \t\n\r\0\x0B");
    }

    private function sanitize(?string $value): ?string
    {
        $digits = preg_replace('/\D+/', '', (string) $value) ?? '';
        if (strlen($digits) < 6) {
            return null;
        }

        $digits = substr($digits, 0, 6);

        return strlen($digits) === 6 ? $digits : null;
    }
}
