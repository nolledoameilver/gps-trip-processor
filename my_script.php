<?php
/**
 * GPS Trip Processor
 * PHP 8.3+ compatible
 * Reads input.csv, cleans invalid rows, splits into trips, outputs GeoJSON
 */

function isValidCoordinate($lat, $lon) {
    return is_numeric($lat) && is_numeric($lon) && $lat >= -90 && $lat <= 90 && $lon >= -180 && $lon <= 180;
}

function isValidTimestamp($timestamp) {
    return strtotime($timestamp) !== false;
}

function haversineDistance($lat1, $lon1, $lat2, $lon2) {
    $R = 6371; // Earth radius in km
    $dLat = deg2rad($lat2 - $lat1);
    $dLon = deg2rad($lon2 - $lon1);
    $a = sin($dLat / 2) ** 2 +
         cos(deg2rad($lat1)) * cos(deg2rad($lat2)) *
         sin($dLon / 2) ** 2;
    return $R * (2 * atan2(sqrt($a), sqrt(1 - $a)));
}

// File paths
$inputFile = 'points.csv';
$rejectLog = 'rejects.log';
$geojsonFile = 'output.geojson';

// Data arrays
$data = [];
$rejects = [];

// Read CSV
if (!file_exists($inputFile)) {
    die("Error: input.csv not found.\n");
}

if (($handle = fopen($inputFile, "r")) !== false) {
    // Skip header if present
    $header = fgetcsv($handle, 0, ",", '"', "\\");

    while (($row = fgetcsv($handle, 0, ",", '"', "\\")) !== false) {
        if (count($row) < 4) {
            $rejects[] = implode(",", $row);
            continue;
        }
        [$device_id, $lat, $lon, $timestamp] = $row;

        if (!isValidCoordinate($lat, $lon) || !isValidTimestamp($timestamp)) {
            $rejects[] = implode(",", $row);
            continue;
        }

        $data[] = [
            'device_id' => $device_id,
            'lat' => (float)$lat,
            'lon' => (float)$lon,
            'timestamp' => $timestamp,
            'time' => strtotime($timestamp)
        ];
    }
    fclose($handle);
}

// Save rejects
if ($rejects) {
    file_put_contents($rejectLog, implode("\n", $rejects));
}

// Sort by timestamp
usort($data, fn($a, $b) => $a['time'] <=> $b['time']);

// Split into trips
$trips = [];
$tripIndex = 1;
$currentTrip = [];
$prevPoint = null;

foreach ($data as $point) {
    if ($prevPoint) {
        $timeDiff = ($point['time'] - $prevPoint['time']) / 60; // minutes
        $dist = haversineDistance($prevPoint['lat'], $prevPoint['lon'], $point['lat'], $point['lon']);

        if ($timeDiff > 25 || $dist > 2) {
            $trips["trip_$tripIndex"] = $currentTrip;
            $tripIndex++;
            $currentTrip = [];
        }
    }
    $currentTrip[] = $point;
    $prevPoint = $point;
}
if ($currentTrip) {
    $trips["trip_$tripIndex"] = $currentTrip;
}

// Build GeoJSON
$features = [];
$colors = ["#FF0000", "#0000FF", "#00FF00", "#FFA500", "#800080"];
$colorIndex = 0;

foreach ($trips as $tripName => $points) {
    $coords = [];
    $totalDist = 0;
    $maxSpeed = 0;

    for ($i = 1; $i < count($points); $i++) {
        $dist = haversineDistance(
            $points[$i - 1]['lat'], $points[$i - 1]['lon'],
            $points[$i]['lat'], $points[$i]['lon']
        );
        $totalDist += $dist;

        $timeDiffH = ($points[$i]['time'] - $points[$i - 1]['time']) / 3600;
        if ($timeDiffH > 0) {
            $speed = $dist / $timeDiffH;
            if ($speed > $maxSpeed) $maxSpeed = $speed;
        }
    }

    $duration = (end($points)['time'] - $points[0]['time']) / 60; // minutes
    $avgSpeed = $duration > 0 ? $totalDist / ($duration / 60) : 0;

    foreach ($points as $p) {
        $coords[] = [$p['lon'], $p['lat']];
    }

    $features[] = [
        "type" => "Feature",
        "properties" => [
            "trip_name" => $tripName,
            "total_distance_km" => round($totalDist, 3),
            "duration_min" => round($duration, 2),
            "avg_speed_kmh" => round($avgSpeed, 2),
            "max_speed_kmh" => round($maxSpeed, 2),
            "color" => $colors[$colorIndex % count($colors)]
        ],
        "geometry" => [
            "type" => "LineString",
            "coordinates" => $coords
        ]
    ];
    $colorIndex++;
}

$geojson = [
    "type" => "FeatureCollection",
    "features" => $features
];

file_put_contents($geojsonFile, json_encode($geojson, JSON_PRETTY_PRINT));

echo "Processing complete. Output saved to $geojsonFile\n";
