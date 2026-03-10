<?php

declare(strict_types=1);

namespace CarbonTrack\Services;

use CarbonTrack\Support\SyntheticRequestFactory;
use Monolog\Logger;

/**
 * Lightweight helper providing country/state lookups and validation backed by the shared states.json dataset.
 */
class RegionService
{
    private const DEFAULT_SEPARATOR = ' · ';
    private const COUNTRY_CODE_PATTERN = '/^[A-Z]{2}$/';
    private const STATE_CODE_PATTERN = '/^(?=.{1,10}$)[A-Z0-9]+(?:-[A-Z0-9]+)*$/';

    private array $countries = [];
    private ?Logger $logger;
    private string $datasetPath;
    private ?AuditLogService $auditLogService;
    private ?ErrorLogService $errorLogService;

    public function __construct(
        ?string $datasetPath = null,
        ?Logger $logger = null,
        ?AuditLogService $auditLogService = null,
        ?ErrorLogService $errorLogService = null
    )
    {
        $projectRoot = dirname(__DIR__, 3);
        $defaultPath = $projectRoot . DIRECTORY_SEPARATOR . 'frontend' . DIRECTORY_SEPARATOR . 'public'
            . DIRECTORY_SEPARATOR . 'locales' . DIRECTORY_SEPARATOR . 'states.json';

        $configured = trim((string) ($_ENV['REGION_DATA_PATH'] ?? ''));
        if ($configured !== '') {
            $this->datasetPath = $this->normalizePath($configured, $projectRoot);
        } elseif ($datasetPath !== null && $datasetPath !== '') {
            $this->datasetPath = $this->normalizePath($datasetPath, $projectRoot);
        } else {
            $this->datasetPath = $defaultPath;
        }

        $this->logger = $logger;
        $this->auditLogService = $auditLogService;
        $this->errorLogService = $errorLogService;
        $this->hydrateDataset();
    }

    public function isReady(): bool
    {
        return !empty($this->countries);
    }

    public function normalizeCountryCode(?string $code): ?string
    {
        if (!is_string($code)) {
            return null;
        }
        $code = strtoupper(trim($code));
        if ($code === '') {
            return null;
        }
        if (!empty($this->countries)) {
            return isset($this->countries[$code]) ? $code : null;
        }

        return preg_match(self::COUNTRY_CODE_PATTERN, $code) === 1 ? $code : null;
    }

    public function normalizeStateCode(?string $code): ?string
    {
        if (!is_string($code)) {
            return null;
        }
        $code = strtoupper(trim(str_replace([' ', '.'], '', $code)));
        if ($code === '') {
            return null;
        }

        return preg_match(self::STATE_CODE_PATTERN, $code) === 1 ? $code : null;
    }

    public function buildRegionCode(string $countryCode, string $stateCode): string
    {
        return sprintf('%s-%s', strtoupper($countryCode), strtoupper($stateCode));
    }

    public function parseRegionCode(?string $regionCode): ?array
    {
        if (!is_string($regionCode) || trim($regionCode) === '') {
            return null;
        }
        $normalized = strtoupper(trim($regionCode));
        $separatorPosition = strpos($normalized, '-');
        if ($separatorPosition === false) {
            return null;
        }

        $country = substr($normalized, 0, $separatorPosition);
        $state = substr($normalized, $separatorPosition + 1);
        $country = $this->normalizeCountryCode($country);
        $state = $this->normalizeStateCode($state);
        if ($country === null || $state === null) {
            return null;
        }

        return [
            'country_code' => $country,
            'state_code' => $state,
        ];
    }

    public function isValidRegion(?string $countryCode, ?string $stateCode): bool
    {
        $country = $this->normalizeCountryCode($countryCode);
        if ($country === null) {
            return false;
        }
        $state = $this->normalizeStateCode($stateCode);
        if ($state === null) {
            return false;
        }
        if (empty($this->countries)) {
            return true;
        }
        return isset($this->countries[$country]['states'][$state]);
    }

    public function getRegionContext(?string $regionCode): ?array
    {
        $parsed = $this->parseRegionCode($regionCode);
        if (!$parsed) {
            return null;
        }

        $countryCode = $parsed['country_code'];
        $stateCode = $parsed['state_code'];
        $countryName = $this->getCountryName($countryCode);
        $stateName = $this->getStateName($countryCode, $stateCode);

        return [
            'region_code' => $this->buildRegionCode($countryCode, $stateCode),
            'country_code' => $countryCode,
            'state_code' => $stateCode,
            'country_name' => $countryName,
            'state_name' => $stateName,
            'region_label' => $this->getRegionLabel($regionCode),
        ];
    }

