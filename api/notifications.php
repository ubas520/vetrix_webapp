<?php
require_once "../config/database.php";
require_once "../includes/functions.php";
require_role(['admin','veterinarian','staff']);
header('Content-Type: application/json; charset=utf-8');

$uid = (int)current_user_id();
$action = $_GET['action'] ?? ($_POST['action'] ?? 'list');


function notification_root_for_role($role) {
    $role = strtolower((string)$role);
    if ($role === 'veterinarian') return 'vet';
    if (in_array($role, ['admin', 'staff'], true)) return $role;
    return 'staff';
}

function notification_date_candidates($row, $text) {
    $created = !empty($row['created_at']) ? strtotime((string)$row['created_at']) : time();
    $today = strtotime(date('Y-m-d'));
    $dates = [];
    $add = function($ts) use (&$dates) {
        $value = date('Y-m-d', $ts);
        if (!in_array($value, $dates, true)) $dates[] = $value;
    };
    if (str_contains($text, 'tomorrow')) {
        $add(strtotime('+1 day', $today));
        $add(strtotime('+1 day', $created));
        $add(strtotime('+2 day', $created));
    } elseif (str_contains($text, 'today')) {
        $add($today);
        $add($created);
    }
    return $dates;
}

function notification_expected_count($text) {
    $text = strtolower((string)$text);
    if (preg_match('/\b(\d{1,3})\b/', $text, $m)) return max(0, (int)$m[1]);
    $words = [
        'one'=>1,'two'=>2,'three'=>3,'four'=>4,'five'=>5,'six'=>6,'seven'=>7,'eight'=>8,'nine'=>9,'ten'=>10,
        'eleven'=>11,'twelve'=>12,'thirteen'=>13,'fourteen'=>14,'fifteen'=>15,'sixteen'=>16,'seventeen'=>17,'eighteen'=>18,'nineteen'=>19,
        'twenty'=>20,'thirty'=>30,'forty'=>40,'fifty'=>50
    ];
    foreach ($words as $word => $value) {
        if (preg_match('/\b' . preg_quote($word, '/') . '\b/', $text)) return $value;
    }
    return 0;
}

function notification_replace_expected_count(string $text, int $expected, int $actual): string {
    if ($text === '' || $expected < 1 || $expected === $actual) return $text;
    $replaced = preg_replace('/\b' . preg_quote((string)$expected, '/') . '\b/', (string)$actual, $text, 1, $count);
    if ($count > 0) return $replaced;
    $numberWords = [1=>'one',2=>'two',3=>'three',4=>'four',5=>'five',6=>'six',7=>'seven',8=>'eight',9=>'nine',10=>'ten',11=>'eleven',12=>'twelve',13=>'thirteen',14=>'fourteen',15=>'fifteen',16=>'sixteen',17=>'seventeen',18=>'eighteen',19=>'nineteen',20=>'twenty',30=>'thirty',40=>'forty',50=>'fifty'];
    if (!isset($numberWords[$expected])) return $text;
    return preg_replace('/\b' . preg_quote($numberWords[$expected], '/') . '\b/i', (string)$actual, $text, 1);
}

function notification_date_where($conn, $columnSql, $date) {
    return "DATE($columnSql)='" . $conn->real_escape_string($date) . "'";
}

function notification_query_count($conn, $sql) {
    $result = $conn->query($sql);
    if (!$result) return 0;
    $row = $result->fetch_assoc();
    return (int)($row['c'] ?? 0);
}

function notification_dashboard_list($rows, $empty, $caption = '') {
    if (!$rows) return '<div class="empty-state compact"><p>' . e($empty) . '</p></div>';
    $html = '<div class="dashboard-detail-list notification-specific-list">';
    if ($caption !== '') $html .= '<p class="notification-specific-caption">' . e($caption) . '</p>';
    foreach ($rows as $row) {
        $href = trim((string)($row['href'] ?? ''));
        $title = trim((string)($row['title'] ?? 'Notification item'));
        $meta = trim((string)($row['meta'] ?? ''));
        $note = trim((string)($row['note'] ?? ''));
        $open = $href !== '' ? '<a class="dashboard-detail-entry" href="' . e($href) . '">' : '<article class="dashboard-detail-entry">';
        $close = $href !== '' ? '<strong>Open</strong></a>' : '</article>';
        $html .= $open . '<span><b>' . e($title) . '</b>';
        if ($meta !== '') $html .= '<small>' . e($meta) . '</small>';
        if ($note !== '') $html .= '<em>' . e($note) . '</em>';
        $html .= '</span>' . $close;
    }
    return $html . '</div>';
}

