<?php

declare(strict_types=1);

namespace CarbonTrack\Services;

class UserProfileViewService
{
    public function __construct(private RegionService $regionService)
    {
    }

    /**
     * @param array<string, mixed> $row
     * @return array<string, mixed>
     */
    public function buildProfileFields(array $row): array
    {
        return array_merge(
            $this->buildSchoolFields($row),
            $this->buildRegionFields($row)
        );
    }

    /**
     * @param array<string, mixed> $row
     * @param array<string, mixed>|null $profileFields
     * @return array<string, mixed>
     */
    public function buildLegacyDisplayFields(array $row, ?array $profileFields = null): array
    {
        $profileFields ??= $this->buildProfileFields($row);
        $legacySchool = $this->normalizeText($row['school'] ?? null);
        $legacyLocation = $this->normalizeText($row['location'] ?? null);

        return [
            'school' => $profileFields['school_name'] ?? $legacySchool,
            'location' => $profileFields['region_label']
                ?? $profileFields['region_code']
                ?? $legacyLocation,
        ];
    }

    /**
     * @param array<string, mixed> $row
     * @return array<string, mixed>
     */
    public function buildSchoolFields(array $row): array
    {
        $schoolId = $this->normalizeSchoolId($row['school_id'] ?? null);
        $joinedSchoolName = $this->normalizeText($row['school_name'] ?? null);
        $legacySchoolName = $this->normalizeText($row['school'] ?? null);

        $schoolName = $joinedSchoolName ?? $legacySchoolName;

        return [
            'school_id' => $schoolId,
            'school_name' => $schoolName,
        ];
    }

    /**
     * @param array<string, mixed> $row
     * @return array<string, mixed>
     */
    public function buildRegionFields(array $row): array
    {
        $storedRegionCode = $this->normalizeText($row['region_code'] ?? null);
        $legacyLocation = $this->normalizeText($row['location'] ?? null);

        $resolved = $this->resolveCompatibleRegion($storedRegionCode)
            ?? $this->resolveCompatibleRegion($legacyLocation);

        if ($resolved !== null) {
            return $resolved;
        }

        return [
            'region_code' => $storedRegionCode ?? $legacyLocation,
            'region_label' => null,
            'country_code' => null,
            'state_code' => null,
            'country_name' => null,
            'state_name' => null,
        ];
    }

    /**
     * @return array<string, mixed>|null
     */
    private function resolveCompatibleRegion(?string $value): ?array
    {
        if ($value === null) {
            return null;
        }

        return $this->regionService->getRegionContext($value);
    }

    private function normalizeText(mixed $value): ?string
    {
        if ($value === null) {
            return null;
        }

        $text = trim((string) $value);
        return $text === '' ? null : $text;
    }

    private function normalizeSchoolId(mixed $value): ?int
    {
        if (!is_numeric($value)) {
            return null;
        }

        $schoolId = (int) $value;
        return $schoolId > 0 ? $schoolId : null;
    }
}
