<?php
// Region -> province -> city -> barangay cascade (read-only)
// Used by address fields across the app. The barangay search returns the full
// region/province/city context so duplicate barangay names are distinguishable.

include_once __DIR__ . '/../core/helpers.php';
include_once __DIR__ . '/../core/guard.php';

// Router entry point. index.php calls this with the parsed action/id/method.
function handle($action, $id, $method)
{
    switch ($action) {
        case 'barangays':
            return locationsSearchBarangays();
        case 'provinces':
            return locationsSearchProvinces();
        case 'cities':
            return locationsSearchCities();
        case 'barangaysByCity':
            return locationsBarangaysByCity();
        case 'allCities':
            return locationsSearchAllCities();
        default:
            error("Unknown locations action: {$action}", 404);
    }
}

// GET /api/locations/provinces?search=...
// Provinces whose name matches the term (empty term returns all). id + name.
function locationsSearchProvinces()
{
    requireLogin();
    $search = isset($_GET['search']) ? trim($_GET['search']) : '';
    $stmt = db()->prepare(
        "SELECT province_id AS id, province_name AS name
         FROM provinces
         WHERE province_name ILIKE :q
         ORDER BY province_name
         LIMIT 100"
    );
    $stmt->execute([':q' => '%' . $search . '%']);
    json(['status' => 'ok', 'data' => locationsIntIds($stmt->fetchAll())]);
}

// GET /api/locations/cities?provinceId=..&search=...
// Cities/municipalities within a province, filtered by the term.
function locationsSearchCities()
{
    requireLogin();
    $provinceId = isset($_GET['provinceId']) && is_numeric($_GET['provinceId']) ? (int) $_GET['provinceId'] : 0;
    if (!$provinceId) {
        json(['status' => 'ok', 'data' => []]);
    }
    $search = isset($_GET['search']) ? trim($_GET['search']) : '';
    $stmt = db()->prepare(
        "SELECT city_id AS id, city_name AS name
         FROM cities
         WHERE province_id = :pid AND city_name ILIKE :q
         ORDER BY city_name
         LIMIT 200"
    );
    $stmt->execute([':pid' => $provinceId, ':q' => '%' . $search . '%']);
    json(['status' => 'ok', 'data' => locationsIntIds($stmt->fetchAll())]);
}

// GET /api/locations/barangaysByCity?cityId=..&search=...
// Barangays within a city/municipality, filtered by the term.
function locationsBarangaysByCity()
{
    requireLogin();
    $cityId = isset($_GET['cityId']) && is_numeric($_GET['cityId']) ? (int) $_GET['cityId'] : 0;
    if (!$cityId) {
        json(['status' => 'ok', 'data' => []]);
    }
    $search = isset($_GET['search']) ? trim($_GET['search']) : '';
    $stmt = db()->prepare(
        "SELECT barangay_id AS id, barangay_name AS name
         FROM barangays
         WHERE city_id = :cid AND barangay_name ILIKE :q
         ORDER BY barangay_name
         LIMIT 300"
    );
    $stmt->execute([':cid' => $cityId, ':q' => '%' . $search . '%']);
    json(['status' => 'ok', 'data' => locationsIntIds($stmt->fetchAll())]);
}

// GET /api/locations/allCities?search=...
// Cities across all provinces matching the term, each shown as "City, Province"
// so duplicates are distinguishable. Used for the Work Experience address field.
function locationsSearchAllCities()
{
    requireLogin();
    $search = isset($_GET['search']) ? trim($_GET['search']) : '';
    if ($search === '') {
        json(['status' => 'ok', 'data' => []]);
    }
    $stmt = db()->prepare(
        "SELECT c.city_id AS id, (c.city_name || ', ' || p.province_name) AS name
         FROM cities c
         JOIN provinces p ON p.province_id = c.province_id
         WHERE c.city_name ILIKE :q
         ORDER BY c.city_name, p.province_name
         LIMIT 50"
    );
    $stmt->execute([':q' => '%' . $search . '%']);
    json(['status' => 'ok', 'data' => locationsIntIds($stmt->fetchAll())]);
}

// Cast the `id` column of each row to int.
function locationsIntIds($rows)
{
    foreach ($rows as &$r) {
        $r['id'] = (int) $r['id'];
    }
    unset($r);
    return $rows;
}

// GET /api/locations/barangays?search=...
// Returns up to 20 barangays whose name matches the search term, each carrying
// its city/province/region so the frontend can tell duplicates apart and store
// the resolved barangay_id.
function locationsSearchBarangays()
{
    // Any logged-in user may read location reference data (used by every module's
    // address fields). No per-module permission needed.
    requireLogin();

    $search = isset($_GET['search']) ? trim($_GET['search']) : '';
    if ($search === '') {
        json(['status' => 'ok', 'data' => []]);
    }

    $stmt = db()->prepare(
        "SELECT b.barangay_id   AS id,
                b.barangay_name  AS barangay,
                c.city_name      AS city,
                p.province_name  AS province,
                r.region_name    AS region
         FROM barangays b
         JOIN cities    c ON c.city_id     = b.city_id
         JOIN provinces p ON p.province_id = c.province_id
         JOIN regions   r ON r.region_id   = p.region_id
         WHERE b.barangay_name ILIKE :q
         ORDER BY b.barangay_name, p.province_name, c.city_name
         LIMIT 20"
    );
    $stmt->execute([':q' => '%' . $search . '%']);
    $rows = $stmt->fetchAll();

    // Normalize ids to int for the frontend.
    foreach ($rows as &$r) {
        $r['id'] = (int) $r['id'];
    }
    unset($r);

    json(['status' => 'ok', 'data' => $rows]);
}
