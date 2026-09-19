<?php
require_once "../config/database.php";
require_once "../includes/functions.php";
require_role("admin");

$todayAppointments = (int)$conn->query("SELECT COUNT(*) c FROM appointments WHERE DATE(COALESCE(scheduled_date, requested_date))=CURDATE()")->fetch_assoc()['c'];
$pendingAppointments = (int)$conn->query("SELECT COUNT(*) c FROM appointments WHERE status='pending'")->fetch_assoc()['c'];
$pendingPets = (int)$conn->query("SELECT COUNT(*) c FROM pets WHERE verification_status='pending'")->fetch_assoc()['c'];
$pendingUsers = (int)$conn->query("SELECT COUNT(*) c FROM users WHERE role='client' AND status='pending'")->fetch_assoc()['c'];
$pendingEditRequests = (int)$conn->query("SELECT COUNT(*) c FROM edit_requests WHERE status='pending'")->fetch_assoc()['c'];
$lowStock = (int)$conn->query("SELECT COUNT(*) c FROM inventory_items WHERE status IN ('low_stock','out_of_stock')")->fetch_assoc()['c'];
$dueSoon = (int)$conn->query("SELECT COUNT(*) c FROM vaccinations WHERE next_due_date BETWEEN CURDATE() AND DATE_ADD(CURDATE(), INTERVAL 60 DAY)")->fetch_assoc()['c'];
$attentionAppointments = (int)$conn->query("SELECT COUNT(*) c FROM appointments WHERE (status='pending' AND requested_date<NOW()) OR (status='approved' AND (scheduled_date IS NULL OR assigned_vet_id IS NULL OR scheduled_date<NOW()))")->fetch_assoc()['c'];
$pendingLeaveRequests = (int)$conn->query("SELECT COUNT(*) c FROM staff_availability WHERE event_kind='unavailable' AND reason LIKE '[Pending]%' AND ends_at>starts_at")->fetch_assoc()['c'];
$revenue = (float)$conn->query("SELECT COALESCE(SUM(total_amount),0) c FROM pos_transactions WHERE payment_status='paid' AND DATE(transaction_date)=CURDATE()")->fetch_assoc()['c'];

function dashboard_rows(mysqli $conn, string $sql): array {
    $rows = [];
    $result = $conn->query($sql);
    while ($result && $row = $result->fetch_assoc()) $rows[] = $row;
    return $rows;
}

$todayAppointmentRows = dashboard_rows($conn, "SELECT a.id,a.status,a.reason,COALESCE(a.scheduled_date,a.requested_date) visit_time,u.full_name client_name,p.name pet_name,p.species,v.full_name vet_name FROM appointments a JOIN users u ON a.owner_id=u.id JOIN pets p ON a.pet_id=p.id LEFT JOIN users v ON a.assigned_vet_id=v.id WHERE DATE(COALESCE(a.scheduled_date,a.requested_date))=CURDATE() ORDER BY visit_time,a.id");
$appointments=[];$res=$conn->query("SELECT a.id,a.status,a.requested_date,a.scheduled_date,a.reason,a.confirmation_code,u.full_name client_name,p.name pet_name,p.species,v.full_name vet_name FROM appointments a JOIN users u ON a.owner_id=u.id JOIN pets p ON a.pet_id=p.id LEFT JOIN users v ON a.assigned_vet_id=v.id ORDER BY COALESCE(a.scheduled_date,a.requested_date) DESC,a.id DESC LIMIT 10");while($r=$res->fetch_assoc())$appointments[]=$r;
$logs=[];$res=$conn->query("SELECT l.*,u.full_name FROM audit_logs l LEFT JOIN users u ON l.actor_user_id=u.id ORDER BY l.created_at DESC LIMIT 10");while($r=$res->fetch_assoc())$logs[]=$r;
$inventory=[];$res=$conn->query("SELECT * FROM inventory_items ORDER BY FIELD(status,'out_of_stock','low_stock','available','inactive'),updated_at DESC,created_at DESC LIMIT 10");while($r=$res->fetch_assoc())$inventory[]=$r;

$title = "Admin Dashboard";
include "../includes/header.php";
include "../includes/navbar.php";
?>
<div class="layout"><?php include "../includes/admin_sidebar.php"; ?>
<main class="app-main">
<div class="app-content">

