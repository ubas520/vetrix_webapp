<?php
require_once "../config/database.php";
require_once "../includes/functions.php";
require_role("staff");
ensure_pos_product_schema($conn);
ensure_inventory_skus($conn);

$inventoryCategoryOptions = [
    'Veterinary medicines',
    'Veterinary supplements',
    'Ear care',
    'Ectoparasite control',
    'Milk replacers',
    'Energy supplements',
    'Pet treats',
    'Pet hygiene',
    'Wet cat food',
    'Wet dog food',
    'Cat litter',
    'Skin care',
    'Dental care',
    'Grooming supplies',
    'Pet accessories'
];
$inventorySicknessOptions = [
    'bacterial infections',
    'bacterial eye infections',
    'ear infection',
    'ear inflammation',
    'ear pain',
    'diarrhea',
    'vomiting',
    'nausea',
    'slow stomach emptying',
    'cough with thick mucus',
    'chest congestion',
    'intestinal infection',
    'diarrhea caused by certain bacteria or parasites',
    'allergies',
    'swelling',
    'inflammation',
    'dull coat',
    'dry skin',
    'coat support',
    'vitamin deficiency',
    'poor appetite',
    'recovery support',
    'liver support',
    'kidney support',
    'general vitamin and mineral support',
    'low platelet count',
    'anemia support',
    'iron deficiency',
    'anemia',
    'ear cleaning',
    'wax buildup',
    'ear odor',
    'fleas',
    'ticks',
    'feeding orphaned or weaning puppies',
    'low energy',
    'weakness',
    'snack',
    'training reward',
    'cleaning fur',
    'cleaning paws',
    'cleaning skin',
    'urine leakage',
    'heat-cycle hygiene',
    'daily adult-cat nutrition',
    'daily dog nutrition',
    'cat toileting',
    'urine absorption',
    'odor control'
];
// Include values already used by the clinic so custom entries become reusable choices.
$categoryRows=$conn->query("SELECT DISTINCT category FROM inventory_items WHERE TRIM(COALESCE(category,''))<>'' ORDER BY category");while($categoryRows&&$row=$categoryRows->fetch_assoc()){$value=trim((string)$row['category']);if($value!==''&&!in_array($value,$inventoryCategoryOptions,true))$inventoryCategoryOptions[]=$value;}
$sicknessRows=$conn->query("SELECT sickness FROM inventory_items WHERE TRIM(COALESCE(sickness,''))<>''");while($sicknessRows&&$row=$sicknessRows->fetch_assoc()){foreach(preg_split('/\s*,\s*/',(string)$row['sickness'],-1,PREG_SPLIT_NO_EMPTY) as $value){$value=trim($value);if($value!==''&&!in_array($value,$inventorySicknessOptions,true))$inventorySicknessOptions[]=$value;}}
sort($inventoryCategoryOptions,SORT_NATURAL|SORT_FLAG_CASE);sort($inventorySicknessOptions,SORT_NATURAL|SORT_FLAG_CASE);
function inventory_sickness_from_post(array $allowed): string {
    $values=$_POST['sickness']??[];if(!is_array($values))$values=[];$selected=[];
    foreach($values as $value){$value=trim((string)$value);if($value!==''&&$value!=='__other__'&&!in_array($value,$selected,true))$selected[]=$value;}
    $other=trim((string)($_POST['sickness_other']??''));if($other!==''&&!in_array($other,$selected,true))$selected[]=$other;return implode(', ',$selected);
}

