<?php

namespace App\Services;

use App\Models\User;
use App\Query\DiscoverableUsersQuery;
use App\Support\GeoDistance;
use Illuminate\Contracts\Pagination\LengthAwarePaginator;

class NearbyUsersService
{
    public const DEFAULT_RADIUS_KM = 50;

    public const MAX_RADIUS_KM = 200;

    /**
     * Paginated nearby users ordered by straight-line distance (km).
     *
     * @param  array{gender?: string|null, radius_km?: float|int|string|null, per_page?: int|string|null}  $filters
     */
    public function paginateNearby(User $viewer, float $latitude, float $longitude, array $filters = []): LengthAwarePaginator
    {
        $radiusKm = (float) ($filters['radius_km'] ?? self::DEFAULT_RADIUS_KM);
        $radiusKm = max(1.0, min($radiusKm, (float) self::MAX_RADIUS_KM));

        $perPage = (int) ($filters['per_page'] ?? 20);
        $perPage = max(1, min($perPage, 100));

        $bbox = GeoDistance::boundingBox($latitude, $longitude, $radiusKm);
        $haversine = GeoDistance::haversineSql('users.latitude', 'users.longitude');
        $bindings = [$latitude, $longitude, $latitude];

        $query = User::query()
            ->select([
                'users.id',
                'users.first_name',
                'users.last_name',
                'users.profile_image',
                'users.gender',
                'users.location',
                'users.date_of_birth',
                'users.about_me',
                'users.created_at',
            ])
            ->selectRaw("{$haversine} AS distance_km", $bindings)
            ->where('users.is_active', 1)
            ->where('users.location_sharing_enabled', 1)
            ->whereNotNull('users.latitude')
            ->whereNotNull('users.longitude')
            ->whereBetween('users.latitude', [$bbox['min_lat'], $bbox['max_lat']])
            ->whereBetween('users.longitude', [$bbox['min_lng'], $bbox['max_lng']]);

        DiscoverableUsersQuery::applySocialExclusions($query, $viewer);

        if (! empty($filters['gender'])) {
            $query->where('users.gender', $filters['gender']);
        }

        $query
            ->whereRaw("{$haversine} <= ?", array_merge($bindings, [$radiusKm]))
            ->orderByRaw('distance_km ASC')
            ->orderBy('users.id');

        return $query
            ->with(['interests:id,name'])
            ->paginate($perPage);
    }
}
