<?php
require_once "../config/database.php";
require_once "../includes/functions.php";
require_once "../includes/report_helpers.php";
require_role("admin");

$title = "Client Directory Report";
include "../includes/header.php";
echo report_actions();
?>
<div class="print-page">
<?= report_header("Client & Pet Directory", "Complete registered client list") ?>
<div class="print-section">
<table class="print-table">
<tr><th>Client</th><th>Contact</th><th>Address</th><th>Status</th><th>Account Source</th><th>Registered Pets</th></tr>
<?php
$clients=$conn->query("SELECT * FROM users WHERE role='client' ORDER BY full_name");
while($c=$clients->fetch_assoc()):
    $cid=(int)$c['id'];
    $pets=$conn->query("SELECT name,species,breed FROM pets WHERE owner_id=$cid");
    $petList=[];
    while($p=$pets->fetch_assoc()) $petList[] = e($p['name'])." (".e($p['species'])." / ".e($p['breed']).")";
?>
<tr>
<td><?= e($c['full_name']) ?></td>
<td><?= e($c['phone']) ?><br><?= e($c['email']) ?></td>
<td><?= e($c['address']) ?></td>
<td><?= e(!empty($c['deleted_at']) ? 'Deleted' : ucwords(str_replace('_',' ',$c['status']))) ?></td>
<td><?= e($c['account_source']) ?></td>
<td><?= implode("<br>", $petList) ?></td>
</tr>
<?php endwhile; ?>
</table>
</div>
</div>
<?php include "../includes/footer.php"; ?>
