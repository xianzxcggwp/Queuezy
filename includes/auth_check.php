<?php
session_start();

function checkRole($allowed_roles = []) {
    if (!isset($_SESSION['user_id'])) {
        header("Location: ../login.php");
        exit();
    }

    if (!in_array($_SESSION['user_role'], $allowed_roles)) {
        echo "Access Denied: You do not have permission to view this page.";
        exit();
    }
}
?>