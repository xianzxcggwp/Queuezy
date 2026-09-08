<?php 
session_start();
require_once '../database/db.php';

if (!isset($_SESSION['user_id']) || !in_array($_SESSION['user_role'] ?? '', ['admin', 'staff'], true)) {
  header('Location: ../login.php');
  exit;
}

if (empty($_SESSION['csrf_token'])) {
  $_SESSION['csrf_token'] = bin2hex(random_bytes(32));
}
$csrfToken = $_SESSION['csrf_token'];

$stationName = 'Window 01 - Main Service Desk';
$savedPreferences = json_decode($_COOKIE['queuezy_station_pref'] ?? '', true);
if (is_array($savedPreferences) && trim((string)($savedPreferences['station'] ?? '')) !== '') {
  $stationName = trim((string)$savedPreferences['station']);
}

$pageTitle = 'Call Next | Queuezy'; 
$appType = 'admin'; 
$pageHeading = 'Call Next'; 

// Get the ticket info from URL params (passed from monitor.php) or query from DB
$ticketNumber = trim($_GET['ticket'] ?? '');
$deptName     = trim($_GET['dept'] ?? '');
$serviceName  = trim($_GET['service'] ?? '');

// Only allow state changes through POST; GET is used only to display a ticket.
if ($_SERVER['REQUEST_METHOD'] === 'POST' && in_array($_POST['action'] ?? '', ['call_ticket', 'skip_ticket'], true) && $conn) {
  if (!hash_equals($csrfToken, (string)($_POST['csrf_token'] ?? ''))) {
    http_response_code(403);
    exit('Invalid request token.');
  }
  $action = $_POST['action'] ?? '';
  $requestedNumber = trim($_POST['ticket_number'] ?? '');
  if ($requestedNumber === '' || strlen($requestedNumber) > 32 || !preg_match('/^[A-Za-z0-9-]+$/', $requestedNumber)) {
    http_response_code(400);
    exit('Invalid ticket number.');
  }
  if ($action === 'call_ticket') {
    $callStmt = $conn->prepare("UPDATE queue SET status = 'now_serving', started_at = NOW() WHERE ticket_number = ? AND status IN ('waiting', 'called', 'now_serving')");
    if (!$callStmt) {
      http_response_code(500);
      exit('Unable to call ticket.');
    }
    $callStmt->bind_param('s', $requestedNumber);
    $callStmt->execute();
    $callStmt->close();
    header('Content-Type: application/json; charset=utf-8');
    echo json_encode(['ok' => true, 'ticket' => $requestedNumber]);
    exit;
  }
  $skipNumber = trim($_POST['ticket_number'] ?? '');
  $skipStmt = $conn->prepare("UPDATE queue SET status = 'no_show' WHERE ticket_number = ? AND status IN ('called', 'now_serving')");
  if ($skipStmt) {
    $skipStmt->bind_param('s', $skipNumber);
    $skipStmt->execute();
    $skipStmt->close();
  }
  $ticketNumber = '';
  $deptName = '';
  $serviceName = '';
}

