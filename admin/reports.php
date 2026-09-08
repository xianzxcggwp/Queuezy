<?php
require_once '../database/db.php';

$departmentLabels = [];
$departmentCounts = [];
if ($conn) {
    $departmentResult = $conn->query("SELECT d.dept_name, COUNT(q.id) AS ticket_count
        FROM departments d
        LEFT JOIN services s ON s.dept_id = d.id
        LEFT JOIN queue q ON q.service_id = s.id
        GROUP BY d.id, d.dept_name
        ORDER BY ticket_count DESC, d.dept_name ASC");
    if ($departmentResult) {
        while ($department = $departmentResult->fetch_assoc()) {
            $departmentLabels[] = $department['dept_name'];
            $departmentCounts[] = (int) $department['ticket_count'];
        }
    }
}

$pageTitle = 'Reports | Queuezy'; $pageHeading = 'Reports'; require_once '../includes/header.php'; ?>
<div class="queue-section-heading"><div><h2>Performance reports</h2><p>Understand queue volume and service performance over time.</p></div><div class="queue-monitor-row"><input class="queue-filter" type="date" value="2026-09-01" aria-label="Start date"><input class="queue-filter" type="date" value="2026-09-03" aria-label="End date"><button class="queue-button" type="button" onclick="window.print()"><i data-feather="download"></i> Export</button></div></div>
<div class="grid grid-cols-1 sm:grid-cols-2 xl:grid-cols-4 gap-5 mb-8">
    <div class="queue-stat"><div class="queue-stat__top"><span>Total Queues</span><span class="queue-stat__icon"><i data-feather="layers"></i></span></div><h2>128</h2><p>all queue tickets</p></div>
    <div class="queue-stat"><div class="queue-stat__top"><span>Completed</span><span class="queue-stat__icon"><i data-feather="check-circle"></i></span></div><h2>83</h2><p class="queue-positive">64.8% completion rate</p></div>
    <div class="queue-stat"><div class="queue-stat__top"><span>Cancelled</span><span class="queue-stat__icon"><i data-feather="x-circle"></i></span></div><h2>10</h2><p>7.8% of total queues</p></div>
    <div class="queue-stat"><div class="queue-stat__top"><span>No Show</span><span class="queue-stat__icon"><i data-feather="user-x"></i></span></div><h2>5</h2><p>3.9% of total queues</p></div>
</div>
<div class="queue-card p-6"><div class="queue-section-heading"><div><h2>Queues by department</h2><p>Ticket volume for the selected period</p></div><span class="queue-badge queue-badge--green">Updated just now</span></div><div class="queue-chart-wrap" style="height:330px"><canvas id="barChart"></canvas></div></div>
<script>
new Chart(document.getElementById('barChart'), { type:'bar', data:{labels:<?= json_encode($departmentLabels, JSON_HEX_TAG | JSON_HEX_APOS | JSON_HEX_AMP | JSON_HEX_QUOT) ?>,datasets:[{data:<?= json_encode($departmentCounts) ?>,backgroundColor:['#7C3AED','#8B5CF6','#A78BFA','#C4B5FD','#DDD6FE'],borderRadius:8,barThickness:42}]}, options:{responsive:true,maintainAspectRatio:false,plugins:{legend:{display:false}},scales:{x:{grid:{display:false},ticks:{color:'#cbd5e1'}},y:{beginAtZero:true,grid:{color:'#475569'},ticks:{stepSize:10,color:'#cbd5e1'},border:{display:false}}}}}); feather.replace(); document.getElementById('queueMenu').addEventListener('click',function(){document.getElementById('queueSidebar').classList.toggle('is-open')});
</script>
</div></main></div></body></html>
