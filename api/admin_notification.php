<?php
session_start();
header('Content-Type: application/json');
header('Cache-Control: no-store, no-cache, must-revalidate, max-age=0');

if (!isset($_SESSION['user_id']) || !in_array($_SESSION['user_role'] ?? '', ['admin', 'staff'], true)) {
    http_response_code(401);
    echo json_encode(['ok' => false, 'message' => 'Authentication required']);
    exit;
}

require_once __DIR__ . '/../database/db.php';
if (!$conn) {
    http_response_code(503);
    echo json_encode(['ok' => false, 'message' => 'Database unavailable']);
    exit;
}

$calling = null;
$waitingCount = 0;
$callingResult = $conn->query("SELECT q.ticket_number, d.dept_name FROM queue q LEFT JOIN services s ON q.service_id = s.id LEFT JOIN departments d ON s.dept_id = d.id WHERE q.status IN ('called', 'now_serving') ORDER BY q.id DESC LIMIT 1");
if ($callingResult) {
    $calling = $callingResult->fetch_assoc();
}
$waitingResult = $conn->query("SELECT COUNT(*) AS total FROM queue WHERE status = 'waiting'");
if ($waitingResult) {
    $waitingCount = (int)($waitingResult->fetch_assoc()['total'] ?? 0);
}

echo json_encode([
    'ok' => true,
    'ticket' => $calling['ticket_number'] ?? null,
    'department' => $calling['dept_name'] ?? null,
    'waiting' => $waitingCount
]);