    public function getCountryName(?string $countryCode): ?string
    {
        $code = $this->normalizeCountryCode($countryCode);
        return $code !== null ? ($this->countries[$code]['name'] ?? null) : null;
    }

    public function getStateName(?string $countryCode, ?string $stateCode): ?string
    {
        $country = $this->normalizeCountryCode($countryCode);
        if ($country === null) {
            return null;
        }
        $state = $this->normalizeStateCode($stateCode);
        if ($state === null) {
            return null;
        }
        return $this->countries[$country]['states'][$state]['name'] ?? null;
    }

    public function getRegionLabel(?string $regionCode, string $separator = self::DEFAULT_SEPARATOR): ?string
    {
        $parsed = $this->parseRegionCode($regionCode);
        if (!$parsed) {
            return null;
        }
        $country = $this->getCountryName($parsed['country_code']);
        $state = $this->getStateName($parsed['country_code'], $parsed['state_code']);
        if ($country === null && $state === null) {
            return null;
        }
        if ($country !== null && $state !== null) {
            return $country . $separator . $state;
        }
        return $country ?? $state;
    }

    private function hydrateDataset(): void
    {
        $path = $this->datasetPath;
        if (!is_file($path)) {
            $this->logDatasetIssue('region_dataset_missing', 'Region dataset not found', ['path' => $path]);
            $this->log('warning', 'Region dataset not found', ['path' => $path]);
            return;
        }

        $contents = @file_get_contents($path);
        if ($contents === false) {
            $this->logDatasetIssue('region_dataset_unreadable', 'Unable to read region dataset', ['path' => $path]);
            $this->log('warning', 'Unable to read region dataset', ['path' => $path]);
            return;
        }

        $decoded = json_decode($contents, true);
        if (!is_array($decoded)) {
            $this->logDatasetIssue('region_dataset_decode_failed', 'Unable to decode region dataset', ['path' => $path]);
            $this->log('warning', 'Unable to decode region dataset', ['path' => $path]);
            return;
        }

        foreach ($decoded as $country) {
            if (!is_array($country)) {
                continue;
            }
            $code = isset($country['iso2']) ? strtoupper(trim((string) $country['iso2'])) : null;
            $name = isset($country['name']) ? trim((string) $country['name']) : null;
            if (!$code || !$name) {
                continue;
            }

            $states = [];
            if (!empty($country['states']) && is_array($country['states'])) {
                foreach ($country['states'] as $state) {
                    if (!is_array($state)) {
                        continue;
                    }
                    $stateCode = isset($state['state_code']) ? strtoupper(trim((string) $state['state_code'])) : null;
                    $stateName = isset($state['name']) ? trim((string) $state['name']) : null;
                    if (!$stateCode || !$stateName) {
                        continue;
                    }
                    $states[$stateCode] = [
                        'code' => $stateCode,
                        'name' => $stateName,
                    ];
                }
            }

            if (empty($states)) {
                continue;
            }

            $this->countries[$code] = [
                'code' => $code,
                'name' => $name,
                'states' => $states,
            ];
        }

        if (empty($this->countries)) {
            $this->logDatasetIssue('region_dataset_empty', 'Region dataset parsed but no usable countries were found', ['path' => $path]);
            $this->log('warning', 'Region dataset parsed but no usable countries were found', ['path' => $path]);
        }
    }

    private function normalizePath(string $path, string $projectRoot): string
    {
        if ($path[0] === '/' || $path[0] === '\\' || preg_match('/^[A-Za-z]:[\\\\\\/]/', $path)) {
            return $path;
        }

        return rtrim($projectRoot, DIRECTORY_SEPARATOR) . DIRECTORY_SEPARATOR . ltrim($path, DIRECTORY_SEPARATOR);
    }

    private function log(string $level, string $message, array $context = []): void
    {
        if (!$this->logger) {
            return;
        }
        try {
            $this->logger->log($level, $message, $context);
        } catch (\Throwable $ignore) {
            // suppress logging failures
        }
    }

    private function logDatasetIssue(string $action, string $message, array $context): void
    {
        if ($this->auditLogService !== null) {
            try {
                $this->auditLogService->log([
                    'action' => $action,
                    'operation_category' => 'system',
                    'actor_type' => 'system',
                    'status' => 'failed',
                    'data' => $context,
                ]);
            } catch (\Throwable $ignore) {
                // ignore audit failures for region service
            }
        }

        if ($this->errorLogService === null) {
            return;
        }

        try {
            $request = SyntheticRequestFactory::fromContext('/internal/regions/dataset', 'GET', null, $context);
            $this->errorLogService->logError('RegionDatasetError', $message, $request, $context);
        } catch (\Throwable $ignore) {
            // ignore error log failures for region service
        }
    }
}