// If no ticket passed via URL, fetch the next waiting ticket from the database
if ($ticketNumber === '' && $conn) {
    $nextStmt = $conn->query("SELECT q.*, s.service_name, d.dept_name, d.dept_code 
        FROM queue q 
        LEFT JOIN services s ON q.service_id = s.id 
        LEFT JOIN departments d ON s.dept_id = d.id 
        WHERE q.status = 'waiting' 
        ORDER BY q.id ASC LIMIT 1");
    if ($nextStmt) {
        $nextRow = $nextStmt->fetch_assoc();
        if ($nextRow) {
            $ticketNumber = $nextRow['ticket_number'] ?? '';
            $deptName     = $nextRow['dept_name'] ?? '';
            $serviceName  = $nextRow['service_name'] ?? '';
        }
    }
}

// If we still have no ticket, try the now_serving one
if ($ticketNumber === '' && $conn) {
    $servStmt = $conn->query("SELECT q.*, s.service_name, d.dept_name 
        FROM queue q 
        LEFT JOIN services s ON q.service_id = s.id 
        LEFT JOIN departments d ON s.dept_id = d.id 
        WHERE q.status = 'now_serving' 
        ORDER BY q.id DESC LIMIT 1");
    if ($servStmt) {
        $servRow = $servStmt->fetch_assoc();
        if ($servRow) {
            $ticketNumber = $servRow['ticket_number'] ?? '';
            $deptName     = $servRow['dept_name'] ?? '';
            $serviceName  = $servRow['service_name'] ?? '';
        }
    }
}

$displayTicket  = $ticketNumber !== '' ? $ticketNumber : 'None';
$displayDept    = $deptName !== '' ? $deptName : 'No Department';
$displayService = $serviceName !== '' ? $serviceName : '';

require_once '../includes/header.php'; 
?>

<style>
  body:has(.call-next-screen) { overflow: hidden; }
  .call-next-screen { min-height: calc(100vh - 76px); }
  body:has(.call-next-screen) .queue-footer { display: none; }
  .call-next-screen .admin-top { margin-bottom: 12px !important; }
  .call-next-screen .queue-card { padding: 20px !important; }
  .call-next-screen .queue-card { position: relative; }
  .call-next-close {
    position: absolute;
    top: 14px;
    right: 14px;
    width: 32px;
    height: 32px;
    border: 1px solid #475569;
    border-radius: 50%;
    background: #1e293b;
    color: #cbd5e1;
    display: inline-flex;
    align-items: center;
    justify-content: center;
    text-decoration: none;
  }
  .call-next-close:hover { background: #334155; color: #ffffff; }
  .call-next-screen .queue-card .my-7 { margin-top: 18px !important; margin-bottom: 18px !important; padding-top: 28px !important; padding-bottom: 28px !important; }
</style>

<div class="admin-layout call-next-screen">
  <main class="admin-main admin-main-full">
    
    <!-- Top Header -->
    <header class="admin-top flex items-center justify-between mb-6">
      <div>
        <a href="monitor.php" class="font-bold text-purple-600 hover:underline flex items-center gap-1 text-sm">
          &larr; Back to monitor
        </a>
        <h1 class="font-extrabold text-2xl mt-1"><?php echo $pageHeading; ?></h1>
      </div>
    </header>

    <!-- Main Content Card -->
    <div class="admin-content">
      <div class="max-w-md mx-auto">
        <div class="queue-card card p-8 text-center bg-slate-800 rounded-2xl shadow-md border border-slate-700">
          <a href="monitor.php" class="call-next-close" aria-label="Close call desk" title="Close call desk">
            <i class="fa-solid fa-xmark" aria-hidden="true"></i>
          </a>
          <p class="text-xs font-semibold text-slate-300 tracking-wider uppercase mb-1">Next in line for</p>
          <h2 class="text-2xl font-black text-white"><?= htmlspecialchars($displayDept) ?></h2>
          <?php if ($displayService): ?>
            <p class="text-xs font-medium text-slate-300 mt-0.5"><?= htmlspecialchars($displayService) ?></p>
          <?php endif; ?>

          <!-- Ticket Box -->
          <div class="my-7 py-10 bg-slate-900 rounded-2xl border border-slate-600 relative">
            <p class="text-xs text-slate-400 uppercase tracking-widest mb-1">Ticket number</p>
            <strong id="widgetTicket" class="text-6xl sm:text-7xl font-black text-purple-300 tracking-tight block"><?= htmlspecialchars($displayTicket) ?></strong>
            
            <div class="mt-4 flex justify-center">
              <button 
                type="button" 
                onclick="playAnnouncement()" 
                data-admin-action="announce" 
                class="p-3 bg-slate-700 text-purple-300 rounded-full shadow-sm hover:bg-slate-600 transition" 
                aria-label="Announce ticket">
                <i class="fa-solid fa-volume-high" aria-hidden="true"></i>
              </button>
            </div>
          </div>

          <!-- Action Buttons -->
          <div class="space-y-3">
            <button 
              type="button" 
              onclick="announceAndCall()" 
              data-admin-action="next" 
              data-csrf-token="<?= htmlspecialchars($csrfToken, ENT_QUOTES) ?>"
              class="queue-button w-full bg-purple-600 hover:bg-purple-700 text-white rounded-xl py-4 font-bold transition shadow-sm">
              Call Next
            </button>
                    <form method="post" class="inline">
                      <input type="hidden" name="action" value="skip_ticket">
                      <input type="hidden" name="csrf_token" value="<?= htmlspecialchars($csrfToken, ENT_QUOTES) ?>">
                      <input type="hidden" name="ticket_number" value="<?= htmlspecialchars($displayTicket, ENT_QUOTES) ?>">
                      <button 
                      type="submit" 
              data-admin-action="skip" 
              class="queue-button queue-button--muted w-full border border-slate-200 text-slate-700 hover:bg-slate-50 rounded-xl py-3.5 font-semibold transition">
              Skip &amp; Next
            </button>
                    </form>
          </div>
        </div>
      </div>
    </div>

  </main>
</div>

<!-- Toast Container -->
<div id="toast" class="toast hide" role="status" aria-live="polite"></div>

<!-- JavaScript Scripts -->
<script>
  function formatTicketForSpeech(rawTicket) {
    if (!rawTicket || rawTicket === 'None') return '';
    // Split by dash: "R-028" => "R" and "028"
    const parts = String(rawTicket).trim().split('-');
    if (parts.length === 2) {
      const letter = parts[0]; // e.g. "R"
      const num = parseInt(parts[1], 10); // e.g. 28 (removes leading zeros)
      return letter + ' ' + num;
    }
    // Fallback: replace dashes with spaces, strip leading zeros
    let str = String(rawTicket).trim().replace(/-/g, ' ');
    return str.replace(/\b0+(\d+)/g, '$1');
  }

  function playAnnouncement() {
    if ('speechSynthesis' in window) {
      speechSynthesis.cancel();
      const currentTicket = document.getElementById('widgetTicket')?.innerText || '';
      if (!currentTicket || currentTicket === 'None') return;
      const formatted = formatTicketForSpeech(currentTicket);
      const station = <?= json_encode($stationName, JSON_HEX_TAG | JSON_HEX_APOS | JSON_HEX_QUOT | JSON_HEX_AMP) ?>;
      const text = 'Now calling ticket number ' + formatted + ', please proceed to ' + station + '.';
      const utterance = new SpeechSynthesisUtterance(text);
      utterance.rate = 0.9;
      utterance.pitch = 1.0;
      utterance.lang = 'en-US';
      speechSynthesis.speak(utterance);
    }
  }

  function announceAndCall() {
    const currentTicket = document.getElementById('widgetTicket')?.innerText || '';
    if (!currentTicket || currentTicket === 'None') return;
    const btn = document.querySelector('[data-admin-action="next"]');
    if (!btn || btn.disabled) return;
    btn.disabled = true;
    btn.textContent = 'Calling...';
    const formData = new FormData();
    formData.append('action', 'call_ticket');
    formData.append('ticket_number', currentTicket);
    formData.append('csrf_token', btn.dataset.csrfToken || '');
    fetch('call_next.php', { method: 'POST', body: formData, headers: { 'Accept': 'application/json' } })
      .then((response) => response.ok ? response.json() : Promise.reject(new Error('Call failed')))
      .then((result) => {
        if (!result.ok) throw new Error('Call failed');
        playAnnouncement();
        btn.textContent = 'Called ' + result.ticket;
      })
      .catch(() => {
        btn.disabled = false;
        btn.textContent = 'Call Next';
        const toast = document.getElementById('toast');
        if (toast) { toast.textContent = 'Unable to call this ticket.'; toast.classList.remove('hide'); }
      });
  }

  document.addEventListener('DOMContentLoaded', function() {
    if (typeof feather !== 'undefined') {
      feather.replace();
    }

    const queueMenu = document.getElementById('queueMenu');
    const queueSidebar = document.getElementById('queueSidebar');
    if (queueMenu && queueSidebar) {
      queueMenu.addEventListener('click', function() {
        queueSidebar.classList.toggle('is-open');
      });
    }
  });
</script>

<?php require_once '../includes/footer.php'; ?>