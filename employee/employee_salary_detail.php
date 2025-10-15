<?php
// ✅ 登入檢查：只要有登入即可（員工端）
session_start();
if (!isset($_SESSION['user'])) {
    header('Location: ../login.php');
    exit;
}

// ✅ 資料庫連線
$conn = new mysqli('localhost', 'root', '', 'mymakeart');
if ($conn->connect_error) {
    die("資料庫連接失敗：" . $conn->connect_error);
}

// ✅ 當前員工（只能看自己）
$employee_id = (int)$_SESSION['user']['id'];

// ✅ 年月：從連結帶入；未帶入則預設上個月
$last_month = (int)date('n', strtotime('first day of last month'));
$last_year  = (int)date('Y', strtotime('first day of last month'));
$year  = isset($_GET['year'])  ? (int)$_GET['year']  : $last_year;
$month = isset($_GET['month']) ? (int)$_GET['month'] : $last_month;

// ✅ 取得員工基本資料
$employee_query = $conn->prepare("SELECT employee_number, hire_date, name FROM employees WHERE id = ?");
$employee_query->bind_param('i', $employee_id);
$employee_query->execute();
$employee_row = $employee_query->get_result()->fetch_assoc();
$employee_number = $employee_row['employee_number'] ?? null;
$hire_date = $employee_row['hire_date'] ?? null;
$employee_name = $employee_row['name'] ?? '';

// ✅ 取得員工薪資結構（預設基礎）
$salary_query = $conn->prepare("SELECT * FROM salary_structure WHERE employee_id = ?");
$salary_query->bind_param('i', $employee_id);
$salary_query->execute();
$salary_result = $salary_query->get_result()->fetch_assoc() ?: [];

