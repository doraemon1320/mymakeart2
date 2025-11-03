<?php
// PHP 功能 #1：啟動工作階段並取得使用者資料
if (session_status() === PHP_SESSION_NONE) session_start();
$emp_number = $_SESSION['user']['employee_number'] ?? '';
$emp_name = $_SESSION['user']['name'] ?? '未知';
// PHP 功能 #2：偵測目前頁面對應的選單項目
$current_page = basename($_SERVER['PHP_SELF']);
?>
<!-- ✅ 導航列樣式 -->
<link rel="stylesheet" href="admin_navbar.css">

<!-- ✅ 保留原有雙層風格：上層品牌帶、下層藍色功能列 -->
<nav class="admin-navbar">
  <div class="admin-navbar-top">
    <div class="container">
      <div class="row align-items-center gy-2">
        <div class="col-auto">
          <!-- PHP 功能 #3：提供返回主管首頁的公司 LOGO 連結 -->
          <a href="admin_home.php" class="navbar-logo" aria-label="返回主管首頁">
            <img src="../LOGO/LOGO-05.png" alt="公司識別 LOGO">
          </a>
        </div>
        <div class="col">
          <div class="brand-info">
            <span class="brand-title">管理員資訊中心</span>
            <span class="brand-subtitle">快速掌握營運決策的最佳夥伴</span>
          </div>
        </div>
        <div class="col-auto">
          <div class="top-actions">
            <a href="../employee/employee_home.php" class="btn btn-light btn-sm">員工系統</a>
          </div>
        </div>
        <div class="col-auto">
          <!-- PHP 功能 #4：顯示登入者名稱與登出連結 -->
          <div class="user-area">
            <span>👤 <?= htmlspecialchars($emp_name) ?></span>
            <a href="../login.php" class="logout-button">登出</a>
          </div>
        </div>
      </div>
    </div>
  </div>

  <div class="admin-navbar-bottom">
    <div class="container">
      <div class="menu-inner">
        <!-- 🔹 管理員首頁 -->
        <a href="admin_home.php" class="admin-nav-link <?= $current_page === 'admin_home.php' ? 'active' : '' ?>">主管首頁</a>

        <!-- 🔹 審核申請 -->
        <div class="admin-dropdown">
          <button type="button" class="dropdown-button <?= in_array($current_page, ['admin_review.php', 'manager_request_leave.php', 'manager_overtime_request.php']) ? 'active' : '' ?>">審核申請</button>
          <div class="dropdown-content">
            <a href="admin_review.php">前往審核</a>
            <a href="manager_request_leave.php">員工請假登入</a>
            <a href="manager_overtime_request.php">員工加班登入</a>
          </div>
        </div>

        <!-- 🔹 員工管理 -->
        <div class="admin-dropdown">
          <button type="button" class="dropdown-button <?= in_array($current_page, ['employee_list.php', 'add_employee.php']) ? 'active' : '' ?>">員工管理</button>
          <div class="dropdown-content">
            <a href="employee_list.php">員工資料</a>
            <a href="add_employee.php">新增員工</a>
          </div>
        </div>

        <!-- 🔹 薪資管理 -->
        <div class="admin-dropdown">
          <button type="button" class="dropdown-button <?= in_array($current_page, ['import_attendance.php', 'attendance_list.php', 'employee_salary_report.php', 'salary_overview.php', 'vacation_management.php']) ? 'active' : '' ?>">薪資管理</button>
          <div class="dropdown-content">
	    <a href="salary_overview.php">員工薪資總表</a>
            <a href="vacation_management.php">特休額度檢查</a>
            <a href="import_attendance.php">匯入打卡資料</a>
            <a href="attendance_list.php">個人考勤紀錄表</a>
            <a href="employee_salary_report.php">個人薪資報表</a>
            
          </div>
        </div>

        <!-- 🔹 系統設定 -->
        <div class="admin-dropdown">
          <button type="button" class="dropdown-button <?= in_array($current_page, ['shift_settings.php', 'settings.php', 'upload_holidays.php']) ? 'active' : '' ?>">系統設定</button>
          <div class="dropdown-content">
            <a href="shift_settings.php">班別設定</a>
            <a href="settings.php">假期設定</a>
	    <a href="taiwan_holiday_list.php">台灣假日清單</a>
            <a href="upload_holidays.php">匯入台灣假日資料</a>
            
          </div>
        </div>

        <!-- 🔄 切換到員工系統 -->
        
      </div>
    </div>
  </div>
</nav>

<!-- JS 功能 #1：動態確認是否已載入 jQuery -->
<script>
  (function () {
    // JS 功能 #1-1：若頁面尚未載入 jQuery，動態載入 CDN 版本
    function ensureJQuery(callback) {
      if (window.jQuery) {
        callback(window.jQuery);
        return;
      }

      var script = document.createElement('script');
      script.src = 'https://code.jquery.com/jquery-3.7.1.min.js';
      script.integrity = 'sha256-7SLUjR0AB+cG2kZZgwyXR0TfLiWZ+CXFqPLZp0iC2jc=';
      script.crossOrigin = 'anonymous';
      script.onload = function () {
        callback(window.jQuery);
      };
      document.head.appendChild(script);
    }

    // JS 功能 #1-2：處理滑鼠移動時的下拉延遲，避免縫隙導致收合
    ensureJQuery(function ($) {
      var hoverDelay = 120;

      $('.admin-dropdown').each(function () {
        var $dropdown = $(this);
        var timer;

        var openMenu = function () {
          clearTimeout(timer);
          $dropdown.addClass('open');
        };

        var closeMenu = function () {
          clearTimeout(timer);
          timer = setTimeout(function () {
            $dropdown.removeClass('open');
          }, hoverDelay);
        };

        $dropdown.on('mouseenter focusin', openMenu);
        $dropdown.on('mouseleave', closeMenu);
        $dropdown.on('focusout', function (event) {
          var related = event.relatedTarget;
          if (related && $dropdown.has(related).length) {
            return;
          }
          closeMenu();
        });
      });
    });
  })();
</script>
