<?php
session_start();

// ✅ 權限檢查：只允許 admin 或 is_manager = 1 的人進入
if (!isset($_SESSION['user']) || ($_SESSION['user']['role'] !== 'admin' && $_SESSION['user']['is_manager'] != 1)) {
    header("Location: ../login.php");
    exit;
}

$user_id = $_SESSION['user']['id'];
$username = htmlspecialchars($_SESSION['user']['username']);
?>
<!DOCTYPE html>
<html lang="zh-TW">
<head>
    <meta charset="UTF-8">
    <title>主管首頁</title>
    <meta name="viewport" content="width=device-width, initial-scale=1">

    <!-- ✅ Bootstrap -->
    <link href="https://cdn.jsdelivr.net/npm/bootstrap@5.3.3/dist/css/bootstrap.min.css" rel="stylesheet">

    <!-- ✅ 導航列樣式 -->
    <link rel="stylesheet" href="admin_navbar.css">

    <style>
        .dashboard {
            display: flex;
            flex-wrap: wrap;
            gap: 20px;
            margin-top: 30px;
        }
        .dashboard-item {
            flex: 1 1 30%;
            background: #fff;
            border: 1px solid #ddd;
            border-radius: 8px;
            padding: 20px;
            box-shadow: 0 2px 6px rgba(0,0,0,0.1);
        }
        .dashboard-item h2 {
            font-size: 1.3rem;
            margin-bottom: 15px;
        }
        .dashboard-item .btn {
            margin-right: 10px;
            margin-top: 10px;
        }
        body {
            background-color: #f8f9fa;
        }
    </style>
</head>
<body>
    <?php include 'admin_navbar.php'; ?>

    <div class="container mt-5">
        <div class="mb-4">
            <h3>👤 歡迎回來，<?= $username ?>！</h3>
            <p class="text-muted">您可以透過以下功能快速進入管理區操作。</p>
        </div>

        <div class="dashboard">
            <div class="dashboard-item">
                <h2>📋 審核申請</h2>
                <p>查看員工的請假與加班申請，並進行審核操作。</p>
               <a href="admin_review.php" class="btn btn-primary me-2">前往審核</a>
			   <a href="manager_request_leave.php" class="btn btn-outline-primary me-2">員工請假登入</a>
			   <a href="manager_overtime_request.php" class="btn btn-outline-primary">員工加班登入</a>

            </div>

            <div class="dashboard-item">
                <h2>👥 員工管理</h2>
                <p>管理員工的資料，並新增員工帳號。</p>
                <a href="employee_list.php" class="btn btn-success me-2">查看員工</a>
				<a href="add_employee.php" class="btn btn-outline-success me-2">新增員工</a>
            </div>
			
			<div class="dashboard-item">
				<h2>💰 薪資管理</h2>
				<p>管理員工的打卡、考勤紀錄與薪資計算。</p>
				<a href="import_attendance.php" class="btn btn-outline-success me-2">匯入打卡資料</a>
				<a href="attendance_list.php" class="btn btn-outline-success me-2">考勤紀錄表</a>
				<a href="employee_salary_report.php" class="btn btn-outline-success">薪資報表</a>
			</div>

			

            <div class="dashboard-item">
                <h2>⚙️ 系統設定</h2>
                <p>管理班別與假期設定，確保系統符合企業需求。</p>
                <a href="shift_settings.php" class="btn btn-warning me-2">班別設定</a>
				<a href="settings.php" class="btn btn-outline-warning me-2">假期設定</a>
				<a href="upload_holidays.php" class="btn btn-outline-warning me-2">匯入假日</a>
				<a href="vacation_management.php" class="btn btn-outline-warning">特休額度檢查</a>

            </div>
        </div>
    </div>

    <!-- Bootstrap JS -->
    <script src="https://cdn.jsdelivr.net/npm/bootstrap@5.3.3/dist/js/bootstrap.bundle.min.js"></script>
</body>
</html>
