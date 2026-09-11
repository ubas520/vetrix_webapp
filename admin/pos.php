<?php
require_once "../config/database.php";
require_once "../includes/functions.php";
require_role('admin');
ensure_pos_product_schema($conn);
ensure_inventory_skus($conn);

if ($_SERVER['REQUEST_METHOD'] === 'POST') {
    verify_csrf_or_fail();
    $action = $_POST['action'] ?? '';
    if ($action === 'upload_payment_qr') {
        $saved=save_clinic_payment_qr($_FILES['payment_qr']??null);
        if(!empty($saved['ok'])){log_action($conn,'Updated clinic POS payment QR','pos_payment',null,'QR payment image updated.');flash('success','QR payment image updated for clinic staff.');}
        else flash('error',$saved['error']??'The QR image could not be uploaded.');
    } elseif ($action === 'remove_payment_qr') {
        $currentQr=clinic_payment_qr_path();
        $paymentDir=dirname(__DIR__).'/uploads/payment';
        $qrFiles=glob($paymentDir.'/clinic_payment_qr.*') ?: [];
        $hadQr=(bool)$currentQr || (bool)$qrFiles;
        $removed=false;
        if($currentQr){
            $fullQr=dirname(__DIR__).'/'.ltrim($currentQr,'/');
            if(is_file($fullQr)) $removed=@unlink($fullQr) || !is_file($fullQr);
        }
        foreach($qrFiles as $oldQr){if(is_file($oldQr)){$removed=@unlink($oldQr)||$removed;}}
        $pointer=$paymentDir.'/current_qr.txt';
        if(is_file($pointer)) @unlink($pointer);
        if(!$hadQr){
            flash('success','No payment QR image was available to remove.');
        }elseif($removed){
            log_action($conn,'Removed clinic POS payment QR','pos_payment',null,'QR payment image removed.');
            flash('success','Payment QR image removed.');
        }else{
            flash('error','Payment QR image could not be removed. Check file permissions and try again.');
        }
    } elseif ($action === 'toggle_product') {
        $id=(int)($_POST['item_id']??0);
        $new=($_POST['new_status']??'')==='inactive'?'inactive':'restore';
        $check=$conn->prepare("SELECT id,item_name,status FROM inventory_items WHERE id=? LIMIT 1");
        $check->bind_param('i',$id);$check->execute();$product=$check->get_result()->fetch_assoc();
        if(!$product){
            flash('error','Product not found.');
        } else {
            if($new==='inactive'){
                $stmt=$conn->prepare("UPDATE inventory_items SET status='inactive' WHERE id=?");$stmt->bind_param('i',$id);
            } else {
                $stmt=$conn->prepare("UPDATE inventory_items SET status=CASE WHEN stock_qty<=0 THEN 'out_of_stock' WHEN stock_qty<=reorder_level THEN 'low_stock' ELSE 'available' END WHERE id=?");$stmt->bind_param('i',$id);
            }
            $stmt->execute();
            log_action($conn,'Changed POS product visibility','inventory_item',$id,$new==='inactive'?'Hidden from POS.':'Shown in POS.');
            flash('success',$new==='inactive'?'Product hidden from POS.':'Product shown in POS.');
        }
    }
    redirect_to('admin/pos.php');
}