function notification_appointment_detail($conn, $row, $uid, $role, $text) {
    $root = notification_root_for_role($role);
    $expectedCount = notification_expected_count(($row['title'] ?? '') . ' ' . ($row['message'] ?? ''));
    $baseWhere = [];
    if ($root === 'client') $baseWhere[] = 'a.owner_id=' . (int)$uid;
    if ($root === 'vet') $baseWhere[] = 'a.assigned_vet_id=' . (int)$uid;
    if (str_contains($text, 'pending') || str_contains($text, 'staff review') || preg_match('/requests? need|need.*review|awaiting|approval/i', $text)) $baseWhere[] = "a.status='pending'";
    if (str_contains($text, 'past')) $baseWhere[] = "COALESCE(a.scheduled_date,a.requested_date)<NOW() AND a.status NOT IN ('completed','cancelled','rejected')";

    $dateCandidates = notification_date_candidates($row, $text);
    $created = !empty($row['created_at']) ? strtotime((string)$row['created_at']) : time();
    if (str_contains($text, 'tomorrow') || str_contains($text, 'calendar') || str_contains($text, 'schedule')) {
        for ($i = 0; $i <= 7; $i++) {
            $value = date('Y-m-d', strtotime('+' . $i . ' day', $created));
            if (!in_array($value, $dateCandidates, true)) $dateCandidates[] = $value;
        }
    }

    $bestDate = '';
    $bestCount = -1;
    foreach ($dateCandidates as $date) {
        if (!preg_match('/^\d{4}-\d{2}-\d{2}$/', $date)) continue;
        $where = array_merge($baseWhere, [notification_date_where($conn, 'COALESCE(a.scheduled_date,a.requested_date)', $date)]);
        $count = notification_query_count($conn, "SELECT COUNT(*) c FROM appointments a WHERE " . implode(' AND ', $where));
        if ($expectedCount > 0 && $count === $expectedCount) { $bestDate = $date; $bestCount = $count; break; }
        if ($count > $bestCount) { $bestDate = $date; $bestCount = $count; }
    }

    if (($bestCount <= 0 || ($expectedCount > 0 && $bestCount !== $expectedCount)) && (str_contains($text, 'tomorrow') || str_contains($text, 'calendar') || str_contains($text, 'schedule'))) {
        $scanWhere = $baseWhere;
        $scanWhere[] = "COALESCE(a.scheduled_date,a.requested_date) IS NOT NULL";
        $scanWhere[] = "DATE(COALESCE(a.scheduled_date,a.requested_date)) BETWEEN '" . $conn->real_escape_string(date('Y-m-d', strtotime('-1 day', $created))) . "' AND '" . $conn->real_escape_string(date('Y-m-d', strtotime('+14 day', $created))) . "'";
        $scanSql = "SELECT DATE(COALESCE(a.scheduled_date,a.requested_date)) d, COUNT(*) c FROM appointments a WHERE " . implode(' AND ', $scanWhere) . " GROUP BY d ORDER BY ABS(COUNT(*) - " . (int)$expectedCount . ") ASC, d ASC LIMIT 1";
        $scan = $conn->query($scanSql);
        if ($scan && ($candidate = $scan->fetch_assoc())) {
            if ($bestCount <= 0 || $expectedCount <= 0 || abs((int)$candidate['c'] - $expectedCount) <= abs($bestCount - $expectedCount)) {
                $bestDate = (string)$candidate['d'];
                $bestCount = (int)$candidate['c'];
            }
        }
    }

    $where = $baseWhere;
    if ($bestDate !== '' && $bestCount > 0) {
        $where[] = notification_date_where($conn, 'COALESCE(a.scheduled_date,a.requested_date)', $bestDate);
    } elseif ($expectedCount > 0) {
        $where[] = "COALESCE(a.scheduled_date,a.requested_date)>=DATE_SUB(NOW(),INTERVAL 1 DAY)";
    } elseif (!$where) {
        $where[] = "COALESCE(a.scheduled_date,a.requested_date)>=DATE_SUB(NOW(),INTERVAL 1 DAY)";
    }
    $whereSql = 'WHERE ' . implode(' AND ', $where);
    $total = notification_query_count($conn, "SELECT COUNT(*) c FROM appointments a $whereSql");
    $sql = "SELECT a.id,a.status,a.reason,a.scheduled_date,a.requested_date,p.name pet_name,p.species,u.full_name client_name,v.full_name vet_name
            FROM appointments a
            JOIN pets p ON a.pet_id=p.id
            JOIN users u ON a.owner_id=u.id
            LEFT JOIN users v ON a.assigned_vet_id=v.id
            $whereSql
            ORDER BY COALESCE(a.scheduled_date,a.requested_date) ASC,a.id ASC
            ";
    $result = $conn->query($sql);
    $items = [];
    while ($result && $r = $result->fetch_assoc()) {
        $when = $r['scheduled_date'] ?: $r['requested_date'];
        $items[] = [
            'href' => app_url($root . '/appointments.php?appointment_id=' . (int)$r['id']),
            'title' => $r['pet_name'] . ' · ' . $r['client_name'],
            'meta' => date('M d, Y h:i A', strtotime($when)) . ' · ' . ucfirst((string)$r['status']),
            'note' => ($r['vet_name'] ? 'Vet: ' . $r['vet_name'] . ' · ' : '') . ($r['reason'] ?: 'No reason saved'),
        ];
    }

    if (!$items) {
        $fallbackWhere = $baseWhere ?: ['1=1'];
        $fallbackSql = "SELECT a.id,a.status,a.reason,a.scheduled_date,a.requested_date,p.name pet_name,p.species,u.full_name client_name,v.full_name vet_name
                FROM appointments a
                JOIN pets p ON a.pet_id=p.id
                JOIN users u ON a.owner_id=u.id
                LEFT JOIN users v ON a.assigned_vet_id=v.id
                WHERE " . implode(' AND ', $fallbackWhere) . "
                ORDER BY COALESCE(a.scheduled_date,a.requested_date) ASC,a.id ASC
                ";
        $fallback = $conn->query($fallbackSql);
        while ($fallback && $r = $fallback->fetch_assoc()) {
            $when = $r['scheduled_date'] ?: $r['requested_date'];
            $items[] = [
                'href' => app_url($root . '/appointments.php?appointment_id=' . (int)$r['id']),
                'title' => $r['pet_name'] . ' · ' . $r['client_name'],
                'meta' => date('M d, Y h:i A', strtotime($when)) . ' · ' . ucfirst((string)$r['status']),
                'note' => ($r['vet_name'] ? 'Vet: ' . $r['vet_name'] . ' · ' : '') . ($r['reason'] ?: 'No reason saved'),
            ];
        }
    }

    $caption = $bestDate ? 'Appointment records for ' . date('M d, Y', strtotime($bestDate)) . '.' : 'Appointment records matched from this notification.';
    return ['html' => notification_dashboard_list($items, 'No appointment records could be matched to this alert.', $caption), 'count' => count($items), 'eyebrow' => $bestDate ? date('M d, Y', strtotime($bestDate)) : 'Appointment detail'];
}

