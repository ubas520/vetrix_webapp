<?php
require_once "../config/database.php";
require_once "../includes/functions.php";
require_role("staff");
ensure_pos_product_schema($conn);
ensure_inventory_skus($conn);

require_once '../includes/product_order_pos_checkout.php';
if ($_SERVER['REQUEST_METHOD'] === 'POST') {
    verify_csrf_or_fail();
    try {
        if (($_POST['payment_method'] ?? '') === 'qr' && !clinic_payment_qr_path()) throw new ProductOrderError('QR payment is not configured yet. Ask an administrator to upload the clinic payment QR.', 422);
        $sale = product_order_pos_checkout($conn, (int) current_user_id(), $_POST);
        flash('success', $sale['status'] === 'cancelled' ? 'Cancelled sale recorded. Stock was not deducted.' : 'POS sale saved. Available stock was updated.');
        if ($sale['status'] === 'paid') redirect_to('staff/receipt.php?transaction_id=' . $sale['id'] . '&from=pos');
    } catch (ProductOrderError $e) { flash('error', $e->getMessage()); }
    catch (Throwable $e) { error_log($e->getMessage()); flash('error', 'The sale could not be saved. Refresh and try again.'); }
    redirect_to('staff/pos.php');
}

$title='Point of Sale';
include "../includes/header.php";
include "../includes/navbar.php";
$categoryRows=$conn->query("SELECT DISTINCT category FROM inventory_items WHERE status!='inactive' AND category IS NOT NULL AND category<>'' ORDER BY category");
$categoryOptions=[];while($categoryRows&&$cat=$categoryRows->fetch_assoc())$categoryOptions[]=$cat['category'];
$salesCategory=trim((string)($_GET['sales_category']??'all'));
if($salesCategory!=='all'&&!in_array($salesCategory,$categoryOptions,true))$salesCategory='all';
[$page,$perPage,$offset]=pagination_values(5,20,[5,10,20]);
$transactionSelect="SELECT t.*,c.full_name client_name,h.full_name handled_by_name,pi.item_summary,pi.item_count FROM pos_transactions t LEFT JOIN users c ON t.client_id=c.id LEFT JOIN users h ON t.handled_by=h.id LEFT JOIN (SELECT transaction_id,GROUP_CONCAT(CONCAT(quantity,' x ',item_name) ORDER BY id SEPARATOR ', ') item_summary,COALESCE(SUM(quantity),0) item_count FROM pos_transaction_items GROUP BY transaction_id) pi ON pi.transaction_id=t.id";
if($salesCategory==='all'){
    $totalTransactions=(int)$conn->query("SELECT COUNT(*) c FROM pos_transactions")->fetch_assoc()['c'];
    $rows=$conn->query($transactionSelect." ORDER BY t.transaction_date DESC,t.id DESC LIMIT ".intval($perPage)." OFFSET ".intval($offset));
}else{
    $categoryWhere=" WHERE EXISTS (SELECT 1 FROM pos_transaction_items pci JOIN inventory_items pii ON pii.id=pci.item_id WHERE pci.transaction_id=t.id AND COALESCE(NULLIF(pii.category,''),'Uncategorized')=?)";
    $countStmt=$conn->prepare("SELECT COUNT(*) c FROM pos_transactions t".$categoryWhere);$countStmt->bind_param('s',$salesCategory);$countStmt->execute();$totalTransactions=(int)$countStmt->get_result()->fetch_assoc()['c'];
    $rowsStmt=$conn->prepare($transactionSelect.$categoryWhere." ORDER BY t.transaction_date DESC,t.id DESC LIMIT ".intval($perPage)." OFFSET ".intval($offset));$rowsStmt->bind_param('s',$salesCategory);$rowsStmt->execute();$rows=$rowsStmt->get_result();
}
$products=$conn->query("SELECT i.*,COALESCE(s.sold_qty,0) sold_qty FROM inventory_items i LEFT JOIN (SELECT item_id,SUM(quantity) sold_qty FROM pos_transaction_items GROUP BY item_id) s ON s.item_id=i.id WHERE i.status!='inactive' ORDER BY sold_qty DESC, i.item_name ASC");
$productSummary=$conn->query("SELECT COUNT(*) products,SUM(status!='inactive') active_products,SUM(status='low_stock') low_stock,SUM(status='out_of_stock') out_stock FROM inventory_items")->fetch_assoc();
$salesSummary=$conn->query("SELECT COUNT(*) total_transactions,COALESCE(SUM(CASE WHEN payment_status='paid' THEN total_amount ELSE 0 END),0) total_sales FROM pos_transactions")->fetch_assoc();
$recentTransactionCount=$rows ? (int)$rows->num_rows : 0;
$clinicPaymentQr=clinic_payment_qr_path();
$clinicPaymentQrSrc=clinic_payment_qr_src();
?>
<div class="layout"><?php include "../includes/staff_sidebar.php"; ?>
<main class="content staff-pos-page" id="mainContent">
<header class="page-heading"><div><span class="eyebrow">Clinic cashier</span><h1>Point of Sale</h1></div><div class="heading-actions"><a class="button-secondary" href="<?=app_url('staff/receipts.php')?>"><?=ui_icon('receipt')?>Receipts</a><a class="button-secondary" href="<?=app_url('staff/inventory.php')?>"><?=ui_icon('inventory')?>Inventory</a></div></header>
<?php if($m=flash('success')):?><div class="alert alert-success"><?=e($m)?></div><?php endif;?>
<?php if($m=flash('error')):?><div class="alert alert-warning"><?=e($m)?></div><?php endif;?>
<section class="metric-grid"><article class="metric-card"><span class="metric-icon"><?=ui_icon('cart')?></span><div><small>POS products</small><strong><?=intval($productSummary['products'])?></strong><p>Products configured by administrators.</p></div></article><article class="metric-card"><span class="metric-icon success"><?=ui_icon('check')?></span><div><small>Available in POS</small><strong><?=intval($productSummary['active_products'])?></strong><p>Products visible to clinic cashiers.</p></div></article><article class="metric-card"><span class="metric-icon info"><?=ui_icon('coins')?></span><div><small>Paid sales</small><strong>₱<?=number_format((float)$salesSummary['total_sales'],2)?></strong><p>Total paid transaction value.</p></div></article><article class="metric-card"><span class="metric-icon warning"><?=ui_icon('receipt')?></span><div><small>Transactions</small><strong><?=intval($salesSummary['total_transactions'])?></strong><p>Recorded cashier transactions.</p></div></article></section>

