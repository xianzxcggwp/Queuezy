<?php
require_once '../database/db.php';

if (session_status() === PHP_SESSION_NONE) {
    session_start();
}

// Ensure user is authenticated
if (!isset($_SESSION['user_id']) || !in_array($_SESSION['user_role'] ?? '', ['admin', 'staff'], true)) {
    header('Location: ../login.php');
    exit;
}

$userId = (int)$_SESSION['user_id'];
$msg = '';
$msgType = 'success';
$isAjaxRequest = ($_SERVER['HTTP_X_REQUESTED_WITH'] ?? '') === 'XMLHttpRequest';

// Handle POST actions
if ($_SERVER['REQUEST_METHOD'] === 'POST' && isset($_POST['action']) && $conn) {
    $action = $_POST['action'];

    // 1. UPDATE PERSONAL PROFILE
    if ($action === 'update_profile') {
        $fullname = trim($_POST['fullname'] ?? '');
        $email = trim($_POST['email'] ?? '');

        if ($fullname === '' || !filter_var($email, FILTER_VALIDATE_EMAIL)) {
            $msg = 'Please enter a valid full name and email address.';
            $msgType = 'error';
        } else {
            // Check if email already exists for another user
            $checkStmt = $conn->prepare("SELECT id FROM users WHERE email = ? AND id != ?");
            if ($checkStmt) {
                $checkStmt->bind_param("si", $email, $userId);
                $checkStmt->execute();
                $checkStmt->store_result();
                if ($checkStmt->num_rows > 0) {
                    $msg = 'This email address is already taken by another user.';
                    $msgType = 'error';
                } else {
                    $updateStmt = $conn->prepare("UPDATE users SET fullname = ?, email = ? WHERE id = ?");
                    if ($updateStmt) {
                        $updateStmt->bind_param("ssi", $fullname, $email, $userId);
                        if ($updateStmt->execute()) {
                            $_SESSION['user_name'] = $fullname;
                            $msg = 'Your profile information was updated successfully!';
                            $msgType = 'success';
                        } else {
                            $msg = 'Failed to update profile. Please try again.';
                            $msgType = 'error';
                        }
                        $updateStmt->close();
                    }
                }
                $checkStmt->close();
            }
        }

        if ($isAjaxRequest) {
          header('Content-Type: application/json; charset=utf-8');
          echo json_encode(['ok' => $msgType === 'success', 'message' => $msg]);
          exit;
        }
    }

    // 2. CHANGE PASSWORD
    if ($action === 'change_password') {
        $currentPass = $_POST['current_password'] ?? '';
        $newPass = $_POST['new_password'] ?? '';
        $confirmPass = $_POST['confirm_password'] ?? '';

        if (empty($currentPass) || empty($newPass) || empty($confirmPass)) {
            $msg = 'Please fill out all password fields.';
            $msgType = 'error';
        } elseif ($newPass !== $confirmPass) {
            $msg = 'New password and confirmation password do not match.';
            $msgType = 'error';
        } elseif (strlen($newPass) < 8 || strlen($newPass) > 15) {
          $msg = 'New password must be between 8 and 15 characters long.';
            $msgType = 'error';
        } else {
            // Verify current password from database
            $pwdStmt = $conn->prepare("SELECT password FROM users WHERE id = ?");
            if ($pwdStmt) {
                $pwdStmt->bind_param("i", $userId);
                $pwdStmt->execute();
                $pwdStmt->bind_result($hashedDbPass);
                if ($pwdStmt->fetch()) {
                    $pwdStmt->close();
                    if (password_verify($currentPass, $hashedDbPass)) {
                        $newHashed = password_hash($newPass, PASSWORD_DEFAULT);
                        $upPassStmt = $conn->prepare("UPDATE users SET password = ? WHERE id = ?");
                        if ($upPassStmt) {
                            $upPassStmt->bind_param("si", $newHashed, $userId);
                            if ($upPassStmt->execute()) {
                                $msg = 'Password changed successfully! Keep your new password safe.';
                                $msgType = 'success';
                            } else {
                                $msg = 'Error saving new password. Please try again.';
                                $msgType = 'error';
                            }
                            $upPassStmt->close();
                        }
                    } else {
                        $msg = 'The current password you entered is incorrect.';
                        $msgType = 'error';
                    }
                } else {
                    $pwdStmt->close();
                    $msg = 'User record not found.';
                    $msgType = 'error';
                }
            }
        }
    }

    // 3. UPDATE WORKSTATION PREFERENCES
    if ($action === 'save_preferences') {
        $stationName = trim($_POST['station_name'] ?? 'Counter 01');
        $ttsRate = floatval($_POST['tts_rate'] ?? 1.0);
        $ttsPitch = floatval($_POST['tts_pitch'] ?? 1.0);
        $chimeEnabled = isset($_POST['chime_enabled']) ? '1' : '0';

        $prefData = json_encode([
            'station' => $stationName,
            'tts_rate' => $ttsRate,
            'tts_pitch' => $ttsPitch,
            'chime' => $chimeEnabled
        ]);

        setcookie('queuezy_station_pref', $prefData, time() + (86400 * 30), "/");
        $msg = 'Workstation & audio preferences saved!';
        $msgType = 'success';

        if ($isAjaxRequest) {
          header('Content-Type: application/json; charset=utf-8');
          echo json_encode(['ok' => true, 'message' => $msg]);
          exit;
        }
    }
}

