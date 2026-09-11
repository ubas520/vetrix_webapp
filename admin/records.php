<?php
require_once "../config/database.php";
require_once "../includes/functions.php";
require_role("admin");
flash('success','Medical Records has been moved to the Veterinarian side.');
redirect_to('admin/dashboard.php');
?>