function notification_vaccination_detail($conn, $row, $uid, $role, $text) {
    $root = notification_root_for_role($role);
    $where = [];
    if ($root === 'client') $where[] = 'p.owner_id=' . (int)$uid;
    if (str_contains($text, 'overdue')) $where[] = 'v.next_due_date<CURDATE()';
    elseif (str_contains($text, 'due') || str_contains($text, 'reminder') || str_contains($text, 'vaccine')) $where[] = "(v.next_due_date IS NULL OR v.next_due_date<=DATE_ADD(CURDATE(),INTERVAL 90 DAY))";
    if (!$where) $where[] = '1=1';
    $whereSql = 'WHERE ' . implode(' AND ', $where);
    $total = notification_query_count($conn, "SELECT COUNT(*) c FROM vaccinations v JOIN pets p ON v.pet_id=p.id $whereSql");
    $sql = "SELECT v.id,v.vaccine_name,v.next_due_date,p.name pet_name,u.full_name owner_name
            FROM vaccinations v JOIN pets p ON v.pet_id=p.id JOIN users u ON p.owner_id=u.id
            $whereSql ORDER BY CASE WHEN v.next_due_date IS NULL THEN 1 ELSE 0 END,v.next_due_date ASC,v.id DESC";
    $result = $conn->query($sql);
    $items = [];
    while ($result && $r = $result->fetch_assoc()) {
        $due = $r['next_due_date'] ? date('M d, Y', strtotime($r['next_due_date'])) : 'No due date';
        $href = app_url($root . '/vaccinations.php?vaccination_id=' . (int)$r['id']);
        $items[] = ['href' => $href, 'title' => $r['pet_name'] . ' · ' . $r['vaccine_name'], 'meta' => $r['owner_name'], 'note' => 'Next due: ' . $due];
    }
    $caption = $total > count($items) ? 'Showing ' . count($items) . ' of ' . $total . ' matching vaccinations.' : $total . ' matching vaccination' . ($total === 1 ? '' : 's') . '.';
    return ['html' => notification_dashboard_list($items, 'No matching vaccination records found for this alert.', $caption), 'count' => count($items), 'eyebrow' => 'Vaccination detail'];
}

