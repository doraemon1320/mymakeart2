<?php
// ✅ 僅 admin 可使用
if (!isset($_SESSION['user']) || $_SESSION['user']['username'] !== 'admin') {
    $_SESSION['login_error'] = "僅限系統管理員使用";
    header("Location: ../login.php");
    exit;
}
?>

<div class="navbar">
    <a href="admin_home.php">🏠 系統首頁</a>
    <a href="company_requests.php">🏢 公司申請審核</a>
    <a href="permissions_list.php">🔐 權限功能清單</a>
    <a href="../logout.php" class="logout">🚪 登出</a>
</div>