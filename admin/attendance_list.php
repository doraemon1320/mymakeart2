<?php
// 【PHP-01】權限檢查與資料庫連線
session_start();
if (!isset($_SESSION['user']) || $_SESSION['user']['role'] !== 'admin') {
    header('Location: ../login.php');
    exit;
}

$conn = new mysqli('localhost', 'root', '', 'mymakeart');
if ($conn->connect_error) {
    die('資料庫連接失敗：' . $conn->connect_error);
}

// 【PHP-02】初始化查詢條件
$employee_number = $_GET['employee_number'] ?? '';
$year = $_GET['year'] ?? date('Y', strtotime('first day of last month'));
$month = $_GET['month'] ?? date('m', strtotime('first day of last month'));
$start_date = "$year-$month-01";
$end_date = date('Y-m-t', strtotime($start_date));

// 【PHP-03】取得員工清單，若未指定員工則預設為第一筆
$current_employee_name = '未選擇員工';
$employee_list = [];
$employee_stmt = $conn->prepare(
    "SELECT employee_number, name FROM employees WHERE role = 'employee' AND (resignation_date IS NULL OR resignation_date = '') ORDER BY employee_number"
);

if ($employee_stmt) {
    $employee_stmt->execute();
    $employee_result = $employee_stmt->get_result();
    if ($employee_result) {
        $employee_list = $employee_result->fetch_all(MYSQLI_ASSOC);
        if (empty($employee_number) && !empty($employee_list)) {
            $employee_number = $employee_list[0]['employee_number'];
            $current_employee_name = $employee_list[0]['name'];
        }
        foreach ($employee_list as $employee) {
            if ($employee['employee_number'] === $employee_number) {
                $current_employee_name = $employee['name'];
                break;
            }
        }
    }
}

// 【PHP-04】班別預設值與請假、出勤陣列
$shift_name = '無班別資訊';
$shift_start_time = '09:00:00';
$shift_end_time = '18:00:00';
$break_start_time = '12:00:00';
$break_end_time = '13:00:00';
$approved_requests = [];
$attendance_logs = [];
$saved_attendance = [];
$attendance_data = [];

// 【PHP-05】行事曆假日資料
$holiday_map = [];
$holiday_result = $conn->query("SELECT holiday_date, description, is_working_day FROM holidays");
if ($holiday_result) {
    foreach ($holiday_result->fetch_all(MYSQLI_ASSOC) as $row) {
        $holiday_map[$row['holiday_date']] = [
            'description' => $row['description'],
            'is_working_day' => $row['is_working_day']
        ];
    }

}

// 【PHP-06】請假假別對照表
$leave_map = [];
$leave_result = $conn->query("SELECT id, name FROM leave_types");
if ($leave_result) {
    foreach ($leave_result->fetch_all(MYSQLI_ASSOC) as $row) {
        $leave_map[$row['id']] = $row['name'];
    }
}