<form method="POST" id="posSaleForm" data-confirm-message="Save this POS sale and deduct stock from inventory?">
<?= csrf_field() ?>
<div class="pos-cashier-layout pos-management-grid equal-height-cards">
    <section class="surface-card pos-products-panel pos-product-browser">
        <div class="section-heading"><div><span class="eyebrow">Product preview</span><h2>Products available for sale</h2></div></div>
        <?php $categoryPalette=['#4f7da8','#b36a86','#a67c2c','#6d7f9a','#8570a6','#4d8791']; $categoryIndex=0; ?><div class="filter-tabs compact pos-category-buttons" id="posCategoryButtons" aria-label="Product categories"><button type="button" class="filter-tab active" style="--category-accent:#4f7da8" data-pos-category-button="all">All</button><?php foreach($categoryOptions as $category):$categoryAccent=$categoryPalette[$categoryIndex++%count($categoryPalette)];?><button type="button" class="filter-tab" style="--category-accent:<?=e($categoryAccent)?>" data-pos-category-button="<?=e(strtolower($category))?>"><?=e($category)?></button><?php endforeach;?><button type="button" class="filter-tab status-out_of_stock" style="--category-accent:#b4232f" data-pos-category-button="unavailable">Unavailable</button></div>
        <div class="data-search-bar pos-product-search pos-search-row">
            <input class="form-control" id="posProductSearch" type="search" placeholder="Search product name or SKU">
            <select class="form-select" id="posNameSort" aria-label="Sort product names"><option value="popular">Most purchased</option><option value="az">Name A–Z</option><option value="za">Name Z–A</option></select>
            <button class="button-primary" id="posProductSearchBtn" type="button">Search</button><button class="button-secondary" id="posProductClearBtn" type="button">Clear</button>
        </div>
        <div class="pos-product-admin-grid" id="posProductGrid">
            <?php if($products->num_rows===0): ?><div class="empty-state"><span><?=ui_icon('package')?></span><h3>No active POS products</h3><p>Ask an administrator to add products in Inventory.</p></div><?php endif; ?>
            <?php while($p=$products->fetch_assoc()): $disabled=(int)$p['stock_qty']<=0; $effectiveStatus=$disabled?'out_of_stock':(((int)$p['stock_qty']<=(int)$p['reorder_level']||$p['status']==='low_stock')?'low_stock':'available'); $stockLabel=ucwords(str_replace('_',' ',$effectiveStatus)); $category=$p['category']?:'Uncategorized'; $detail=['title'=>$p['item_name'],'eyebrow'=>'Product details','fields'=>['Category'=>$category,'Price'=>'₱'.number_format((float)$p['sale_price'],2),'Stock'=>$p['stock_qty'].' '.($p['unit']?:'pcs'),'Status'=>$stockLabel,'SKU'=>$p['sku']?:'No SKU']]; ?>
            <article class="pos-admin-product-card stock-<?=e($effectiveStatus)?>" data-pos-admin-product data-pos-cashier-product data-name="<?=e($p['item_name'])?>" data-category="<?=e($category)?>" data-sku="<?=e($p['sku']?:'')?>" data-sold="<?=intval($p['sold_qty']??0)?>" data-status="<?=e($effectiveStatus)?>">
                <div class="pos-admin-product-photo"><?php if(!empty($p['product_photo'])):?><img src="<?=e(app_url($p['product_photo']))?>" alt="<?=e($p['item_name'])?>"><?php else:?><span><?=ui_icon('package')?></span><?php endif;?></div>
                <div class="pos-admin-product-copy"><small><?=e($category)?></small><h3><?=e($p['item_name'])?></h3><p><?=e($p['sku']?:'No SKU')?> · <?=intval($p['stock_qty'])?> in stock</p><span class="pos-stock-indicator status-<?=e($effectiveStatus)?>"><?=e($disabled?'Out of stock':$stockLabel)?></span><strong>₱<?=number_format((float)$p['sale_price'],2)?></strong></div>
                <div class="pos-admin-product-actions"><button class="button-primary pos-add-product" type="button" data-id="<?=$p['id']?>" data-name="<?=e($p['item_name'])?>" data-category="<?=e(strtolower($category))?>" data-price="<?=e($p['sale_price'])?>" data-stock="<?=e($p['stock_qty'])?>" <?=$disabled?'disabled':''?>><?=ui_icon('cart')?><?=$disabled?'Unavailable':'Add to cart'?></button></div>
            </article>
            <?php endwhile;?>
        </div>
    </section>

    <aside class="surface-card pos-cart-panel">
        <div class="section-heading compact"><div><span class="eyebrow">Current sale</span><h2>Cart Summary</h2></div></div>
        <label>Client</label>
        <select class="form-select mb-3" name="client_id"><option value="">Walk-in / No linked client</option><?php $clients=$conn->query("SELECT id,full_name FROM users WHERE role='client' ORDER BY full_name"); while($c=$clients->fetch_assoc()): ?><option value="<?=$c['id']?>"><?=e($c['full_name'])?></option><?php endwhile;?></select>
        <div id="posCartNotice" class="pos-cart-notice" hidden><?=ui_icon('cart')?> Product added to cart.</div><div id="posCartItems" class="pos-cart-items"><div class="text-muted py-3">No products selected yet.</div></div>
        <div id="posHiddenInputs"></div>
        <div class="pos-cart-total"><span>Total</span><strong id="posTotal">₱0.00</strong></div>
        <label>Payment method</label>
        <select class="form-select mb-2" id="posPaymentMethod" name="payment_method"><option value="cash">Cash</option><option value="qr"<?=$clinicPaymentQr?'':' disabled'?>>QR payment<?=$clinicPaymentQr?'':' · not configured'?></option></select>
        <div id="posCashPaymentFields">
            <label>Cash Received</label>
            <input class="form-control mb-2" id="cashReceived" name="cash_received" type="number" min="0" step="0.01" placeholder="Optional">
            <div class="pos-change-row"><span>Change</span><b id="posChange">₱0.00</b></div>
            <label>Payment Status</label>
            <select class="form-select mb-2" name="payment_status"><option value="paid">Paid</option><option value="pending">Pending</option><option value="cancelled">Cancelled</option></select>
        </div>
        <div class="pos-qr-payment-panel" id="posQrPaymentPanel" hidden><?php if($clinicPaymentQr):?><span>Scan to pay</span><img src="<?=e($clinicPaymentQrSrc)?>" alt="Clinic QR payment code"><small>QR payments are recorded as paid when the sale is completed and the receipt is generated automatically.</small><?php else:?><small>Ask an administrator to upload the clinic payment QR.</small><?php endif;?></div>
        <label>Notes</label>
        <textarea class="form-control mb-3" name="notes" placeholder="Optional receipt note"></textarea>
        <div class="pos-cart-submit-actions"><button class="button-secondary w-100" id="clearCartBtn" type="button" disabled>Clear Cart</button><button class="button-primary w-100" id="saveSaleBtn" disabled>Checkout</button></div>
    </aside>
