<?php
require_once "../config/database.php";
require_once "../includes/functions.php";
require_once "../includes/report_helpers.php";
require_role("admin");

$token = trim($_GET['token'] ?? '');
$stmt=$conn->prepare("SELECT q.*,p.*,u.full_name,u.email,u.phone,u.address FROM qr_tokens q JOIN pets p ON q.pet_id=p.id JOIN users u ON p.owner_id=u.id WHERE q.token=? AND q.status='active' AND q.expires_at>NOW() AND p.verification_status='approved' LIMIT 1");
$stmt->bind_param("s",$token);
$stmt->execute();
$pet=$stmt->get_result()->fetch_assoc();
if(!$pet) die("Invalid QR token.");

$title = "Emergency QR Report";
include "../includes/header.php";
echo report_actions();
?>
<div class="print-page">
<?= report_header("Emergency Pet Profile", clean_report($pet['name'])) ?>

<div class="print-section">
    <h3>Emergency Contact</h3>
    <table class="print-table">
        <tr><th>Owner</th><td><?= e($pet['full_name']) ?></td></tr>
        <tr><th>Phone</th><td><?= e($pet['phone']) ?></td></tr>
        <tr><th>Email</th><td><?= e($pet['email']) ?></td></tr>
        <tr><th>Address</th><td><?= e($pet['address']) ?></td></tr>
    </table>
</div>

<div class="print-section">
    <h3>Pet Details</h3>
    <table class="print-table">
        <tr><th>Pet</th><td><?= e($pet['name']) ?></td><th>Species</th><td><?= e($pet['species']) ?></td></tr>
        <tr><th>Breed</th><td><?= e($pet['breed']) ?></td><th>Weight</th><td><?= e($pet['weight']) ?> kg</td></tr>
    </table>
</div>

<div class="print-section">
    <h3>Critical Information</h3>
    <div class="print-note">
        <b>Allergies:</b> <?= e($pet['allergies']) ?><br>
        <b>Critical Notes:</b> <?= e($pet['critical_notes']) ?><br>
        <b>Owner Notes:</b> <?= e($pet['notes']) ?>
    </div>
</div>

<div class="print-section">
<h3>Recent Medical History</h3>
<table class="print-table">
<tr><th>Date</th><th>Symptoms</th><th>Diagnosis</th><th>Treatment</th></tr>
<?php $pid=(int)$pet['pet_id']; $records=$conn->query("SELECT * FROM medical_records WHERE pet_id=$pid ORDER BY visit_date DESC LIMIT 5"); while($r=$records->fetch_assoc()): ?>
<tr><td><?= e($r['visit_date']) ?></td><td><?= e($r['symptoms']) ?></td><td><?= e($r['diagnosis']) ?></td><td><?= e($r['treatment']) ?></td></tr>
<?php endwhile; ?>
</table>
</div>
</div>
<?php include "../includes/footer.php"; ?>
