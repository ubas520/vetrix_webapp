<?php
require_once "../config/database.php";
require_once "../includes/functions.php";
require_role('admin');
ensure_pos_product_schema($conn);
ensure_inventory_skus($conn);

function inventory_restock_alert_columns(mysqli $conn): array {
    $result = $conn->query("SHOW COLUMNS FROM inventory_restock_alerts");
    if (!$result) return [];
    $columns = [];
    while ($column = $result->fetch_assoc()) $columns[(string)$column['Field']] = $column;
    return $columns;
}

function inventory_restock_alert_has_daily_unique_index(mysqli $conn): bool {
    $result = $conn->query("SHOW INDEX FROM inventory_restock_alerts");
    if (!$result) return false;
    $uniqueIndexes = [];
    while ($index = $result->fetch_assoc()) {
        if ((int)$index['Non_unique'] !== 0) continue;
        $uniqueIndexes[(string)$index['Key_name']][(int)$index['Seq_in_index']] = (string)$index['Column_name'];
    }
    foreach ($uniqueIndexes as $columns) {
        ksort($columns);
        if (array_values($columns) === ['item_id','alert_date']) return true;
    }
    return false;
}

function inventory_restock_alert_uses_innodb(mysqli $conn): bool {
    $result = $conn->query("SELECT ENGINE FROM information_schema.TABLES WHERE TABLE_SCHEMA=DATABASE() AND TABLE_NAME='inventory_restock_alerts' LIMIT 1");
    if (!$result) return false;
    $table = $result->fetch_assoc();
    return $table && strcasecmp((string)$table['ENGINE'], 'InnoDB') === 0;
}

