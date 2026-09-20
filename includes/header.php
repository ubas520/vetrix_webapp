<?php
require_once __DIR__ . "/functions.php";
if (!headers_sent()) {
    header('X-Content-Type-Options: nosniff');
    header('X-Frame-Options: SAMEORIGIN');
    header('Referrer-Policy: strict-origin-when-cross-origin');
    header("Permissions-Policy: camera=" . (!empty($allowQrCamera) ? "(self)" : "()") . ", microphone=(), geolocation=()");
    header("Content-Security-Policy: frame-ancestors 'self'; object-src 'none'; base-uri 'self'; form-action 'self'");
}
$title = $title ?? "Vetrix";
$roleClass = 'role-' . ($_SESSION['role'] ?? 'guest');
$scriptName = basename((string)($_SERVER['SCRIPT_NAME'] ?? ''));
if (preg_match('/^(?:reports|report_|export\.php)/', $scriptName)) $roleClass .= ' report-page';
$styleVersion = file_exists(__DIR__ . '/../assets/css/style.css') ? filemtime(__DIR__ . '/../assets/css/style.css') : time();
$referenceStyleVersion = file_exists(__DIR__ . '/../assets/css/vetrix-reference.css') ? filemtime(__DIR__ . '/../assets/css/vetrix-reference.css') : time();
$enterpriseStyleVersion = filemtime(__DIR__ . '/../assets/css/vetrix-enterprise.css');
$redesignStyleVersion = file_exists(__DIR__ . '/../assets/css/vetrix-redesign.css') ? filemtime(__DIR__ . '/../assets/css/vetrix-redesign.css') : time();
?>
<!DOCTYPE html>
<html lang="en">
<head>
    <meta charset="UTF-8">
    <title><?= e($title) ?></title>
    <meta name="viewport" content="width=device-width, initial-scale=1, viewport-fit=cover">
    <meta name="theme-color" content="#3569F4">
    <meta name="color-scheme" content="light">
    <meta name="csrf-token" content="<?= e(csrf_token()) ?>">
    <link rel="preconnect" href="https://fonts.googleapis.com">
    <link rel="preconnect" href="https://fonts.gstatic.com" crossorigin>
    <link href="https://fonts.googleapis.com/css2?family=Manrope:wght@400;500;600;700;800&display=swap" rel="stylesheet">
    <link href="<?= app_url('assets/css/bootstrap.min.css') ?>" rel="stylesheet">
    <link href="<?= app_url('assets/css/style.css') ?>?v=<?= e($styleVersion) ?>" rel="stylesheet">
    <link href="<?= app_url('assets/css/vetrix-reference.css') ?>?v=<?= e($referenceStyleVersion) ?>" rel="stylesheet">
    <link href="<?= app_url('assets/css/vetrix-enterprise.css') ?>?v=<?= e($enterpriseStyleVersion) ?>" rel="stylesheet" media="screen">
    <link href="<?= app_url('assets/css/vetrix-redesign.css') ?>?v=<?= e($redesignStyleVersion) ?>" rel="stylesheet" media="screen">
    <?php if (($_SESSION['role'] ?? '') === 'veterinarian' && in_array($scriptName, ['medical_records.php','prescription.php','vaccinations.php'], true)): ?>
    <link href="<?= app_url('assets/css/clinical-record-cards.css') ?>?v=<?= e(filemtime(__DIR__ . '/../assets/css/clinical-record-cards.css')) ?>" rel="stylesheet" media="screen">
    <?php endif; ?>
    <?php if (($_SESSION['role'] ?? '') === 'admin' && $scriptName === 'reports.php'): ?>
    <link href="<?= app_url('assets/css/report-cards.css') ?>?v=<?= e(hash_file('sha256', __DIR__ . '/../assets/css/report-cards.css')) ?>" rel="stylesheet" media="screen">
    <?php endif; ?>
    <script>try{if(localStorage.getItem('vetrix.sidebar.expanded')==='0')document.documentElement.classList.add('vetrix-precollapsed')}catch(e){}</script>
    <style>body.vetrix-shell-prepaint .app-topbar,body.vetrix-shell-prepaint .layout{visibility:hidden!important}</style>
</head>
<body class="<?= e($roleClass) ?> enterprise-ui" data-app-base="<?= app_url('') ?>" data-user-role="<?= e($_SESSION['role'] ?? 'guest') ?>" data-page-title="<?= e($title) ?>">
<script>try{if(document.documentElement.classList.contains('vetrix-precollapsed')&&matchMedia('(min-width:1024px)').matches)document.body.classList.add('sidebar-collapsed')}catch(e){}document.body.classList.add('vetrix-shell-prepaint');</script>
<a class="skip-link" href="#mainContent">Skip to main content</a>
