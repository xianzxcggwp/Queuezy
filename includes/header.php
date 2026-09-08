<?php
if (session_status() === PHP_SESSION_NONE) {
    session_start();
}
if (!isset($_SESSION['user_id']) || !in_array($_SESSION['user_role'] ?? '', ['admin', 'staff'], true)) {
    header('Location: ../login.php');
    exit;
}
header('Cache-Control: no-store, no-cache, must-revalidate, max-age=0');
header('Pragma: no-cache');
header('Expires: 0');
$currentPage = basename($_SERVER['PHP_SELF']);
$adminNowServing = null;
$adminWaitingCount = 0;
if (isset($conn) && $conn) {
    $adminNowServingResult = $conn->query("SELECT ticket_number FROM queue WHERE status IN ('called', 'now_serving') ORDER BY id DESC LIMIT 1");
    if ($adminNowServingResult) {
        $adminNowServing = $adminNowServingResult->fetch_assoc()['ticket_number'] ?? null;
    }
    $adminWaitingResult = $conn->query("SELECT COUNT(*) AS total FROM queue WHERE status = 'waiting'");
    if ($adminWaitingResult) {
        $adminWaitingCount = (int)($adminWaitingResult->fetch_assoc()['total'] ?? 0);
    }
}
?>
<!DOCTYPE html>
<html lang="en">
<head>
    <meta charset="UTF-8">
    <meta name="viewport" content="width=device-width, initial-scale=1.0">
    <title>QUEUEZY</title>
    <link rel="icon" type="image/png" href="../assets/images/favicon.png">
    <link rel="preconnect" href="https://fonts.googleapis.com">
    <link rel="preconnect" href="https://fonts.gstatic.com" crossorigin>
    <link rel="stylesheet" href="https://cdnjs.cloudflare.com/ajax/libs/font-awesome/6.5.2/css/all.min.css">
    <link href="https://fonts.googleapis.com/css2?family=DM+Sans:wght@400;500;600;700&family=Inter:wght@400;500;600;700;800&display=swap" rel="stylesheet">
    <script src="https://cdn.tailwindcss.com"></script>
    <link rel="stylesheet" href="../assets/css/build.css">
    <link rel="stylesheet" href="../includes/style.css">
    <link rel="stylesheet" href="../assets/css/admin-queuezy.css">
    <style>
        .queue-layout { opacity: 1; transform: none; animation: none; }
        body.portal-leaving .queue-layout { opacity: 1; transform: none; transition: none; }
        .admin-notification-wrap { position: relative; }
        .admin-notification-panel { position: absolute; top: 52px; right: 0; z-index: 50; width: 280px; padding: 16px; background: #ffffff; border: 1px solid #e2e8f0; border-radius: 14px; box-shadow: 0 14px 30px rgba(15, 23, 42, 0.16); opacity: 0; visibility: hidden; pointer-events: none; transform: translateY(-6px); transition: opacity 180ms ease, transform 180ms ease, visibility 180ms ease; }
        .admin-notification-panel.is-visible { opacity: 1; visibility: visible; pointer-events: auto; transform: translateY(0); }
        .admin-notification-panel h3 { margin: 0; font-size: 14px; font-weight: 800; color: #0f172a; }
        .admin-notification-panel p { margin: 10px 0 0; font-size: 12px; line-height: 1.5; color: #64748b; }
        .admin-notification-panel strong { color: #0f172a; }
        .queue-content { animation: adminPageEnter 220ms ease both; }
        @keyframes adminPageEnter { from { opacity: 0; transform: translateY(8px); } to { opacity: 1; transform: translateY(0); } }
        body.dark-mode .admin-notification-panel { background: #1e293b; border-color: #475569; }
        body.dark-mode .admin-notification-panel h3,
        body.dark-mode .admin-notification-panel strong { color: #f8fafc; }
        @media (prefers-reduced-motion: reduce) { .admin-notification-panel { transition: none; } .queue-content { animation: none; } }
    </style>
    <script src="https://cdn.jsdelivr.net/npm/chart.js@4.4.4/dist/chart.umd.min.js"></script>
    <script src="../assets/js/plugins/feather.min.js"></script>
    <script src="../assets/js/password-toggle.js"></script>
    <script>
        (() => {
            document.documentElement.classList.add('dark-mode');
            localStorage.setItem('queuezy-dark-mode', 'true');
        })();
    </script>
</head>
<body class="queue-app dark-mode">
<div class="queue-layout">
    <aside class="queue-sidebar" id="queueSidebar">
        <div class="queue-brand-row">
            <div class="queue-brand" role="img" aria-label="Queuezy"><img class="queue-brand-logo queue-brand-logo--processing" id="queueBrandLogo" src="../assets/images/logo.jpg" alt="" width="64" height="64" style="width: 64px !important; height: 64px !important; max-width: 64px; max-height: 64px;"><span class="queue-brand-name">QUEUE<span>ZY</span></span></div>
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
        <div class="queue-sidebar__label">Workspace</div>
        <nav class="queue-nav" aria-label="Main navigation">
            <a class="<?= $currentPage === 'template01.php' ? 'is-active' : '' ?>" href="template01.php"><i data-feather="layers"></i><span>Departments</span></a>
            <a class="<?= $currentPage === 'template02.php' ? 'is-active' : '' ?>" href="template02.php"><i data-feather="briefcase"></i><span>Services</span></a>
            <?php 
              $queueBadge = 0;
              if (isset($conn) && $conn) {
                  $qbRes = $conn->query("SELECT COUNT(*) AS cnt FROM queue WHERE status IN ('waiting','now_serving')");
                  if ($qbRes && $qbRow = $qbRes->fetch_assoc()) { $queueBadge = (int)$qbRow['cnt']; }
              }
            ?>
            <a class="<?= $currentPage === 'monitor.php' ? 'is-active' : '' ?>" href="monitor.php"><i data-feather="list"></i><span>Queues</span><?php if ($queueBadge > 0): ?><em><?= $queueBadge ?></em><?php endif; ?></a>
            <a class="<?= $currentPage === 'template04.php' ? 'is-active' : '' ?>" href="template04.php"><i data-feather="users"></i><span>Users</span></a>
            <a class="<?= $currentPage === 'notifications.php' ? 'is-active' : '' ?>" href="notifications.php" id="adminSidebarNotificationLink" data-notification-key="<?= htmlspecialchars(($adminNowServing ?? '') . '|' . $adminWaitingCount, ENT_QUOTES) ?>"><i data-feather="bell"></i><span>Notifications</span><?php if ($adminNowServing || $adminWaitingCount > 0): ?><em>1</em><?php endif; ?></a>
        </nav>
        <div class="queue-sidebar__label queue-sidebar__label--lower">Manage</div>
        <nav class="queue-nav">
            <a class="<?= $currentPage === 'billing.php' ? 'is-active' : '' ?>" href="billing.php"><i data-feather="credit-card"></i><span>Billing</span></a>
            <a class="<?= $currentPage === 'reports.php' ? 'is-active' : '' ?>" href="reports.php"><i data-feather="bar-chart-2"></i><span>Reports</span></a>
            <a class="<?= $currentPage === 'profile.php' ? 'is-active' : '' ?>" href="profile.php"><i data-feather="settings"></i><span>Settings</span></a>
            <a href="../logout.php" class="logout-alert" onclick="return confirm('Are you sure you want to log out?');" style="color: #ef4444;"><i data-feather="log-out" style="color: #ef4444;"></i><span style="color: #ef4444; font-weight: 700;">Log Out</span></a>
        </nav>
        <div class="queue-sidebar__footer">
            <div class="profile-icon" aria-label="Profile"><i class="fa-solid fa-user"></i></div>
            <div style="flex:1;">
                <strong><?= htmlspecialchars($_SESSION['user_name'] ?? 'Admin') ?></strong>
                <small><?= htmlspecialchars(ucfirst($_SESSION['user_role'] ?? 'staff')) ?></small>
            </div>
        </div>
    </aside>
    <main class="queue-main">
        <header class="queue-topbar">
            <button class="queue-menu" id="queueMenu" type="button" aria-label="Open sidebar" onclick="toggleQueueSidebar(event)"><span aria-hidden="true">&gt;</span></button>
            <div class="queue-topbar__actions">
                <div class="admin-notification-wrap">
                    <button class="queue-icon-button" id="adminNotificationButton" type="button" aria-label="Notifications" aria-expanded="false"><i class="fa-solid fa-bell"></i><?php if ($adminNowServing || $adminWaitingCount > 0): ?><b id="adminTopNotificationDot"></b><?php endif; ?></button>
                    <div class="admin-notification-panel" id="adminNotificationPanel" role="status" aria-live="polite">
                        <h3>Notifications</h3>
                        <?php if ($adminNowServing): ?>
                            <p id="adminNotificationContent"><strong>Ticket #<?= htmlspecialchars($adminNowServing) ?> is now calling.</strong> Please serve the customer.</p>
                        <?php elseif ($adminWaitingCount > 0): ?>
                            <p id="adminNotificationContent"><strong><?= $adminWaitingCount ?> ticket<?= $adminWaitingCount === 1 ? '' : 's' ?> waiting.</strong> Open Queues to call the next customer.</p>
                        <?php else: ?>
                            <p id="adminNotificationContent">No new queue notifications.</p>
                        <?php endif; ?>
                    </div>
                </div>
                <div class="queue-user" role="group" aria-label="Current user">
                    <span class="profile-icon" aria-label="Profile"><i class="fa-solid fa-user"></i></span>
                    <span><strong><?= htmlspecialchars($_SESSION['user_name'] ?? 'Admin') ?></strong><small><?= htmlspecialchars(ucfirst($_SESSION['user_role'] ?? 'staff')) ?></small></span>
                </div>
            </div>
            <script>
                (() => {
                    const button = document.getElementById('adminNotificationButton');
                    const panel = document.getElementById('adminNotificationPanel');
                    const sidebarLink = document.getElementById('adminSidebarNotificationLink');
                    const notificationKey = sidebarLink?.dataset.notificationKey || '';
                    const clearNotificationBadge = () => {
                        if (!notificationKey) return;
                        localStorage.setItem('queuezy-admin-viewed-notification', notificationKey);
                        sidebarLink?.querySelector('em')?.remove();
                        document.getElementById('adminTopNotificationDot')?.remove();
                    };
                    if (notificationKey && notificationKey === localStorage.getItem('queuezy-admin-viewed-notification')) clearNotificationBadge();
                    button?.addEventListener('click', (event) => {
                        event.stopPropagation();
                        clearNotificationBadge();
                        const visible = panel?.classList.toggle('is-visible') === true;
                        button.setAttribute('aria-expanded', String(visible));
                    });
                    document.addEventListener('click', (event) => {
                        if (panel?.classList.contains('is-visible') && !panel.contains(event.target) && event.target !== button) {
                            panel.classList.remove('is-visible');
                            button?.setAttribute('aria-expanded', 'false');
                        }
                    });
                    sidebarLink?.addEventListener('click', clearNotificationBadge);
                    if (<?= $currentPage === 'notifications.php' ? 'true' : 'false' ?>) clearNotificationBadge();
                    const updateAdminNotifications = () => {
                        fetch('../api/admin_notification.php', { cache: 'no-store' })
                            .then((response) => response.ok ? response.json() : null)
                            .then((data) => {
                                if (!data?.ok) return;
                                const content = data.ticket
                                    ? `<strong>Ticket #${data.ticket} is now calling.</strong> Please serve the customer.`
                                    : data.waiting > 0
                                        ? `<strong>${data.waiting} ticket${data.waiting === 1 ? '' : 's'} waiting.</strong> Open Queues to call the next customer.`
                                        : 'No new queue notifications.';
                                document.querySelectorAll('#adminNotificationContent, #adminPageNotificationContent').forEach((element) => { element.innerHTML = content; });
                            })
                            .catch(() => {});
                    };
                    updateAdminNotifications();
                    window.setInterval(updateAdminNotifications, 5000);
                })();
            </script>
        </header>
        <div class="queue-content">