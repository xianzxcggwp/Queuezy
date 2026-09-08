<?php 
require_once '../database/db.php';

$msg = '';
$msgType = 'success';
$stationName = 'Window 01 - Main Service Desk';
$conn?->query("CREATE TABLE IF NOT EXISTS service_fees (
  id INT AUTO_INCREMENT PRIMARY KEY,
  service_id INT NOT NULL UNIQUE,
  amount DECIMAL(10, 2) NOT NULL DEFAULT 0.00,
  updated_at TIMESTAMP DEFAULT CURRENT_TIMESTAMP ON UPDATE CURRENT_TIMESTAMP,
  CONSTRAINT fk_service_fees_service FOREIGN KEY (service_id) REFERENCES services(id) ON DELETE CASCADE
) ENGINE=InnoDB");
$conn?->query("CREATE TABLE IF NOT EXISTS queue_payments (
  id INT AUTO_INCREMENT PRIMARY KEY,
  queue_id INT NOT NULL UNIQUE,
  amount DECIMAL(10, 2) NOT NULL DEFAULT 0.00,
  status ENUM('pending', 'paid') NOT NULL DEFAULT 'pending',
  paid_at DATETIME NULL,
  CONSTRAINT fk_queue_payments_queue FOREIGN KEY (queue_id) REFERENCES queue(id) ON DELETE CASCADE
) ENGINE=InnoDB");
$savedPreferences = json_decode($_COOKIE['queuezy_station_pref'] ?? '', true);
if (is_array($savedPreferences) && trim((string)($savedPreferences['station'] ?? '')) !== '') {
  $stationName = trim((string)$savedPreferences['station']);
}

