<?php
// Read-only address lookups (province -> city -> barangay), shared by the logged-in
// staff endpoints (modules/locations.php) and the public kiosk endpoints
// (modules/kiosk.php). These only read reference data; the callers decide who may use them.

include_once __DIR__ . '/db.php';

// Cast the `id` column of each row to int.
function locQueryIntIds($rows)
{
    foreach ($rows as &$r) {
        $r['id'] = (int) $r['id'];
    }
    unset($r);
    return $rows;
}

// Provinces whose name matches the term (empty term returns all).
function locQueryProvinces($search)
{
    $stmt = db()->prepare(
        "SELECT province_id AS id, province_name AS name
         FROM provinces
         WHERE province_name ILIKE :q
         ORDER BY province_name
         LIMIT 100"
    );
    $stmt->execute([':q' => '%' . trim($search) . '%']);
    return locQueryIntIds($stmt->fetchAll());
}

// Cities/municipalities within a province, filtered by the term.
function locQueryCities($provinceId, $search)
{
    if (!$provinceId) return [];
    $stmt = db()->prepare(
        "SELECT city_id AS id, city_name AS name
         FROM cities
         WHERE province_id = :pid AND city_name ILIKE :q
         ORDER BY city_name
         LIMIT 200"
    );
    $stmt->execute([':pid' => (int) $provinceId, ':q' => '%' . trim($search) . '%']);
    return locQueryIntIds($stmt->fetchAll());
}

// Barangays within a city/municipality, filtered by the term.
function locQueryBarangaysByCity($cityId, $search)
{
    if (!$cityId) return [];
    $stmt = db()->prepare(
        "SELECT barangay_id AS id, barangay_name AS name
         FROM barangays
         WHERE city_id = :cid AND barangay_name ILIKE :q
         ORDER BY barangay_name
         LIMIT 300"
    );
    $stmt->execute([':cid' => (int) $cityId, ':q' => '%' . trim($search) . '%']);
    return locQueryIntIds($stmt->fetchAll());
}

// Cities across all provinces, named "City, Province" (empty term returns nothing).
function locQueryAllCities($search)
{
    $search = trim($search);
    if ($search === '') return [];
    $stmt = db()->prepare(
        "SELECT c.city_id AS id, (c.city_name || ', ' || p.province_name) AS name
         FROM cities c
         JOIN provinces p ON p.province_id = c.province_id
         WHERE c.city_name ILIKE :q
         ORDER BY c.city_name, p.province_name
         LIMIT 50"
    );
    $stmt->execute([':q' => '%' . $search . '%']);
    return locQueryIntIds($stmt->fetchAll());
}