function notification_inventory_detail($conn, $role) {
    if (notification_root_for_role($role) !== 'admin') return ['html' => '', 'count' => 0, 'eyebrow' => 'Notification detail'];
    $total = notification_query_count($conn, "SELECT COUNT(*) c FROM inventory_items WHERE status IN ('low_stock','out_of_stock') OR stock_qty<=reorder_level");
    $result = $conn->query("SELECT id,item_name,category,stock_qty,reorder_level,status FROM inventory_items WHERE status IN ('low_stock','out_of_stock') OR stock_qty<=reorder_level ORDER BY stock_qty ASC,item_name");
    if(!$result || !$result->num_rows) return ['html'=>'<div class="empty-state compact"><p>No low-stock inventory items found.</p></div>','count'=>0,'eyebrow'=>'Inventory detail'];
    $html='<div class="dashboard-detail-list notification-specific-list notification-inventory-list" data-notification-paginated data-page-size="5"><p class="notification-specific-caption">'.(int)$total.' low-stock item'.($total===1?'':'s').'.</p>';
    $index=0;
    while($r=$result->fetch_assoc()){
        $html.='<a class="dashboard-detail-entry" data-notification-page-item data-item-index="'.$index.'" href="'.e(app_url('admin/inventory.php?edit_item='.(int)$r['id'].'#currentInventory')).'"><span><b>'.e($r['item_name']).'</b><small>'.e($r['category']?:'Uncategorized').' · '.e(ucwords(str_replace('_',' ',(string)$r['status']))).'</small><em>Stock: '.(int)$r['stock_qty'].' · Reorder: '.(int)$r['reorder_level'].'</em></span><strong>Open</strong></a>';
        $index++;
    }
    $pages=max(1,(int)ceil($total/5));
    $html.='</div><nav class="pagination-shell natural-pagination notification-inline-pagination" data-notification-inline-pager data-total-pages="'.$pages.'" aria-label="Inventory pages"><div class="pagination-current-page"><span data-inline-page-label>Page <b>1</b> of '.$pages.'</span></div><div class="pagination-nav" data-inline-nav></div></nav>';
    return ['html'=>$html,'count'=>$total,'eyebrow'=>'Inventory detail'];
}

