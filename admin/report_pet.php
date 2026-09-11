<?php
require_once "../config/database.php";
require_once "../includes/functions.php";
require_once "../includes/report_helpers.php";
require_role("admin");

$pet_id = (int)($_GET['pet_id'] ?? 0);
$stmt = $conn->prepare("SELECT p.*,u.full_name,u.email,u.phone,u.address FROM pets p JOIN users u ON p.owner_id=u.id WHERE p.id=? AND p.verification_status='approved'");
$stmt->bind_param("i",$pet_id);
$stmt->execute();
$pet = $stmt->get_result()->fetch_assoc();
if (!$pet) die("Pet not found.");

$title = "Pet Medical Report";
include "../includes/header.php";
echo report_actions();
?>
<div class="print-page">
<?= report_header("Pet Medical Report", clean_report($pet['name'])) ?>

<div class="print-section">
    <h3>Pet Information</h3>
    <table class="print-table">
        <tr><th>Name</th><td><?= e($pet['name']) ?></td><th>Species</th><td><?= e($pet['species']) ?></td></tr>
        <tr><th>Breed</th><td><?= e($pet['breed']) ?></td><th>Sex</th><td><?= e($pet['sex']) ?></td></tr>
        <tr><th>Age</th><td><?= e(pet_age($pet['birth_date'])) ?></td><th>Weight</th><td><?= e($pet['weight']) ?> kg</td></tr>
        <tr><th>Color</th><td><?= e($pet['color']) ?></td><th>Birth Date</th><td><?= e($pet['birth_date']) ?></td></tr>
    </table>
</div>

<div class="print-section">
    <h3>Owner Information</h3>
    <table class="print-table">
        <tr><th>Owner</th><td><?= e($pet['full_name']) ?></td></tr>
        <tr><th>Phone</th><td><?= e($pet['phone']) ?></td></tr>
        <tr><th>Email</th><td><?= e($pet['email']) ?></td></tr>
        <tr><th>Address</th><td><?= e($pet['address']) ?></td></tr>
    </table>
</div>

<div class="print-section">
    <h3>Emergency Notes</h3>
    <div class="print-note">
        <b>Allergies:</b> <?= e($pet['allergies']) ?><br>
        <b>Critical Notes:</b> <?= e($pet['critical_notes']) ?><br>
        <b>Owner Notes:</b> <?= e($pet['notes']) ?>
    </div>
</div>

<div class="print-section">
    <h3>Medical Records</h3>
    <table class="print-table">
        <tr><th>Date</th><th>Vet</th><th>Symptoms</th><th>Diagnosis</th><th>Treatment</th><th>Prescription</th><th class="no-print">PDF</th></tr>
        <?php $records=$conn->query("SELECT * FROM medical_records WHERE pet_id=$pet_id ORDER BY visit_date DESC"); while($r=$records->fetch_assoc()): ?>
        <tr>
            <td><?= e($r['visit_date']) ?></td>
            <td><?= e($r['veterinarian']) ?></td>
            <td><?= e($r['symptoms']) ?></td>
            <td><?= e($r['diagnosis']) ?></td>
            <td><?= e($r['treatment']) ?></td>
            <td><?= e($r['prescription']) ?></td><td class="no-print"><?php if(trim((string)($r['prescription']??''))!==''):?><a href="<?=app_url('vet/prescription_pdf.php?record_id='.$r['id'])?>">Download</a><?php endif;?></td>
        </tr>
        <?php endwhile; ?>
    </table>
</div>

<div class="print-section">
    <h3>Vaccination History</h3>
    <table class="print-table">
        <tr><th>Vaccine</th><th>Date Given</th><th>Next Due</th><th>Administered By</th><th>Remarks</th></tr>
        <?php $vacs=$conn->query("SELECT * FROM vaccinations WHERE pet_id=$pet_id ORDER BY next_due_date DESC"); while($v=$vacs->fetch_assoc()): ?>
        <tr>
            <td><?= e($v['vaccine_name']) ?></td>
            <td><?= e($v['date_given']) ?></td>
            <td><?= e($v['next_due_date']) ?></td>
            <td><?= e($v['administered_by']) ?></td>
            <td><?= e($v['remarks']) ?></td>
        </tr>
        <?php endwhile; ?>
    </table>
</div>

<div class="print-section">
    <p class="small text-muted">This report is generated from Vetrix clinic records. For medical interpretation, consult a licensed veterinarian.</p>
</div>
</div>
<?php include "../includes/footer.php"; ?>
