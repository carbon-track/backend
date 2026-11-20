<?php

declare(strict_types=1);

function readComposerManifest(string $filename): array
{
    $path = __DIR__ . '/' . $filename;
    if (!is_readable($path)) {
        throw new RuntimeException("Cannot read {$filename}.");
    }

    $manifest = json_decode((string) file_get_contents($path), true, 512, JSON_THROW_ON_ERROR);
    if (!is_array($manifest['packages'] ?? null) || !array_is_list($manifest['packages'])) {
        throw new RuntimeException("Invalid package list in {$filename}.");
    }

    return $manifest;
}

function indexComposerPackages(array $packages): array
{
    $indexed = [];
    foreach ($packages as $package) {
        $name = $package['name'] ?? null;
        if (!is_string($name) || !is_string($package['version'] ?? null) || isset($indexed[$name])) {
            throw new RuntimeException('Composer package entries must have unique names and valid versions.');
        }
        $indexed[$name] = $package;
    }

    return $indexed;
}

function composerPackageIdentity(array $package): array
{
    return [
        $package['version'] ?? null,
        $package['source']['reference'] ?? null,
        $package['dist']['reference'] ?? null,
        $package['dist']['shasum'] ?? null,
    ];
}

try {
    if (!is_file(__DIR__ . '/vendor/autoload.php')) {
        throw new RuntimeException('vendor/autoload.php is missing.');
    }

    $lock = readComposerManifest('composer.lock');
    $installed = readComposerManifest('vendor/composer/installed.json');
    if (($installed['dev'] ?? null) !== false || ($installed['dev-package-names'] ?? []) !== []) {
        throw new RuntimeException('The committed vendor directory must contain a production install.');
    }

    $expected = indexComposerPackages($lock['packages']);
    $actual = indexComposerPackages($installed['packages']);
    $mismatched = [];
    foreach (array_keys($expected + $actual) as $name) {
        if (composerPackageIdentity($expected[$name] ?? []) !== composerPackageIdentity($actual[$name] ?? [])) {
            $mismatched[] = $name;
        }
    }
    if ($mismatched !== []) {
        throw new RuntimeException('Package versions or references differ from composer.lock for ' . implode(', ', $mismatched) . '.');
    }

    foreach ($actual as $name => $package) {
        $path = $package['install-path'] ?? null;
        if (($package['type'] ?? null) !== 'metapackage'
            && (!is_string($path) || $path === '' || !is_dir(__DIR__ . '/vendor/composer/' . $path))) {
            throw new RuntimeException("The installed directory for {$name} is missing.");
        }
    }

    fwrite(STDOUT, 'Committed vendor matches composer.lock (' . count($expected) . ' production packages).' . PHP_EOL);
} catch (Throwable $exception) {
    fwrite(STDERR, 'Committed vendor check failed. ' . $exception->getMessage() . PHP_EOL);
    fwrite(STDERR, 'Run composer install --no-dev --prefer-dist --optimize-autoloader in backend and stage vendor/.' . PHP_EOL);
    exit(1);
}