// Fetch current user details from MySQL
$currentUser = [
    'id' => $userId,
    'fullname' => $_SESSION['user_name'] ?? 'Admin',
    'email' => '',
    'role' => $_SESSION['user_role'] ?? 'staff',
    'created_at' => date('Y-m-d H:i:s')
];

if ($conn) {
    $stmt = $conn->prepare("SELECT id, fullname, email, role, created_at FROM users WHERE id = ?");
    if ($stmt) {
        $stmt->bind_param("i", $userId);
        $stmt->execute();
        $res = $stmt->get_result();
        if ($row = $res->fetch_assoc()) {
            $currentUser = $row;
        }
        $stmt->close();
    }
}

// Load saved cookie preferences if available
$savedPref = [
    'station' => 'Window 01 - Main Service Desk',
    'tts_rate' => 1.0,
    'tts_pitch' => 1.0,
    'chime' => '1'
];
if (isset($_COOKIE['queuezy_station_pref'])) {
    $decoded = json_decode($_COOKIE['queuezy_station_pref'], true);
    if (is_array($decoded)) {
        $savedPref = array_merge($savedPref, $decoded);
    }
}

$pageTitle = 'Profile & Settings | Queuezy';
$pageHeading = 'Account & Workstation Settings';
require_once '../includes/header.php';
?>