</div>
</form>

<section class="surface-card management-table-card pos-sales-history pos-records-card mt-4" id="posSalesHistory">
    <div class="section-heading"><div><span class="eyebrow">Sales history</span><h2>Recent transactions</h2></div><form method="GET" class="pos-sales-controls"><select class="form-select" name="sales_category" aria-label="Filter transactions by category" onchange="this.form.submit()"><option value="all">All categories</option><?php foreach($categoryOptions as $category):?><option value="<?=e($category)?>" <?=$salesCategory===$category?'selected':''?>><?=e($category)?></option><?php endforeach;?></select><label class="entries-select pos-sales-show">Show<select class="form-select unified-show-select" name="per_page" aria-label="Show transactions" onchange="this.form.submit()"><?=render_per_page_options($perPage,[5,10,20])?></select></label></form></div>
    <div class="table-scroll-only compact-history">
        <table class="data-table">
            <thead><tr><th>Date</th><th>Client</th><th>Amount</th><th>Items</th><th>Status</th><th>Handled by</th></tr></thead>
            <tbody>
            <?php if($recentTransactionCount===0): ?>
                <tr><td colspan="6" class="empty-cell">No POS transactions have been recorded yet.</td></tr>
            <?php endif; ?>
            <?php while($r=$rows->fetch_assoc()): $displayDate=date('M d, Y h:i A',strtotime($r['transaction_date'])); $detail=['title'=>'POS transaction #'.$r['id'],'eyebrow'=>'Recent payment record','fields'=>['Date'=>$displayDate,'Client'=>$r['client_name']?:'Walk-in','Amount'=>'PHP '.number_format((float)$r['total_amount'],2),'Items'=>$r['item_summary']?:'No line items recorded','Total quantity'=>(int)($r['item_count']??0),'Payment status'=>ucfirst($r['payment_status']),'Handled by'=>$r['handled_by_name']?:'Clinic staff','Notes'=>$r['notes']?:'No note']]; ?>
                <tr data-transaction-id="<?=intval($r['id'])?>" data-record-detail='<?=e(json_encode($detail))?>'><td><?=date('M d, h:i A',strtotime($r['transaction_date']))?></td><td><?=e($r['client_name'] ?: 'Walk-in')?></td><td>₱<?=number_format((float)$r['total_amount'],2)?></td><td><?=intval($r['item_count']??0)?></td><td><?=badge($r['payment_status'])?></td><td><?=e($r['handled_by_name']?:'Clinic staff')?></td></tr>
            <?php endwhile;?>
            </tbody>
        </table>
    </div>
    <?=render_pagination($page,$perPage,$totalTransactions,['sales_category'=>$salesCategory,'per_page'=>per_page_value($perPage),'_anchor'=>'posSalesHistory'])?>
