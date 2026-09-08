<?php
session_start();
$_SESSION = [];
session_destroy();
header("Location: dist/login.php");
exit(); 
?>
