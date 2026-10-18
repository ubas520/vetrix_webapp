<?php
require_once "../config/database.php";
require_once "../includes/functions.php";
require_role("admin");
redirect_to('vet/medical_records.php' . (!empty($_GET) ? '?' . http_build_query($_GET) : ''));
?>
