<?php

declare(strict_types=1);

if (parse_url($_SERVER['REQUEST_URI'], PHP_URL_PATH) !== '/capture' || $_SERVER['REQUEST_METHOD'] !== 'PUT') {
    http_response_code(404);
    return;
}

$capture = getenv('INRIVER_TEST_CALLBACK_CAPTURE');
$statusFile = getenv('INRIVER_TEST_CALLBACK_STATUS');
if (!$capture || !$statusFile) {
    http_response_code(500);
    return;
}

$request = [
    'method' => $_SERVER['REQUEST_METHOD'],
    'api_key' => $_SERVER['HTTP_X_INRIVER_APIKEY'] ?? null,
    'body' => json_decode(file_get_contents('php://input'), true, 512, JSON_THROW_ON_ERROR),
];
file_put_contents($capture, json_encode($request, JSON_THROW_ON_ERROR) . "\n", FILE_APPEND | LOCK_EX);
http_response_code((int) file_get_contents($statusFile));
header('Content-Type: application/json');
echo '{}';