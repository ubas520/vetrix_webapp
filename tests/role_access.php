<?php
session_save_path(sys_get_temp_dir());
require_once __DIR__ . '/../includes/functions.php';

$cases = [
    ['admin', 'admin', true],
    ['admin', 'veterinarian', true],
    ['admin', 'staff', true],
    ['admin', ['veterinarian', 'staff'], true],
    ['veterinarian', 'veterinarian', true],
    ['veterinarian', 'admin', false],
    ['staff', 'veterinarian', false],
    ['client', ['veterinarian', 'staff'], false],
    ['', 'veterinarian', false],
];
foreach ($cases as [$role, $required, $expected]) {
    if (role_has_access($role, $required) !== $expected) {
        throw new RuntimeException('Unexpected access for ' . $role);
    }
}
$_SESSION['user_id'] = 1;
$_SESSION['role'] = 'admin';
require_role('veterinarian');
require_role('staff');
if ($_SESSION['role'] !== 'admin') {
    throw new RuntimeException('Administrator identity must be preserved.');
}
session_destroy();
echo "Role access checks passed.\n";
