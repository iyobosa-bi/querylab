<?php

namespace App\Repositories;

use App\Models\Technician;
use App\Repositories\Contracts\TechnicianRepositoryInterface;
use Illuminate\Cache\TaggedCache;
use Illuminate\Support\Collection;
use Illuminate\Support\Facades\Cache;

/**
 * Caching decorator: same interface, wraps the Eloquent repository.
 * Services never know caching exists, and caching can be turned off by
 * changing one binding in AppServiceProvider.
 *
 * Tag layout:
 *   ['technicians', 'technician:{id}']  one technician's profile, stats and reviews
 *   ['technicians', 'area:{id}']        lists that contain many technicians
 *
 * Tags must be passed in the SAME ORDER when reading and writing, so they
 * are only ever built by the helpers below.
 */
class CachedTechnicianRepository implements TechnicianRepositoryInterface
{
    public const PROFILE_TTL = 3600;   // 1 hour: profiles rarely change, and we flush on change

    public const STATS_TTL = 600;      // 10 minutes: stats change with every job

    public const LIST_TTL = 600;       // lists can't be flushed per technician, so keep them short

    public function __construct(private readonly EloquentTechnicianRepository $inner) {}

    public function findWithProfile(int $id): ?Technician
    {
        // remember() treats null as "not cached", so a missing id would hit the
        // database on every request. Cache `false` instead and turn it back into null.
        $technician = $this->forTechnician($id)->remember(
            "technician:{$id}:profile",
            self::PROFILE_TTL,
            fn () => $this->inner->findWithProfile($id) ?? false,
        );

        return $technician ?: null;
    }

    public function stats(int $id): array
    {
        return $this->forTechnician($id)->remember(
            "technician:{$id}:stats",
            self::STATS_TTL,
            fn () => $this->inner->stats($id),
        );
    }

    public function recentReviews(int $id, int $limit = 5): Collection
    {
        return $this->forTechnician($id)->remember(
            "technician:{$id}:reviews:{$limit}",
            self::STATS_TTL,
            fn () => $this->inner->recentReviews($id, $limit),
        );
    }

    public function topRatedInArea(int $areaId, int $limit = 10): Collection
    {
        return Cache::tags(['technicians', "area:{$areaId}"])->remember(
            "technicians:top-rated:area:{$areaId}:limit:{$limit}",
            self::LIST_TTL,
            fn () => $this->inner->topRatedInArea($areaId, $limit),
        );
    }

    /** Clear everything cached about one technician (called by ReviewObserver and after profile edits). */
    public static function forgetTechnician(int $id): void
    {
        Cache::tags(["technician:{$id}"])->flush();
    }

    /** Clear every technician entry and every technician list. */
    public static function forgetAll(): void
    {
        Cache::tags(['technicians'])->flush();
    }

    private function forTechnician(int $id): TaggedCache
    {
        return Cache::tags(['technicians', "technician:{$id}"]);
    }
}
