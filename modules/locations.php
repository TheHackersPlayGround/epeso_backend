<?php
// Region -> province -> city -> barangay cascade (read-only), used by address fields app-wide.

include_once __DIR__ . '/../core/helpers.php';
include_once __DIR__ . '/../core/guard.php';
include_once __DIR__ . '/../core/location_queries.php';

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
    json(['status' => 'ok', 'data' => locQueryProvinces($search)]);
}

// GET /api/locations/cities?provinceId=..&search=...
// Cities/municipalities within a province, filtered by the term.
function locationsSearchCities()
{
    requireLogin();
    $provinceId = isset($_GET['provinceId']) && is_numeric($_GET['provinceId']) ? (int) $_GET['provinceId'] : 0;
    $search = isset($_GET['search']) ? trim($_GET['search']) : '';
    json(['status' => 'ok', 'data' => locQueryCities($provinceId, $search)]);
}

// GET /api/locations/barangaysByCity?cityId=..&search=...
// Barangays within a city/municipality, filtered by the term.
function locationsBarangaysByCity()
{
    requireLogin();
    $cityId = isset($_GET['cityId']) && is_numeric($_GET['cityId']) ? (int) $_GET['cityId'] : 0;
    $search = isset($_GET['search']) ? trim($_GET['search']) : '';
    json(['status' => 'ok', 'data' => locQueryBarangaysByCity($cityId, $search)]);
}

// GET /api/locations/allCities?search=... Cities shown as "City, Province" for the Work Experience field.
function locationsSearchAllCities()
{
    requireLogin();
    $search = isset($_GET['search']) ? trim($_GET['search']) : '';
    json(['status' => 'ok', 'data' => locQueryAllCities($search)]);
}

// GET /api/locations/barangays?search=... Returns up to 20 matches with city/province/region context.
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
