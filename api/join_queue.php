<?php
session_start();
header('Content-Type: application/json');
if ($_SERVER['REQUEST_METHOD'] !== 'POST') {
    http_response_code(405);
    echo json_encode(['ok' => false, 'message' => 'POST required']);
    exit;
}
require_once __DIR__ . '/../database/db.php';
if (!$conn) {
    echo json_encode(['ok' => false, 'message' => 'Database unavailable']);
    http_response_code(503);
    exit;
}
$userId = (int) ($_SESSION['user_id'] ?? 0);

if ($userId <= 0) {
    echo json_encode(['ok' => false, 'message' => 'Authentication required']);
    http_response_code(401);
    exit;
}

$serviceId = (int) ($_POST['service_id'] ?? 0);
if ($serviceId > 0) {
    $serviceStmt = $conn->prepare('SELECT s.id, d.dept_code FROM services s JOIN departments d ON d.id = s.dept_id WHERE s.id = ? AND d.status = \'active\' LIMIT 1');
    $serviceStmt?->bind_param('i', $serviceId);
} else {
    $department = trim($_POST['department'] ?? '');
    $service = trim($_POST['service'] ?? '');
    $serviceStmt = $conn->prepare('SELECT s.id, d.dept_code FROM services s JOIN departments d ON d.id = s.dept_id WHERE d.dept_name = ? AND s.service_name = ? AND d.status = \'active\' LIMIT 1');
    $serviceStmt?->bind_param('ss', $department, $service);
}

if (!$serviceStmt || !$serviceStmt->execute()) {
    echo json_encode(['ok' => false, 'message' => 'Service lookup failed']);
    http_response_code(500);
    exit;
}

$serviceResult = $serviceStmt->get_result();
$serviceData = $serviceResult ? $serviceResult->fetch_assoc() : null;
$serviceStmt->close();
if (!$serviceData) {
    echo json_encode(['ok' => false, 'message' => 'Service unavailable']);
    http_response_code(422);
    exit;
}

$nextIdResult = $conn->query('SELECT COALESCE(MAX(id), 0) + 1 AS next_id FROM queue');
$nextId = (int) ($nextIdResult?->fetch_assoc()['next_id'] ?? 1);
$ticket = strtoupper($serviceData['dept_code'] ?: 'Q') . '-' . str_pad((string) $nextId, 3, '0', STR_PAD_LEFT);
$insertStmt = $conn->prepare("INSERT INTO queue (user_id, service_id, ticket_number, status) VALUES (?, ?, ?, 'waiting')");
if ($insertStmt) {
    $insertStmt->bind_param('iis', $userId, $serviceData['id'], $ticket);
    if ($insertStmt->execute()) {
        echo json_encode(['ok' => true, 'ticket' => $ticket, 'persisted' => true]);
        exit;
    }
}

echo json_encode(['ok' => false, 'message' => 'Unable to create queue ticket']);
http_response_code(500);
?>
