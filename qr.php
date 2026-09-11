<?php
require_once __DIR__ . '/includes/pet_qr.php';

header('Cache-Control: no-store, private');
header('Referrer-Policy: no-referrer');
header('X-Robots-Tag: noindex, nofollow');
$token = pet_qr_token($_GET['token'] ?? '');
if (!is_logged_in()) {
    if ($token !== '') $_SESSION['pending_pet_qr_token'] = $token;
    else unset($_SESSION['pending_pet_qr_token']);
    redirect_to('login.php');
}
require_once __DIR__ . '/config/database.php';
require_role(['admin', 'staff', 'veterinarian', 'client']);

$role = $_SESSION['role'];
// Newer clinic installations share their existing retrieval screen across roles.
if ($role !== 'client' && is_file(__DIR__ . '/includes/qr_retrieval_page.php')) {
    $qrRole = $role;
    require __DIR__ . '/includes/qr_retrieval_page.php';
    exit;
}
$pet = pet_qr_record($conn, $token, $role, (int) current_user_id());
$records = [];
if ($pet) {
    $stmt = $conn->prepare('SELECT visit_date,symptoms,diagnosis,treatment FROM medical_records WHERE pet_id=? ORDER BY visit_date DESC,id DESC LIMIT 5');
    $stmt->bind_param('i', $pet['pet_id']);
    $stmt->execute();
    $records = $stmt->get_result()->fetch_all(MYSQLI_ASSOC);
    $stmt->close();
    log_action($conn, 'QR pet record retrieved', 'pet', $pet['pet_id'], 'QR web link opened.');
} else {
    http_response_code(404);
}
$sidebars = ['admin' => 'admin_sidebar.php', 'staff' => 'staff_sidebar.php', 'veterinarian' => 'vet_sidebar.php', 'client' => 'client_sidebar.php'];
$title = 'QR Pet Retrieval - Vetrix';
include __DIR__ . '/includes/header.php';
include __DIR__ . '/includes/navbar.php';
?>
<div class="layout">
    <?php include __DIR__ . '/includes/' . $sidebars[$role]; ?>
    <main class="content">
        <section class="hero mb-4">
            <h1>QR Pet Retrieval</h1>
            <p>Your scanned QR opens the matching pet's protected clinic record.</p>
        </section>
        <?php if (!$pet): ?>
            <div class="soft-card">
                <h2>Pet record unavailable</h2>
                <p>This QR may be inactive or expired, or your account does not have access. Sign in as the pet owner or contact the clinic for help.</p>
                <a class="btn btn-primary" href="<?= e(app_url(dashboard_for_role($role))) ?>">Back to dashboard</a>
            </div>
        <?php else: ?>
            <div class="row g-4">
                <div class="col-lg-5">
                    <div class="soft-card">
                        <h2><?= e($pet['name']) ?></h2>
                        <p><?= e($pet['species']) ?> / <?= e($pet['breed']) ?></p>
                        <h3>Owner contact</h3>
                        <p><b>Owner:</b> <?= e($pet['full_name']) ?><br>
                           <b>Phone:</b> <?= e($pet['phone']) ?><br>
                           <b>Email:</b> <?= e($pet['email']) ?><br>
                           <b>Address:</b> <?= e($pet['address']) ?></p>
                        <div class="priority-alert">
                            <b>Allergies:</b> <?= e($pet['allergies'] ?: 'None reported') ?><br>
                            <b>Critical notes:</b> <?= e($pet['critical_notes'] ?: 'None reported') ?>
                        </div>
                    </div>
                </div>
                <div class="col-lg-7">
                    <div class="table-card">
                        <h3>Recent Medical Records</h3>
                        <?php if (!$records): ?>
                            <p>No clinic records yet.</p>
                        <?php else: ?>
                            <div class="table-responsive">
                                <table class="table">
                                    <thead><tr><th>Date</th><th>Symptoms</th><th>Diagnosis</th><th>Treatment</th></tr></thead>
                                    <tbody><?php foreach ($records as $record): ?>
                                        <tr><td><?= e($record['visit_date']) ?></td><td><?= e($record['symptoms']) ?></td><td><?= e($record['diagnosis']) ?></td><td><?= e($record['treatment']) ?></td></tr>
                                    <?php endforeach; ?></tbody>
                                </table>
                            </div>
                        <?php endif; ?>
                    </div>
                </div>
            </div>
        <?php endif; ?>
    </main>
</div>
<?php include __DIR__ . '/includes/footer.php'; ?>
