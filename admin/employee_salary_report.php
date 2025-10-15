<?php
session_start();

// ✅ 第 1 點：登入檢查
if (!isset($_SESSION['user']) || $_SESSION['user']['role'] !== 'admin') {
    header('Location: ../login.php');
    exit;
}

// ✅ 第 2 點：資料庫連線
$conn = new mysqli('localhost', 'root', '', 'mymakeart');
if ($conn->connect_error) {
    die("資料庫連接失敗：" . $conn->connect_error);
}

// ✅ 第 3 點：初始化查詢年月與員工 ID
$last_month = date('m', strtotime('first day of last month'));
$last_year = date('Y', strtotime('first day of last month'));
$year = $_GET['year'] ?? $last_year;
$month = $_GET['month'] ?? $last_month;
$employee_id = $_GET['employee_id'] ?? '';

// ✅ 第 4 點：撈出員工清單
$employee_result = $conn->query("SELECT * FROM `employees` ORDER BY `id` ASC");
$employee_list = $employee_result->fetch_all(MYSQLI_ASSOC);

// ✅ 第 5 點：查詢員工基本資料
$employee_query = $conn->prepare("SELECT employee_number, hire_date, name FROM employees WHERE id = ?");
$employee_query->bind_param('s', $employee_id);
$employee_query->execute();
$employee_result = $employee_query->get_result()->fetch_assoc();
$employee_number = $employee_result['employee_number'] ?? null;
$hire_date = $employee_result['hire_date'] ?? null;
$employee_name = $employee_result['name'] ?? '';

// ✅ 第 6 點：查詢員工薪資結構
$salary_query = $conn->prepare("SELECT * FROM salary_structure WHERE employee_id = ?");
$salary_query->bind_param('s', $employee_id);
$salary_query->execute();
$salary_result = $salary_query->get_result()->fetch_assoc();

// ✅ 第 7 點：查詢當月薪資紀錄
$salary_record_query = $conn->prepare("SELECT * FROM employee_monthly_salary WHERE employee_id = ? AND year = ? AND month = ?");
$salary_record_query->bind_param("iii", $employee_id, $year, $month);
$salary_record_query->execute();
$salary_record = $salary_record_query->get_result()->fetch_assoc();

// ✅ 第 8 點：初始化薪資欄位與時薪
$base_salary = $salary_record['base_salary'] ?? ($salary_result['base_salary'] ?? 0);
$meal_allowance = $salary_record['meal_allowance'] ?? ($salary_result['meal_allowance'] ?? 0);
$attendance_bonus = $salary_record['attendance_bonus'] ?? ($salary_result['attendance_bonus'] ?? 0);
$position_bonus = $salary_record['position_bonus'] ?? ($salary_result['position_bonus'] ?? 0);
$skill_bonus = $salary_record['skill_bonus'] ?? ($salary_result['skill_bonus'] ?? 0);
$labor_insurance = $salary_record['labor_insurance'] ?? ($salary_result['labor_insurance'] ?? 0);
$health_insurance = $salary_record['health_insurance'] ?? ($salary_result['health_insurance'] ?? 0);
$leave_deduction = isset($salary_record['leave_deduction']) ? (int)$salary_record['leave_deduction'] : 0;
$absent_deduction = $salary_record['absent_deduction'] ?? 0;
$vacation_cash2 = 0; // 預設值
if (isset($salary_record['vacation_cash'])) {
    $vacation_cash2 = (int)$salary_record['vacation_cash'];
}
$overtime_pay = 0; // 預設為 0
if (isset($salary_record['overtime_pay'])) {
    $overtime_pay = (int)$salary_record['overtime_pay'];
}
$vacation_cash_days2 = $salary_record['vacation_cash_days'] ?? 0;
$hourly_rate = ceil($base_salary / 240);
$total_absent_minutes = 0;
$overtime_details = []; // 用來記錄每筆加班明細


// ✅ 第 8.5 點：初始化薪資資料供前端使用
$salary_data = [
    '底薪' => $base_salary,
    '伙食費' => $meal_allowance,
    '全勤獎金' => $attendance_bonus,
    '職務加給' => $position_bonus,
    '技術津貼' => $skill_bonus,
];
$salary_data = array_filter($salary_data, fn($v) => $v > 0);

// ✅ 第 9 點：撈出本月核准請假或加班（含跨月）
$start_of_month = "$year-$month-01";
$end_of_month = date("Y-m-t", strtotime($start_of_month));

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
    'sssssss',
    $employee_id,
    $start_of_month, $end_of_month,
    $start_of_month, $end_of_month,
    $start_of_month, $end_of_month
);
$request_query->execute();
$approved_requests = $request_query->get_result()->fetch_all(MYSQLI_ASSOC);

// ✅ 第 10 點：計算缺席總分鐘
$absent_query = $conn->prepare("SELECT date, status_text, absent_minutes FROM saved_attendance WHERE employee_number = ? AND YEAR(date) = ? AND MONTH(date) = ? AND absent_minutes > 0");
$absent_query->bind_param('sss', $employee_number, $year, $month);
$absent_query->execute();
$absent_result = $absent_query->get_result()->fetch_all(MYSQLI_ASSOC);
foreach ($absent_result as $absent) {
    $total_absent_minutes += (int)$absent['absent_minutes'];
}

// ✅ 第 11 點：撈已核准請假 + 薪資扣除比例
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

