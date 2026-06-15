<?php

namespace App\Support;

/**
 * Straight-line (Haversine) distance helpers for nearby user queries.
 * Uses a bounding-box pre-filter so the DB can use indexes before Haversine math.
 */
final class GeoDistance
{
    public const EARTH_RADIUS_KM = 6371.0;

    /**
     * Approximate lat/lng window that fully contains a circle of radiusKm.
     *
     * @return array{min_lat: float, max_lat: float, min_lng: float, max_lng: float}
     */
    public static function boundingBox(float $latitude, float $longitude, float $radiusKm): array
    {
        $latDelta = rad2deg($radiusKm / self::EARTH_RADIUS_KM);
        $cosLat = cos(deg2rad($latitude));
        $lngDelta = $cosLat > 1e-10
            ? rad2deg($radiusKm / self::EARTH_RADIUS_KM / $cosLat)
            : 180.0;

        return [
            'min_lat' => max(-90.0, $latitude - $latDelta),
            'max_lat' => min(90.0, $latitude + $latDelta),
            'min_lng' => max(-180.0, $longitude - $lngDelta),
            'max_lng' => min(180.0, $longitude + $lngDelta),
        ];
    }

    /**
     * Haversine distance in km. Bindings: viewerLat, viewerLng, viewerLat.
     */
    public static function haversineSql(string $latColumn, string $lngColumn): string
    {
        return sprintf(
            '(%F * acos(LEAST(1, GREATEST(-1, cos(radians(?)) * cos(radians(%s)) * cos(radians(%s) - radians(?)) + sin(radians(?)) * sin(radians(%s))))))',
            self::EARTH_RADIUS_KM,
            $latColumn,
            $lngColumn,
            $latColumn
        );
    }

    /**
     * @return array{0: float, 1: float, 2: float}
     */
    public static function haversineBindings(float $latitude): array
    {
        return [$latitude, $latitude, $latitude];
    }

    public static function distanceMeters(
        float $fromLat,
        float $fromLng,
        float $toLat,
        float $toLng
    ): float {
        $latFrom = deg2rad($fromLat);
        $latTo = deg2rad($toLat);
        $latDelta = deg2rad($toLat - $fromLat);
        $lngDelta = deg2rad($toLng - $fromLng);

        $a = sin($latDelta / 2) ** 2
            + cos($latFrom) * cos($latTo) * sin($lngDelta / 2) ** 2;

        return self::EARTH_RADIUS_KM * 2 * atan2(sqrt($a), sqrt(1 - $a)) * 1000;
    }
}
