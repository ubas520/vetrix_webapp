<?php
require_once '../config/database.php';
require_once '../includes/functions.php';
require_role('admin');
header('Content-Type: application/json; charset=utf-8');
header('Cache-Control: no-store');
if ($_SERVER['REQUEST_METHOD'] !== 'POST') {
    http_response_code(405);
    header('Allow: POST');
    echo json_encode(['error' => 'Use POST.']);
    exit;
}
verify_csrf_or_fail();

function chart_rows($conn, $sql, $types = '', $params = []) {
    $stmt = $conn->prepare($sql);
    if (!$stmt) throw new RuntimeException('Chart query failed.');
    if ($types) $stmt->bind_param($types, ...$params);
    if (!$stmt->execute()) throw new RuntimeException('Chart query failed.');
    return $stmt->get_result()->fetch_all(MYSQLI_ASSOC);
}
function chart_months($rows, $months) {
    $counts = array_column($rows, 'total', 'month');
    return array_map(fn($month) => (int)($counts[$month] ?? 0), $months);
}
try {
    $kind = $_POST['kind'] ?? '';
    $months = [];
    $first = new DateTimeImmutable('first day of this month');
    for ($i = 5; $i >= 0; $i--) $months[] = $first->modify("-$i months")->format('Y-m');
    $start = $months[0] . '-01';
    $end = $first->modify('+1 month')->format('Y-m-d');
    $labels = array_map(fn($m) => date('M Y', strtotime($m . '-01')), $months);
    $data = ['type' => 'bar', 'labels' => [], 'datasets' => []];
    if (in_array($kind, ['pet', 'qr'], true)) {
        if ($kind === 'pet') {
            $pets = chart_rows($conn, "SELECT id,name FROM pets WHERE id=? AND verification_status='approved'", 'i', [(int)($_POST['pet_id'] ?? 0)]);
        } else {
            $pets = chart_rows($conn, "SELECT p.id,p.name FROM qr_tokens q JOIN pets p ON p.id=q.pet_id WHERE q.token=? AND q.status='active' AND q.expires_at>NOW() AND p.verification_status='approved' LIMIT 1", 's', [trim($_POST['token'] ?? '')]);
        }
        if (!$pets) throw new InvalidArgumentException($kind === 'pet' ? 'Select an approved pet to see medical visits.' : 'Enter a valid active QR token to see medical visits.');
        $rows = chart_rows($conn, "SELECT DATE_FORMAT(visit_date,'%Y-%m') month,COUNT(*) total FROM medical_records WHERE pet_id=? AND visit_date>=? AND visit_date<? GROUP BY month", 'iss', [(int)$pets[0]['id'], $start, $end]);
        $data += ['title' => $pets[0]['name'] . ' · Medical visits, last 6 months'];
        $data['labels'] = $labels;
        $data['datasets'] = [['label' => 'Medical visits', 'data' => chart_months($rows, $months)]];
    } elseif ($kind === 'appointments') {
        $from = $_POST['from'] ?? '';
        $to = $_POST['to'] ?? '';
        foreach ([$from, $to] as $date) {
            $parsed = DateTimeImmutable::createFromFormat('!Y-m-d', $date);
            if (!$parsed || $parsed->format('Y-m-d') !== $date) throw new InvalidArgumentException('Choose valid From and To dates.');
        }
        if ($from > $to) throw new InvalidArgumentException('From date must be before or equal to To date.');
        $rows = chart_rows($conn, "SELECT a.status,COUNT(*) total FROM appointments a JOIN users u ON u.id=a.owner_id JOIN pets p ON p.id=a.pet_id WHERE DATE(COALESCE(a.scheduled_date,a.requested_date)) BETWEEN ? AND ? GROUP BY a.status ORDER BY a.status", 'ss', [$from, $to]);
        $data = ['type' => 'doughnut', 'title' => 'Appointment status · ' . $from . ' to ' . $to, 'labels' => array_map(fn($r) => ucwords(str_replace('_', ' ', $r['status'])), $rows), 'datasets' => [['label' => 'Appointments', 'data' => array_map('intval', array_column($rows, 'total'))]]];
    } elseif ($kind === 'vaccinations') {
        $days = (int)($_POST['days'] ?? 60);
        if (!in_array($days, [30, 60, 90, 365], true)) throw new InvalidArgumentException('Choose a valid due period.');
        $rows = chart_rows($conn, "SELECT CASE WHEN v.next_due_date<CURDATE() THEN 'Overdue' ELSE 'Upcoming' END category,COUNT(*) total FROM vaccinations v JOIN pets p ON p.id=v.pet_id JOIN users u ON u.id=p.owner_id WHERE v.next_due_date<=DATE_ADD(CURDATE(),INTERVAL ? DAY) GROUP BY category", 'i', [$days]);
        $counts = array_column($rows, 'total', 'category');
        $data = ['type' => 'doughnut', 'title' => "Vaccination doses · Next $days days and overdue", 'labels' => ['Overdue', 'Upcoming'], 'datasets' => [['label' => 'Doses', 'backgroundColor' => ['#dc6575', '#18a999'], 'data' => [(int)($counts['Overdue'] ?? 0), (int)($counts['Upcoming'] ?? 0)]]]];
    } elseif ($kind === 'directory') {
        $clients = chart_rows($conn, "SELECT DATE_FORMAT(created_at,'%Y-%m') month,COUNT(*) total FROM users WHERE role='client' AND created_at>=? AND created_at<? GROUP BY month", 'ss', [$start, $end]);
        $pets = chart_rows($conn, "SELECT DATE_FORMAT(created_at,'%Y-%m') month,COUNT(*) total FROM pets WHERE created_at>=? AND created_at<? GROUP BY month", 'ss', [$start, $end]);
        $data = ['type' => 'line', 'title' => 'New registrations · Last 6 months', 'labels' => $labels, 'datasets' => [['label' => 'Clients', 'data' => chart_months($clients, $months)], ['label' => 'Pets', 'data' => chart_months($pets, $months)]]];
    } elseif ($kind === 'analytics') {
        $rows = chart_rows($conn, "SELECT COALESCE(NULLIF(TRIM(species),''),'Unspecified') species,COUNT(*) total FROM pets WHERE verification_status='approved' GROUP BY species ORDER BY total DESC,species");
        $data = ['type' => 'bar', 'horizontal' => true, 'title' => 'Approved pets by species', 'labels' => array_column($rows, 'species'), 'datasets' => [['label' => 'Pets', 'data' => array_map('intval', array_column($rows, 'total'))]]];
    } else {
        throw new InvalidArgumentException('Unknown report chart.');
    }
    echo json_encode($data, JSON_THROW_ON_ERROR);
} catch (InvalidArgumentException $error) {
    http_response_code(422);
    echo json_encode(['error' => $error->getMessage()]);
} catch (Throwable $error) {
    error_log('Report chart: ' . $error->getMessage());
    http_response_code(500);
    echo json_encode(['error' => 'Chart data is temporarily unavailable.']);
}