function ensure_inventory_restock_alert_schema(mysqli $conn): bool {
    $created = $conn->query("CREATE TABLE IF NOT EXISTS inventory_restock_alerts (
        id INT AUTO_INCREMENT PRIMARY KEY,
        item_id INT NOT NULL,
        alert_date DATE NOT NULL,
        notified_by INT NULL,
        recipient_count INT UNSIGNED NOT NULL DEFAULT 0,
        created_at TIMESTAMP NOT NULL DEFAULT CURRENT_TIMESTAMP,
        UNIQUE KEY uniq_inventory_restock_alert_day (item_id, alert_date),
        KEY idx_inventory_restock_alert_notified_by (notified_by),
        CONSTRAINT inventory_restock_alerts_ibfk_1 FOREIGN KEY (item_id) REFERENCES inventory_items(id) ON DELETE CASCADE,
        CONSTRAINT inventory_restock_alerts_ibfk_2 FOREIGN KEY (notified_by) REFERENCES users(id) ON DELETE SET NULL
    ) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci");
    if (!$created || !table_exists($conn, 'inventory_restock_alerts')) return false;

    $countResult = $conn->query("SELECT COUNT(*) row_count FROM inventory_restock_alerts");
    if (!$countResult) return false;
    $rowCount = (int)($countResult->fetch_assoc()['row_count'] ?? 0);
    $columns = inventory_restock_alert_columns($conn);

    foreach (['item_id' => 'INT NOT NULL', 'alert_date' => 'DATE NOT NULL'] as $name => $definition) {
        if (isset($columns[$name])) continue;
        if ($rowCount > 0 || !$conn->query("ALTER TABLE inventory_restock_alerts ADD COLUMN `$name` $definition")) return false;
    }
    foreach (['notified_by' => 'INT NULL', 'recipient_count' => 'INT UNSIGNED NOT NULL DEFAULT 0', 'created_at' => 'TIMESTAMP NOT NULL DEFAULT CURRENT_TIMESTAMP'] as $name => $definition) {
        if (!isset($columns[$name]) && !$conn->query("ALTER TABLE inventory_restock_alerts ADD COLUMN `$name` $definition")) return false;
    }

    $columns = inventory_restock_alert_columns($conn);
    foreach (['item_id','alert_date','notified_by','recipient_count','created_at'] as $required) {
        if (!isset($columns[$required])) return false;
    }
    $itemType = strtolower((string)$columns['item_id']['Type']);
    if (!preg_match('/^(?:tinyint|smallint|mediumint|int|bigint)(?:\(\d+\))?(?: unsigned)?$/', $itemType)) {
        if ($rowCount > 0 || !$conn->query("ALTER TABLE inventory_restock_alerts MODIFY item_id INT NOT NULL")) return false;
        $itemType = 'int';
    }
    if (strtolower((string)$columns['alert_date']['Type']) !== 'date') {
        if ($rowCount > 0 || !$conn->query("ALTER TABLE inventory_restock_alerts MODIFY alert_date DATE NOT NULL")) return false;
    }

    $invalidCoreRows = $conn->query("SELECT COUNT(*) invalid_count FROM inventory_restock_alerts WHERE item_id IS NULL OR alert_date IS NULL");
    if (!$invalidCoreRows || (int)($invalidCoreRows->fetch_assoc()['invalid_count'] ?? 0) > 0) return false;
    if (strtoupper((string)$columns['item_id']['Null']) !== 'NO') {
        $safeItemType = strtoupper($itemType);
        if (!$conn->query("ALTER TABLE inventory_restock_alerts MODIFY item_id $safeItemType NOT NULL")) return false;
    }
    if (strtoupper((string)$columns['alert_date']['Null']) !== 'NO' && !$conn->query("ALTER TABLE inventory_restock_alerts MODIFY alert_date DATE NOT NULL")) return false;

    $columns = inventory_restock_alert_columns($conn);
    if (!isset($columns['item_id'],$columns['alert_date'])
        || strtoupper((string)$columns['item_id']['Null']) !== 'NO'
        || strtolower((string)$columns['alert_date']['Type']) !== 'date'
        || strtoupper((string)$columns['alert_date']['Null']) !== 'NO') return false;

    if (!inventory_restock_alert_has_daily_unique_index($conn)) {
        $duplicates = $conn->query("SELECT 1 FROM inventory_restock_alerts GROUP BY item_id,alert_date HAVING COUNT(*)>1 LIMIT 1");
        if (!$duplicates || $duplicates->num_rows > 0) return false;
        $namedIndex = $conn->query("SHOW INDEX FROM inventory_restock_alerts WHERE Key_name='uniq_inventory_restock_alert_day'");
        $indexName = $namedIndex && $namedIndex->num_rows > 0
            ? 'uniq_inventory_restock_item_date_guard'
            : 'uniq_inventory_restock_alert_day';
        if (!$conn->query("ALTER TABLE inventory_restock_alerts ADD UNIQUE KEY `$indexName` (item_id,alert_date)")) return false;
    }
    if (!inventory_restock_alert_has_daily_unique_index($conn)) return false;

    if (!inventory_restock_alert_uses_innodb($conn)) {
        if (!$conn->query("ALTER TABLE inventory_restock_alerts ENGINE=InnoDB")) return false;
    }
    if (!inventory_restock_alert_uses_innodb($conn) || !inventory_restock_alert_has_daily_unique_index($conn)) return false;

    // Preserve alerts sent before the daily-limit ledger was introduced.
    return (bool)$conn->query("INSERT IGNORE INTO inventory_restock_alerts(item_id,alert_date,notified_by,recipient_count,created_at)
        SELECT a.entity_id,DATE(a.created_at),NULL,0,MIN(a.created_at)
        FROM audit_logs a
        INNER JOIN inventory_items i ON i.id=a.entity_id
        WHERE a.action='Sent inventory item restock alert'
          AND a.entity_type='inventory_item'
          AND a.entity_id IS NOT NULL
        GROUP BY a.entity_id,DATE(a.created_at)");
}

function send_inventory_restock_alert(mysqli $conn, ?int $itemId = null, ?string $stockGroup = null): array {
    $isItemAlert = $itemId !== null && $itemId > 0;
    if (!$isItemAlert && !in_array($stockGroup, ['low_stock','out_of_stock'], true)) {
        return ['type' => 'error', 'message' => 'Choose a valid inventory restock group.'];
    }
    if (!$conn->begin_transaction()) {
        return ['type' => 'error', 'message' => 'The restock alert could not be started. Please try again.'];
    }

    if ($isItemAlert) {
        $itemStmt = $conn->prepare("SELECT id,item_name,status,stock_qty,reorder_level FROM inventory_items WHERE id=? LIMIT 1 FOR UPDATE");
        if ($itemStmt) $itemStmt->bind_param('i', $itemId);
    } else {
        $itemStmt = $conn->prepare("SELECT id,item_name,status,stock_qty,reorder_level FROM inventory_items WHERE status=? ORDER BY id FOR UPDATE");
        if ($itemStmt) $itemStmt->bind_param('s', $stockGroup);
    }
    if (!$itemStmt || !$itemStmt->execute()) {
        $conn->rollback();
        return ['type' => 'error', 'message' => 'The inventory items could not be checked. Please try again.'];
    }
    $items = $itemStmt->get_result()->fetch_all(MYSQLI_ASSOC);
    if (!$items) {
        $conn->rollback();
        $label = $stockGroup === 'out_of_stock' ? 'out-of-stock' : 'low-stock';
        return ['type' => 'error', 'message' => $isItemAlert ? 'Inventory item not found.' : 'There are no '.$label.' inventory items to notify.'];
    }
    if ($isItemAlert && !in_array((string)$items[0]['status'], ['low_stock','out_of_stock'], true)) {
        $conn->rollback();
        return ['type' => 'error', 'message' => 'This item is currently available and does not need a restock alert.'];
    }

    $actor = (int)current_user_id();
    $reservation = $conn->prepare("INSERT INTO inventory_restock_alerts(item_id,alert_date,notified_by,recipient_count) VALUES(?,CURRENT_DATE(),?,0)");
    if (!$reservation) {
        $conn->rollback();
        return ['type' => 'error', 'message' => 'The daily notification limit could not be checked. Please try again.'];
    }
    $reservedItems = [];
    $alreadyNotified = 0;
    foreach ($items as $item) {
        $reservedItemId = (int)$item['id'];
        $reservation->bind_param('ii', $reservedItemId, $actor);
        if ($reservation->execute()) {
            $reservedItems[] = $item;
            continue;
        }
        if ((int)$reservation->errno === 1062) {
            $alreadyNotified++;
            if ($isItemAlert) {
                $conn->rollback();
                return ['type' => 'error', 'message' => 'Staff were already notified about '.$item['item_name'].' today. The once-a-day limit resets tomorrow.'];
            }
            continue;
        }
        $conn->rollback();
        return ['type' => 'error', 'message' => 'The daily notification limit could not be recorded. Please try again.'];
    }
    if (!$reservedItems) {
        $conn->rollback();
        $label = $stockGroup === 'out_of_stock' ? 'out-of-stock' : 'low-stock';
        return ['type' => 'error', 'message' => 'Every '.$label.' inventory item was already notified today. The once-a-day limit resets tomorrow.'];
    }

    $staff = $conn->query("SELECT id FROM users WHERE role='staff' AND status='active' AND deleted_at IS NULL ORDER BY id");
    if (!$staff || $staff->num_rows === 0) {
        $conn->rollback();
        return ['type' => 'error', 'message' => 'No active staff account received the restock alert. No daily limits were used.'];
    }

    $alertStatus = $isItemAlert ? (string)$reservedItems[0]['status'] : (string)$stockGroup;
    $title = $alertStatus === 'out_of_stock' ? 'Urgent restock required' : 'Low stock restock alert';
    if ($isItemAlert) {
        $item = $reservedItems[0];
        $message = $item['item_name'].' is '.($alertStatus === 'out_of_stock' ? 'out of stock' : 'at or below its reorder level').' (stock: '.(int)$item['stock_qty'].', reorder level: '.(int)$item['reorder_level'].'). Review Inventory and coordinate restocking.';
    } else {
        $names = array_column($reservedItems, 'item_name');
        $message = ($alertStatus === 'out_of_stock' ? 'Out-of-stock items: ' : 'Low-stock items: ').implode(', ', $names).'. Review Inventory and coordinate restocking.';
    }

    $sent = 0;
    while ($member = $staff->fetch_assoc()) {
        if (!notify_user($conn, (int)$member['id'], $title, $message, 'system', 'staff/inventory.php')) {
            $conn->rollback();
            return ['type' => 'error', 'message' => 'The restock alert could not be delivered to every active staff account. No alert or daily limit was recorded; please try again.'];
        }
        $sent++;
    }

    $record = $conn->prepare("UPDATE inventory_restock_alerts SET recipient_count=? WHERE item_id=? AND alert_date=CURRENT_DATE()");
    if (!$record) {
        $conn->rollback();
        return ['type' => 'error', 'message' => 'The restock alert could not be finalized. No alert or daily limit was recorded; please try again.'];
    }
    foreach ($reservedItems as $item) {
        $reservedItemId = (int)$item['id'];
        $record->bind_param('ii', $sent, $reservedItemId);
        if (!$record->execute() || (int)$record->affected_rows !== 1) {
            $conn->rollback();
            return ['type' => 'error', 'message' => 'The restock alert could not be finalized. No alert or daily limit was recorded; please try again.'];
        }
    }

    if ($isItemAlert) {
        $item = $reservedItems[0];
        log_action($conn, 'Sent inventory item restock alert', 'inventory_item', (int)$item['id'], $title.' for '.$item['item_name'].' sent to '.$sent.' staff account'.($sent === 1 ? '' : 's').'.');
    } else {
        $entityType = 'inventory_'.$stockGroup;
        log_action($conn, 'Sent inventory restock alert', $entityType, null, $title.' for '.count($reservedItems).' item'.(count($reservedItems) === 1 ? '' : 's').' sent to '.$sent.' staff account'.($sent === 1 ? '' : 's').'.');
    }
    if (!$conn->commit()) {
        $conn->rollback();
        return ['type' => 'error', 'message' => 'The restock alert could not be saved. Please try again.'];
    }

    if ($isItemAlert) {
        return ['type' => 'success', 'message' => 'Success: staff notified about '.$reservedItems[0]['item_name'].' ('.$sent.' account'.($sent === 1 ? '' : 's').'). The once-a-day limit is now reached and resets tomorrow.'];
    }
    $label = $stockGroup === 'out_of_stock' ? 'out-of-stock' : 'low-stock';
    $skipped = $alreadyNotified > 0 ? ' '.$alreadyNotified.' item'.($alreadyNotified === 1 ? ' was' : 's were').' already notified today and skipped.' : '';
    return ['type' => 'success', 'message' => 'Success: '.$label.' staff alert sent to '.$sent.' account'.($sent === 1 ? '' : 's').' for '.count($reservedItems).' item'.(count($reservedItems) === 1 ? '' : 's').'.'.$skipped.' The once-a-day limit for the notified items resets tomorrow.'];
}

$inventoryAlertLedgerReady = ensure_inventory_restock_alert_schema($conn);

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
$categoryRows=$conn->query("SELECT DISTINCT category FROM inventory_items WHERE TRIM(COALESCE(category,''))<>'' ORDER BY category");while($categoryRows&&$row=$categoryRows->fetch_assoc()){$value=trim((string)$row['category']);if($value!==''&&!in_array($value,$inventoryCategoryOptions,true))$inventoryCategoryOptions[]=$value;}
$sicknessRows=$conn->query("SELECT sickness FROM inventory_items WHERE TRIM(COALESCE(sickness,''))<>''");while($sicknessRows&&$row=$sicknessRows->fetch_assoc()){foreach(preg_split('/\s*,\s*/',(string)$row['sickness'],-1,PREG_SPLIT_NO_EMPTY) as $value){$value=trim($value);if($value!==''&&!in_array($value,$inventorySicknessOptions,true))$inventorySicknessOptions[]=$value;}}
sort($inventoryCategoryOptions,SORT_NATURAL|SORT_FLAG_CASE);sort($inventorySicknessOptions,SORT_NATURAL|SORT_FLAG_CASE);
function inventory_sickness_from_post(array $allowed): string {
    $values = $_POST['sickness'] ?? [];
    if (!is_array($values)) $values = [];
    $selected = [];
    foreach ($values as $value) {
        $value = trim((string)$value);
        if ($value !== '' && $value !== '__other__' && !in_array($value, $selected, true)) $selected[] = $value;
    }
    $other = trim((string)($_POST['sickness_other'] ?? ''));
    if ($other !== '' && !in_array($other, $selected, true)) $selected[] = $other;
    return implode(', ', $selected);
}

if ($_SERVER['REQUEST_METHOD'] === 'POST') {
    verify_csrf_or_fail();
    $action = $_POST['action'] ?? '';
    $postRedirect = 'admin/inventory.php';
    if (in_array($action, ['save_item','update_item'], true)) {
        $id = (int)($_POST['item_id'] ?? 0);
        $name = trim($_POST['item_name'] ?? '');
        $sku = trim($_POST['sku'] ?? '');
        $category = trim($_POST['category'] ?? '');
        if ($category === '__other__') $category = trim((string)($_POST['category_other'] ?? ''));
        if ($category !== '' && !in_array($category, $inventoryCategoryOptions, true)) $inventoryCategoryOptions[] = $category;
        $sickness = inventory_sickness_from_post($inventorySicknessOptions);
        if ($sku === '') $sku = generate_inventory_sku($conn, $category, $name, $id);
        $unit = trim($_POST['unit'] ?? '');
        $price = max(0, (float)($_POST['sale_price'] ?? 0));
        $stock = max(0, (int)($_POST['stock_qty'] ?? 0));
        $reorder = max(0, (int)($_POST['reorder_level'] ?? 5));
        if ($name === '') { flash('error', 'Enter an item name.'); redirect_to('admin/inventory.php'); }
        $status = $stock <= 0 ? 'out_of_stock' : ($stock <= $reorder ? 'low_stock' : 'available');
        $upload = upload_image_file($_FILES['product_photo'] ?? null, 'uploads/products', 'product');
        if (!$upload['ok']) { flash('error', $upload['error']); redirect_to('admin/inventory.php'); }
        if ($action === 'save_item') {
            $photo = $upload['path'];
            $stmt = $conn->prepare("INSERT INTO inventory_items(sku,item_name,category,sickness,product_photo,sale_price,stock_qty,unit,reorder_level,status) VALUES(?,?,?,?,?,?,?,?,?,?)");
            $stmt->bind_param('sssssdisis', $sku, $name, $category, $sickness, $photo, $price, $stock, $unit, $reorder, $status);
            $ok = $stmt->execute();
            $id = (int)$stmt->insert_id;
            $label = 'created';
        } else {
            $currentStmt = $conn->prepare("SELECT product_photo FROM inventory_items WHERE id=? LIMIT 1");
            $currentStmt->bind_param('i', $id); $currentStmt->execute(); $current=$currentStmt->get_result()->fetch_assoc();
            if (!$current) { flash('error','Inventory item not found.'); redirect_to('admin/inventory.php'); }
            $photo = $upload['path'] ?: $current['product_photo'];
            $stmt = $conn->prepare("UPDATE inventory_items SET sku=?,item_name=?,category=?,sickness=?,product_photo=?,sale_price=?,stock_qty=?,unit=?,reorder_level=?,status=? WHERE id=?");
            $stmt->bind_param('sssssdisisi', $sku, $name, $category, $sickness, $photo, $price, $stock, $unit, $reorder, $status, $id);
            $label = 'updated';
            $ok = $stmt->execute();
        }
        if ($ok) {
            log_action($conn, ucfirst($label).' inventory item', 'inventory_item', $id, 'Product identity, selling price, quantity, unit, and reorder data saved.');
            flash('success', 'Inventory item '.$label.'.');
        } else flash('error', 'The inventory item could not be saved.');
    } elseif ($action === 'move_stock') {
        $item = (int)($_POST['item_id'] ?? 0);
        $qty = max(1,(int)($_POST['quantity'] ?? 0));
        $type = ($_POST['movement_type'] ?? '') === 'stock_out' ? 'stock_out' : 'stock_in';
        $remarksChoice = trim((string)($_POST['remarks_choice'] ?? ''));
        $remarksOther = trim((string)($_POST['remarks_other'] ?? ''));
        $remarks = $remarksChoice === '__other__' ? $remarksOther : $remarksChoice;
        $stmt = $conn->prepare("SELECT stock_qty,item_name FROM inventory_items WHERE id=? FOR UPDATE");
        $stmt->bind_param('i',$item);$stmt->execute();$current=$stmt->get_result()->fetch_assoc();
        if (!$current) flash('error','Inventory item not found.');
        elseif ($type==='stock_out' && $qty>(int)$current['stock_qty']) flash('error','Stock-out quantity cannot exceed available stock.');
        else {
            $delta=$type==='stock_in'?$qty:-$qty;
            $update=$conn->prepare("UPDATE inventory_items SET stock_qty=GREATEST(0,stock_qty+?),status=CASE WHEN GREATEST(0,stock_qty+?)<=0 THEN 'out_of_stock' WHEN GREATEST(0,stock_qty+?)<=reorder_level THEN 'low_stock' ELSE 'available' END WHERE id=?");
            $update->bind_param('iiii',$delta,$delta,$delta,$item);$update->execute();
            $by=(int)current_user_id();
            $move=$conn->prepare("INSERT INTO inventory_movements(item_id,movement_type,quantity,remarks,created_by) VALUES(?,?,?,?,?)");
            $move->bind_param('isisi',$item,$type,$qty,$remarks,$by);$move->execute();
            log_action($conn,'Adjusted inventory stock','inventory_item',$item,ucwords(str_replace('_',' ',$type))." $qty");
            flash('success','Stock adjustment recorded.');
        }
    } elseif ($action === 'send_restock_alert') {
        $itemId = max(0, (int)($_POST['item_id'] ?? 0));
        if ($itemId > 0) {
            $returnStatus = (string)($_POST['return_status'] ?? 'all');
            if (!in_array($returnStatus, ['all','available','low_stock','out_of_stock','inactive'], true)) $returnStatus = 'all';
            $returnPage = max(1, min(100000, (int)($_POST['return_page'] ?? 1)));
            $postRedirect = 'admin/inventory.php?status=' . rawurlencode($returnStatus) . '&page=' . $returnPage . '#currentInventory';
        } else {
            $stockGroup = ($_POST['stock_group'] ?? '') === 'out_of_stock' ? 'out_of_stock' : 'low_stock';
        }
        $result = $inventoryAlertLedgerReady
            ? send_inventory_restock_alert($conn, $itemId > 0 ? $itemId : null, $itemId > 0 ? null : $stockGroup)
            : ['type' => 'error', 'message' => 'The daily notification limit schema is incomplete. No alert was sent; import the latest vetrix.sql schema or repair its item/date unique key.'];
        flash($result['type'], $result['message']);
    }
    redirect_to($postRedirect);
}

$summary=$conn->query("SELECT COUNT(*) total_items,COALESCE(SUM(stock_qty),0) total_stock,SUM(status='low_stock') low_stock,SUM(status='out_of_stock') out_stock FROM inventory_items")->fetch_assoc();
$inventoryFilter=$_GET['status']??'all';
if(!in_array($inventoryFilter,['all','available','low_stock','out_of_stock','inactive'],true))$inventoryFilter='all';
$inventoryWhere=$inventoryFilter==='all'?'':" WHERE status='".$conn->real_escape_string($inventoryFilter)."'";
$allItems=$conn->query("SELECT * FROM inventory_items ORDER BY FIELD(status,'out_of_stock','low_stock','available','inactive'),item_name")->fetch_all(MYSQLI_ASSOC);
$notifiedTodayIds=[];
if ($inventoryAlertLedgerReady) {
    $notifiedTodayRows=$conn->query("SELECT item_id FROM inventory_restock_alerts WHERE alert_date=CURRENT_DATE()");
    while($notifiedTodayRows&&$noticeRow=$notifiedTodayRows->fetch_assoc())$notifiedTodayIds[(int)$noticeRow['item_id']]=true;
}
$filteredItems=$inventoryFilter==='all' ? $allItems : array_values(array_filter($allItems,fn($item)=>$item['status']===$inventoryFilter));
$totalFiltered=count($filteredItems);
[$page,$perPage,$offset]=pagination_values(5,5,[5]);
$items=array_slice($filteredItems,$offset,$perPage);
$inventoryStatusCounts=['all'=>count($allItems),'available'=>0,'low_stock'=>0,'out_of_stock'=>0,'inactive'=>0];
foreach($allItems as $inventoryStatusItem){$state=$inventoryStatusItem['status']??'';if(isset($inventoryStatusCounts[$state]))$inventoryStatusCounts[$state]++;}
$moveItems=$conn->query("SELECT id,item_name,stock_qty,unit FROM inventory_items ORDER BY item_name")->fetch_all(MYSQLI_ASSOC);
$categorySummary=$conn->query("SELECT COALESCE(NULLIF(category,''),'Uncategorized') category,COUNT(*) item_count,COALESCE(SUM(stock_qty),0) stock_count FROM inventory_items GROUP BY COALESCE(NULLIF(category,''),'Uncategorized') ORDER BY stock_count DESC,category")->fetch_all(MYSQLI_ASSOC);
$lowItems=array_values(array_filter($allItems,fn($item)=>$item['status']==='low_stock'));
$outItems=array_values(array_filter($allItems,fn($item)=>$item['status']==='out_of_stock'));
function inventory_detail_list(array $items): string {
    if(!$items)return '<div class="empty-state compact"><p>No matching products.</p></div>';
    $html='<div class="inventory-detail-list">';
    foreach($items as $item){
        $html.='<a class="dashboard-detail-entry" href="'.e(app_url('admin/inventory.php?edit_item='.$item['id'].'#currentInventory')).'"><span><b>'.e($item['item_name']).'</b><small>'.e($item['category']?:'Uncategorized').' · '.intval($item['stock_qty']).' in stock</small><em>Reorder level: '.intval($item['reorder_level']).'</em></span><strong>Edit</strong></a>';
    }
    return $html.'</div>';
}
$categoryHtml='<div class="inventory-detail-list">';
foreach($categorySummary as $category)$categoryHtml.='<div class="inventory-summary-line"><span><b>'.e($category['category']).'</b><small>'.intval($category['item_count']).' product'.((int)$category['item_count']===1?'':'s').'</small></span><strong>'.intval($category['stock_count']).' units</strong></div>';
$categoryHtml.='</div>';
$lowHtml='<div class="inventory-restock-notice low-stock-notice"><b>Low stock</b><span>These products have reached their reorder level. Notify staff to restock them soon.</span></div>'.inventory_detail_list($lowItems).'<form method="POST" class="inventory-alert-form" data-confirm-message="Send a low-stock restock alert to all active staff accounts?">'.csrf_field().'<input type="hidden" name="action" value="send_restock_alert"><input type="hidden" name="stock_group" value="low_stock"><button class="button-primary" type="submit">'.ui_icon('bell').'Notify staff: low stock</button></form>';
$outHtml='<div class="inventory-restock-notice out-stock-notice"><b>Out of stock</b><span>These products are unavailable for sale. Staff should restock them as soon as possible.</span></div>'.inventory_detail_list($outItems).'<form method="POST" class="inventory-alert-form" data-confirm-message="Send an urgent out-of-stock alert to all active staff accounts?">'.csrf_field().'<input type="hidden" name="action" value="send_restock_alert"><input type="hidden" name="stock_group" value="out_of_stock"><button class="button-danger" type="submit">'.ui_icon('alert').'Notify staff: out of stock</button></form>';
$title='Inventory Management';include "../includes/header.php";include "../includes/navbar.php";
?>
<div class="layout"><?php include "../includes/admin_sidebar.php"; ?><main class="content inventory-page admin-inventory-page" id="mainContent">
<header class="page-heading"><div><span class="eyebrow">Clinic operations</span><h1>Inventory</h1><p>Manage products, monitor stock levels, and coordinate restocking.</p></div><div class="heading-actions inventory-heading-actions"><button class="button-primary" type="button" data-inventory-workbench-open="add"><?=ui_icon('plus')?>Add inventory item</button><button class="button-secondary" type="button" data-inventory-workbench-open="move"><?=ui_icon('refresh')?>Stock adjustment</button><a class="button-secondary" href="<?=app_url('admin/pos.php')?>"><?=ui_icon('cart')?>Open POS</a></div></header>
<?php if($m=flash('success')):?><div class="alert alert-success" role="status"><?=e($m)?></div><?php endif;?><?php if($m=flash('error')):?><div class="alert alert-danger" role="alert"><?=e($m)?></div><?php endif;?>
<section class="metric-grid"><a class="metric-card" href="#currentInventory"><span class="metric-icon"><?=ui_icon('package')?></span><div><small>Items</small><strong><?=intval($summary['total_items'])?></strong><p>Go to the current inventory list.</p></div></a><button type="button" class="metric-card" data-record-detail='<?=e(json_encode(['title'=>'Total stock overview','eyebrow'=>'Inventory by category','html'=>$categoryHtml,'dialog_class'=>'inventory-category-detail','hide_footer'=>true]))?>'><span class="metric-icon info"><?=ui_icon('layers')?></span><div><small>Total stock</small><strong><?=intval($summary['total_stock'])?></strong><p>See the categories that make up total stock.</p></div></button><button type="button" class="metric-card" data-record-detail='<?=e(json_encode(['title'=>'Low-stock products','eyebrow'=>'Restock review','html'=>$lowHtml,'dialog_class'=>'inventory-low-stock-detail','hide_footer'=>true]))?>'><span class="metric-icon warning"><?=ui_icon('alert')?></span><div><small>Low stock</small><strong><?=intval($summary['low_stock'])?></strong><p>Review products and notify staff.</p></div></button><button type="button" class="metric-card" data-record-detail='<?=e(json_encode(['title'=>'Out-of-stock products','eyebrow'=>'Urgent restock review','html'=>$outHtml,'dialog_class'=>'inventory-out-stock-detail','hide_footer'=>true]))?>'><span class="metric-icon danger"><?=ui_icon('x')?></span><div><small>Out of stock</small><strong><?=intval($summary['out_stock'])?></strong><p>Review unavailable products and alert staff.</p></div></button></section>
<div class="management-grid-two equal-height-cards inventory-workbench" id="inventoryWorkbench" data-inventory-workbench>
<section class="surface-card management-form-card" data-inventory-pane="add"><div class="section-heading compact"><div><span class="eyebrow">New item</span><h2>Add Inventory Item</h2></div><button class="button-secondary" type="button" data-inventory-workbench-close>Close</button></div><form method="POST" class="form-stack" enctype="multipart/form-data"><?=csrf_field()?><input type="hidden" name="action" value="save_item"><div class="form-grid-two"><label><span class="field-label-row"><span>SKU or code</span><small>Optional</small></span><input class="form-control" name="sku"></label><label>Item name<input class="form-control" name="item_name" required></label><label><span class="field-label-row"><span>Category</span><small>Optional</small></span><select class="form-select" name="category"><option value="">Select category</option><?php foreach($inventoryCategoryOptions as $option):?><option value="<?=e($option)?>"><?=e($option)?></option><?php endforeach;?><option value="__other__">Other</option></select><input class="form-control inventory-other-input" name="category_other" data-inventory-category-other placeholder="Enter another category" hidden></label><label>Selling price<input class="form-control" name="sale_price" type="number" min="0" step="0.01" value="0.00" required></label><label>Unit<input class="form-control" name="unit" placeholder="bottle, pack, box, piece" required></label><label>Initial stock<input class="form-control" name="stock_qty" type="number" min="0" required></label><label class="inventory-reorder-field"><span class="field-label-row"><span>Reorder level</span><small>Low-stock alert starts at or below this quantity</small></span><input class="form-control" name="reorder_level" type="number" min="0" required></label><label><span class="field-label-row"><span>Product photo</span><small>Optional</small></span><input class="form-control" name="product_photo" type="file" accept="image/jpeg,image/png,image/webp"></label></div><fieldset class="inventory-sickness-field"><legend><span>Sickness</span><small>Optional</small></legend><div class="inventory-sickness-options"><?php foreach($inventorySicknessOptions as $option):?><label class="inventory-sickness-option"><input type="checkbox" name="sickness[]" value="<?=e($option)?>"><span><?=e($option)?></span></label><?php endforeach;?><label class="inventory-sickness-option"><input type="checkbox" name="sickness[]" value="__other__" data-inventory-sickness-other-toggle><span>Other</span></label></div><input class="form-control inventory-other-input" name="sickness_other" data-inventory-sickness-other placeholder="Enter another sickness or use" hidden></fieldset><button class="button-primary" type="submit"><?=ui_icon('plus')?>Add inventory item</button></form></section>
<section class="surface-card management-form-card" data-inventory-pane="move"><div class="section-heading compact"><div><span class="eyebrow">Movement</span><h2>Stock adjustment</h2></div><button class="button-secondary" type="button" data-inventory-workbench-close>Close</button></div><form method="POST" class="form-stack"><?=csrf_field()?><input type="hidden" name="action" value="move_stock"><label>Inventory item<select class="form-select" name="item_id" required><?php foreach($moveItems as $i):?><option value="<?=$i['id']?>"><?=e($i['item_name'])?></option><?php endforeach;?></select></label><div class="form-grid-two"><label>Movement<select class="form-select" name="movement_type"><option value="stock_in">Stock in</option><option value="stock_out">Stock out</option></select></label><label>Quantity<input class="form-control" name="quantity" type="number" min="1" required></label></div><label>Reason or reference<select class="form-select" name="remarks_choice" data-inventory-remarks required><option value="">Select reason</option><option value="Delivery">Delivery</option><option value="Damaged item">Damaged item</option><option value="Clinic use">Clinic use</option><option value="Correction">Correction</option><option value="__other__">Other</option></select><input class="form-control inventory-other-input" name="remarks_other" data-inventory-remarks-other placeholder="Enter another reason or reference" hidden></label><button class="button-primary" type="submit"><?=ui_icon('refresh')?>Record adjustment</button></form></section>
</div>
<nav class="filter-tabs compact inventory-status-tabs" aria-label="Inventory status categories"><?php foreach(['all'=>'All','available'=>'Available','low_stock'=>'Low stock','out_of_stock'=>'Out of stock'] as $stockKey=>$stockLabel):?><a class="filter-tab status-<?=e($stockKey)?> <?=$inventoryFilter===$stockKey?'active':''?>" href="?status=<?=e($stockKey)?>#currentInventory"><?=e($stockLabel)?><b><?=intval($inventoryStatusCounts[$stockKey]??0)?></b></a><?php endforeach;?></nav>
<section class="surface-card management-table-card inventory-current-card" id="currentInventory"><div class="section-heading"><div><span class="eyebrow">Current inventory</span><h2>Products and Stock Levels</h2></div></div><?php if(!$items):?><div class="empty-state compact"><p>No inventory items found.</p></div><?php else:?><div class="inventory-overview-table" role="table" aria-label="Current inventory"><div class="inventory-overview-head" role="row"><span>Item</span><span>Category</span><span>Price</span><span>Stock</span><span>Restock when stock is</span><span>Status</span><span>Notif staff</span></div><?php foreach($items as $r):?><div class="inventory-overview-row stock-<?=e($r['status'])?>" role="row" data-item-id="<?=intval($r['id'])?>" data-edit-inventory='<?=e(json_encode($r))?>' tabindex="0" aria-label="Edit <?=e($r['item_name'])?>"><span class="inventory-overview-item"><i><?=ui_icon('package')?></i><em><b><?=e($r['item_name'])?></b><small><?=e($r['sku']?:'No SKU')?></small></em></span><span><?=e($r['category']?:'Uncategorized')?></span><span><b>₱<?=number_format((float)$r['sale_price'],2)?></b></span><span><?=intval($r['stock_qty'])?></span><span><?=intval($r['reorder_level'])?> or fewer</span><span><?=badge($r['status'])?></span><?php $notifiedToday=!empty($notifiedTodayIds[(int)$r['id']]);?><form method="POST" class="inventory-row-action" data-confirm-message="Notify staff to review this inventory item? This alert can only be sent once per day."><?=csrf_field()?><input type="hidden" name="action" value="send_restock_alert"><input type="hidden" name="item_id" value="<?=intval($r['id'])?>"><input type="hidden" name="return_status" value="<?=e($inventoryFilter)?>"><input type="hidden" name="return_page" value="<?=intval($page)?>"><button class="button-secondary<?=$notifiedToday?' inventory-notify-success':''?>" type="submit" <?=(!in_array($r['status'],['low_stock','out_of_stock'],true)||$notifiedToday)?'disabled aria-disabled="true" title="'.($notifiedToday?'Staff notified successfully today. Available again tomorrow.':'This item does not currently need a restock alert').'"':''?>><?=$notifiedToday?ui_icon('check'):ui_icon('bell')?><?=$notifiedToday?'Staff notified':'Notify staff'?></button><?php if($notifiedToday):?><small class="inventory-row-success" role="status">Sent today · available tomorrow</small><?php endif;?></form></div><?php endforeach;?></div><?php endif;?><?=render_pagination($page,$perPage,$totalFiltered,['status'=>$inventoryFilter,'_anchor'=>'currentInventory'])?></section>
<section class="calendar-dialog" id="inventoryEditDialog" aria-hidden="true"><div class="dialog-scrim" data-close-inventory-edit></div><div class="dialog-card inventory-edit-dialog"><header><div><span class="eyebrow inventory-edit-eyebrow">Edit Inventory Item</span><p id="inventoryEditTitle">Inventory item</p></div><button class="icon-button" type="button" data-close-inventory-edit><?=ui_icon('x')?></button></header><form method="POST" enctype="multipart/form-data" class="form-stack inventory-edit-form"><?=csrf_field()?><input type="hidden" name="action" value="update_item"><input type="hidden" name="item_id" id="inventoryEditId"><div class="inventory-edit-scroll-region"><div class="form-grid-two"><label>SKU<input class="form-control" name="sku" id="inventoryEditSku"></label><label>Item name<input class="form-control" name="item_name" id="inventoryEditName" required></label><label><span class="field-label-row"><span>Category</span><small>Optional</small></span><select class="form-select" name="category" id="inventoryEditCategory"><option value="">Select category</option><?php foreach($inventoryCategoryOptions as $option):?><option value="<?=e($option)?>"><?=e($option)?></option><?php endforeach;?></select></label><label>Selling price<input class="form-control" type="number" min="0" step="0.01" name="sale_price" id="inventoryEditPrice" required></label><label>Unit<input class="form-control" name="unit" id="inventoryEditUnit" placeholder="bottle, pack, box, piece" required></label><label>Current stock<input class="form-control" type="number" min="0" name="stock_qty" id="inventoryEditStock" required></label><label class="inventory-reorder-field"><span class="field-label-row"><span>Reorder level</span><small>Low-stock alert starts at or below this quantity</small></span><input class="form-control" type="number" min="0" name="reorder_level" id="inventoryEditReorder" required></label><label>Replace photo<input class="form-control" type="file" name="product_photo" accept="image/jpeg,image/png,image/webp"></label></div><fieldset class="inventory-sickness-field"><legend><span>Sickness</span><small>Optional</small></legend><div class="inventory-sickness-options" id="inventoryEditSickness"><?php foreach($inventorySicknessOptions as $option):?><label class="inventory-sickness-option"><input type="checkbox" name="sickness[]" value="<?=e($option)?>"><span><?=e($option)?></span></label><?php endforeach;?></div></fieldset></div><div class="form-actions"><button class="button-secondary" type="button" data-close-inventory-edit>Cancel</button><button class="button-primary" type="submit">Save product details</button></div></form></div></section>
<script>(()=>{const d=document.getElementById('inventoryEditDialog'),open=()=>{d.classList.add('open');d.setAttribute('aria-hidden','false');document.body.classList.add('overlay-open')},close=()=>{d.classList.remove('open');d.setAttribute('aria-hidden','true');document.body.classList.remove('overlay-open')};document.querySelectorAll('[data-close-inventory-edit]').forEach(b=>b.addEventListener('click',close));const openRow=row=>{const i=JSON.parse(row.dataset.editInventory);document.getElementById('inventoryEditTitle').textContent=i.item_name;document.getElementById('inventoryEditId').value=i.id;document.getElementById('inventoryEditSku').value=i.sku||'';document.getElementById('inventoryEditName').value=i.item_name||'';document.getElementById('inventoryEditCategory').value=i.category||'';const sicknessBox=document.getElementById('inventoryEditSickness'),activeSickness=(i.sickness||'').split(/\s*,\s*/).filter(Boolean);sicknessBox?.querySelectorAll('input[type=\"checkbox\"]').forEach(cb=>cb.checked=activeSickness.includes(cb.value));if(sicknessBox){[...sicknessBox.querySelectorAll('.inventory-sickness-option')].sort((a,b)=>Number(b.querySelector('input').checked)-Number(a.querySelector('input').checked)).forEach(label=>sicknessBox.appendChild(label));}document.getElementById('inventoryEditPrice').value=Number(i.sale_price||0).toFixed(2);document.getElementById('inventoryEditUnit').value=i.unit||'';document.getElementById('inventoryEditStock').value=i.stock_qty||0;document.getElementById('inventoryEditReorder').value=i.reorder_level||0;open()};document.querySelectorAll('[data-edit-inventory]').forEach(row=>{row.addEventListener('click',event=>{if(event.target.closest('.inventory-row-action'))return;openRow(row)});row.addEventListener('keydown',event=>{if((event.key==='Enter'||event.key===' ')&&!event.target.closest('.inventory-row-action')){event.preventDefault();openRow(row)}})});const focus=new URL(location.href).searchParams.get('edit_item');if(focus){const row=document.querySelector(`[data-item-id="${CSS.escape(focus)}"]`);if(row)setTimeout(()=>openRow(row),80)}})();</script>
<script id="inventoryOtherFields">(()=>{const category=document.querySelector('select[name="category"]'),categoryOther=document.querySelector('[data-inventory-category-other]'),sicknessToggle=document.querySelector('[data-inventory-sickness-other-toggle]'),sicknessOther=document.querySelector('[data-inventory-sickness-other]'),remarks=document.querySelector('[data-inventory-remarks]'),remarksOther=document.querySelector('[data-inventory-remarks-other]');const sync=()=>{if(categoryOther){const show=category?.value==='__other__';categoryOther.hidden=!show;categoryOther.required=!!show;if(!show)categoryOther.value='';}if(sicknessOther){const show=!!sicknessToggle?.checked;sicknessOther.hidden=!show;sicknessOther.required=show;if(!show)sicknessOther.value='';}if(remarksOther){const show=remarks?.value==='__other__';remarksOther.hidden=!show;remarksOther.required=!!show;if(!show)remarksOther.value='';}};category?.addEventListener('change',sync);sicknessToggle?.addEventListener('change',sync);remarks?.addEventListener('change',sync);sync();})();</script>
</main></div><?php include "../includes/footer.php"; ?>