function notification_feedback_detail($conn, $role) {
    $root = notification_root_for_role($role);
    if (!in_array($root, ['admin', 'staff', 'client'], true)) return ['html' => '', 'count' => 0, 'eyebrow' => 'Notification detail'];
    $where = $root === 'client' ? 'WHERE f.user_id=' . (int)current_user_id() . " AND f.comment NOT LIKE '[Deleted] %'" : "WHERE f.comment NOT LIKE '[Deleted] %'";
    $total = notification_query_count($conn, "SELECT COUNT(*) c FROM feedback f $where");
    $result = $conn->query("SELECT f.id,f.rating,f.comment,f.created_at,u.full_name FROM feedback f JOIN users u ON f.user_id=u.id $where ORDER BY f.created_at DESC");
    $items = [];
    while ($result && $r = $result->fetch_assoc()) {
        $items[] = ['href' => app_url(($root === 'client' ? 'client' : 'admin') . '/feedback.php?feedback_id=' . (int)$r['id']), 'title' => $r['full_name'] . ' · ' . (int)$r['rating'] . '/5', 'meta' => date('M d, Y h:i A', strtotime($r['created_at'])), 'note' => $r['comment'] ?: 'No written comment'];
    }
    $shown=count($items);$caption = $total > $shown ? 'Showing ' . $shown . ' of ' . $total . ' matching feedback items.' : $shown . ' matching feedback item' . ($shown === 1 ? '' : 's') . '.';
    return ['html' => notification_dashboard_list($items, 'No matching feedback found.', $caption), 'count' => $shown, 'eyebrow' => 'Feedback detail'];
}

function notification_pet_detail($conn, $uid, $role, $text) {
    $root = notification_root_for_role($role);
    $where = [];
    if ($root === 'client') $where[] = 'p.owner_id=' . (int)$uid;
    if (str_contains($text, 'pending')) $where[] = "p.verification_status='pending'";
    if (!$where) $where[] = '1=1';
    $whereSql = 'WHERE ' . implode(' AND ', $where);
    $total = notification_query_count($conn, "SELECT COUNT(*) c FROM pets p $whereSql");
    $result = $conn->query("SELECT p.id,p.name,p.species,p.verification_status,u.full_name owner_name FROM pets p JOIN users u ON p.owner_id=u.id $whereSql ORDER BY p.updated_at DESC,p.created_at DESC");
    $items = [];
    while ($result && $r = $result->fetch_assoc()) {
        $items[] = ['href' => app_url($root . '/pets.php?pet_id=' . (int)$r['id']), 'title' => $r['name'] . ' · ' . $r['species'], 'meta' => $r['owner_name'], 'note' => 'Status: ' . ucwords(str_replace('_', ' ', (string)$r['verification_status']))];
    }
    $shown=count($items);$caption = $total > $shown ? 'Showing ' . $shown . ' of ' . $total . ' matching pet profiles.' : $shown . ' matching pet profile' . ($shown === 1 ? '' : 's') . '.';
    return ['html' => notification_dashboard_list($items, 'No matching pet profiles found.', $caption), 'count' => $shown, 'eyebrow' => 'Pet detail'];
}