$summary=$conn->query("SELECT COUNT(*) total_transactions,COALESCE(SUM(CASE WHEN payment_status='paid' THEN total_amount ELSE 0 END),0) total_sales FROM pos_transactions")->fetch_assoc();
$productSummary=$conn->query("SELECT COUNT(*) products,SUM(status!='inactive') active_products,SUM(status='low_stock') low_stock,SUM(status='out_of_stock') out_stock FROM inventory_items")->fetch_assoc();
$products=$conn->query("SELECT i.*,COALESCE(s.sold_qty,0) sold_qty,CASE WHEN i.status='inactive' THEN 'inactive' WHEN i.stock_qty<=0 THEN 'out_of_stock' WHEN i.stock_qty<=i.reorder_level THEN 'low_stock' ELSE 'available' END effective_status FROM inventory_items i LEFT JOIN (SELECT item_id,SUM(quantity) sold_qty FROM pos_transaction_items GROUP BY item_id) s ON s.item_id=i.id ORDER BY FIELD(CASE WHEN i.status='inactive' THEN 'inactive' WHEN i.stock_qty<=0 THEN 'out_of_stock' WHEN i.stock_qty<=i.reorder_level THEN 'low_stock' ELSE 'available' END,'out_of_stock','low_stock','available','inactive'), CASE WHEN i.stock_qty>i.reorder_level AND i.status<>'inactive' THEN COALESCE(s.sold_qty,0) ELSE 0 END DESC, i.item_name ASC")->fetch_all(MYSQLI_ASSOC);
$categories=[];foreach($products as $product){$category=trim((string)$product['category'])?:'Uncategorized';$categories[$category]=true;}ksort($categories,SORT_NATURAL|SORT_FLAG_CASE);
[$salesPage,$salesPerPage,$salesOffset]=pagination_values(5,20,[5,10,20]);
$totalSalesHistory=(int)$conn->query("SELECT COUNT(*) c FROM pos_transactions")->fetch_assoc()['c'];
$rows=$conn->query("SELECT t.*,c.full_name client_name,h.full_name handled_by_name,pi.item_summary,pi.item_count FROM pos_transactions t LEFT JOIN users c ON t.client_id=c.id LEFT JOIN users h ON t.handled_by=h.id LEFT JOIN (SELECT transaction_id,GROUP_CONCAT(CONCAT(quantity,' × ',item_name) ORDER BY id SEPARATOR ' • ') item_summary,COALESCE(SUM(quantity),0) item_count FROM pos_transaction_items GROUP BY transaction_id) pi ON pi.transaction_id=t.id ORDER BY t.transaction_date DESC,t.id DESC LIMIT ".intval($salesPerPage)." OFFSET ".intval($salesOffset))->fetch_all(MYSQLI_ASSOC);
$clinicPaymentQr=clinic_payment_qr_path();
$clinicPaymentQrSrc=clinic_payment_qr_src();
$title='POS Products and Reports';include "../includes/header.php";include "../includes/navbar.php";
?>
<div class="layout"><?php include "../includes/admin_sidebar.php";?><main class="content admin-pos-page" id="mainContent">
<header class="page-heading"><div><span class="eyebrow">Point of sale</span><h1>POS Products and Transactions</h1></div><div class="heading-actions"><button class="button-secondary" type="button" data-open-payment-qr><?=ui_icon('qr')?>Payment QR</button><button class="button-secondary" type="button" data-open-pos-view><?=ui_icon('eye')?>View POS</button><a class="button-secondary" href="<?=app_url('admin/inventory.php')?>"><?=ui_icon('inventory')?>Manage products</a><a class="button-primary" href="<?=app_url('admin/reports.php#exports')?>"><?=ui_icon('download')?>Reports and export</a></div></header>
<?php if($m=flash('success')):?><div class="alert alert-success" role="status"><?=e($m)?></div><?php endif;?><?php if($m=flash('error')):?><div class="alert alert-danger" role="alert"><?=e($m)?></div><?php endif;?>
<section class="metric-grid"><article class="metric-card"><span class="metric-icon"><?=ui_icon('cart')?></span><div><small>POS products</small><strong><?=intval($productSummary['products'])?></strong><p>Products configured in Inventory.</p></div></article><article class="metric-card"><span class="metric-icon success"><?=ui_icon('check')?></span><div><small>Shown in POS</small><strong><?=intval($productSummary['active_products'])?></strong><p>Available to clinic cashiers.</p></div></article><article class="metric-card"><span class="metric-icon info"><?=ui_icon('coins')?></span><div><small>Paid sales</small><strong>₱<?=number_format((float)$summary['total_sales'],2)?></strong><p>Total paid transaction value.</p></div></article><article class="metric-card"><span class="metric-icon warning"><?=ui_icon('receipt')?></span><div><small>Transactions</small><strong><?=intval($summary['total_transactions'])?></strong><p>Recorded cashier transactions.</p></div></article></section>

