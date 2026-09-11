<?php
require_once "../config/database.php";
require_once "../includes/functions.php";
require_role("admin");
flash('success','Creating medical records is now available only on the Veterinarian side.');
redirect_to('admin/appointments.php');
?>
