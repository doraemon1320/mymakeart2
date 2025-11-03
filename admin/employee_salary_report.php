<?php
session_start();

// 【PHP-01】登入權限檢查
if (!isset($_SESSION['user']) || $_SESSION['user']['role'] !== 'admin') {
    header('Location: ../login.php');
    exit;
}

// 【PHP-02】資料庫連線
$conn = new mysqli('localhost', 'root', '', 'mymakeart');
if ($conn->connect_error) {
    die('資料庫連線失敗：' . $conn->connect_error);
}

// 【PHP-03】初始化查詢條件
$last_month = date('n', strtotime('first day of last month'));
$last_year = date('Y', strtotime('first day of last month'));
$year = isset($_GET['year']) ? (int)$_GET['year'] : (int)$last_year;
$month = isset($_GET['month']) ? (int)$_GET['month'] : (int)$last_month;

$employee_sql = "SELECT id, employee_number, name, hire_date FROM employees ORDER BY id ASC";
$employee_list = $conn->query($employee_sql)->fetch_all(MYSQLI_ASSOC);
$employee_id = isset($_GET['employee_id']) ? (int)$_GET['employee_id'] : 0;
if ($employee_id === 0 && !empty($employee_list)) {
    $employee_id = (int)$employee_list[0]['id'];
}

$employee_profile = [
    'employee_number' => null,
    'hire_date' => null,
    'name' => '',
];
$salary_structure = [];
$salary_record = [];
$approved_requests = [];
$absent_result = [];
$leave_results = [];
$leave_deduction_details = [];
$leave_count = [];
$history_result = [];
$vacation_summary = [];
$overtime_details = [];

$base_salary = $meal_allowance = $attendance_bonus = $position_bonus = $skill_bonus = 0;
$labor_insurance = $health_insurance = $leave_deduction = $absent_deduction = 0;
$vacation_cash = $vacation_cash_days = $vacation_cash_hours = 0;
$overtime_pay = $gross_salary = $total_deductions = $net_salary = 0;
$total_absent_minutes = 0;
$hourly_rate = 0;
$can_convert_vacation = false;
$remaining_days = 0;
$remaining_hours_only = 0;

