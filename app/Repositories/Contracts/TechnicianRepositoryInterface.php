<?php

namespace App\Repositories\Contracts;

use App\Models\Technician;
use Illuminate\Support\Collection;

interface TechnicianRepositoryInterface
{
    /** Technician with user, area and categories loaded, or null if not found. */
    public function findWithProfile(int $id): ?Technician;

    /** @return array{completed_jobs:int, active_jobs:int, reviews_count:int, avg_rating:?float, total_earned:float} */
    public function stats(int $id): array;

    public function recentReviews(int $id, int $limit = 5): Collection;

    /** Best-rated verified technicians in an area (at least 5 reviews). */
    public function topRatedInArea(int $areaId, int $limit = 10): Collection;
}
