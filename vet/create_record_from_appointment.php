<?php
require_once "../config/database.php";
require_once "../includes/functions.php";
require_role("veterinarian");

$id = (int)($_GET['id'] ?? $_POST['appointment_id'] ?? 0);
$app = $conn->query("SELECT a.*, p.name pet_name, p.species, p.breed, p.allergies, p.critical_notes, u.full_name
    FROM appointments a
    JOIN pets p ON a.pet_id=p.id
    JOIN users u ON a.owner_id=u.id
    WHERE a.id=$id")->fetch_assoc();
if(!$app) die('Appointment not found.');

if($_SERVER['REQUEST_METHOD'] === 'POST'){verify_csrf_or_fail();
    $stmt = $conn->prepare("INSERT INTO medical_records(pet_id,veterinarian,visit_date,symptoms,diagnosis,treatment,prescription,notes) VALUES(?,?,?,?,?,?,?,?)");
    $stmt->bind_param("isssssss", $app['pet_id'], $_POST['veterinarian'], $_POST['visit_date'], $_POST['symptoms'], $_POST['diagnosis'], $_POST['treatment'], $_POST['prescription'], $_POST['notes']);
    $stmt->execute();
    $record_id = $conn->insert_id;
    notify_user($conn, $app['owner_id'], 'Medical Record Added', 'A new medical record was added for '.$app['pet_name'].'.', 'record', null);
    log_action($conn, 'Veterinarian created medical record from appointment', 'medical_record', $record_id, 'Appointment ID '.$id);
    flash('success', 'Medical record created from appointment.');
    redirect_to('vet/prescription.php');
}

$title = 'Create Record';
include "../includes/header.php";
include "../includes/navbar.php";
?>
<div class="layout"><?php include "../includes/vet_sidebar.php"; ?>
<main class="content" id="mainContent">
    <header class="page-heading"><div><span class="eyebrow">Appointment record</span><h1>Create Medical Record</h1></div><a class="button-secondary" data-smart-back href="<?=app_url('vet/appointments.php')?>"><?=ui_icon('arrow-left')?>Back to appointments</a></header>

    <div class="row g-4 align-items-start">
        <div class="col-lg-4">
            <aside class="admin-workflow-card">
                <div class="section-head mb-3">
                    <div>
                        <h3><?= e($app['pet_name']) ?></h3>
                        <p class="text-muted mb-0"><?= e($app['full_name']) ?></p>
                    </div>
                </div>
                <div class="admin-meta-row mb-3">
                    <span><?= e($app['species']) ?></span>
                    <span><?= e($app['breed'] ?: 'Breed not set') ?></span>
                    <?= badge($app['status']) ?>
                </div>
                <div class="admin-note-box mb-3"><b>Appointment reason:</b> <?= e($app['reason'] ?: 'No reason saved.') ?></div>
                <p><b>Allergies:</b> <?= e($app['allergies'] ?: 'No allergies saved.') ?></p>
                <p><b>Critical notes:</b> <?= e($app['critical_notes'] ?: 'No critical notes.') ?></p>
            </aside>
        </div>
        <div class="col-lg-8">
            <section class="admin-workflow-card">
                <div class="section-head mb-3">
                    <div>
                        <h3>Record Details</h3>
                        <p class="text-muted mb-0">Complete diagnosis, treatment, prescription, and notes before saving.</p>
                    </div>
                </div>
                <form method="POST" class="reference-mini-form">
                    <?= csrf_field() ?>
                    <input type="hidden" name="appointment_id" value="<?=$id?>">
                    <label class="form-label">Veterinarian</label>
                    <input class="form-control" name="veterinarian" placeholder="Dr. Name">
                    <label class="form-label">Visit Date</label>
                    <input class="form-control" type="date" name="visit_date" value="<?=date('Y-m-d')?>" required>
                    <label class="form-label">Symptoms</label>
                    <textarea class="form-control" name="symptoms"><?=e($app['reason'])?></textarea>
                    <label class="form-label">Diagnosis</label>
                    <textarea class="form-control" name="diagnosis" required></textarea>
                    <label class="form-label">Treatment</label>
                    <textarea class="form-control" name="treatment" required></textarea>
                    <label class="form-label">Prescription</label>
                    <textarea class="form-control" name="prescription"></textarea>
                    <label class="form-label">Notes</label>
                    <textarea class="form-control" name="notes"></textarea>
                    <div class="admin-action-row">
                        <button class="btn btn-primary">Save Medical Record</button>
                        <a class="btn btn-light" data-smart-back href="appointments.php">Back</a>
                    </div>
                </form>
            </section>
        </div>
    </div>
</main>
</div>
<?php include "../includes/footer.php"; ?>
