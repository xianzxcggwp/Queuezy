<?php
$host = getenv('DB_HOST') ?: getenv('MYSQL_HOST') ?: 'localhost';
$user = getenv('DB_USER') ?: getenv('MYSQL_USER') ?: 'root';
$password = getenv('DB_PASS') ?: getenv('MYSQL_PASSWORD') ?: '';
$dbname = getenv('DB_NAME') ?: getenv('MYSQL_DATABASE') ?: 'queuezy_db';

mysqli_report(MYSQLI_REPORT_OFF);
$conn = @new mysqli($host, $user, $password, $dbname);

if ($conn->connect_error) {
    error_log('Queuezy database connection failed: ' . $conn->connect_error);
    $conn = null;
}
?>