// 【PHP-07】若有員工編號則載入相關排班與出勤資料
if (!empty($employee_number)) {
    $shift_query = $conn->prepare("
        SELECT shifts.name AS shift_name, shifts.start_time, shifts.end_time, shifts.break_start, shifts.break_end
        FROM employees
        JOIN shifts ON employees.shift_id = shifts.id
        WHERE employees.employee_number = ?
    ");
    if ($shift_query) {
        $shift_query->bind_param('s', $employee_number);
        $shift_query->execute();
        $shift_info = $shift_query->get_result()->fetch_assoc();
        if ($shift_info) {
            $shift_name = $shift_info['shift_name'] ?? $shift_name;
            $shift_start_time = $shift_info['start_time'] ?? $shift_start_time;
            $shift_end_time = $shift_info['end_time'] ?? $shift_end_time;
            $break_start_time = $shift_info['break_start'] ?? $break_start_time;
            $break_end_time = $shift_info['break_end'] ?? $break_end_time;
        }
    }

    $request_stmt = $conn->prepare("
        SELECT * FROM requests
        WHERE employee_number = ? AND status = 'Approved' AND start_date <= ? AND end_date >= ?
    ");
    if ($request_stmt) {
        $request_stmt->bind_param('sss', $employee_number, $end_date, $start_date);
        $request_stmt->execute();
        $approved_requests = $request_stmt->get_result()->fetch_all(MYSQLI_ASSOC);
    }

    $log_stmt = $conn->prepare("
        SELECT log_date, MIN(log_time) AS first_time, MAX(log_time) AS last_time
        FROM attendance_logs
        WHERE employee_number = ? AND log_date BETWEEN ? AND ?
        GROUP BY log_date
    ");
    if ($log_stmt) {
        $log_stmt->bind_param('sss', $employee_number, $start_date, $end_date);
        $log_stmt->execute();
        foreach ($log_stmt->get_result()->fetch_all(MYSQLI_ASSOC) as $row) {
            $attendance_logs[$row['log_date']] = [
                'first_time' => $row['first_time'],
                'last_time' => $row['last_time']
            ];
        }
    }

    $saved_stmt = $conn->prepare("
        SELECT date, first_time, last_time, status_text, absent_minutes
        FROM saved_attendance
        WHERE employee_number = ? AND date BETWEEN ? AND ?
    ");
    if ($saved_stmt) {
        $saved_stmt->bind_param('sss', $employee_number, $start_date, $end_date);
        $saved_stmt->execute();
        foreach ($saved_stmt->get_result()->fetch_all(MYSQLI_ASSOC) as $row) {
            $saved_attendance[$row['date']] = $row;
        }
    }

    // 【PHP-08】建立日期列表並比對打卡與假日資料
    $dates_in_month = [];
    $current = new DateTime($start_date);
    $last_day = new DateTime($end_date);
    while ($current <= $last_day) {
        $dates_in_month[] = $current->format('Y-m-d');
        $current->modify('+1 day');
    }

    foreach ($dates_in_month as $day) {
        $first_time = $attendance_logs[$day]['first_time'] ?? '-';
        $last_time = $attendance_logs[$day]['last_time'] ?? '-';
        $default_status = [];
        $status_class = 'normal';
        $absent_minutes = 0;

        // 【PHP-09】優先使用人工儲存的覆蓋資料
        if (isset($saved_attendance[$day])) {
            $first_time = $saved_attendance[$day]['first_time'];
            $last_time = $saved_attendance[$day]['last_time'];
            $default_status = array_filter(array_map('trim', explode(',', $saved_attendance[$day]['status_text'])));
            $absent_minutes = (int) $saved_attendance[$day]['absent_minutes'];
        } else {
            $has_approved = false;
            foreach ($approved_requests as $req) {
                $req_start = date('Y-m-d', strtotime($req['start_date']));
                $req_end = date('Y-m-d', strtotime($req['end_date']));
                if ($day >= $req_start && $day <= $req_end) {
                    $has_approved = true;
                    $status = ($req['type'] === '請假') ? ($leave_map[$req['subtype']] ?? $req['subtype']) : '加班申請';
                    $first_time = $status;
                    $last_time = $status;
                    $default_status[] = $status;
                    $status_class = ($req['type'] === '請假') ? 'leave' : 'overtime';
                    break;
                                }
            }

            if (!$has_approved) {
                $day_of_week = date('N', strtotime($day));
                $is_weekend = ($day_of_week >= 6);
                $is_holiday = isset($holiday_map[$day]) && !$holiday_map[$day]['is_working_day'];
                $is_working_day = isset($holiday_map[$day]) && $holiday_map[$day]['is_working_day'];

                if ($is_weekend && !$is_working_day) {
                    $first_time = $last_time = ($day_of_week === 6 ? '禮拜六' : '禮拜日');
                    $default_status[] = '國定假日';
                    $status_class = 'holiday';
                } elseif ($is_holiday) {
                    $first_time = $last_time = $holiday_map[$day]['description'];
                    $default_status[] = '國定假日';
                    $status_class = 'holiday';
                } elseif ($first_time === '-' && $last_time === '-') {
                    $default_status[] = '曠職';
                    $status_class = 'absent';

                    $work_seconds = strtotime("$day $shift_end_time") - strtotime("$day $shift_start_time");
                    $rest_seconds = strtotime("$day $break_end_time") - strtotime("$day $break_start_time");
                    $absent_minutes = max(0, floor(($work_seconds - $rest_seconds) / 60));
                } else {
                    $late_minutes = 0;
                    $early_leave_minutes = 0;

                    $is_valid_time = static function ($time) {
                        return preg_match('/^\d{2}:\d{2}(:\d{2})?$/', $time);
                    };

                    if (strtotime($first_time) > strtotime($shift_start_time)) {
                        $default_status[] = '遲到';
                        $status_class = 'red';

                        if ($is_valid_time($first_time)) {
                            $late_minutes = max(0, floor((strtotime("$day $first_time") - strtotime("$day $shift_start_time")) / 60));
                        }
                    }
                    if (strtotime($last_time) < strtotime($shift_end_time)) {
                        $default_status[] = '早退';
                        $status_class = 'red';

                        if ($is_valid_time($last_time)) {
                            $early_leave_minutes = max(0, floor((strtotime("$day $shift_end_time") - strtotime("$day $last_time")) / 60));
                        }
                    }

                    $absent_minutes += $late_minutes + $early_leave_minutes;
                }
            }
        }

        if (empty($default_status)) {
            $default_status[] = '正常出勤';
            $status_class = 'normal';
        }

        $attendance_data[] = [
            'date' => $day,
            'first_time' => $first_time,
            'last_time' => $last_time,
            'status_class' => $status_class,
            'default_status' => $default_status,
            'absent_minutes' => $absent_minutes
        ];
    }

    // 【PHP-10】本月缺勤總分鐘數少於 30 分鐘則歸零
    if (!empty($attendance_data) && array_sum(array_column($attendance_data, 'absent_minutes')) < 30) {
        foreach ($attendance_data as &$row) {
            $row['absent_minutes'] = 0;
        }
        unset($row);
    }
}

$total_absent_minutes = array_sum(array_column($attendance_data, 'absent_minutes'));
$total_absent_hours = $total_absent_minutes > 0 ? round($total_absent_minutes / 60, 2) : 0;

// 【PHP-11】狀態選項清單
$status_options = [
    '正常出勤',
    '漏打卡(上班)',
    '漏打卡(下班)',
    '國定假日',
    '曠職',
    '早退',
    '遲到',
    '颱風假'
];

// 【PHP-12】狀態標籤色彩判斷
function getStatusBadgeClass(string $status): string
{
    $status = trim($status);
    if ($status === '') {
        return 'status-badge-secondary';
    }
    $danger = ['遲到', '早退'];
    $absent = ['曠職'];
    $warning = ['漏打卡(上班)', '漏打卡(下班)'];
    $holiday = ['國定假日', '颱風假', '禮拜六', '禮拜日'];

    if (in_array($status, $danger, true)) {
        return 'status-badge-danger';
    }
    if (in_array($status, $absent, true)) {
        return 'status-badge-absent';
    }
    if (in_array($status, $warning, true)) {
        return 'status-badge-warning';
    }
    if (in_array($status, $holiday, true)) {
        return 'status-badge-holiday';
    }
    if (mb_strpos($status, '假') !== false) {
        return 'status-badge-leave';
    }
    if ($status === '加班申請') {
        return 'status-badge-overtime';
    }
    return 'status-badge-normal';
}
?>

<!DOCTYPE html>
<html lang="zh-TW">
<head>
    <meta charset="UTF-8">
    <meta name="viewport" content="width=device-width, initial-scale=1.0">
    <title>考勤紀錄表</title>
    <link href="https://cdn.jsdelivr.net/npm/bootstrap@5.3.3/dist/css/bootstrap.min.css" rel="stylesheet">
    <script src="https://cdn.jsdelivr.net/npm/bootstrap@5.3.3/dist/js/bootstrap.bundle.min.js"></script>
    <link rel="stylesheet" href="styles.css">
    <link rel="stylesheet" href="attendance_list.css">
    <link rel="stylesheet" href="admin_navbar.css">
</head>
<body class="bg-light">
    <?php include 'admin_navbar.php'; ?>

    <header class="page-hero">
        <div class="container">
            <div class="row align-items-center g-4">
                <div class="col-lg-8">
                    <h1 class="hero-title">考勤紀錄判斷中心</h1>
                    <p class="hero-subtitle">
                        依據匯入打卡紀錄、自動比對班別、例假日與政府公告假期，協助快速找出遲到、早退、曠職與請假狀況，確保薪資計算零誤差。
                    </p>
                    <div class="page-hero-meta mt-3">
                        <span>📅 查詢期間：<?= htmlspecialchars(date('Y年m月', strtotime($start_date))) ?>（<?= htmlspecialchars($start_date) ?> ~ <?= htmlspecialchars($end_date) ?>）</span>
                        <span>👤 目前檢視：<?= htmlspecialchars($current_employee_name) ?></span>
                    </div>
                </div>
                <div class="col-lg-4 text-lg-end">
                    <div class="hero-highlight">
                        <span class="label">本月缺席統計</span>
                        <strong><?= number_format($total_absent_minutes) ?> 分鐘</strong>
                        <small class="d-block">約 <?= number_format($total_absent_hours, 2) ?> 小時</small>
                    </div>
                </div>
            </div>
        </div>
    </header>

    <main class="container py-4">
        <div class="card shadow-sm brand-card mb-4">
            <div class="brand-card-header d-flex flex-column flex-lg-row align-items-lg-center justify-content-between">
                <div>
                    <h2 class="mb-1">查詢條件</h2>
                    <p class="mb-0">選擇員工與月份後即可重新整理對應的考勤分析結果。</p>
                </div>
                <div class="text-lg-end mt-3 mt-lg-0">
                    <span class="badge rounded-pill text-bg-warning">黃：需人工確認</span>
                    <span class="badge rounded-pill text-bg-danger">紅：立即關注</span>
                </div>
            </div>
            <div class="brand-card-body">
                <form method="GET" class="row g-3">
                    <div class="col-lg-5">
                        <label class="form-label fw-bold">選擇員工</label>
                        <select id="employee_number" name="employee_number" class="form-select" required>
                            <option value="">請選擇</option>
                            <?php foreach ($employee_list as $employee): ?>
                                <option value="<?= htmlspecialchars($employee['employee_number']) ?>" <?= $employee_number === $employee['employee_number'] ? 'selected' : '' ?>>
                                    <?= htmlspecialchars($employee['employee_number'] . ' - ' . $employee['name']) ?>
                                </option>
                            <?php endforeach; ?>
                        </select>
                    </div>
                    <div class="col-lg-3 col-md-6">
                        <label class="form-label fw-bold">年份</label>
                        <input type="number" name="year" class="form-control" value="<?= htmlspecialchars($year) ?>" required>
                    </div>
                    <div class="col-lg-2 col-md-6">
                        <label class="form-label fw-bold">月份</label>
                        <select name="month" class="form-select" required>
                            <?php for ($m = 1; $m <= 12; $m++): ?>
                                <?php $val = str_pad((string) $m, 2, '0', STR_PAD_LEFT); ?>
                                <option value="<?= $val ?>" <?= $val === $month ? 'selected' : '' ?>><?= $val ?></option>
                            <?php endfor; ?>
                        </select>
                    </div>
                    <div class="col-lg-2 col-md-6 align-self-end">
                        <button type="submit" class="btn btn-brand w-100">立即查詢</button>
                    </div>
                </form>
            </div>
        </div>

        <div class="row g-4 mb-4" id="shift-summary-section">
            <div class="col-xl-8">
                <div class="card shadow-sm h-100 brand-card">
                    <div class="brand-card-header">
                        <h3 class="mb-1">班別資訊</h3>
                        <p class="mb-0">系統將以班別作為計算遲到、早退與曠職的基準，可搭配手動編輯進行調整。</p>
                    </div>
                    <div class="brand-card-body">
                        <div class="table-responsive">
                            <table class="table table-bordered align-middle">
                                <tbody>
                                    <tr>
                                        <th class="table-primary w-25">班別名稱</th>
                                        <td><?= htmlspecialchars($shift_name) ?></td>
                                    </tr>
                                    <tr>
                                        <th class="table-primary">上班時間</th>
                                        <td><?= htmlspecialchars($shift_start_time) ?></td>
                                    </tr>
                                    <tr>
                                        <th class="table-primary">午休時段</th>
                                        <td><?= htmlspecialchars($break_start_time) ?> ~ <?= htmlspecialchars($break_end_time) ?></td>
                                    </tr>
                                    <tr>
                                        <th class="table-primary">下班時間</th>
                                        <td><?= htmlspecialchars($shift_end_time) ?></td>
                                    </tr>
                                </tbody>
                            </table>
                        </div>
                    </div>
                </div>
            </div>
            <div class="col-xl-4">
                <div class="card shadow-sm h-100 summary-card brand-card">
                    <div class="brand-card-header">
                        <h3 class="mb-0">缺勤時數摘要</h3>
                    </div>
                    <div class="brand-card-body">
                        <ul class="list-unstyled mb-4">
                            <li class="d-flex justify-content-between">
                                <span>缺勤分鐘數</span>
                                <strong><?= number_format($total_absent_minutes) ?> 分</strong>
                            </li>
                            <li class="d-flex justify-content-between">
                                <span>換算小時</span>
                                <strong><?= number_format($total_absent_hours, 2) ?> 小時</strong>
                            </li>
                        </ul>
                        <div class="alert alert-brand-info mb-0">
                            若本月缺勤不足 30 分鐘，系統自動不扣薪，仍可於表格內調整狀態與分鐘數。
                        </div>
                    </div>
                </div>
            </div>
        </div>

        <div class="card shadow-sm mb-4 brand-card" id="approved-requests-section">
            <div class="brand-card-header">
                <h3 class="mb-1">當月核准申請</h3>
                <p class="mb-0">以請假與加班核准資料覆蓋打卡紀錄，確保薪資計算使用正確資訊。</p>
            </div>
            <div class="brand-card-body">
                <div class="table-responsive">
                    <table class="table table-bordered table-hover align-middle">
                        <thead class="table-primary">
                            <tr>
                                <th>類型</th>
                                <th>假別 / 申請</th>
                                <th>理由</th>
                                <th>開始時間</th>
                                <th>結束時間</th>
                                <th>審核狀態</th>
                            </tr>
                        </thead>
                        <tbody>
                            <?php if (!empty($approved_requests)): ?>
                                <?php foreach ($approved_requests as $request): ?>
                                    <tr>
                                        <td><?= htmlspecialchars($request['type']) ?></td>
                                        <td><?= htmlspecialchars($leave_map[$request['subtype']] ?? $request['subtype']) ?></td>
                                        <td><?= htmlspecialchars($request['reason']) ?></td>
                                        <td><?= htmlspecialchars($request['start_date']) ?></td>
                                        <td><?= htmlspecialchars($request['end_date']) ?></td>
                                        <td><span class="badge text-bg-success">已核准</span></td>
                                    </tr>
                                <?php endforeach; ?>
                            <?php else: ?>
                                <tr>
                                    <td colspan="6" class="text-center text-muted">本月沒有核准的請假或加班申請。</td>
                                </tr>
                            <?php endif; ?>
                        </tbody>
                    </table>
                </div>
            </div>
        </div>

        <div class="card shadow-sm brand-card" id="attendance-detail-section">
            <div class="brand-card-header">
                <div class="d-flex flex-column flex-lg-row justify-content-between align-items-lg-center">
                    <div>
                        <h3 class="mb-1">每日考勤判斷</h3>
                        <p class="mb-0">按「修改」即可調整狀態或缺勤分鐘數，儲存後即會同步至薪資報表。</p>
                    </div>
                    <div class="mt-3 mt-lg-0 card-action-group" id="attendance-action-group">
                        <button id="edit_button" class="btn btn-warning me-2" onclick="enableEditing()">✏️ 修改</button>
                        <button id="cancel_button" class="btn btn-outline-danger me-2" style="display: none;" onclick="cancelEditing()">❌ 取消</button>
                        <button id="save_button" class="btn btn-success me-2" onclick="saveAttendanceToServer()">💾 儲存</button>
                        <button id="export_button" class="btn btn-secondary" onclick="exportToImage()">📸 匯出圖片</button>
                    </div>
                </div>
            </div>
            <div class="brand-card-body">
                <div class="d-flex flex-column flex-lg-row justify-content-between align-items-lg-center mb-3">
                    <div class="text-muted">若需調整多日資料，可先修改後再一次儲存，避免重複操作。</div>
                    <div class="mt-3 mt-lg-0">
                        <span class="badge bg-light text-dark border">✅ 綠：正常或已覆蓋</span>
                        <span class="badge bg-warning text-dark border">⚠️ 黃：需人工確認</span>
                        <span class="badge bg-danger">❗ 紅：異常狀態</span>
                    </div>
                </div>

                <div class="table-responsive attendance-export-area">
                    <table class="table table-bordered table-hover attendance-table align-middle" id="attendance_table">
                        <thead class="table-primary">
                            <tr>
                                <th>日期</th>
                                <th>上班時間</th>
                                <th>下班時間</th>
                                <th>狀態</th>
                                <th>缺席分鐘</th>
                            </tr>
                        </thead>
                        <tbody>
                            <?php foreach ($attendance_data as $record): ?>
                                <?php $status_values = $record['default_status']; ?>
                                <tr class="status-row status-<?= htmlspecialchars($record['status_class']) ?>">
                                    <td class="date fw-semibold"><?= htmlspecialchars($record['date']) ?></td>
                                    <td class="first-time"><?= htmlspecialchars($record['first_time']) ?></td>
                                    <td class="last-time"><?= htmlspecialchars($record['last_time']) ?></td>
                                    <td>
                                        <div class="status-text">
                                            <?php foreach ($status_values as $status): ?>
                                                <span class="badge status-badge <?= getStatusBadgeClass($status) ?>" data-status="<?= htmlspecialchars($status) ?>"><?= htmlspecialchars($status) ?></span>
                                            <?php endforeach; ?>
                                        </div>
                                        <select class="form-select status-select" multiple style="display: none;" size="6" onchange="updateRowColor(this)">
                                            <?php foreach ($status_options as $option): ?>
                                                <option value="<?= htmlspecialchars($option) ?>" <?= in_array($option, $status_values, true) ? 'selected' : '' ?>><?= htmlspecialchars($option) ?></option>
                                            <?php endforeach; ?>
                                            <?php foreach ($leave_map as $id => $name): ?>
                                                <option value="<?= htmlspecialchars($name) ?>" <?= in_array($name, $status_values, true) ? 'selected' : '' ?>><?= htmlspecialchars($name) ?></option>
                                            <?php endforeach; ?>
                                        </select>
                                    </td>
                                    <td>
                                        <div class="absent-text"><span class="absent-value" data-minutes="<?= (int) $record['absent_minutes'] ?>"><?= (int) $record['absent_minutes'] ?></span> 分</div>
                                        <input type="number" class="form-control form-control-sm absent-input" value="<?= (int) $record['absent_minutes'] ?>" min="0" style="display: none;">
                                    </td>
                                </tr>
                            <?php endforeach; ?>
                        </tbody>
                    </table>
                </div>

                <div class="alert alert-warning mt-4" role="alert">
                    匯出圖片時將自動帶入班別資訊與核准申請表格，方便保留稽核紀錄或與主管溝通。
                </div>
            </div>
        </div>
    </main>

    <script src="https://cdnjs.cloudflare.com/ajax/libs/html2canvas/1.4.1/html2canvas.min.js"></script>

    <script>
    // 【JS-01】切換編輯模式
    function enableEditing() {
        document.querySelectorAll('.status-text, .absent-text').forEach(el => el.style.display = 'none');
        document.querySelectorAll('.status-select, .absent-input').forEach(el => el.style.display = 'block');

        document.getElementById('edit_button').style.display = 'none';
        document.getElementById('save_button').style.display = 'inline-block';
        document.getElementById('cancel_button').style.display = 'inline-block';
    }

    // 【JS-02】取消編輯並回復顯示
    function cancelEditing() {
        document.querySelectorAll('.status-select, .absent-input').forEach(el => el.style.display = 'none');
        document.querySelectorAll('.status-text, .absent-text').forEach(el => el.style.display = 'block');

        document.getElementById('edit_button').style.display = 'inline-block';
        document.getElementById('cancel_button').style.display = 'none';
        document.getElementById('save_button').style.display = 'inline-block';
    }

    // 【JS-03】狀態文字轉換顏色徽章
    function renderStatusBadges(selectElement) {
        const container = selectElement.closest('td').querySelector('.status-text');
        if (!container) return;

        container.innerHTML = '';
        const selectedValues = Array.from(selectElement.selectedOptions).map(option => option.value);

        if (selectedValues.length === 0) {
            const badge = document.createElement('span');
            badge.className = 'badge status-badge status-badge-normal';
            badge.dataset.status = '正常出勤';
            badge.textContent = '正常出勤';
            container.appendChild(badge);
        } else {
            selectedValues.forEach(value => {
                const badge = document.createElement('span');
                badge.className = 'badge status-badge ' + mapStatusToBadge(value);
                badge.dataset.status = value;
                badge.textContent = value;
                container.appendChild(badge);
            });
        }
    }

    // 【JS-04】對應列顏色
    function updateRowColor(selectElement) {
        const row = selectElement.closest('tr');
        const selectedValues = Array.from(selectElement.selectedOptions).map(option => option.value);
        row.classList.remove('status-normal', 'status-yellow', 'status-orange', 'status-purple', 'status-red', 'status-holiday', 'status-leave', 'status-absent', 'status-overtime');

        if (selectedValues.includes('正常出勤')) {
            row.classList.add('status-normal');
        } else if (selectedValues.includes('國定假日') || selectedValues.includes('颱風假') || selectedValues.includes('禮拜六') || selectedValues.includes('禮拜日')) {
            row.classList.add('status-holiday');
        } else if (selectedValues.some(value => value.includes('假'))) {
            row.classList.add('status-leave');
        } else if (selectedValues.includes('曠職')) {
            row.classList.add('status-absent');
        } else if (selectedValues.includes('加班申請')) {
            row.classList.add('status-overtime');
        } else if (selectedValues.includes('遲到') || selectedValues.includes('早退')) {
            row.classList.add('status-red');
        } else if (selectedValues.includes('漏打卡(上班)') || selectedValues.includes('漏打卡(下班)')) {
            row.classList.add('status-yellow');
        } else {
            row.classList.add('status-orange');
        }

        renderStatusBadges(selectElement);
    }

    // 【JS-05】狀態顏色對應設定
    function mapStatusToBadge(status) {
        const danger = ['遲到', '早退'];
        const absent = ['曠職'];
        const warning = ['漏打卡(上班)', '漏打卡(下班)'];
        const holiday = ['國定假日', '颱風假', '禮拜六', '禮拜日'];

        if (danger.includes(status)) return 'status-badge-danger';
        if (absent.includes(status)) return 'status-badge-absent';
        if (warning.includes(status)) return 'status-badge-warning';
        if (holiday.includes(status)) return 'status-badge-holiday';
        if (status === '加班申請') return 'status-badge-overtime';
        if (status.includes('假')) return 'status-badge-leave';
        return 'status-badge-normal';
    }

    // 【JS-06】整理表格資料
    function collectAttendanceData() {
        const rows = document.querySelectorAll('.attendance-table tbody tr');
        const attendanceData = [];

        rows.forEach(row => {
            const date = row.querySelector('.date')?.textContent.trim();
            const rawFirstTime = row.querySelector('.first-time')?.textContent.trim() || '';
            const rawLastTime = row.querySelector('.last-time')?.textContent.trim() || '';
            const selectedOptions = row.querySelector('.status-select')?.selectedOptions || [];
            const statusText = Array.from(selectedOptions).map(opt => opt.value).join(',');
            const absentInput = row.querySelector('.absent-input');
            const absent = absentInput ? parseInt(absentInput.value, 10) || 0 : 0;

            if (date) {
                attendanceData.push({
                    date: date,
                    first_time: rawFirstTime || null,
                    last_time: rawLastTime || null,
                    status_text: statusText,
                    absent_minutes: absent
                });
            }
        });

        return attendanceData;
    }

    // 【JS-07】儲存至後端
    function saveAttendanceToServer() {
        const employeeNumber = document.querySelector('select[name="employee_number"]').value;
        const attendanceData = collectAttendanceData();

        if (!employeeNumber || attendanceData.length === 0) {
            alert('資料不完整，無法儲存！');
            return;
        }

        fetch('save_attendance.php', {
            method: 'POST',
            headers: { 'Content-Type': 'application/json' },
            body: JSON.stringify({
                employee_number: employeeNumber,
                attendance_data: attendanceData
            })
        })
        .then(response => response.json())
        .then(data => {
            if (data.success) {
                alert('✅ 資料已儲存成功！');
                location.reload();
            } else {
                alert('❌ 儲存失敗：' + data.message);
                if (data.error) console.error(data.error);
            }
        })
        .catch(error => {
            alert('❌ 發生錯誤，請稍後再試');
            console.error(error);
        });
    }

    // 【JS-08】匯出圖片
    function exportToImage() {
        const exportSection = document.createElement('div');
        exportSection.className = 'export-wrapper';

        const shiftSummary = document.querySelector('#shift-summary-section')?.cloneNode(true);
        const approvedSection = document.querySelector('#approved-requests-section')?.cloneNode(true);
        const attendanceSection = document.querySelector('#attendance-detail-section')?.cloneNode(true);

        if (!shiftSummary || !approvedSection || !attendanceSection) {
            alert('找不到匯出區塊，請確認表格是否正確包在對應區域中！');
            return;
        }

        attendanceSection.querySelector('#attendance-action-group')?.remove();
        attendanceSection.querySelectorAll('.status-select').forEach(select => select.style.display = 'none');
        attendanceSection.querySelectorAll('.absent-input').forEach(input => input.style.display = 'none');
        attendanceSection.querySelectorAll('.status-text').forEach(text => text.style.display = 'flex');
        attendanceSection.querySelectorAll('.absent-text').forEach(text => text.style.display = 'block');

        const year = document.querySelector('[name="year"]').value;
        const month = document.querySelector('[name="month"]').value;
        const employeeSelect = document.querySelector('[name="employee_number"]');
        const employeeName = employeeSelect && employeeSelect.selectedIndex >= 0
            ? employeeSelect.options[employeeSelect.selectedIndex].text.split(' - ')[1]
            : '未知員工';

        const title = document.createElement('h2');
        title.textContent = `${year}年${month}月 ${employeeName} 考勤資訊`;
        title.className = 'export-title';

        exportSection.appendChild(title);
        exportSection.appendChild(shiftSummary);
        exportSection.appendChild(approvedSection);
        exportSection.appendChild(attendanceSection);

        document.body.appendChild(exportSection);

        html2canvas(exportSection, {
            scale: 2,
            useCORS: true
        }).then(canvas => {
            const link = document.createElement('a');
            link.href = canvas.toDataURL('image/png');
            link.download = `${year}年${month}月_${employeeName}_考勤資訊.png`;
            link.click();
            document.body.removeChild(exportSection);
        }).catch(error => {
            console.error('匯出圖片失敗：', error);
            alert('匯出圖片失敗，請稍後再試！');
            document.body.removeChild(exportSection);
        });
    }

    // 【JS-09】載入後設定列顏色與缺勤文字
    document.addEventListener('DOMContentLoaded', () => {
        document.querySelectorAll('.status-select').forEach(select => {
            updateRowColor(select);
            select.addEventListener('change', () => updateRowColor(select));
        });

        document.querySelectorAll('.absent-input').forEach(input => {
            input.addEventListener('input', () => {
                const value = Math.max(0, parseInt(input.value || '0', 10));
                input.value = value;
                const text = input.closest('td').querySelector('.absent-value');
                if (text) {
                    text.dataset.minutes = value;
                    text.textContent = value;
                }
            });
        });
    });
    </script>
</body>
</html>