<?php
if (session_status() === PHP_SESSION_NONE) session_start();

$conn = new mysqli("localhost", "root", "", "mymakeart");
if ($conn->connect_error) {
    die("資料庫連線失敗：" . $conn->connect_error);
}
?>