<!-- Page Header -->
<div class="vetrix-page-header">
    <div class="vetrix-page-header-top">
        <div>
            <h1 class="vetrix-page-title">Administrator Dashboard</h1>
            <p class="vetrix-page-description">Welcome back, <?=e($_SESSION['full_name'] ?? 'Administrator')?>. Here's an overview of today's clinic operations.</p>
        </div>
        <div class="vetrix-page-actions">
            <a class="btn-vetrix btn-vetrix-secondary" href="<?=app_url('admin/calendar.php')?>">
                <?=ui_icon('calendar-days')?>
                <span>Calendar</span>
            </a>
            <a class="btn-vetrix btn-vetrix-primary" href="<?=app_url('admin/appointments.php')?>">
                <?=ui_icon('calendar')?>
                <span>Appointments</span>
            </a>
        </div>
    </div>
</div>

<?php if($m=flash('success')):?>
<div class="vetrix-alert vetrix-alert-success" role="status">
    <?=ui_icon('check-circle')?>
    <div><?=e($m)?></div>
</div>
<?php endif;?>

<!-- Dashboard Stats -->
<div class="vetrix-stats-grid">
    <a href="<?=app_url('admin/appointments.php')?>" class="vetrix-stat-card">
        <div class="vetrix-stat-icon" style="background: #3478A9;">
            <?=ui_icon('calendar')?>
        </div>
        <div class="vetrix-stat-content">
            <div class="vetrix-stat-label">Today's Appointments</div>
            <div class="vetrix-stat-value"><?=$todayAppointments?></div>
        </div>
    </a>

    <a href="<?=app_url('admin/appointments.php?status=pending')?>" class="vetrix-stat-card">
        <div class="vetrix-stat-icon" style="background: #F59E0B;">
            <?=ui_icon('clock')?>
        </div>
        <div class="vetrix-stat-content">
            <div class="vetrix-stat-label">Pending Appointments</div>
            <div class="vetrix-stat-value"><?=$pendingAppointments?></div>
        </div>
    </a>

    <a href="<?=app_url('admin/pets.php?verification=pending')?>" class="vetrix-stat-card">
        <div class="vetrix-stat-icon" style="background: #6366F1;">
            <?=ui_icon('paw')?>
        </div>
        <div class="vetrix-stat-content">
            <div class="vetrix-stat-label">Pending Pet Verification</div>
            <div class="vetrix-stat-value"><?=$pendingPets?></div>
        </div>
    </a>

    <a href="<?=app_url('admin/pos.php')?>" class="vetrix-stat-card">
        <div class="vetrix-stat-icon" style="background: #10B981;">
            <?=ui_icon('coins')?>
        </div>
        <div class="vetrix-stat-content">
            <div class="vetrix-stat-label">Today's Revenue</div>
            <div class="vetrix-stat-value">₱<?=number_format($revenue,2)?></div>
        </div>
    </a>
</div>

