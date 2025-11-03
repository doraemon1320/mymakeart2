<?php
session_start();

// 【PHP-1】權限檢查：只允許 admin 或 is_manager = 1 的人進入
if (!isset($_SESSION['user']) || ($_SESSION['user']['role'] !== 'admin' && $_SESSION['user']['is_manager'] != 1)) {
    header("Location: ../login.php");
    exit;
}

// 【PHP-2】整理使用者資訊
$user_id = $_SESSION['user']['id'];
$username = htmlspecialchars($_SESSION['user']['username']);

// 【PHP-3】連線資料庫並準備儀表板所需的即時資料
require_once '../db_connect.php';

$metrics = [
    'pending_leave' => 0,
    'pending_overtime' => 0,
    'leave_hours' => 0.0,
    'overtime_hours' => 0.0,
    'attendance_alerts' => 0,
];

$todoItems = [];
$recentApprovals = [];

// 【PHP-4】統計待審核請假與加班件數
if ($stmt = $conn->prepare("SELECT COUNT(*) AS total FROM requests WHERE type = ? AND status = 'Pending'")) {
    $typeLeave = '請假';
    $stmt->bind_param('s', $typeLeave);
    $stmt->execute();
    $result = $stmt->get_result()->fetch_assoc();
    $metrics['pending_leave'] = (int)($result['total'] ?? 0);
    $stmt->close();
}

if ($stmt = $conn->prepare("SELECT COUNT(*) AS total FROM requests WHERE type = ? AND status = 'Pending'")) {
    $typeOvertime = '加班';
    $stmt->bind_param('s', $typeOvertime);
    $stmt->execute();
    $result = $stmt->get_result()->fetch_assoc();
    $metrics['pending_overtime'] = (int)($result['total'] ?? 0);
    $stmt->close();
}

// 【PHP-5】計算本月請假與加班時數（已核准）
if ($stmt = $conn->prepare("SELECT COALESCE(SUM(TIMESTAMPDIFF(MINUTE, start_date, end_date)), 0) AS total_minutes FROM requests WHERE type = '請假' AND status = 'Approved' AND start_date IS NOT NULL AND end_date IS NOT NULL AND YEAR(start_date) = YEAR(CURDATE()) AND MONTH(start_date) = MONTH(CURDATE())")) {
    $stmt->execute();
    $result = $stmt->get_result()->fetch_assoc();
    $metrics['leave_hours'] = round(((int)($result['total_minutes'] ?? 0)) / 60, 1);
    $stmt->close();
}

if ($stmt = $conn->prepare("SELECT COALESCE(SUM(TIMESTAMPDIFF(MINUTE, start_date, end_date)), 0) AS total_minutes FROM requests WHERE type = '加班' AND status = 'Approved' AND start_date IS NOT NULL AND end_date IS NOT NULL AND YEAR(start_date) = YEAR(CURDATE()) AND MONTH(start_date) = MONTH(CURDATE())")) {
    $stmt->execute();
    $result = $stmt->get_result()->fetch_assoc();
    $metrics['overtime_hours'] = round(((int)($result['total_minutes'] ?? 0)) / 60, 1);
    $stmt->close();
}

// 【PHP-6】統計近七日出勤異常紀錄
if ($stmt = $conn->prepare("SELECT COUNT(*) AS total FROM saved_attendance WHERE date >= DATE_SUB(CURDATE(), INTERVAL 7 DAY) AND status_text IS NOT NULL AND status_text NOT IN ('正常出勤','國定假日')")) {
    $stmt->execute();
    $result = $stmt->get_result()->fetch_assoc();
    $metrics['attendance_alerts'] = (int)($result['total'] ?? 0);
    $stmt->close();
}

// 【PHP-7】待辦清單：整理待審核申請與出勤異常
if ($stmt = $conn->prepare("SELECT name, type, subtype, start_date, end_date, created_at FROM requests WHERE status = 'Pending' ORDER BY created_at ASC LIMIT 6")) {
    $stmt->execute();
    $result = $stmt->get_result();
    while ($row = $result->fetch_assoc()) {
        $title = ($row['type'] === '加班' ? '加班審核' : '請假審核') . '｜' . ($row['name'] ?? '未填寫姓名');
        $period = '';
        if (!empty($row['start_date']) && !empty($row['end_date'])) {
            $period = date('m/d H:i', strtotime($row['start_date'])) . ' - ' . date('m/d H:i', strtotime($row['end_date']));
        }
        $todoItems[] = [
            'title' => $title,
            'description' => $period ?: ('申請時間：' . date('m/d H:i', strtotime($row['created_at']))),
            'badge' => ($row['type'] === '加班') ? 'bg-warning text-dark' : 'bg-danger',
            'level' => ($row['type'] === '加班') ? '中' : '高',
            'link' => 'admin_review.php',
        ];
    }
    $stmt->close();
}

