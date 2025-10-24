<?php

declare(strict_types=1);

namespace CarbonTrack\Services;

use JsonException;

class AdminAiCommandRepository
{
    /**
     * @param array<int,string> $paths
     */
    public function __construct(array $paths)
    {
        $this->paths = array_values(array_filter($paths, static fn ($path) => is_string($path) && $path !== ''));
    }

    /**
     * @return array<string,mixed>
     */
    public function getConfig(): array
    {
        $this->ensureFreshConfig();

        return $this->cachedConfig ?? [];
    }

    public function getFingerprint(): string
    {
        $this->ensureFreshConfig();

        return $this->activeFingerprint ?? 'none';
    }

    public function getActivePath(): ?string
    {
        $this->ensureFreshConfig();

        return $this->activePath;
    }

    public function getLastModified(): ?int
    {
        $this->ensureFreshConfig();

        return $this->activeModifiedAt;
    }

    public function reload(): void
    {
        $this->resetCache();
        $this->ensureFreshConfig();
    }

    /**
     * @return array<int,string>
     */
    public function getPaths(): array
    {
        return $this->paths;
    }

    private function ensureFreshConfig(): void
    {
        foreach ($this->paths as $path) {
            if (!is_file($path) || !is_readable($path)) {
                continue;
            }

            $modifiedAt = @filemtime($path) ?: null;

            if ($this->activePath === $path && $this->cachedConfig !== null && $this->activeModifiedAt === $modifiedAt) {
                return;
            }

            $config = require $path;
            if (!is_array($config)) {
                continue;
            }

            $this->cachedConfig = $config;
            $this->activePath = $path;
            $this->activeModifiedAt = $modifiedAt;
            $this->activeFingerprint = $this->computeFingerprint($config, $path, $modifiedAt);
            $this->lastLoadedAt = microtime(true);

            return;
        }

        if ($this->cachedConfig === null) {
            $this->cachedConfig = [];
            $this->activePath = null;
            $this->activeModifiedAt = null;
            $this->activeFingerprint = null;
            $this->lastLoadedAt = microtime(true);
        }
    }

    private function resetCache(): void
    {
        $this->cachedConfig = null;
        $this->activePath = null;
        $this->activeModifiedAt = null;
        $this->activeFingerprint = null;
        $this->lastLoadedAt = null;
    }

    /**
     * @param array<string,mixed> $config
     */
    private function computeFingerprint(array $config, string $path, ?int $modifiedAt): string
    {
        try {
            $encoded = json_encode($config, JSON_THROW_ON_ERROR | JSON_UNESCAPED_UNICODE | JSON_UNESCAPED_SLASHES);
        } catch (JsonException) {
            $encoded = serialize($config);
        }

        return sha1($path . '|' . (string) $modifiedAt . '|' . $encoded);
    }

    /**
     * @var array<string,mixed>|null
     */
    private ?array $cachedConfig = null;

    private ?string $activePath = null;

    private ?int $activeModifiedAt = null;

    private ?string $activeFingerprint = null;

    private ?float $lastLoadedAt = null;

    /**
     * @var array<int,string>
     */
    private array $paths;
}