<!-- Action Queue Section -->
<div class="vetrix-card" style="margin-top: var(--vetrix-space-2xl);">
    <div class="vetrix-card-header">
        <div>
            <h2 class="vetrix-card-title">Action Queue</h2>
            <p class="text-secondary text-sm" style="margin: 0;">Items requiring immediate attention</p>
        </div>
    </div>
    <div class="vetrix-card-body">
        <div style="display: grid; grid-template-columns: repeat(auto-fill, minmax(300px, 1fr)); gap: var(--vetrix-space-md);">

            <?php if($attentionAppointments > 0): ?>
            <a href="<?=app_url('admin/appointments.php')?>" class="vetrix-alert vetrix-alert-danger" style="text-decoration: none; margin: 0;">
                <?=ui_icon('alert-circle')?>
                <div>
                    <strong style="font-size: var(--vetrix-font-size-md);"><?=$attentionAppointments?> Appointment<?=$attentionAppointments>1?'s':''?> Need Attention</strong>
                    <p class="text-xs" style="margin: 4px 0 0 0;">Appointments with scheduling or assignment issues</p>
                </div>
            </a>
            <?php endif; ?>

            <?php if($pendingAppointments > 0): ?>
            <a href="<?=app_url('admin/appointments.php?status=pending')?>" class="vetrix-alert vetrix-alert-warning" style="text-decoration: none; margin: 0;">
                <?=ui_icon('clock')?>
                <div>
                    <strong style="font-size: var(--vetrix-font-size-md);"><?=$pendingAppointments?> Pending Appointment<?=$pendingAppointments>1?'s':''?></strong>
                    <p class="text-xs" style="margin: 4px 0 0 0;">Scheduling requests awaiting approval</p>
                </div>
            </a>
            <?php endif; ?>

            <?php if($pendingPets > 0): ?>
            <a href="<?=app_url('admin/pets.php?verification=pending')?>" class="vetrix-alert vetrix-alert-info" style="text-decoration: none; margin: 0;">
                <?=ui_icon('paw')?>
                <div>
                    <strong style="font-size: var(--vetrix-font-size-md);"><?=$pendingPets?> Pet<?=$pendingPets>1?'s':''?> Awaiting Verification</strong>
                    <p class="text-xs" style="margin: 4px 0 0 0;">Review submitted pet profiles</p>
                </div>
            </a>
            <?php endif; ?>

            <?php if($pendingEditRequests > 0): ?>
            <a href="<?=app_url('admin/pet_edit_requests.php?status=pending')?>" class="vetrix-alert vetrix-alert-info" style="text-decoration: none; margin: 0;">
                <?=ui_icon('edit')?>
                <div>
                    <strong style="font-size: var(--vetrix-font-size-md);"><?=$pendingEditRequests?> Pet Edit Request<?=$pendingEditRequests>1?'s':''?></strong>
                    <p class="text-xs" style="margin: 4px 0 0 0;">Owner profile change requests</p>
                </div>
            </a>
            <?php endif; ?>

            <?php if($lowStock > 0): ?>
            <a href="<?=app_url('admin/inventory.php')?>" class="vetrix-alert vetrix-alert-warning" style="text-decoration: none; margin: 0;">
                <?=ui_icon('package')?>
                <div>
                    <strong style="font-size: var(--vetrix-font-size-md);"><?=$lowStock?> Low Stock Item<?=$lowStock>1?'s':''?></strong>
                    <p class="text-xs" style="margin: 4px 0 0 0;">Inventory items need restocking</p>
                </div>
            </a>
            <?php endif; ?>

            <?php if($dueSoon > 0): ?>
            <a href="<?=app_url('admin/vaccinations.php')?>" class="vetrix-alert vetrix-alert-info" style="text-decoration: none; margin: 0;">
                <?=ui_icon('syringe')?>
                <div>
                    <strong style="font-size: var(--vetrix-font-size-md);"><?=$dueSoon?> Vaccination<?=$dueSoon>1?'s':''?> Due Soon</strong>
                    <p class="text-xs" style="margin: 4px 0 0 0;">Follow-ups needed within 60 days</p>
                </div>
            </a>
            <?php endif; ?>
        </div>
    </div>
</div>

<!-- Today's Schedule -->
<?php if($todayAppointmentRows): ?>
<div class="vetrix-card" style="margin-top: var(--vetrix-space-2xl);">
    <div class="vetrix-card-header">
        <h2 class="vetrix-card-title">Today's Appointment Schedule</h2>
        <a href="<?=app_url('admin/appointments.php')?>" class="btn-vetrix btn-vetrix-sm btn-vetrix-secondary">View All</a>
    </div>
    <div class="vetrix-card-body">
        <div class="vetrix-table-container" style="border: none; box-shadow: none;">
            <table class="vetrix-table">
                <thead>
                    <tr>
                        <th>Time</th>
                        <th>Pet</th>
                        <th>Owner</th>
                        <th>Reason</th>
                        <th>Veterinarian</th>
                        <th>Status</th>
                        <th>Actions</th>
                    </tr>
                </thead>
                <tbody>
                    <?php foreach($todayAppointmentRows as $r): ?>
                    <tr>
                        <td><?=date('h:i A', strtotime($r['visit_time']))?></td>
                        <td><strong><?=e($r['pet_name'])?></strong><br><small class="text-muted"><?=e($r['species'])?></small></td>
                        <td><?=e($r['client_name'])?></td>
                        <td><?=e($r['reason'] ?: 'No reason provided')?></td>
                        <td><?=e($r['vet_name'] ?: 'Not assigned')?></td>
                        <td><span class="vetrix-badge vetrix-badge-<?=e($r['status'])?>"><?=e(ucfirst($r['status']))?></span></td>
                        <td>
                            <div class="vetrix-table-actions">
                                <a href="<?=app_url('admin/appointments.php?appointment_id='.$r['id'])?>" class="vetrix-table-action-btn" title="View">
                                    <?=ui_icon('eye')?>
                                </a>
                            </div>
                        </td>
                    </tr>
                    <?php endforeach; ?>
                </tbody>
            </table>
        </div>
    </div>