if($_SERVER['REQUEST_METHOD']==='POST'){
    verify_csrf_or_fail();
    $action=$_POST['action'] ?? (isset($_POST['move_stock'])?'move_stock':'');
    if($action==='create_item'){
        $name=trim((string)($_POST['item_name']??''));$sku=trim((string)($_POST['sku']??''));$category=trim((string)($_POST['category']??''));if($category==='__other__')$category=trim((string)($_POST['category_other']??''));if($category!==''&&!in_array($category,$inventoryCategoryOptions,true))$inventoryCategoryOptions[]=$category;$sickness=inventory_sickness_from_post($inventorySicknessOptions);$unit=trim((string)($_POST['unit']??''));$price=max(0,(float)($_POST['sale_price']??0));$stock=max(0,(int)($_POST['stock_qty']??0));$reorder=max(0,(int)($_POST['reorder_level']??5));
        if($sku==='')$sku=generate_inventory_sku($conn,$category,$name);
        $upload=upload_image_file($_FILES['product_photo']??null,'uploads/products','product');
        if($name===''||!$upload['ok'])flash('error',$name===''?'Enter an item name.':$upload['error']);else{$statusValue=$stock<=0?'out_of_stock':($stock<=$reorder?'low_stock':'available');$photo=$upload['path'];$stmt=$conn->prepare("INSERT INTO inventory_items(sku,item_name,category,sickness,product_photo,sale_price,stock_qty,unit,reorder_level,status) VALUES(?,?,?,?,?,?,?,?,?,?)");$stmt->bind_param('sssssdisis',$sku,$name,$category,$sickness,$photo,$price,$stock,$unit,$reorder,$statusValue);if($stmt->execute()){log_action($conn,'Created inventory item','inventory_item',(int)$stmt->insert_id,'Created by staff.');flash('success','Inventory item created.');}else flash('error','The inventory item could not be created.');}
    }elseif($action==='update_item'){
        $id=(int)($_POST['item_id'] ?? 0);
        $name=trim($_POST['item_name'] ?? '');
        $sku=trim($_POST['sku'] ?? '');
        $category=trim($_POST['category'] ?? '');
        if(!in_array($category,$inventoryCategoryOptions,true))$category='';
        $sickness=inventory_sickness_from_post($inventorySicknessOptions);
        $unit=trim($_POST['unit'] ?? '');
        $price=max(0,(float)($_POST['sale_price'] ?? 0));
        $stock=max(0,(int)($_POST['stock_qty'] ?? 0));
        $reorder=max(0,(int)($_POST['reorder_level'] ?? 5));
        if($name===''){
            flash('error','Enter an item name.');
        }else{
            if($sku==='')$sku=generate_inventory_sku($conn,$category,$name,$id);
            $currentStmt=$conn->prepare("SELECT product_photo FROM inventory_items WHERE id=? LIMIT 1");
            $currentStmt->bind_param('i',$id);$currentStmt->execute();$current=$currentStmt->get_result()->fetch_assoc();
            if(!$current){
                flash('error','Inventory item not found.');
            }else{
                $upload=upload_image_file($_FILES['product_photo'] ?? null,'uploads/products','product');
                if(!$upload['ok']){
                    flash('error',$upload['error']);
                }else{
                    $photo=$upload['path'] ?: $current['product_photo'];
                    $statusValue=$stock<=0?'out_of_stock':($stock<=$reorder?'low_stock':'available');
                    $stmt=$conn->prepare("UPDATE inventory_items SET sku=?,item_name=?,category=?,sickness=?,product_photo=?,sale_price=?,stock_qty=?,unit=?,reorder_level=?,status=? WHERE id=?");
                    $stmt->bind_param('sssssdisisi',$sku,$name,$category,$sickness,$photo,$price,$stock,$unit,$reorder,$statusValue,$id);
                    if($stmt->execute()){
                        log_action($conn,'Updated inventory item','inventory_item',$id,'Staff updated product identity, price, quantity, unit, and reorder data.');
                        flash('success','Inventory item updated.');
                    }else flash('error','The inventory item could not be updated.');
                }
            }
        }
    }elseif($action==='move_stock'){
        $qty=max(1,(int)($_POST['quantity'] ?? 0));
        $type=($_POST['movement_type'] ?? '')==='stock_out'?'stock_out':'stock_in';
        $item=(int)($_POST['item_id'] ?? 0);
        $currentStmt=$conn->prepare("SELECT stock_qty,item_name FROM inventory_items WHERE id=? LIMIT 1");
        $currentStmt->bind_param('i',$item);$currentStmt->execute();$current=$currentStmt->get_result()->fetch_assoc();
        if(!$current){
            flash('error','Inventory item not found.');
        }elseif($type==='stock_out' && $qty>(int)$current['stock_qty']){
            flash('error','Stock-out quantity cannot exceed available stock.');
        }else{
            $delta=$type==='stock_in'?$qty:-$qty;
            $stmt=$conn->prepare("UPDATE inventory_items SET stock_qty=GREATEST(0,stock_qty+?),status=CASE WHEN GREATEST(0,stock_qty+?)<=0 THEN 'out_of_stock' WHEN GREATEST(0,stock_qty+?)<=reorder_level THEN 'low_stock' ELSE 'available' END WHERE id=?");
            $stmt->bind_param('iiii',$delta,$delta,$delta,$item);
            $stmt->execute();
            $by=(int)current_user_id();
            $remarksChoice=trim((string)($_POST['remarks_choice']??''));$remarksOther=trim((string)($_POST['remarks_other']??''));$remarks=$remarksChoice==='__other__'?$remarksOther:$remarksChoice;
            $move=$conn->prepare("INSERT INTO inventory_movements(item_id,movement_type,quantity,remarks,created_by) VALUES(?,?,?,?,?)");
            $move->bind_param('isisi',$item,$type,$qty,$remarks,$by);
            $move->execute();
            log_action($conn,'Recorded inventory movement','inventory_item',$item,$type.' qty '.$qty);
            flash('success','Stock movement recorded.');
        }
    }
    redirect_to('staff/inventory.php');
}

