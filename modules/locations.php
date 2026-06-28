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
        default:
            error("Unknown locations action: {$action}", 404);
    }
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
