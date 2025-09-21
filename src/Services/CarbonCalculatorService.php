<?php

declare(strict_types=1);

namespace CarbonTrack\Services;

use CarbonTrack\Models\CarbonActivity;
use Monolog\Logger;

class CarbonCalculatorService
{
    private ?Logger $logger;

    public function __construct(?Logger $logger = null)
    {
        $this->logger = $logger;
    }

    /**
     * Calculate carbon reduction (for testing)
     */
    public function calculateCarbonReduction(array $activity, float $amount): float
    {
        if ($amount < 0) {
            return 0.0;
        }
        
        $carbonFactor = $activity['carbon_factor'] ?? 0;
        return $carbonFactor * $amount;
    }

    /**
     * Calculate points from carbon amount
     */
    public function calculatePoints(float $carbonAmount, int $pointsPerKg = 10): int
    {
        return (int) ($carbonAmount * $pointsPerKg);
    }

    /**
     * Validate activity data for create/update flows.
     */
    public function validateActivityData(array $activity, bool $isUpdate = false): bool
    {
        $required = ['name_zh', 'name_en', 'carbon_factor', 'unit', 'category'];
        $allowed = array_merge($required, ['description_zh', 'description_en', 'icon', 'is_active', 'sort_order']);

        // Ensure at least one recognised field is present for updates
        if ($isUpdate) {
            $presentKeys = array_intersect(array_keys($activity), $allowed);
            if (empty($presentKeys)) {
                return false;
            }
        }

        foreach ($required as $field) {
            if ($isUpdate && !array_key_exists($field, $activity)) {
                continue;
            }

            if ($this->isBlank($activity[$field] ?? null)) {
                return false;
            }
        }

        if (array_key_exists('carbon_factor', $activity)) {
            if (!is_numeric($activity['carbon_factor'])) {
                return false;
            }

            if ((float) $activity['carbon_factor'] < 0) {
                return false;
            }
        }

        if (array_key_exists('sort_order', $activity) && !is_numeric($activity['sort_order'])) {
            return false;
        }

        if (array_key_exists('is_active', $activity)) {
            $value = $activity['is_active'];
            if (!is_bool($value) && !in_array($value, [0, 1, '0', '1', 'true', 'false', 'on', 'off', 'yes', 'no'], true)) {
                return false;
            }
        }

        return true;
    }

    /**
     * Validate amount
     */
    public function validateAmount(float $amount): bool
    {
        return $amount >= 0;
    }

    /**
     * Get supported units
     */
    public function getSupportedUnits(): array
    {
        return ['km', 'kg', 'hours', 'times', 'kWh', 'liters', 'days', 'minutes'];
    }

    /**
     * Get carbon factor by category
     */
    public function getCarbonFactorByCategory(string $category): array
    {
        $factors = [
            'transport' => [
                'car' => 2.3,
                'bus' => 0.8,
                'bicycle' => 0.0,
                'walking' => 0.0
            ],
            'energy' => [
                'electricity' => 0.5,
                'gas' => 2.0
            ]
        ];
        
        return $factors[$category] ?? [];
    }

    /**
     * Convert units
     */
    public function convertUnits(float $value, string $fromUnit, string $toUnit): float
    {
        $conversions = [
            'km' => ['m' => 1000],
            'kg' => ['g' => 1000],
        ];
        
        if ($fromUnit === $toUnit) {
            return $value;
        }
        
        if (isset($conversions[$fromUnit][$toUnit])) {
            return $value * $conversions[$fromUnit][$toUnit];
        }
        
        return $value; // Return original if conversion not supported
    }

    /**
     * Calculate monthly stats
     */
    public function calculateMonthlyStats(array $activities): array
    {
        if (empty($activities)) {
            return [
                'total_carbon_saved' => 0.0,
                'total_points_earned' => 0,
                'total_activities' => 0,
                'average_carbon_per_activity' => 0.0
            ];
        }
        
        $totalCarbon = array_sum(array_column($activities, 'carbon_amount'));
        $totalPoints = array_sum(array_column($activities, 'points'));
        $totalCount = count($activities);
        
        return [
            'total_carbon_saved' => $totalCarbon,
            'total_points_earned' => $totalPoints,
            'total_activities' => $totalCount,
            'average_carbon_per_activity' => $totalCount > 0 ? $totalCarbon / $totalCount : 0.0
        ];
    }

    /**
     * Calculate carbon savings for a given activity and data input
     *
     * @param string $activityId UUID of the carbon activity
     * @param float $dataInput Input data (quantity, times, etc.)
     * @return array Result with carbon savings and activity details
     * @throws \InvalidArgumentException If activity not found or invalid
     */
    public function calculateCarbonSavings(string $activityId, float $dataInput, ?array $activity = null): array
    {
        if ($dataInput < 0) {
            throw new \InvalidArgumentException('Data input cannot be negative');
        }

        $resolvedActivity = $activity ?? $this->resolveActivity($activityId);

        if (!$resolvedActivity) {
            throw new \InvalidArgumentException('Activity not found');
        }

        $carbonFactor = $this->extractCarbonFactor($resolvedActivity);
        $unit = $resolvedActivity['unit'] ?? null;
        $nameZh = $resolvedActivity['name_zh'] ?? null;
        $nameEn = $resolvedActivity['name_en'] ?? null;
        $combinedName = trim(($nameZh ?? '') . ' ' . ($nameEn ?? ''));
        if ($combinedName === '') {
            $combinedName = $nameZh ?? $nameEn ?? '';
        }

        $carbonSavings = $carbonFactor * $dataInput;
        $pointsEarned = (int) round($carbonSavings * 10);

        return [
            'activity_id' => $activityId,
            'activity_name_zh' => $nameZh,
            'activity_name_en' => $nameEn,
            'activity_combined_name' => $combinedName,
            'category' => $resolvedActivity['category'] ?? null,
            'carbon_factor' => $carbonFactor,
            'unit' => $unit,
            'data_input' => $dataInput,
            'carbon_savings' => $carbonSavings,
            'points_earned' => $pointsEarned,
        ];
    }