function notification_workforce_detail($conn, $row, $uid, $role, $text) {
    if (!table_exists($conn, 'staff_availability')) return ['html' => '', 'count' => 0, 'eyebrow' => 'Calendar detail'];
    $root = notification_root_for_role($role);
    $where = [];
    if (in_array($root, ['vet', 'staff'], true)) $where[] = 'sa.user_id=' . (int)$uid;
    if (str_contains($text, 'clinic schedule') || str_contains($text, 'on duty') || str_contains($text, 'duty')) $where[] = "sa.event_kind='clinic_schedule'";
    else $where[] = "sa.event_kind='unavailable'";
    if (str_contains($text, 'approved')) $where[] = "sa.reason LIKE '[Approved]%'";
    elseif (str_contains($text, 'denied') || str_contains($text, 'rejected')) $where[] = "sa.reason LIKE '[Denied]%'";
    elseif (str_contains($text, 'pending') || str_contains($text, 'requested') || str_contains($text, 'request')) $where[] = "sa.reason LIKE '[Pending]%'";

    $chosenDate = '';
    $chosenCount = -1;
    foreach (notification_date_candidates($row, $text) as $date) {
        if (!preg_match('/^\d{4}-\d{2}-\d{2}$/', $date)) continue;
        $dateWhere = array_merge($where, ["DATE(sa.starts_at)='" . $conn->real_escape_string($date) . "'"]);
        $count = notification_query_count($conn, "SELECT COUNT(*) c FROM staff_availability sa WHERE " . implode(' AND ', $dateWhere));
        if ($count > $chosenCount) { $chosenCount = $count; $chosenDate = $date; }
    }
    if ($chosenDate !== '' && $chosenCount > 0) $where[] = "DATE(sa.starts_at)='" . $conn->real_escape_string($chosenDate) . "'";
    if (!$where) $where[] = '1=1';
    $whereSql = 'WHERE ' . implode(' AND ', $where);
    $total = notification_query_count($conn, "SELECT COUNT(*) c FROM staff_availability sa $whereSql");
    $result = $conn->query("SELECT sa.id,sa.event_kind,sa.starts_at,sa.ends_at,sa.block_type,sa.reason,u.full_name,u.role FROM staff_availability sa JOIN users u ON sa.user_id=u.id $whereSql ORDER BY sa.starts_at ASC,sa.id ASC");
    $items = [];
    while ($result && $r = $result->fetch_assoc()) {
        $reason = preg_replace('/^\[(Approved|Pending|Denied)\]\s*/i', '', (string)($r['reason'] ?? '')) ?: 'No reason saved';
        $items[] = [
            'href' => '',
            'title' => $r['full_name'] . ' · ' . ucwords(str_replace('_', ' ', (string)$r['block_type'])),
            'meta' => date('M d, Y h:i A', strtotime($r['starts_at'])) . ' to ' . date('M d, Y h:i A', strtotime($r['ends_at'])),
            'note' => ucwords(str_replace('_', ' ', (string)$r['event_kind'])) . ' · ' . $reason,
        ];
    }
    $caption = $total > count($items) ? 'Showing ' . count($items) . ' of ' . $total . ' matching calendar items.' : $total . ' matching calendar item' . ($total === 1 ? '' : 's') . '.';
    return ['html' => notification_dashboard_list($items, 'No matching workforce calendar items found for this alert.', $caption), 'count' => count($items), 'eyebrow' => $chosenDate ? date('M d, Y', strtotime($chosenDate)) : 'Calendar detail'];
}

function notification_user_detail($conn, $role, $text, $actionUrl) {
    if (notification_root_for_role($role) !== 'admin') return ['html' => '', 'count' => 0, 'eyebrow' => 'Account detail'];
    $parts = parse_url((string)$actionUrl);
    $query = [];
    parse_str($parts['query'] ?? '', $query);
    $allowedRoles = ['admin', 'staff', 'veterinarian', 'client'];
    $roleFilter = in_array(($query['role'] ?? ''), $allowedRoles, true) ? $query['role'] : '';
    if ($roleFilter === '') {
        if (str_contains($text, 'staff')) $roleFilter = 'staff';
        elseif (str_contains($text, 'veterinarian') || str_contains($text, 'vet')) $roleFilter = 'veterinarian';
        elseif (str_contains($text, 'client')) $roleFilter = 'client';
    }
    $where = [];
    if ($roleFilter !== '') $where[] = "u.role='" . $conn->real_escape_string($roleFilter) . "'";
    if (str_contains($text, 'pending') || str_contains($text, 'awaiting') || str_contains($text, 'review')) $where[] = "u.status='pending'";
    elseif (str_contains($text, 'approved')) $where[] = "u.status='approved'";
    elseif (str_contains($text, 'inactive')) $where[] = "u.status='inactive'";
    elseif (str_contains($text, 'active')) $where[] = "u.status='active'";
    if (!$where) $where[] = '1=1';
    $whereSql = 'WHERE ' . implode(' AND ', $where);
    $total = notification_query_count($conn, "SELECT COUNT(*) c FROM users u $whereSql");
    $result = $conn->query("SELECT u.id,u.full_name,u.email,u.phone,u.role,u.status,u.created_at FROM users u $whereSql ORDER BY u.created_at DESC,u.id DESC");
    $items = [];
    while ($result && $r = $result->fetch_assoc()) {
        $items[] = [
            'href' => '',
            'title' => $r['full_name'] . ' · ' . ucwords(str_replace('_', ' ', (string)$r['role'])),
            'meta' => $r['email'] . ' · ' . ucwords(str_replace('_', ' ', (string)$r['status'])),
            'note' => ($r['phone'] ?: 'No phone saved') . ' · Created ' . date('M d, Y', strtotime($r['created_at'])),
        ];
    }
    $caption = $total > count($items) ? 'Showing ' . count($items) . ' of ' . $total . ' matching accounts.' : $total . ' matching account' . ($total === 1 ? '' : 's') . '.';
    return ['html' => notification_dashboard_list($items, 'No matching accounts found for this alert.', $caption), 'count' => count($items), 'eyebrow' => 'Account detail'];
}

