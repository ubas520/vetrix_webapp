<?php
require_once "../config/database.php";
require_once "../includes/functions.php";
require_role('staff');
ensure_pos_product_schema($conn);
$q=trim($_GET['q']??'');
[$page,$perPage,$offset]=pagination_values(6,6,[6]);
$where='';
if($q!==''){
    $safe=$conn->real_escape_string($q);
    $where="WHERE (r.receipt_number LIKE '%$safe%' OR CAST(t.id AS CHAR) LIKE '%$safe%' OR COALESCE(c.full_name,'Walk-in') LIKE '%$safe%' OR COALESCE(h.full_name,'Clinic staff') LIKE '%$safe%')";
}
$total=(int)$conn->query("SELECT COUNT(*) c FROM pos_receipts r JOIN pos_transactions t ON r.transaction_id=t.id LEFT JOIN users c ON t.client_id=c.id LEFT JOIN users h ON t.handled_by=h.id $where")->fetch_assoc()['c'];
$rows=$conn->query("SELECT r.*,t.total_amount,t.payment_status,t.payment_method,t.transaction_date,c.full_name client_name,h.full_name cashier_name FROM pos_receipts r JOIN pos_transactions t ON r.transaction_id=t.id LEFT JOIN users c ON t.client_id=c.id LEFT JOIN users h ON t.handled_by=h.id $where ORDER BY t.transaction_date DESC,t.id DESC LIMIT $perPage OFFSET $offset");
$title='Receipts';include "../includes/header.php";include "../includes/navbar.php";
?>
<div class="layout"><?php include "../includes/staff_sidebar.php";?><main class="content staff-receipts-page" id="mainContent">
<header class="page-heading"><div><span class="eyebrow">Point of sale</span><h1>Receipts</h1></div><a class="button-primary" href="<?=app_url('staff/pos.php')?>"><?=ui_icon('cart')?>Point of Sale</a></header>
<form class="surface-card data-search-bar" method="GET"><input class="form-control" type="search" name="q" value="<?=e($q)?>" placeholder="Search receipt, transaction, client, or cashier"><button class="button-primary" type="submit">Search</button><?php if($q!==''):?><a class="button-secondary" href="receipts.php">Clear</a><?php endif;?></form>
<section class="surface-card"><div class="section-heading receipt-archive-heading"><div><span class="eyebrow">Receipt archive</span><h2><?=$total?> saved receipt<?=$total===1?'':'s'?></h2></div><div class="receipt-archive-controls"><label class="entries-select">Show<select name="per_page"><?=render_per_page_options($perPage,[6])?></select></label><div class="view-toggle" data-view-toggle data-target="#receiptArchive" data-key="staff-receipts" data-default="grid"><button type="button" data-view="list" aria-label="List view"><?=ui_icon('list')?></button><button type="button" data-view="grid" aria-label="Grid view"><?=ui_icon('grid')?></button></div></div></div>
<div class="receipt-archive-list view-grid" id="receiptArchive"><?php if(!$rows->num_rows):?><div class="empty-state"><h3>No receipts found</h3><p>Paid POS transactions will appear here.</p></div><?php endif;while($r=$rows->fetch_assoc()):?><article class="receipt-archive-row"><div><small><?=e(date('M d, Y h:i A',strtotime($r['transaction_date'])))?></small><h3><?=e($r['receipt_number'])?></h3><p><?=e($r['client_name']?:'Walk-in')?> · Cashier: <?=e($r['cashier_name']?:'Clinic staff')?> · Payment: <?=e(($r['payment_method']??'cash')==='qr'?'QR':'Cash')?></p></div><div class="receipt-archive-total"><small><?=e(ucfirst($r['payment_status']))?></small><b>₱<?=number_format((float)$r['total_amount'],2)?></b></div><div class="receipt-archive-actions"><a class="button-secondary" href="<?=app_url('staff/receipt.php?transaction_id='.(int)$r['transaction_id'].'&from=receipts')?>"><?=ui_icon('eye')?>View</a><a class="button-primary" href="<?=app_url('staff/receipt_pdf.php?transaction_id='.(int)$r['transaction_id'])?>"><?=ui_icon('download')?>PDF</a></div></article><?php endwhile;?></div>
<?=render_pagination($page,$perPage,$total,['q'=>$q,'per_page'=>per_page_value($perPage),'_anchor'=>'receiptArchive'])?></section>
</main></div><?php include "../includes/footer.php";?>