<section class="surface-card pos-product-browser">
  <div class="section-heading"><div><span class="eyebrow">Product preview</span><h2>Products shown to cashiers</h2></div></div>
  <div class="data-search-bar pos-product-search">
    <input class="form-control" type="search" data-pos-admin-search placeholder="Search product name or SKU">
    <select class="form-select" data-pos-admin-category aria-label="Filter products by category"><option value="all">All categories</option><?php foreach(array_keys($categories) as $category):?><option value="<?=e($category)?>"><?=e($category)?></option><?php endforeach;?></select>
    <select class="form-select" data-pos-admin-sort aria-label="Sort product names"><option value="priority">Stock priority</option><option value="az">Name A–Z</option><option value="za">Name Z–A</option></select>
    <label class="entries-select">Show<select class="form-select" data-pos-admin-show aria-label="Products per page"><option value="5" selected>5</option><option value="10">10</option><option value="15">15</option><option value="20">20</option></select></label>
    <button class="button-primary" type="button" data-pos-admin-apply>Search</button>
    <button class="button-secondary" type="button" data-pos-admin-clear>Clear</button>
  </div>
  <div class="pos-product-admin-grid">
  <?php if(!$products):?><div class="empty-state"><span><?=ui_icon('package')?></span><h3>No products found</h3><p>Add the first product from Inventory.</p></div><?php endif;?>
  <?php $previewIndex=0; foreach($products as $product):$category=trim((string)$product['category'])?:'Uncategorized';$effective=$product['effective_status']??($product['stock_qty']<=0?'out_of_stock':$product['status']);$hidden=$effective==='inactive';$stockLabel=$effective==='out_of_stock'?'Out of stock':($effective==='low_stock'?'Low stock':($hidden?'Hidden':'Available'));$previewIndex++;?>
    <article class="pos-admin-product-card <?=$hidden?'is-hidden':'stock-'.e($effective)?>" data-pos-admin-product <?=$previewIndex>5?'hidden':''?> data-name="<?=e($product['item_name'])?>" data-category="<?=e($category)?>" data-sku="<?=e($product['sku']?:'')?>" data-status="<?=e($effective)?>" data-sold="<?=intval($product['sold_qty']??0)?>">
      <div class="pos-admin-product-photo"><?php if($product['product_photo']):?><img src="<?=e(app_url($product['product_photo']))?>" alt="<?=e($product['item_name'])?>"><?php else:?><span><?=ui_icon('package')?></span><?php endif;?><?php if($hidden):?><em>Hidden</em><?php endif;?></div>
      <div class="pos-admin-product-copy"><small><?=e($category)?></small><h3><?=e($product['item_name'])?></h3><p><?=e($product['sku']?:'No SKU')?> · <?=intval($product['stock_qty'])?> in stock</p><span class="pos-stock-indicator status-<?=e($effective)?>"><?=e($stockLabel)?></span><strong>₱<?=number_format((float)$product['sale_price'],2)?></strong></div>
      <div class="pos-admin-product-actions pos-visibility-action"><form method="POST" data-confirm-message="<?=$hidden?'Show this product in POS?':'Hide this product from POS?'?>"><?=csrf_field()?><input type="hidden" name="action" value="toggle_product"><input type="hidden" name="item_id" value="<?=$product['id']?>"><input type="hidden" name="new_status" value="<?=$hidden?'restore':'inactive'?>"><button class="<?=$hidden?'button-primary':'button-secondary'?>" type="submit"><?=$hidden?ui_icon('eye').'Show in POS':ui_icon('eye-off').'Hide in POS'?></button></form></div>
    </article>
  <?php endforeach;?>
  </div>
  <nav class="data-pagination natural-pagination pos-client-pagination" data-pos-admin-pager aria-label="Product preview pages"></nav>
</section>

