<?php
// Exercise request handling without changing clinic data or sending notifications.
function verify_csrf_or_fail() { $GLOBALS['csrfChecked'] = true; }
function log_action(...$args) { $GLOBALS['logged'] = true; }
function notify_user(...$args) { $GLOBALS['notified'] = true; }
function flash(...$args) {}
function redirect_to($path) { throw new RuntimeException($path); }
class PrescriptionTestResult {
    function __construct(private $row) {}
    function fetch_assoc() { return $this->row; }
    function fetch_all($mode) { return []; }
}
class PrescriptionTestStatement {
    public $affected_rows;
    function __construct(private $db, private $sql) { $this->affected_rows = $db->affected; }
    function bind_param($types, &...$values) { $this->db->params[] = $values; }
    function execute() { return true; }
    function get_result() { return new PrescriptionTestResult($this->db->row); }
}
class PrescriptionTestDb {
    public $sql = [], $params = [];
    function __construct(public $row, public $affected = 1) {}
    function prepare($sql) { $this->sql[] = $sql; return new PrescriptionTestStatement($this, $sql); }
    function query($sql) { return new PrescriptionTestResult(null); }
}
$valid = ['record_id'=>12,'medicine'=>'Test medicine','dose'=>'Test dose','frequency'=>'Test frequency','duration'=>'Test duration'];
foreach (['missing', 'unavailable', 'saved', 'concurrent'] as $case) {
    $_SERVER['REQUEST_METHOD'] = 'POST';
    $_GET = [];
    $_POST = $case === 'missing' ? ['record_id'=>12] : $valid;
    $csrfChecked = $logged = $notified = false;
    $conn = new PrescriptionTestDb($case === 'unavailable' ? null : ['id'=>12,'pet_id'=>7,'owner_id'=>4,'name'=>'Test pet'], $case === 'concurrent' ? 0 : 1);
    $redirect = '';
    try { include __DIR__.'/../includes/prescription_entry.php'; }
    catch (RuntimeException $e) { $redirect = $e->getMessage(); }
    if (!$csrfChecked) throw new Exception('CSRF verification was skipped');
    if ($case === 'saved') {
        if ($redirect !== 'vet/prescription.php?pet_id=7#medicalRecord12' || !$logged || !$notified) throw new Exception('Successful save did not finish correctly');
        if (!str_starts_with($conn->sql[1], 'UPDATE medical_records SET prescription=? WHERE id=?')) throw new Exception('Save must only update medication on the chosen visit');
        if ($conn->params[1][1] !== 12 || !str_contains($conn->params[1][0], 'Frequency: Test frequency')) throw new Exception('Medication or consultation binding incorrect');
    } elseif ($prescriptionError === '' || $redirect !== '' || $logged || $notified) {
        throw new Exception('Invalid or concurrent save must not report success');
    }
    foreach ($conn->sql as $sql) if (str_contains($sql, 'INSERT')) throw new Exception('Prescription must not create another visit');
}
echo "Prescription workflow checks passed (validation, unavailable visit, save, concurrent save).\n";
