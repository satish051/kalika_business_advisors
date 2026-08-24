<?php
// api/admin/logout.php
require_once 'auth.php';
destroy_stateless_session();
header("Location: /api/admin/login.php");
exit;
?>