$status=strtolower($_GET['status'] ?? 'all');
$allowed=['all','available','low_stock','out_of_stock','inactive'];
if(!in_array($status,$allowed,true)) $status='all';
$q=trim($_GET['q'] ?? '');
[$page,$perPage,$offset]=pagination_values(5,5,[5]);
$where=[];
if($status!=='all') $where[]="status='".$conn->real_escape_string($status)."'";
if($q!==''){
    $safe=$conn->real_escape_string($q);
    $where[]="(item_name LIKE '%$safe%' OR category LIKE '%$safe%' OR sku LIKE '%$safe%' OR unit LIKE '%$safe%')";
}
$whereSql=$where?'WHERE '.implode(' AND ',$where):'';
$totalFiltered=(int)$conn->query("SELECT COUNT(*) c FROM inventory_items $whereSql")->fetch_assoc()['c'];
$items=$conn->query("SELECT * FROM inventory_items $whereSql ORDER BY FIELD(status,'out_of_stock','low_stock','available','inactive'),stock_qty ASC,item_name LIMIT $perPage OFFSET $offset")->fetch_all(MYSQLI_ASSOC);
$summary=$conn->query("SELECT COUNT(*) total_items,COALESCE(SUM(stock_qty),0) total_stock,SUM(status='available') available,SUM(status='low_stock') low_stock,SUM(status='out_of_stock') out_stock,SUM(status='inactive') inactive FROM inventory_items")->fetch_assoc();
$moveItems=$conn->query("SELECT id,item_name,stock_qty,unit FROM inventory_items WHERE status!='inactive' ORDER BY item_name");
$allInventoryItems=$conn->query("SELECT * FROM inventory_items ORDER BY FIELD(status,'out_of_stock','low_stock','available','inactive'),item_name")->fetch_all(MYSQLI_ASSOC);
$categorySummary=$conn->query("SELECT COALESCE(NULLIF(category,''),'Uncategorized') category,COUNT(*) item_count,COALESCE(SUM(stock_qty),0) stock_count FROM inventory_items GROUP BY COALESCE(NULLIF(category,''),'Uncategorized') ORDER BY stock_count DESC,category")->fetch_all(MYSQLI_ASSOC);
$lowItems=array_values(array_filter($allInventoryItems,fn($item)=>$item['status']==='low_stock'));
$outItems=array_values(array_filter($allInventoryItems,fn($item)=>$item['status']==='out_of_stock'));
function staff_inventory_detail_list(array $items): string {
    if(!$items)return '<div class="empty-state compact"><p>No matching products.</p></div>';
    $html='<div class="inventory-detail-list">';
    foreach($items as $item){
        $html.='<a class="dashboard-detail-entry" href="'.e(app_url('staff/inventory.php?edit_item='.$item['id'].'#currentInventory')).'"><span><b>'.e($item['item_name']).'</b><small>'.e($item['category']?:'Uncategorized').' · '.intval($item['stock_qty']).' in stock</small><em>Reorder level: '.intval($item['reorder_level']).'</em></span><strong>Edit</strong></a>';
    }
    return $html.'</div>';
}
$categoryHtml='<div class="inventory-detail-list">';
foreach($categorySummary as $category)$categoryHtml.='<div class="inventory-summary-line"><span><b>'.e($category['category']).'</b><small>'.intval($category['item_count']).' product'.((int)$category['item_count']===1?'':'s').'</small></span><strong>'.intval($category['stock_count']).' units</strong></div>';
$categoryHtml.='</div>';
$lowHtml='<div class="inventory-restock-notice low-stock-notice"><b>Low stock</b><span>These products have reached their reorder level and should be restocked soon.</span></div>'.staff_inventory_detail_list($lowItems);
$outHtml='<div class="inventory-restock-notice out-stock-notice"><b>Out of stock</b><span>These products are unavailable for sale. Staff should restock them as soon as possible.</span></div>'.staff_inventory_detail_list($outItems);

