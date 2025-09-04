<?php

declare(strict_types=1);

namespace CarbonTrack\Models;

use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\SoftDeletes;

class School extends Model
{
    use SoftDeletes;

    protected $table = 'schools';
    
    protected $fillable = [
        'name',
        'location',
        'is_active',
        'sort_order'
    ];

    protected $casts = [
        'is_active' => 'boolean',
        'sort_order' => 'integer',
        'created_at' => 'datetime',
        'updated_at' => 'datetime',
        'deleted_at' => 'datetime'
    ];

    protected $attributes = [
        'is_active' => true
    ];

    // Scope for active schools
    public function scopeActive($query)
    {
        return $query->where('is_active', true);
    }

    // Scope for inactive schools
    public function scopeInactive($query)
    {
        return $query->where('is_active', false);
    }

    // Scope for ordering by sort_order
    public function scopeOrdered($query)
    {
        return $query->orderBy('sort_order', 'asc');
    }

    // Get display name
    public function getDisplayNameAttribute()
    {
        return $this->name . ' (' . $this->location . ')';
    }

    // Check if school is active
    public function isActive(): bool
    {
        return $this->is_active;
    }

    // Activate school
    public function activate(): void
    {
        $this->update(['is_active' => true]);
    }

    // Deactivate school
    public function deactivate(): void
    {
        $this->update(['is_active' => false]);
    }

    // Update sort order
    public function updateSortOrder(int $order): void
    {
        $this->update(['sort_order' => $order]);
    }
}