if ($stmt = $conn->prepare("SELECT s.date, s.employee_number, s.status_text, e.name FROM saved_attendance s LEFT JOIN employees e ON e.employee_number = s.employee_number WHERE s.date >= DATE_SUB(CURDATE(), INTERVAL 14 DAY) AND s.status_text IS NOT NULL AND s.status_text NOT IN ('正常出勤','國定假日') ORDER BY s.date DESC LIMIT 6")) {
    $stmt->execute();
    $result = $stmt->get_result();
    while ($row = $result->fetch_assoc()) {
        $todoItems[] = [
            'title' => '出勤異常｜' . ($row['name'] ?? $row['employee_number']),
            'description' => date('m/d', strtotime($row['date'])) . ' · ' . $row['status_text'],
            'badge' => 'bg-info text-dark',
            'level' => '追蹤',
            'link' => 'attendance_list.php',
        ];
    }
    $stmt->close();
}

// 【PHP-8】整理最新核准申請
if ($stmt = $conn->prepare("SELECT name, type, subtype, start_date, end_date, created_at FROM requests WHERE status = 'Approved' ORDER BY created_at DESC LIMIT 6")) {
    $stmt->execute();
    $result = $stmt->get_result();
    while ($row = $result->fetch_assoc()) {
        $recentApprovals[] = $row;
    }
    $stmt->close();
}

// 【PHP-9】釋放資料庫連線資源
$conn->close();
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
        :root {
            --brand-gold: #ffcd00;
            --brand-pink: #e36386;
            --brand-blue: #345d9d;
        }

        body {
            font-family: "Noto Sans TC", "Microsoft JhengHei", sans-serif;
            background: linear-gradient(135deg, rgba(255, 205, 0, 0.08), rgba(52, 93, 157, 0.08));
        }

        .brand-banner {
            background: linear-gradient(120deg, var(--brand-blue), var(--brand-pink));
            border-radius: 18px;
            padding: 30px;
            color: #fff;
            position: relative;
            overflow: hidden;
            box-shadow: 0 12px 30px rgba(52, 93, 157, 0.25);
        }

        .brand-banner::after {
            content: "";
            position: absolute;
            width: 220px;
            height: 220px;
            border-radius: 50%;
            background: rgba(255, 205, 0, 0.3);
            top: -40px;
            right: -60px;
        }

        .brand-logo {
            width: 120px;
            height: auto;
        }

        .brand-highlight {
            color: var(--brand-gold);
            font-weight: 700;
        }

        .dashboard-card {
            border: none;
            border-radius: 16px;
            overflow: hidden;
            box-shadow: 0 10px 25px rgba(0, 0, 0, 0.08);
            transition: transform 0.25s ease, box-shadow 0.25s ease;
        }

        .dashboard-card:hover {
            transform: translateY(-6px);
            box-shadow: 0 18px 32px rgba(52, 93, 157, 0.18);
        }

        .dashboard-card .card-header {
            background: linear-gradient(135deg, rgba(52, 93, 157, 0.12), rgba(255, 205, 0, 0.18));
            font-weight: 600;
            color: var(--brand-blue);
        }

        .feature-icon {
            font-size: 2.2rem;
            width: 64px;
            height: 64px;
            border-radius: 16px;
            display: flex;
            align-items: center;
            justify-content: center;
            background: rgba(255, 205, 0, 0.18);
            color: var(--brand-blue);
        }

        .btn-brand {
            background-color: var(--brand-blue);
            border: none;
            color: #fff;
        }

        .btn-brand:hover {
            background-color: #274677;
        }

        .btn-outline-brand {
            border-color: var(--brand-blue);
            color: var(--brand-blue);
        }

        .btn-outline-brand:hover {
            background-color: var(--brand-blue);
            color: #fff;
        }

        .badge-brand {
            background-color: var(--brand-pink);
        }

        .table-highlight thead.table-primary {
            background-color: rgba(255, 205, 0, 0.2) !important;
            color: var(--brand-blue);
        }

        .table-highlight tbody tr:hover {
            background-color: rgba(227, 99, 134, 0.08);
        }

        .quick-links .btn {
            min-width: 140px;
        }
    </style>
