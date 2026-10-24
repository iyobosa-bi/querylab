<?php

namespace App\Models;

use App\Enums\ServiceRequestStatus;
use Illuminate\Database\Eloquent\Builder;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;
use Illuminate\Database\Eloquent\Relations\HasMany;
use Illuminate\Database\Eloquent\Relations\HasOne;

class ServiceRequest extends Model
{
    protected $fillable = [
        'category_id', 'area_id', 'title', 'description', 'budget', 'scheduled_at',
    ];

    protected function casts(): array
    {
        return [
            'status' => ServiceRequestStatus::class,
            'budget' => 'decimal:2',
            'scheduled_at' => 'datetime',
            'accepted_at' => 'datetime',
            'completed_at' => 'datetime',
            'cancelled_at' => 'datetime',
        ];
    }

    public function customer(): BelongsTo
    {
        return $this->belongsTo(User::class, 'customer_id');
    }

    public function technician(): BelongsTo
    {
        return $this->belongsTo(Technician::class);
    }

    public function category(): BelongsTo
    {
        return $this->belongsTo(Category::class);
    }

    public function area(): BelongsTo
    {
        return $this->belongsTo(Area::class);
    }

    public function quotes(): HasMany
    {
        return $this->hasMany(Quote::class);
    }

    /** The most recent quote, loadable with ->with('latestQuote') in one extra query. */
    public function latestQuote(): HasOne
    {
        return $this->hasOne(Quote::class)->latestOfMany();
    }

    /** The cheapest quote. `ofMany` builds a MIN() subquery join for you. */
    public function lowestQuote(): HasOne
    {
        return $this->hasOne(Quote::class)->ofMany('amount', 'min');
    }

    public function review(): HasOne
    {
        return $this->hasOne(Review::class);
    }

    public function payments(): HasMany
    {
        return $this->hasMany(Payment::class);
    }

    public function scopeStatus(Builder $query, ServiceRequestStatus $status): void
    {
        $query->where('status', $status);
    }

    public function scopeUnassigned(Builder $query): void
    {
        $query->whereNull('technician_id');
    }
}
