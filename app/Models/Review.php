<?php

namespace App\Models;

use App\Observers\ReviewObserver;
use Illuminate\Database\Eloquent\Attributes\ObservedBy;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;

/**
 * The observer clears the technician's cached profile whenever a review is
 * created, updated or deleted, even if the write didn't go through a repository.
 */
#[ObservedBy(ReviewObserver::class)]
class Review extends Model
{
    protected $fillable = ['rating', 'comment'];

    public function serviceRequest(): BelongsTo
    {
        return $this->belongsTo(ServiceRequest::class);
    }

    public function customer(): BelongsTo
    {
        return $this->belongsTo(User::class, 'customer_id');
    }

    public function technician(): BelongsTo
    {
        return $this->belongsTo(Technician::class);
    }
}