    private function resolveActivity(string $activityId): ?array
    {
        try {
            $model = CarbonActivity::find($activityId);
        } catch (\Throwable $e) {
            if ($this->logger) {
                $this->logger->warning('Failed to resolve carbon activity', [
                    'activity_id' => $activityId,
                    'error' => $e->getMessage(),
                ]);
            }
            $model = null;
        }

        if (!$model) {
            return null;
        }

        return [
            'id' => $model->id,
            'name_zh' => $model->name_zh,
            'name_en' => $model->name_en,
            'category' => $model->category,
            'carbon_factor' => (float) $model->carbon_factor,
            'unit' => $model->unit,
        ];
    }

    private function extractCarbonFactor(array $activity): float
    {
        $factor = $activity['carbon_factor'] ?? $activity['factor'] ?? 0;
        if (!is_numeric($factor)) {
            return 0.0;
        }

        return (float) $factor;
    }

    /**
     * Get all available carbon activities
     *
     * @param string|null $category Filter by category
     * @param string|null $search Search term
     * @return array List of activities
     */
    public function getAvailableActivities(
        ?string $category = null,
        ?string $search = null,
        bool $includeInactive = false,
        bool $includeDeleted = false
    ): array {
        try {
            $query = $includeDeleted ? CarbonActivity::withTrashed() : CarbonActivity::query();

            if (!$includeInactive) {
                $query->where('is_active', true);
            }

            if ($category) {
                $query->where('category', $category);
            }

            if ($search) {
                $query->where(function ($q) use ($search) {
                    $like = '%' . $search . '%';
                    $q->where('name_zh', 'LIKE', $like)
                        ->orWhere('name_en', 'LIKE', $like)
                        ->orWhere('description_zh', 'LIKE', $like)
                        ->orWhere('description_en', 'LIKE', $like);
                });
            }

            return $query->orderBy('category')
                ->orderBy('sort_order')
                ->orderBy('created_at')
                ->get()
                ->map(function (CarbonActivity $activity) use ($includeDeleted) {
                    return [
                        'id' => $activity->id,
                        'name_zh' => $activity->name_zh,
                        'name_en' => $activity->name_en,
                        'combined_name' => $activity->getCombinedName(),
                        'category' => $activity->category,
                        'carbon_factor' => (float) $activity->carbon_factor,
                        'unit' => $activity->unit,
                        'description_zh' => $activity->description_zh,
                        'description_en' => $activity->description_en,
                        'icon' => $activity->icon,
                        'is_active' => (bool) $activity->is_active,
                        'sort_order' => (int) $activity->sort_order,
                        'created_at' => $activity->created_at,
                        'updated_at' => $activity->updated_at,
                        'statistics' => null,
                        'deleted_at' => $includeDeleted ? $activity->deleted_at : null,
                    ];
                })
                ->toArray();
        } catch (\Exception $e) {
            if ($this->logger) {
                $this->logger->error('Failed to get activities from database', ['error' => $e->getMessage()]);
            }

            return [];
        }
    }

    /**
     * Get activities grouped by category
     *
     * @return array Activities grouped by category
     */
    public function getActivitiesGroupedByCategory(bool $includeInactive = false, bool $includeDeleted = false): array
    {
        $activities = $this->getAvailableActivities(null, null, $includeInactive, $includeDeleted);

        $grouped = [];
        foreach ($activities as $activity) {
            $category = $activity['category'] ?? 'uncategorized';

            if (!isset($grouped[$category])) {
                $grouped[$category] = [
                    'category' => $category,
                    'count' => 0,
                    'activities' => [],
                ];
            }

            $grouped[$category]['activities'][] = $activity;
            $grouped[$category]['count']++;
        }

        return array_values($grouped);
    }

    /**
     * Get all categories
     *
     * @return array List of categories
     */
    public function getCategories(bool $includeInactive = false, bool $includeDeleted = false): array
    {
        try {
            $query = $includeDeleted ? CarbonActivity::withTrashed() : CarbonActivity::query();

            if (!$includeInactive) {
                $query->where('is_active', true);
            }

            return $query->whereNotNull('category')
                ->distinct()
                ->orderBy('category')
                ->pluck('category')
                ->filter()
                ->values()
                ->toArray();
        } catch (\Exception $e) {
            if ($this->logger) {
                $this->logger->error('Failed to get categories from database', ['error' => $e->getMessage()]);
            }

            return [];
        }
    }

    private function isBlank($value): bool
    {
        if ($value === null) {
            return true;
        }

        if (is_string($value) && trim($value) === '') {
            return true;
        }

        return false;
    }

    /**
     * Get activity statistics (stub for tests)
     */
    public function getActivityStatistics(?string $activityId = null): array
    {
        // Provide a simple stub; tests can mock this method
        return [
            'total_records' => 0,
            'approved_records' => 0,
            'pending_records' => 0,
            'rejected_records' => 0,
        ];
    }
}

