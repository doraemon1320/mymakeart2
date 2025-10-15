<?php
require_once "../db_connect.php";

// ✅ 僅 admin 可使用
if (!isset($_SESSION['user']) || $_SESSION['user']['username'] !== 'admin') {
    $_SESSION['login_error'] = "僅限系統管理員使用";
    header("Location: ../login.php");
    exit;
}
?>

<!DOCTYPE html>
<html lang="zh-Hant">
<head>
    <meta charset="UTF-8">
    <title>系統管理者後台</title>
    <link rel="stylesheet" href="admin_navbar.css">
</head>
<body>
<?php include "admin_navbar.php"; ?>

<div class="container">
    <h1>👑 系統管理者後台</h1>
    <div class="card-grid">
        <a href="company_requests.php" class="card">🏢 公司帳號審核</a>
        <a href="permissions_list.php" class="card">🔐 權限功能管理</a>
        <!-- 預留更多 admin 功能 -->
        <a href="../logout.php" class="card logout">🚪 登出</a>
    </div>
</div>
</body>
</html>