</section>
</main></div>
<script>
(function(){
    const cart = new Map();
    const cards = document.querySelectorAll('.pos-add-product');
    const cartItems = document.getElementById('posCartItems');
    const hidden = document.getElementById('posHiddenInputs');
    const totalEl = document.getElementById('posTotal');
    const changeEl = document.getElementById('posChange');
    const cashInput = document.getElementById('cashReceived');
    const paymentMethod = document.getElementById('posPaymentMethod');
    const cashPaymentFields = document.getElementById('posCashPaymentFields');
    const qrPaymentPanel = document.getElementById('posQrPaymentPanel');
    const saveBtn = document.getElementById('saveSaleBtn');
    const clearCartBtn = document.getElementById('clearCartBtn');
    const cartNotice = document.getElementById('posCartNotice');
    const clearFilterBtn = document.getElementById('posProductClearBtn');
    const searchBtn = document.getElementById('posProductSearchBtn');
    const search = document.getElementById('posProductSearch');
    const categoryButtons = [...document.querySelectorAll('[data-pos-category-button]')];
    let activeCategory = 'all';
    const sort = document.getElementById('posNameSort');
    const grid = document.getElementById('posProductGrid');
    const peso = new Intl.NumberFormat('en-PH',{style:'currency',currency:'PHP'});

    function renderCart(){
        let total = 0;
        cartItems.innerHTML = '';
        hidden.innerHTML = '';
        if(cart.size === 0){
            cartItems.innerHTML = '<div class="text-muted py-3">No products selected yet.</div>';
            saveBtn.disabled = true;
            if(clearCartBtn) clearCartBtn.disabled = true;
        } else {
            saveBtn.disabled = false;
            if(clearCartBtn) clearCartBtn.disabled = false;
        }
        cart.forEach(item => {
            total += item.price * item.qty;
            const row = document.createElement('div');
            row.className = 'pos-cart-item';
            row.innerHTML = `<div><b>${item.name}</b><small>${peso.format(item.price)} each</small></div><div class="pos-qty-controls"><button type="button" data-act="minus" data-id="${item.id}">−</button><span>${item.qty}</span><button type="button" data-act="plus" data-id="${item.id}">+</button></div><strong>${peso.format(item.price * item.qty)}</strong>`;
            cartItems.appendChild(row);
            hidden.insertAdjacentHTML('beforeend', `<input type="hidden" name="item_id[]" value="${item.id}"><input type="hidden" name="quantity[]" value="${item.qty}">`);
        });
        totalEl.textContent = peso.format(total);
        const cash = parseFloat(cashInput?.value || '0');
        changeEl.textContent = peso.format(Math.max(0, cash - total));
    }

    cards.forEach(card => card.addEventListener('click', () => {
        const id = card.dataset.id;
        const stock = parseInt(card.dataset.stock || '0',10);
        if(stock <= 0) return;
        const current = cart.get(id) || {id, name: card.dataset.name, price: parseFloat(card.dataset.price || '0'), qty:0, stock};
        if(current.qty < stock) current.qty++;
        cart.set(id,current);
        if(cartNotice){cartNotice.hidden=false;clearTimeout(window.__posNoticeTimer);window.__posNoticeTimer=setTimeout(()=>cartNotice.hidden=true,1500);}
        renderCart();
    }));

    cartItems.addEventListener('click', e => {
        const btn = e.target.closest('button[data-act]');
        if(!btn) return;
        const item = cart.get(btn.dataset.id);
        if(!item) return;
        if(btn.dataset.act === 'plus' && item.qty < item.stock) item.qty++;
        if(btn.dataset.act === 'minus') item.qty--;
        if(item.qty <= 0) cart.delete(item.id); else cart.set(item.id,item);
        renderCart();
    });

    function applyFilter(){
        const q = (search.value || '').toLowerCase();
        const c = activeCategory;
        cards.forEach(card => {
            const tile = card.closest('[data-pos-cashier-product]');
            const matchesCat = c === 'all' || (c === 'unavailable' ? (tile?.dataset.status||'') === 'out_of_stock' : (tile?.dataset.category || '').toLowerCase() === c);
            const haystack = ((tile?.dataset.name||'')+' '+(tile?.dataset.category||'')+' '+(tile?.dataset.sku||'')).toLowerCase();
            const matches = haystack.includes(q) && matchesCat;
            if(tile) tile.hidden = !matches;
        });
        const tiles=[...document.querySelectorAll('[data-pos-cashier-product]')];
        tiles.sort((a,b)=>{
            if(sort?.value==='az') return (a.dataset.name||'').localeCompare(b.dataset.name||'');
            if(sort?.value==='za') return (b.dataset.name||'').localeCompare(a.dataset.name||'');
            const unavailableA=(a.dataset.status||'')==='out_of_stock'?1:0, unavailableB=(b.dataset.status||'')==='out_of_stock'?1:0; return unavailableA-unavailableB || Number(b.dataset.sold||0)-Number(a.dataset.sold||0) || (a.dataset.name||'').localeCompare(b.dataset.name||'');
        }).forEach(tile=>grid?.appendChild(tile));
    }
    categoryButtons.forEach(button=>button.addEventListener('click',()=>{activeCategory=button.dataset.posCategoryButton||'all';categoryButtons.forEach(b=>{const on=b===button;b.classList.toggle('active',on);b.classList.toggle('is-active',on);b.setAttribute('aria-pressed',on?'true':'false');});applyFilter();}));
    searchBtn?.addEventListener('click', applyFilter);
    clearFilterBtn?.addEventListener('click', () => { search.value = ''; activeCategory='all'; categoryButtons.forEach(b=>{const on=(b.dataset.posCategoryButton||'')==='all';b.classList.toggle('active',on);b.classList.toggle('is-active',on);b.setAttribute('aria-pressed',on?'true':'false');}); if(sort)sort.value='popular'; applyFilter(); });
    clearCartBtn?.addEventListener('click', async () => { if(!cart.size) return; const ok=window.vetrixConfirm ? await window.vetrixConfirm('Clear all products from the current cart?','Clear cart') : true; if(ok){ cart.clear(); renderCart(); } });
    cashInput?.addEventListener('input', renderCart);
    const syncPaymentMethod=()=>{const qr=paymentMethod?.value==='qr';if(cashPaymentFields)cashPaymentFields.hidden=qr;if(qrPaymentPanel)qrPaymentPanel.hidden=!qr;if(saveBtn)saveBtn.textContent='Checkout';renderCart();};
    paymentMethod?.addEventListener('change',syncPaymentMethod);
    syncPaymentMethod();
})();
</script>
<?php include "../includes/footer.php"; ?>
