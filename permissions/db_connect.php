<?php
session_start();

// 檢查是否登入
if ($_SESSION['user']['username'] !== 'admin') {
    header("Location: ../login.php");
    exit;
}
$conn = new mysqli('localhost', 'root', '', 'mymakeart');
if ($conn->connect_error) {
    die("資料庫連接失敗：" . $conn->connect_error);
}

?>

