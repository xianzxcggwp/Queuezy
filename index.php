<?php
session_start();
header('Cache-Control: no-store, no-cache, must-revalidate, max-age=0');
require_once 'database/db.php';

if (isset($_GET['guest']) && $_GET['guest'] === '1') {
  $_SESSION['guest_mode'] = true;
  unset($_SESSION['user_id'], $_SESSION['user_role'], $_SESSION['user_name'], $_SESSION['user_email']);
}
$isGuest = !isset($_SESSION['user_id']) && !empty($_SESSION['guest_mode']);

if (!$isGuest && !isset($_SESSION['user_id'])) {
    header('Location: login.php');
    exit;
}

$userRole = strtolower($_SESSION['user_role'] ?? $_SESSION['role'] ?? 'customer');

$userId = (int)($_SESSION['user_id'] ?? 0);
$userName = $isGuest ? 'Guest' : ($_SESSION['user_name'] ?? 'Customer');
$userEmail = $_SESSION['user_email'] ?? '';
$activeTicketNumber = null;

$msg = '';
$msgType = 'success';

if ($conn) {
    $accountStmt = $conn->prepare('SELECT fullname, email FROM users WHERE id = ? LIMIT 1');
    if ($accountStmt) {
        $accountStmt->bind_param('i', $userId);
        $accountStmt->execute();
        $account = $accountStmt->get_result()->fetch_assoc();
        if ($account) {
            $userName = $account['fullname'] ?? $userName;
            $userEmail = $account['email'] ?? $userEmail;
        }
        $accountStmt->close();
    }
      $activeCheck = $conn->prepare("SELECT ticket_number FROM queue WHERE user_id = ? AND status IN ('waiting', 'called', 'now_serving') ORDER BY id DESC LIMIT 1");
      if ($activeCheck) {
        $activeCheck->bind_param('i', $userId);
        $activeCheck->execute();
        $activeRow = $activeCheck->get_result()->fetch_assoc();
        $activeTicketNumber = $activeRow['ticket_number'] ?? null;
        $activeCheck->close();
      }
}

if ($_SERVER['REQUEST_METHOD'] === 'POST' && isset($_POST['action'])) {
  if ($_POST['action'] === 'update_profile' && $conn) {
    $newName = trim($_POST['full_name'] ?? '');
    $newEmail = trim($_POST['email'] ?? '');
    if ($newName === '' || !filter_var($newEmail, FILTER_VALIDATE_EMAIL)) {
      $msg = 'Please enter a valid name and email address.';
      $msgType = 'error';
    } else {
      $profileStmt = $conn->prepare('UPDATE users SET fullname = ?, email = ? WHERE id = ?');
      if ($profileStmt) {
        $profileStmt->bind_param('ssi', $newName, $newEmail, $userId);
        if ($profileStmt->execute()) {
          $_SESSION['user_name'] = $newName;
          $_SESSION['user_email'] = $newEmail;
          $userName = $newName;
          $userEmail = $newEmail;
          $msg = 'Your profile was updated.';
        } else {
          $msg = 'We could not update your name.';
          $msgType = 'error';
        }
        $profileStmt->close();
      }
    }
  }

  if ($_POST['action'] === 'change_password' && $conn) {
    $currentPassword = $_POST['current_password'] ?? '';
    $newPassword = $_POST['new_password'] ?? '';
    $confirmPassword = $_POST['confirm_password'] ?? '';
    $passwordStmt = $conn->prepare('SELECT password FROM users WHERE id = ? LIMIT 1');
    $passwordStmt?->bind_param('i', $userId);
    $passwordStmt?->execute();
    $passwordRow = $passwordStmt ? $passwordStmt->get_result()->fetch_assoc() : null;
    $passwordStmt?->close();
    $storedPassword = $passwordRow['password'] ?? '';
    $validCurrent = $storedPassword !== '' && (password_verify($currentPassword, $storedPassword) || hash_equals((string) $storedPassword, $currentPassword));
    if (!$validCurrent) {
      $msg = 'Your current password is incorrect.';
      $msgType = 'error';
    } elseif (strlen($newPassword) < 8 || strlen($newPassword) > 15) {
      $msg = 'Your new password must be between 8 and 15 characters.';
      $msgType = 'error';
    } elseif ($newPassword !== $confirmPassword) {
      $msg = 'Your new passwords do not match.';
      $msgType = 'error';
    } else {
      $newHash = password_hash($newPassword, PASSWORD_DEFAULT);
      $updatePassword = $conn->prepare('UPDATE users SET password = ? WHERE id = ?');
      if ($updatePassword) {
        $updatePassword->bind_param('si', $newHash, $userId);
        $msg = $updatePassword->execute() ? 'Your password was changed.' : 'We could not change your password.';
        $msgType = $updatePassword->affected_rows >= 0 && $msg === 'Your password was changed.' ? 'success' : 'error';
        $updatePassword->close();
      }
    }
  }
}

// Handle Joining Queue Action (Inserts ticket into MySQL `queue` table)
if ($_SERVER['REQUEST_METHOD'] === 'POST' && isset($_POST['action'])) {
  if ($isGuest && in_array($_POST['action'], ['join_queue', 'join_other_queue', 'cancel_queue', 'update_profile', 'change_password'], true)) {
    $msg = 'Please sign in or create an account to use queue tickets.';
    $msgType = 'error';
  }

  if ($_POST['action'] === 'join_queue' && !$conn) {
    $msg = 'The queue service is temporarily unavailable. Please try again later.';
    $msgType = 'error';
  }

  if (!$isGuest && $_POST['action'] === 'join_queue' && $conn && $activeTicketNumber) {
    $msg = 'You already have an active ticket #' . $activeTicketNumber . '. Please wait until it is completed or cancel it before joining another queue.';
    $msgType = 'error';
  }

  if (!$isGuest && $_POST['action'] === 'join_queue' && $conn && !$activeTicketNumber) {
        $serviceId = (int)($_POST['service_id'] ?? 0);
        
    if ($serviceId <= 0) {
      $msg = 'Please select a valid service.';
      $msgType = 'error';
    } else {
            // Check service & dept details
                $sStmt = $conn->prepare("SELECT s.*, d.dept_code, d.dept_name, d.status AS dept_status FROM services s LEFT JOIN departments d ON s.dept_id = d.id WHERE s.id = ?");
            if ($sStmt) {
                $sStmt->bind_param("i", $serviceId);
                $sStmt->execute();
                $sRes = $sStmt->get_result();
                $serviceData = $sRes->fetch_assoc();
                $sStmt->close();

                if ($serviceData && ($serviceData['dept_status'] ?? 'active') !== 'active') {
                  $msg = 'This department is currently ' . ($serviceData['dept_status'] === 'maintenance' ? 'under maintenance' : 'inactive') . '. Please choose another service.';
                  $msgType = 'error';
                } elseif ($serviceData) {
                    $prefix = !empty($serviceData['dept_code']) ? strtoupper($serviceData['dept_code']) : 'Q';
                    
                    // Generate Next Ticket Number (e.g., R-029)
                    $maxQuery = $conn->query("SELECT MAX(id) as max_id FROM queue");
                    $nextId = 1;
                    if ($maxQuery && $maxRow = $maxQuery->fetch_assoc()) {
                        $nextId = ((int)$maxRow['max_id']) + 1;
                    }
                    $ticketNumber = sprintf("%s-%03d", $prefix, $nextId);

                    // Insert ticket into MySQL
                    $insertStmt = $conn->prepare("INSERT INTO queue (user_id, service_id, ticket_number, status) VALUES (?, ?, ?, 'waiting')");
                    if ($insertStmt) {
                        $insertStmt->bind_param("iis", $userId, $serviceId, $ticketNumber);
                        if ($insertStmt->execute()) {
                            header("Location: index.php?joined=1&ticket=" . urlencode($ticketNumber) . '#active-ticket-section');
                            exit;
                        } else {
                            $msg = "Error issuing ticket. Please try again.";
                            $msgType = "error";
                        }
                        $insertStmt->close();
                      } else {
                        $msg = 'We could not prepare your queue ticket. Please try again.';
                        $msgType = 'error';
                    }
                    } else {
                      $msg = 'That service is no longer available. Please choose another service.';
                      $msgType = 'error';
                }
                  } else {
                    $msg = 'We could not load that service. Please try again.';
                    $msgType = 'error';
            }
        }
    }

    if (!$isGuest && $_POST['action'] === 'join_other_queue' && !$conn) {
      $msg = 'The queue service is temporarily unavailable. Please try again later.';
      $msgType = 'error';
    }

    if (!$isGuest && $_POST['action'] === 'join_other_queue' && $conn && $activeTicketNumber) {
      $msg = 'You already have an active ticket #' . $activeTicketNumber . '. Please wait until it is completed or cancel it before joining another queue.';
      $msgType = 'error';
    }

    if (!$isGuest && $_POST['action'] === 'join_other_queue' && $conn && !$activeTicketNumber) {
      $otherServiceName = trim($_POST['other_service_name'] ?? '');
      $otherDepartmentId = (int)($_POST['other_department_id'] ?? 0);
      if ($otherServiceName === '' || strlen($otherServiceName) > 120) {
        $msg = 'Please enter an other service name up to 120 characters.';
        $msgType = 'error';
      } elseif ($otherDepartmentId <= 0) {
        $msg = 'Please select a department for your request.';
        $msgType = 'error';
      } else {
        $deptStmt = $conn->prepare("SELECT id, dept_code, status FROM departments WHERE id = ? LIMIT 1");
        $deptRow = null;
        $deptStmt?->bind_param('i', $otherDepartmentId);
        if ($deptStmt && $deptStmt->execute()) {
          $deptRow = $deptStmt->get_result()->fetch_assoc();
        }
        $deptStmt?->close();

        $otherDeptId = (int)($deptRow['id'] ?? 0);
        if ($otherDeptId <= 0 || ($deptRow['status'] ?? 'active') !== 'active') {
          $msg = 'That department is not currently available. Please choose another department.';
          $msgType = 'error';
          $otherDeptId = 0;
        }

        $serviceId = 0;
        if ($otherDeptId > 0) {
          $estimatedTime = 10;
          $createService = $conn->prepare('INSERT INTO services (dept_id, service_name, estimated_time_mins) VALUES (?, ?, ?)');
          if ($createService) {
            $createService->bind_param('isi', $otherDeptId, $otherServiceName, $estimatedTime);
            if ($createService->execute()) {
              $serviceId = $createService->insert_id;
            }
            $createService->close();
          }
        }

        if ($serviceId > 0) {
          $maxQuery = $conn->query('SELECT MAX(id) AS max_id FROM queue');
          $nextId = 1;
          if ($maxQuery && $maxRow = $maxQuery->fetch_assoc()) {
            $nextId = ((int)$maxRow['max_id']) + 1;
          }
          $ticketNumber = sprintf('O-%03d', $nextId);
          $insertOther = $conn->prepare("INSERT INTO queue (user_id, service_id, ticket_number, status) VALUES (?, ?, ?, 'waiting')");
          if ($insertOther) {
            $insertOther->bind_param('iis', $userId, $serviceId, $ticketNumber);
            if ($insertOther->execute()) {
              header('Location: index.php?joined=1&ticket=' . urlencode($ticketNumber) . '#active-ticket-section');
              exit;
            }
            $insertOther->close();
          }
        }

        $msg = 'We could not create your other service ticket. Please try again.';
        $msgType = 'error';
      }
    }

    if (!$isGuest && $_POST['action'] === 'cancel_queue' && $conn) {
        $queueId = (int)($_POST['queue_id'] ?? 0);
        if ($queueId > 0) {
            $cStmt = $conn->prepare("UPDATE queue SET status = 'cancelled' WHERE id = ? AND user_id = ?");
            if ($cStmt) {
                $cStmt->bind_param("ii", $queueId, $userId);
          $cStmt->execute();
          $cancelled = $cStmt->affected_rows > 0;
                $cStmt->close();
          header("Location: index.php?cancelled=" . ($cancelled ? '1' : '0'));
                exit;
            }
        }
    }
}

// Query Active MySQL Data for Customer Dashboard
$deptList = [];
$serviceList = [];
$myActiveTicket = null;
$myRecentTicket = null;
$nowServingTicket = null;