</div>
<?php endif; ?>

<!-- Recent Activity Tabs -->
<div class="vetrix-card" style="margin-top: var(--vetrix-space-2xl);">
    <div class="vetrix-card-header">
        <h2 class="vetrix-card-title">Recent Activity</h2>
    </div>
    <div class="vetrix-card-body">
        <!-- Tabs -->
        <div class="vetrix-tabs">
            <div class="vetrix-tabs-list">
                <a href="#appointments" class="vetrix-tab active" data-tab="appointments">
                    <?=ui_icon('calendar')?>
                    Appointments
                </a>
                <a href="#inventory" class="vetrix-tab" data-tab="inventory">
                    <?=ui_icon('package')?>
                    Inventory
                </a>
                <a href="#activity" class="vetrix-tab" data-tab="activity">
                    <?=ui_icon('activity')?>
                    Activity Log
                </a>
            </div>
        </div>

        <!-- Tab Panels -->
        <div class="vetrix-tab-panels">
            <!-- Appointments Panel -->
            <div class="vetrix-tab-panel active" data-panel="appointments">
                <?php if($appointments): ?>
                <div class="vetrix-table-container" style="border: none; box-shadow: none;">
                    <table class="vetrix-table">
                        <thead>
                            <tr>
                                <th>Pet / Client</th>
                                <th>Date & Time</th>
                                <th>Veterinarian</th>
                                <th>Status</th>
                                <th>Actions</th>
                            </tr>
                        </thead>
                        <tbody>
                            <?php foreach(array_slice($appointments, 0, 5) as $r):
                                $when = $r['scheduled_date'] ?: $r['requested_date'];
                            ?>
                            <tr>
                                <td>
                                    <strong><?=e($r['pet_name'])?></strong><br>
                                    <small class="text-muted"><?=e($r['client_name'])?> · <?=e($r['species'])?></small>
                                </td>
                                <td><?=date('M d, Y h:i A', strtotime($when))?></td>
                                <td><?=e($r['vet_name'] ?: 'Not assigned')?></td>
                                <td><span class="vetrix-badge vetrix-badge-<?=e($r['status'])?>"><?=e(ucfirst($r['status']))?></span></td>
                                <td>
                                    <div class="vetrix-table-actions">
                                        <a href="<?=app_url('admin/appointments.php?appointment_id='.$r['id'])?>" class="vetrix-table-action-btn" title="View">
                                            <?=ui_icon('eye')?>
                                        </a>
                                    </div>
                                </td>
                            </tr>
                            <?php endforeach; ?>
                        </tbody>
                    </table>
                </div>
                <?php else: ?>
                <div class="vetrix-empty-state">
                    <div class="vetrix-empty-icon"><?=ui_icon('calendar')?></div>
                    <div class="vetrix-empty-title">No appointments found</div>
                    <p class="vetrix-empty-description">No appointments have been scheduled yet.</p>
                </div>
                <?php endif; ?>
            </div>

            <!-- Inventory Panel -->
            <div class="vetrix-tab-panel" data-panel="inventory">
                <?php if($inventory): ?>
                <div class="vetrix-table-container" style="border: none; box-shadow: none;">
                    <table class="vetrix-table">
                        <thead>
                            <tr>
                                <th>Item</th>
                                <th>Category</th>
                                <th>Stock</th>
                                <th>Price</th>
                                <th>Status</th>
                                <th>Actions</th>
                            </tr>
                        </thead>
                        <tbody>
                            <?php foreach(array_slice($inventory, 0, 5) as $r): ?>
                            <tr>
                                <td>
                                    <strong><?=e($r['item_name'])?></strong><br>
                                    <small class="text-muted"><?=e($r['sku'] ?: 'No SKU')?></small>
                                </td>
                                <td><?=e($r['category'] ?: 'Uncategorized')?></td>
                                <td><?=intval($r['stock_qty'])?></td>
                                <td>₱<?=number_format((float)$r['sale_price'], 2)?></td>
                                <td><span class="vetrix-badge vetrix-badge-<?=e(str_replace('_', '-', $r['status']))?>"><?=e(ucwords(str_replace('_', ' ', $r['status'])))?></span></td>
                                <td>
                                    <div class="vetrix-table-actions">
                                        <a href="<?=app_url('admin/inventory.php?item_id='.$r['id'])?>" class="vetrix-table-action-btn" title="View">
                                            <?=ui_icon('eye')?>
                                        </a>
                                    </div>
                                </td>
                            </tr>
                            <?php endforeach; ?>
                        </tbody>
                    </table>
                </div>
                <?php else: ?>
                <div class="vetrix-empty-state">
                    <div class="vetrix-empty-icon"><?=ui_icon('package')?></div>
                    <div class="vetrix-empty-title">No inventory items</div>
                    <p class="vetrix-empty-description">No inventory items have been added yet.</p>
                </div>
                <?php endif; ?>
            </div>

            <!-- Activity Log Panel -->
            <div class="vetrix-tab-panel" data-panel="activity">
                <?php if($logs): ?>
                <div class="vetrix-table-container" style="border: none; box-shadow: none;">
                    <table class="vetrix-table">
                        <thead>
                            <tr>
                                <th>Action</th>
                                <th>Actor</th>
                                <th>Entity</th>
                                <th>Date & Time</th>
                                <th>Actions</th>
                            </tr>
                        </thead>
                        <tbody>
                            <?php foreach(array_slice($logs, 0, 5) as $r): ?>
                            <tr>
                                <td><?=e($r['action'])?></td>
                                <td><?=e($r['full_name'] ?: 'System')?></td>
                                <td><?=e(ucwords(str_replace('_', ' ', $r['entity_type'] ?: 'System')))?></td>
                                <td><?=date('M d, Y h:i A', strtotime($r['created_at']))?></td>
                                <td>
                                    <div class="vetrix-table-actions">
                                        <button class="vetrix-table-action-btn" title="View Details" onclick="alert('<?=e($r['details'] ?: 'No details available')?>)">
                                            <?=ui_icon('eye')?>
                                        </button>
                                    </div>
                                </td>
                            </tr>
                            <?php endforeach; ?>
                        </tbody>
                    </table>
                </div>
                <?php else: ?>
                <div class="vetrix-empty-state">
                    <div class="vetrix-empty-icon"><?=ui_icon('activity')?></div>
                    <div class="vetrix-empty-title">No activity recorded</div>
                    <p class="vetrix-empty-description">No system activity has been logged yet.</p>
                </div>
                <?php endif; ?>
            </div>
        </div>
    </div>
</div>

</div>
</main>
</div>

<script>
// Tab switching functionality
document.addEventListener('DOMContentLoaded', function() {
    const tabs = document.querySelectorAll('.vetrix-tab');
    const panels = document.querySelectorAll('.vetrix-tab-panel');

    tabs.forEach(tab => {
        tab.addEventListener('click', function(e) {
            e.preventDefault();
            const targetPanel = this.dataset.tab;

            // Remove active class from all tabs and panels
            tabs.forEach(t => t.classList.remove('active'));
            panels.forEach(p => p.classList.remove('active'));

            // Add active class to clicked tab and corresponding panel
            this.classList.add('active');
            document.querySelector(`[data-panel="${targetPanel}"]`).classList.add('active');
        });
    });
});
</script>

<style>
.vetrix-tab-panels {
    margin-top: var(--vetrix-space-lg);
}

.vetrix-tab-panel {
    display: none;
}

.vetrix-tab-panel.active {
    display: block;
}
</style>

<?php include "../includes/footer.php"; ?>