// Handle Action (Calling ticket / updating status in MySQL)
if ($_SERVER['REQUEST_METHOD'] === 'POST' && isset($_POST['action'])) {
    if ($_POST['action'] === 'update_status' && $conn) {
        $queueId = (int)($_POST['queue_id'] ?? 0);
        $newStatus = trim($_POST['new_status'] ?? 'now_serving');

        if ($queueId > 0 && in_array($newStatus, ['waiting', 'called', 'now_serving', 'completed', 'cancelled', 'no_show'], true)) {
          $updateSql = in_array($newStatus, ['called', 'now_serving'], true)
            ? "UPDATE queue SET status = ?, started_at = NOW() WHERE id = ?"
            : "UPDATE queue SET status = ? WHERE id = ?";
          $stmt = $conn->prepare($updateSql);
            if ($stmt) {
                $stmt->bind_param("si", $newStatus, $queueId);
                if ($stmt->execute() && $newStatus === 'completed') {
                  $paymentStmt = $conn->prepare("INSERT INTO queue_payments (queue_id, amount, status, paid_at)
                    SELECT q.id, COALESCE(sf.amount, 0), 'paid', NOW()
                    FROM queue q LEFT JOIN service_fees sf ON sf.service_id = q.service_id WHERE q.id = ?
                    ON DUPLICATE KEY UPDATE amount = VALUES(amount), status = 'paid', paid_at = NOW()");
                  if ($paymentStmt) {
                    $paymentStmt->bind_param('i', $queueId);
                    $paymentStmt->execute();
                    $paymentStmt->close();
                  }
                }
                $stmt->close();
                header("Location: monitor.php?updated=1");
                exit;
            }
        }
    }

    if ($_POST['action'] === 'delete' && $conn) {
        $queueId = (int)($_POST['queue_id'] ?? 0);
        if ($queueId > 0) {
            $stmt = $conn->prepare("DELETE FROM queue WHERE id = ?");
            if ($stmt) {
                $stmt->bind_param("i", $queueId);
                $stmt->execute();
                $stmt->close();
                header("Location: monitor.php?deleted=1");
                exit;
            }
        }
    }
}

// Fetch ONLY Queue tickets that exist in MySQL
$queueList = [];
$nowServingTicket = null;
$waitingCount = 0;
$completedCount = 0;

if ($conn) {
    $res = $conn->query("SELECT q.*, s.service_name, s.estimated_time_mins, COALESCE(sf.amount, 0) AS service_fee, d.dept_name, d.dept_code, u.fullname AS user_fullname FROM queue q LEFT JOIN services s ON q.service_id = s.id LEFT JOIN service_fees sf ON sf.service_id = s.id LEFT JOIN departments d ON s.dept_id = d.id LEFT JOIN users u ON q.user_id = u.id ORDER BY q.id DESC");
    if ($res) {
        while ($row = $res->fetch_assoc()) {
            $queueList[] = $row;
            if ($row['status'] === 'now_serving' && !$nowServingTicket) {
                $nowServingTicket = $row;
            }
            if ($row['status'] === 'waiting') {
                $waitingCount++;
            }
            if ($row['status'] === 'completed') {
                $completedCount++;
            }
        }
    }
}

$pageTitle = 'Live Monitor | Queuezy Admin'; 
$pageHeading = 'Live Queue Monitor'; 
require_once '../includes/header.php'; 
?>

<!-- Embedded Custom CSS for Senior-Grade UI/UX & Micro-interactions -->
<style>
  .stat-card-luxury {
    position: relative;
    overflow: hidden;
    background: #ffffff;
    border: 1px solid #ece9f4;
    border-radius: 20px;
    padding: 24px;
    transition: all 0.28s cubic-bezier(0.4, 0, 0.2, 1);
    box-shadow: 0 4px 20px -2px rgba(15, 23, 42, 0.1);
  }
  .stat-card-luxury:hover {
    transform: translateY(-4px);
    box-shadow: 0 16px 36px -4px rgba(124, 58, 237, 0.12);
    border-color: #d8b4fe;
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
  .stat-card-luxury:hover::after { opacity: 1; }
  body.dark-mode .monitor-metrics .bg-purple-50 { background: #2e1b52 !important; }
  body.dark-mode .monitor-metrics .bg-indigo-50 { background: #1e1b4b !important; }
  body.dark-mode .monitor-metrics .bg-emerald-50 { background: #052e2b !important; }
  body.dark-mode .monitor-metrics .bg-amber-50 { background: #422006 !important; }

  .status-badge-lux {
    display: inline-flex;
    align-items: center;
    gap: 6px;
    font-size: 11px;
    font-weight: 800;
    padding: 4px 12px;
    border-radius: 9999px;
    text-transform: uppercase;
    letter-spacing: 0.5px;
  }
  .status-badge-lux.now_serving { background: #f3e8ff; color: #7c3aed; border: 1px solid #e9d5ff; }
  .status-badge-lux.waiting { background: #eff6ff; color: #2563eb; border: 1px solid #bfdbfe; }
  .status-badge-lux.completed { background: #ecfdf5; color: #059669; border: 1px solid #a7f3d0; }
  .status-badge-lux.cancelled { background: #fef2f2; color: #dc2626; border: 1px solid #fecaca; }
  body.dark-mode .status-badge-lux.now_serving { background: #2e1b52; color: #d8b4fe; border-color: #6d28d9; }
  body.dark-mode .status-badge-lux.waiting { background: #172554; color: #93c5fd; border-color: #1d4ed8; }
  body.dark-mode .status-badge-lux.completed { background: #052e2b; color: #6ee7b7; border-color: #047857; }
  body.dark-mode .status-badge-lux.cancelled { background: #451a1a; color: #fca5a5; border-color: #991b1b; }
  body.dark-mode .status-badge-lux.no_show { background: #334155; color: #cbd5e1; border-color: #64748b; }
  body.dark-mode .monitor-table .bg-red-50 { background: #451a1a !important; color: #fca5a5 !important; border-color: #991b1b !important; }
  body.dark-mode .monitor-table .hover\:bg-red-100:hover { background: #5b2020 !important; }

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

  .monitor-table-container {
    background: #ffffff;
    border: 1px solid #ece9f4;
    border-radius: 20px;
    box-shadow: 0 4px 20px -2px rgba(15, 23, 42, 0.1);
    overflow: hidden;
  }
  .monitor-table {
    width: 100%;
    min-width: 0;
    border-collapse: collapse;
    font-size: 11px;
    table-layout: fixed;
  }
  .monitor-table th {
    background: #faf9fd;
    padding: 11px 8px;
    font-size: 9px;
    font-weight: 800;
    text-transform: uppercase;
    letter-spacing: 0.6px;
    color: #64748b;
    border-bottom: 1px solid #ece9f4;
    text-align: left;
    white-space: nowrap;
  }
  .monitor-table td {
    padding: 12px 8px;
    border-bottom: 1px solid #f1f5f9;
    color: #334155;
    vertical-align: middle;
  }
  .monitor-table th:nth-child(1), .monitor-table td:nth-child(1) { width: 9%; }
  .monitor-table th:nth-child(2), .monitor-table td:nth-child(2) { width: 14%; }
  .monitor-table th:nth-child(3), .monitor-table td:nth-child(3) { width: 15%; }
  .monitor-table th:nth-child(4), .monitor-table td:nth-child(4) { width: 9%; white-space: nowrap; }
  .monitor-table th:nth-child(5), .monitor-table td:nth-child(5) { width: 13%; }
  .monitor-table th:nth-child(6), .monitor-table td:nth-child(6) { width: 10%; }
  .monitor-table th:nth-child(7), .monitor-table td:nth-child(7) { width: 12%; white-space: nowrap; }
  .monitor-table th:last-child, .monitor-table td:last-child { width: 18%; white-space: nowrap; }
  .monitor-table td:last-child .flex { gap: 4px; }
  .monitor-table td:last-child button { padding: 6px 7px; font-size: 10px; gap: 3px; }
  .monitor-table td:last-child button svg { width: 12px; height: 12px; }
  .monitor-table tr:last-child td { border-bottom: none; }
  .monitor-table tr:hover td { background: #fcfaff; }

  /* Toast Notification */
  .toast-container {
    position: fixed;
    bottom: 24px;
    right: 24px;
    z-index: 1000;
    display: flex;
    flex-direction: column;
    gap: 10px;
    pointer-events: none;
  }
  .toast-item {
    background: #1e1b4b;
    color: #ffffff;
    padding: 14px 20px;
    border-radius: 16px;
    font-size: 13px;
    font-weight: 600;
    display: flex;
    align-items: center;
    gap: 12px;
    box-shadow: 0 12px 30px rgba(0, 0, 0, 0.2);
    pointer-events: auto;
  }
</style>

<!-- Top Breadcrumbs & Action Bar -->
<div class="mb-8">
  <div class="flex flex-wrap items-center justify-between gap-4">
    <div>
      
      <h1 class="text-2xl sm:text-3xl font-black text-slate-900 tracking-tight flex items-center gap-3">
        <span>Queue Monitor</span>
        <span class="inline-flex items-center gap-2 bg-emerald-50 text-emerald-700 text-xs font-bold px-3 py-1 rounded-full border border-emerald-200">
    
    </div>

    <!-- Action Buttons -->
    <div class="flex items-center gap-3">
      <button type="button" onclick="playAnnounce('Sample Ticket')" class="px-4 py-2.5 rounded-xl border border-slate-200 bg-white hover:bg-slate-50 text-slate-700 font-bold text-sm shadow-sm transition inline-flex items-center gap-2">
        <i class="fa-solid fa-volume-high w-4 h-4 text-purple-600" aria-hidden="true"></i>
        <span>Audio Test</span>
      </button>

      <a href="call_next.php?ticket=<?= urlencode($nowServingTicket['ticket_number'] ?? '') ?>&dept=<?= urlencode($nowServingTicket['dept_name'] ?? '') ?>&service=<?= urlencode($nowServingTicket['service_name'] ?? '') ?>" class="px-5 py-2.5 rounded-xl bg-gradient-to-r from-purple-600 to-indigo-600 text-white font-bold text-sm shadow-md hover:from-purple-700 hover:to-indigo-700 transition inline-flex items-center gap-2">
        <i data-feather="mic" class="w-4 h-4"></i>
        <span>Open Call Desk</span>
      </a>
    </div>
  </div>
</div>

<!-- Executive Live Metric Cards -->
<div class="monitor-metrics grid grid-cols-1 sm:grid-cols-2 xl:grid-cols-4 gap-5 mb-8">
  <div class="stat-card-luxury">
    <div class="flex items-center justify-between">
      <span class="text-xs font-bold text-slate-400 uppercase tracking-wider">Now Serving</span>
      <div class="w-10 h-10 rounded-2xl bg-purple-50 text-purple-600 flex items-center justify-center">
        <i class="fa-solid fa-bullhorn" style="font-size: 20px;" aria-hidden="true"></i>
      </div>
    </div>
    <div class="mt-3">
      <h3 class="text-3xl font-black text-purple-600">
        <?= $nowServingTicket ? htmlspecialchars($nowServingTicket['ticket_number']) : 'None' ?>
      </h3>
      <p class="text-xs text-slate-500 mt-2">
        <?= $nowServingTicket ? htmlspecialchars($nowServingTicket['service_name'] ?? 'In Service') : 'No active caller' ?>
      </p>
    </div>
  </div>

  <div class="stat-card-luxury">
    <div class="flex items-center justify-between">
      <span class="text-xs font-bold text-slate-400 uppercase tracking-wider">Total Waiting</span>
      <div class="w-10 h-10 rounded-2xl bg-indigo-50 text-indigo-600 flex items-center justify-center">
        <i class="fa-solid fa-users" style="font-size: 20px;" aria-hidden="true"></i>
      </div>
    </div>
    <div class="mt-3">
      <h3 class="text-3xl font-black text-slate-900"><?= $waitingCount ?></h3>
      <p class="text-xs text-slate-500 mt-2 flex items-center gap-1">
        <span class="w-2 h-2 rounded-full bg-emerald-500"></span>
        <span>Waiting in line</span>
      </p>
    </div>
  </div>

  <div class="stat-card-luxury">
    <div class="flex items-center justify-between">
      <span class="text-xs font-bold text-slate-400 uppercase tracking-wider">Completed</span>
      <div class="w-10 h-10 rounded-2xl bg-emerald-50 text-emerald-600 flex items-center justify-center">
        <i class="fa-solid fa-circle-check" style="font-size: 20px;" aria-hidden="true"></i>
      </div>
    </div>
    <div class="mt-3">
      <h3 class="text-3xl font-black text-emerald-600"><?= $completedCount ?></h3>
      <p class="text-xs text-emerald-600 font-semibold mt-2">Resolved tickets</p>
    </div>
  </div>

  <div class="stat-card-luxury">
    <div class="flex items-center justify-between">
      <span class="text-xs font-bold text-slate-400 uppercase tracking-wider">Total Tickets</span>
      <div class="w-10 h-10 rounded-2xl bg-amber-50 text-amber-600 flex items-center justify-center">
        <i class="fa-solid fa-list" style="font-size: 20px;" aria-hidden="true"></i>
      </div>
    </div>
    <div class="mt-3">
      <h3 class="text-3xl font-black text-slate-900"><?= count($queueList) ?></h3>
      <p class="text-xs text-slate-500 mt-2">Total issued tickets</p>
    </div>
  </div>
</div>

<!-- Toolbar -->
<div class="bg-white p-4 sm:p-5 rounded-2xl border border-slate-200/80 shadow-sm mb-8 flex flex-col md:flex-row items-stretch md:items-center justify-between gap-4">
  <div class="relative flex-1 max-w-md md:ml-auto">
    <i data-feather="search" class="absolute left-3.5 top-1/2 -translate-y-1/2 w-4 h-4 text-slate-400"></i>
    <input type="text" id="queueSearchInput" onkeyup="filterQueueTable()" placeholder="Search ticket number, customer, or service..." class="w-full pl-10 pr-4 py-2.5 text-sm bg-slate-50 border border-slate-200 rounded-xl focus:outline-none focus:border-purple-500 focus:bg-white transition">
  </div>
</div>

<!-- Monitor Data Table -->
<?php if (empty($queueList)): ?>
<div class="py-16 text-center bg-white border border-[#ece9f4] rounded-2xl p-8">
  <div class="w-16 h-16 rounded-full bg-purple-50 text-purple-600 flex items-center justify-center mx-auto mb-4">
    <i data-feather="list" class="w-7 h-7"></i>
  </div>
  <h3 class="font-extrabold text-lg text-slate-800">No active queue tickets</h3>
  <p class="text-xs text-slate-400 max-w-sm mx-auto mt-1">Queue tickets issued via kiosk or app will appear here live.</p>
</div>
<?php else: ?>
<div class="monitor-table-container">
  <div class="overflow-x-auto">
    <table class="monitor-table" id="monitorTable">
      <thead>
        <tr>
          <th>Ticket #</th>
          <th>Department</th>
          <th>Service</th>
          <th>Price</th>
          <th>Customer</th>
          <th>Issued Time</th>
          <th>Status</th>
          <th style="text-align: right;">Action</th>
        </tr>
      </thead>
      <tbody>
        <?php foreach ($queueList as $q): ?>
        <tr class="queue-row">
          <td>
            <span class="font-black text-purple-700 text-base">#<?= htmlspecialchars($q['ticket_number']) ?></span>
          </td>
          <td>
            <div class="font-bold text-slate-900"><?= htmlspecialchars($q['dept_name'] ?? 'General') ?></div>
          </td>
          <td><?= htmlspecialchars($q['service_name'] ?? 'Service') ?></td>
          <td class="font-bold text-emerald-600">
            <?= (float)$q['service_fee'] > 0 ? '&#8369;' . number_format((float)$q['service_fee'], 2) : '--' ?>
          </td>
          <td>
            <div class="font-semibold text-slate-800"><?= htmlspecialchars($q['user_fullname'] ?? 'Customer') ?></div>
          </td>
          <td class="text-xs text-slate-500">
            <?= htmlspecialchars(date('M d, h:i A', strtotime($q['created_at']))) ?>
          </td>
          <td>
            <span class="status-badge-lux <?= $q['status'] ?>">
              <?= ucfirst(str_replace('_', ' ', $q['status'])) ?>
            </span>
          </td>
          <td style="text-align: right;">
            <div class="flex items-center justify-end gap-2">
              <?php if ($q['status'] === 'waiting'): ?>
              <form method="post" class="inline">
                <input type="hidden" name="action" value="update_status">
                <input type="hidden" name="queue_id" value="<?= $q['id'] ?>">
                <input type="hidden" name="new_status" value="now_serving">
                <button type="submit" onclick="playAnnounce('<?= htmlspecialchars($q['ticket_number']) ?>')" class="px-3 py-1.5 rounded-xl bg-purple-600 hover:bg-purple-700 text-white font-bold text-xs shadow-sm transition inline-flex items-center gap-1.5">
                  <i class="fa-solid fa-volume-high w-3.5 h-3.5" aria-hidden="true"></i>
                  <span>Call Ticket</span>
                </button>
              </form>
              <?php elseif ($q['status'] === 'now_serving'): ?>
              <form method="post" class="inline">
                <input type="hidden" name="action" value="update_status">
                <input type="hidden" name="queue_id" value="<?= $q['id'] ?>">
                <input type="hidden" name="new_status" value="completed">
                <button type="submit" class="px-3 py-1.5 rounded-xl bg-emerald-600 hover:bg-emerald-700 text-white font-bold text-xs shadow-sm transition inline-flex items-center gap-1.5">
                  <i data-feather="check" class="w-3.5 h-3.5"></i>
                  <span>Complete</span>
                </button>
              </form>
              <?php else: ?>
              <span class="text-xs text-slate-400 font-semibold mr-1">Processed</span>
              <?php endif; ?>

              <form method="post" class="inline" onsubmit="return confirm('Delete ticket #<?= htmlspecialchars($q['ticket_number'], ENT_QUOTES) ?>?');">
                <input type="hidden" name="action" value="delete">
                <input type="hidden" name="queue_id" value="<?= $q['id'] ?>">
                <button type="submit" class="px-3 py-1.5 rounded-xl border border-red-200 bg-red-50 hover:bg-red-100 text-red-600 font-bold text-xs transition inline-flex items-center gap-1.5 shadow-sm" title="Delete Ticket">
                  <i data-feather="trash-2" class="w-3.5 h-3.5"></i>
                  <span>DELETE</span>
                </button>
              </form>
            </div>
          </td>
        </tr>
        <?php endforeach; ?>
      </tbody>
    </table>
  </div>
</div>
<?php endif; ?>

<div class="toast-container" id="toastBox"></div>

<script>

  function filterQueueTable() {
    const q = document.getElementById('queueSearchInput').value.toLowerCase();
    const rows = document.querySelectorAll('#monitorTable tbody tr');
    rows.forEach(r => {
      const text = r.innerText.toLowerCase();
      r.style.display = text.includes(q) ? '' : 'none';
    });
  }

  function formatTicketForSpeech(rawTicket) {
    if (!rawTicket) return '45';
    let str = String(rawTicket).trim().replace(/-/g, ' ');
    return str.replace(/\b0+(\d+)/g, '$1');
  }

  function playAnnounce(ticket) {
    if ('speechSynthesis' in window) {
      window.speechSynthesis.cancel();
      const formatted = formatTicketForSpeech(ticket);
      const station = <?= json_encode($stationName, JSON_HEX_TAG | JSON_HEX_APOS | JSON_HEX_QUOT | JSON_HEX_AMP) ?>;
      const utterance = new SpeechSynthesisUtterance("Now calling ticket number " + formatted + ", please proceed to " + station + ".");
      utterance.rate = 0.9;
      utterance.pitch = 1.0;
      utterance.lang = 'en-US';
      window.speechSynthesis.speak(utterance);
    }
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
