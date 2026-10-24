<?php

namespace App\Observers;

use App\Models\Review;
use App\Repositories\CachedTechnicianRepository;

class ReviewObserver
{
    public function saved(Review $review): void
    {
        CachedTechnicianRepository::forgetTechnician($review->technician_id);
    }

    public function deleted(Review $review): void
    {
        CachedTechnicianRepository::forgetTechnician($review->technician_id);
    }
}
