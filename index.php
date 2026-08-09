<?php
ini_set('display_errors', 1);
ini_set('display_startup_errors', 1);
error_reporting(E_ALL);
// هذا الكود يحول الزائر فوراً إلى مسار الأدمن
header("Location: /public/admin/login.php");
exit;
?>