<section class="surface-card management-table-card pos-sales-history" id="recentTransactions"><div class="section-heading"><div><span class="eyebrow">Sales history</span><h2>Recent transactions</h2></div><div class="pos-sales-controls"><label class="entries-select">Show<select name="per_page" onchange="const u=new URL(location.href);u.searchParams.set('per_page',this.value);u.searchParams.set('page','1');u.hash='recentTransactions';location.href=u.toString()"><?=render_per_page_options($salesPerPage,[5,10,20])?></select></label></div></div><div class="table-scroll-only compact-history"><table class="data-table"><thead><tr><th>Date</th><th>Client</th><th>Amount</th><th>Items</th><th>Status</th><th>Handled by</th></tr></thead><tbody><?php if(!$rows):?><tr><td colspan="6" class="empty-cell">No POS transactions found.</td></tr><?php endif;foreach($rows as $r):$detail=['title'=>'POS transaction #'.$r['id'],'eyebrow'=>'Sales record','fields'=>['Date'=>date('M d, Y h:i A',strtotime($r['transaction_date'])),'Client'=>$r['client_name']?:'Walk-in','Amount'=>'₱'.number_format((float)$r['total_amount'],2),'Items'=>$r['item_summary']?:'No line items recorded','Payment status'=>ucfirst($r['payment_status']),'Handled by'=>$r['handled_by_name']?:'Clinic staff','Notes'=>$r['notes']?:'No note']];?><tr data-transaction-id="<?=intval($r['id'])?>" data-record-detail='<?=e(json_encode($detail))?>'><td><?=date('M d, h:i A',strtotime($r['transaction_date']))?></td><td><?=e($r['client_name']?:'Walk-in')?></td><td>₱<?=number_format((float)$r['total_amount'],2)?></td><td><?=intval($r['item_count']??0)?></td><td><?=badge($r['payment_status'])?></td><td><?=e($r['handled_by_name']?:'Clinic staff')?></td></tr><?php endforeach;?></tbody></table></div><?=render_pagination($salesPage,$salesPerPage,$totalSalesHistory,['per_page'=>per_page_value($salesPerPage),'_anchor'=>'recentTransactions'])?></section>

