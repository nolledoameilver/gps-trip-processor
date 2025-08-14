# GPS Trip Processor (PHP)

Processes shuffled GPS points from a CSV and outputs trips as GeoJSON LineStrings, with stats per trip.

## Input
- File: `points.csv`
- Columns (in any order if header is present):  
  `device_id, lat, lon, timestamp`  
  - `lat` in [-90, 90], `lon` in [-180, 180]  
  - `timestamp` ISO 8601

## What the script does
1. **Clean**: Discards rows with invalid coordinates or bad timestamps → logs to `rejects.log`.
2. **Order**: Sorts remaining points by timestamp.
3. **Split trips** when **either**:
   - Time gap > 25 minutes, **or**
   - Straight-line distance jump > 2 km (Haversine).
4. **Compute per trip**:
   - Total distance (km)
   - Duration (min)
   - Average speed (km/h)
   - Max speed (km/h)
5. **Output**:
   - `output.geojson`: FeatureCollection with one **LineString** per trip.
   - Each trip has a distinct `color` property.

## Requirements
- **PHP 8+** (CLI). No external libraries, no database.

## Usage
1. Place `points.csv` beside `my_script.php`.
2. Run:
   ```bash
   php my_script.php