if ($employee_id > 0) {
    // 【PHP-04】查詢員工基本資料
    $employee_stmt = $conn->prepare('SELECT employee_number, hire_date, name FROM employees WHERE id = ? LIMIT 1');
    $employee_stmt->bind_param('i', $employee_id);
    $employee_stmt->execute();
    $employee_profile = $employee_stmt->get_result()->fetch_assoc() ?: $employee_profile;
    $employee_stmt->close();

    // 【PHP-05】取得薪資結構
    $structure_stmt = $conn->prepare('SELECT * FROM salary_structure WHERE employee_id = ? LIMIT 1');
    $structure_stmt->bind_param('i', $employee_id);
    $structure_stmt->execute();
    $salary_structure = $structure_stmt->get_result()->fetch_assoc() ?: [];
    $structure_stmt->close();

    // 【PHP-06】取得當月薪資紀錄
    $record_stmt = $conn->prepare('SELECT * FROM employee_monthly_salary WHERE employee_id = ? AND year = ? AND month = ? LIMIT 1');
    $record_stmt->bind_param('iii', $employee_id, $year, $month);
    $record_stmt->execute();
    $salary_record = $record_stmt->get_result()->fetch_assoc() ?: [];
    $record_stmt->close();

    // 【PHP-07】初始化薪資欄位
    $base_salary = (int)($salary_record['base_salary'] ?? ($salary_structure['base_salary'] ?? 0));
    $meal_allowance = (int)($salary_record['meal_allowance'] ?? ($salary_structure['meal_allowance'] ?? 0));
    $attendance_bonus = (int)($salary_record['attendance_bonus'] ?? ($salary_structure['attendance_bonus'] ?? 0));
    $position_bonus = (int)($salary_record['position_bonus'] ?? ($salary_structure['position_bonus'] ?? 0));
    $skill_bonus = (int)($salary_record['skill_bonus'] ?? ($salary_structure['skill_bonus'] ?? 0));
    $labor_insurance = (int)($salary_record['labor_insurance'] ?? ($salary_structure['labor_insurance'] ?? 0));
    $health_insurance = (int)($salary_record['health_insurance'] ?? ($salary_structure['health_insurance'] ?? 0));
    $vacation_cash = (int)($salary_record['vacation_cash'] ?? 0);
    $vacation_cash_days = (int)($salary_record['vacation_cash_days'] ?? 0);
    $vacation_cash_hours = (int)($salary_record['vacation_cash_hours'] ?? 0);
    $leave_deduction = isset($salary_record['leave_deduction']) ? (int)$salary_record['leave_deduction'] : 0;
    $absent_deduction = isset($salary_record['absent_deduction']) ? (int)$salary_record['absent_deduction'] : 0;
    $overtime_pay = isset($salary_record['overtime_pay']) ? (int)$salary_record['overtime_pay'] : 0;
    $hourly_rate = $base_salary > 0 ? (int)ceil($base_salary / 240) : 0;

    // 【PHP-08】查詢核准的請假/加班
    $start_of_month = sprintf('%04d-%02d-01', $year, $month);
    $end_of_month = date('Y-m-t', strtotime($start_of_month));

    $request_stmt = $conn->prepare('
        SELECT type, subtype, reason, start_date, end_date, status
        FROM requests
        WHERE employee_id = ?
          AND status = "Approved"
          AND (
                (start_date BETWEEN ? AND ?)
             OR (end_date BETWEEN ? AND ?)
             OR (start_date <= ? AND end_date >= ?)
          )
        ORDER BY start_date ASC
    ');
    $request_stmt->bind_param(
        'issssss',
        $employee_id,
        $start_of_month, $end_of_month,
        $start_of_month, $end_of_month,
        $start_of_month, $end_of_month
    );
    $request_stmt->execute();
    $approved_requests = $request_stmt->get_result()->fetch_all(MYSQLI_ASSOC);
    $request_stmt->close();

    // 【PHP-09】取得缺席紀錄
    $employee_number = $employee_profile['employee_number'] ?? '';
    if ($employee_number) {
        $absent_stmt = $conn->prepare('SELECT date, status_text, absent_minutes FROM saved_attendance WHERE employee_number = ? AND YEAR(date) = ? AND MONTH(date) = ? AND absent_minutes > 0');
        $absent_stmt->bind_param('sii', $employee_number, $year, $month);
        $absent_stmt->execute();
        $absent_result = $absent_stmt->get_result()->fetch_all(MYSQLI_ASSOC);
        $absent_stmt->close();
    }
    foreach ($absent_result as $row) {
        $total_absent_minutes += (int)$row['absent_minutes'];
    }

    // 【PHP-10】請假扣薪計算所需資料
    $leave_stmt = $conn->prepare('
        SELECT r.subtype, r.start_date, r.end_date, l.salary_ratio
        FROM requests r
        JOIN leave_types l ON r.subtype = l.name
        WHERE r.employee_id = ?
          AND r.status = "Approved"
          AND (
                (r.start_date BETWEEN ? AND ?)
             OR (r.end_date BETWEEN ? AND ?)
             OR (r.start_date <= ? AND r.end_date >= ?)
          )
    ');
    $leave_stmt->bind_param(
        'issssss',
        $employee_id,
        $start_of_month, $end_of_month,
        $start_of_month, $end_of_month,
        $start_of_month, $end_of_month
    );
    $leave_stmt->execute();
    $leave_results = $leave_stmt->get_result()->fetch_all(MYSQLI_ASSOC);
    $leave_stmt->close();

    // 【PHP-11】取得班別資訊供時數計算
    $shift_stmt = $conn->prepare('
        SELECT s.start_time, s.end_time, s.break_start, s.break_end
        FROM shifts s
        JOIN employees e ON s.id = e.shift_id
        WHERE e.id = ?
        LIMIT 1
    ');
    $shift_stmt->bind_param('i', $employee_id);
    $shift_stmt->execute();
    $shift = $shift_stmt->get_result()->fetch_assoc();
    $shift_stmt->close();

    // 【PHP-12】計算請假扣薪
    if ($hourly_rate > 0 && !empty($shift)) {
        $computed_leave_deductions = [];
        foreach ($leave_results as $leave) {
            $hours = calculateLeaveHoursByShift($leave['start_date'], $leave['end_date'], $shift);
            if ($hours <= 0) {
                continue;
            }
            $salary_ratio = (float)($leave['salary_ratio'] ?? 0);
            $deduct_percent = max(0, 100 - $salary_ratio);
            $amount = (int)ceil($hourly_rate * $hours * $deduct_percent / 100);
            $computed_leave_deductions[] = $amount;
            $leave_deduction_details[] = [
                'start' => $leave['start_date'],
                'end' => $leave['end_date'],
                'type' => $leave['subtype'] ?? '其他假別',
                'hours' => round($hours, 1),
                'hourly_rate' => $hourly_rate,
                'deduct_percent' => $deduct_percent,
                'amount' => $amount,
            ];
        }
        if (empty($salary_record['leave_deduction'])) {
            $leave_deduction = array_sum($computed_leave_deductions);
        }
    }

    // 【PHP-13】若未填寫缺席扣薪則自動換算
    if ($hourly_rate > 0 && $absent_deduction === 0 && $total_absent_minutes > 0) {
        $absent_deduction = (int)ceil($hourly_rate * ($total_absent_minutes / 60));
    }

    // 【PHP-14】請假統計
    $leave_types_res = $conn->query('SELECT name, days_per_year FROM leave_types');
    while ($row = $leave_types_res->fetch_assoc()) {
        $leave_count[$row['name']] = [
            'total_days' => (float)$row['days_per_year'],
            'used_days' => 0.0,
            'used_hours' => 0.0,
        ];
    }
    if (!empty($shift)) {
        $used_stmt = $conn->prepare('SELECT subtype, start_date, end_date FROM requests WHERE employee_id = ? AND status = "Approved"');
        $used_stmt->bind_param('i', $employee_id);
        $used_stmt->execute();
        $used_rows = $used_stmt->get_result()->fetch_all(MYSQLI_ASSOC);
        $used_stmt->close();
        foreach ($used_rows as $row) {
            $hours = calculateLeaveHoursByShift($row['start_date'], $row['end_date'], $shift);
            $days = floor($hours / 8);
            $remain_hours = fmod($hours, 8);
            $type = $row['subtype'];
            if (isset($leave_count[$type])) {
                $leave_count[$type]['used_days'] += $days;
                $leave_count[$type]['used_hours'] += $remain_hours;
            }
        }
    }

    // 【PHP-15】特休紀錄與彙總
    $history_stmt = $conn->prepare('
        SELECT year, month, days, hours, status, created_at
        FROM annual_leave_records
        WHERE employee_id = ?
        ORDER BY created_at DESC
        LIMIT 20
    ');
    $history_stmt->bind_param('i', $employee_id);
    $history_stmt->execute();
    $history_result = $history_stmt->get_result()->fetch_all(MYSQLI_ASSOC);
    $history_stmt->close();

    $summary_stmt = $conn->prepare('
        SELECT
            SUM(CASE WHEN status = "取得" THEN days ELSE 0 END) AS total_get_days,
            SUM(CASE WHEN status = "取得" THEN hours ELSE 0 END) AS total_get_hours,
            SUM(CASE WHEN status = "使用" THEN days ELSE 0 END) AS total_use_days,
            SUM(CASE WHEN status = "使用" THEN hours ELSE 0 END) AS total_use_hours,
            SUM(CASE WHEN status = "轉現金" THEN days ELSE 0 END) AS total_cash_days,
            SUM(CASE WHEN status = "轉現金" THEN hours ELSE 0 END) AS total_cash_hours
        FROM annual_leave_records
        WHERE employee_id = ?
    ');
    $summary_stmt->bind_param('i', $employee_id);
    $summary_stmt->execute();
    $vacation_summary = $summary_stmt->get_result()->fetch_assoc() ?: [];
    $summary_stmt->close();

    $total_get_hours = ($vacation_summary['total_get_days'] ?? 0) * 8 + ($vacation_summary['total_get_hours'] ?? 0);
    $total_use_hours = ($vacation_summary['total_use_days'] ?? 0) * 8 + ($vacation_summary['total_use_hours'] ?? 0);
    $total_cash_hours = ($vacation_summary['total_cash_days'] ?? 0) * 8 + ($vacation_summary['total_cash_hours'] ?? 0);
    $remaining_hours_total = $total_get_hours - $total_use_hours - $total_cash_hours;
    $remaining_days = $remaining_hours_total > 0 ? (int)floor($remaining_hours_total / 8) : 0;
    $remaining_hours_only = $remaining_hours_total > 0 ? fmod($remaining_hours_total, 8) : 0;

    // 【PHP-16】判斷特休是否可轉現金
    $conversion_stmt = $conn->prepare('SELECT COUNT(*) AS cnt FROM annual_leave_records WHERE employee_id = ? AND year = ? AND month = ? AND status = "轉現金"');
    $conversion_stmt->bind_param('iii', $employee_id, $year, $month);
    $conversion_stmt->execute();
    $converted = $conversion_stmt->get_result()->fetch_assoc();
    $conversion_stmt->close();
    $can_convert_vacation = ($remaining_days > 0) && (($converted['cnt'] ?? 0) == 0);

    // 【PHP-17】計算加班費
    if ($hourly_rate > 0 && empty($salary_record['overtime_pay'])) {
        foreach ($approved_requests as $request) {
            if ($request['type'] !== '加班') {
                continue;
            }
            $start_ts = strtotime($request['start_date']);
            $end_ts = strtotime($request['end_date']);
            if (!$start_ts || !$end_ts || $end_ts <= $start_ts) {
                continue;
            }
            $duration_hours = round(($end_ts - $start_ts) / 3600, 2);
            $segments = [];
            $is_holiday = isHoliday(date('Y-m-d', $start_ts), $conn);
            $remaining = $duration_hours;
            if ($is_holiday) {
                if ($remaining > 8) {
                    $segments[] = ['hours' => 2, 'rate' => 1.34];
                    $segments[] = ['hours' => 6, 'rate' => 1.67];
                    $segments[] = ['hours' => $remaining - 8, 'rate' => 2.67];
                } elseif ($remaining > 2) {
                    $segments[] = ['hours' => 2, 'rate' => 1.34];
                    $segments[] = ['hours' => $remaining - 2, 'rate' => 1.67];
                } else {
                    $segments[] = ['hours' => $remaining, 'rate' => 1.34];
                }
            } else {
                if ($remaining > 2) {
                    $segments[] = ['hours' => 2, 'rate' => 1.34];
                    $segments[] = ['hours' => $remaining - 2, 'rate' => 1.67];
                } else {
                    $segments[] = ['hours' => $remaining, 'rate' => 1.34];
                }
            }
            $segment_start = 0;
            foreach ($segments as $segment) {
                $segment_hours = $segment['hours'];
                $segment_rate = $segment['rate'];
                $segment_pay = (int)ceil($hourly_rate * $segment_hours * $segment_rate);
                $overtime_pay += $segment_pay;
                $range_start = $segment_start + 1;
                $range_end = $segment_start + $segment_hours;
                $overtime_details[] = [
                    'start' => $request['start_date'],
                    'end' => $request['end_date'],
                    'range_label' => $segment_hours > 1 ? sprintf('%d - %d 小時', $range_start, $range_end) : sprintf('%d 小時', $range_start),
                    'hours' => $segment_hours,
                    'rate' => $segment_rate,
                    'pay' => $segment_pay,
                ];
                $segment_start += $segment_hours;
            }
        }
    }

    // 【PHP-18】整體薪資計算
    $gross_salary = $base_salary + $meal_allowance + $attendance_bonus + $position_bonus + $skill_bonus + $vacation_cash + $overtime_pay;
    $total_deductions = $labor_insurance + $health_insurance + $leave_deduction + $absent_deduction;
    $net_salary = $gross_salary - $total_deductions;
}

// 【PHP-19】薪資結構顯示陣列
$salary_data = array_filter([
    '底薪' => $base_salary,
    '伙食費' => $meal_allowance,
    '全勤獎金' => $attendance_bonus,
    '職務加給' => $position_bonus,
    '技術津貼' => $skill_bonus,
]);

function calculateLeaveHoursByShift(string $start, string $end, array $shift): float
{
    $start_ts = strtotime($start);
    $end_ts = strtotime($end);
    if (!$start_ts || !$end_ts || $end_ts <= $start_ts) {
        return 0.0;
    }

    $total_seconds = 0;
    $current = $start_ts;
    while ($current < $end_ts) {
        $date = date('Y-m-d', $current);
        $shift_start = strtotime($date . ' ' . $shift['start_time']);
        $shift_end = strtotime($date . ' ' . $shift['end_time']);
        $break_start = strtotime($date . ' ' . $shift['break_start']);
        $break_end = strtotime($date . ' ' . $shift['break_end']);

        $actual_start = max($current, $shift_start);
        $actual_end = min($end_ts, $shift_end);
        if ($actual_end <= $actual_start) {
            $current = strtotime('+1 day', strtotime($date));
            continue;
        }

        $duration = $actual_end - $actual_start;
        $break_overlap = max(0, min($actual_end, $break_end) - max($actual_start, $break_start));
        $total_seconds += max(0, $duration - $break_overlap);

        $current = strtotime('+1 day', strtotime($date));
    }

    return round($total_seconds / 3600, 1);
}

function isHoliday(string $date, mysqli $conn): bool
{
    $stmt = $conn->prepare('SELECT is_working_day FROM holidays WHERE holiday_date = ? LIMIT 1');
    $stmt->bind_param('s', $date);
    $stmt->execute();
    $row = $stmt->get_result()->fetch_assoc();
    $stmt->close();
    if ($row !== null) {
        return (int)$row['is_working_day'] === 0;
    }
    $weekday = (int)date('w', strtotime($date));
    return $weekday === 0 || $weekday === 6;
}

?>
<!DOCTYPE html>
<html lang="zh-TW">
<head>
    <meta charset="UTF-8">
    <meta name="viewport" content="width=device-width, initial-scale=1.0">
    <title>員工薪資報表</title>
    <link rel="stylesheet" href="https://cdn.jsdelivr.net/npm/bootstrap@5.3.3/dist/css/bootstrap.min.css">
    <script src="https://code.jquery.com/jquery-3.7.1.min.js"></script>
    <script src="https://cdn.jsdelivr.net/npm/bootstrap@5.3.3/dist/js/bootstrap.bundle.min.js"></script>
    <link rel="stylesheet" href="admin_navbar.css">
    <style>
        :root {
            --brand-gold: #ffcd00;
            --brand-rose: #e36386;
            --brand-blue: #345d9d;
            --brand-gray: #f7f8fb;
        }
        body {
            background: var(--brand-gray);
            font-family: 'Noto Sans TC', 'Microsoft JhengHei', sans-serif;
        }
        .brand-navbar {
            background: #fff;
            border-bottom: 4px solid var(--brand-blue);
        }
        .brand-navbar .navbar-brand img {
            height: 44px;
        }
        .brand-navbar .navbar-brand span {
            color: var(--brand-blue);
            font-weight: 700;
            margin-left: 0.75rem;
        }
        .page-header {
            background: linear-gradient(135deg, rgba(255,205,0,0.16), rgba(52,93,157,0.16));
            border-radius: 1rem;
            padding: 2rem;
        }
        .page-header h1 {
            color: var(--brand-blue);
            font-weight: 700;
            margin-bottom: 0.5rem;
        }
        .page-header p {
            color: #5a5a5a;
            margin: 0;
        }
        .section-card {
            border: none;
            border-radius: 1rem;
            box-shadow: 0 12px 30px rgba(52,93,157,0.08);
            margin-bottom: 2rem;
        }
        .section-card .card-header {
            background: var(--brand-blue);
            color: #fff;
            border-radius: 1rem 1rem 0 0;
            font-weight: 600;
        }
        .editing-alert {
            border-left: 4px solid var(--brand-rose);
            background: rgba(227, 99, 134, 0.12);
            color: #b12d51;
        }
        .salary-editing-mode .form-control:not(.d-none) {
            background: rgba(255, 205, 0, 0.12);
            border-color: rgba(52, 93, 157, 0.4);
        }
        .table thead.table-primary {
            background-color: rgba(52,93,157,0.12) !important;
            color: #1d2f53;
        }
        .btn-brand {
            background: var(--brand-blue);
            color: #fff;
        }
        .btn-brand:hover {
            background: #274276;
            color: #fff;
        }
        .btn-outline-brand {
            border-color: var(--brand-blue);
            color: var(--brand-blue);
        }
        .btn-outline-brand:hover {
            background: var(--brand-blue);
            color: #fff;
        }
        .salary-summary .highlight {
            color: var(--brand-rose);
            font-weight: 700;
        }
        .double-underline {
            border-bottom: double 3px #222;
        }
        .deduction-amount {
            color: #d62839;
            font-weight: 600;
        }
    </style>
</head>
<body>

<?php include 'admin_navbar.php'; ?>

<div class="container py-4">
    <div class="page-header mb-4">
        <h1>員工薪資報表</h1>
        <p>檢視並調整員工於 <?= htmlspecialchars($year) ?> 年 <?= htmlspecialchars($month) ?> 月的薪資結構、請假扣薪與出缺勤紀錄，確保薪資核算透明無誤。</p>
    </div>

    <div class="section-card card">
        <div class="card-body">
            <form method="GET" class="row g-3 align-items-end">
                <div class="col-lg-4 col-md-6">
                    <label for="employee_id" class="form-label">選擇員工</label>
                    <select id="employee_id" name="employee_id" class="form-select" required>
                        <?php foreach ($employee_list as $emp): ?>
                            <option value="<?= htmlspecialchars($emp['id']) ?>" <?= $employee_id == $emp['id'] ? 'selected' : '' ?>>
                                <?= htmlspecialchars($emp['employee_number'] . ' - ' . $emp['name']) ?>
                            </option>
                        <?php endforeach; ?>
                    </select>
                </div>
                <div class="col-md-4 col-lg-3">
                    <label for="year" class="form-label">年份</label>
                    <input type="number" id="year" name="year" class="form-control" value="<?= htmlspecialchars($year) ?>" required>
                </div>
                <div class="col-md-4 col-lg-3">
                    <label for="month" class="form-label">月份</label>
                    <select id="month" name="month" class="form-select">
                        <?php for ($m = 1; $m <= 12; $m++): ?>
                            <option value="<?= $m ?>" <?= $m == $month ? 'selected' : '' ?>><?= $m ?> 月</option>
                        <?php endfor; ?>
                    </select>
                </div>
                <div class="col-md-4 col-lg-2 d-grid">
                    <button type="submit" class="btn btn-brand">重新查詢</button>
                </div>
            </form>
        </div>
    </div>

    <?php if ($employee_id > 0): ?>
    <div class="row g-4 mb-4">
        <div class="col-md-6">
            <div class="section-card card h-100">
                <div class="card-header">員工基本資料</div>
                <div class="card-body">
                    <div class="row gy-2">
                        <div class="col-5 text-secondary">員工姓名</div>
                        <div class="col-7 fw-semibold"><?= htmlspecialchars($employee_profile['name'] ?? '未設定') ?></div>
                        <div class="col-5 text-secondary">員工編號</div>
                        <div class="col-7 fw-semibold"><?= htmlspecialchars($employee_profile['employee_number'] ?? '未設定') ?></div>
                        <div class="col-5 text-secondary">到職日</div>
                        <div class="col-7 fw-semibold"><?= $employee_profile['hire_date'] ? htmlspecialchars($employee_profile['hire_date']) : '未設定' ?></div>
                        <div class="col-5 text-secondary">計薪月份</div>
                        <div class="col-7 fw-semibold"><?= htmlspecialchars($year) ?> 年 <?= htmlspecialchars($month) ?> 月</div>
                    </div>
                </div>
            </div>
        </div>
        <div class="col-md-6">
            <div class="section-card card h-100">
                <div class="card-header">薪資概況</div>
                <div class="card-body salary-summary">
                    <div class="d-flex justify-content-between mb-2">
                        <span>底薪換算時薪</span>
                        <span class="highlight"><?= number_format($hourly_rate) ?> 元</span>
                    </div>
                    <div class="d-flex justify-content-between mb-2">
                        <span>請假扣薪總額</span>
                        <span class="highlight"><?= number_format($leave_deduction) ?> 元</span>
                    </div>
                    <div class="d-flex justify-content-between mb-2">
                        <span>遲到/缺勤扣薪</span>
                        <span class="highlight"><?= number_format($absent_deduction) ?> 元</span>
                    </div>
                    <div class="d-flex justify-content-between">
                        <span>實領薪資</span>
                        <span class="highlight fs-4"><?= number_format($net_salary) ?> 元</span>
                    </div>
                </div>
            </div>
        </div>
    </div>

    <div class="section-card card">
        <div class="card-header">員工薪資結構</div>
        <div class="card-body">
            <?php if (!empty($salary_data)): ?>
                <div class="table-responsive">
                    <table class="table table-bordered align-middle mb-0">
                        <thead class="table-primary">
                            <tr>
                                <th style="width: 35%;">項目</th>
                                <th>金額（元）</th>
                            </tr>
                        </thead>
                        <tbody>
                            <?php foreach ($salary_data as $key => $value): ?>
                                <tr>
                                    <td class="fw-semibold text-secondary"><?= htmlspecialchars($key) ?></td>
                                    <td><?= number_format($value) ?></td>
                                </tr>
                            <?php endforeach; ?>
                        </tbody>
                    </table>
                </div>
            <?php else: ?>
                <p class="text-muted mb-0">尚未設定薪資結構。</p>
            <?php endif; ?>
        </div>
    </div>

    <?php if (!empty($approved_requests)): ?>
    <div class="section-card card">
        <div class="card-header">當月核准的請假與加班申請</div>
        <div class="card-body">
            <div class="table-responsive">
                <table class="table table-bordered align-middle mb-0">
                    <thead class="table-primary">
                        <tr>
                            <th>類型</th>
                            <th>假別 / 加班別</th>
                            <th>申請事由</th>
                            <th>起始時間</th>
                            <th>結束時間</th>
                            <th>狀態</th>
                        </tr>
                    </thead>
                    <tbody>
                        <?php foreach ($approved_requests as $request): ?>
                            <tr>
                                <td><?= htmlspecialchars($request['type']) ?></td>
                                <td><?= htmlspecialchars($request['subtype'] ?? '-') ?></td>
                                <td><?= htmlspecialchars($request['reason'] ?? '-') ?></td>
                                <td><?= htmlspecialchars($request['start_date']) ?></td>
                                <td><?= htmlspecialchars($request['end_date']) ?></td>
                                <td><span class="badge rounded-pill bg-success-subtle text-success fw-semibold">已核准</span></td>
                            </tr>
                        <?php endforeach; ?>
                    </tbody>
                </table>
            </div>
        </div>
    </div>
    <?php endif; ?>

    <?php if (!empty($leave_count)): ?>
    <div class="section-card card">
        <div class="card-header">假別使用統計</div>
        <div class="card-body">
            <div class="table-responsive">
                <table class="table table-bordered align-middle mb-0">
                    <thead class="table-primary">
                        <tr>
                            <th>假別名稱</th>
                            <th>年度可休天數</th>
                            <th>已使用天數</th>
                            <th>已使用小時</th>
                            <th>剩餘天數</th>
                            <th>剩餘小時</th>
                        </tr>
                    </thead>
                    <tbody>
                        <?php foreach ($leave_count as $type => $count): ?>
                            <?php
                                $used_days = (float)$count['used_days'];
                                $used_hours = (float)$count['used_hours'];
                                if ($used_days == 0 && $used_hours == 0) {
                                    continue;
                                }
                                $total_hours = $count['total_days'] * 8;
                                $used_total_hours = $used_days * 8 + $used_hours;
                                $remain_hours = max(0, $total_hours - $used_total_hours);
                                $remain_days = floor($remain_hours / 8);
                                $remain_hours_only = fmod($remain_hours, 8);
                            ?>
                            <tr>
                                <td><?= htmlspecialchars($type) ?></td>
                                <td><?= number_format($count['total_days'], 1) ?></td>
                                <td><?= number_format($used_days, 1) ?></td>
                                <td><?= number_format($used_hours, 1) ?></td>
                                <td><?= number_format($remain_days, 1) ?></td>
                                <td><?= number_format($remain_hours_only, 1) ?></td>
                            </tr>
                        <?php endforeach; ?>
                    </tbody>
                </table>
            </div>
        </div>
    </div>
    <?php endif; ?>

    <?php if (!empty($history_result)): ?>
    <div class="section-card card">
        <div class="card-header">特休變動紀錄</div>
        <div class="card-body">
            <div class="table-responsive">
                <table class="table table-bordered align-middle mb-0">
                    <thead class="table-primary">
                        <tr>
                            <th>年度</th>
                            <th>月份</th>
                            <th>天數</th>
                            <th>小時</th>
                            <th>狀態</th>
                            <th>建立時間</th>
                        </tr>
                    </thead>
                    <tbody>
                        <?php foreach ($history_result as $row): ?>
                            <tr>
                                <td><?= htmlspecialchars($row['year']) ?></td>
                                <td><?= htmlspecialchars($row['month']) ?></td>
                                <td><?= number_format($row['days'] ?? 0, 1) ?></td>
                                <td><?= number_format($row['hours'] ?? 0, 1) ?></td>
                                <td><?= htmlspecialchars($row['status']) ?></td>
                                <td><?= htmlspecialchars($row['created_at']) ?></td>
                            </tr>
                        <?php endforeach; ?>
                    </tbody>
                </table>
            </div>
        </div>
    </div>
    <?php endif; ?>

    <?php if (!empty($vacation_summary)): ?>
    <div class="section-card card">
        <div class="card-header">特休總覽</div>
        <div class="card-body">
            <div class="table-responsive">
                <table class="table table-bordered align-middle mb-0">
                    <thead class="table-primary">
                        <tr>
                            <th>取得特休 (天)</th>
                            <th>取得特休 (小時)</th>
                            <th>已使用 (天)</th>
                            <th>已使用 (小時)</th>
                            <th>已轉現金 (天)</th>
                            <th>已轉現金 (小時)</th>
                            <th>剩餘特休 (天)</th>
                            <th>剩餘特休 (小時)</th>
                        </tr>
                    </thead>
                    <tbody>
                        <tr>
                            <td><?= number_format($vacation_summary['total_get_days'] ?? 0, 1) ?></td>
                            <td><?= number_format($vacation_summary['total_get_hours'] ?? 0, 1) ?></td>
                            <td><?= number_format($vacation_summary['total_use_days'] ?? 0, 1) ?></td>
                            <td><?= number_format($vacation_summary['total_use_hours'] ?? 0, 1) ?></td>
                            <td><?= number_format($vacation_summary['total_cash_days'] ?? 0, 1) ?></td>
                            <td><?= number_format($vacation_summary['total_cash_hours'] ?? 0, 1) ?></td>
                            <td><?= number_format($remaining_days, 1) ?></td>
                            <td><?= number_format($remaining_hours_only ?? 0, 1) ?></td>
                        </tr>
                    </tbody>
                </table>
            </div>
        </div>
    </div>
    <?php endif; ?>

    <?php if ($can_convert_vacation): ?>
    <div class="section-card card">
        <div class="card-header">特休轉現金設定</div>
        <div class="card-body">
            <div class="row align-items-center g-3">
                <div class="col-md-6">
                    <label for="vacation_cash_days" class="form-label mb-0">可轉換特休天數：剩餘 <?= number_format($remaining_days, 0) ?> 天</label>
                    <select id="vacation_cash_days" name="vacation_cash_days" class="form-select">
                        <?php for ($i = 0; $i <= $remaining_days; $i++): ?>
                            <option value="<?= $i ?>" <?= $i == $vacation_cash_days ? 'selected' : '' ?>><?= $i ?> 天</option>
                        <?php endfor; ?>
                    </select>
                </div>
                <div class="col-md-6">
                    <div class="alert alert-warning mb-0">
                        以底薪換算：底薪 ÷ 240 × 8 小時 × 轉換天數，自動計入「特休轉現金」。
                    </div>
                </div>
            </div>
        </div>
    </div>
    <?php endif; ?>

    <?php if (!empty($absent_result)): ?>
    <div class="section-card card">
        <div class="card-header">本月缺席與遲到紀錄</div>
        <div class="card-body">
            <div class="table-responsive">
                <table class="table table-bordered align-middle mb-3">
                    <thead class="table-primary">
                        <tr>
                            <th>日期</th>
                            <th>狀態說明</th>
                            <th>缺席分鐘</th>
                        </tr>
                    </thead>
                    <tbody>
                        <?php foreach ($absent_result as $row): ?>
                            <tr>
                                <td><?= htmlspecialchars($row['date']) ?></td>
                                <td><?= htmlspecialchars($row['status_text']) ?></td>
                                <td><?= htmlspecialchars($row['absent_minutes']) ?></td>
                            </tr>
                        <?php endforeach; ?>
                    </tbody>
                </table>
            </div>
            <p class="mb-0">總缺席時數：<span class="fw-semibold text-danger"><?= htmlspecialchars($total_absent_minutes) ?> 分鐘</span>（約 <?= round($total_absent_minutes / 60, 1) ?> 小時）</p>
        </div>
    </div>
    <?php endif; ?>

    <div class="section-card card">
        <div class="card-header">薪資明細與核算</div>
        <div class="card-body">
            <form method="POST" action="save_salary.php" class="needs-validation" id="salary_form" novalidate>
                <input type="hidden" name="employee_id" value="<?= htmlspecialchars($employee_id) ?>">
                <input type="hidden" name="year" value="<?= htmlspecialchars($year) ?>">
                <input type="hidden" name="month" value="<?= htmlspecialchars($month) ?>">
                <input type="hidden" id="vacation_cash_hours" name="vacation_cash_hours" value="<?= htmlspecialchars($vacation_cash_hours) ?>">
                <input type="hidden" name="vacation_cash_days" id="vacation_cash_days_hidden" value="<?= htmlspecialchars($vacation_cash_days) ?>">

                <div id="edit_mode_alert" class="alert alert-warning editing-alert d-none" role="alert">
                    已啟用薪資編輯模式，請調整後記得儲存或取消。
                </div>

                <div class="table-responsive">
                    <table class="table table-bordered align-middle">
                        <thead class="table-primary">
                            <tr>
                                <th style="width: 18%;">項目</th>
                                <th style="width: 18%;">金額（元）</th>
                                <th>計算說明</th>
                            </tr>
                        </thead>
                        <tbody>
                            <tr id="row_base_salary" <?= $base_salary > 0 ? '' : 'style="display:none;"' ?>>
                                <td class="table-primary">底薪</td>
                                <td>
                                    <span id="base_salary_display"><?= htmlspecialchars($base_salary) ?></span>
                                    <input type="number" class="form-control d-none" id="base_salary" name="base_salary" value="<?= htmlspecialchars($base_salary) ?>" required oninput="updateSalary()">
                                </td>
                                <td>
                                    <div id="base_salary_note_display"><?= nl2br(htmlspecialchars($salary_record['base_salary_note'] ?? '')) ?></div>
                                    <textarea class="form-control d-none" id="base_salary_note" name="base_salary_note"><?= htmlspecialchars($salary_record['base_salary_note'] ?? '') ?></textarea>
                                </td>
                            </tr>
                            <tr id="row_meal_allowance" <?= $meal_allowance > 0 ? '' : 'style="display:none;"' ?>>
                                <td class="table-primary">餐費</td>
                                <td>
                                    <span id="meal_allowance_display"><?= htmlspecialchars($meal_allowance) ?></span>
                                    <input type="number" class="form-control d-none" id="meal_allowance" name="meal_allowance" value="<?= htmlspecialchars($meal_allowance) ?>" oninput="updateSalary()">
                                </td>
                                <td>
                                    <div id="meal_allowance_note_display"><?= nl2br(htmlspecialchars($salary_record['meal_allowance_note'] ?? '')) ?></div>
                                    <textarea class="form-control d-none" id="meal_allowance_note" name="meal_allowance_note"><?= htmlspecialchars($salary_record['meal_allowance_note'] ?? '') ?></textarea>
                                </td>
                            </tr>
                            <tr id="row_attendance_bonus" <?= $attendance_bonus > 0 ? '' : 'style="display:none;"' ?>>
                                <td class="table-primary">全勤獎金</td>
                                <td>
                                    <span id="attendance_bonus_display"><?= htmlspecialchars($attendance_bonus) ?></span>
                                    <input type="number" class="form-control d-none" id="attendance_bonus" name="attendance_bonus" value="<?= htmlspecialchars($attendance_bonus) ?>" oninput="updateSalary()">
                                </td>
                                <td>
                                    <div id="attendance_bonus_note_display"><?= nl2br(htmlspecialchars($salary_record['attendance_bonus_note'] ?? '')) ?></div>
                                    <textarea class="form-control d-none" id="attendance_bonus_note" name="attendance_bonus_note"><?= htmlspecialchars($salary_record['attendance_bonus_note'] ?? '') ?></textarea>
                                </td>
                            </tr>
                            <tr id="row_position_bonus" <?= $position_bonus > 0 ? '' : 'style="display:none;"' ?>>
                                <td class="table-primary">職務加給</td>
                                <td>
                                    <span id="position_bonus_display"><?= htmlspecialchars($position_bonus) ?></span>
                                    <input type="number" class="form-control d-none" id="position_bonus" name="position_bonus" value="<?= htmlspecialchars($position_bonus) ?>" oninput="updateSalary()">
                                </td>
                                <td>
                                    <div id="position_bonus_note_display"><?= nl2br(htmlspecialchars($salary_record['position_bonus_note'] ?? '')) ?></div>
                                    <textarea class="form-control d-none" id="position_bonus_note" name="position_bonus_note"><?= htmlspecialchars($salary_record['position_bonus_note'] ?? '') ?></textarea>
                                </td>
                            </tr>
                            <tr id="row_skill_bonus" <?= $skill_bonus > 0 ? '' : 'style="display:none;"' ?>>
                                <td class="table-primary">技術津貼</td>
                                <td>
                                    <span id="skill_bonus_display"><?= htmlspecialchars($skill_bonus) ?></span>
                                    <input type="number" class="form-control d-none" id="skill_bonus" name="skill_bonus" value="<?= htmlspecialchars($skill_bonus) ?>" oninput="updateSalary()">
                                </td>
                                <td>
                                    <div id="skill_bonus_note_display"><?= nl2br(htmlspecialchars($salary_record['skill_bonus_note'] ?? '')) ?></div>
                                    <textarea class="form-control d-none" id="skill_bonus_note" name="skill_bonus_note"><?= htmlspecialchars($salary_record['skill_bonus_note'] ?? '') ?></textarea>
                                </td>
                            </tr>
                            <tr id="row_overtime_pay" <?= $overtime_pay > 0 ? '' : 'style="display:none;"' ?>>
                                <td class="table-primary">加班費</td>
                                <td>
                                    <span id="overtime_pay_display"><?= htmlspecialchars($overtime_pay) ?></span>
                                    <input type="number" class="form-control d-none" id="overtime_pay" name="overtime_pay" value="<?= htmlspecialchars($overtime_pay) ?>" oninput="updateSalary()">
                                </td>
                                <td>
                                    <div id="overtime_note_display">
                                        <?php if (!empty($salary_record['overtime_note'])): ?>
                                            <?= nl2br(htmlspecialchars($salary_record['overtime_note'])) ?>
                                        <?php else: ?>
                                            <?php if (!empty($overtime_details)): ?>
                                                <?php foreach ($overtime_details as $detail): ?>
                                                    <?= htmlspecialchars($detail['start']) ?> ~ <?= htmlspecialchars($detail['end']) ?>：<?= htmlspecialchars($detail['range_label']) ?> × 時薪 <?= number_format($hourly_rate) ?> × <?= $detail['rate'] ?> 倍 = <?= number_format($detail['pay']) ?> 元<br>
                                                <?php endforeach; ?>
                                            <?php else: ?>
                                                依核准加班申請自動計算。
                                            <?php endif; ?>
                                        <?php endif; ?>
                                    </div>
                                    <textarea class="form-control d-none" id="overtime_note" name="overtime_note"><?= htmlspecialchars($salary_record['overtime_note'] ?? '') ?></textarea>
                                </td>
                            </tr>
                            <tr id="row_vacation_cash" <?= $vacation_cash > 0 ? '' : 'style="display:none;"' ?>>
                                <td class="table-primary">特休轉現金</td>
                                <td>
                                    <span id="vacation_cash_display"><?= htmlspecialchars($vacation_cash) ?></span>
                                    <input type="number" class="form-control d-none" id="vacation_cash" name="vacation_cash" value="<?= htmlspecialchars($vacation_cash) ?>" readonly oninput="updateSalary()">
                                </td>
                                <td>
                                    <?php $vacation_note_text = $salary_record['vacation_cash_note'] ?? '底薪 ÷ 240 × 8 小時 × 特休轉換天數'; ?>
                                    <div id="vacation_cash_note_display"><?= nl2br(htmlspecialchars($vacation_note_text)) ?></div>
                                    <textarea class="form-control d-none" id="vacation_cash_note" name="vacation_cash_note"><?= htmlspecialchars($vacation_note_text) ?></textarea>
                                </td>
                            </tr>
                            <tr class="fw-semibold">
                                <td class="table-primary">總工資</td>
                                <td>
                                    <span id="gross_salary_display"><?= htmlspecialchars($gross_salary) ?></span>
                                    <input type="hidden" id="gross_salary" name="gross_salary" value="<?= htmlspecialchars($gross_salary) ?>">
                                </td>
                                <td></td>
                            </tr>
                            <tr id="row_labor_insurance">
                                <td class="table-primary">勞保費</td>
                                <td>
                                    <span class="deduction-amount" id="labor_insurance_display"><?= htmlspecialchars($labor_insurance) ?></span>
                                    <input type="number" class="form-control d-none" id="labor_insurance" name="labor_insurance" value="<?= htmlspecialchars($labor_insurance) ?>" oninput="updateSalary()">
                                </td>
                                <td>
                                    <div id="labor_insurance_note_display"><?= nl2br(htmlspecialchars($salary_record['labor_insurance_note'] ?? '依級距表自動計算')) ?></div>
                                    <textarea class="form-control d-none" id="labor_insurance_note" name="labor_insurance_note"><?= htmlspecialchars($salary_record['labor_insurance_note'] ?? '依級距表自動計算') ?></textarea>
                                </td>
                            </tr>
                            <tr id="row_health_insurance">
                                <td class="table-primary">健保費</td>
                                <td>
                                    <span class="deduction-amount" id="health_insurance_display"><?= htmlspecialchars($health_insurance) ?></span>
                                    <input type="number" class="form-control d-none" id="health_insurance" name="health_insurance" value="<?= htmlspecialchars($health_insurance) ?>" oninput="updateSalary()">
                                </td>
                                <td>
                                    <div id="health_insurance_note_display"><?= nl2br(htmlspecialchars($salary_record['health_insurance_note'] ?? '依級距表自動計算')) ?></div>
                                    <textarea class="form-control d-none" id="health_insurance_note" name="health_insurance_note"><?= htmlspecialchars($salary_record['health_insurance_note'] ?? '依級距表自動計算') ?></textarea>
                                </td>
                            </tr>
                            <tr id="row_leave_deduction" <?= $leave_deduction > 0 ? '' : 'style="display:none;"' ?>>
                                <td class="table-primary">請假扣薪</td>
                                <td>
                                    <span class="deduction-amount" id="leave_deduction_display"><?= htmlspecialchars($leave_deduction) ?></span>
                                    <input type="number" class="form-control d-none" id="leave_deduction" name="leave_deduction" value="<?= htmlspecialchars($leave_deduction) ?>" oninput="updateSalary()">
                                </td>
                                <td>
                                    <div id="leave_deduction_note_display">
                                        <?php if (!empty($salary_record['leave_deduction_note'])): ?>
                                            <?= nl2br(htmlspecialchars($salary_record['leave_deduction_note'])) ?>
                                        <?php elseif (!empty($leave_deduction_details)): ?>
                                            <?php foreach ($leave_deduction_details as $detail): ?>
                                                [<?= htmlspecialchars(date('Y-m-d', strtotime($detail['start']))) ?>] <?= htmlspecialchars($detail['type']) ?>（<?= htmlspecialchars($detail['start']) ?> ~ <?= htmlspecialchars($detail['end']) ?>）：<?= number_format($detail['hours'], 1) ?> 小時 × 時薪 <?= number_format($detail['hourly_rate']) ?> 元 × 扣除 <?= $detail['deduct_percent'] ?>% = <?= number_format($detail['amount']) ?> 元<br>
                                            <?php endforeach; ?>
                                        <?php elseif ($leave_deduction > 0): ?>
                                            已輸入請假扣薪 <?= number_format($leave_deduction) ?> 元，請於備註欄補充計算方式。
                                        <?php else: ?>
                                            本月無請假扣薪紀錄。
                                        <?php endif; ?>
                                    </div>
                                    <textarea class="form-control d-none" id="leave_deduction_note" name="leave_deduction_note"><?php
                                        if (!empty($salary_record['leave_deduction_note'])) {
                                            echo htmlspecialchars($salary_record['leave_deduction_note']);
                                        } elseif (!empty($leave_deduction_details)) {
                                            $notes = [];
                                            foreach ($leave_deduction_details as $detail) {
                                                $notes[] = sprintf('[%s] %s（%s ~ %s）：%s 小時 × 時薪 %s 元 × 扣除 %s%% = %s 元',
                                                    date('Y-m-d', strtotime($detail['start'])),
                                                    $detail['type'],
                                                    $detail['start'],
                                                    $detail['end'],
                                                    number_format($detail['hours'], 1),
                                                    number_format($detail['hourly_rate']),
                                                    $detail['deduct_percent'],
                                                    number_format($detail['amount'])
                                                );
                                            }
                                            echo htmlspecialchars(implode("\n", $notes));
                                        } elseif ($leave_deduction > 0) {
                                            echo '已輸入請假扣薪金額，請在此說明計算方式。';
                                        } else {
                                            echo '本月無請假扣薪紀錄。';
                                        }
                                    ?></textarea>
                                </td>
                            </tr>
                            <tr id="row_absent_deduction" <?= $absent_deduction > 0 ? '' : 'style="display:none;"' ?>>
                                <td class="table-primary">缺勤扣薪</td>
                                <td>
                                    <span class="deduction-amount" id="absent_deduction_display"><?= htmlspecialchars($absent_deduction) ?></span>
                                    <input type="number" class="form-control d-none" id="absent_deduction" name="absent_deduction" value="<?= htmlspecialchars($absent_deduction) ?>" oninput="updateSalary()">
                                </td>
                                <td>
                                    <div id="absent_deduction_note_display">
                                        <?php if (!empty($salary_record['absent_deduction_note'])): ?>
                                            <?= nl2br(htmlspecialchars($salary_record['absent_deduction_note'])) ?>
                                        <?php else: ?>
                                            缺席 <?= $total_absent_minutes ?> 分鐘（約 <?= round($total_absent_minutes / 60, 1) ?> 小時） × 時薪 <?= number_format($hourly_rate) ?> 元。
                                        <?php endif; ?>
                                    </div>
                                    <textarea class="form-control d-none" id="absent_deduction_note" name="absent_deduction_note"><?= htmlspecialchars($salary_record['absent_deduction_note'] ?? '') ?></textarea>
                                </td>
                            </tr>
                            <tr class="fw-semibold">
                                <td class="table-primary">總扣除</td>
                                <td>
                                    <span class="deduction-amount" id="total_deductions_display"><?= htmlspecialchars($total_deductions) ?></span>
                                    <input type="hidden" id="total_deductions" name="total_deductions" value="<?= htmlspecialchars($total_deductions) ?>">
                                </td>
                                <td></td>
                            </tr>
                            <tr class="fw-bold">
                                <td class="table-primary">實領薪資</td>
                                <td class="double-underline">
                                    <span id="net_salary_display"><?= htmlspecialchars($net_salary) ?></span>
                                    <input type="hidden" id="net_salary" name="net_salary" value="<?= htmlspecialchars($net_salary) ?>">
                                </td>
                                <td>總工資 - 總扣除</td>
                            </tr>
                        </tbody>
                    </table>
                </div>

                <div class="d-flex flex-wrap gap-2 justify-content-end">
                    <button type="submit" class="btn btn-success">儲存薪資</button>
                    <?php if (!empty($salary_record)): ?>
                        <button type="button" id="edit_salary_btn" class="btn btn-warning" onclick="enableSalaryEditing()">修改薪資</button>
                        <button type="button" id="cancel_edit_btn" class="btn btn-outline-danger d-none" onclick="cancelSalaryEditing()">取消修改</button>
                    <?php endif; ?>
                    <button type="button" id="export_image_btn" class="btn btn-outline-brand" onclick="exportToImage()">匯出圖片</button>
                </div>
            </form>
        </div>
    </div>
    <?php endif; ?>
</div>

<script src="https://cdnjs.cloudflare.com/ajax/libs/html2canvas/1.4.1/html2canvas.min.js"></script>
<script>
// 【JS-01】啟用薪資欄位編輯模式
function enableSalaryEditing() {
    const fields = [
        'base_salary', 'meal_allowance', 'attendance_bonus', 'position_bonus', 'skill_bonus',
        'overtime_pay', 'vacation_cash', 'labor_insurance', 'health_insurance', 'leave_deduction', 'absent_deduction'
    ];
    const form = document.getElementById('salary_form');
    if (form) {
        form.classList.add('salary-editing-mode');
    }
    const alertBox = document.getElementById('edit_mode_alert');
    if (alertBox) {
        alertBox.classList.remove('d-none');
    }
    fields.forEach(id => {
        const input = document.getElementById(id);
        const display = document.getElementById(id + '_display');
        if (input) {
            input.classList.remove('d-none');
            input.removeAttribute('readonly');
        }
        if (display) {
            display.classList.add('d-none');
        }
        const noteInput = document.getElementById(id + '_note');
        const noteDisplay = document.getElementById(id + '_note_display');
        if (noteInput) {
            noteInput.classList.remove('d-none');
        }
        if (noteDisplay) {
            noteDisplay.classList.add('d-none');
        }
        const row = document.getElementById('row_' + id);
        if (row) {
            row.style.display = 'table-row';
        }
    });
    const cancelBtn = document.getElementById('cancel_edit_btn');
    if (cancelBtn) {
        cancelBtn.classList.remove('d-none');
    }
    const editBtn = document.getElementById('edit_salary_btn');
    if (editBtn) {
        editBtn.classList.add('d-none');
    }
    const firstInput = document.getElementById('base_salary');
    if (firstInput) {
        firstInput.focus();
        firstInput.select();
    }
}

// 【JS-02】取消編輯恢復原貌
function cancelSalaryEditing() {
    const fields = [
        'base_salary', 'meal_allowance', 'attendance_bonus', 'position_bonus', 'skill_bonus',
        'overtime_pay', 'vacation_cash', 'labor_insurance', 'health_insurance', 'leave_deduction', 'absent_deduction'
    ];
    const form = document.getElementById('salary_form');
    if (form) {
        form.classList.remove('salary-editing-mode');
    }
    const alertBox = document.getElementById('edit_mode_alert');
    if (alertBox) {
        alertBox.classList.add('d-none');
    }
    fields.forEach(id => {
        const input = document.getElementById(id);
        const display = document.getElementById(id + '_display');
        if (input) {
            input.classList.add('d-none');
            if (id === 'vacation_cash') {
                input.setAttribute('readonly', true);
            }
            input.value = display ? display.textContent.trim().replace(/,/g, '') : input.value;
        }
        if (display) {
            display.classList.remove('d-none');
        }
        const noteInput = document.getElementById(id + '_note');
        const noteDisplay = document.getElementById(id + '_note_display');
        if (noteInput) {
            noteInput.classList.add('d-none');
        }
        if (noteDisplay) {
            noteDisplay.classList.remove('d-none');
        }
        const row = document.getElementById('row_' + id);
        if (row && display) {
            const value = parseInt(display.textContent.trim().replace(/,/g, '')) || 0;
            row.style.display = value > 0 ? 'table-row' : 'none';
        }
    });
    const cancelBtn = document.getElementById('cancel_edit_btn');
    if (cancelBtn) {
        cancelBtn.classList.add('d-none');
    }
    const editBtn = document.getElementById('edit_salary_btn');
    if (editBtn) {
        editBtn.classList.remove('d-none');
        editBtn.focus();
    }
}

// 【JS-03】即時計算薪資
function updateSalary() {
    const getValue = function (id) {
        const element = document.getElementById(id);
        if (!element) {
            return 0;
        }
        const value = element.value;
        if (value === '' || value === undefined || value === null) {
            return 0;
        }
        const parsed = parseInt(value, 10);
        return isNaN(parsed) ? 0 : parsed;
    };
    const base_salary = getValue('base_salary');
    const meal_allowance = getValue('meal_allowance');
    const attendance_bonus = getValue('attendance_bonus');
    const position_bonus = getValue('position_bonus');
    const skill_bonus = getValue('skill_bonus');
    const labor_insurance = getValue('labor_insurance');
    const health_insurance = getValue('health_insurance');
    const leave_deduction = getValue('leave_deduction');
    const absent_deduction = getValue('absent_deduction');
    const overtime_pay = getValue('overtime_pay');
    const vacation_cash = getValue('vacation_cash');

    const vacationDisplay = document.getElementById('vacation_cash_display');
    if (vacationDisplay) {
        vacationDisplay.textContent = vacation_cash;
    }
    const overtimeDisplay = document.getElementById('overtime_pay_display');
    if (overtimeDisplay) {
        overtimeDisplay.textContent = overtime_pay;
    }

    const gross_salary = base_salary + meal_allowance + attendance_bonus + position_bonus + skill_bonus + vacation_cash + overtime_pay;
    document.getElementById('gross_salary_display').textContent = gross_salary;
    document.getElementById('gross_salary').value = gross_salary;

    const total_deductions = labor_insurance + health_insurance + leave_deduction + absent_deduction;
    document.getElementById('total_deductions_display').textContent = total_deductions;
    document.getElementById('total_deductions').value = total_deductions;

    const net_salary = gross_salary - total_deductions;
    document.getElementById('net_salary_display').textContent = net_salary;
    document.getElementById('net_salary').value = net_salary;
}

// 【JS-04】特休轉現金自動換算
$(document).on('change', '#vacation_cash_days', function () {
    const days = parseInt($(this).val(), 10) || 0;
    $('#vacation_cash_days_hidden').val(days);
    const baseSalary = parseInt($('#base_salary').length ? $('#base_salary').val() : $('#base_salary_display').text(), 10) || 0;
    const vacationCash = Math.ceil(baseSalary / 240 * days * 8);
    $('#vacation_cash').val(vacationCash);
    $('#vacation_cash_display').text(vacationCash);
    if (days > 0) {
        $('#row_vacation_cash').show();
    } else {
        $('#row_vacation_cash').hide();
    }
    updateSalary();
});

// 【JS-05】匯出圖片
function exportToImage() {
    const exportBtn = document.getElementById('export_image_btn');
    if (exportBtn) {
        exportBtn.disabled = true;
        exportBtn.textContent = '匯出中...';
    }
    const exportArea = document.createElement('div');
    exportArea.style.padding = '24px';
    exportArea.style.background = '#ffffff';
    exportArea.style.width = '100%';
    exportArea.style.maxWidth = '1200px';
    const title = document.createElement('h2');
    const employeeText = $('#employee_id option:selected').text().split(' - ')[1] || '未選擇員工';
    title.textContent = `麥創藝有限公司 ${$('#year').val()}年${$('#month').val()}月 ${employeeText} 薪資報表`;
    title.style.textAlign = 'center';
    title.style.marginBottom = '20px';
    exportArea.appendChild(title);

    document.querySelectorAll('.section-card').forEach(section => {
        if (section.offsetHeight > 0) {
            exportArea.appendChild(section.cloneNode(true));
        }
    });

    exportArea.style.position = 'fixed';
    exportArea.style.top = '-9999px';
    document.body.appendChild(exportArea);

    html2canvas(exportArea, { scale: 2, useCORS: true }).then(canvas => {
        const link = document.createElement('a');
        link.href = canvas.toDataURL('image/png');
        link.download = `${title.textContent}.png`;
        link.click();
        document.body.removeChild(exportArea);
        if (exportBtn) {
            exportBtn.disabled = false;
            exportBtn.textContent = '匯出圖片';
        }
    }).catch(error => {
        console.error('匯出失敗', error);
        alert('匯出圖片失敗，請稍後再試。');
        document.body.removeChild(exportArea);
        if (exportBtn) {
            exportBtn.disabled = false;
            exportBtn.textContent = '匯出圖片';
        }
    });
}
</script>
</body>
</html>