// ✅ 第 12 點：撈班別資訊（for 請假計算使用）
$shift_q = $conn->prepare("
    SELECT start_time, end_time, break_start, break_end 
    FROM shifts s 
    JOIN employees e ON s.id = e.shift_id 
    WHERE e.id = ?
");
$shift_q->bind_param('i', $employee_id);
$shift_q->execute();
$shift = $shift_q->get_result()->fetch_assoc();


// ✅ 第 13 點：依照班別計算請假扣薪時數與金額（同時建立明細）
$leave_deduction_details = [];   // ⬅️ 新增：初始化給前端 foreach 用
if (empty($salary_record['leave_deduction'])) {
    $leave_deductions = [];

    foreach ($leave_results as $leave) {
        // 計算請假時數（扣除休息）
        $hours = calculateLeaveHoursByShift($leave['start_date'], $leave['end_date'], $shift);

        // leave_types.salary_ratio：給薪比例（例如 50 = 給 50% 薪）
        $salary_ratio   = (float)($leave['salary_ratio'] ?? 0);
        $deduct_percent = max(0, 100 - $salary_ratio); // 要扣的百分比
        $amount         = (int)ceil($hourly_rate * $hours * ($deduct_percent / 100));

        // 小計累加
        $leave_deductions[] = $amount;

        // 明細給前端顯示
        $leave_deduction_details[] = [
            'date'          => date('Y-m-d', strtotime($leave['start_date'])),
            'type'          => $leave['subtype'] ?? '其他假別',
            'hours'         => round($hours, 1),
            'hourly_rate'   => (int)$hourly_rate,
            'deduct_percent'=> $deduct_percent,
            'amount'        => $amount,
        ];
    }

    $leave_deduction = array_sum($leave_deductions);
} else {
    // 有既存金額時至少給空陣列，避免前端 foreach 當掉
    $leave_deduction_details = [];
}

// ✅ 第 14 點：統計所有假別的可休天數（年額）與已使用（天+小時）
$leave_types_result = $conn->query("SELECT name, days_per_year FROM leave_types");
$leave_count = [];
while ($lt = $leave_types_result->fetch_assoc()) {
    $leave_count[$lt['name']] = [
        'total_days' => (float)$lt['days_per_year'],
        'used_days' => 0,
        'used_hours' => 0,
    ];
}

// ✅ 第 15 點：撈出所有已核准請假記錄，並用班別計算實際請假時數
if (empty($salary_record['leave_deduction'])) {
	$used_query = $conn->prepare("SELECT subtype, start_date, end_date FROM requests WHERE employee_id = ? AND status = 'Approved'");
	$used_query->bind_param('i', $employee_id);
	$used_query->execute();
	$used_rows = $used_query->get_result()->fetch_all(MYSQLI_ASSOC);

	foreach ($used_rows as $row) {
		$hours = calculateLeaveHoursByShift($row['start_date'], $row['end_date'], $shift);
		$days = floor($hours / 8);
		$remain_hours = fmod($hours, 8);
		$subtype = $row['subtype'];
		if (isset($leave_count[$subtype])) {
			$leave_count[$subtype]['used_days'] += $days;
			$leave_count[$subtype]['used_hours'] += $remain_hours;
		}
	}
}
// ✅ 第 16 點：整理出「有使用」的假別（供前端表格過濾）
$used_leaves = array_filter($leave_count, fn($x) => $x['used_days'] > 0 || $x['used_hours'] > 0);


// ✅ 第 17 點：撈出「特休變動紀錄」for 顯示歷史表格
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

// ✅ 第 18 點：撈出「特休總覽」彙總（取得/使用/轉現金）
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
$summary_result = $summary_stmt->get_result()->fetch_assoc();
$has_vacation_summary = ($summary_result['total_earned_vacation'] ?? 0) > 0 || ($summary_result['total_used_vacation'] ?? 0) > 0 || ($summary_result['total_converted_vacation'] ?? 0) > 0;
$remaining_vacation = $summary_result['remaining_vacation'] ?? 0;

// ✅ 第 19 點：確認本月是否已有「特休轉現金」紀錄
$conversion_check = $conn->prepare("SELECT COUNT(*) as count FROM annual_leave_records WHERE employee_id = ? AND year = ? AND month = ? AND status = '轉現金'");
$conversion_check->bind_param('iii', $employee_id, $year, $month);
$conversion_check->execute();
$conversion_result = $conversion_check->get_result()->fetch_assoc();
$has_vacation_conversion = ($conversion_result['count'] > 0);

// ✅ 第 20 點：判斷是否允許轉現金（有剩 + 尚未轉現金）
$can_convert_vacation = ($remaining_vacation > 0 && !$has_vacation_conversion);



// ✅ 計算請假時數（依據班別，排除休息時間）

function calculateLeaveHoursByShift($start, $end, $shift) {
    $start_ts = strtotime($start);
    $end_ts = strtotime($end);
    if (!$start_ts || !$end_ts || $end_ts <= $start_ts) return 0;

    $total = 0;
    $current = $start_ts;

    while ($current <= $end_ts) {
        $current_date = date('Y-m-d', $current);
        $shift_start = strtotime("$current_date {$shift['start_time']}");
        $shift_end = strtotime("$current_date {$shift['end_time']}");
        $break_start = strtotime("$current_date {$shift['break_start']}");
        $break_end = strtotime("$current_date {$shift['break_end']}");

        $actual_start = max($current, $shift_start);
        $actual_end = min($end_ts, $shift_end);

        // 若整段都不在班別時間內，跳過
        if ($actual_end <= $actual_start) {
            $current = strtotime('+1 day', strtotime($current_date));
            continue;
        }

        // 計算有效工作時數（排除休息）
        $duration = $actual_end - $actual_start;
        $break_overlap = max(0, min($actual_end, $break_end) - max($actual_start, $break_start));
        $work_seconds = max(0, $duration - $break_overlap);
        $total += $work_seconds;

        $current = strtotime('+1 day', strtotime($current_date));
    }

    return round($total / 3600, 1); // 轉成小時
}

// ✅ 判斷某天是否為平日（週一～週五，且不是國定假日）
function isWeekdayButNotHoliday($date, $conn) {
    $dow = date('w', strtotime($date)); // 0=星期日, 6=星期六

    // 查 holiday 表
    $stmt = $conn->prepare("SELECT is_working_day FROM holidays WHERE holiday_date = ?");
    $stmt->bind_param("s", $date);
    $stmt->execute();
    $res = $stmt->get_result()->fetch_assoc();
    $stmt->close();

    if ($res) {
        return $res['is_working_day'] == 1; // 補班日當作平日
    }

    return $dow >= 1 && $dow <= 5; // 週一～週五
}

// ✅ 判斷是否為假日（週末或國定假日）
function isHoliday($date, $conn) {
    $dow = date('w', strtotime($date)); // 0=星期日, 6=星期六

    // 查 holiday 表
    $stmt = $conn->prepare("SELECT is_working_day FROM holidays WHERE holiday_date = ?");
    $stmt->bind_param("s", $date);
    $stmt->execute();
    $res = $stmt->get_result()->fetch_assoc();
    $stmt->close();

    if ($res) {
        return $res['is_working_day'] == 0;
    }

    return $dow == 0 || $dow == 6; // 預設六日為假日
}


// ✅ 第 21 點：計算加班費（以班別時間與時薪為基礎）

$overtime_details = [];


// ✅ 第 21 點：依國定假日與補班日計算加班費
// ✅ 合併區段計算加班費
// ✅ 計算每筆加班的總金額與分段資料
if (empty($salary_record['overtime_pay'])) {
    foreach ($approved_requests as $request) {
        if ($request['type'] === '加班') {
            $start_ts = strtotime($request['start_date']);
            $end_ts = strtotime($request['end_date']);
            if ($end_ts > $start_ts) {
                $duration_hours = round(($end_ts - $start_ts) / 3600, 2);
                $start_date = date('Y-m-d', $start_ts);
                $is_holiday = isHoliday($start_date, $conn);

                $segments = [];
                $remaining = $duration_hours;

                // 分段邏輯（平日 / 假日）
                if (!$is_holiday) {
                    if ($remaining > 2) {
                        $segments[] = ['hours' => 2, 'rate' => 1.34];
                        $segments[] = ['hours' => $remaining - 2, 'rate' => 1.67];
                    } else {
                        $segments[] = ['hours' => $remaining, 'rate' => 1.34];
                    }
                } else {
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

                // 累計小時計算用
                $total_segment_start = 0;
                foreach ($segments as $seg) {
                    $seg_hours = $seg['hours'];
                    $seg_rate = $seg['rate'];
                    $pay = ceil($hourly_rate * $seg_hours * $seg_rate);
                    $overtime_pay += $pay;

                    // 顯示範圍（1小時 or 1–2小時）
                    $start_label = $total_segment_start + 1;
                    $end_label = $total_segment_start + $seg_hours;
                    if ($seg_hours == 1) {
                        $range_label = "{$start_label} 小時";
                    } else {
                        $range_label = "{$start_label} - {$end_label} 小時";
                    }

                    $overtime_details[] = [
                        'start' => $request['start_date'],
                        'end' => $request['end_date'],
                        'hours' => $seg_hours,
                        'rate' => $seg_rate,
                        'pay' => $pay,
                        'range_label' => $range_label
                    ];

                    $total_segment_start += $seg_hours;
                }
            }
        }
    }
} else {
    $overtime_pay = (int)$salary_record['overtime_pay'];
}



// ✅ 正確計算總工資（加班費不應重複加兩次）
$gross_salary = $base_salary + $meal_allowance + $attendance_bonus + $position_bonus + $skill_bonus + $vacation_cash2 + $overtime_pay;

$total_deductions = $labor_insurance + $health_insurance + $leave_deduction + $absent_deduction;
$net_salary = $gross_salary - $total_deductions;

?>


<!DOCTYPE html>
<html lang="zh-TW">
<head>
    <meta charset="UTF-8">
    <meta name="viewport" content="width=device-width, initial-scale=1.0">
    <title>員工薪資報表</title>
		<link href="https://cdn.jsdelivr.net/npm/bootstrap@5.3.3/dist/css/bootstrap.min.css" rel="stylesheet">
    <script src="https://cdn.jsdelivr.net/npm/bootstrap@5.3.3/dist/js/bootstrap.bundle.min.js"></script>
    <link rel="stylesheet" href="styles.css">
    <link rel="stylesheet" href="admin_navbar.css">
</head>
<style>
/* 加粗總工資、總扣除、實領薪資 */
.bold-row {
    font-weight: bold;
}

.deduction-amount {
    color: red !important;
    font-weight: bold;
}



/* 實領薪資金額底部加雙橫線 */
.double-underline {
    border-bottom: double 3px black;
}
	
</style>
<?php include 'admin_navbar.php'; ?>
<body>
    <div class="container">
	<div class="container mt-4">
		
        <h1>員工薪資報表</h1>
		<form method="GET" action="" class="row g-3 align-items-end mb-4">
            <div class="col-md-4">
                <label class="form-label">選擇員工</label>
                <select name="employee_id" class="form-select" required>
                    <?php foreach ($employee_list as $employee): ?>
                        <option value="<?= htmlspecialchars($employee['id']) ?>" <?= ($employee_id == $employee['id']) ? 'selected' : '' ?>>
                            <?= htmlspecialchars($employee['id'] . ' - ' . $employee['name']) ?>
                        </option>
                    <?php endforeach; ?>
                </select>
            </div>
            <div class="col-md-2">
                <label class="form-label">年份</label>
                <input type="number" name="year" value="<?= htmlspecialchars($year) ?>" class="form-control" required>
            </div>
            <div class="col-md-2">
                <label class="form-label">月份</label>
                <select name="month" class="form-select">
                    <?php for ($m = 1; $m <= 12; $m++): ?>
                        <option value="<?= $m ?>" <?= ($m == $month) ? 'selected' : '' ?>><?= $m ?></option>
                    <?php endfor; ?>
                </select>
            </div>
            <div class="col-md-2">
                <button type="submit" class="btn btn-primary w-100">查詢</button>
            </div>
        </form>
		<form method="POST" action="save_salary.php">
        <h2 class="mt-5">員工薪資結構</h2>
        <table class="table table-bordered">
            <?php foreach ($salary_data as $key => $value): ?>
                <tr><th class="table-primary"><?= htmlspecialchars($key) ?></th><td><?= htmlspecialchars($value) ?></td></tr>
            <?php endforeach; ?>
        </table>
        
        <!-- 核准申請表 -->
        <?php if (!empty($approved_requests)): ?>
            <h2>當月核准的申請</h2>
            <table class="approved-requests table table-bordered">
				<thead  class="table-primary">
                <tr>
					<th>類型</th>
					<th>假別</th>
					<th>理由</th>
					<th>起始日期與時間</th>
					<th>結束日期與時間</th>
					<th>狀態</th>
				</tr>
				</thead>
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
            </table>
        <?php endif; ?>

            </tbody>
        </table>
<?php if (!empty($used_leaves)): ?>
    <h2 class="mt-5">各休假次數累計表</h2>
    <table border="1"  class="table table-bordered">
		
        <thead  class="table-primary">
            <tr>
                <th>假別</th>
                <th>年度可休天數</th>
                <th>已使用天數</th>
                <th>已使用小時</th>
                <th>剩餘天數</th>
                <th>剩餘小時</th>
            </tr>
        </thead>
        <tbody  class="table table-bordered">
            <?php foreach ($leave_count as $leave_type => $count): ?>
                <?php
                    $used_days = (float) $count['used_days'];
                    $used_hours = (float) $count['used_hours'];
                    if ($used_days == 0 && $used_hours == 0) continue; // ❌ 沒有使用紀錄就跳過

                    $total_hours = $count['total_days'] * 8;
                    $used_total_hours = $used_days * 8 + $used_hours;
                    $remaining_total_hours = max(0, $total_hours - $used_total_hours);
                    $remain_days = floor($remaining_total_hours / 8);
                    $remain_hours = fmod($remaining_total_hours, 8);
                ?>
                <tr>
                    <td><?= htmlspecialchars($leave_type) ?></td>
                    <td><?= number_format($count['total_days'], 1) ?></td>
                    <td><?= number_format($used_days, 1) ?></td>
                    <td><?= number_format($used_hours, 1) ?></td>
                    <td><?= number_format($remain_days, 1) ?></td>
                    <td><?= number_format($remain_hours, 1) ?></td>
                </tr>
            <?php endforeach; ?>
        </tbody>
    </table>
<?php endif; ?>


	
		<!-- 只有當有特休變動紀錄時才顯示 -->
		<?php if ($has_vacation_history): ?>
			<h2 class="mt-5">特休變動紀錄</h2>
			<table border="1"  class="table table-bordered">
				<thead  class="table-primary">
				<tr>
					<th>年</th>
					<th>月</th>
					<th>天數</th>
					<th>狀態</th>
					<th>建立日期</th>
				</tr>
				</thead>
				<?php foreach ($history_result as $row): ?>
					<tr>
						<td><?= htmlspecialchars($row['year']) ?></td>
						<td><?= htmlspecialchars($row['month']) ?></td>
						<td><?= htmlspecialchars($row['vacation_days'] ?? '0') ?> 天</td>
						<td><?= htmlspecialchars($row['status']) ?></td>
						<td><?= htmlspecialchars($row['created_at']) ?></td>
					</tr>
				<?php endforeach; ?>
			</table>
		<?php endif; ?>

		<!-- 只有當有特休總覽紀錄時才顯示 -->
		<?php if ($has_vacation_summary): ?>
<!-- 🔵 員工特休彙總紀錄（新版） -->
<h2 class="mt-5">特休總覽</h2>
<table border="1"  class="table table-bordered">
    <thead  class="table-primary">
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
    <tbody  class="table table-bordered">
        <?php
        // 🔵 先初始化每個欄位預設為 0
        $total_acquired_days = 0;
        $total_acquired_hours = 0;
        $total_used_days = 0;
        $total_used_hours = 0;
        $total_cash_days = 0;
        $total_cash_hours = 0;

        // 🔵 查詢這位員工的特休累積資料
        $vacation_summary_stmt = $conn->prepare("
            SELECT 
                SUM(CASE WHEN status = '取得' THEN days ELSE 0 END) AS total_acquired_days,
                SUM(CASE WHEN status = '取得' THEN hours ELSE 0 END) AS total_acquired_hours,
                SUM(CASE WHEN status = '使用' THEN days ELSE 0 END) AS total_used_days,
                SUM(CASE WHEN status = '使用' THEN hours ELSE 0 END) AS total_used_hours,
                SUM(CASE WHEN status = '轉現金' THEN days ELSE 0 END) AS total_cash_days,
                SUM(CASE WHEN status = '轉現金' THEN hours ELSE 0 END) AS total_cash_hours
            FROM annual_leave_records
            WHERE employee_id = ?
        ");
        $vacation_summary_stmt->bind_param('i', $employee_id);
        $vacation_summary_stmt->execute();
        $vacation_summary = $vacation_summary_stmt->get_result()->fetch_assoc();

        if ($vacation_summary) {
            $total_acquired_days = (float)($vacation_summary['total_acquired_days'] ?? 0);
            $total_acquired_hours = (float)($vacation_summary['total_acquired_hours'] ?? 0);
            $total_used_days = (float)($vacation_summary['total_used_days'] ?? 0);
            $total_used_hours = (float)($vacation_summary['total_used_hours'] ?? 0);
            $total_cash_days = (float)($vacation_summary['total_cash_days'] ?? 0);
            $total_cash_hours = (float)($vacation_summary['total_cash_hours'] ?? 0);
        }

        // 🔵 計算剩餘特休（以小時計算後再拆回天+小時）
        $total_remaining_hours = 
            ($total_acquired_days * 8 + $total_acquired_hours)
            - ($total_used_days * 8 + $total_used_hours)
            - ($total_cash_days * 8 + $total_cash_hours);

        $remaining_days = floor($total_remaining_hours / 8);
        $remaining_hours = fmod($total_remaining_hours, 8);
        ?>

        <tr>
            <td><?= number_format($total_acquired_days, 1) ?> 天</td>
           
            <td><?= number_format($total_used_days, 1) ?> 天</td>
            <td><?= number_format($total_used_hours, 1) ?> 小時</td>
            <td><?= number_format($total_cash_days, 1) ?> 天</td>
            <td><?= number_format($total_cash_hours, 1) ?> 小時</td>
            <td><?= number_format($remaining_days, 1) ?> 天</td>
            <td><?= number_format($remaining_hours, 1) ?> 小時</td>
        </tr>
    </tbody>
</table>
		<?php endif; ?>
		
		<!-- 特休轉現金表單 -->
		<?php if ($can_convert_vacation): ?>
			<h2>特休轉現金</h2>
			<table border="1"  class="table table-bordered">
				<tr>
					
					<td class="table-primary">選擇特休轉現金天數</td>
				
					<td>
						<select id="vacation_cash_days" name="vacation_cash_days" onchange="updateSalary()">
							<?php for ($i = 0; $i <= $remaining_days; $i++): ?>
								<option value="<?= $i ?>" <?= $i == $vacation_cash_days2 ? 'selected' : '' ?>><?= $i ?> 天</option>
							<?php endfor; ?>
						</select>
					</td>
				</tr>
			</table>
		<?php endif; ?>


	
	 	<?php if (!empty($absent_result)): ?>
       <h2 class="mt-5">本月缺席時數表</h2>
        <table  class="table table-bordered">
			<thead  class="table-primary">
            <tr><th>日期</th><th>狀態</th><th>缺席時數（分鐘）</th></tr>
			</thead>
            <?php foreach ($absent_result as $absent): ?>
                <tr>
                    <td><?= htmlspecialchars($absent['date']) ?></td>
                    <td><?= htmlspecialchars($absent['status_text']) ?></td>
                    <td><?= htmlspecialchars($absent['absent_minutes']) ?></td>
                </tr>
            <?php endforeach; ?>
        </table>
        
        <h2 class="mt-5">總缺席時數</h2>
        <p>總計缺席：<?= htmlspecialchars($total_absent_minutes) ?> 分鐘</p>
		<?php endif; ?>
	
				<input type="hidden" name="employee_id" value="<?= htmlspecialchars($employee_id) ?>">
				<input type="hidden" name="year" value="<?= htmlspecialchars($year) ?>">
				<input type="hidden" name="month" value="<?= htmlspecialchars($month) ?>">

				<h2><?= htmlspecialchars($employee_name) ?> 本月應領薪資</h2>
				<table  class="table table-bordered">
					<thead  class="table-primary">
					<tr><th style="width: 10%;">項目</th><th style="width: 20%;">金額</th><th style="width: 70%;">計算方式</th></tr>
					</thead>	
					<!-- ✅ 底薪 -->
					<tr id="row_base_salary" style="display: <?= $base_salary > 0 ? 'table-row' : 'none' ?>;">
						<td class="table-primary">底薪</td>
						<td class="ps-4">
							<span id="base_salary_display"><?= htmlspecialchars($base_salary) ?></span>
							<input type="number" id="base_salary" name="base_salary" value="<?= htmlspecialchars($base_salary) ?>" style="display: none;" required oninput="updateSalary()">
						</td>
						<td>
							<div id="base_salary_note_display"><?= nl2br(htmlspecialchars($salary_record['base_salary_note'] ?? '')) ?></div>
							<textarea id="base_salary_note" name="base_salary_note" class="form-control" style="display: none;"><?= htmlspecialchars($salary_record['base_salary_note'] ?? '') ?></textarea>
						</td>
					</tr>
					 <!-- ✅ 餐費 -->
					<tr id="row_meal_allowance" style="display: <?= $meal_allowance > 0 ? 'table-row' : 'none' ?>;">
						<td class="table-primary">餐費</td>
						<td class="ps-4">
							<span id="meal_allowance_display"><?= htmlspecialchars($meal_allowance) ?></span>
							<input type="number" id="meal_allowance" name="meal_allowance" value="<?= htmlspecialchars($meal_allowance) ?>" style="display: none;" oninput="updateSalary()">
						</td>
						<td>
							<div id="meal_allowance_note_display"><?= nl2br(htmlspecialchars($salary_record['meal_allowance_note'] ?? '')) ?></div>
							<textarea id="meal_allowance_note" name="meal_allowance_note" class="form-control" style="display: none;"><?= htmlspecialchars($salary_record['meal_allowance_note'] ?? '') ?></textarea>
						</td>
					</tr>

					<!-- ✅ 全勤獎金 -->
					<tr id="row_attendance_bonus" style="display: <?= $attendance_bonus > 0 ? 'table-row' : 'none' ?>;">
						<td class="table-primary">全勤獎金</td>
						<td class="ps-4">
							<span id="attendance_bonus_display"><?= htmlspecialchars($attendance_bonus) ?></span>
							<input type="number" id="attendance_bonus" name="attendance_bonus" value="<?= htmlspecialchars($attendance_bonus) ?>" style="display: none;" oninput="updateSalary()">
						</td>
						<td>
							<div id="attendance_bonus_note_display"><?= nl2br(htmlspecialchars($salary_record['attendance_bonus_note'] ?? '')) ?></div>
							<textarea id="attendance_bonus_note" name="attendance_bonus_note" class="form-control" style="display: none;"><?= htmlspecialchars($salary_record['attendance_bonus_note'] ?? '') ?></textarea>
						</td>
					</tr>

					<!-- ✅ 職務加給 -->
					<tr id="row_position_bonus" style="display: <?= $position_bonus > 0 ? 'table-row' : 'none' ?>;">
						<td class="table-primary">職務加給</td>
						<td class="ps-4">
							<span id="position_bonus_display"><?= htmlspecialchars($position_bonus) ?></span>
							<input type="number" id="position_bonus" name="position_bonus" value="<?= htmlspecialchars($position_bonus) ?>" style="display: none;" oninput="updateSalary()">
						</td>
						<td>
							<div id="position_bonus_note_display"><?= nl2br(htmlspecialchars($salary_record['position_bonus_note'] ?? '')) ?></div>
							<textarea id="position_bonus_note" name="position_bonus_note" class="form-control" style="display: none;"><?= htmlspecialchars($salary_record['position_bonus_note'] ?? '') ?></textarea>
						</td>
					</tr>

					<!-- ✅ 技術津貼 -->
					<tr id="row_skill_bonus" style="display: <?= $skill_bonus > 0 ? 'table-row' : 'none' ?>;">
						<td class="table-primary">技術津貼</td>
						<td class="ps-4">
							<span id="skill_bonus_display"><?= htmlspecialchars($skill_bonus) ?></span>
							<input type="number" id="skill_bonus" name="skill_bonus" value="<?= htmlspecialchars($skill_bonus) ?>" style="display: none;" oninput="updateSalary()">
						</td>
						<td>
							<div id="skill_bonus_note_display"><?= nl2br(htmlspecialchars($salary_record['skill_bonus_note'] ?? '')) ?></div>
							<textarea id="skill_bonus_note" name="skill_bonus_note" class="form-control" style="display: none;"><?= htmlspecialchars($salary_record['skill_bonus_note'] ?? '') ?></textarea>
						</td>
					</tr>
					<!-- ✅ 加班費 -->
						<tr id="row_overtime_pay" style="display: <?= $overtime_pay > 0 ? 'table-row' : 'none' ?>;">
							<td class="table-primary">加班費</td>
							<td class="ps-4">
								<span id="overtime_pay_display">
									<?php if (!empty($salary_record['overtime_pay'])): ?>
										<?= nl2br(htmlspecialchars($salary_record['overtime_pay'])) ?>
									<?php else: ?>
									<?= htmlspecialchars($overtime_pay) ?>
									<?php endif; ?>
								</span>
								<input type="number" id="overtime_pay" name="overtime_pay" value="<?php if (!empty($salary_record['overtime_pay'])): ?><?= nl2br(htmlspecialchars($salary_record['overtime_pay'])) ?><?php else: ?><?= htmlspecialchars($overtime_pay) ?><?php endif; ?>"
								style="display: none;" oninput="updateSalary()">
							</td>
							<td>
								<div id="overtime_note_display">
									<?php if (!empty($salary_record['overtime_note'])): ?>
										<?= nl2br(htmlspecialchars($salary_record['overtime_note'])) ?>
									<?php else: ?>
										<?php $total_overtime_hours = 0;
											foreach ($overtime_details as $ot) {
												echo "{$ot['start']} ~ {$ot['end']}：{$ot['range_label']} × 時薪 {$hourly_rate} × {$ot['rate']} 倍 = {$ot['pay']} 元<br>";
												$total_overtime_hours += $ot['hours'];
											}
											echo "<strong>共計 " . number_format($total_overtime_hours, 1) . " 小時</strong>"; ?>
										
									<?php endif; ?>
								</div>
								<textarea id="overtime_note" name="overtime_note" class="form-control" style="display: none;"><?php
										if (!empty($salary_record['overtime_note'])) {
											echo htmlspecialchars($salary_record['overtime_note']);
										} else {
											$total_overtime_hours = 0;
											foreach ($overtime_details as $ot) {
												echo "{$ot['start']} ~ {$ot['end']}：{$ot['range_label']} × 時薪 {$hourly_rate} × {$ot['rate']} 倍 = {$ot['pay']} 元<br>";
												$total_overtime_hours += $ot['hours'];
											}
											echo "<strong>共計 " . number_format($total_overtime_hours, 1) . " 小時</strong>";
										}
									?>
								</textarea>
							</td>
						</tr>

					<!-- ✅ 特休轉現金 -->
					<?php
					$vacation_cash_note_text = isset($salary_record['vacation_cash_note']) && $salary_record['vacation_cash_note'] !== ''
						? $salary_record['vacation_cash_note']
						: '底薪 / 240 × 8 小時 × 天數';
					?>
					<tr id="row_vacation_cash" style="display: <?= $vacation_cash2 > 0 ? 'table-row' : 'none' ?>;">
						<td class="table-primary">特休轉現金</td>
						<td class="ps-4">
							<span id="vacation_cash_display"><?= htmlspecialchars($vacation_cash2) ?></span>
							<input type="number" id="vacation_cash" name="vacation_cash" value="<?= htmlspecialchars($vacation_cash2) ?>" style="display: none;" oninput="updateSalary()" readonly>
						</td>
						<td>
							<div id="vacation_cash_note_display">
								<?= nl2br(htmlspecialchars($vacation_cash_note_text)) ?>
							</div>
							<textarea id="vacation_cash_note" name="vacation_cash_note" class="form-control" style="display: none;"><?= htmlspecialchars($vacation_cash_note_text) ?></textarea>
						</td>
					</tr>

					<!-- 總工資自動計算 -->
					<tr class="bold-row">
						<td class="table-primary">總工資</td>
						<td id="gross_salary_display"><?= htmlspecialchars($gross_salary) ?></td>
						<input type="hidden" id="gross_salary" name="gross_salary" value="<?= htmlspecialchars($gross_salary) ?>">
						<td></td>
					</tr>

					<!-- ✅ 勞保費 -->
					<tr id="row_labor_insurance">
						<td class="table-primary">勞保費</td>
						<td class="deduction-amount ps-4">
							<span id="labor_insurance_display"><?= htmlspecialchars($labor_insurance) ?></span>
							<input type="number" id="labor_insurance" name="labor_insurance" value="<?= htmlspecialchars($labor_insurance) ?>" style="display: none;" oninput="updateSalary()">
						</td>
						<td>
							<div id="labor_insurance_note_display"><?= nl2br(htmlspecialchars($salary_record['labor_insurance_note'] ?? '依照級距表')) ?></div>
							<textarea id="labor_insurance_note" name="labor_insurance_note" class="form-control" style="display: none;"><?= nl2br(htmlspecialchars($salary_record['labor_insurance_note'] ?? '依照級距表')) ?></textarea>
						</td>
					</tr>

					<!-- ✅ 健保費 -->
					<tr id="row_health_insurance">
						<td class="table-primary">健保費</td>
						<td class="deduction-amount ps-4">
							<span id="health_insurance_display"><?= htmlspecialchars($health_insurance) ?></span>
							<input type="number" id="health_insurance" name="health_insurance" value="<?= htmlspecialchars($health_insurance) ?>" style="display: none;" oninput="updateSalary()">
						</td>
						<td>
							<div id="health_insurance_note_display"><?= nl2br(htmlspecialchars($salary_record['health_insurance_note'] ?? '依照級距表')) ?></div>
							<textarea id="health_insurance_note" name="health_insurance_note" class="form-control" style="display: none;"><?= nl2br(htmlspecialchars($salary_record['health_insurance'] ?? '依照級距表')) ?></textarea>
						</td>
					</tr>
					<!-- 📌 第1點：請假扣除資料列 -->
<tr id="row_leave_deduction" style="display: <?= $leave_deduction > 0 ? 'table-row' : 'none' ?>;">
    <td class="table-primary">請假扣除</td>

    <!-- 📌 第2點：金額欄，顯示金額與可編輯 input -->
    <td class="ps-4">
        <span class="deduction-amount" id="leave_deduction_display"><?= htmlspecialchars($leave_deduction) ?></span>
        <input type="number" id="leave_deduction" name="leave_deduction" value="<?= htmlspecialchars($leave_deduction) ?>" style="display: none;" required oninput="updateSalary()">
    </td>

    <!-- 📌 第3點：計算方式欄，顯示備註或自動組成文字 -->
    <td>
        <div id="leave_deduction_note_display">
            <?php if (!empty($salary_record['leave_deduction_note'])): ?>
                <?= nl2br(htmlspecialchars($salary_record['leave_deduction_note'])) ?>
            <?php else: ?>
                <?php foreach ($leave_deduction_details as $item): ?>
                    [<?= $item['date'] ?>] <?= $item['type'] ?>：<?= $item['hours'] ?> 小時 × 時薪 <?= $item['hourly_rate'] ?> × 扣除比例 <?= $item['deduct_percent'] ?>% = <?= $item['amount'] ?> 元<br>
                <?php endforeach; ?>
            <?php endif; ?>
        </div>

        <!-- 📌 第4點：textarea 編輯備註用 -->
        <textarea id="leave_deduction_note" name="leave_deduction_note" class="form-control" style="display: none;"><?php if (!empty($salary_record['leave_deduction_note'])): ?><?= htmlspecialchars($salary_record['leave_deduction_note']) ?><?php else: ?><?php foreach ($leave_deduction_details as $item): ?>[<?= $item['date'] ?>] <?= $item['type'] ?>：<?= $item['hours'] ?> 小時 × 時薪 <?= $item['hourly_rate'] ?> × 扣除比例 <?= $item['deduct_percent'] ?>% = <?= $item['amount'] ?> 元<?php if (end($leave_deduction_details) !== $item): ?>&#13;<?php endif; ?><?php endforeach; ?><?php endif; ?></textarea>
    </td>
</tr>

					<tr id="row_absent_deduction" style="display: <?= $absent_deduction > 0 ? 'table-row' : 'none' ?>;">
						<td class="table-primary">缺席扣除</td>
						<td class="ps-4">
							<span class="deduction-amount" id="absent_deduction_display"><?= htmlspecialchars($absent_deduction) ?></span>
							<input type="number" id="absent_deduction" name="absent_deduction" value="<?= htmlspecialchars($absent_deduction) ?>" style="display: none;" required oninput="updateSalary()">
						</td>
						<td>
							<div id="absent_deduction_note_display">
								<?php if (!empty($salary_record['absent_deduction_note'])): ?>
									<?= nl2br(htmlspecialchars($salary_record['absent_deduction_note'])) ?>
								<?php else: ?>
									缺席時數: <?= (int)$total_absent_minutes ?> 分鐘（<?= round($total_absent_minutes / 60, 1) ?> 小時） × 換算時薪: <?= ceil($base_salary / 240) ?> 元
								<?php endif; ?>
							</div>
							<textarea id="absent_deduction_note" name="absent_deduction_note" class="form-control" style="display: none;"><?php if (!empty($salary_record['absent_deduction_note'])): ?><?= nl2br(htmlspecialchars($salary_record['absent_deduction_note'])) ?><?php else: ?>缺席時數: <?= (int)$total_absent_minutes ?> 分鐘（<?= round($total_absent_minutes / 60, 1) ?> 小時） × 換算時薪: <?= ceil($base_salary / 240) ?> 元<?php endif; ?></textarea>
						</td>
					</tr>

					<!-- 總扣除自動計算 -->
					<tr class="bold-row">
						<td class="table-primary">總扣除</td>
						<td class="deduction-amount" id="total_deductions_display"><?= htmlspecialchars($total_deductions) ?></td>
						<input type="hidden" id="total_deductions" name="total_deductions" value="<?= htmlspecialchars($total_deductions) ?>">
						<td></td>
					</tr>

					<!-- 實領薪資自動計算 -->
					<tr class="bold-row">
						<td class="table-primary">實領薪資</td>
						<td class="double-underline" id="net_salary_display"><?= htmlspecialchars($net_salary) ?></td>
						<input type="hidden" id="net_salary" name="net_salary" value="<?= htmlspecialchars($net_salary) ?>">
						<td>總工資 - 總扣除</td>
					</tr>
				</table>
			<div class="my-4 d-flex gap-2">
			<button class="btn btn-success" type="submit" name="save_salary" id="save_salary_btn">儲存薪資</button>

			<?php if ($salary_record): ?>
				<button class="btn btn-warning" type="button" id="edit_salary_btn" onclick="enableSalaryEditing()">修改薪資</button>
				<button class="btn btn-outline-danger" type="button" id="cancel_edit_btn" style="display: none;" onclick="cancelSalaryEditing()">取消修改</button>
			<?php endif; ?>


			<button class="btn btn-secondary" type="button" id="export_button" onclick="exportToImage()">匯出圖片</button>
		</div>




			</form>
			


    </div>
	</div>
	<script>
	// ✅ 第 1 區：點擊「修改薪資」 → 顯示 input 與取消按鈕
	function enableSalaryEditing() {
		const editableFields = [
			'base_salary',
			'meal_allowance',
			'attendance_bonus',
			'position_bonus',
			'skill_bonus',
			'overtime_pay',
			'vacation_cash',
			'labor_insurance',
			'health_insurance',
			'leave_deduction',
			'absent_deduction',
			'overtime'
		];

		editableFields.forEach(id => {
			const input = document.getElementById(id);
			const span = document.getElementById(id + '_display');
			const row = document.getElementById('row_' + id);
			const noteInput = document.getElementById(id + '_note');
			const noteDisplay = document.getElementById(id + '_note_display');

			// 顯示 input 欄
			if (input) input.style.display = 'inline-block';
			if (span) span.style.display = 'none';
			if (row) row.style.display = 'table-row';

			// 顯示 textarea + 隱藏原說明
			if (noteInput && noteDisplay) {
				noteInput.style.display = 'block';
				noteDisplay.style.display = 'none';
			}

			// 特休轉現金的 input 預設 readonly，要打開
			if (id === 'vacation_cash') {
				input?.removeAttribute('readonly');
			}
		});

		document.getElementById("cancel_edit_btn").style.display = "inline-block";
	}



	// ✅ 第 2 區：點擊「取消修改」 → 恢復原樣、隱藏 input
	function cancelSalaryEditing() {
		const editableFields = [
			'base_salary',
			'meal_allowance',
			'attendance_bonus',
			'position_bonus',
			'skill_bonus',
			'overtime_pay',
			'vacation_cash',
			'labor_insurance',
			'health_insurance',
			'leave_deduction',
			'absent_deduction',
			'overtime'
		];

		editableFields.forEach(id => {
			const input = document.getElementById(id);
			const span = document.getElementById(id + '_display');
			const row = document.getElementById('row_' + id);
			const noteInput = document.getElementById(id + '_note');
			const noteDisplay = document.getElementById(id + '_note_display');

			// 還原 input 值 & 隱藏輸入框，顯示原本 span
			if (input && span) {
				input.style.display = 'none';
				span.style.display = 'inline-block';

				// 回填原數值
				input.value = parseFloat(span.textContent.trim().replace(',', '')) || 0;

				// 特休轉現金重設為 readonly
				if (id === 'vacation_cash') {
					input.setAttribute('readonly', true);
				}
			}

			// 還原說明區塊
			if (noteInput && noteDisplay) {
				noteInput.style.display = 'none';
				noteDisplay.style.display = 'block';

				// 將 textarea 還原成顯示文字
				noteInput.value = noteDisplay.innerText.trim();
			}

			// 若數值為 0 就整行隱藏
			if (row && span) {
				const val = parseFloat(span.textContent.trim().replace(',', '')) || 0;
				row.style.display = val > 0 ? 'table-row' : 'none';
			}
		});

		document.getElementById("cancel_edit_btn").style.display = "none";
	}



	function updateSalary() {
    let base_salary = parseInt(document.getElementById('base_salary').value) || 0;
    let meal_allowance = parseInt(document.getElementById('meal_allowance').value) || 0;
    let attendance_bonus = parseInt(document.getElementById('attendance_bonus').value) || 0;
    let position_bonus = parseInt(document.getElementById('position_bonus').value) || 0;
    let skill_bonus = parseInt(document.getElementById('skill_bonus').value) || 0;
    let labor_insurance = parseInt(document.getElementById('labor_insurance').value) || 0;
    let health_insurance = parseInt(document.getElementById('health_insurance').value) || 0;
    let leave_deduction = parseInt(document.getElementById('leave_deduction').value) || 0;
    let absent_deduction = parseInt(document.getElementById('absent_deduction').value) || 0;

    // ✅ 改為直接讀金額，不從天數計算
    let vacation_cash = parseInt(document.getElementById('vacation_cash').value) || 0;

    // ✅ 更新顯示
    document.getElementById('vacation_cash_display').innerText = vacation_cash;

    let overtime_pay = <?= $overtime_pay ?>;

    let gross_salary = base_salary + meal_allowance + attendance_bonus + position_bonus + skill_bonus + vacation_cash + overtime_pay;
    document.getElementById('gross_salary_display').innerText = gross_salary;
    document.getElementById('gross_salary').value = gross_salary;

    let total_deductions = labor_insurance + health_insurance + leave_deduction + absent_deduction;
    document.getElementById('total_deductions_display').innerText = total_deductions;
    document.getElementById('total_deductions').value = total_deductions;

    let net_salary = gross_salary - total_deductions;
    document.getElementById('net_salary_display').innerText = net_salary;
    document.getElementById('net_salary').value = net_salary;
}

		
		
function updateVacationCash() {
    const base_salary = parseInt(document.getElementById('base_salary').value) || 0;
    const vacation_days = parseInt(document.getElementById('vacation_cash_days').value) || 0;
    const vacation_cash_input = document.getElementById('vacation_cash');

    const vacation_cash = Math.ceil(base_salary / 240 * vacation_days * 8);
    vacation_cash_input.value = vacation_cash;
    document.getElementById('vacation_cash_display').innerText = vacation_cash;

    updateSalary();
}

document.getElementById("vacation_cash_days").addEventListener("change", function () {
    let row = document.getElementById("row_vacation_cash");
    let days = parseInt(this.value) || 0;
    if (days > 0) {
        row.style.display = "table-row";
    } else {
        row.style.display = "none";
    }

    updateVacationCash(); // 加入金額計算
});


	</script>
<script src="https://cdnjs.cloudflare.com/ajax/libs/html2canvas/1.4.1/html2canvas.min.js"></script>
<script>
function exportToImage() {
    // 只擷取有資料的內容
    const exportSection = document.createElement("div");
    exportSection.style.padding = "20px";
    exportSection.style.background = "#ffffff";
    exportSection.style.width = "100%";
    exportSection.style.maxWidth = "1200px";
    exportSection.style.margin = "0 auto";

    // 選擇需要擷取的內容
    const tables = document.querySelectorAll("h2, table");
    tables.forEach(table => {
        if (table.offsetHeight > 0) {
            exportSection.appendChild(table.cloneNode(true));
        }
    });

    // 加入標題
    const title = document.createElement("h2");

    // 取得目前選擇的年、月
    const year = document.querySelector("[name='year']").value;
    const month = document.querySelector("[name='month']").value;

    // 取得員工姓名
    const employeeSelect = document.querySelector("[name='employee_id']");
    const employeeName = employeeSelect.options[employeeSelect.selectedIndex].text.split(" - ")[1] || "未知員工";

    // ✅ 公司名稱統一為：麥創藝有限公司
    const companyName = "麥創藝有限公司";

    // ✅ 組合完整標題
    const titleText = `${companyName} ${year}年${month}月 ${employeeName} 薪資報表`;
    title.textContent = titleText;
    title.style.textAlign = "center";
    title.style.marginBottom = "20px";

    exportSection.insertBefore(title, exportSection.firstChild);

    // 插入到頁面上但不顯示
    exportSection.style.position = "absolute";
    exportSection.style.left = "-9999px";
    document.body.appendChild(exportSection);

    // 匯出圖片
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

	
	document.getElementById("vacation_cash_days").addEventListener("change", function () {
    let row = document.getElementById("row_vacation_cash");
    if (this.value > 0) {
        row.style.display = "table-row";
    } else {
        row.style.display = "none";
    }
});

</script>
</body>
</html>


