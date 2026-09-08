<?php
require_once '../database/db.php';

$callingTicket = null;
$waitingCount = 0;
if ($conn) {
    $callingResult = $conn->query("SELECT q.ticket_number, d.dept_name FROM queue q LEFT JOIN services s ON q.service_id = s.id LEFT JOIN departments d ON s.dept_id = d.id WHERE q.status IN ('called', 'now_serving') ORDER BY q.id DESC LIMIT 1");
    if ($callingResult) {
        $callingTicket = $callingResult->fetch_assoc();
    }
    $waitingResult = $conn->query("SELECT COUNT(*) AS total FROM queue WHERE status = 'waiting'");
    if ($waitingResult) {
        $waitingCount = (int)($waitingResult->fetch_assoc()['total'] ?? 0);
    }
}

$pageTitle = 'Notifications | Queuezy Admin';
$pageHeading = 'Notifications';
require_once '../includes/header.php';
?>

<style>
    body.dark-mode .notification-card {
        background: #1e293b !important;
        border-color: #334155 !important;
    }
    body.dark-mode .notification-card .bg-red-50 {
        background: #451a1a !important;
        color: #fca5a5 !important;
    }
    body.dark-mode .notification-card .bg-blue-50 {
        background: #172554 !important;
        color: #93c5fd !important;
    }
    body.dark-mode .notification-card p.text-slate-500,
    body.dark-mode .notification-card p.text-slate-600 { color: #cbd5e1 !important; }
</style>

<div class="mb-8">
    <h1 class="text-3xl font-black text-slate-900 tracking-tight">Notifications</h1>
    <p class="text-sm text-slate-500 mt-1">Stay updated on the current queue.</p>
</div>

<div class="grid grid-cols-1 xl:grid-cols-2 gap-6">
    <section class="notification-card bg-white border border-red-200 rounded-2xl p-6 shadow-sm">
        <div class="flex items-start gap-4">
            <div class="flex h-12 w-12 flex-shrink-0 items-center justify-center rounded-2xl bg-red-50 text-red-600">
                <i class="fa-solid fa-bullhorn text-xl" aria-hidden="true"></i>
            </div>
            <div>
                <h2 class="text-lg font-black text-slate-900">Current call</h2>
                <?php if ($callingTicket): ?>
                    <p id="adminPageNotificationContent" class="mt-2 text-sm text-slate-600"><strong>Ticket #<?= htmlspecialchars($callingTicket['ticket_number']) ?> is now calling.</strong> Please serve the customer.</p>
                    <p class="mt-1 text-xs text-slate-500"><?= htmlspecialchars($callingTicket['dept_name'] ?? 'Queue desk') ?></p>
                <?php else: ?>
                    <p id="adminPageNotificationContent" class="mt-2 text-sm text-slate-600">No ticket is currently being called.</p>
                <?php endif; ?>
            </div>
        </div>
    </section>

    <section class="notification-card bg-white border border-slate-200 rounded-2xl p-6 shadow-sm">
        <div class="flex items-start gap-4">
            <div class="flex h-12 w-12 flex-shrink-0 items-center justify-center rounded-2xl bg-blue-50 text-blue-600">
                <i class="fa-solid fa-list-ol text-xl" aria-hidden="true"></i>
            </div>
            <div>
                <h2 class="text-lg font-black text-slate-900">Waiting queue</h2>
                <p class="mt-2 text-sm text-slate-600"><strong><?= $waitingCount ?> ticket<?= $waitingCount === 1 ? '' : 's' ?></strong> waiting to be served.</p>
                <a href="monitor.php" class="mt-3 inline-flex text-sm font-bold text-purple-600 hover:text-purple-700">Open queue monitor</a>
            </div>
        </div>
    </section>
</div>

<?php require_once '../includes/footer.php'; ?>
