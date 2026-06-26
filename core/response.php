<?php
// json() / error() / body() helpers

// Send a JSON response and stop.
function json($data, $status = 200)
{
    http_response_code($status);
    header('Content-Type: application/json; charset=utf-8');
    echo json_encode($data);
    exit;
}

// Send a JSON error and stop.
function error($message, $status = 400, $detail = null)
{
    $payload = ['error' => $message];
    if ($detail !== null) {
        $payload['detail'] = $detail;
    }
    json($payload, $status);
}

// Read and decode the JSON request body sent by Axios.
function body()
{
    $raw = file_get_contents('php://input');
    if ($raw === '' || $raw === false) {
        return [];
    }
    $decoded = json_decode($raw, true);
    return is_array($decoded) ? $decoded : [];
}
