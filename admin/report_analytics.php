<?php
require_once "../config/database.php";
require_once "../includes/functions.php";
require_once "../includes/report_helpers.php";
require_role("admin");

$title = "Clinic Analytics Report";
include "../includes/header.php";
echo report_actions();

$totalPets=$conn->query("SELECT COUNT(*) c FROM pets WHERE verification_status='approved'")->fetch_assoc()['c'];
$totalClients=$conn->query("SELECT COUNT(*) c FROM users WHERE role='client'")->fetch_assoc()['c'];
$totalRecords=$conn->query("SELECT COUNT(*) c FROM medical_records")->fetch_assoc()['c'];
$totalAppointments=$conn->query("SELECT COUNT(*) c FROM appointments")->fetch_assoc()['c'];
$avgRating=$conn->query("SELECT ROUND(AVG(rating),1) c FROM feedback")->fetch_assoc()['c'] ?? 0;
?>
<div class="print-page">
<?= report_header("Clinic Analytics Report", "Management summary") ?>

<div class="print-section">
<h3>Key Metrics</h3>
<table class="print-table">
<tr><th>Total Clients</th><td><?= $totalClients ?></td><th>Total Pets</th><td><?= $totalPets ?></td></tr>
<tr><th>Medical Records</th><td><?= $totalRecords ?></td><th>Appointments</th><td><?= $totalAppointments ?></td></tr>
<tr><th>Average Feedback Rating</th><td><?= e($avgRating) ?>/5</td><th>Report Scope</th><td>Current clinic database</td></tr>
</table>
</div>

<div class="print-section">
<h3>Appointments by Status</h3><p>This section supports the dashboard pie chart.</p>
<table class="print-table">
<tr><th>Status</th><th>Total</th></tr>
<?php $rows=$conn->query("SELECT status,COUNT(*) c FROM appointments GROUP BY status"); while($r=$rows->fetch_assoc()): ?>
<tr><td><?= e($r['status']) ?></td><td><?= e($r['c']) ?></td></tr>
<?php endwhile; ?>
</table>
</div>

<div class="print-section">
<h3>Pets by Species</h3>
<table class="print-table">
<tr><th>Species</th><th>Total</th></tr>
<?php $rows=$conn->query("SELECT species,COUNT(*) c FROM pets WHERE verification_status='approved' GROUP BY species"); while($r=$rows->fetch_assoc()): ?>
<tr><td><?= e($r['species']) ?></td><td><?= e($r['c']) ?></td></tr>
<?php endwhile; ?>
</table>
</div>

<div class="print-section">
<h3>Common Diagnoses</h3>
<table class="print-table">
<tr><th>Diagnosis</th><th>Count</th></tr>
<?php $rows=$conn->query("SELECT diagnosis,COUNT(*) c FROM medical_records WHERE diagnosis IS NOT NULL AND TRIM(diagnosis)<>'' GROUP BY diagnosis ORDER BY c DESC,diagnosis ASC LIMIT 10"); while($r=$rows->fetch_assoc()): ?>
<tr><td><?= e($r['diagnosis']) ?></td><td><?= e($r['c']) ?></td></tr>
<?php endwhile; ?>
</table>
</div>

<div class="print-section">
<h3>Recent Activity</h3>
<table class="print-table">
<tr><th>Date</th><th>User</th><th>Action</th><th>Details</th></tr>
<?php $rows=$conn->query("SELECT l.*,u.full_name FROM audit_logs l LEFT JOIN users u ON l.actor_user_id=u.id ORDER BY l.created_at DESC LIMIT 15"); while($r=$rows->fetch_assoc()): ?>
<tr><td><?= e($r['created_at']) ?></td><td><?= e($r['full_name'] ?? 'System') ?></td><td><?= e($r['action']) ?></td><td><?= e($r['details']) ?></td></tr>
<?php endwhile; ?>
</table>
</div>
</div>
<?php include "../includes/footer.php"; ?>
