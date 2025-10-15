<?php
if (session_status() === PHP_SESSION_NONE) session_start();
$emp_number = $_SESSION['user']['employee_number'] ?? '';
$emp_name = $_SESSION['user']['name'] ?? '未知';
$current_page = basename($_SERVER['PHP_SELF']);
?>
<!-- ✅ 導航列樣式 -->
    <link rel="stylesheet" href="admin_navbar.css">
<!-- 管理員黑色導航列 -->
<div class="navbar">
  <div class="navbar-inner">

    <!-- 🔹 管理員首頁 -->
    <a href="admin_home.php" class="<?= $current_page === 'admin_home.php' ? 'active' : '' ?>">主管首頁</a>

    <!-- 🔹 審核申請 -->
    <div class="dropdown">
      <button class="dropdown-button <?= in_array($current_page, ['admin_review.php', 'manager_request_leave.php', 'manager_overtime_request.php']) ? 'active' : '' ?>">審核申請</button>
      <div class="dropdown-content">
        <a href="admin_review.php">前往審核</a>
        <a href="manager_request_leave.php">員工請假登入</a>
        <a href="manager_overtime_request.php">員工加班登入</a>
      </div>
    </div>

    <!-- 🔹 員工管理 -->
    <div class="dropdown">
      <button class="dropdown-button <?= in_array($current_page, ['employee_list.php', 'add_employee.php']) ? 'active' : '' ?>">員工管理</button>
      <div class="dropdown-content">
        <a href="employee_list.php">員工資料</a>
        <a href="add_employee.php">新增員工</a>
      </div>
    </div>

    <!-- 🔹 薪資管理 -->
    <div class="dropdown">
      <button class="dropdown-button <?= in_array($current_page, ['import_attendance.php', 'attendance_list.php', 'employee_salary_report.php']) ? 'active' : '' ?>">薪資管理</button>
      <div class="dropdown-content">
        <a href="import_attendance.php">匯入打卡資料</a>
        <a href="attendance_list.php">考勤紀錄表</a>
        <a href="employee_salary_report.php">薪資報表</a>
	<a href="salary_overview.php">員工薪資總表</a>
      </div>
    </div>

    <!-- 🔹 系統設定 -->
    <div class="dropdown">
      <button class="dropdown-button <?= in_array($current_page, ['shift_settings.php', 'settings.php', 'upload_holidays.php', 'vacation_management.php']) ? 'active' : '' ?>">系統設定</button>
      <div class="dropdown-content">
        <a href="shift_settings.php">班別設定</a>
        <a href="settings.php">假期設定</a>
        <a href="upload_holidays.php">匯入台灣假日資料</a>
        <a href="vacation_management.php">特休額度檢查</a>
      </div>
    </div>

    <!-- 🔄 切換到員工系統 -->
    <a href="../employee/employee_home.php" style="color: #ffc107; font-weight: bold;">員工系統</a>

    <!-- 👤 使用者資訊區 -->
    <div class="user-area">
      <span>👤 <?= htmlspecialchars($emp_name) ?></span>
      <a href="../login.php" class="logout-button">登出</a>
    </div>
  </div>
</div>
