<?php
session_start();
header('Content-Type: application/json');
header('Cache-Control: no-store, no-cache, must-revalidate, max-age=0');

$userId = (int)($_SESSION['user_id'] ?? 0);
if ($userId <= 0) {
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

$stmt = $conn->prepare("SELECT q.ticket_number, q.status, d.dept_name, s.service_name FROM queue q LEFT JOIN services s ON s.id = q.service_id LEFT JOIN departments d ON d.id = s.dept_id WHERE q.user_id = ? AND q.status IN ('waiting', 'called', 'now_serving') ORDER BY q.id DESC LIMIT 1");
if (!$stmt) {
    http_response_code(500);
    echo json_encode(['ok' => false, 'message' => 'Unable to check ticket status']);
    exit;
}

$stmt->bind_param('i', $userId);
$stmt->execute();
$ticket = $stmt->get_result()->fetch_assoc();
$stmt->close();

echo json_encode([
    'ok' => true,
    'ticket' => $ticket['ticket_number'] ?? null,
    'status' => $ticket['status'] ?? null,
    'department' => $ticket['dept_name'] ?? null,
    'service' => $ticket['service_name'] ?? null
]);