</head>
<body>
    <?php include 'admin_navbar.php'; ?>

    <div class="container my-5">
        <div class="brand-banner mb-5">
            <div class="row align-items-center g-4">
                <div class="col-md-3 text-center text-md-start">
                    <img src="../logo/LOGO-05.png" alt="公司LOGO" class="brand-logo img-fluid">
                </div>
                <div class="col-md-9">
                    <h1 class="display-6 fw-bold mb-2">主管儀表板</h1>
                    <p class="lead mb-3">歡迎回來，<span class="brand-highlight"><?= $username ?></span>！掌握營運脈動，快速完成待辦任務。</p>
                    <div class="d-flex flex-wrap gap-2">
                        <span class="badge badge-brand rounded-pill px-3 py-2">即時狀態監控</span>
                        <span class="badge bg-warning rounded-pill px-3 py-2 text-dark">流程效率提升</span>
                        <span class="badge bg-light text-dark rounded-pill px-3 py-2">角色：主管 / 管理員</span>
                    </div>
                </div>
            </div>
        </div>

        <div class="row g-4 mb-5">
            <div class="col-lg-4">
                <div class="card dashboard-card h-100">
                    <div class="card-header d-flex align-items-center justify-content-between">
                        <span>核准與追蹤</span>
                        <span class="badge bg-warning text-dark">流程中心</span>
                    </div>
                    <div class="card-body">
                        <div class="d-flex align-items-center mb-3">
                            <div class="feature-icon me-3">📋</div>
                            <div>
                                <h5 class="card-title mb-1">審核申請</h5>
                                <p class="text-muted mb-0">整合請假、加班案件，加速核准流程。</p>
                            </div>
                        </div>
                        <div class="quick-links d-flex flex-wrap gap-2">
                            <a href="admin_review.php" class="btn btn-brand">前往審核</a>
                            <a href="manager_request_leave.php" class="btn btn-outline-brand">員工請假</a>
                            <a href="manager_overtime_request.php" class="btn btn-outline-brand">員工加班</a>
                        </div>
                    </div>
                </div>
            </div>

            <div class="col-lg-4">
                <div class="card dashboard-card h-100">
                    <div class="card-header d-flex align-items-center justify-content-between">
                        <span>人力資源</span>
                        <span class="badge bg-light text-dark">HR 工具</span>
                    </div>
                    <div class="card-body">
                        <div class="d-flex align-items-center mb-3">
                            <div class="feature-icon me-3">👥</div>
                            <div>
                                <h5 class="card-title mb-1">員工管理</h5>
                                <p class="text-muted mb-0">掌握人力配置，維護人員履歷與角色權限。</p>
                            </div>
                        </div>
                        <div class="quick-links d-flex flex-wrap gap-2">
                            <a href="employee_list.php" class="btn btn-brand">查看員工</a>
                            <a href="add_employee.php" class="btn btn-outline-brand">新增員工</a>
                        </div>
                    </div>
                </div>
            </div>

            <div class="col-lg-4">
                <div class="card dashboard-card h-100">
                    <div class="card-header d-flex align-items-center justify-content-between">
                        <span>財務與考勤</span>
                        <span class="badge bg-primary">薪資中心</span>
                    </div>
                    <div class="card-body">
                        <div class="d-flex align-items-center mb-3">
                            <div class="feature-icon me-3">💰</div>
                            <div>
                                <h5 class="card-title mb-1">薪資管理</h5>
                                <p class="text-muted mb-0">整合考勤與薪資報表，維持薪資透明與正確。</p>
                            </div>
                        </div>
                        <div class="quick-links d-flex flex-wrap gap-2">
                            <a href="import_attendance.php" class="btn btn-brand">匯入打卡</a>
                            <a href="attendance_list.php" class="btn btn-outline-brand">考勤紀錄</a>
                            <a href="employee_salary_report.php" class="btn btn-outline-brand">薪資報表</a>
                        </div>
                    </div>
                </div>
            </div>
        </div>

        <div class="row g-4 mb-5">
            <div class="col-xl-8">
                <div class="card dashboard-card table-highlight">
                    <div class="card-header d-flex justify-content-between align-items-center">
                        <span>即時營運快照</span>
                        <span class="text-muted small">資料時間：<span id="lastRefresh">-</span></span>
                    </div>
                    <div class="card-body">
                        <div class="table-responsive">
                            <table class="table table-bordered align-middle mb-0">
                                <thead class="table-primary">
                                    <tr>
                                        <th scope="col">指標</th>
                                        <th scope="col">當日狀態</th>
                                        <th scope="col">本週趨勢</th>
                                        <th scope="col">快速連結</th>
                                    </tr>
                                </thead>
                                <tbody>
                                    <tr>
                                        <td class="fw-semibold">待審核請假</td>
                                        <td>
                                            <?php if ($metrics['pending_leave'] > 0): ?>
                                                <span class="badge bg-danger"><?= $metrics['pending_leave'] ?> 件</span>
                                            <?php else: ?>
                                                <span class="badge bg-success">0 件</span>
                                            <?php endif; ?>
                                        </td>
                                        <td class="text-muted">
                                            <?= $metrics['pending_leave'] > 0
                                                ? '尚有 ' . $metrics['pending_leave'] . ' 件等待主管審核'
                                                : '目前所有請假申請均已完成審核'; ?>
                                        </td>
                                        <td><a href="admin_review.php" class="btn btn-sm btn-outline-brand">前往審核</a></td>
                                    </tr>
                                    <tr>
                                        <td class="fw-semibold">待審核加班</td>
                                        <td>
                                            <?php if ($metrics['pending_overtime'] > 0): ?>
                                                <span class="badge bg-warning text-dark"><?= $metrics['pending_overtime'] ?> 件</span>
                                            <?php else: ?>
                                                <span class="badge bg-success">0 件</span>
                                            <?php endif; ?>
                                        </td>
                                        <td class="text-muted">
                                            <?= $metrics['pending_overtime'] > 0
                                                ? '尚有 ' . $metrics['pending_overtime'] . ' 件加班申請待確認'
                                                : '目前沒有尚未處理的加班申請'; ?>
                                        </td>
                                        <td><a href="admin_review.php" class="btn btn-sm btn-outline-brand">立即檢視</a></td>
                                    </tr>
                                    <tr>
                                        <td class="fw-semibold">本月核准請假時數</td>
                                        <td>
                                            <?php if ($metrics['leave_hours'] > 0): ?>
                                                <span class="badge bg-primary"><?= number_format($metrics['leave_hours'], 1) ?> 小時</span>
                                            <?php else: ?>
                                                <span class="badge bg-light text-dark">0 小時</span>
                                            <?php endif; ?>
                                        </td>
                                        <td class="text-muted"><?= date('n') ?> 月累計已核准請假時數</td>
                                        <td><a href="attendance_list.php" class="btn btn-sm btn-outline-brand">追蹤請假</a></td>
                                    </tr>
                                    <tr>
                                        <td class="fw-semibold">本月核准加班時數</td>
                                        <td>
                                            <?php if ($metrics['overtime_hours'] > 0): ?>
                                                <span class="badge bg-info text-dark"><?= number_format($metrics['overtime_hours'], 1) ?> 小時</span>
                                            <?php else: ?>
                                                <span class="badge bg-light text-dark">0 小時</span>
                                            <?php endif; ?>
                                        </td>
                                        <td class="text-muted"><?= date('n') ?> 月累計已核准加班時數</td>
                                        <td><a href="manager_overtime_request.php" class="btn btn-sm btn-outline-brand">檢視加班</a></td>
                                    </tr>
                                    <tr>
                                        <td class="fw-semibold">近七日出勤異常</td>
                                        <td>
                                            <?php if ($metrics['attendance_alerts'] > 0): ?>
                                                <span class="badge bg-danger"><?= $metrics['attendance_alerts'] ?> 筆</span>
                                            <?php else: ?>
                                                <span class="badge bg-success">0 筆</span>
                                            <?php endif; ?>
                                        </td>
                                        <td class="text-muted">
                                            <?= $metrics['attendance_alerts'] > 0
                                                ? '近七日共有 ' . $metrics['attendance_alerts'] . ' 筆需追蹤的出勤紀錄'
                                                : '近七日出勤紀錄皆為正常狀態'; ?>
                                        </td>
                                        <td><a href="attendance_list.php" class="btn btn-sm btn-outline-brand">查看出勤</a></td>
                                    </tr>
                                </tbody>
                            </table>
                        </div>
                    </div>
                </div>
            </div>

            <div class="col-xl-4">
                <div class="card dashboard-card h-100">
                    <div class="card-header">待辦清單</div>
                    <div class="card-body">
                        <ul class="list-group list-group-flush">
                            <?php if (!empty($todoItems)): ?>
                                <?php foreach (array_slice($todoItems, 0, 6) as $item): ?>
                                    <li class="list-group-item d-flex justify-content-between align-items-start">
                                        <div>
                                            <div class="fw-semibold"><?= htmlspecialchars($item['title']) ?></div>
                                            <small class="text-muted d-block"><?= htmlspecialchars($item['description']) ?></small>
                                            <?php if (!empty($item['link'])): ?>
                                                <div class="mt-2">
                                                    <a href="<?= htmlspecialchars($item['link']) ?>" class="btn btn-sm btn-outline-brand">立即處理</a>
                                                </div>
                                            <?php endif; ?>
                                        </div>
                                        <span class="badge <?= htmlspecialchars($item['badge']) ?> rounded-pill"><?= htmlspecialchars($item['level']) ?></span>
                                    </li>
                                <?php endforeach; ?>
                            <?php else: ?>
                                <li class="list-group-item text-muted">目前沒有待辦事項。</li>
                            <?php endif; ?>
                        </ul>
                    </div>
                </div>
            </div>
        </div>

        <div class="row g-4">
            <div class="col-lg-6">
                <div class="card dashboard-card">
                    <div class="card-header">系統設定快速動作</div>
                    <div class="card-body">
                        <p class="text-muted">維持制度更新與流程順暢，確保資料正確完整。</p>
                        <div class="d-flex flex-wrap gap-2">
                            <a href="shift_settings.php" class="btn btn-brand">班別設定</a>
                            <a href="settings.php" class="btn btn-outline-brand">假期設定</a>
                            <a href="upload_holidays.php" class="btn btn-outline-brand">匯入假日</a>
                            <a href="vacation_management.php" class="btn btn-outline-brand">特休額度</a>
                        </div>
                    </div>
                </div>
            </div>
            <div class="col-lg-6">
                <div class="card dashboard-card h-100">
                    <div class="card-header">最新核准申請</div>
                    <div class="card-body">
                        <?php if (!empty($recentApprovals)): ?>
                            <div class="table-responsive">
                                <table class="table table-bordered table-sm align-middle mb-0">
                                    <thead class="table-primary">
                                        <tr>
                                            <th scope="col">員工</th>
                                            <th scope="col">類型</th>
                                            <th scope="col">期間</th>
                                            <th scope="col">建立時間</th>
                                        </tr>
                                    </thead>
                                    <tbody>
                                        <?php foreach ($recentApprovals as $record): ?>
                                            <tr>
                                                <td><?= htmlspecialchars($record['name'] ?? '未填寫姓名') ?></td>
                                                <td><?= htmlspecialchars($record['type'] . ($record['subtype'] ? '｜' . $record['subtype'] : '')) ?></td>
                                                <td>
                                                    <?php if (!empty($record['start_date']) && !empty($record['end_date'])): ?>
                                                        <?= htmlspecialchars(date('m/d H:i', strtotime($record['start_date'])) . ' - ' . date('m/d H:i', strtotime($record['end_date']))) ?>
                                                    <?php else: ?>
                                                        <span class="text-muted">未填寫</span>
                                                    <?php endif; ?>
                                                </td>
                                                <td><?= htmlspecialchars(date('m/d H:i', strtotime($record['created_at']))) ?></td>
                                            </tr>
                                        <?php endforeach; ?>
                                    </tbody>
                                </table>
                            </div>
                        <?php else: ?>
                            <p class="text-muted mb-0">目前尚無核准紀錄。</p>
                        <?php endif; ?>
                    </div>
                </div>
            </div>
        </div>
    </div>

    <!-- ✅ jQuery -->
    <script src="https://code.jquery.com/jquery-3.7.1.min.js"></script>
    <!-- Bootstrap JS -->
    <script src="https://cdn.jsdelivr.net/npm/bootstrap@5.3.3/dist/js/bootstrap.bundle.min.js"></script>
    <script>
        // 【JS-1】更新即時資料戳記，強化主管掌握資料的新鮮度
        $(function () {
            const now = new Date();
            const formatted = now.getFullYear() + "/" + (now.getMonth() + 1).toString().padStart(2, "0") + "/" + now.getDate().toString().padStart(2, "0") +
                " " + now.getHours().toString().padStart(2, "0") + ":" + now.getMinutes().toString().padStart(2, "0");
            $("#lastRefresh").text(formatted);
        });
    </script>
</body>
</html>