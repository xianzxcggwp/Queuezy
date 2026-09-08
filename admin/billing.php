<?php
require_once '../database/db.php';

$message = '';
$messageType = 'success';

if ($conn) {
    $conn->query("CREATE TABLE IF NOT EXISTS service_fees (
        id INT AUTO_INCREMENT PRIMARY KEY,
        service_id INT NOT NULL UNIQUE,
        amount DECIMAL(10, 2) NOT NULL DEFAULT 0.00,
        updated_at TIMESTAMP DEFAULT CURRENT_TIMESTAMP ON UPDATE CURRENT_TIMESTAMP,
        CONSTRAINT fk_service_fees_service FOREIGN KEY (service_id) REFERENCES services(id) ON DELETE CASCADE
    ) ENGINE=InnoDB");

    // Starter prices by department; saved custom fees are left unchanged.
    $conn->query("INSERT INTO service_fees (service_id, amount)
        SELECT s.id,
            CASE
                WHEN d.dept_name LIKE '%Medical Aesthetics%' THEN 2500.00
                WHEN d.dept_name LIKE '%Retail%' THEN 500.00
                WHEN d.dept_name LIKE '%Barbering%' THEN 350.00
                WHEN d.dept_name LIKE '%Nail%' THEN 500.00
                WHEN d.dept_name LIKE '%Skin Care%' THEN 1000.00
                WHEN d.dept_name LIKE '%Lash%' THEN 1200.00
                WHEN d.dept_name LIKE '%Body & Massage%' THEN 1200.00
                WHEN d.dept_name LIKE '%Hair Removal%' THEN 800.00
                WHEN d.dept_name LIKE '%Wellness%' THEN 1500.00
                WHEN d.dept_name LIKE '%Piercing%' THEN 800.00
                WHEN d.dept_name LIKE '%Makeup%' THEN 1800.00
                WHEN d.dept_name LIKE '%Hair Care%' THEN 700.00
                ELSE 500.00
            END
        FROM services s
        LEFT JOIN departments d ON d.id = s.dept_id
        LEFT JOIN service_fees sf ON sf.service_id = s.id
        WHERE sf.id IS NULL");
}

if ($_SERVER['REQUEST_METHOD'] === 'POST' && ($_POST['action'] ?? '') === 'save_fee' && $conn) {
    $serviceId = (int)($_POST['service_id'] ?? 0);
    $amount = filter_var(str_replace(',', '', trim((string)($_POST['amount'] ?? ''))), FILTER_VALIDATE_FLOAT);

    if ($serviceId > 0 && $amount !== false && $amount >= 0) {
        $stmt = $conn->prepare('INSERT INTO service_fees (service_id, amount) VALUES (?, ?) ON DUPLICATE KEY UPDATE amount = VALUES(amount)');
        if ($stmt) {
            $stmt->bind_param('id', $serviceId, $amount);
            if ($stmt->execute()) {
                header('Location: billing.php?saved=1');
                exit;
            }
            $stmt->close();
        }
    }

    $message = 'Enter a valid non-negative fee.';
    $messageType = 'error';
}

$serviceList = [];
if ($conn) {
    $result = $conn->query("SELECT s.id, s.service_name, d.dept_name, COALESCE(sf.amount, 0) AS amount,
        sf.updated_at FROM services s
        LEFT JOIN departments d ON d.id = s.dept_id
        LEFT JOIN service_fees sf ON sf.service_id = s.id
        ORDER BY d.dept_name, s.service_name");
    if ($result) {
        while ($row = $result->fetch_assoc()) {
            $serviceList[] = $row;
        }
    }
}

$configuredCount = count(array_filter($serviceList, static fn($service) => (float)$service['amount'] > 0));
$totalFees = array_sum(array_map(static fn($service) => (float)$service['amount'], $serviceList));
$pageTitle = 'Billing | Queuezy Admin';
$pageHeading = 'Billing';
require_once '../includes/header.php';
?>

<div class="queue-section-heading">
    <div>
        <h2>Service billing</h2>
        <p>Set the fee charged for each service offered by your departments.</p>
    </div>
</div>

<?php if (isset($_GET['saved'])): ?>
    <div class="queue-alert queue-alert--success mb-6">Billing amount saved successfully.</div>
<?php elseif ($message !== ''): ?>
    <div class="queue-alert queue-alert--error mb-6"><?= htmlspecialchars($message) ?></div>
<?php endif; ?>

<div class="grid grid-cols-1 sm:grid-cols-3 gap-5 mb-8">
    <div class="queue-stat"><div class="queue-stat__top"><span>Total services</span><span class="queue-stat__icon"><i data-feather="briefcase"></i></span></div><h2><?= count($serviceList) ?></h2><p>available for billing</p></div>
    <div class="queue-stat"><div class="queue-stat__top"><span>Configured fees</span><span class="queue-stat__icon"><i data-feather="check-circle"></i></span></div><h2><?= $configuredCount ?></h2><p>services with an amount</p></div>
    <div class="queue-stat"><div class="queue-stat__top"><span>Total listed fees</span><span class="queue-stat__icon"><i data-feather="dollar-sign"></i></span></div><h2>&#8369;<?= number_format($totalFees, 2) ?></h2><p>across all services</p></div>
</div>

<div class="queue-card p-6">
    <div class="queue-section-heading">
        <div><h2>Service fees</h2><p>Amounts are saved per service and can be changed at any time.</p></div>
        <label class="sr-only" for="billingSearch">Search services or departments</label>
        <div class="relative w-full sm:w-72 flex-shrink-0 ml-auto">
            <i data-feather="search" class="pointer-events-none absolute right-3 top-1/2 h-5 w-5 -translate-y-1/2 text-blue-600"></i>
            <input id="billingSearch" class="queue-filter w-full pr-10" type="search" placeholder="Search services or departments..." autocomplete="off">
        </div>
    </div>
    <?php if (!$serviceList): ?>
        <p class="text-slate-500">No services found. Add a service before setting its billing amount.</p>
    <?php else: ?>
        <div class="overflow-x-auto">
            <table class="w-full text-left">
                <thead><tr class="border-b border-slate-200 text-xs uppercase tracking-wider text-slate-400"><th class="px-3 py-3">Service</th><th class="px-3 py-3">Department</th><th class="px-3 py-3">Fee</th><th class="px-3 py-3 text-right">Action</th></tr></thead>
                <tbody>
                <?php foreach ($serviceList as $service): ?>
                    <tr class="billing-row border-b border-slate-100" data-search="<?= htmlspecialchars(strtolower($service['service_name'] . ' ' . ($service['dept_name'] ?? '')), ENT_QUOTES) ?>">
                        <td class="px-3 py-4 font-semibold text-slate-700"><?= htmlspecialchars($service['service_name']) ?></td>
                        <td class="px-3 py-4 text-slate-500"><?= htmlspecialchars($service['dept_name'] ?? 'Unassigned') ?></td>
                        <td class="px-3 py-4">
                            <form method="post" class="flex items-center justify-end gap-2">
                                <input type="hidden" name="action" value="save_fee">
                                <input type="hidden" name="service_id" value="<?= (int)$service['id'] ?>">
                                <label class="sr-only" for="amount-<?= (int)$service['id'] ?>">Fee for <?= htmlspecialchars($service['service_name']) ?></label>
                                <span class="text-slate-500">&#8369;</span><input id="amount-<?= (int)$service['id'] ?>" class="queue-filter w-32" type="text" name="amount" inputmode="decimal" data-money-input min="0" value="<?= htmlspecialchars(number_format((float)$service['amount'], 2, '.', ',')) ?>" required>
                                <button class="queue-button" type="submit"><i data-feather="save"></i> Save</button>
                            </form>
                        </td>
                        <td class="px-3 py-4 text-right text-xs text-slate-400"><?= $service['updated_at'] ? 'Updated ' . htmlspecialchars($service['updated_at']) : 'Not configured' ?></td>
                    </tr>
                <?php endforeach; ?>
                <tr id="billingNoResults" class="hidden">
                    <td colspan="4" class="px-3 py-8 text-center text-slate-500">No matching services found.</td>
                </tr>
                </tbody>
            </table>
        </div>
    <?php endif; ?>
</div>
<script>feather.replace();</script>
<script>
document.querySelectorAll('[data-money-input]').forEach((input) => {
    input.addEventListener('input', () => {
        const parts = input.value.replace(/[^0-9.]/g, '').split('.');
        const whole = (parts.shift() || '').replace(/^0+(?=\d)/, '');
        const decimals = parts.join('').slice(0, 2);
        input.value = whole.replace(/\B(?=(\d{3})+(?!\d))/g, ',') + (parts.length || input.value.includes('.') ? `.${decimals}` : '');
    });
});

const billingSearch = document.getElementById('billingSearch');
const billingRows = [...document.querySelectorAll('.billing-row')];
const billingNoResults = document.getElementById('billingNoResults');

billingSearch?.addEventListener('input', () => {
    const query = billingSearch.value.trim().toLowerCase();
    let visibleRows = 0;

    billingRows.forEach((row) => {
        const matches = !query || (row.dataset.search || '').includes(query);
        row.classList.toggle('hidden', !matches);
        if (matches) visibleRows += 1;
    });

    billingNoResults?.classList.toggle('hidden', visibleRows !== 0);
});
</script>
</div></main></div></body></html>