<style>
  /* Base Layout & Luxury Container Styles */
  .profile-container {
    max-width: 1200px;
    margin: 0 auto;
    font-family: 'DM Sans', 'Inter', -apple-system, sans-serif;
  }

  .prof-card {
    background: #ffffff;
    border: 1px solid #ece9f4;
    border-radius: 20px;
    padding: 28px;
    box-shadow: 0 4px 20px -2px rgba(57, 35, 97, 0.04);
    margin-bottom: 24px;
    position: relative;
    overflow: hidden;
  }

  .prof-alert {
    display: flex;
    align-items: center;
    gap: 12px;
    padding: 16px 20px;
    border-radius: 16px;
    font-size: 13px;
    font-weight: 600;
    margin-bottom: 24px;
  }
  .prof-alert.success {
    animation: profileSaveSuccess 420ms ease both;
  }
  @keyframes profileSaveSuccess {
    from { opacity: 0; transform: translateY(-8px); }
    to { opacity: 1; transform: translateY(0); }
  }
  .prof-alert.success {
    background: #ecfdf5;
    color: #065f46;
    border: 1px solid #a7f3d0;
  }
  .prof-alert.error {
    background: #fff1f2;
    color: #9f1239;
    border: 1px solid #fecdd3;
  }
  .profile-toast {
    position: fixed;
    right: 24px;
    bottom: 24px;
    z-index: 1100;
    display: flex;
    align-items: center;
    gap: 10px;
    max-width: min(360px, calc(100vw - 32px));
    padding: 14px 18px;
    border: 1px solid #6ee7b7;
    border-radius: 12px;
    background: #064e3b;
    color: #ecfdf5;
    box-shadow: 0 14px 30px rgba(2, 6, 23, 0.28);
    font-size: 13px;
    font-weight: 700;
    opacity: 0;
    transform: translateY(12px);
    pointer-events: none;
    transition: opacity 180ms ease, transform 180ms ease;
  }
  .profile-toast.is-visible {
    opacity: 1;
    transform: translateY(0);
  }
  .profile-toast.is-error {
    border-color: #fca5a5;
    background: #7f1d1d;
    color: #fef2f2;
  }

  /* Hero Header Card */
  .hero-profile-row {
    display: flex;
    align-items: center;
    justify-content: space-between;
    flex-wrap: wrap;
    gap: 20px;
  }
  .hero-user-info {
    display: flex;
    align-items: center;
    gap: 20px;
  }
  .hero-avatar {
    width: 76px;
    height: 76px;
    border-radius: 20px;
    background: linear-gradient(135deg, #7c3aed 0%, #4f46e5 100%);
    color: #ffffff;
    font-weight: 800;
    font-size: 28px;
    display: flex;
    align-items: center;
    justify-content: center;
    box-shadow: 0 10px 25px -5px rgba(124, 58, 237, 0.35);
    flex-shrink: 0;
  }
  .hero-avatar i { font-size: 30px; }
  .hero-meta-title {
    font-size: 22px;
    font-weight: 800;
    color: #0f172a;
    line-height: 1.2;
    margin-bottom: 4px;
    display: flex;
    align-items: center;
    gap: 10px;
  }
  .hero-role-badge {
    display: inline-flex;
    align-items: center;
    gap: 5px;
    font-size: 11px;
    font-weight: 800;
    padding: 4px 12px;
    border-radius: 9999px;
    text-transform: uppercase;
    letter-spacing: 0.5px;
  }
  .hero-role-badge.admin { background: #fdf2f8; color: #db2777; border: 1px solid #fbcfe8; }
  .hero-role-badge.staff { background: #f3e8ff; color: #7c3aed; border: 1px solid #e9d5ff; }

  .hero-email {
    font-size: 13px;
    color: #64748b;
    margin-bottom: 8px;
    font-family: monospace;
  }
  .hero-tags {
    display: flex;
    align-items: center;
    gap: 16px;
    font-size: 12px;
    color: #94a3b8;
  }
  .hero-tags span {
    display: inline-flex;
    align-items: center;
    gap: 6px;
  }

  .status-pill-pulse {
    display: inline-flex;
    align-items: center;
    gap: 8px;
    background: #ecfdf5;
    border: 1px solid #a7f3d0;
    color: #047857;
    font-size: 12px;
    font-weight: 700;
    padding: 8px 16px;
    border-radius: 9999px;
  }
  .status-pill-pulse .dot {
    width: 8px;
    height: 8px;
    border-radius: 50%;
    background: #10b981;
    box-shadow: 0 0 0 3px rgba(16, 185, 129, 0.2);
  }

  /* Navigation Tabs */
  .profile-tabs-nav {
    display: flex;
    align-items: center;
    gap: 8px;
    margin-bottom: 24px;
    border-bottom: 1px solid #e2e8f0;
    padding-bottom: 12px;
    overflow-x: auto;
  }
  .prof-tab-btn {
    padding: 10px 18px;
    border-radius: 12px;
    font-size: 13px;
    font-weight: 700;
    color: #64748b;
    background: transparent;
    border: 1px solid transparent;
    cursor: pointer;
    transition: all 0.2s ease;
    display: inline-flex;
    align-items: center;
    gap: 8px;
    white-space: nowrap;
  }
  .prof-tab-btn:hover {
    color: #7c3aed;
    background: #faf5ff;
  }
  .prof-tab-btn.active {
    color: #7c3aed;
    background: #f3e8ff;
    border-color: #e9d5ff;
  }

  /* Grid & Form Components */
  .settings-grid-layout {
    display: grid;
    grid-template-columns: 2fr 1fr;
    gap: 24px;
  }
  @media (max-width: 900px) {
    .settings-grid-layout {
      grid-template-columns: 1fr;
    }
  }

  .form-group {
    margin-bottom: 20px;
  }
  .form-label {
    display: block;
    font-size: 11px;
    font-weight: 800;
    text-transform: uppercase;
    letter-spacing: 0.5px;
    color: #475569;
    margin-bottom: 8px;
  }
  .form-control-input {
    width: 100%;
    box-sizing: border-box;
    border-radius: 12px;
    border: 1px solid #cbd5e1;
    padding: 12px 16px;
    font-size: 13px;
    color: #0f172a;
    outline: none;
    transition: all 0.2s ease;
    background: #ffffff;
  }
  .form-control-input:focus {
    border-color: #7c3aed;
    box-shadow: 0 0 0 3px rgba(124, 58, 237, 0.15);
  }
  .form-help-text {
    font-size: 11px;
    color: #94a3b8;
    margin-top: 6px;
    display: block;
  }

  .btn-submit-lux {
    background: #7c3aed;
    color: #ffffff;
    font-size: 13px;
    font-weight: 700;
    padding: 12px 24px;
    border-radius: 12px;
    border: none;
    cursor: pointer;
    transition: all 0.2s ease;
    box-shadow: 0 4px 14px rgba(124, 58, 237, 0.3);
  }
  .btn-submit-lux:hover {
    background: #6d28d9;
    transform: translateY(-1px);
    box-shadow: 0 6px 20px rgba(124, 58, 237, 0.4);
  }

  .btn-secondary-lux {
    background: #ffffff;
    color: #64748b;
    font-size: 13px;
    font-weight: 700;
    padding: 12px 20px;
    border-radius: 12px;
    border: 1px solid #cbd5e1;
    cursor: pointer;
    transition: all 0.2s ease;
    display: inline-flex;
    align-items: center;
    gap: 8px;
    text-decoration: none;
  }
  .btn-secondary-lux:hover {
    background: #f8fafc;
    color: #0f172a;
    border-color: #94a3b8;
  }

  /* Password Strength */
  .strength-bar-wrap {
    height: 6px;
    background: #e2e8f0;
    border-radius: 9999px;
    overflow: hidden;
    margin-top: 8px;
  }
  .strength-bar-inner {
    height: 100%;
    width: 0%;
    transition: all 0.3s ease;
  }

  /* Switch Toggle */
  .toggle-switch-wrap {
    display: flex;
    align-items: center;
    justify-content: space-between;
    background: #faf5ff;
    border: 1px solid #f3e8ff;
    padding: 16px 20px;
    border-radius: 16px;
  }
  .switch-toggle {
    position: relative;
    display: inline-block;
    width: 44px;
    height: 24px;
  }
  .switch-toggle input {
    opacity: 0;
    width: 0;
    height: 0;
  }
  .switch-slider {
    position: absolute;
    cursor: pointer;
    top: 0; left: 0; right: 0; bottom: 0;
    background-color: #cbd5e1;
    transition: .3s;
    border-radius: 24px;
  }
  .switch-slider:before {
    position: absolute;
    content: "";
    height: 18px;
    width: 18px;
    left: 3px;
    bottom: 3px;
    background-color: white;
    transition: .3s;
    border-radius: 50%;
  }
  .save-workstation-button {
    transition: opacity 180ms ease, transform 180ms ease;
  }
  .save-workstation-button.is-saving {
    opacity: 0.75;
    transform: translateY(1px);
    cursor: wait;
  }
  .save-workstation-button.is-saving::before {
    content: '';
    display: inline-block;
    width: 12px;
    height: 12px;
    margin-right: 8px;
    border: 2px solid rgba(255, 255, 255, 0.45);
    border-top-color: #ffffff;
    border-radius: 50%;
    vertical-align: -2px;
    animation: saveSpinner 650ms linear infinite;
  }
  @keyframes saveSpinner { to { transform: rotate(360deg); } }
  @media (prefers-reduced-motion: reduce) {
    .prof-alert.success,
    .save-workstation-button,
    .save-workstation-button.is-saving::before { animation: none; transition: none; }
  }
  input:checked + .switch-slider {
    background-color: #7c3aed;
  }
  input:checked + .switch-slider:before {
    transform: translateX(20px);
  }

  /* Information Cards */
  .side-info-card {
    background: #ffffff;
    border: 1px solid #ece9f4;
    border-radius: 20px;
    padding: 24px;
    margin-bottom: 20px;
  }
  .side-info-title {
    font-size: 14px;
    font-weight: 800;
    color: #0f172a;
    margin-bottom: 12px;
  }
  .side-info-list {
    list-style: none;
    padding: 0;
    margin: 0;
    font-size: 12px;
    color: #64748b;
    display: flex;
    flex-direction: column;
    gap: 12px;
  }
  .side-info-list li {
    display: flex;
    align-items: flex-start;
    gap: 8px;
    line-height: 1.4;
  }
  .side-info-list li svg {
    color: #10b981;
    flex-shrink: 0;
    margin-top: 1px;
  }

  .hidden-pane {
    display: none !important;
  }
  html.dark-mode .profile-container .prof-card,
  body.dark-mode .profile-container .prof-card,
  html.dark-mode .profile-container .side-info-card,
  body.dark-mode .profile-container .side-info-card { background: #1e293b; border-color: #334155; color: #f8fafc; }
  html.dark-mode .profile-container h3,
  html.dark-mode .profile-container h4,
  html.dark-mode .profile-container .hero-meta-title,
  body.dark-mode .profile-container h3,
  body.dark-mode .profile-container h4,
  body.dark-mode .profile-container .hero-meta-title { color: #f8fafc !important; }
  html.dark-mode .profile-container p,
  html.dark-mode .profile-container .hero-email,
  html.dark-mode .profile-container .hero-tags,
  html.dark-mode .profile-container .form-help-text,
  html.dark-mode .profile-container .side-info-list,
  body.dark-mode .profile-container p,
  body.dark-mode .profile-container .hero-email,
  body.dark-mode .profile-container .hero-tags,
  body.dark-mode .profile-container .form-help-text,
  body.dark-mode .profile-container .side-info-list { color: #cbd5e1 !important; }
  html.dark-mode .profile-container .prof-tab-btn,
  body.dark-mode .profile-container .prof-tab-btn { color: #cbd5e1; }
  html.dark-mode .profile-container .prof-tab-btn:hover,
  body.dark-mode .profile-container .prof-tab-btn:hover { background: #312e81; color: #ffffff; }
  html.dark-mode .profile-container .prof-tab-btn.active,
  body.dark-mode .profile-container .prof-tab-btn.active { background: #7c3aed; color: #ffffff; }
  html.dark-mode .profile-container .form-control-input,
  body.dark-mode .profile-container .form-control-input { background: #0f172a; border-color: #475569; color: #f8fafc; }
  html.dark-mode .profile-container [style*="color: #0f172a"],
  html.dark-mode .profile-container [style*="color: #334155"],
  html.dark-mode .profile-container [style*="color: #475569"],
  body.dark-mode .profile-container [style*="color: #0f172a"],
  body.dark-mode .profile-container [style*="color: #334155"],
  body.dark-mode .profile-container [style*="color: #475569"] { color: #f8fafc !important; }
  html.dark-mode .profile-container [style*="color: #64748b"],
  html.dark-mode .profile-container [style*="color: #94a3b8"],
  body.dark-mode .profile-container [style*="color: #64748b"],
  body.dark-mode .profile-container [style*="color: #94a3b8"] { color: #cbd5e1 !important; }
  html.dark-mode .profile-container [style*="background: #ffffff"],
  html.dark-mode .profile-container [style*="background: #f8fafc"],
  body.dark-mode .profile-container [style*="background: #ffffff"],
  body.dark-mode .profile-container [style*="background: #f8fafc"] { background: #1e293b !important; border-color: #334155 !important; }
  html.dark-mode .profile-container [style*="border-top: 1px solid #f1f5f9"],
  body.dark-mode .profile-container [style*="border-top: 1px solid #f1f5f9"] { border-color: #334155 !important; }
  body.dark-mode .profile-container .hero-role-badge.admin { background: #4a1735; color: #f9a8d4; border-color: #9d174d; }
  body.dark-mode .profile-container .hero-role-badge.staff { background: #2e1b52; color: #d8b4fe; border-color: #6d28d9; }
  body.dark-mode .profile-container .status-pill-pulse { background: #052e2b; color: #6ee7b7; border-color: #047857; }
  .profile-container .chime-title {
    color: #4c1d95 !important;
  }
  .profile-container .chime-description {
    color: #6b21a8 !important;
  }
  html.dark-mode .profile-container .chime-title,
  body.dark-mode .profile-container .chime-title { color: #f8fafc !important; }
  html.dark-mode .profile-container .chime-description,
  body.dark-mode .profile-container .chime-description { color: #c4b5fd !important; }
  html.dark-mode .profile-container .toggle-switch-wrap,
  body.dark-mode .profile-container .toggle-switch-wrap {
    background: #1e293b !important;
    border-color: #475569 !important;
  }
</style>

<div class="profile-container">

  <!-- Top Alert Feedback Banner -->
  <?php if (!empty($msg)): ?>
  <div class="prof-alert <?= $msgType === 'success' ? 'success' : 'error' ?>">
    <i data-feather="<?= $msgType === 'success' ? 'check-circle' : 'alert-circle' ?>" style="width: 20px; height: 20px;"></i>
    <span><?= htmlspecialchars($msg) ?></span>
  </div>
  <?php endif; ?>

  <!-- Hero Profile Header Card -->
  <div class="prof-card">
    <div class="hero-profile-row">
      <div class="hero-user-info">
        <div class="hero-avatar">
          <i class="fa-solid fa-user" aria-hidden="true"></i>
        </div>
        <div>
          <div class="hero-meta-title">
            <span><?= htmlspecialchars($currentUser['fullname']) ?></span>
            <span class="hero-role-badge <?= $currentUser['role'] === 'admin' ? 'admin' : 'staff' ?>">
              <i data-feather="<?= $currentUser['role'] === 'admin' ? 'shield' : 'award' ?>" style="width: 12px; height: 12px;"></i>
              <?= htmlspecialchars(strtoupper($currentUser['role'])) ?>
            </span>
          </div>
          <div class="hero-email"><?= htmlspecialchars($currentUser['email']) ?></div>
          <div class="hero-tags">
            <span><i data-feather="database" style="width: 13px; height: 13px; color: #7c3aed;"></i> User ID: #<?= $currentUser['id'] ?></span>
            <span><i data-feather="calendar" style="width: 13px; height: 13px; color: #7c3aed;"></i> Joined <?= date('F j, Y', strtotime($currentUser['created_at'])) ?></span>
          </div>
        </div>
      </div>

      <div>
        <div class="status-pill-pulse">
          <span class="dot"></span>
          <span>Active Workstation Session</span>
        </div>
      </div>
    </div>
  </div>

  <!-- Tabs Navigation -->
  <div class="profile-tabs-nav">
    <button type="button" class="prof-tab-btn active" onclick="switchProfileTab('tab-profile', this)">
      <i data-feather="user" style="width: 15px; height: 15px;"></i>
      <span>Personal Profile</span>
    </button>
    <button type="button" class="prof-tab-btn" onclick="switchProfileTab('tab-security', this)">
      <i data-feather="lock" style="width: 15px; height: 15px;"></i>
      <span>Password & Security</span>
    </button>
    <button type="button" class="prof-tab-btn" onclick="switchProfileTab('tab-station', this)">
      <i data-feather="volume-2" style="width: 15px; height: 15px;"></i>
      <span>Station & Announcer</span>
    </button>
    <button type="button" class="prof-tab-btn" onclick="switchProfileTab('tab-sessions', this)">
      <i data-feather="shield" style="width: 15px; height: 15px;"></i>
      <span>Account Overview</span>
    </button>
  </div>

  <!-- TAB 1: PERSONAL PROFILE -->
  <div id="tab-profile" class="tab-pane-container">
    <div class="settings-grid-layout">
      <div>
        <div class="prof-card">
          <h3 style="font-size: 16px; font-weight: 800; color: #0f172a; margin-bottom: 4px;">Personal Details</h3>
          <p style="font-size: 12px; color: #64748b; margin-bottom: 24px;">Manage your primary contact and identification in Queuezy.</p>

          <form method="post" id="personalProfileForm">
            <input type="hidden" name="action" value="update_profile">

            <div class="form-group">
              <label class="form-label">Full Name</label>
              <input type="text" name="fullname" required value="<?= htmlspecialchars($currentUser['fullname']) ?>" class="form-control-input">
              <span class="form-help-text">Displayed on queue tickets you serve and staff action logs.</span>
            </div>

            <div class="form-group">
              <label class="form-label">Email Address</label>
              <input type="email" name="email" required value="<?= htmlspecialchars($currentUser['email']) ?>" class="form-control-input">
              <span class="form-help-text">Used for logging into the admin portal and system alerts.</span>
            </div>

            <div class="form-group">
              <label class="form-label">Assigned System Role</label>
              <div style="display: flex; align-items: center; justify-content: space-between; padding: 14px 18px; background: #f8fafc; border-radius: 12px; border: 1px solid #e2e8f0; font-size: 12px;">
                <strong style="color: #334155; text-transform: uppercase;"><?= htmlspecialchars($currentUser['role']) ?> Level Access</strong>
                <span style="color: #94a3b8;">Contact SuperAdmin to alter privileges</span>
              </div>
            </div>

            <div style="padding-top: 16px; border-top: 1px solid #f1f5f9; display: flex; justify-content: flex-end;">
              <button type="submit" class="btn-submit-lux">Save Profile Changes</button>
            </div>
          </form>
        </div>
      </div>

      <div>
        <div class="side-info-card">
          <h4 class="side-info-title">Profile Tips</h4>
          <ul class="side-info-list">
            <li>
              <i data-feather="check" style="width: 16px; height: 16px;"></i>
              <span>Keep your email updated to receive critical password resets and ticket notifications.</span>
            </li>
            <li>
              <i data-feather="check" style="width: 16px; height: 16px;"></i>
              <span>Your name updates immediately in the top navigation bar and active session.</span>
            </li>
          </ul>
        </div>
      </div>
    </div>
  </div>

  <!-- TAB 2: SECURITY & PASSWORD -->
  <div id="tab-security" class="tab-pane-container hidden-pane">
    <div class="settings-grid-layout">
      <div>
        <div class="prof-card">
          <h3 style="font-size: 16px; font-weight: 800; color: #0f172a; margin-bottom: 4px;">Update Password</h3>
          <p style="font-size: 12px; color: #64748b; margin-bottom: 24px;">Ensure your Queuezy account is safeguarded with a robust password.</p>

          <form method="post" onsubmit="return validatePasswordChange();">
            <input type="hidden" name="action" value="change_password">

            <div class="form-group">
              <label class="form-label">Current Password</label>
              <input type="password" name="current_password" required minlength="8" maxlength="15" placeholder="Enter existing password" class="form-control-input">
            </div>

            <div class="form-group">
              <label class="form-label">New Password</label>
              <input type="password" name="new_password" id="newPassInput" required minlength="8" maxlength="15" placeholder="8 to 15 characters" class="form-control-input" oninput="checkStrength(this.value)">
              <div class="strength-bar-wrap">
                <div class="strength-bar-inner" id="strengthFill"></div>
              </div>
              <span id="strengthText" style="font-size: 11px; font-weight: 700; color: #94a3b8; display: block; margin-top: 6px;">Password Strength: Minimum 6 characters</span>
            </div>

            <div class="form-group">
              <label class="form-label">Confirm New Password</label>
              <input type="password" name="confirm_password" id="confirmPassInput" required minlength="8" maxlength="15" placeholder="Repeat new password" class="form-control-input" oninput="checkMatch()">
              <span id="matchText" style="font-size: 11px; font-weight: 700; margin-top: 6px; display: block;"></span>
            </div>

            <div style="padding-top: 16px; border-top: 1px solid #f1f5f9; display: flex; justify-content: flex-end;">
              <button type="submit" class="btn-submit-lux">Update Password</button>
            </div>
          </form>
        </div>
      </div>

      <div>
        <div class="side-info-card">
          <h4 class="side-info-title">Security Best Practices</h4>
          <ul class="side-info-list">
            <li>
              <i data-feather="shield" style="width: 16px; height: 16px; color: #7c3aed;"></i>
              <span>Use at least 8 characters with a mix of numbers and special symbols.</span>
            </li>
            <li>
              <i data-feather="lock" style="width: 16px; height: 16px; color: #7c3aed;"></i>
              <span>Do not reuse passwords across multiple systems.</span>
            </li>
          </ul>
        </div>
      </div>
    </div>
  </div>

  <!-- TAB 3: STATION & ANNOUNCER (VOICE TTS) -->
  <div id="tab-station" class="tab-pane-container hidden-pane">
    <div class="settings-grid-layout">
      <div>
        <div class="prof-card">
          <h3 style="font-size: 16px; font-weight: 800; color: #0f172a; margin-bottom: 4px;">Workstation & Voice Caller Setup</h3>
          <p style="font-size: 12px; color: #64748b; margin-bottom: 24px;">Configure which desk counter you are serving and calibrate the automated ticket voice announcer.</p>

          <form method="post" id="workstationPreferencesForm">
            <input type="hidden" name="action" value="save_preferences">

            <div class="form-group">
              <label class="form-label">Desk Counter / Window Identification</label>
              <input type="text" name="station_name" required value="<?= htmlspecialchars($savedPref['station']) ?>" placeholder="e.g. Window 01 - Registrar" class="form-control-input">
              <span class="form-help-text">This name will be announced when calling customers (e.g. "Ticket A-102 please proceed to Window 01").</span>
            </div>

            <div style="display: grid; grid-template-columns: 1fr 1fr; gap: 16px; margin-bottom: 20px;">
              <div>
                <label class="form-label">Announcer Speed: <span id="speedVal" style="color: #7c3aed;"><?= $savedPref['tts_rate'] ?>x</span></label>
                <input type="range" name="tts_rate" min="0.7" max="1.3" step="0.1" value="<?= $savedPref['tts_rate'] ?>" style="width: 100%; accent-color: #7c3aed;" oninput="document.getElementById('speedVal').innerText = this.value + 'x'">
              </div>
              <div>
                <label class="form-label">Voice Pitch: <span id="pitchVal" style="color: #7c3aed;"><?= $savedPref['tts_pitch'] ?></span></label>
                <input type="range" name="tts_pitch" min="0.7" max="1.3" step="0.1" value="<?= $savedPref['tts_pitch'] ?>" style="width: 100%; accent-color: #7c3aed;" oninput="document.getElementById('pitchVal').innerText = this.value">
              </div>
            </div>

            <div class="toggle-switch-wrap" style="margin-bottom: 20px;">
              <div>
                <strong class="chime-title" style="font-size: 13px; display: block; margin-bottom: 2px;">Audio Arrival Ding-Dong Chime</strong>
                <span class="chime-description" style="font-size: 11px;">Play an airport-style notification tone before calling tickets.</span>
              </div>
              <label class="switch-toggle">
                <input type="checkbox" name="chime_enabled" value="1" <?= $savedPref['chime'] === '1' ? 'checked' : '' ?>>
                <span class="switch-slider"></span>
              </label>
            </div>

            <div style="display: flex; align-items: center; justify-content: space-between; padding-top: 16px; border-top: 1px solid #f1f5f9;">
              <button type="button" onclick="testVoice()" class="btn-secondary-lux">
                <i data-feather="volume-2" style="width: 16px; height: 16px; color: #7c3aed;"></i>
                <span>Test Audio Announcement</span>
              </button>

              <button type="submit" class="btn-submit-lux save-workstation-button">Save Workstation Config</button>
            </div>
          </form>
        </div>
      </div>

      <div>
        <div class="side-info-card">
          <h4 class="side-info-title">Live Audio Engine</h4>
          <p style="font-size: 12px; color: #64748b; line-height: 1.5; margin-bottom: 12px;">The announcer utilizes the Web Speech Synthesis API and dual-frequency Web Audio oscillators.</p>
          <div style="padding: 12px; background: #faf5ff; border-radius: 12px; font-size: 11px; color: #7c3aed; font-family: monospace; border: 1px solid #f3e8ff;">
            Sample: "Now calling ticket number A-101, please proceed to <?= htmlspecialchars($savedPref['station']) ?>."
          </div>
        </div>
      </div>
    </div>
  </div>

  <!-- TAB 4: ACCOUNT OVERVIEW & SESSIONS -->
  <div id="tab-sessions" class="tab-pane-container hidden-pane">
    <div style="display: grid; grid-template-columns: 1fr 1fr; gap: 24px;">
      <div class="prof-card">
        <h3 style="font-size: 16px; font-weight: 800; color: #0f172a; margin-bottom: 16px;">Current Active Session</h3>
        <div style="font-size: 12px; display: flex; flex-direction: column; gap: 14px;">
          <div style="display: flex; justify-content: space-between; padding-bottom: 10px; border-bottom: 1px solid #f1f5f9;">
            <span style="color: #64748b; font-weight: 600;">User Role:</span>
            <span style="font-weight: 800; color: #7c3aed; text-transform: uppercase;"><?= htmlspecialchars($currentUser['role']) ?></span>
          </div>
          <div style="display: flex; justify-content: space-between; padding-bottom: 10px; border-bottom: 1px solid #f1f5f9;">
            <span style="color: #64748b; font-weight: 600;">Database User ID:</span>
            <span style="font-family: monospace; color: #0f172a;">#<?= $currentUser['id'] ?></span>
          </div>
          <div style="display: flex; justify-content: space-between; padding-bottom: 10px; border-bottom: 1px solid #f1f5f9;">
            <span style="color: #64748b; font-weight: 600;">Client IP Address:</span>
            <span style="font-family: monospace; color: #0f172a;"><?= htmlspecialchars($_SERVER['REMOTE_ADDR'] ?? '127.0.0.1') ?></span>
          </div>
          <div style="display: flex; justify-content: space-between; padding-bottom: 10px; border-bottom: 1px solid #f1f5f9;">
            <span style="color: #64748b; font-weight: 600;">Session Status:</span>
            <span style="color: #059669; font-weight: 800;">● Authenticated & Active</span>
          </div>
        </div>
      </div>

      <div class="prof-card">
        <h3 style="font-size: 16px; font-weight: 800; color: #0f172a; margin-bottom: 8px;">End Desk Shift</h3>
        <p style="font-size: 12px; color: #64748b; margin-bottom: 24px; line-height: 1.5;">Need to step away or end your shift? Terminating your session logs out your account safely and returns you to the login screen.</p>
        
        <a href="../logout.php" class="btn-secondary-lux logout-confirm" style="color: #e11d48; border-color: #fecdd3; background: #fff1f2;">
          <i data-feather="log-out" style="width: 16px; height: 16px;"></i>
          <span>Log Out of Queuezy</span>
        </a>
      </div>
    </div>
  </div>

</div>

<script>
  function switchProfileTab(tabId, el) {
    document.querySelectorAll('.tab-pane-container').forEach(tab => tab.classList.add('hidden-pane'));
    document.querySelectorAll('.prof-tab-btn').forEach(btn => btn.classList.remove('active'));
    
    const target = document.getElementById(tabId);
    if (target) {
      target.classList.remove('hidden-pane');
    }
    el.classList.add('active');
    feather.replace();
  }

  function checkStrength(val) {
    const fill = document.getElementById('strengthFill');
    const txt = document.getElementById('strengthText');
    if (!fill || !txt) return;

    if (val.length === 0) {
      fill.style.width = '0%';
      fill.style.backgroundColor = '#e2e8f0';
      txt.innerText = 'Password Strength: Minimum 6 characters';
      txt.style.color = '#94a3b8';
    } else if (val.length < 6) {
      fill.style.width = '30%';
      fill.style.backgroundColor = '#f43f5e';
      txt.innerText = 'Too Weak (must be at least 6 characters)';
      txt.style.color = '#f43f5e';
    } else if (val.length < 9) {
      fill.style.width = '65%';
      fill.style.backgroundColor = '#f59e0b';
      txt.innerText = 'Moderate / Good password';
      txt.style.color = '#f59e0b';
    } else {
      fill.style.width = '100%';
      fill.style.backgroundColor = '#10b981';
      txt.innerText = 'Strong & Secure password';
      txt.style.color = '#10b981';
    }
  }

  function checkMatch() {
    const newPass = document.getElementById('newPassInput').value;
    const confPass = document.getElementById('confirmPassInput').value;
    const matchText = document.getElementById('matchText');

    if (!confPass) {
      matchText.innerText = '';
      return;
    }

    if (newPass === confPass) {
      matchText.innerText = '✓ Passwords match';
      matchText.style.color = '#10b981';
    } else {
      matchText.innerText = '✕ Passwords do not match';
      matchText.style.color = '#f43f5e';
    }
  }

  function validatePasswordChange() {
    const newPass = document.getElementById('newPassInput').value;
    const confPass = document.getElementById('confirmPassInput').value;
    if (newPass !== confPass) {
      alert('The new password and confirmation password do not match.');
      return false;
    }
    return true;
  }

  function showWorkstationFeedback(message, type) {
    const profileContainer = document.querySelector('.profile-container');
    if (!profileContainer) return;
    let feedback = profileContainer.querySelector('.prof-alert');
    if (!feedback) {
      feedback = document.createElement('div');
      profileContainer.prepend(feedback);
    }
    feedback.className = 'prof-alert ' + type;
    feedback.innerHTML = '<i data-feather="' + (type === 'success' ? 'check-circle' : 'alert-circle') + '" style="width: 20px; height: 20px;"></i><span></span>';
    feedback.querySelector('span').textContent = message;
    if (typeof feather !== 'undefined') feather.replace();
  }

  function showProfileToast(message, type) {
    let toast = document.getElementById('profileToast');
    if (!toast) {
      toast = document.createElement('div');
      toast.id = 'profileToast';
      toast.className = 'profile-toast';
      document.body.appendChild(toast);
    }
    toast.classList.toggle('is-error', type === 'error');
    toast.textContent = message;
    toast.classList.add('is-visible');
    window.clearTimeout(toast.hideTimer);
    toast.hideTimer = window.setTimeout(() => toast.classList.remove('is-visible'), 3200);
  }

  document.getElementById('personalProfileForm')?.addEventListener('submit', function (event) {
    event.preventDefault();
    const form = this;
    const saveButton = form.querySelector('button[type="submit"]');
    saveButton.disabled = true;
    fetch(window.location.href, {
      method: 'POST',
      body: new FormData(form),
      headers: { 'X-Requested-With': 'XMLHttpRequest', 'Accept': 'application/json' }
    })
      .then((response) => response.ok ? response.json() : Promise.reject(new Error('Save failed')))
      .then((result) => {
        if (!result.ok) throw new Error(result.message || 'Save failed');
        showProfileToast(result.message, 'success');
        document.querySelectorAll('.queue-user strong, .queue-sidebar__footer strong').forEach((element) => { element.textContent = form.fullname.value; });
      })
      .catch((error) => showProfileToast(error.message || 'Unable to save profile. Please try again.', 'error'))
      .finally(() => { saveButton.disabled = false; });
  });

  document.getElementById('workstationPreferencesForm')?.addEventListener('submit', function (event) {
    event.preventDefault();
    const saveButton = this.querySelector('.save-workstation-button');
    if (!saveButton) return;
    saveButton.classList.add('is-saving');
    saveButton.disabled = true;
    saveButton.textContent = 'Saving...';

    const form = this;
    fetch(window.location.href, {
      method: 'POST',
      body: new FormData(form),
      headers: { 'X-Requested-With': 'XMLHttpRequest', 'Accept': 'application/json' }
    })
      .then((response) => response.ok ? response.json() : Promise.reject(new Error('Save failed')))
      .then((result) => {
        if (!result.ok) throw new Error(result.message || 'Save failed');
        saveButton.classList.remove('is-saving');
        saveButton.disabled = false;
        saveButton.textContent = 'Saved';
        showWorkstationFeedback(result.message, 'success');
        window.setTimeout(() => { saveButton.textContent = 'Save Workstation Config'; }, 1800);
      })
      .catch(() => {
        saveButton.classList.remove('is-saving');
        saveButton.disabled = false;
        saveButton.textContent = 'Save Workstation Config';
        showWorkstationFeedback('Unable to save preferences. Please try again.', 'error');
      });
  });

  // Dual Tone Chime & Voice Synthesis Test
  function testVoice() {
    if (!('speechSynthesis' in window)) {
      alert('Speech synthesis is not supported on this browser.');
      return;
    }

    try {
      const audioCtx = new (window.AudioContext || window.webkitAudioContext)();
      const osc1 = audioCtx.createOscillator();
      const osc2 = audioCtx.createOscillator();
      const gainNode = audioCtx.createGain();

      osc1.type = 'sine';
      osc2.type = 'sine';
      osc1.frequency.setValueAtTime(587.33, audioCtx.currentTime); // D5
      osc2.frequency.setValueAtTime(880.00, audioCtx.currentTime + 0.15); // A5

      gainNode.gain.setValueAtTime(0.2, audioCtx.currentTime);
      gainNode.gain.exponentialRampToValueAtTime(0.001, audioCtx.currentTime + 0.45);

      osc1.connect(gainNode);
      osc2.connect(gainNode);
      gainNode.connect(audioCtx.destination);

      osc1.start(audioCtx.currentTime);
      osc1.stop(audioCtx.currentTime + 0.15);
      osc2.start(audioCtx.currentTime + 0.15);
      osc2.stop(audioCtx.currentTime + 0.45);
    } catch(e) {}

    setTimeout(() => {
      const station = document.querySelector('input[name="station_name"]').value || 'Window 01';
      const rate = parseFloat(document.querySelector('input[name="tts_rate"]').value) || 1.0;
      const pitch = parseFloat(document.querySelector('input[name="tts_pitch"]').value) || 1.0;

      const utterance = new SpeechSynthesisUtterance(`Now calling ticket number A 101, please proceed to ${station}.`);
      utterance.rate = rate;
      utterance.pitch = pitch;
      window.speechSynthesis.speak(utterance);
    }, 450);
  }

  document.addEventListener('DOMContentLoaded', () => {
    if (typeof feather !== 'undefined') {
      feather.replace();
    }
    const queueMenu = document.getElementById('queueMenu');
    const queueSidebar = document.getElementById('queueSidebar');
    if (queueMenu && queueSidebar) {
      queueMenu.addEventListener('click', () => queueSidebar.classList.toggle('is-open'));
    }
  });
</script>

<?php require_once '../includes/footer.php'; ?>
</div></main></div></body></html>