// ✅ 取得該年月的薪資紀錄
$salary_record_query = $conn->prepare("
    SELECT * FROM employee_monthly_salary 
    WHERE employee_id = ? AND year = ? AND month = ?
");
$salary_record_query->bind_param("iii", $employee_id, $year, $month);
$salary_record_query->execute();
$salary_record = $salary_record_query->get_result()->fetch_assoc() ?: [];

// ✅ 初始化金額與時薪
$base_salary       = $salary_record['base_salary']       ?? ($salary_result['base_salary']       ?? 0);
$meal_allowance    = $salary_record['meal_allowance']    ?? ($salary_result['meal_allowance']    ?? 0);
$attendance_bonus  = $salary_record['attendance_bonus']  ?? ($salary_result['attendance_bonus']  ?? 0);
$position_bonus    = $salary_record['position_bonus']    ?? ($salary_result['position_bonus']    ?? 0);
$skill_bonus       = $salary_record['skill_bonus']       ?? ($salary_result['skill_bonus']       ?? 0);
$labor_insurance   = $salary_record['labor_insurance']   ?? ($salary_result['labor_insurance']   ?? 0);
$health_insurance  = $salary_record['health_insurance']  ?? ($salary_result['health_insurance']  ?? 0);
$leave_deduction   = isset($salary_record['leave_deduction']) ? (int)$salary_record['leave_deduction'] : 0;
$absent_deduction  = (int)($salary_record['absent_deduction'] ?? 0);
$vacation_cash2    = isset($salary_record['vacation_cash']) ? (int)$salary_record['vacation_cash'] : 0;
$overtime_pay      = isset($salary_record['overtime_pay'])   ? (int)$salary_record['overtime_pay']   : 0;

$hourly_rate = $base_salary > 0 ? ceil($base_salary / 240) : 0;
$total_absent_minutes = 0;
$overtime_details = [];

// ✅ 前端顯示用的結構表
$salary_data = array_filter([
    '底薪'     => $base_salary,
    '伙食費'   => $meal_allowance,
    '全勤獎金' => $attendance_bonus,
    '職務加給' => $position_bonus,
    '技術津貼' => $skill_bonus,
], fn($v) => $v > 0);

// ✅ 時間範圍（本頁使用 year/month 的整月）
$start_of_month = sprintf("%04d-%02d-01", $year, $month);
$end_of_month   = date("Y-m-t", strtotime($start_of_month));

// ✅ 撈出本月核准請假或加班（含跨月）
$request_query = $conn->prepare("
    SELECT type, subtype, reason, start_date, end_date, status 
    FROM requests 
    WHERE employee_id = ? 
      AND status = 'Approved' 
      AND (
          (start_date BETWEEN ? AND ?) OR 
          (end_date BETWEEN ? AND ?) OR 
          (start_date <= ? AND end_date >= ?)
      )
");
$request_query->bind_param(
    'issssss',
    $employee_id,
    $start_of_month, $end_of_month,
    $start_of_month, $end_of_month,
    $start_of_month, $end_of_month
);
$request_query->execute();
$approved_requests = $request_query->get_result()->fetch_all(MYSQLI_ASSOC);

// ✅ 缺席總分鐘（本月）
$absent_query = $conn->prepare("
    SELECT date, status_text, absent_minutes 
    FROM saved_attendance 
    WHERE employee_number = ? AND YEAR(date) = ? AND MONTH(date) = ? AND absent_minutes > 0
");
$absent_query->bind_param('sii', $employee_number, $year, $month);
$absent_query->execute();
$absent_result = $absent_query->get_result()->fetch_all(MYSQLI_ASSOC);
foreach ($absent_result as $absent) {
    $total_absent_minutes += (int)$absent['absent_minutes'];
}

// ✅ 已核准請假 + 薪資扣除比例
$leave_query = $conn->prepare("
    SELECT r.subtype, r.start_date, r.end_date, l.salary_ratio 
    FROM requests r 
    JOIN leave_types l ON r.subtype = l.name 
    WHERE r.employee_id = ? 
      AND r.status = 'Approved' 
      AND (
        (r.start_date BETWEEN ? AND ?) OR
        (r.end_date BETWEEN ? AND ?) OR
        (r.start_date <= ? AND r.end_date >= ?)
    )
");
$leave_query->bind_param('issssss', 
    $employee_id, 
    $start_of_month, $end_of_month,
    $start_of_month, $end_of_month,
    $start_of_month, $end_of_month
);
$leave_query->execute();
$leave_results = $leave_query->get_result()->fetch_all(MYSQLI_ASSOC);

// ✅ 班別資訊（請假工時計算）
$shift_q = $conn->prepare("
    SELECT s.start_time, s.end_time, s.break_start, s.break_end 
    FROM shifts s 
    JOIN employees e ON s.id = e.shift_id 
    WHERE e.id = ?
");
$shift_q->bind_param('i', $employee_id);
$shift_q->execute();
$shift = $shift_q->get_result()->fetch_assoc() ?: [
    'start_time' => '09:00:00',
    'end_time'   => '18:00:00',
    'break_start'=> '12:00:00',
    'break_end'  => '13:00:00'
];

// ✅ 計算請假扣薪（並組明細，若薪資表已有固定金額就直接顯示金額不重算）
$leave_deduction_details = [];
if (empty($salary_record['leave_deduction'])) {
    $tmp = [];
    foreach ($leave_results as $leave) {
        $hours = calculateLeaveHoursByShift($leave['start_date'], $leave['end_date'], $shift);
        $salary_ratio   = (float)($leave['salary_ratio'] ?? 0);   // 例如 50 表示給 50%
        $deduct_percent = max(0, 100 - $salary_ratio);
        $amount         = (int)ceil($hourly_rate * $hours * ($deduct_percent / 100));

        $tmp[] = $amount;
        $leave_deduction_details[] = [
            'date'           => date('Y-m-d', strtotime($leave['start_date'])),
            'type'           => $leave['subtype'] ?? '其他假別',
            'hours'          => round($hours, 1),
            'hourly_rate'    => (int)$hourly_rate,
            'deduct_percent' => $deduct_percent,
            'amount'         => $amount,
        ];
    }
    $leave_deduction = array_sum($tmp);
} else {
    $leave_deduction_details = []; // 已有金額就不顯示自動明細
}

// ✅ 各假別年額與使用（統計用）
$leave_types_result = $conn->query("SELECT name, days_per_year FROM leave_types");
$leave_count = [];
while ($lt = $leave_types_result->fetch_assoc()) {
    $leave_count[$lt['name']] = [
        'total_days' => (float)$lt['days_per_year'],
        'used_days'  => 0,
        'used_hours' => 0,
    ];
}

$used_query = $conn->prepare("
    SELECT subtype, start_date, end_date 
    FROM requests 
    WHERE employee_id = ? AND status = 'Approved'
");
$used_query->bind_param('i', $employee_id);
$used_query->execute();
$used_rows = $used_query->get_result()->fetch_all(MYSQLI_ASSOC);

foreach ($used_rows as $row) {
    $hours = calculateLeaveHoursByShift($row['start_date'], $row['end_date'], $shift);
    $days = floor($hours / 8);
    $remain_hours = fmod($hours, 8);
    $subtype = $row['subtype'];
    if (isset($leave_count[$subtype])) {
        $leave_count[$subtype]['used_days']  += $days;
        $leave_count[$subtype]['used_hours'] += $remain_hours;
    }
}
$used_leaves = array_filter($leave_count, fn($x) => $x['used_days'] > 0 || $x['used_hours'] > 0);

// ✅ 特休變動紀錄（最近 10 筆）
$history_stmt = $conn->prepare("
    SELECT year, month, days AS vacation_days, status, created_at 
    FROM annual_leave_records 
    WHERE employee_id = ? 
    ORDER BY created_at DESC 
    LIMIT 10
");
$history_stmt->bind_param('i', $employee_id);
$history_stmt->execute();
$history_result = $history_stmt->get_result()->fetch_all(MYSQLI_ASSOC);
$has_vacation_history = !empty($history_result);


// ✅ 特休總覽（彙總）
$summary_stmt = $conn->prepare("
    SELECT 
        SUM(CASE WHEN status = '取得' THEN days ELSE 0 END) AS total_earned_vacation,
        SUM(CASE WHEN status = '使用' THEN days ELSE 0 END) AS total_used_vacation,
        SUM(CASE WHEN status = '轉現金' THEN days ELSE 0 END) AS total_converted_vacation,
        SUM(CASE WHEN status = '取得' THEN days ELSE 0 END) - 
        SUM(CASE WHEN status = '使用' THEN days ELSE 0 END) - 
        SUM(CASE WHEN status = '轉現金' THEN days ELSE 0 END) AS remaining_vacation
    FROM annual_leave_records 
    WHERE employee_id = ?
");
$summary_stmt->bind_param('i', $employee_id);
$summary_stmt->execute();
$summary_result = $summary_stmt->get_result()->fetch_assoc() ?: [];
$has_vacation_summary = ($summary_result['total_earned_vacation'] ?? 0) > 0 
    || ($summary_result['total_used_vacation'] ?? 0) > 0 
    || ($summary_result['total_converted_vacation'] ?? 0) > 0;

// ✅ 加班費明細（若薪資表已有金額就不重算）
if (empty($salary_record['overtime_pay'])) {
    foreach ($approved_requests as $request) {
        if ($request['type'] !== '加班') continue;

        $start_ts = strtotime($request['start_date']);
        $end_ts   = strtotime($request['end_date']);
        if ($end_ts <= $start_ts) continue;

        $duration_hours = round(($end_ts - $start_ts) / 3600, 2);
        $start_date = date('Y-m-d', $start_ts);

        $is_holiday = isHoliday($start_date, $conn);
        $segments = [];
        $remaining = $duration_hours;

        if (!$is_holiday) {
            // 平日：前 2 小時 1.34 倍，其餘 1.67 倍
            if ($remaining > 2) {
                $segments[] = ['hours' => 2, 'rate' => 1.34];
                $segments[] = ['hours' => $remaining - 2, 'rate' => 1.67];
            } else {
                $segments[] = ['hours' => $remaining, 'rate' => 1.34];
            }
        } else {
            // 假日：2h 1.34、6h 1.67、其餘 2.67
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
        }

        $total_segment_start = 0;
        foreach ($segments as $seg) {
            $seg_hours = (float)$seg['hours'];
            $seg_rate  = (float)$seg['rate'];
            $pay = ceil($hourly_rate * $seg_hours * $seg_rate);
            $overtime_pay += $pay;

            $start_label = $total_segment_start + 1;
            $end_label   = $total_segment_start + $seg_hours;
            $range_label = ($seg_hours == 1) ? "{$start_label} 小時" : "{$start_label} - {$end_label} 小時";

            $overtime_details[] = [
                'start' => $request['start_date'],
                'end'   => $request['end_date'],
                'hours' => $seg_hours,
                'rate'  => $seg_rate,
                'pay'   => $pay,
                'range_label' => $range_label
            ];
            $total_segment_start += $seg_hours;
        }
    }
}

// ✅ 總工資 / 總扣除 / 實領
$gross_salary     = $base_salary + $meal_allowance + $attendance_bonus + $position_bonus + $skill_bonus + $vacation_cash2 + $overtime_pay;
$total_deductions = $labor_insurance + $health_insurance + $leave_deduction + $absent_deduction;
$net_salary       = $gross_salary - $total_deductions;

/* ---------------- 共用函式 ---------------- */
function calculateLeaveHoursByShift($start, $end, $shift) {
    $start_ts = strtotime($start);
    $end_ts   = strtotime($end);
    if (!$start_ts || !$end_ts || $end_ts <= $start_ts) return 0;

    $total = 0; $current = $start_ts;
    while ($current <= $end_ts) {
        $current_date = date('Y-m-d', $current);
        $shift_start  = strtotime("$current_date {$shift['start_time']}");
        $shift_end    = strtotime("$current_date {$shift['end_time']}");
        $break_start  = strtotime("$current_date {$shift['break_start']}");
        $break_end    = strtotime("$current_date {$shift['break_end']}");

        $actual_start = max($current, $shift_start);
        $actual_end   = min($end_ts,   $shift_end);

        if ($actual_end <= $actual_start) {
            $current = strtotime('+1 day', strtotime($current_date));
            continue;
        }

        $duration = $actual_end - $actual_start;
        $break_overlap = max(0, min($actual_end, $break_end) - max($actual_start, $break_start));
        $work_seconds = max(0, $duration - $break_overlap);
        $total += $work_seconds;

        $current = strtotime('+1 day', strtotime($current_date));
    }
    return round($total / 3600, 1);
}

function isHoliday($date, $conn) {
    $dow = date('w', strtotime($date)); // 0=Sun,6=Sat
    $stmt = $conn->prepare("SELECT is_working_day FROM holidays WHERE holiday_date = ?");
    $stmt->bind_param("s", $date);
    $stmt->execute();
    $res = $stmt->get_result()->fetch_assoc();
    $stmt->close();

    if ($res !== null) {
        return (int)$res['is_working_day'] === 0; // 0=假日,1=補班(工作日)
    }
    return ($dow == 0 || $dow == 6); // 預設六日為假日
}

function fmt_money($amount, $is_total = false) {
    $n = number_format((float)$amount);
    return $is_total ? $n : ('　' . $n);
}
?>
<!DOCTYPE html>
<html lang="zh-TW">
<head>
    <meta charset="UTF-8">
    <title>我的薪資明細</title>
    <meta name="viewport" content="width=device-width, initial-scale=1">
    <!-- Bootstrap -->
    <link href="https://cdn.jsdelivr.net/npm/bootstrap@5.3.3/dist/css/bootstrap.min.css" rel="stylesheet">
    <!-- 員工導覽列樣式 -->
    <link rel="stylesheet" href="employee_navbar.css">
    <style>
        .bold-row { font-weight: bold; }
        .deduction-amount { color: #c0392b !important; font-weight: bold; }
        .double-underline { border-bottom: double 3px black; }
        .status-green { color: #16a34a; font-weight: 600; }
        .status-red { color: #b91c1c; font-weight: 600; }
    </style>
</head>
<body class="bg-light">
    <!-- ✅ 員工導覽列 -->
    <?php include 'employee_navbar.php'; ?>

    <div class="container mt-4">
        <h1 class="mb-3">我的薪資明細</h1>
        <p class="text-muted">期間：<?= htmlspecialchars($year) ?> 年 <?= htmlspecialchars($month) ?> 月　｜　員工：<?= htmlspecialchars($employee_name) ?></p>

        <!-- ✅ 薪資結構 -->
        <?php if (!empty($salary_data)): ?>
        <h2 class="mt-4">薪資結構</h2>
        <table class="table table-bordered">
            <?php foreach ($salary_data as $key => $value): ?>
                <tr>
                    <th class="table-primary" style="width: 20%;"><?= htmlspecialchars($key) ?></th>
                    <td><?= number_format((float)$value) ?></td>
                </tr>
            <?php endforeach; ?>
        </table>
        <?php endif; ?>

        <!-- ✅ 當月核准申請 -->
        <?php if (!empty($approved_requests)): ?>
        <h2 class="mt-4">當月核准的申請</h2>
        <table class="table table-bordered">
            <thead class="table-primary">
                <tr>
                    <th>類型</th>
                    <th>假別</th>
                    <th>理由</th>
                    <th>起始</th>
                    <th>結束</th>
                    <th>狀態</th>
                </tr>
            </thead>
            <tbody>
            <?php foreach ($approved_requests as $request): ?>
                <tr>
                    <td><?= htmlspecialchars($request['type']) ?></td>
                    <td><?= htmlspecialchars($request['subtype']) ?></td>
                    <td><?= htmlspecialchars($request['reason'] ?? '-') ?></td>
                    <td><?= htmlspecialchars($request['start_date']) ?></td>
                    <td><?= htmlspecialchars($request['end_date']) ?></td>
                    <td><?= $request['status'] === 'Approved' ? '<span class="status-green">已核准</span>' : '<span class="status-red">未核准</span>' ?></td>
                </tr>
            <?php endforeach; ?>
            </tbody>
        </table>
        <?php endif; ?>

        <!-- ✅ 各休假次數累計表（只顯示有使用者） -->
        <?php if (!empty($used_leaves)): ?>
        <h2 class="mt-4">各休假次數累計表</h2>
        <table class="table table-bordered">
            <thead class="table-primary">
                <tr>
                    <th>假別</th>
                    <th>年度可休天數</th>
                    <th>已使用天數</th>
                    <th>已使用小時</th>
                    <th>剩餘天數</th>
                    <th>剩餘小時</th>
                </tr>
            </thead>
            <tbody>
            <?php foreach ($leave_count as $leave_type => $count): 
                $used_days  = (float)$count['used_days'];
                $used_hours = (float)$count['used_hours'];
                if ($used_days == 0 && $used_hours == 0) continue;

                $total_hours            = $count['total_days'] * 8;
                $used_total_hours       = $used_days * 8 + $used_hours;
                $remaining_total_hours  = max(0, $total_hours - $used_total_hours);
                $remain_days  = floor($remaining_total_hours / 8);
                $remain_hours = fmod($remaining_total_hours, 8);
            ?>
                <tr>
                    <td><?= htmlspecialchars($leave_type) ?></td>
                    <td><?= fmt_money($count['total_days'], 1) ?></td>
                    <td><?= fmt_money($used_days, 1) ?></td>
                    <td><?= fmt_money($used_hours, 1) ?></td>
                    <td><?= fmt_money($remain_days, 1) ?></td>
                    <td><?= fmt_money($remain_hours, 1) ?></td>
                </tr>
            <?php endforeach; ?>
            </tbody>
        </table>
        <?php endif; ?>

        <!-- ✅ 特休變動紀錄 -->
        <?php if ($has_vacation_history): ?>
        <h2 class="mt-4">特休變動紀錄</h2>
        <table class="table table-bordered">
            <thead class="table-primary">
                <tr>
                    <th>年</th>
                    <th>月</th>
                    <th>天數</th>
                    <th>狀態</th>
                    <th>建立日期</th>
                </tr>
            </thead>
            <tbody>
            <?php foreach ($history_result as $row): ?>
                <tr>
                    <td><?= htmlspecialchars($row['year']) ?></td>
                    <td><?= htmlspecialchars($row['month']) ?></td>
                    <td><?= htmlspecialchars($row['vacation_days'] ?? '0') ?> 天</td>
                    <td><?= htmlspecialchars($row['status']) ?></td>
                    <td><?= htmlspecialchars($row['created_at']) ?></td>
                </tr>
            <?php endforeach; ?>
            </tbody>
        </table>
        <?php endif; ?>

        <!-- ✅ 特休總覽（彙總顯示） -->
        <?php if ($has_vacation_summary): ?>
        <h2 class="mt-4">特休總覽</h2>
        <table class="table table-bordered">
            <thead class="table-primary">
                <tr>
                    <th>取得特休天數</th>
                    <th>已使用特休天數</th>
                    <th>已使用特休小時</th>
                    <th>已轉現金特休天數</th>
                    <th>已轉現金特休小時</th>
                    <th>剩餘特休天數</th>
                    <th>剩餘特休小時</th>
                </tr>
            </thead>
            <tbody>
                <?php
                $vacation_summary_stmt = $conn->prepare("
                    SELECT 
                        SUM(CASE WHEN status = '取得' THEN days  ELSE 0 END) AS total_acquired_days,
                        SUM(CASE WHEN status = '取得' THEN hours ELSE 0 END) AS total_acquired_hours,
                        SUM(CASE WHEN status = '使用' THEN days  ELSE 0 END) AS total_used_days,
                        SUM(CASE WHEN status = '使用' THEN hours ELSE 0 END) AS total_used_hours,
                        SUM(CASE WHEN status = '轉現金' THEN days ELSE 0 END) AS total_cash_days,
                        SUM(CASE WHEN status = '轉現金' THEN hours ELSE 0 END) AS total_cash_hours
                    FROM annual_leave_records
                    WHERE employee_id = ?
                ");
                $vacation_summary_stmt->bind_param('i', $employee_id);
                $vacation_summary_stmt->execute();
                $vacation_summary = $vacation_summary_stmt->get_result()->fetch_assoc() ?: [];

                $total_acquired_days  = (float)($vacation_summary['total_acquired_days']  ?? 0);
                $total_acquired_hours = (float)($vacation_summary['total_acquired_hours'] ?? 0);
                $total_used_days      = (float)($vacation_summary['total_used_days']      ?? 0);
                $total_used_hours     = (float)($vacation_summary['total_used_hours']     ?? 0);
                $total_cash_days      = (float)($vacation_summary['total_cash_days']      ?? 0);
                $total_cash_hours     = (float)($vacation_summary['total_cash_hours']     ?? 0);

                $total_remaining_hours = 
                    ($total_acquired_days * 8 + $total_acquired_hours)
                    - ($total_used_days * 8 + $total_used_hours)
                    - ($total_cash_days * 8 + $total_cash_hours);

                $remaining_days  = floor($total_remaining_hours / 8);
                $remaining_hours = fmod($total_remaining_hours, 8);
                ?>
                <tr>
                    <td><?= fmt_money($total_acquired_days, 1) ?> 天</td>
                    <td><?= fmt_money($total_used_days, 1) ?> 天</td>
                    <td><?= fmt_money($total_used_hours, 1) ?> 小時</td>
                    <td><?= fmt_money($total_cash_days, 1) ?> 天</td>
                    <td><?= fmt_money($total_cash_hours, 1) ?> 小時</td>
                    <td><?= fmt_money($remaining_days, 1) ?> 天</td>
                    <td><?= fmt_money($remaining_hours, 1) ?> 小時</td>
                </tr>
            </tbody>
        </table>
        <?php endif; ?>

        <!-- ✅ 缺席 -->
        <?php if (!empty($absent_result)): ?>
        <h2 class="mt-4">本月缺席時數表</h2>
        <table class="table table-bordered">
            <thead class="table-primary">
                <tr><th>日期</th><th>狀態</th><th>缺席時數（分鐘）</th></tr>
            </thead>
            <tbody>
            <?php foreach ($absent_result as $absent): ?>
                <tr>
                    <td><?= htmlspecialchars($absent['date']) ?></td>
                    <td><?= htmlspecialchars($absent['status_text']) ?></td>
                    <td><?= htmlspecialchars($absent['absent_minutes']) ?></td>
                </tr>
            <?php endforeach; ?>
            </tbody>
        </table>

        <h2 class="mt-3">總缺席時數</h2>
        <p>總計缺席：<?= (int)$total_absent_minutes ?> 分鐘（約 <?= round($total_absent_minutes / 60, 1) ?> 小時）</p>
        <?php endif; ?>

        <!-- ✅ 本月應領薪資 -->
        <h2 class="mt-4"><?= htmlspecialchars($employee_name) ?> 本月應領薪資</h2>
        <table class="table table-bordered">
            <thead class="table-primary">
                <tr>
                    <th style="width: 15%;">項目</th>
                    <th style="width: 20%;">金額</th>
                    <th>計算方式</th>
                </tr>
            </thead>
            <tbody>
                <?php if ($base_salary > 0): ?>
                <tr>
                    <td class="table-primary">底薪</td>
                    <td><?= fmt_money($base_salary) ?></td>
                    <td><?= nl2br(htmlspecialchars($salary_record['base_salary_note'] ?? '')) ?></td>
                </tr>
                <?php endif; ?>

                <?php if ($meal_allowance > 0): ?>
                <tr>
                    <td class="table-primary">餐費</td>
                    <td><?= fmt_money($meal_allowance) ?></td>
                    <td><?= nl2br(htmlspecialchars($salary_record['meal_allowance_note'] ?? '')) ?></td>
                </tr>
                <?php endif; ?>

                <?php if ($attendance_bonus > 0): ?>
                <tr>
                    <td class="table-primary">全勤獎金</td>
                    <td><?= fmt_money($attendance_bonus) ?></td>
                    <td><?= nl2br(htmlspecialchars($salary_record['attendance_bonus_note'] ?? '')) ?></td>
                </tr>
                <?php endif; ?>

                <?php if ($position_bonus > 0): ?>
                <tr>
                    <td class="table-primary">職務加給</td>
                    <td><?= fmt_money($position_bonus) ?></td>
                    <td><?= nl2br(htmlspecialchars($salary_record['position_bonus_note'] ?? '')) ?></td>
                </tr>
                <?php endif; ?>

                <?php if ($skill_bonus > 0): ?>
                <tr>
                    <td class="table-primary">技術津貼</td>
                    <td><?= fmt_money($skill_bonus) ?></td>
                    <td><?= nl2br(htmlspecialchars($salary_record['skill_bonus_note'] ?? '')) ?></td>
                </tr>
                <?php endif; ?>

                <?php if ($overtime_pay > 0): ?>
                <tr>
                    <td class="table-primary">加班費</td>
                    <td><?= fmt_money($overtime_pay) ?></td>
                    <td>
                        <?php if (!empty($salary_record['overtime_note'])): ?>
                            <?= nl2br(htmlspecialchars($salary_record['overtime_note'])) ?>
                        <?php else: ?>
                            <?php 
                            $total_overtime_hours = 0;
                            foreach ($overtime_details as $ot) {
                                echo "{$ot['start']} ~ {$ot['end']}：{$ot['range_label']} × 時薪 {$hourly_rate} × {$ot['rate']} 倍 = " . fmt_money($ot['pay']) . " 元<br>";
                                $total_overtime_hours += $ot['hours'];
                            }
                            echo "<strong>共計 " . fmt_money($total_overtime_hours, 1) . " 小時</strong>";
                            ?>
                        <?php endif; ?>
                    </td>
                </tr>
                <?php endif; ?>

                <?php if ($vacation_cash2 > 0): ?>
                <tr>
                    <td class="table-primary">特休轉現金</td>
                    <td><?= fmt_money($vacation_cash2) ?></td>
                    <td><?= nl2br(htmlspecialchars($salary_record['vacation_cash_note'] ?? '底薪 / 240 × 8 小時 × 天數')) ?></td>
                </tr>
                <?php endif; ?>
				 <tr class="bold-row">
                    <td class="table-primary">總工資</td>
                    <td><?= number_format($gross_salary) ?></td>
                    <td></td>
                </tr>
                <?php if ($labor_insurance > 0): ?>
                <tr>
                    <td class="table-primary">勞保費</td>
                    <td class="deduction-amount"><?= fmt_money($labor_insurance) ?></td>
                    <td><?= nl2br(htmlspecialchars($salary_record['labor_insurance_note'] ?? '依照級距表')) ?></td>
                </tr>
                <?php endif; ?>

                <?php if ($health_insurance > 0): ?>
                <tr>
                    <td class="table-primary">健保費</td>
                    <td class="deduction-amount"><?= fmt_money($health_insurance) ?></td>
                    <td><?= nl2br(htmlspecialchars($salary_record['health_insurance_note'] ?? '依照級距表')) ?></td>
                </tr>
                <?php endif; ?>

                <?php if ($leave_deduction > 0): ?>
                <tr>
                    <td class="table-primary">請假扣除</td>
                    <td class="deduction-amount"><?= fmt_money($leave_deduction) ?></td>
                    <td>
                        <?php if (!empty($salary_record['leave_deduction_note'])): ?>
                            <?= nl2br(htmlspecialchars($salary_record['leave_deduction_note'])) ?>
                        <?php else: ?>
                            <?php foreach ($leave_deduction_details as $item): ?>
                                [<?= htmlspecialchars($item['date']) ?>] <?= htmlspecialchars($item['type']) ?>：
                                <?= fmt_money($item['hours'], 1) ?> 小時 × 時薪 <?= fmt_money($item['hourly_rate']) ?> × 扣除 <?= fmt_money($item['deduct_percent']) ?>% = <?= fmt_money($item['amount']) ?> 元<br>
                            <?php endforeach; ?>
                        <?php endif; ?>
                    </td>
                </tr>
                <?php endif; ?>

                <?php if ($absent_deduction > 0): ?>
                <tr>
                    <td class="table-primary">缺席扣除</td>
                    <td class="deduction-amount"><?= fmt_money($absent_deduction) ?></td>
                    <td>
                        <?php if (!empty($salary_record['absent_deduction_note'])): ?>
                            <?= nl2br(htmlspecialchars($salary_record['absent_deduction_note'])) ?>
                        <?php else: ?>
                            缺席時數：<?= (int)$total_absent_minutes ?> 分鐘（約 <?= round($total_absent_minutes / 60, 1) ?> 小時） × 換算時薪：<?= fmt_money($hourly_rate) ?> 元
                        <?php endif; ?>
                    </td>
                </tr>
                <?php endif; ?>

               

                <tr class="bold-row">
                    <td class="table-primary">總扣除</td>
                    <td class="deduction-amount"><?= number_format($total_deductions) ?></td>
                    <td></td>
                </tr>

                <tr class="bold-row">
                    <td class="table-primary">實領薪資</td>
                    <td class="double-underline"><?= number_format($net_salary) ?></td>
                    <td>總工資 - 總扣除</td>
                </tr>
            </tbody>
        </table>

        <!-- ✅ 匯出圖片 -->
        <div class="my-4">
            <button class="btn btn-secondary" type="button" id="export_button" onclick="exportToImage()">匯出圖片</button>
        </div>
    </div>

    <!-- JS -->
    <script src="https://cdn.jsdelivr.net/npm/bootstrap@5.3.3/dist/js/bootstrap.bundle.min.js"></script>
    <script src="https://cdnjs.cloudflare.com/ajax/libs/html2canvas/1.4.1/html2canvas.min.js"></script>
    <script>
    function exportToImage() {
        const exportSection = document.createElement("div");
        exportSection.style.padding = "20px";
        exportSection.style.background = "#ffffff";
        exportSection.style.width = "100%";
        exportSection.style.maxWidth = "1200px";
        exportSection.style.margin = "0 auto";

        // 只擷取本頁主要內容的 h2、table
        const blocks = document.querySelectorAll("h2, table");
        blocks.forEach(el => {
            if (el.offsetHeight > 0) {
                exportSection.appendChild(el.cloneNode(true));
            }
        });

        // 標題
        const title = document.createElement("h2");
        const companyName = "麥創藝有限公司";
        const year  = <?= json_encode($year) ?>;
        const month = <?= json_encode($month) ?>;
        const employeeName = <?= json_encode($employee_name) ?>;
        const titleText = `${companyName} ${year}年${month}月 ${employeeName} 薪資報表`;
        title.textContent = titleText;
        title.style.textAlign = "center";
        title.style.marginBottom = "20px";
        exportSection.insertBefore(title, exportSection.firstChild);

        // 插入到頁面上但不顯示
        exportSection.style.position = "absolute";
        exportSection.style.left = "-9999px";
        document.body.appendChild(exportSection);

        html2canvas(exportSection, {
            scale: 2,
            useCORS: true,
            width: exportSection.offsetWidth
        }).then(canvas => {
            const link = document.createElement("a");
            link.href = canvas.toDataURL("image/png");
            link.download = `${titleText}.png`;
            link.click();
            document.body.removeChild(exportSection);
        }).catch(error => {
            console.error("匯出圖片失敗：", error);
            alert("匯出圖片失敗，請稍後再試！");
        });
    }
    </script>
</body>
</html>