$title='Inventory Updates';
include "../includes/header.php";
include "../includes/navbar.php";
?>
<div class="layout"><?php include "../includes/staff_sidebar.php"; ?>
<main class="content staff-inventory-page admin-inventory-page inventory-page" id="mainContent">
<header class="page-heading"><div><span class="eyebrow">Clinic operations</span><h1>Inventory</h1><p>Add products, record stock movements, and review current availability.</p></div><div class="heading-actions inventory-heading-actions"><button class="button-primary" type="button" data-inventory-workbench-open="add"><?=ui_icon('plus')?>Add inventory item</button><button class="button-secondary" type="button" data-inventory-workbench-open="move"><?=ui_icon('refresh')?>Stock adjustment</button><a class="button-secondary" href="<?=app_url('staff/pos.php')?>"><?=ui_icon('cart')?>Open POS</a></div></header>
<?php if($m=flash('success')):?><div class="alert alert-success" role="status"><?=e($m)?></div><?php endif;?><?php if($m=flash('error')):?><div class="alert alert-danger" role="alert"><?=e($m)?></div><?php endif;?>

<section class="metric-grid"><a class="metric-card" href="#currentInventory"><span class="metric-icon"><?=ui_icon('package')?></span><div><small>Items</small><strong><?=intval($summary['total_items'])?></strong><p>Go to the current inventory list.</p></div></a><button type="button" class="metric-card" data-record-detail='<?=e(json_encode(['title'=>'Total stock overview','eyebrow'=>'Inventory by category','html'=>$categoryHtml,'dialog_class'=>'inventory-category-detail','hide_footer'=>true]))?>'><span class="metric-icon info"><?=ui_icon('layers')?></span><div><small>Total stock</small><strong><?=intval($summary['total_stock'])?></strong><p>See the categories that make up total stock.</p></div></button><button type="button" class="metric-card" data-record-detail='<?=e(json_encode(['title'=>'Low-stock products','eyebrow'=>'Restock review','html'=>$lowHtml,'dialog_class'=>'inventory-low-stock-detail','hide_footer'=>true]))?>'><span class="metric-icon warning"><?=ui_icon('alert')?></span><div><small>Low stock</small><strong><?=intval($summary['low_stock'])?></strong><p>Review products and notify staff.</p></div></button><button type="button" class="metric-card" data-record-detail='<?=e(json_encode(['title'=>'Out-of-stock products','eyebrow'=>'Urgent restock review','html'=>$outHtml,'dialog_class'=>'inventory-out-stock-detail','hide_footer'=>true]))?>'><span class="metric-icon danger"><?=ui_icon('x')?></span><div><small>Out of stock</small><strong><?=intval($summary['out_stock'])?></strong><p>Review unavailable products and alert staff.</p></div></button></section>

