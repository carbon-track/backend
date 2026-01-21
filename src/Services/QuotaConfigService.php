<?php

declare(strict_types=1);

namespace CarbonTrack\Services;

class QuotaConfigService
{
    public function getQuotaDefinitions(): array
    {
        static $definitions = null;
        if ($definitions !== null) {
            return $definitions;
        }

        $raw = $_ENV['QUOTA_DEFINITIONS'] ?? getenv('QUOTA_DEFINITIONS') ?: '';
        $definitions = array_values(array_filter(array_map('trim', explode(',', $raw)), 'strlen'));
        return $definitions;
    }

    public function decodeJsonToArray($value): ?array
    {
        if (is_array($value)) {
            return $value;
        }
        if (!is_string($value)) {
            return null;
        }
        $trimmed = trim($value);
        if ($trimmed === '') {
            return null;
        }
        $decoded = json_decode($trimmed, true);
        if (is_array($decoded)) {
            return $decoded;
        }
        if (is_string($decoded)) {
            $decodedAgain = json_decode($decoded, true);
            return is_array($decodedAgain) ? $decodedAgain : null;
        }
        return null;
    }

    public function flattenQuotas(?array $json): array
    {
        $json = $this->normalizeQuotaConfig($json);
        $flat = [];
        foreach ($this->getQuotaDefinitions() as $key) {
            $parts = explode('.', $key);
            $value = $json;
            foreach ($parts as $part) {
                if (is_array($value) && array_key_exists($part, $value)) {
                    $value = $value[$part];
                } else {
                    $value = null;
                    break;
                }
            }
            $flat[$key] = $value;
        }
        return $flat;
    }

    public function unflattenQuotas(array $flat, ?array $currentJson): array
    {
        $result = $this->normalizeQuotaConfig($currentJson);

        foreach ($flat as $dotKey => $value) {
            if (!in_array($dotKey, $this->getQuotaDefinitions(), true)) {
                continue;
            }

            $parts = explode('.', $dotKey);
            $temp = &$result;

            foreach ($parts as $i => $part) {
                if ($i === count($parts) - 1) {
                    if ($value === '' || $value === null) {
                        unset($temp[$part]);
                    } else {
                        $temp[$part] = is_numeric($value) ? (int) $value : $value;
                    }
                } else {
                    if (!isset($temp[$part]) || !is_array($temp[$part])) {
                        $temp[$part] = [];
                    }
                    $temp = &$temp[$part];
                }
            }
        }

        return $result;
    }

    public function normalizeQuotaConfig(?array $config): array
    {
        $normalized = $config ?? [];

        foreach ($this->getQuotaDefinitions() as $dotKey) {
            if (!array_key_exists($dotKey, $normalized)) {
                continue;
            }
            $value = $normalized[$dotKey];
            unset($normalized[$dotKey]);

            $parts = explode('.', $dotKey);
            $temp = &$normalized;
            foreach ($parts as $i => $part) {
                if ($i === count($parts) - 1) {
                    $temp[$part] = $value;
                } else {
                    if (!isset($temp[$part]) || !is_array($temp[$part])) {
                        $temp[$part] = [];
                    }
                    $temp = &$temp[$part];
                }
            }
        }

        return $normalized;
    }
}