<section class="calendar-dialog" id="paymentQrDialog" aria-hidden="true"><div class="dialog-scrim" data-close-payment-qr></div><div class="dialog-card payment-qr-dialog"><header><div><span class="eyebrow">QR payment</span><h2>Clinic payment QR</h2><p>Upload the QR code that clinic staff should show for QR payments.</p></div><button class="icon-button" type="button" data-close-payment-qr><?=ui_icon('x')?></button></header><form method="POST" enctype="multipart/form-data" class="form-stack payment-qr-upload-form"><?=csrf_field()?><input type="hidden" name="action" value="upload_payment_qr"><div class="payment-qr-preview<?=$clinicPaymentQr?' has-image':''?>" data-payment-qr-preview><?php if($clinicPaymentQr):?><img data-payment-qr-preview-image src="<?=e($clinicPaymentQrSrc)?>" alt="Clinic payment QR"><small data-payment-qr-preview-label>Uploaded file: <?=e(basename($clinicPaymentQr))?></small><?php else:?><div class="payment-qr-placeholder" data-payment-qr-placeholder><?=ui_icon('qr')?><span>No QR image uploaded yet</span><small>Choose a JPG, PNG, or WEBP file below.</small></div><img data-payment-qr-preview-image hidden alt="Clinic payment QR"><small data-payment-qr-preview-label></small><?php endif;?></div><label class="payment-qr-upload-field"><input class="form-control" type="file" name="payment_qr" accept="image/jpeg,image/png,image/webp" data-payment-qr-input aria-label="Choose clinic payment QR file" required></label><div class="form-actions payment-qr-actions"><button class="button-secondary" type="button" data-close-payment-qr>Cancel</button><?php if($clinicPaymentQr):?><button class="button-danger" type="submit" form="removePaymentQrForm" data-confirm-message="Remove the current payment QR image?"><?=ui_icon('trash')?>Remove photo</button><?php endif;?><button class="button-primary" type="submit">Save payment QR</button></div></form><?php if($clinicPaymentQr):?><form method="POST" id="removePaymentQrForm" class="payment-qr-remove-form"><?=csrf_field()?><input type="hidden" name="action" value="remove_payment_qr"></form><?php endif;?></div></section>
<section class="calendar-dialog" id="posViewDialog" aria-hidden="true"><div class="dialog-scrim" data-close-pos-view></div><div class="dialog-card pos-view-dialog"><header><div><span class="eyebrow">Cashier preview</span><h2>Products available in POS</h2></div><button class="icon-button" type="button" data-close-pos-view><?=ui_icon('x')?></button></header><div class="pos-product-admin-grid pos-view-product-grid pos-cashier-preview"><?php $activePreview=array_values(array_filter($products,fn($item)=>($item['effective_status']??$item['status'])!=='inactive'));if(!$activePreview):?><div class="empty-state"><span><?=ui_icon('package')?></span><h3>No active POS products</h3><p>Ask an administrator to add products in Inventory.</p></div><?php endif;foreach($activePreview as $item):$disabled=(int)$item['stock_qty']<=0;$effectiveStatus=$disabled?'out_of_stock':(((int)$item['stock_qty']<=(int)$item['reorder_level']||($item['effective_status']??$item['status'])==='low_stock')?'low_stock':'available');$stockLabel=ucwords(str_replace('_',' ',$effectiveStatus));$category=$item['category']?:'Uncategorized';?><article class="pos-admin-product-card stock-<?=e($effectiveStatus)?>" data-pos-admin-product data-pos-cashier-product data-name="<?=e($item['item_name'])?>" data-category="<?=e($category)?>" data-sku="<?=e($item['sku']?:'')?>" data-sold="<?=intval($item['sold_qty']??0)?>" data-status="<?=e($effectiveStatus)?>"><div class="pos-admin-product-photo"><?php if(!empty($item['product_photo'])):?><img src="<?=e(app_url($item['product_photo']))?>" alt="<?=e($item['item_name'])?>"><?php else:?><span><?=ui_icon('package')?></span><?php endif;?></div><div class="pos-admin-product-copy"><small><?=e($category)?></small><h3><?=e($item['item_name'])?></h3><p><?=e($item['sku']?:'No SKU')?> · <?=intval($item['stock_qty'])?> in stock</p><span class="pos-stock-indicator status-<?=e($effectiveStatus)?>"><?=e($disabled?'Out of stock':$stockLabel)?></span><strong>₱<?=number_format((float)$item['sale_price'],2)?></strong></div><div class="pos-admin-product-actions pos-visibility-action"><form method="POST" data-confirm-message="Hide this product from POS?"><?=csrf_field()?><input type="hidden" name="action" value="toggle_product"><input type="hidden" name="item_id" value="<?=$item['id']?>"><input type="hidden" name="new_status" value="inactive"><button class="button-secondary pos-preview-hide-button" type="submit"><?=ui_icon('eye-off')?>Hide in POS</button></form></div></article><?php endforeach;?></div></div></section>
<script>(()=>{const bind=(id,openSel,closeSel)=>{const dialog=document.getElementById(id),open=()=>{dialog?.classList.add('open');dialog?.setAttribute('aria-hidden','false');document.body.classList.add('overlay-open')},close=()=>{dialog?.classList.remove('open');dialog?.setAttribute('aria-hidden','true');document.body.classList.remove('overlay-open')};document.querySelector(openSel)?.addEventListener('click',open);document.querySelectorAll(closeSel).forEach(button=>button.addEventListener('click',close));};bind('posViewDialog','[data-open-pos-view]','[data-close-pos-view]');bind('paymentQrDialog','[data-open-payment-qr]','[data-close-payment-qr]');const input=document.querySelector('[data-payment-qr-input]'),preview=document.querySelector('[data-payment-qr-preview]'),image=preview?.querySelector('[data-payment-qr-preview-image]'),label=preview?.querySelector('[data-payment-qr-preview-label]'),placeholder=preview?.querySelector('[data-payment-qr-placeholder]');input?.addEventListener('change',()=>{const file=input.files?.[0];if(!file||!preview||!image||!label)return;const url=URL.createObjectURL(file);image.onload=()=>URL.revokeObjectURL(url);image.src=url;image.hidden=false;preview.classList.add('has-image');if(placeholder)placeholder.remove();label.textContent='Selected file: '+file.name;});})();</script>
<script>(()=>{const id=new URL(location.href).searchParams.get('transaction_id');if(!id)return;const row=document.querySelector(`[data-transaction-id="${CSS.escape(id)}"]`);if(row)setTimeout(()=>row.click(),100);})();</script>
</main></div><?php include "../includes/footer.php";?>