function notification_broad_detail_payload($conn, $row, $uid, $role) {
    $text = strtolower(trim(($row['type'] ?? '') . ' ' . ($row['title'] ?? '') . ' ' . ($row['message'] ?? '') . ' ' . ($row['action_url'] ?? '')));
    if (str_contains($text, 'unavailability') || str_contains($text, 'unavailable') || str_contains($text, 'workforce') || str_contains($text, 'leave') || str_contains($text, 'on duty') || str_contains($text, 'clinic schedule')) {
        return notification_workforce_detail($conn, $row, $uid, $role, $text);
    }
    if (str_contains($text, 'appointment') || str_contains($text, 'schedule') || str_contains($text, 'calendar')) {
        return notification_appointment_detail($conn, $row, $uid, $role, $text);
    }
    if (str_contains($text, 'vaccin') || str_contains($text, 'vaccine')) return notification_vaccination_detail($conn, $row, $uid, $role, $text);
    if (str_contains($text, 'stock') || str_contains($text, 'inventory') || str_contains($text, 'supplies')) return notification_inventory_detail($conn, $role);
    if (str_contains($text, 'feedback') || str_contains($text, 'rating')) return notification_feedback_detail($conn, $role);
    if (str_contains($text, 'account') || str_contains($text, 'user') || str_contains($text, 'staff') || str_contains($text, 'access')) return notification_user_detail($conn, $role, $text, $row['action_url'] ?? '');
    if (str_contains($text, 'pet') || str_contains($text, 'record') || str_contains($text, 'profile')) return notification_pet_detail($conn, $uid, $role, $text);
    return ['html' => '', 'count' => 0, 'eyebrow' => 'Notification detail'];
}

function notification_prepare_payload($conn, $row, $uid, $role) {
    $row['exact_target'] = notification_action_has_exact_target($row['action_url'] ?? '') ? 1 : 0;
    $row['display_title'] = (string)($row['title'] ?? 'Notification');
    $row['display_message'] = (string)($row['message'] ?? '');
    if (!$row['exact_target']) {
        $detail = notification_broad_detail_payload($conn, $row, $uid, $role);
        $detailCount = (int)($detail['count'] ?? 0);
        $detailHtml = (string)($detail['html'] ?? '');
        $resolvedLiveData = trim($detailHtml) !== '';
        $row['detail_html'] = $detailHtml;
        $row['detail_count'] = $detailCount;
        $row['detail_eyebrow'] = $detail['eyebrow'] ?? 'Notification detail';

        // If an older notification contains a count, display the current live count so
        // the badge/title and the records opened by the notification never disagree.
        $expected = notification_expected_count(($row['title'] ?? '') . ' ' . ($row['message'] ?? ''));
        if ($expected > 0 && $resolvedLiveData) {
            $row['display_title'] = notification_replace_expected_count((string)($row['title'] ?? ''), $expected, $detailCount);
            $row['display_message'] = notification_replace_expected_count((string)($row['message'] ?? ''), $expected, $detailCount);
        }

        if ($resolvedLiveData) {
            $row['preview'] = $detailCount > 0
                ? $detailCount . ' matching record' . ($detailCount === 1 ? '' : 's') . ' available.'
                : 'No matching records currently require attention.';
        } else {
            $message = trim((string)($row['display_message'] ?? ''));
            $row['preview'] = strlen($message) > 120 ? substr($message, 0, 117) . '...' : $message;
        }
    } else {
        $message = trim((string)($row['display_message'] ?? ''));
        $row['preview'] = strlen($message) > 120 ? substr($message, 0, 117) . '...' : $message;
        $row['detail_html'] = '';
        $row['detail_count'] = 0;
        $row['detail_eyebrow'] = '';
    }
    return $row;
}

