<?php

namespace App\Repositories;

use App\Models\Review;
use App\Models\Technician;
use App\Repositories\Contracts\TechnicianRepositoryInterface;
use Illuminate\Support\Collection;
use Illuminate\Support\Facades\DB;

/** Plain data access. Knows nothing about caching. */
class EloquentTechnicianRepository implements TechnicianRepositoryInterface
{
    public function findWithProfile(int $id): ?Technician
    {
        return Technician::with([
            'user:id,name,email,phone',
            'area:id,name',
            'categories:id,name',
        ])->find($id);
    }

    public function stats(int $id): array
    {
        // One pass over the technician's jobs, counting two things at once with FILTER (PostgreSQL).
        $jobs = DB::table('service_requests')
            ->where('technician_id', $id)
            ->selectRaw('COUNT(*) FILTER (WHERE status = ?) AS completed_jobs', ['completed'])
            ->selectRaw('COUNT(*) FILTER (WHERE status IN (?, ?)) AS active_jobs', ['accepted', 'in_progress'])
            ->first();

        $reviews = DB::table('reviews')
            ->where('technician_id', $id)
            ->selectRaw('COUNT(*) AS reviews_count, ROUND(AVG(rating), 2) AS avg_rating')
            ->first();

        $earned = DB::table('payments')
            ->join('service_requests', 'service_requests.id', '=', 'payments.service_request_id')
            ->where('service_requests.technician_id', $id)
            ->where('payments.status', 'success')
            ->sum('payments.amount');

        return [
            'completed_jobs' => (int) $jobs->completed_jobs,
            'active_jobs' => (int) $jobs->active_jobs,
            'reviews_count' => (int) $reviews->reviews_count,
            // AVG over zero rows is NULL, not 0. Keep it null so the UI can say "No reviews yet".
            'avg_rating' => $reviews->avg_rating === null ? null : (float) $reviews->avg_rating,
            'total_earned' => (float) $earned,
        ];
    }

    public function recentReviews(int $id, int $limit = 5): Collection
    {
        return Review::with('customer:id,name')
            ->where('technician_id', $id)
            ->latest('id')
            ->limit($limit)
            ->get();
    }

    public function topRatedInArea(int $areaId, int $limit = 10): Collection
    {
        return Technician::query()
            ->with('user:id,name')
            ->where('area_id', $areaId)
            ->verified()
            ->has('reviews', '>=', 5)
            ->withCount('reviews')
            ->withAvg('reviews', 'rating')
            // PostgreSQL puts NULLs FIRST when sorting DESC. Say NULLS LAST explicitly.
            ->orderByRaw('reviews_avg_rating DESC NULLS LAST')
            ->limit($limit)
            ->get();
    }
}
