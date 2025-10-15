<?php
if (session_status() === PHP_SESSION_NONE) session_start();

// ✅ 安全取得是否為 admin 或主管
$is_admin = isset($_SESSION['user']['role']) && $_SESSION['user']['role'] === 'admin';
$is_manager = isset($_SESSION['user']['is_manager']) && $_SESSION['user']['is_manager'];
?>
<!-- ✅ 外層黑色背景 -->
<div class="navbar-custom">
    <div class="container d-flex justify-content-between align-items-center">
        <div class="nav-left">
            <strong class="text-white me-3">員工系統</strong>
            <a href="/mymakeart/employee/employee_home.php">🏠首頁</a>
            <a href="/mymakeart/employee/edit_profile.php">✏️修改資料</a>
            <a href="/mymakeart/employee/request_leave.php">🟡申請請假/加班</a>
            <a href="/mymakeart/employee/history_requests.php">📄我的申請</a>
            <a href="/mymakeart/employee/tasks_list.php">✅我的任務</a>
            <a href="/mymakeart/employee/notifications.php">🔔通知</a>
            <a href="/mymakeart/employee/employee_salary_summary.php" class="active">💰我的薪資</a>
        </div>
        <div class="nav-right">
            <?php if ($is_admin): ?>
                <a href="/mymakeart/admin/admin_home.php" class="admin-btn">⚙️ 管理員模式</a>
            <?php endif; ?>
            <span class="text-white"><?= htmlspecialchars($_SESSION['user']['name'] ?? '') ?></span>
            <a href="/mymakeart/login.php" class="logout-btn">登出</a>
        </div>
    </div>
</div>