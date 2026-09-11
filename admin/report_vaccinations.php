<?php
require_once "../config/database.php";
require_once "../includes/functions.php";
require_once "../includes/report_helpers.php";
require_role("admin");

$days = (int)($_GET['days'] ?? 60);
$title = "Vaccination Due Report";
include "../includes/header.php";
echo report_actions();

$stmt = $conn->prepare("SELECT v.*,p.name pet_name,u.full_name,u.phone FROM vaccinations v JOIN pets p ON v.pet_id=p.id JOIN users u ON p.owner_id=u.id WHERE v.next_due_date IS NOT NULL AND v.next_due_date<=DATE_ADD(CURDATE(), INTERVAL ? DAY) ORDER BY v.next_due_date");
$stmt->bind_param("i",$days);
$stmt->execute();
$rows = $stmt->get_result();
?>
<div class="print-page">
<?= report_header("Vaccination Due Report", "Due within " . $days . " days") ?>
<div class="print-section">
<table class="print-table">
<tr><th>Due Date</th><th>Status</th><th>Pet</th><th>Owner</th><th>Phone</th><th>Vaccine</th><th>Date Given</th><th>Remarks</th></tr>
<?php while($r=$rows->fetch_assoc()): ?>
<tr>
<td><?= e($r['next_due_date']) ?></td>
<td><?= strtotime($r['next_due_date']) < strtotime(date('Y-m-d')) ? 'Overdue' : 'Due soon' ?></td>
<td><?= e($r['pet_name']) ?></td>
<td><?= e($r['full_name']) ?></td>
<td><?= e($r['phone']) ?></td>
<td><?= e($r['vaccine_name']) ?></td>
<td><?= e($r['date_given']) ?></td>
<td><?= e($r['remarks']) ?></td>
</tr>
<?php endwhile; ?>
</table>
</div>
</div>
<?php include "../includes/footer.php"; ?>