if ($action === 'detail') {
    $id = (int)($_GET['id'] ?? 0);
    $stmt = $conn->prepare("SELECT id,title,message,type,status,action_url,created_at FROM notifications WHERE id=? AND user_id=? AND title NOT LIKE '[Deleted] %' LIMIT 1");
    $stmt->bind_param('ii', $id, $uid);
    $stmt->execute();
    $row = $stmt->get_result()->fetch_assoc();
    if (!$row) {
        http_response_code(404);
        echo json_encode(['ok' => false, 'message' => 'Notification not found.']);
        exit;
    }
    echo json_encode(['ok' => true, 'notification' => notification_prepare_payload($conn, $row, $uid, $_SESSION['role'] ?? 'staff')]);
    exit;
}

if ($action === 'all') {
    $stmt = $conn->prepare("SELECT id,title,message,type,status,action_url,created_at FROM notifications WHERE user_id=? AND title NOT LIKE '[Deleted] %' ORDER BY created_at DESC,id DESC");
    $stmt->bind_param('i', $uid);
    $stmt->execute();
    $rows = [];
    $result = $stmt->get_result();
    while ($row = $result->fetch_assoc()) $rows[] = notification_prepare_payload($conn, $row, $uid, $_SESSION['role'] ?? 'staff');
    $unread = notification_query_count($conn, "SELECT COUNT(*) c FROM notifications WHERE user_id=" . (int)$uid . " AND status='unread' AND title NOT LIKE '[Deleted] %'");
    echo json_encode(['ok' => true, 'notifications' => $rows, 'unread' => $unread]);
    exit;
}

if ($action === 'list') {
    $stmt = $conn->prepare("SELECT id,title,message,type,status,action_url,created_at FROM notifications WHERE user_id=? AND title NOT LIKE '[Deleted] %' ORDER BY created_at DESC,id DESC");
    $stmt->bind_param('i', $uid);
    $stmt->execute();
    $rows = [];
    $result = $stmt->get_result();
    while ($row = $result->fetch_assoc()) {
        $rows[] = notification_prepare_payload($conn, $row, $uid, $_SESSION['role'] ?? 'staff');
    }
    $unread = notification_query_count($conn, "SELECT COUNT(*) c FROM notifications WHERE user_id=" . (int)$uid . " AND status='unread' AND title NOT LIKE '[Deleted] %'");
    echo json_encode(['ok' => true, 'notifications' => $rows, 'unread' => $unread]);
    exit;
}

if ($_SERVER['REQUEST_METHOD'] !== 'POST') {
    http_response_code(405);
    echo json_encode(['ok' => false, 'message' => 'Method not allowed.']);
    exit;
}
verify_csrf_or_fail();

if ($action === 'mark_read') {
    $id = (int)($_POST['id'] ?? 0);
    $stmt = $conn->prepare("UPDATE notifications SET status='read', updated_at=NOW() WHERE id=? AND user_id=? AND title NOT LIKE '[Deleted] %'");
    $stmt->bind_param('ii', $id, $uid);
    $stmt->execute();
    echo json_encode(['ok' => true]);
    exit;
}
if ($action === 'mark_all') {
    $stmt = $conn->prepare("UPDATE notifications SET status='read', updated_at=NOW() WHERE user_id=? AND status='unread' AND title NOT LIKE '[Deleted] %'");
    $stmt->bind_param('i', $uid);
    $stmt->execute();
    echo json_encode(['ok' => true]);
    exit;
}

http_response_code(400);
echo json_encode(['ok' => false, 'message' => 'Unknown action.']);