if ($conn) {
  $conn->query("CREATE TABLE IF NOT EXISTS service_fees (
    id INT AUTO_INCREMENT PRIMARY KEY,
    service_id INT NOT NULL UNIQUE,
    amount DECIMAL(10, 2) NOT NULL DEFAULT 0.00,
    updated_at TIMESTAMP DEFAULT CURRENT_TIMESTAMP ON UPDATE CURRENT_TIMESTAMP,
    CONSTRAINT fk_service_fees_service FOREIGN KEY (service_id) REFERENCES services(id) ON DELETE CASCADE
  ) ENGINE=InnoDB");
    $conn->query("CREATE TABLE IF NOT EXISTS queue_payments (
      id INT AUTO_INCREMENT PRIMARY KEY,
      queue_id INT NOT NULL UNIQUE,
      amount DECIMAL(10, 2) NOT NULL DEFAULT 0.00,
      status ENUM('pending', 'paid') NOT NULL DEFAULT 'pending',
      paid_at DATETIME NULL,
      CONSTRAINT fk_queue_payments_queue FOREIGN KEY (queue_id) REFERENCES queue(id) ON DELETE CASCADE
    ) ENGINE=InnoDB");

    // 1. Departments from MySQL
    $dRes = $conn->query("SELECT d.*, (SELECT COUNT(*) FROM services s WHERE s.dept_id = d.id) AS service_count FROM departments d ORDER BY d.id ASC");
    if ($dRes) {
        while ($dRow = $dRes->fetch_assoc()) {
            $deptList[] = $dRow;
        }
    }

    // 2. Services from MySQL
    $sRes = $conn->query("SELECT s.*, d.dept_name, d.dept_code, COALESCE(d.status, 'active') AS dept_status, COALESCE(sf.amount, 0) AS service_fee FROM services s LEFT JOIN departments d ON s.dept_id = d.id LEFT JOIN service_fees sf ON sf.service_id = s.id WHERE d.dept_code IS NULL OR d.dept_code <> 'O' ORDER BY s.id ASC");
    if ($sRes) {
        while ($sRow = $sRes->fetch_assoc()) {
            $serviceList[] = $sRow;
        }
    }

    $servicesByDepartment = [];
    foreach ($serviceList as $service) {
      $departmentKey = (string)($service['dept_id'] ?? 'unassigned');
      $servicesByDepartment[$departmentKey]['name'] = $service['dept_name'] ?? 'General Services';
      $servicesByDepartment[$departmentKey]['code'] = $service['dept_code'] ?? 'Q';
      $servicesByDepartment[$departmentKey]['services'][] = $service;
    }

    // 3. Customer's current active queue ticket from MySQL
    $tStmt = $conn->prepare("SELECT q.*, s.service_name, s.estimated_time_mins, d.dept_name, d.dept_code, COALESCE(sf.amount, 0) AS bill_amount, COALESCE(qp.status, 'pending') AS payment_status, qp.paid_at FROM queue q JOIN services s ON q.service_id = s.id JOIN departments d ON s.dept_id = d.id LEFT JOIN service_fees sf ON sf.service_id = s.id LEFT JOIN queue_payments qp ON qp.queue_id = q.id WHERE q.user_id = ? AND q.status IN ('waiting', 'called', 'now_serving') ORDER BY q.id DESC LIMIT 1");
    if ($tStmt) {
        $tStmt->bind_param("i", $userId);
        $tStmt->execute();
        $tRes = $tStmt->get_result();
        $myActiveTicket = $tRes->fetch_assoc();
        $tStmt->close();
    }

      // 4. Most recent finished ticket for the customer's ticket history
      $recentStmt = $conn->prepare("SELECT q.*, s.service_name, s.estimated_time_mins, d.dept_name, d.dept_code, COALESCE(sf.amount, 0) AS bill_amount, COALESCE(qp.status, 'pending') AS payment_status, qp.paid_at FROM queue q JOIN services s ON q.service_id = s.id JOIN departments d ON s.dept_id = d.id LEFT JOIN service_fees sf ON sf.service_id = s.id LEFT JOIN queue_payments qp ON qp.queue_id = q.id WHERE q.user_id = ? AND q.status IN ('completed', 'cancelled', 'no_show') ORDER BY q.id DESC LIMIT 1");
      if ($recentStmt) {
        $recentStmt->bind_param('i', $userId);
        $recentStmt->execute();
        $recentResult = $recentStmt->get_result();
        $myRecentTicket = $recentResult->fetch_assoc();
        $recentStmt->close();
      }

      // 5. Overall Now Serving Ticket from MySQL
    $nsRes = $conn->query("SELECT q.ticket_number, d.dept_name FROM queue q JOIN services s ON q.service_id = s.id JOIN departments d ON s.dept_id = d.id WHERE q.status IN ('called', 'now_serving') ORDER BY q.id DESC LIMIT 1");
    if ($nsRes && $nsRow = $nsRes->fetch_assoc()) {
        $nowServingTicket = $nsRow;
    }
}