<div class="management-grid-two equal-height-cards inventory-workbench" id="inventoryWorkbench" data-inventory-workbench>
<section class="surface-card management-form-card" data-inventory-pane="add"><div class="section-heading compact"><div><span class="eyebrow">New item</span><h2>Add Inventory Item</h2></div><button class="button-secondary" type="button" data-inventory-workbench-close>Close</button></div><form method="POST" enctype="multipart/form-data" class="form-stack"><?=csrf_field()?><input type="hidden" name="action" value="create_item"><div class="form-grid-two"><label><span class="field-label-row"><span>SKU or code</span><small>Optional</small></span><input class="form-control" name="sku"></label><label>Item name<input class="form-control" name="item_name" required></label><label><span class="field-label-row"><span>Category</span><small>Optional</small></span><select class="form-select" name="category"><option value="">Select category</option><?php foreach($inventoryCategoryOptions as $option):?><option value="<?=e($option)?>"><?=e($option)?></option><?php endforeach;?><option value="__other__">Other</option></select><input class="form-control inventory-other-input" name="category_other" data-inventory-category-other placeholder="Enter another category" hidden></label><label>Selling price<input class="form-control" type="number" min="0" step="0.01" name="sale_price" value="0.00" required></label><label>Unit<input class="form-control" name="unit" placeholder="bottle, pack, box, piece" required></label><label>Initial stock<input class="form-control" type="number" min="0" name="stock_qty" required></label><label><span class="field-label-row"><span>Reorder level</span><small>Low-stock alert starts at or below this quantity</small></span><input class="form-control" type="number" min="0" name="reorder_level" required></label><label><span class="field-label-row"><span>Product photo</span><small>Optional</small></span><input class="form-control" type="file" name="product_photo" accept="image/jpeg,image/png,image/webp"></label></div><fieldset class="inventory-sickness-field"><legend><span>Sickness</span><small>Optional</small></legend><div class="inventory-sickness-options"><?php foreach($inventorySicknessOptions as $option):?><label class="inventory-sickness-option"><input type="checkbox" name="sickness[]" value="<?=e($option)?>"><span><?=e($option)?></span></label><?php endforeach;?><label class="inventory-sickness-option"><input type="checkbox" name="sickness[]" value="__other__" data-inventory-sickness-other-toggle><span>Other</span></label></div><input class="form-control inventory-other-input" name="sickness_other" data-inventory-sickness-other placeholder="Enter another sickness or use" hidden></fieldset><button class="button-primary" type="submit"><?=ui_icon('plus')?>Add inventory item</button></form></section>
<section class="surface-card management-form-card" id="stockMovement" data-inventory-pane="move"><div class="section-heading compact"><div><span class="eyebrow">Movement</span><h2>Stock adjustment</h2></div><button class="button-secondary" type="button" data-inventory-workbench-close>Close</button></div><form method="POST" class="form-stack"><?=csrf_field()?><input type="hidden" name="action" value="move_stock"><label>Inventory item<select class="form-select" name="item_id" id="staffMovementItem" required><?php while($i=$moveItems->fetch_assoc()):?><option value="<?=$i['id']?>"><?=e($i['item_name'])?></option><?php endwhile;?></select></label><div class="form-grid-two"><label>Movement<select class="form-select" name="movement_type"><option value="stock_in">Stock in</option><option value="stock_out">Stock out</option></select></label><label>Quantity<input class="form-control" id="staffMovementQuantity" name="quantity" type="number" min="1" required></label></div><label>Reason or reference<select class="form-select" name="remarks_choice" data-inventory-remarks required><option value="">Select reason</option><option value="Delivery">Delivery</option><option value="Damaged item">Damaged item</option><option value="Clinic use">Clinic use</option><option value="Correction">Correction</option><option value="__other__">Other</option></select><input class="form-control inventory-other-input" name="remarks_other" data-inventory-remarks-other placeholder="Enter another reason or reference" hidden></label><button class="button-primary" type="submit"><?=ui_icon('refresh')?>Record adjustment</button></form></section>
</div>
<nav class="filter-tabs compact inventory-status-tabs" aria-label="Inventory status categories"><?php foreach(['all'=>'All','available'=>'Available','low_stock'=>'Low stock','out_of_stock'=>'Out of stock'] as $stockKey=>$stockLabel):$count=$stockKey==='all'?($summary['total_items']??0):($stockKey==='out_of_stock'?($summary['out_stock']??0):($summary[$stockKey]??0));?><a class="filter-tab status-<?=e($stockKey)?> <?=$status===$stockKey?'active':''?>" href="?status=<?=e($stockKey)?>#currentInventory"><?=e($stockLabel)?><b><?=intval($count)?></b></a><?php endforeach;?></nav>
<section class="surface-card management-table-card inventory-current-card" id="currentInventory"><div class="section-heading"><div><span class="eyebrow">Current inventory</span><h2>Products and Stock Levels</h2></div></div><?php if(!$items):?><div class="empty-state compact"><p>No inventory items found.</p></div><?php else:?><div class="inventory-overview-table" role="table" aria-label="Current inventory"><div class="inventory-overview-head" role="row"><span>Item</span><span>Category</span><span>Price</span><span>Stock</span><span>Restock when stock is</span><span>Status</span></div><?php foreach($items as $r):?><div class="inventory-overview-row stock-<?=e($r['status'])?>" role="row" data-item-id="<?=intval($r['id'])?>" data-edit-inventory='<?=e(json_encode($r))?>' tabindex="0" aria-label="Edit <?=e($r['item_name'])?>"><span class="inventory-overview-item"><i><?=ui_icon('package')?></i><em><b><?=e($r['item_name'])?></b><small><?=e($r['sku']?:'No SKU')?></small></em></span><span><?=e($r['category']?:'Uncategorized')?></span><span><b>₱<?=number_format((float)$r['sale_price'],2)?></b></span><span><?=intval($r['stock_qty'])?></span><span><?=intval($r['reorder_level'])?> or fewer</span><span><?=badge($r['status'])?></span></div><?php endforeach;?></div><?php endif;?><?=render_pagination($page,$perPage,$totalFiltered,['status'=>$status,'q'=>$q,'_anchor'=>'currentInventory'])?></section>

