<?php

namespace App\Services;

use App\Models\User;
use App\Support\GeoDistance;

class UserLocationService
{
    /** Ignore sub-meter GPS jitter. */
    public const UNCHANGED_EPSILON_METERS = 50;

    /** Always persist when the user moved at least this far. */
    public const MIN_MOVE_METERS = 500;

    /** Minimum seconds between DB writes for small movements. */
    public const MIN_UPDATE_INTERVAL_SECONDS = 90;

    /**
     * @return array{
     *     updated: bool,
     *     latitude: float|null,
     *     longitude: float|null,
     *     location: string|null,
     *     location_sharing_enabled: bool,
     *     location_updated_at: mixed
     * }
     */
    public function enableAndUpdate(User $user, float $latitude, float $longitude, ?string $location = null): array
    {
        if (! $this->shouldPersist($user, $latitude, $longitude)) {
            if (! $user->location_sharing_enabled) {
                $user->update(['location_sharing_enabled' => true]);
                $user->refresh();
            }

            return $this->payload($user, updated: false);
        }

        $user->update([
            'latitude' => $latitude,
            'longitude' => $longitude,
            'location' => $location ?? $user->location,
            'location_sharing_enabled' => true,
            'location_updated_at' => now(),
        ]);

        $user->refresh();

        return $this->payload($user, updated: true);
    }

    /**
     * @return array{
     *     updated: bool,
     *     latitude: float|null,
     *     longitude: float|null,
     *     location: string|null,
     *     location_sharing_enabled: bool,
     *     location_updated_at: mixed
     * }
     */
    public function disable(User $user): array
    {
        $user->update([
            'location_sharing_enabled' => false,
            'latitude' => null,
            'longitude' => null,
            'location_updated_at' => null,
        ]);

        $user->refresh();

        return $this->payload($user, updated: true);
    }

    public function shouldPersist(User $user, float $latitude, float $longitude): bool
    {
        if ($user->latitude === null || $user->longitude === null) {
            return true;
        }

        $meters = GeoDistance::distanceMeters(
            (float) $user->latitude,
            (float) $user->longitude,
            $latitude,
            $longitude
        );

        if ($meters < self::UNCHANGED_EPSILON_METERS) {
            return false;
        }

        if ($meters >= self::MIN_MOVE_METERS) {
            return true;
        }

        if ($user->location_updated_at === null) {
            return true;
        }

        return $user->location_updated_at->diffInSeconds(now()) >= self::MIN_UPDATE_INTERVAL_SECONDS;
    }

    /**
     * @return array{
     *     updated: bool,
     *     latitude: float|null,
     *     longitude: float|null,
     *     location: string|null,
     *     location_sharing_enabled: bool,
     *     location_updated_at: mixed
     * }
     */
    private function payload(User $user, bool $updated): array
    {
        return [
            'updated' => $updated,
            'latitude' => $user->latitude !== null ? (float) $user->latitude : null,
            'longitude' => $user->longitude !== null ? (float) $user->longitude : null,
            'location' => $user->location,
            'location_sharing_enabled' => (bool) $user->location_sharing_enabled,
            'location_updated_at' => $user->location_updated_at,
        ];
    }
}