// Calculate position ahead in queue
$activeStatusLabel = '';
if ($myActiveTicket) {
  $activeStatusLabel = in_array($myActiveTicket['status'], ['called', 'now_serving'], true)
    ? 'Calling'
    : ucfirst(str_replace('_', ' ', $myActiveTicket['status']));
}
$waitingAhead = 0;
if ($myActiveTicket && $conn) {
    $posStmt = $conn->prepare("SELECT COUNT(*) as ahead FROM queue WHERE service_id = ? AND status = 'waiting' AND id < ?");
    if ($posStmt) {
        $posStmt->bind_param("ii", $myActiveTicket['service_id'], $myActiveTicket['id']);
        $posStmt->execute();
        $posRes = $posStmt->get_result();
        if ($posRow = $posRes->fetch_assoc()) {
            $waitingAhead = (int)$posRow['ahead'];
        }
        $posStmt->close();
    }
}
?>
<!doctype html>
<html lang="en">
<head>
  <meta charset="UTF-8">
  <meta name="viewport" content="width=device-width, initial-scale=1.0">
  <title>QUEUEZY</title>
  <link rel="icon" type="image/png" href="assets/images/favicon.png">
  <link rel="stylesheet" href="https://cdnjs.cloudflare.com/ajax/libs/font-awesome/6.5.2/css/all.min.css">
  <link rel="preconnect" href="https://fonts.googleapis.com">
  <link rel="preconnect" href="https://fonts.gstatic.com" crossorigin>
  <link href="https://fonts.googleapis.com/css2?family=DM+Sans:wght@400;500;600;700&family=Inter:wght@400;500;600;700;800&display=swap" rel="stylesheet">
  <script src="https://cdn.tailwindcss.com"></script>
  <link rel="stylesheet" href="assets/css/admin-queuezy.css">
  <script src="assets/js/plugins/feather.min.js"></script>
  <style>
    body {
      font-family: 'DM Sans', 'Inter', -apple-system, sans-serif;
      background: #f8f7fc;
      color: #1e1b4b;
      margin: 0;
      padding: 0;
    }
    .queue-layout {
      display: flex;
      min-height: 100vh;
      width: 100%;
    }
    .queue-sidebar {
      width: 256px;
      height: 100vh;
      max-height: 100vh;
      padding: 28px 18px;
      background: #ffffff;
      border-right: 1px solid #ece9f4;
      position: fixed;
      top: 0; bottom: 0; left: 0;
      z-index: 40;
      display: flex;
      flex-direction: column;
      box-sizing: border-box;
      overflow: hidden;
    }
    .queue-main {
      margin-left: 256px;
      min-height: 100vh;
      flex: 1;
      display: flex;
      flex-direction: column;
      min-width: 0;
    }
    .queue-topbar {
      height: 76px;
      padding: 0 clamp(20px, 3vw, 48px);
      background: #ffffff;
      border-bottom: 1px solid #ece9f4;
      display: flex;
      align-items: center;
      justify-content: space-between;
      position: sticky;
      top: 0;
      z-index: 30;
    }
    .queue-menu {
      display: none;
      align-items: center;
      justify-content: center;
      border: 1px solid #ece9f4;
      border-radius: 10px;
      background: transparent;
      color: #7c3aed;
      cursor: pointer;
      padding: 8px;
    }
    .queue-menu svg,
    .queue-sidebar-toggle svg { width: 18px; height: 18px; stroke-width: 2.5; }
    .queue-menu span,
    .queue-sidebar-toggle span { font-size: 22px; line-height: 1; font-weight: 700; }
    .queue-sidebar-backdrop {
      display: none;
    }
    .queue-nav {
      display: flex;
      flex-direction: column;
      gap: 6px;
      margin-top: 16px;
    }
    .queue-nav a {
      display: flex;
      align-items: center;
      gap: 12px;
      padding: 12px 14px;
      border-radius: 12px;
      color: #64748b;
      font-size: 13px;
      font-weight: 600;
      text-decoration: none;
      transition: all 0.2s;
    }
    .queue-nav a.is-active, .queue-nav a:hover {
      background: #f3e8ff;
      color: #7c3aed;
    }
    .queue-nav a:focus-visible,
    .queue-sidebar-toggle:focus-visible,
    .queue-menu:focus-visible {
      outline: 3px solid #c4b5fd;
      outline-offset: 2px;
    }
    .queue-brand {
      display: flex;
      align-items: center;
      gap: 8px;
      font-size: 20px;
      font-weight: 800;
      color: #0f172a;
      text-decoration: none;
    }
    .queue-brand-logo {
      display: block;
      flex: 0 0 64px;
      width: 64px !important;
      height: 64px !important;
      max-width: 64px;
      max-height: 64px;
      object-fit: contain;
      border-radius: 12px;
    }
    .queue-brand-logo--processing { opacity: 0; }
    .queue-brand-name { color: #0f172a; font-weight: 900; text-transform: uppercase; white-space: nowrap; }
    .queue-brand-name span { color: #6d28d9; }
    .queue-brand-row {
      display: flex;
      align-items: center;
      justify-content: space-between;
      gap: 12px;
    }
    .queue-sidebar-toggle {
      display: inline-flex;
      align-items: center;
      justify-content: center;
      width: 34px;
      height: 34px;
      border: 1px solid #ece9f4;
      border-radius: 10px;
      background: #ffffff;
      color: #7c3aed;
      cursor: pointer;
    }
    .queue-sidebar-toggle:hover,
    .queue-menu:hover { background: #f3e8ff; color: #6d28d9; }
    .queue-brand-mark {
      width: 36px;
      height: 36px;
      border-radius: 12px;
      background: #7c3aed;
      color: #ffffff;
      display: flex;
      align-items: center;
      justify-content: center;
      font-weight: 900;
    }
    .queue-sidebar__label {
      font-size: 10px;
      font-weight: 800;
      text-transform: uppercase;
      letter-spacing: 1px;
      color: #94a3b8;
      margin-top: 24px;
      margin-bottom: 8px;
    }
    .queue-sidebar-section-toggle {
      width: 100%;
      border: 0;
      background: transparent;
      text-align: left;
      cursor: pointer;
      display: flex;
      align-items: center;
      justify-content: space-between;
      font: inherit;
    }
    .queue-sidebar-section-toggle svg {
      width: 14px;
      height: 14px;
      transition: transform 0.2s ease;
    }
    .queue-sidebar-section-toggle[aria-expanded="false"] svg {
      transform: rotate(-90deg);
    }
    .queue-sidebar-section.is-collapsed {
      display: none;
    }
    .queue-sidebar__account {
      margin-top: auto;
    }
    .queue-sidebar__footer {
      margin-top: 0;
      padding-top: 16px;
      border-top: 1px solid #f1f0f7;
      display: flex;
      align-items: center;
      gap: 12px;
      font-size: 12px;
    }
    .queue-sidebar__footer strong,
    .queue-sidebar__footer small {
      display: block;
      line-height: 1.35;
    }
    .queue-help-icon {
      width: 36px;
      height: 36px;
      flex: 0 0 36px;
      display: flex;
      align-items: center;
      justify-content: center;
      border-radius: 10px;
      background: #f8fafc;
      color: #7c3aed;
    }
    .profile-icon {
      width: 42px;
      height: 42px;
      flex: 0 0 42px;
      display: inline-flex;
      align-items: center;
      justify-content: center;
      border-radius: 50%;
      background: #f1f5f9;
      color: #111827;
    }
    .profile-icon svg { width: 28px; height: 28px; stroke-width: 2.3; }
    .profile-icon i { font-size: 20px; }
    .queue-avatar {
      width: 36px;
      height: 36px;
      border-radius: 50%;
      background: #f3e8ff;
      color: #7c3aed;
      display: flex;
      align-items: center;
      justify-content: center;
      font-weight: 800;
    }
    .queue-user {
      display: flex;
      align-items: center;
      gap: 10px;
      padding: 8px 14px 8px 8px;
      border: 1px solid #e2e8f0;
      border-radius: 14px;
      background: #f8fafc;
      color: inherit;
      text-decoration: none;
      cursor: pointer;
    }
    .queue-user:hover { border-color: #c4b5fd; background: #f3e8ff; }
    .queue-user strong,
    .queue-user small {
      display: block;
      line-height: 1.3;
    }
    .queue-user strong { color: #0f172a; font-size: 12px; }
    .queue-user small { color: #7c3aed; font-size: 9px; font-weight: 700; text-transform: uppercase; margin-top: 2px; }
    .queue-user .profile-icon {
      width: 36px;
      height: 36px;
      flex-basis: 36px;
    }
    .notification-sidebar-link { position: relative; }
    .sidebar-notification-dot {
      width: 7px;
      height: 7px;
      margin-left: auto;
      border-radius: 50%;
      background: #dc2626;
      border: 1px solid #ffffff;
    }
    .queue-topbar__actions {
      margin-left: auto;
      display: flex;
      align-items: center;
      gap: 16px;
      position: relative;
    }
    .queue-icon-button {
      position: relative;
      width: 42px;
      height: 42px;
      display: inline-flex;
      align-items: center;
      justify-content: center;
      border: 1px solid #e2e8f0;
      border-radius: 12px;
      background: #f8fafc;
      color: #7c3aed;
      cursor: pointer;
    }
    .queue-icon-button i { font-size: 17px; }
    .queue-icon-button:hover { background: #f3e8ff; color: #6d28d9; }
    .queue-icon-button b {
      position: absolute;
      top: 8px;
      right: 9px;
      width: 5px;
      height: 5px;
      border-radius: 50%;
      background: #ef4444;
    }
    .notification-panel {
      position: absolute;
      top: 54px;
      right: 0;
      z-index: 60;
      width: 300px;
      padding: 16px;
      border: 1px solid #e2e8f0;
      border-radius: 14px;
      background: #ffffff;
      color: #0f172a;
      box-shadow: 0 12px 28px rgba(15, 23, 42, 0.16);
      opacity: 0;
      visibility: hidden;
      pointer-events: none;
      transform: translateY(-6px);
      transition: opacity 180ms ease, transform 180ms ease, visibility 180ms ease;
    }
    .notification-panel.is-visible { opacity: 1; visibility: visible; pointer-events: auto; transform: translateY(0); }
    .notification-panel h3 { margin: 0; font-size: 14px; font-weight: 800; }
    .notification-item { display: flex; gap: 10px; margin-top: 14px; font-size: 12px; line-height: 1.4; }
    .notification-item i { color: #7c3aed; margin-top: 2px; }
    .notification-item strong { display: block; }
    .notification-item span { color: #64748b; }
    .queue-ticket-button,
    a[data-page-link="services"] { box-shadow: none; }
    .queue-ticket-button:hover,
    a[data-page-link="services"]:hover { box-shadow: none; }
    .queue-ticket-button.is-blocked { opacity: 0.5; cursor: not-allowed; }
    a.queue-join-button {
      position: relative;
      isolation: isolate;
      overflow: hidden;
      border: 2px solid transparent;
      background: linear-gradient(100deg, #982fe8, #5147df) padding-box,
        conic-gradient(from var(--queue-edge-angle), #982fe8 0deg 250deg, #f5d0fe 285deg, #ffffff 315deg, #982fe8 350deg 360deg) border-box !important;
      box-shadow: 0 0 16px rgba(124, 58, 237, 0.28);
      animation: queueEdgeRotation 2.8s linear infinite;
    }
    a.queue-join-button:hover,
    a.queue-join-button:focus-visible {
      box-shadow: 0 0 22px rgba(168, 85, 247, 0.52);
    }
    @property --queue-edge-angle {
      syntax: '<angle>';
      initial-value: 0deg;
      inherits: false;
    }
    @keyframes queueEdgeRotation {
      to { --queue-edge-angle: 360deg; }
    }
    @media (prefers-reduced-motion: reduce) {
      a.queue-join-button { animation: none; }
    }
    .other-service-card { position: relative; overflow: visible !important; }
    .other-service-card[data-hover-message]::after {
      content: attr(data-hover-message);
      position: absolute;
      left: 0;
      top: calc(100% + 8px);
      width: 100%;
      box-sizing: border-box;
      padding: 10px 12px;
      border-radius: 10px;
      background: #1e1b4b;
      color: #ffffff;
      font-size: 11px;
      font-weight: 700;
      line-height: 1.35;
      text-align: center;
      opacity: 0;
      pointer-events: none;
      transform: translateY(-5px);
      transition: opacity 0.18s ease, transform 0.18s ease;
      z-index: 3;
    }
    .other-service-card[data-hover-message]:hover::after,
    .other-service-card[data-hover-message]:focus-within::after { opacity: 1; transform: translateY(0); }
    body.dark-mode .other-service-card[data-hover-message]::after { background: #1e1b4b; color: #ffffff; }
    body.dark-mode .other-service-card h3 { color: #f8fafc !important; }
    body.dark-mode .other-service-card p { color: #cbd5e1 !important; }
    body.dark-mode .other-service-card input { background: #0f172a; border-color: #475569; color: #f8fafc; }
    body.dark-mode .other-service-card input::placeholder { color: #94a3b8; }
    .queue-content {
      max-width: 1360px;
      width: 100%;
      box-sizing: border-box;
      padding: 36px clamp(20px, 3.5vw, 48px);
      margin: 0 auto;
      flex: 1;
    }
    @media (max-width: 900px) {
      .queue-menu { display: inline-flex; align-items: center; justify-content: center; }
      .queue-sidebar { transform: translateX(-100%); transition: transform 0.25s; }
      .queue-sidebar.is-open { transform: translateX(0); box-shadow: 0 10px 40px rgba(0,0,0,0.2); }
      .queue-main { margin-left: 0; }
      .queue-sidebar-backdrop {
        position: fixed;
        inset: 0;
        z-index: 35;
        background: rgba(15, 23, 42, 0.35);
      }
      .queue-sidebar-backdrop.is-visible { display: block; }
    }
    .stat-card-luxury {
      background: #ffffff;
      border: 1px solid #ece9f4;
      border-radius: 20px;
      padding: 24px;
      transition: all 0.28s cubic-bezier(0.4, 0, 0.2, 1);
      box-shadow: 0 4px 20px -2px rgba(57, 35, 97, 0.04);
      position: relative;
      overflow: hidden;
    }
    .stat-card-luxury:hover {
      transform: none;
      box-shadow: none;
      border-color: #ece9f4;
    }
    .stat-card-luxury::after {
      content: '';
      position: absolute;
      top: 0; left: 0; right: 0;
      height: 4px;
      background: linear-gradient(90deg, #7c3aed, #a855f7);
      opacity: 0;
      transition: opacity 0.25s ease;
    }
    .stat-card-luxury:hover::after { opacity: 0; }

    .live-pulse-dot {
      width: 8px;
      height: 8px;
      border-radius: 50%;
      background: #10b981;
      display: inline-block;
      box-shadow: 0 0 0 0 rgba(16, 185, 129, 0.7);
      animation: livePulse 1.6s infinite;
    }
    @keyframes livePulse {
      0% { transform: scale(0.95); box-shadow: 0 0 0 0 rgba(16, 185, 129, 0.7); }
      70% { transform: scale(1); box-shadow: 0 0 0 8px rgba(16, 185, 129, 0); }
      100% { transform: scale(0.95); box-shadow: 0 0 0 0 rgba(16, 185, 129, 0); }
    }

    .toast-container {
      position: fixed;
      bottom: 24px; right: 24px;
      z-index: 1000;
      display: flex; flex-direction: column; gap: 10px;
      pointer-events: none;
    }
    .toast-item {
      background: #1e1b4b; color: #ffffff;
      padding: 14px 20px; border-radius: 16px;
      font-size: 13px; font-weight: 600;
      display: flex; align-items: center; gap: 12px;
      box-shadow: 0 12px 30px rgba(0, 0, 0, 0.2);
      pointer-events: auto;
    }
    .confirm-modal {
      position: fixed;
      inset: 0;
      z-index: 1100;
      display: none;
      align-items: center;
      justify-content: center;
      padding: 20px;
      background: rgba(15, 23, 42, 0.48);
    }
    .confirm-modal.is-visible { display: flex; }
    .confirm-dialog {
      width: min(380px, 100%);
      padding: 24px;
      border: 1px solid #e2e8f0;
      border-radius: 18px;
      background: #ffffff;
      color: #0f172a;
      box-shadow: 0 20px 50px rgba(15, 23, 42, 0.22);
    }
    .confirm-dialog h2 { margin: 0; font-size: 18px; font-weight: 800; }
    .customer-call-overlay {
      position: fixed;
      inset: 0;
      z-index: 1200;
      display: none;
      align-items: center;
      justify-content: center;
      padding: 20px;
      background: rgba(15, 23, 42, 0.72);
    }
    .customer-call-overlay.is-visible { display: flex; }
    .customer-call-dialog {
      width: min(430px, 100%);
      padding: 28px 22px 22px;
      border: 1px solid #475569;
      border-radius: 22px;
      background: #1e293b;
      color: #ffffff;
      text-align: center;
      box-shadow: 0 24px 70px rgba(0, 0, 0, 0.45);
    }
    .customer-call-dialog__ticket {
      margin: 18px 0;
      padding: 24px 12px;
      border: 1px solid #475569;
      border-radius: 18px;
      background: #0f172a;
      font-size: clamp(42px, 12vw, 72px);
      font-weight: 900;
      line-height: 1;
      letter-spacing: -1px;
    }
    .customer-call-dialog__stop {
      width: 100%;
      border: 0;
      border-radius: 12px;
      padding: 13px 16px;
      background: #dc2626;
      color: #ffffff;
      font-weight: 800;
      cursor: pointer;
    }
    .confirm-dialog p { margin: 8px 0 20px; color: #64748b; font-size: 13px; }
    .confirm-actions { display: flex; justify-content: flex-end; gap: 10px; }
    .confirm-actions button { border: 0; border-radius: 10px; padding: 10px 16px; font-weight: 700; cursor: pointer; }
    .confirm-cancel { background: #f1f5f9; color: #475569; }
    .confirm-continue { background: #7c3aed; color: #ffffff; }
    .confirm-continue.is-logging-out { cursor: wait; opacity: 0.8; }
    .confirm-continue.is-logging-out::before {
      content: '';
      display: inline-block;
      width: 12px;
      height: 12px;
      margin-right: 7px;
      border: 2px solid rgba(255, 255, 255, 0.45);
      border-top-color: #ffffff;
      border-radius: 50%;
      vertical-align: -2px;
      animation: logoutSpinner 650ms linear infinite;
    }
    @keyframes logoutSpinner { to { transform: rotate(360deg); } }
    .faq-item {
      border: 1px solid #e2e8f0;
      border-radius: 14px;
      padding: 14px 16px;
      background: #ffffff;
    }
    .faq-item summary {
      cursor: pointer;
      color: #0f172a;
      font-size: 14px;
      font-weight: 800;
    }
    .faq-item p {
      margin: 10px 0 0;
      color: #475569;
      font-size: 13px;
      line-height: 1.6;
    }
    .customer-ticket-number {
      display: block;
      width: 100%;
      text-align: center;
    }
    .customer-queue-position {
      display: flex;
      flex-direction: column;
      align-items: center;
      text-align: center;
    }
    .customer-queue-position form { width: 100%; }
    body.dark-mode .faq-item { background: #1e293b; border-color: #475569; }
    body.dark-mode .faq-item summary { color: #f8fafc; }
    body.dark-mode .faq-item p { color: #cbd5e1; }
    body.dark-mode .confirm-dialog { background: #1e293b; border-color: #475569; color: #f8fafc; }
    body.dark-mode .confirm-dialog p { color: #cbd5e1; }
    body.dark-mode .confirm-cancel { background: #334155; color: #e2e8f0; }
    .hidden { display: none !important; }
    .queue-page { display: none !important; opacity: 0; transform: translateY(8px); }
    .queue-page.is-current { display: block !important; animation: queuePageEnter 320ms cubic-bezier(0.22, 1, 0.36, 1) both; }
    .queue-page.is-current.grid { display: grid !important; }
    @keyframes queuePageEnter { from { opacity: 0; transform: translateY(8px); } to { opacity: 1; transform: translateY(0); } }
    @media (prefers-reduced-motion: reduce) {
      .notification-panel { transition: none; }
      .queue-page.is-current { animation: none; opacity: 1; transform: none; }
    }
    .queue-page.is-hidden { display: none !important; }
    body.dark-mode { background: #0f172a; color: #e5e7eb; }
    body.dark-mode .queue-content { background: #0f172a; }
    body.dark-mode .queue-topbar,
    body.dark-mode .queue-sidebar,
    body.dark-mode .stat-card-luxury,
    body.dark-mode .queue-brand-row button,
    body.dark-mode #settings-section form,
    body.dark-mode #settings-section > div > div { background: #1e293b; border-color: #334155; }
    body.dark-mode .queue-topbar { border-color: #334155; }
    body.dark-mode .queue-sidebar { border-color: #334155; }
    body.dark-mode h1,
    body.dark-mode h2,
    body.dark-mode h3,
    body.dark-mode label { color: #f9fafb; }
    body.dark-mode .text-slate-900,
    body.dark-mode .text-slate-800,
    body.dark-mode .queue-brand { color: #f9fafb; }
    body.dark-mode .queue-brand-name { color: #ffffff !important; }
    body.dark-mode .queue-brand-name span { color: #c4b5fd !important; }
    body.dark-mode p,
    body.dark-mode .text-slate-500,
    body.dark-mode .text-slate-400 { color: #9ca3af; }
    body.dark-mode .queue-nav a { color: #cbd5e1; }
    body.dark-mode .queue-nav a.is-active,
    body.dark-mode .queue-nav a:hover { background: #312e81; color: #ffffff; }
    body.dark-mode .queue-sidebar__label { color: #94a3b8; }
    body.dark-mode input { background: #0f172a; border-color: #475569; color: #f9fafb; }
    body.dark-mode .text-slate-600,
    body.dark-mode .text-slate-700,
    body.dark-mode .text-gray-500,
    body.dark-mode .text-gray-600,
    body.dark-mode .text-gray-700 { color: #cbd5e1 !important; }
    body.dark-mode .text-slate-900,
    body.dark-mode .text-slate-800,
    body.dark-mode .text-gray-800 { color: #f8fafc !important; }
    body.dark-mode .profile-icon { background: #334155; color: #f8fafc; }
    body.dark-mode .queue-menu,
    body.dark-mode .queue-sidebar-toggle { background: #1e293b; border-color: #475569; color: #c4b5fd; }
    body.dark-mode .queue-menu:hover,
    body.dark-mode .queue-sidebar-toggle:hover { background: #312e81; color: #ffffff; }
    .queue-layout.sidebar-collapsed .queue-sidebar { transform: translateX(-100%); transition: transform 0.25s; }
    .queue-sidebar.is-collapsed { transform: translateX(-100%); transition: transform 0.25s; }
    .queue-layout.sidebar-collapsed .queue-main { margin-left: 0; }
    .queue-layout.sidebar-collapsed .queue-menu { display: inline-flex; }
    body.dark-mode .queue-sidebar__footer strong,
    body.dark-mode .queue-user strong { color: #f9fafb; }
    body.dark-mode .queue-sidebar__footer small,
    body.dark-mode .queue-user small { color: #94a3b8; }
    body.dark-mode .queue-user { background: #1e293b; border-color: #475569; }
    body.dark-mode .queue-icon-button { background: #1e293b; border-color: #475569; color: #c4b5fd; }
    body.dark-mode .queue-icon-button:hover { background: #312e81; color: #ffffff; }
    body.dark-mode .notification-panel { background: #1e293b; border-color: #475569; color: #f8fafc; box-shadow: 0 12px 28px rgba(0, 0, 0, 0.35); }
    body.dark-mode .notification-item span { color: #cbd5e1; }
    body.dark-mode .stat-card-luxury .bg-purple-50 { background: #3b2d52 !important; }
    body.dark-mode .stat-card-luxury .bg-indigo-50 { background: #293754 !important; }
    body.dark-mode .stat-card-luxury .bg-emerald-50 { background: #183d35 !important; }
    body.dark-mode .stat-card-luxury .bg-amber-50 { background: #493c1e !important; }
    body.dark-mode .stat-card-luxury .text-purple-600 { color: #c4b5fd !important; }
    body.dark-mode .stat-card-luxury .text-indigo-600 { color: #bfdbfe !important; }
    body.dark-mode .stat-card-luxury .text-emerald-600 { color: #6ee7b7 !important; }
    body.dark-mode .queue-ticket-button,
    body.dark-mode a[data-page-link="services"] { box-shadow: none !important; }
    body.dark-mode .queue-ticket-button:hover,
    body.dark-mode a[data-page-link="services"]:hover { box-shadow: none !important; }
    .customer-department-services {
      max-height: 0;
      opacity: 0;
      overflow: hidden;
      transition: max-height 360ms cubic-bezier(0.4, 0, 0.2, 1), opacity 240ms ease;
    }
    .customer-department-services.is-expanded { opacity: 1; }
    .customer-department-chevron {
      color: #334155;
      transition: none;
    }
    .customer-service-row {
      min-height: 76px;
    }
    .service-price {
      display: inline-flex;
      align-items: center;
      min-height: 30px;
      padding: 5px 10px;
      border: 1px solid #dbe3ee;
      border-radius: 8px;
      background: #f8fafc;
      color: #334155;
      font-size: 12px;
      font-weight: 800;
      white-space: nowrap;
    }
    .customer-service-row .queue-ticket-button {
      min-width: 112px;
      justify-content: center;
      border-radius: 10px;
      box-shadow: none;
    }
    body.dark-mode .customer-department-chevron { color: #e2e8f0 !important; }
    body.dark-mode .service-price { background: #1e293b; border-color: #475569; color: #e2e8f0; }
    @media (max-width: 900px) {
      .queue-menu { display: inline-flex; }
      .queue-layout:has(.queue-sidebar.is-open) .queue-menu { display: none; }
      .queue-layout.sidebar-collapsed .queue-sidebar.is-open { transform: translateX(0); }
    }
  </style>
</head>
<script src="assets/js/password-toggle.js"></script>
<body class="queue-app">
<script>
  if (localStorage.getItem('queuezy-dark-mode') === 'true') document.body.classList.add('dark-mode');
</script>
<div class="queue-layout">

  <!-- Sidebar aligned with Admin layout -->
  <aside class="queue-sidebar" id="queueSidebar">
    <div class="queue-brand-row">
      <div class="queue-brand" role="img" aria-label="Queuezy"><img class="queue-brand-logo queue-brand-logo--processing" id="queueBrandLogo" src="assets/images/logo.jpg" alt="" width="64" height="64"><span class="queue-brand-name">Queue<span>zy</span></span></div>
      <button class="queue-sidebar-toggle" id="queueSidebarToggle" type="button" aria-label="Collapse sidebar"><span aria-hidden="true">&lt;</span></button>
    </div>
    <script>
      (() => {
        const logo = document.getElementById('queueBrandLogo');
        const removeCheckerboard = () => {
          if (!logo?.naturalWidth) return;
          const canvas = document.createElement('canvas');
          canvas.width = logo.naturalWidth;
          canvas.height = logo.naturalHeight;
          const context = canvas.getContext('2d');
          context.drawImage(logo, 0, 0);
          const image = context.getImageData(0, 0, canvas.width, canvas.height);
          for (let index = 0; index < image.data.length; index += 4) {
            const red = image.data[index];
            const green = image.data[index + 1];
            const blue = image.data[index + 2];
            const brightness = (red + green + blue) / 3;
            const saturation = Math.max(red, green, blue) - Math.min(red, green, blue);
            if (saturation < 18 && brightness < 180) image.data[index + 3] = 0;
          }
          context.putImageData(image, 0, 0);
          logo.src = canvas.toDataURL('image/png');
          logo.classList.remove('queue-brand-logo--processing');
        };
        logo?.addEventListener('load', removeCheckerboard, { once: true });
        if (logo?.complete) removeCheckerboard();
      })();
    </script>
    <div class="queue-sidebar-section" data-sidebar-section="service">
      <nav class="queue-nav" aria-label="Customer navigation">
        <a href="index.php"><i data-feather="home"></i><span>Home</span></a>
        <a href="#services-section"><i data-feather="layers"></i><span>Services</span></a>
        <a href="#notifications" class="notification-sidebar-link" id="notificationSidebarLink"<?php if ($myActiveTicket): ?> data-notification-key="<?= htmlspecialchars($myActiveTicket['ticket_number'] . '|' . $myActiveTicket['status'], ENT_QUOTES) ?>"<?php endif; ?>><i data-feather="bell"></i><span>Notifications</span><?php if ($myActiveTicket): ?><b class="sidebar-notification-dot" aria-label="Unread notification"></b><?php endif; ?></a>
      </nav>
    </div>
    <div class="queue-sidebar-section" data-sidebar-section="ticket">
      <nav class="queue-nav" aria-label="My queue navigation">
        <a href="#active-ticket-section"><i data-feather="clock"></i><span>My Queue Ticket</span></a>
        <a href="#bill-section"><i data-feather="credit-card"></i><span>My Bill</span></a>
        <a href="#settings-section"><i data-feather="settings"></i><span>Settings</span></a>
        <a href="#faqs"><i data-feather="help-circle"></i><span>FAQs</span></a>
      </nav>
    </div>
    <div class="queue-sidebar__account">
      <div class="queue-sidebar-section" data-sidebar-section="account">
        <div class="queue-sidebar__footer">
          <div class="profile-icon" aria-label="Profile"><i class="fa-solid fa-user"></i></div>
          <div style="flex:1;">
            <strong><?= htmlspecialchars($userName) ?></strong>
            <small>Customer</small>
          </div>
        </div>
        <div style="padding: 12px 18px 0 18px;">
          <a href="logout.php" class="logout-link" style="color: #ef4444; font-weight: 700; text-decoration: none; display: flex; align-items: center; gap: 8px; font-size: 13px;">
            <i data-feather="log-out" style="width: 16px; height: 16px; color: #ef4444;"></i>
            <span>Log Out</span>
          </a>
        </div>
      </div>
    </div>
  </aside>
  <div class="queue-sidebar-backdrop" id="queueSidebarBackdrop"></div>
  <div class="customer-call-overlay" id="customerCallOverlay" role="alertdialog" aria-modal="true" aria-labelledby="customerCallTitle">
    <div class="customer-call-dialog">
      <p class="text-xs font-bold uppercase tracking-widest text-blue-200">Your ticket is calling</p>
      <h2 id="customerCallTitle" class="mt-2 text-2xl font-black">Front Desk</h2>
      <p id="customerCallService" class="mt-1 text-sm text-slate-300">Please proceed to the front desk.</p>
      <div id="customerCallTicket" class="customer-call-dialog__ticket">--</div>
      <button id="stopCustomerCallOverlay" type="button" class="customer-call-dialog__stop">Stop Ringing</button>
    </div>
  </div>

  <!-- Main Content -->
  <main class="queue-main">
    <header class="queue-topbar">
      <button class="queue-menu" id="queueMenu" type="button" aria-label="Open sidebar"><span aria-hidden="true">&gt;</span></button>
      <div class="queue-topbar__actions">
        <button class="queue-icon-button" id="notificationButton" type="button" aria-label="Notifications" aria-expanded="false"><i class="fa-solid fa-bell"></i><?php if ($myActiveTicket): ?><b id="topNotificationDot" aria-label="Unread notification"></b><?php endif; ?></button>
        <div class="notification-panel" id="notificationPanel" role="status" aria-live="polite">
          <h3>Notifications</h3>
          <?php if ($myActiveTicket): ?>
          <?php if (in_array($myActiveTicket['status'], ['called', 'now_serving'], true)): ?>
          <div class="notification-item"><i class="fa-solid fa-bullhorn"></i><div><strong id="notificationTitle">Your ticket is now calling</strong><span id="notificationMessage">Ticket #<?= htmlspecialchars($myActiveTicket['ticket_number']) ?> is now calling. Please proceed to the service desk.</span></div></div>
          <?php else: ?>
          <div class="notification-item"><i class="fa-solid fa-ticket"></i><div><strong id="notificationTitle">Your queue is active</strong><span id="notificationMessage">Ticket #<?= htmlspecialchars($myActiveTicket['ticket_number']) ?> is <?= htmlspecialchars(str_replace('_', ' ', $myActiveTicket['status'])) ?>.</span></div></div>
          <?php endif; ?>
          <?php else: ?>
          <div class="notification-item"><i class="fa-solid fa-circle-check"></i><div><strong>No new notifications</strong><span>You are all caught up.</span></div></div>
          <?php endif; ?>
        </div>
        <a href="#settings-section" class="queue-user" data-page-link="settings" aria-label="Open customer settings">
          <span class="profile-icon" aria-label="Profile"><i class="fa-solid fa-user"></i></span>
          <span><strong><?= htmlspecialchars($userName) ?></strong><small>Customer</small></span>
        </a>
      </div>
    </header>

    <div class="queue-content">
      
      <!-- Top Welcome Ribbon -->
      <div class="queue-page" data-page="home">
      <div class="mb-8 flex flex-wrap items-center justify-between gap-4">
        <div>
         
          <h1 class="text-2xl sm:text-3xl font-black text-slate-900 tracking-tight flex items-center gap-3">
            <span>Welcome, <?= htmlspecialchars($userName) ?>!</span>
            
          </h1>
          <p class="text-sm text-slate-500 mt-1">Join a queue line remotely, view active tickets, and track counter calls in real time.</p>
        </div>

        <a href="#services-section" data-page-link="services" class="queue-join-button ml-auto flex-shrink-0 px-5 py-2.5 rounded-xl bg-gradient-to-r from-purple-600 to-indigo-600 text-white font-bold text-sm hover:from-purple-700 hover:to-indigo-700 transition inline-flex items-center gap-2">
          <i data-feather="plus-circle" class="w-4 h-4"></i>
          <span>Join a Queue Line</span>
        </a>
      </div>

      <?php if ($msg): ?>
      <div class="queue-page mb-6 p-4 rounded-2xl border <?= $msgType === 'error' ? 'bg-red-50 border-red-200 text-red-700' : 'bg-emerald-50 border-emerald-200 text-emerald-700' ?> font-semibold text-xs flex items-center gap-2" data-page="home">
        <i data-feather="<?= $msgType === 'error' ? 'alert-circle' : 'check-circle' ?>" class="w-4 h-4"></i>
        <span><?= htmlspecialchars($msg) ?></span>
      </div>
      <?php endif; ?>

      <!-- Executive Metric Cards -->
      <div class="queue-page grid grid-cols-1 sm:grid-cols-2 xl:grid-cols-4 gap-5 mb-8" data-page="home">
        <div class="stat-card-luxury">
          <div class="flex items-center justify-between">
            <span class="text-xs font-bold text-slate-400 uppercase tracking-wider">Your Active Ticket</span>
            <div class="w-10 h-10 rounded-2xl bg-purple-50 text-purple-600 flex items-center justify-center">
              <i class="fa-solid fa-ticket" style="font-size: 20px;" aria-hidden="true"></i>
            </div>
          </div>
          <div class="mt-3">
            <h3 class="text-3xl font-black text-purple-600">
              <?= $myActiveTicket ? '#' . htmlspecialchars($myActiveTicket['ticket_number']) : 'No Ticket' ?>
            </h3>
            <p class="text-xs text-slate-500 mt-2">
              <?= $myActiveTicket ? htmlspecialchars($myActiveTicket['service_name']) : 'Select a service below to join' ?>
            </p>
          </div>
        </div>

        <div class="stat-card-luxury">
          <div class="flex items-center justify-between">
            <span class="text-xs font-bold text-slate-400 uppercase tracking-wider">Queue Position</span>
            <div class="w-10 h-10 rounded-2xl bg-indigo-50 text-indigo-600 flex items-center justify-center">
              <i class="fa-solid fa-users" style="font-size: 20px;" aria-hidden="true"></i>
            </div>
          </div>
          <div class="mt-3">
            <h3 class="text-3xl font-black text-indigo-600">
              <?= $myActiveTicket ? '#' . ($waitingAhead + 1) : '--' ?>
            </h3>
            <p class="text-xs text-slate-500 mt-2">
              <?= $myActiveTicket ? $waitingAhead . ' customer(s) ahead of you' : 'Join a service line' ?>
            </p>
          </div>
        </div>

        <div class="stat-card-luxury">
          <div class="flex items-center justify-between">
            <span class="text-xs font-bold text-slate-400 uppercase tracking-wider">Now Serving</span>
            <div class="w-10 h-10 rounded-2xl bg-emerald-50 text-emerald-600 flex items-center justify-center">
              <i class="fa-solid fa-bullhorn" style="font-size: 20px;" aria-hidden="true"></i>
            </div>
          </div>
          <div class="mt-3">
            <h3 class="text-3xl font-black text-emerald-600">
              <?= $nowServingTicket ? '#' . htmlspecialchars($nowServingTicket['ticket_number']) : 'None' ?>
            </h3>
            <p class="text-xs text-slate-500 mt-2">
              <?= $nowServingTicket ? htmlspecialchars($nowServingTicket['dept_name']) . ' Desk' : 'Counter active' ?>
            </p>
          </div>
        </div>

        <div class="stat-card-luxury">
          <div class="flex items-center justify-between">
            <span class="text-xs font-bold text-slate-400 uppercase tracking-wider">Available Services</span>
            <div class="w-10 h-10 rounded-2xl bg-amber-50 text-amber-600 flex items-center justify-center">
              <i class="fa-solid fa-layer-group" style="font-size: 20px;" aria-hidden="true"></i>
            </div>
          </div>
          <div class="mt-3">
            <h3 class="text-3xl font-black text-slate-900"><?= count($serviceList) ?></h3>
            <p class="text-xs text-slate-500 mt-2">Services ready in catalog</p>
          </div>
        </div>
      </div>
      </div>

      <!-- Active Ticket Banner (If customer has a ticket in queue) -->
      <?php if ($myActiveTicket || $myRecentTicket): ?>
      <div class="queue-page bg-gradient-to-br from-purple-900 via-indigo-900 to-slate-900 text-white rounded-3xl p-6 sm:p-8 shadow-xl mb-10 border border-purple-800/40 relative overflow-hidden" id="active-ticket-section" data-page="ticket">
        <div class="flex flex-col md:flex-row items-center md:items-center justify-between gap-6 relative z-10">
          <div>
            <?php $displayTicket = $myActiveTicket ?: $myRecentTicket; ?>
            <span class="inline-flex items-center gap-2 <?= $myActiveTicket ? 'bg-purple-500/20 text-purple-300 border-purple-500/30' : 'bg-slate-500/20 text-slate-300 border-slate-400/30' ?> text-xs font-bold px-3 py-1 rounded-full border mb-3">
              <?php if ($myActiveTicket): ?><span class="live-pulse-dot"></span><?php endif; ?>
              <?= $myActiveTicket ? 'Status: ' : 'Recent Ticket: ' ?><?= $myActiveTicket ? htmlspecialchars($activeStatusLabel) : ucfirst(str_replace('_', ' ', $displayTicket['status'])) ?>
            </span>
            <h2 class="text-xs uppercase font-extrabold tracking-widest text-purple-300"><?= $myActiveTicket ? 'Your Live Queue Ticket' : 'Your Recent Ticket' ?></h2>
            <div class="customer-ticket-number text-5xl sm:text-6xl font-black text-white tracking-tight mt-1 mb-2">
              #<?= htmlspecialchars($displayTicket['ticket_number']) ?>
            </div>
            <p class="text-sm text-purple-200">
              Department: <strong><?= htmlspecialchars($displayTicket['dept_name']) ?></strong> | Service: <strong><?= htmlspecialchars($displayTicket['service_name']) ?></strong>
            </p>
          </div>

          <div class="customer-queue-position bg-white/10 backdrop-blur-md rounded-2xl p-5 border border-white/10 text-center min-w-[220px]">
            <?php if ($myActiveTicket): ?>
            <div class="text-xs font-bold text-purple-200 uppercase tracking-wider mb-1">Queue Position</div>
            <div class="text-3xl font-black text-white">#<?= $waitingAhead + 1 ?></div>
            <p class="text-xs text-purple-300 mt-1">Est. Wait: ~<?= (int)$myActiveTicket['estimated_time_mins'] ?> mins</p>

            <form method="post" class="mt-4 confirm-action" data-confirm-message="Cancel your queue ticket #<?= htmlspecialchars($myActiveTicket['ticket_number'], ENT_QUOTES) ?>?">
              <input type="hidden" name="action" value="cancel_queue">
              <input type="hidden" name="queue_id" value="<?= $myActiveTicket['id'] ?>">
              <button type="submit" class="w-full py-2 rounded-xl bg-red-500/20 hover:bg-red-500/30 text-red-200 font-bold text-xs border border-red-400/40 transition">
                Cancel Queue Ticket
              </button>
            </form>
            <?php else: ?>
            <div class="text-xs font-bold text-slate-300 uppercase tracking-wider mb-1">Ticket Status</div>
            <div class="text-2xl font-black text-white">Finished</div>
            <p class="text-xs text-slate-300 mt-1">This ticket is no longer active.</p>
            <?php endif; ?>
          </div>
        </div>
      </div>
      <?php endif; ?>

      <?php if ($myActiveTicket || $myRecentTicket): ?>
      <?php $billTicket = $myActiveTicket ?: $myRecentTicket; $billAmount = (float)($billTicket['bill_amount'] ?? 0); ?>
      <?php $billIsCancelled = ($billTicket['status'] ?? '') === 'cancelled'; ?>
      <section class="queue-page mb-10" id="bill-section" data-page="bill">
        <div class="stat-card-luxury p-6 sm:p-8">
          <div class="flex flex-col items-start gap-4 sm:flex-row sm:justify-between">
            <div>
              <span class="text-xs font-extrabold uppercase tracking-widest text-purple-600">My Bill</span>
              <h2 class="mt-2 text-2xl font-black text-slate-900"><?= htmlspecialchars($billTicket['service_name']) ?></h2>
              <p class="mt-1 text-sm text-slate-500">Ticket #<?= htmlspecialchars($billTicket['ticket_number']) ?> · <?= htmlspecialchars($billTicket['dept_name']) ?></p>
            </div>
            <div class="text-left">
              <?php if ($billIsCancelled): ?>
                <p class="text-xs font-bold uppercase tracking-wider text-red-500">Status</p>
                <p class="mt-1 text-3xl font-black text-red-600">Canceled</p>
              <?php else: ?>
                <p class="text-xs font-bold uppercase tracking-wider text-slate-400">Amount due</p>
                <p class="mt-1 text-3xl font-black text-slate-900">&#8369;<?= number_format($billAmount, 2) ?></p>
              <?php endif; ?>
            </div>
          </div>
          <div class="mt-5 flex items-center gap-2 rounded-xl px-4 py-3 text-sm font-semibold <?= $billIsCancelled ? 'bg-red-50 text-red-700' : (($billTicket['payment_status'] ?? 'pending') === 'paid' ? 'bg-emerald-50 text-emerald-700' : 'bg-amber-50 text-amber-700') ?>">
            <i data-feather="<?= $billIsCancelled ? 'x-circle' : (($billTicket['payment_status'] ?? 'pending') === 'paid' ? 'check-circle' : 'info') ?>" class="h-4 w-4"></i>
            <?php if ($billIsCancelled): ?>
              This ticket was canceled. No payment is required.
            <?php elseif (($billTicket['payment_status'] ?? 'pending') === 'paid'): ?>
              Paid<?= !empty($billTicket['paid_at']) ? ' on ' . htmlspecialchars($billTicket['paid_at']) : '' ?>
            <?php else: ?>
              <?= $billAmount > 0 ? 'Please settle this amount at the service desk.' : 'The fee for this service has not been configured yet.' ?>
            <?php endif; ?>
          </div>
        </div>
      </section>
      <?php endif; ?>

      <!-- Available Services Catalog Section -->
      <div class="queue-page" id="services-section" data-page="services">
      <div class="mb-6">
        <h2 class="text-2xl font-black text-slate-900 tracking-tight">Available Queue Lines & Services</h2>
        <p class="text-xs text-slate-500 mt-1">Select any service line below to issue your ticket directly in the system.</p>
      </div>

      <?php if (empty($serviceList)): ?>
      <div class="py-16 text-center bg-white border border-[#ece9f4] rounded-2xl p-8">
        <div class="w-16 h-16 rounded-full bg-purple-50 text-purple-600 flex items-center justify-center mx-auto mb-4">
          <i data-feather="briefcase" class="w-7 h-7"></i>
        </div>
        <h3 class="font-extrabold text-lg text-slate-800">No active services available</h3>
        <p class="text-xs text-slate-400 max-w-sm mx-auto mt-1">Services configured by administrators will appear here.</p>
      </div>
      <?php else: ?>
      <?php foreach ($servicesByDepartment as $departmentGroup): ?>
      <section class="stat-card-luxury p-6 mb-10 last:mb-0 customer-department-group" data-department-search="<?= htmlspecialchars(strtolower($departmentGroup['name'])) ?>" aria-labelledby="department-<?= htmlspecialchars(md5($departmentGroup['name'])) ?>">
        <button type="button" class="customer-department-toggle flex w-full items-center gap-3 text-left" aria-expanded="false" aria-controls="department-services-<?= htmlspecialchars(md5($departmentGroup['name'])) ?>">
          <span class="w-2 h-8 rounded-full bg-purple-600" aria-hidden="true"></span>
          <div class="flex-1 min-w-0">
            <h3 id="department-<?= htmlspecialchars(md5($departmentGroup['name'])) ?>" class="text-xl font-black text-slate-900"><?= htmlspecialchars($departmentGroup['name']) ?></h3>
            <p class="text-xs text-slate-500"><?= count($departmentGroup['services']) ?> service<?= count($departmentGroup['services']) === 1 ? '' : 's' ?> available</p>
          </div>
          <span class="customer-department-chevron flex h-10 w-10 flex-shrink-0 items-center justify-center text-black" aria-hidden="true">
            <i class="fa-solid fa-chevron-down text-xl"></i>
          </span>
        </button>
      <div id="department-services-<?= htmlspecialchars(md5($departmentGroup['name'])) ?>" class="customer-department-services mt-4 divide-y divide-slate-100">
        <?php foreach ($departmentGroup['services'] as $s): ?>
        <?php $departmentStatus = $s['dept_status'] ?? 'active'; $departmentAvailable = $departmentStatus === 'active'; ?>
        <div class="flex flex-col sm:flex-row sm:items-center justify-between gap-4 py-4 first:pt-0 last:pb-0 customer-service-row" data-service-search="<?= htmlspecialchars(strtolower($s['service_name'] . ' ' . ($s['dept_name'] ?? ''))) ?>">
          <div>
            <h4 class="text-base font-black text-slate-900"><?= htmlspecialchars($s['service_name']) ?></h4>
            <?php if ((float)$s['service_fee'] > 0): ?>
            <div class="mt-2">
              <span class="service-price">Price: &#8369;<?= number_format((float)$s['service_fee'], 2) ?></span>
            </div>
            <?php endif; ?>
          </div>

          <div class="flex items-center justify-between sm:justify-end gap-5 flex-shrink-0">
            <span class="text-xs font-bold <?= $departmentAvailable ? 'text-emerald-600' : ($departmentStatus === 'maintenance' ? 'text-amber-600' : 'text-red-600') ?> flex items-center gap-1.5">
              <span class="w-2 h-2 rounded-full <?= $departmentAvailable ? 'bg-emerald-500' : ($departmentStatus === 'maintenance' ? 'bg-amber-500' : 'bg-red-500') ?>"></span> <?= $departmentAvailable ? 'Front Desk Open' : ucfirst($departmentStatus) ?>
            </span>

            <form method="post" class="join-queue-form">
              <input type="hidden" name="action" value="join_queue">
              <input type="hidden" name="service_id" value="<?= $s['id'] ?>">
              <button type="submit" class="queue-ticket-button<?= $myActiveTicket || !$departmentAvailable ? ' is-blocked' : '' ?> px-4 py-2 rounded-xl bg-purple-600 hover:bg-purple-700 text-white font-bold text-xs transition inline-flex items-center gap-1.5"<?= $myActiveTicket || !$departmentAvailable ? ' aria-disabled="true" disabled' : '' ?>>
                <i data-feather="plus-circle" class="w-3.5 h-3.5"></i>
                <span>Get Ticket</span>
              </button>
            </form>
          </div>
        </div>
        <?php endforeach; ?>
      </div>
      </section>
      <?php endforeach; ?>
      <p id="customerServiceNoResults" class="hidden py-8 text-center text-sm font-semibold text-slate-500">No matching services found.</p>
      <?php endif; ?>

      <div class="stat-card-luxury mt-6">
        <div class="flex items-start gap-4">
          <div class="w-10 h-10 rounded-2xl bg-amber-50 text-amber-600 flex items-center justify-center flex-shrink-0">
            <i class="fa-solid fa-pen-to-square" style="font-size: 18px;" aria-hidden="true"></i>
          </div>
          <div class="flex-1 min-w-0">
            <div class="flex flex-wrap items-center gap-3">
              <h3 class="text-lg font-black text-slate-900">Other Service</h3>
              <span class="px-3 py-1 rounded-lg bg-white text-slate-700 border border-slate-300 text-xs font-black">Price: --</span>
            </div>
            <p class="text-xs text-slate-500 mt-1 mb-4">Describe the service you need if it is not listed above.</p>
            <form method="post" class="join-queue-form flex min-w-0 flex-col gap-3 sm:flex-row">
              <input type="hidden" name="action" value="join_other_queue">
              <select name="other_department_id" required class="w-full min-w-0 rounded-xl border border-slate-200 px-4 py-2.5 text-sm outline-none focus:border-purple-500 focus:ring-2 focus:ring-purple-100 sm:w-auto">
                <option value="">Select department</option>
                <?php foreach ($deptList as $department): ?>
                <?php if (($department['status'] ?? 'active') === 'active'): ?>
                <option value="<?= (int)$department['id'] ?>"><?= htmlspecialchars($department['dept_name']) ?></option>
                <?php endif; ?>
                <?php endforeach; ?>
              </select>
              <input type="text" name="other_service_name" maxlength="120" required placeholder="Enter your service request" class="w-full min-w-0 flex-1 rounded-xl border border-slate-200 px-4 py-2.5 text-sm outline-none focus:border-purple-500 focus:ring-2 focus:ring-purple-100">
              <button type="submit" class="queue-ticket-button<?= $myActiveTicket ? ' is-blocked' : '' ?> w-full rounded-xl bg-purple-600 hover:bg-purple-700 px-4 py-2.5 text-xs font-bold text-white transition sm:w-auto"<?= $myActiveTicket ? ' aria-disabled="true"' : '' ?>>Get Ticket</button>
            </form>
          </div>
        </div>
      </div>
      <?php $bottomTicket = $myActiveTicket ?: $myRecentTicket; ?>
      <?php if ($bottomTicket): ?>
      <a href="#active-ticket-section" data-page-link="ticket" class="stat-card-luxury mt-6 flex items-center justify-between gap-4 no-underline">
        <div class="flex items-center gap-3">
          <div class="w-10 h-10 rounded-2xl bg-purple-50 text-purple-600 flex items-center justify-center">
            <i class="fa-solid fa-ticket" style="font-size: 18px;" aria-hidden="true"></i>
          </div>
          <div>
            <h3 class="text-base font-black text-slate-900">Recent Ticket</h3>
            <p class="text-xs text-slate-500 mt-1">Ticket #<?= htmlspecialchars($bottomTicket['ticket_number']) ?> is <?= htmlspecialchars(str_replace('_', ' ', $bottomTicket['status'])) ?>.</p>
          </div>
        </div>
        <span class="text-xs font-bold text-purple-600">View Ticket &rarr;</span>
      </a>
      <?php endif; ?>
      </div>

      <section class="queue-page" id="notifications" data-page="notifications">
        <div class="mb-8">
          <h1 class="text-3xl font-black text-slate-900 tracking-tight">Notifications</h1>
          <p class="text-sm text-slate-500 mt-1">View updates about your queue ticket.</p>
        </div>
        <?php if ($myActiveTicket): ?>
        <div class="bg-white border border-red-200 rounded-2xl p-6 shadow-sm flex items-start gap-4">
          <div class="w-10 h-10 rounded-full bg-red-50 text-red-600 flex items-center justify-center flex-shrink-0">
            <i class="fa-solid fa-bullhorn" aria-hidden="true"></i>
          </div>
          <div>
            <?php if (in_array($myActiveTicket['status'], ['called', 'now_serving'], true)): ?>
            <h2 id="notificationPageTitle" class="text-base font-black text-slate-900">Your ticket is now calling</h2>
            <p id="notificationPageMessage" class="text-sm text-slate-600 mt-1">Ticket #<?= htmlspecialchars($myActiveTicket['ticket_number']) ?> is now calling. Please proceed to the service desk.</p>
            <button id="stopCustomerRinging" type="button" class="mt-4 rounded-xl bg-red-600 px-4 py-2 text-xs font-bold text-white hover:bg-red-700">Stop Ringing</button>
            <?php else: ?>
            <h2 id="notificationPageTitle" class="text-base font-black text-slate-900">Your queue ticket is active</h2>
            <p id="notificationPageMessage" class="text-sm text-slate-600 mt-1">Ticket #<?= htmlspecialchars($myActiveTicket['ticket_number']) ?> is <?= htmlspecialchars(str_replace('_', ' ', $myActiveTicket['status'])) ?>.</p>
            <?php endif; ?>
          </div>
        </div>
        <?php else: ?>
        <div class="bg-white border border-slate-200 rounded-2xl p-6 text-sm text-slate-600">You have no new notifications.</div>
        <?php endif; ?>
      </section>

      <section class="queue-page" id="faqs" data-page="faqs">
        <div class="mb-8">
          <h1 class="text-3xl font-black text-slate-900 tracking-tight">Frequently Asked Questions</h1>
          <p class="text-sm text-slate-500 mt-1">Helpful answers about using Queuezy.</p>
        </div>
        <div class="grid grid-cols-1 xl:grid-cols-2 gap-6">
          <div class="bg-white border border-slate-200 rounded-2xl p-6 shadow-sm">
            <h2 class="text-lg font-black text-slate-900 mb-4">Using Queuezy</h2>
            <div class="space-y-3">
              <details class="faq-item" open>
                <summary>What is Queuezy?</summary>
                <p>Queuezy is a digital queue management system that lets you join a service queue, receive a ticket, and follow your place without waiting in a physical line.</p>
              </details>
              <details class="faq-item">
                <summary>How do I get a queue ticket?</summary>
                <p>Open Services, choose a department and service, then select Get Ticket. Your active ticket appears in My Queue Ticket.</p>
              </details>
              <details class="faq-item">
                <summary>How will I know when it is my turn?</summary>
                <p>Open Notifications to see queue updates. When your ticket is called, proceed to the service window shown by the staff.</p>
              </details>
              <details class="faq-item">
                <summary>Can I join another queue while I have a ticket?</summary>
                <p>No. Finish or cancel your active ticket before joining another queue.</p>
              </details>
            </div>
          </div>
          <div class="bg-white border border-slate-200 rounded-2xl p-6 shadow-sm">
            <h2 class="text-lg font-black text-slate-900 mb-4">About The System</h2>
            <div class="space-y-3">
              <details class="faq-item" open>
                <summary>Who are the developers of Queuezy?</summary>
                <p>The developers of Queuezy are Christian Achera and James Cabandocos.</p>
              </details>
              <details class="faq-item">
                <summary>Who is part of the Queuezy project team?</summary>
                <p><strong>Project Manager:</strong> Jeliane Mae Galupar.<br><strong>Team Members:</strong> Christian Achera, James Cabandocos, Ron Cristofer Ravancho, and Jefferson Asistorga.</p>
              </details>
              <details class="faq-item">
                <summary>What should I do if I miss my call?</summary>
                <p>Check your ticket status and contact the service desk for assistance. Staff may mark an unattended ticket as no-show.</p>
              </details>
              <details class="faq-item">
                <summary>Why is my ticket not moving?</summary>
                <p>Queue progress depends on service availability and the number of customers ahead of you. Refresh the dashboard or check Notifications for updates.</p>
              </details>
              <details class="faq-item">
                <summary>How can I get help?</summary>
                <p>Visit the service desk shown for your department and provide your ticket number to a staff member.</p>
              </details>
            </div>
          </div>
        </div>
      </section>

      <section class="queue-page" id="settings-section" data-page="settings">
        <div class="mb-8">
          <h1 class="text-3xl font-black text-slate-900 tracking-tight">Settings</h1>
          <p class="text-sm text-slate-500 mt-1">Manage your account and dashboard preferences.</p>
        </div>
        <?php if ($msg): ?>
        <div class="mb-6 p-4 rounded-2xl border <?= $msgType === 'error' ? 'bg-red-50 border-red-200 text-red-700' : 'bg-emerald-50 border-emerald-200 text-emerald-700' ?> font-semibold text-xs">
          <?= htmlspecialchars($msg) ?>
        </div>
        <?php endif; ?>
        <div class="grid grid-cols-1 xl:grid-cols-2 gap-6">
          <form method="post" class="bg-white border border-slate-200 rounded-2xl p-6 confirm-action" data-confirm-message="Save your profile changes?">
            <input type="hidden" name="action" value="update_profile">
            <h2 class="text-lg font-black text-slate-900">Profile</h2>
            <label class="block mt-5 text-sm font-bold text-slate-700">Full name
              <input name="full_name" value="<?= htmlspecialchars($userName, ENT_QUOTES) ?>" required class="mt-2 w-full rounded-xl border border-slate-200 px-4 py-3 outline-none focus:border-purple-500">
            </label>
            <label class="block mt-4 text-sm font-bold text-slate-700">Email address
              <input type="email" name="email" value="<?= htmlspecialchars($userEmail, ENT_QUOTES) ?>" required class="mt-2 w-full rounded-xl border border-slate-200 px-4 py-3 outline-none focus:border-purple-500">
            </label>
            <button type="submit" class="mt-5 rounded-xl bg-purple-600 px-5 py-3 text-sm font-bold text-white hover:bg-purple-700">Save profile</button>
          </form>

          <form method="post" class="bg-white border border-slate-200 rounded-2xl p-6 confirm-action" data-confirm-message="Change your password?">
            <input type="hidden" name="action" value="change_password">
            <h2 class="text-lg font-black text-slate-900">Change password</h2>
            <label class="block mt-5 text-sm font-bold text-slate-700">Current password
              <input type="password" name="current_password" required minlength="8" maxlength="15" class="mt-2 w-full rounded-xl border border-slate-200 px-4 py-3 outline-none focus:border-purple-500">
            </label>
            <label class="block mt-4 text-sm font-bold text-slate-700">New password
              <input type="password" name="new_password" minlength="8" maxlength="15" required class="mt-2 w-full rounded-xl border border-slate-200 px-4 py-3 outline-none focus:border-purple-500">
            </label>
            <label class="block mt-4 text-sm font-bold text-slate-700">Confirm new password
              <input type="password" name="confirm_password" minlength="8" maxlength="15" required class="mt-2 w-full rounded-xl border border-slate-200 px-4 py-3 outline-none focus:border-purple-500">
            </label>
            <button type="submit" class="mt-5 rounded-xl bg-purple-600 px-5 py-3 text-sm font-bold text-white hover:bg-purple-700">Change password</button>
          </form>

          <div class="bg-white border border-slate-200 rounded-2xl p-6">
            <h2 class="text-lg font-black text-slate-900">Preferences</h2>
            <label class="mt-5 flex items-center justify-between gap-4 text-sm font-bold text-slate-700">Dark mode
              <input type="checkbox" id="darkModeToggle" class="h-5 w-5 accent-purple-600">
            </label>
            <label class="mt-5 flex items-center justify-between gap-4 text-sm font-bold text-slate-700">Email notifications
              <input type="checkbox" id="emailNotificationsToggle" checked class="h-5 w-5 accent-purple-600">
            </label>
            <label class="mt-5 flex items-center justify-between gap-4 text-sm font-bold text-slate-700">Queue reminders
              <input type="checkbox" id="queueRemindersToggle" checked class="h-5 w-5 accent-purple-600">
            </label>
          </div>
        </div>
      </section>

    </div>
  </main>
</div>

<div class="toast-container" id="toastBox"></div>
<div class="confirm-modal" id="confirmModal" role="dialog" aria-modal="true" aria-label="Confirmation message">
  <div class="confirm-dialog">
    <p id="confirmMessage"></p>
    <div class="confirm-actions">
      <button class="confirm-cancel" id="confirmCancel" type="button">Cancel</button>
      <button class="confirm-continue" id="confirmContinue" type="button">Continue</button>
    </div>
  </div>
</div>

<script>
  function showToast(msg) {
    const toastBox = document.getElementById('toastBox');
    const toast = document.createElement('div');
    toast.className = 'toast-item';
    toast.innerHTML = `<i data-feather="check-circle" style="width: 18px; height: 18px; color: #34d399;"></i><span>${msg}</span>`;
    toastBox.appendChild(toast);
    if (typeof feather !== 'undefined') feather.replace();
    setTimeout(() => { toast.remove(); }, 3500);
  }

  document.addEventListener('DOMContentLoaded', () => {
    if (typeof feather !== 'undefined') {
      feather.replace();
    }
    const queueMenu = document.getElementById('queueMenu');
    const queueSidebar = document.getElementById('queueSidebar');
    const queueSidebarBackdrop = document.getElementById('queueSidebarBackdrop');
    const queueSidebarToggle = document.getElementById('queueSidebarToggle');
    const queueLayout = document.querySelector('.queue-layout');
    const notificationButton = document.getElementById('notificationButton');
    const notificationSidebarLink = document.getElementById('notificationSidebarLink');
    const notificationPanel = document.getElementById('notificationPanel');
    const customerCallOverlay = document.getElementById('customerCallOverlay');
    const customerCallTitle = document.getElementById('customerCallTitle');
    const customerCallService = document.getElementById('customerCallService');
    const customerCallTicket = document.getElementById('customerCallTicket');
    const stopCustomerCallOverlay = document.getElementById('stopCustomerCallOverlay');
    const confirmModal = document.getElementById('confirmModal');
    const confirmMessage = document.getElementById('confirmMessage');
    const confirmCancel = document.getElementById('confirmCancel');
    const confirmContinue = document.getElementById('confirmContinue');
    let customerAudioContext = null;
    let pendingCustomerRing = null;
    let repeatedCustomerRingTimer = null;
    let activeCallingNotificationKey = '';
    let silencedCallingNotificationKey = localStorage.getItem('queuezy-silenced-notification') || '';
    let pendingConfirmation = null;
    let logoutConfirmationPending = false;
    const queueLinks = document.querySelectorAll('.queue-nav a');
    const queuePages = document.querySelectorAll('[data-page]');
    const markNotificationsViewed = () => {
      const notificationKey = notificationSidebarLink?.dataset.notificationKey;
      if (!notificationKey) return;
      localStorage.setItem('queuezy-viewed-notification', notificationKey);
      notificationSidebarLink.querySelector('.sidebar-notification-dot')?.remove();
      document.getElementById('topNotificationDot')?.remove();
    };
    const addNotificationDots = () => {
      if (notificationSidebarLink && !notificationSidebarLink.querySelector('.sidebar-notification-dot')) {
        const dot = document.createElement('b');
        dot.className = 'sidebar-notification-dot';
        dot.setAttribute('aria-label', 'Unread notification');
        notificationSidebarLink.appendChild(dot);
      }
      if (notificationButton && !document.getElementById('topNotificationDot')) {
        const dot = document.createElement('b');
        dot.id = 'topNotificationDot';
        dot.setAttribute('aria-label', 'Unread notification');
        notificationButton.appendChild(dot);
      }
    };
    const unlockCustomerAudio = async () => {
      if (!customerAudioContext) {
        const AudioContextClass = window.AudioContext || window.webkitAudioContext;
        if (!AudioContextClass) return;
        customerAudioContext = new AudioContextClass();
      }
      if (customerAudioContext.state === 'suspended') {
        try { await customerAudioContext.resume(); } catch (error) { return; }
      }
    };
    const ringCustomerPhone = async (ticket) => {
      await unlockCustomerAudio();
      if (!customerAudioContext || customerAudioContext.state !== 'running') {
        pendingCustomerRing = ticket;
        return;
      }
      pendingCustomerRing = null;
      if (customerAudioContext) {
        const start = customerAudioContext.currentTime;
        const gain = customerAudioContext.createGain();
        const firstTone = customerAudioContext.createOscillator();
        const secondTone = customerAudioContext.createOscillator();
        firstTone.type = 'sine';
        secondTone.type = 'sine';
        firstTone.frequency.setValueAtTime(659.25, start);
        secondTone.frequency.setValueAtTime(987.77, start + 0.18);
        gain.gain.setValueAtTime(0.001, start);
        gain.gain.exponentialRampToValueAtTime(0.28, start + 0.03);
        gain.gain.exponentialRampToValueAtTime(0.001, start + 0.5);
        firstTone.connect(gain);
        secondTone.connect(gain);
        gain.connect(customerAudioContext.destination);
        firstTone.start(start);
        firstTone.stop(start + 0.18);
        secondTone.start(start + 0.18);
        secondTone.stop(start + 0.5);
      }
      if (navigator.vibrate) navigator.vibrate([220, 100, 220]);
      if ('speechSynthesis' in window && ticket) {
        const spokenTicket = String(ticket).trim().replace(/-/g, ' ').replace(/\b0+(\d+)/g, '$1');
        const announcement = new SpeechSynthesisUtterance(`Ticket ${spokenTicket} is now calling. Please proceed to the service desk.`);
        announcement.rate = 0.9;
        announcement.pitch = 1;
        announcement.lang = 'en-US';
        window.speechSynthesis.cancel();
        window.speechSynthesis.speak(announcement);
      }
    };
    document.addEventListener('pointerdown', () => {
      unlockCustomerAudio();
      if ('Notification' in window && Notification.permission === 'default') {
        Notification.requestPermission().catch(() => {});
      }
      if (pendingCustomerRing) window.setTimeout(() => ringCustomerPhone(pendingCustomerRing), 50);
    });
    const notifyCustomerPhone = (ticket) => {
      if (!('Notification' in window) || Notification.permission !== 'granted') return;
      try {
        new Notification('Queuezy: your ticket is calling', {
          body: `Ticket #${ticket} is now calling. Please proceed to the service desk.`,
          tag: `queuezy-${ticket}`,
          renotify: true
        });
      } catch (error) {}
    };
    const stopRepeatedCustomerRing = () => {
      if (repeatedCustomerRingTimer) window.clearInterval(repeatedCustomerRingTimer);
      repeatedCustomerRingTimer = null;
      activeCallingNotificationKey = '';
    };
    const stopCustomerRinging = () => {
      silencedCallingNotificationKey = activeCallingNotificationKey || notificationSidebarLink?.dataset.notificationKey || '';
      if (silencedCallingNotificationKey) localStorage.setItem('queuezy-silenced-notification', silencedCallingNotificationKey);
      stopRepeatedCustomerRing();
      pendingCustomerRing = null;
      window.speechSynthesis?.cancel();
      if (navigator.vibrate) navigator.vibrate(0);
      const stopButton = document.getElementById('stopCustomerRinging');
      if (stopButton) {
        stopButton.textContent = 'Ringing Stopped';
        stopButton.disabled = true;
      }
      customerCallOverlay?.classList.remove('is-visible');
    };
    const startRepeatedCustomerRing = (ticket, notificationKey) => {
      if (activeCallingNotificationKey === notificationKey) return;
      if (silencedCallingNotificationKey === notificationKey) return;
      stopRepeatedCustomerRing();
      activeCallingNotificationKey = notificationKey;
      ringCustomerPhone(ticket);
      repeatedCustomerRingTimer = window.setInterval(() => {
        if (activeCallingNotificationKey === notificationKey) ringCustomerPhone(ticket);
      }, 10000);
    };
    const updateCustomerNotification = (ticket, status, department, service) => {
      if (!ticket || !status) return;
      const notificationKey = `${ticket}|${status}`;
      if (notificationSidebarLink) notificationSidebarLink.dataset.notificationKey = notificationKey;
      const isCalling = status === 'called' || status === 'now_serving';
      const title = isCalling ? 'Your ticket is now calling' : 'Your queue is active';
      const message = isCalling
        ? `Ticket #${ticket} is now calling. Please proceed to the service desk.`
        : `Ticket #${ticket} is ${status.replace('_', ' ')}.`;
      if (isCalling && silencedCallingNotificationKey !== notificationKey) {
        if (customerCallTitle) customerCallTitle.textContent = department || 'Front Desk';
        if (customerCallService) customerCallService.textContent = service || 'Please proceed to the front desk.';
        if (customerCallTicket) customerCallTicket.textContent = ticket;
        customerCallOverlay?.classList.add('is-visible');
      } else if (!isCalling) {
        customerCallOverlay?.classList.remove('is-visible');
      }
      document.querySelectorAll('#notificationTitle, #notificationPageTitle').forEach((element) => { element.textContent = title; });
      document.querySelectorAll('#notificationMessage, #notificationPageMessage').forEach((element) => { element.textContent = message; });
      if (isCalling) {
        const stopButton = document.getElementById('stopCustomerRinging');
        if (stopButton && silencedCallingNotificationKey !== notificationKey) {
          stopButton.textContent = 'Stop Ringing';
          stopButton.disabled = false;
        }
        if (silencedCallingNotificationKey !== notificationKey) localStorage.removeItem('queuezy-silenced-notification');
        if (silencedCallingNotificationKey !== notificationKey) silencedCallingNotificationKey = '';
        if (localStorage.getItem('queuezy-rung-notification') !== notificationKey) {
          localStorage.setItem('queuezy-rung-notification', notificationKey);
          notifyCustomerPhone(ticket);
        }
        startRepeatedCustomerRing(ticket, notificationKey);
      } else {
        stopRepeatedCustomerRing();
      }
      if (localStorage.getItem('queuezy-viewed-notification') !== notificationKey) addNotificationDots();
    };
    document.getElementById('stopCustomerRinging')?.addEventListener('click', stopCustomerRinging);
    const checkCustomerNotification = () => {
      fetch('api/customer_notification.php', { cache: 'no-store' })
        .then((response) => response.ok ? response.json() : null)
        .then((data) => {
          if (data?.ok) updateCustomerNotification(data.ticket, data.status, data.department, data.service);
        })
        .catch(() => {});
    };
    if (notificationSidebarLink?.dataset.notificationKey === localStorage.getItem('queuezy-viewed-notification')) {
      notificationSidebarLink.querySelector('.sidebar-notification-dot')?.remove();
      document.getElementById('topNotificationDot')?.remove();
    }
    checkCustomerNotification();
    window.setInterval(checkCustomerNotification, 5000);
    stopCustomerCallOverlay?.addEventListener('click', stopCustomerRinging);
    const closeQueueSidebar = () => {
      queueSidebar?.classList.remove('is-open');
      queueSidebarBackdrop?.classList.remove('is-visible');
    };
    const toggleQueueSidebar = () => {
      if (!queueSidebar) return;
      if (window.innerWidth <= 900) {
        queueSidebar.classList.remove('is-collapsed');
        const isOpen = queueSidebar.classList.toggle('is-open');
        queueSidebarBackdrop?.classList.toggle('is-visible', isOpen);
      } else {
        const collapsed = queueSidebar.classList.toggle('is-collapsed');
        queueLayout?.classList.toggle('sidebar-collapsed', collapsed);
      }
    };
    const toggleSidebarButton = (event) => {
      event?.preventDefault();
      if (window.innerWidth <= 900) {
        closeQueueSidebar();
      } else {
        const collapsed = queueSidebar.classList.toggle('is-collapsed');
        queueLayout?.classList.toggle('sidebar-collapsed', collapsed);
      }
    };
    if (queueSidebar) {
      queueMenu?.addEventListener('click', toggleQueueSidebar);
      queueSidebarToggle?.addEventListener('click', toggleSidebarButton);
      queueSidebarBackdrop?.addEventListener('click', closeQueueSidebar);
      queueSidebar.querySelectorAll('a').forEach((link) => link.addEventListener('click', closeQueueSidebar));
      document.addEventListener('keydown', (event) => {
        if (event.key === 'Escape') closeQueueSidebar();
      });
    }
    notificationButton?.addEventListener('click', (event) => {
      event.stopPropagation();
      markNotificationsViewed();
      const visible = notificationPanel?.classList.toggle('is-visible') === true;
      notificationButton.setAttribute('aria-expanded', String(visible));
    });
    notificationSidebarLink?.addEventListener('click', markNotificationsViewed);
    document.addEventListener('click', (event) => {
      if (notificationPanel?.classList.contains('is-visible') && !notificationPanel.contains(event.target) && event.target !== notificationButton) {
        notificationPanel.classList.remove('is-visible');
        notificationButton?.setAttribute('aria-expanded', 'false');
      }
    });
    document.addEventListener('keydown', (event) => {
      if (event.key === 'Escape') {
        notificationPanel?.classList.remove('is-visible');
        notificationButton?.setAttribute('aria-expanded', 'false');
        confirmModal?.classList.remove('is-visible');
        pendingConfirmation = null;
      }
    });
    const openConfirmation = (message, action) => {
      pendingConfirmation = action;
      if (confirmMessage) confirmMessage.textContent = message;
      confirmModal?.classList.add('is-visible');
      confirmCancel?.focus();
    };
    const closeConfirmation = () => {
      confirmModal?.classList.remove('is-visible');
      pendingConfirmation = null;
      logoutConfirmationPending = false;
      if (confirmContinue) confirmContinue.style.display = '';
      if (confirmContinue) confirmContinue.textContent = 'Continue';
      confirmContinue?.classList.remove('is-logging-out');
      if (confirmContinue) confirmContinue.disabled = false;
      if (confirmCancel) confirmCancel.textContent = 'Cancel';
      if (confirmCancel) confirmCancel.disabled = false;
    };
    confirmCancel?.addEventListener('click', closeConfirmation);
    confirmContinue?.addEventListener('click', () => {
      const action = pendingConfirmation;
      if (logoutConfirmationPending) {
        confirmContinue.classList.add('is-logging-out');
        confirmContinue.disabled = true;
        confirmContinue.textContent = 'Logging out...';
        confirmCancel.disabled = true;
        window.setTimeout(() => action?.(), 350);
        return;
      }
      closeConfirmation();
      action?.();
    });
    confirmModal?.addEventListener('click', (event) => {
      if (event.target === confirmModal) closeConfirmation();
    });
    const showBlockedMessage = () => {
      pendingConfirmation = null;
      if (confirmMessage) confirmMessage.textContent = 'Please wait for your ticket to be done or cancelled.';
      if (confirmContinue) confirmContinue.style.display = 'none';
      if (confirmCancel) confirmCancel.textContent = 'Close';
      confirmModal?.classList.add('is-visible');
      confirmCancel?.focus();
    };
    const customerServiceSearch = document.getElementById('customerServiceSearch');
    const customerServiceSearchButton = document.getElementById('customerServiceSearchButton');
    const customerDepartmentGroups = [...document.querySelectorAll('.customer-department-group')];
    const customerServiceNoResults = document.getElementById('customerServiceNoResults');
    const setDepartmentExpanded = (group, expanded) => {
      const toggle = group.querySelector('.customer-department-toggle');
      const services = group.querySelector('.customer-department-services');
      const chevron = group.querySelector('.customer-department-chevron');
      toggle?.setAttribute('aria-expanded', String(expanded));
      if (services) {
        services.classList.toggle('is-expanded', expanded);
        services.style.maxHeight = expanded ? `${services.scrollHeight}px` : '0px';
      }
    };
    customerDepartmentGroups.forEach((group) => {
      group.querySelector('.customer-department-toggle')?.addEventListener('click', () => {
        const toggle = group.querySelector('.customer-department-toggle');
        setDepartmentExpanded(group, toggle?.getAttribute('aria-expanded') !== 'true');
      });
    });
    const filterCustomerServices = () => {
      const query = customerServiceSearch?.value.trim().toLowerCase() || '';
      let visibleGroups = 0;

      customerDepartmentGroups.forEach((group) => {
        const departmentMatches = (group.dataset.departmentSearch || '').includes(query);
        const rows = [...group.querySelectorAll('.customer-service-row')];
        let visibleRows = 0;

        rows.forEach((row) => {
          const matches = departmentMatches || (row.dataset.serviceSearch || '').includes(query);
          row.classList.toggle('hidden', !matches);
          if (matches) visibleRows += 1;
        });

        group.classList.toggle('hidden', visibleRows === 0);
        if (query && visibleRows > 0) setDepartmentExpanded(group, true);
        if (visibleRows > 0) visibleGroups += 1;
      });

      customerServiceNoResults?.classList.toggle('hidden', visibleGroups !== 0 || !query);
    };
    customerServiceSearch?.addEventListener('input', filterCustomerServices);
    customerServiceSearchButton?.addEventListener('click', () => {
      customerServiceSearch?.focus();
      filterCustomerServices();
    });
    document.querySelectorAll('.join-queue-form').forEach((form) => {
      form.addEventListener('submit', (event) => {
        if (form.querySelector('[aria-disabled="true"]')) {
          event.preventDefault();
          showBlockedMessage();
          return;
        }
        const otherServiceInput = form.querySelector('[name="other_service_name"]');
        const serviceName = otherServiceInput?.value.trim() || form.closest('.stat-card-luxury')?.querySelector('h3')?.textContent.trim() || 'this service';
        if (!serviceName) {
          event.preventDefault();
          otherServiceInput?.focus();
          return;
        }
        event.preventDefault();
        openConfirmation(`Get a queue ticket for ${serviceName}?`, () => form.submit());
      });
    });
    document.querySelectorAll('.confirm-action').forEach((form) => {
      form.addEventListener('submit', (event) => {
        event.preventDefault();
        openConfirmation(form.dataset.confirmMessage || 'Are you sure you want to continue?', () => form.submit());
      });
    });
    document.querySelectorAll('.logout-link').forEach((link) => {
      link.addEventListener('click', (event) => {
        event.preventDefault();
        if (confirmContinue) confirmContinue.textContent = 'Log Out';
        openConfirmation('Are you sure you want to log out?', () => { window.location.href = link.href; });
        logoutConfirmationPending = true;
      });
    });
    const setActiveQueuePage = () => {
      const target = window.location.hash || '#home-section';
      const hasTicketPage = document.querySelector('[data-page="ticket"]');
      const page = target === '#services-section' ? 'services' : target === '#active-ticket-section' && hasTicketPage ? 'ticket' : target === '#bill-section' ? 'bill' : target === '#notifications' ? 'notifications' : target === '#settings-section' ? 'settings' : target === '#faqs' ? 'faqs' : 'home';
      queuePages.forEach((element) => {
        const active = element.dataset.page === page;
        element.classList.toggle('is-current', active);
        element.classList.toggle('is-hidden', !active);
      });
      queueLinks.forEach((link) => {
        const isHome = page === 'home' && link.getAttribute('href') === 'index.php';
        const isTarget = link.getAttribute('href') === target;
        link.classList.toggle('is-active', isHome || isTarget);
      });
    };
    queueLinks.forEach((link) => link.addEventListener('click', closeQueueSidebar));
    document.querySelectorAll('[data-page-link]').forEach((link) => link.addEventListener('click', closeQueueSidebar));
    window.addEventListener('hashchange', setActiveQueuePage);
    setActiveQueuePage();
    const preferenceToggles = {
      darkModeToggle: 'queuezy-dark-mode',
      emailNotificationsToggle: 'queuezy-email-notifications',
      queueRemindersToggle: 'queuezy-queue-reminders'
    };
    Object.entries(preferenceToggles).forEach(([id, storageKey]) => {
      const toggle = document.getElementById(id);
      if (!toggle) return;
      const stored = localStorage.getItem(storageKey);
      if (stored !== null) toggle.checked = stored === 'true';
      toggle.addEventListener('change', () => {
        localStorage.setItem(storageKey, String(toggle.checked));
        if (id === 'darkModeToggle') document.body.classList.toggle('dark-mode', toggle.checked);
      });
    });
    document.body.classList.toggle('dark-mode', document.getElementById('darkModeToggle')?.checked === true);
    document.querySelectorAll('[data-sidebar-toggle]').forEach((toggle) => {
      toggle.addEventListener('click', () => {
        const section = document.querySelector(`[data-sidebar-section="${toggle.dataset.sidebarToggle}"]`);
        const expanded = toggle.getAttribute('aria-expanded') === 'true';
        toggle.setAttribute('aria-expanded', String(!expanded));
        section?.classList.toggle('is-collapsed', expanded);
      });
    });
    const urlParams = new URLSearchParams(window.location.search);
    if (urlParams.has('joined')) {
      const ticket = urlParams.get('ticket') || '';
      showToast(`Successfully joined queue! Your ticket is #${ticket}`);
    }
    if (urlParams.get('cancelled') === '1') {
      showToast('Queue ticket cancelled.');
    } else if (urlParams.get('cancelled') === '0') {
      showToast('We could not cancel that queue ticket.');
    }
  });
</script>

</body>
</html>
