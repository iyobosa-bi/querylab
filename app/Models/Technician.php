<?php

namespace App\Models;

use App\Enums\ServiceRequestStatus;
use Illuminate\Database\Eloquent\Builder;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;
use Illuminate\Database\Eloquent\Relations\BelongsToMany;
use Illuminate\Database\Eloquent\Relations\HasMany;

class Technician extends Model
{
    protected $fillable = [
        'area_id', 'bio', 'hourly_rate', 'years_experience', 'is_available',
    ];

    protected function casts(): array
    {
        return [
            'hourly_rate' => 'decimal:2',
            'is_available' => 'boolean',
            'is_verified' => 'boolean',
            'verified_at' => 'datetime',
        ];
    }

    public function user(): BelongsTo
    {
        return $this->belongsTo(User::class);
    }

    public function area(): BelongsTo
    {
        return $this->belongsTo(Area::class);
    }

    public function categories(): BelongsToMany
    {
        return $this->belongsToMany(Category::class)
            ->withPivot('years_in_skill')
            ->withTimestamps();
    }

    public function serviceRequests(): HasMany
    {
        return $this->hasMany(ServiceRequest::class);
    }

    public function completedRequests(): HasMany
    {
        return $this->hasMany(ServiceRequest::class)
            ->where('status', ServiceRequestStatus::Completed);
    }

    public function quotes(): HasMany
    {
        return $this->hasMany(Quote::class);
    }

    public function reviews(): HasMany
    {
        return $this->hasMany(Review::class);
    }

    public function scopeVerified(Builder $query): void
    {
        $query->where('is_verified', true);
    }

    public function scopeAvailable(Builder $query): void
    {
        $query->where('is_available', true);
    }
}
