<?php
require_once "config/database.php";
require_once "includes/functions.php";
if ($_SERVER['REQUEST_METHOD'] !== 'POST') {
    redirect_to(is_logged_in() ? dashboard_for_role($_SESSION['role'] ?? 'client') : 'login.php');
}
$_SESSION = [];
if (ini_get('session.use_cookies')) {
    $params = session_get_cookie_params();
    setcookie(session_name(), '', time() - 42000, $params['path'], $params['domain'] ?? '', $params['secure'], $params['httponly']);
}
session_destroy();
header('Location: ' . app_url('login.php?signed_out=1'));
exit;