<section class="calendar-dialog" id="staffInventoryEditDialog" aria-hidden="true"><div class="dialog-scrim" data-close-inventory-edit></div><div class="dialog-card inventory-edit-dialog"><header><div><span class="eyebrow inventory-edit-eyebrow">Edit Inventory Item</span><p id="staffInventoryEditTitle">Inventory item</p></div><button class="icon-button" type="button" data-close-inventory-edit><?=ui_icon('x')?></button></header><form method="POST" enctype="multipart/form-data" class="form-stack inventory-edit-form"><?=csrf_field()?><input type="hidden" name="action" value="update_item"><input type="hidden" name="item_id" id="staffInventoryEditId"><div class="inventory-edit-scroll-region"><div class="form-grid-two"><label>SKU<input class="form-control" name="sku" id="staffInventoryEditSku"></label><label>Item name<input class="form-control" name="item_name" id="staffInventoryEditName" required></label><label><span class="field-label-row"><span>Category</span><small>Optional</small></span><select class="form-select" name="category" id="staffInventoryEditCategory"><option value="">Select category</option><?php foreach($inventoryCategoryOptions as $option):?><option value="<?=e($option)?>"><?=e($option)?></option><?php endforeach;?></select></label><label>Selling price<input class="form-control" type="number" min="0" step="0.01" name="sale_price" id="staffInventoryEditPrice" required></label><label>Unit<input class="form-control" name="unit" id="staffInventoryEditUnit" placeholder="bottle, pack, box, piece" required></label><label>Current stock<input class="form-control" type="number" min="0" name="stock_qty" id="staffInventoryEditStock" required></label><label><span class="field-label-row"><span>Reorder level</span><small>Low-stock alert starts at or below this quantity</small></span><input class="form-control" type="number" min="0" name="reorder_level" id="staffInventoryEditReorder" required></label><label>Replace photo<input class="form-control" type="file" name="product_photo" accept="image/jpeg,image/png,image/webp"></label></div><fieldset class="inventory-sickness-field"><legend><span>Sickness</span><small>Optional</small></legend><div class="inventory-sickness-options" id="staffInventoryEditSickness"><?php foreach($inventorySicknessOptions as $option):?><label class="inventory-sickness-option"><input type="checkbox" name="sickness[]" value="<?=e($option)?>"><span><?=e($option)?></span></label><?php endforeach;?></div></fieldset></div><div class="form-actions"><button class="button-secondary" type="button" data-close-inventory-edit>Cancel</button><button class="button-primary" type="submit">Save product details</button></div></form></div></section>
<script>(()=>{const dialog=document.getElementById('staffInventoryEditDialog');if(!dialog)return;const open=()=>{dialog.classList.add('open');dialog.setAttribute('aria-hidden','false');document.body.classList.add('overlay-open')},close=()=>{dialog.classList.remove('open');dialog.setAttribute('aria-hidden','true');document.body.classList.remove('overlay-open')},fill=card=>{const item=JSON.parse(card.dataset.editInventory);document.getElementById('staffInventoryEditTitle').textContent=item.item_name;document.getElementById('staffInventoryEditId').value=item.id;document.getElementById('staffInventoryEditSku').value=item.sku||'';document.getElementById('staffInventoryEditName').value=item.item_name||'';document.getElementById('staffInventoryEditCategory').value=item.category||'';const sicknessBox=document.getElementById('staffInventoryEditSickness'),activeSickness=(item.sickness||'').split(/\s*,\s*/).filter(Boolean);sicknessBox?.querySelectorAll('input[type=\"checkbox\"]').forEach(cb=>cb.checked=activeSickness.includes(cb.value));if(sicknessBox){[...sicknessBox.querySelectorAll('.inventory-sickness-option')].sort((a,b)=>Number(b.querySelector('input').checked)-Number(a.querySelector('input').checked)).forEach(label=>sicknessBox.appendChild(label));}document.getElementById('staffInventoryEditPrice').value=Number(item.sale_price||0).toFixed(2);document.getElementById('staffInventoryEditUnit').value=item.unit||'';document.getElementById('staffInventoryEditStock').value=item.stock_qty||0;document.getElementById('staffInventoryEditReorder').value=item.reorder_level||0;open()};document.querySelectorAll('[data-close-inventory-edit]').forEach(button=>button.addEventListener('click',close));document.querySelectorAll('[data-edit-inventory]').forEach(row=>{row.addEventListener('click',event=>{if(event.target.closest('.inventory-row-action'))return;fill(row)});row.addEventListener('keydown',event=>{if((event.key==='Enter'||event.key===' ')&&!event.target.closest('.inventory-row-action')){event.preventDefault();fill(row)}})});const editId=new URL(location.href).searchParams.get('edit_item');if(editId){const card=document.querySelector(`[data-item-id="${CSS.escape(editId)}"][data-edit-inventory]`);if(card)setTimeout(()=>fill(card),80)}const moveId=new URL(location.href).searchParams.get('move_item');if(moveId){const select=document.getElementById('staffMovementItem'),quantity=document.getElementById('staffMovementQuantity'),section=document.getElementById('stockMovement');if(select&&[...select.options].some(option=>option.value===moveId)){select.value=moveId;setTimeout(()=>{section?.scrollIntoView({block:'center'});quantity?.focus()},100)}}})();</script>
<script id="inventoryOtherFields">(()=>{const category=document.querySelector('select[name="category"]'),categoryOther=document.querySelector('[data-inventory-category-other]'),sicknessToggle=document.querySelector('[data-inventory-sickness-other-toggle]'),sicknessOther=document.querySelector('[data-inventory-sickness-other]'),remarks=document.querySelector('[data-inventory-remarks]'),remarksOther=document.querySelector('[data-inventory-remarks-other]');const sync=()=>{if(categoryOther){const show=category?.value==='__other__';categoryOther.hidden=!show;categoryOther.required=!!show;if(!show)categoryOther.value='';}if(sicknessOther){const show=!!sicknessToggle?.checked;sicknessOther.hidden=!show;sicknessOther.required=show;if(!show)sicknessOther.value='';}if(remarksOther){const show=remarks?.value==='__other__';remarksOther.hidden=!show;remarksOther.required=!!show;if(!show)remarksOther.value='';}};category?.addEventListener('change',sync);sicknessToggle?.addEventListener('change',sync);remarks?.addEventListener('change',sync);sync();})();</script>
</main></div><?php include "../includes/footer.php"; ?>
