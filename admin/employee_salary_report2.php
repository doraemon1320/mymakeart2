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
$leave_deduction = $salary_record['leave_deduction'] ?? 0;
$absent_deduction = $salary_record['absent_deduction'] ?? 0;
$vacation_cash2 = $salary_record['vacation_cash'] ?? 0;
$vacation_cash_days2 = $salary_record['vacation_cash_days'] ?? 0;
$hourly_rate = ceil($base_salary / 240);
$total_absent_minutes = 0;
$overtime_pay = 0;
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

// ✅ 第 13 點：依照班別計算請假扣薪時數與金額
$leave_deductions = []; // ✅ 新陣列儲存每筆請假扣薪結果
foreach ($leave_results as $leave) {
    $hours = calculateLeaveHoursByShift($leave['start_date'], $leave['end_date'], $shift);
    $salary_ratio = (float)($leave['salary_ratio'] ?? 0);
    $deduct = ceil($hourly_rate * $hours * (1 - ($salary_ratio / 100)));

	$leave_deductions[] = array_merge($leave, [
    'hours' => $hours,
    'deduct' => $deduct,
	]);

}
$leave_deduction = array_sum(array_column($leave_deductions, 'deduct'));


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

// ✅ 第 16 點：整理出「有使用」的假別（供前端表格過濾）
$used_leaves = array_filter($leave_count, fn($x) => $x['used_days'] > 0 || $x['used_hours'] > 0);


// ✅ 第 17 點：撈出「特休變動紀錄」for 顯示歷史表格
$history_stmt = $conn->prepare("
    SELECT year, month, days AS vacation_days, hours AS vacation_hours, status, created_at 
    FROM annual_leave_records 
    WHERE employee_id = ? 
    ORDER BY year DESC, month DESC, created_at DESC
");

$history_stmt->bind_param('i', $employee_id);
$history_stmt->execute();
$history_result = $history_stmt->get_result()->fetch_all(MYSQLI_ASSOC);
$has_vacation_history = !empty($history_result);

// 分頁設定
$perPage = 10;
$page = isset($_GET['vacation_page']) ? max(1, intval($_GET['vacation_page'])) : 1;
$offset = ($page - 1) * $perPage;

// 撈出所有特休變動紀錄（排序年、月、建立時間 降冪）
$vacation_history_stmt = $conn->prepare("
    SELECT year, month, days, hours, status, created_at
    FROM annual_leave_records
    WHERE employee_id = ?
    ORDER BY year DESC, month DESC, created_at DESC
    LIMIT ? OFFSET ?
");
$vacation_history_stmt->bind_param("iii", $employee_id, $perPage, $offset);
$vacation_history_stmt->execute();
$vacation_history_result = $vacation_history_stmt->get_result()->fetch_all(MYSQLI_ASSOC);

// 查總筆數供分頁使用
$count_stmt = $conn->prepare("SELECT COUNT(*) as total FROM annual_leave_records WHERE employee_id = ?");
$count_stmt->bind_param("i", $employee_id);
$count_stmt->execute();
$count_result = $count_stmt->get_result()->fetch_assoc();
$total_records = (int)($count_result['total'] ?? 0);
$total_pages = ceil($total_records / $perPage);



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

// ✅ 第 21 點：計算加班費（以班別時間與時薪為基礎）
$overtime_pay = 0;
$overtime_details = [];

foreach ($approved_requests as $request) {
    if ($request['type'] === '加班') {
        $start = strtotime($request['start_date']);
        $end = strtotime($request['end_date']);
        if ($end > $start) {
            $duration_hours = round(($end - $start) / 3600, 1); // 計算小時
            $rate = 1.33; // 加班費率（平日1.33倍，假日可調為2）
            $pay = ceil($hourly_rate * $duration_hours * $rate);
            $overtime_pay += $pay;
            $overtime_details[] = [
                'start' => $request['start_date'],
                'end' => $request['end_date'],
                'hours' => $duration_hours,
                'rate' => $rate,
                'pay' => $pay,
            ];
        }
    }
}
// ✅ 第 22 點：計算總工資、總扣除與實領薪資// ✅ 原始計算：底薪 + 津貼 + 特休轉現金 + 加班費
$gross_salary = $base_salary + $meal_allowance + $attendance_bonus + $position_bonus + $skill_bonus + $vacation_cash2 + $overtime_pay;

$total_deductions = $labor_insurance + $health_insurance + $leave_deduction + $absent_deduction;
$net_salary = $gross_salary - $total_deductions;
// ✅ 後補：加入加班費
$gross_salary += $overtime_pay;
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

/* 所有扣除項目金額為紅色 */
.deduction-amount {
    color: red !important;
}

/* 總扣除 ➜ 紅色 + 粗體 */
.bold-row .deduction-amount {
    font-weight: bold;
    color: red;
}

/* 實領薪資金額底部加雙橫線 */
.double-underline {
    border-bottom: double 3px black;
}

</style>

<body>
<?php include 'admin_navbar.php'; ?>
    <div class="container">
	<div class="container mt-4">
        <h2>員工薪資報表</h2>
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
        <h5 class="mt-5">員工薪資結構</h5>
		<table class="table table-bordered">
			<?php foreach ($salary_data as $key => $value): 
				$field_id = strtolower(str_replace([' ', '＋', '（', '）'], ['_', '', '', ''], $key));
			?>
				<tr>
					<th style="background-color: #cfe2ff;"><?= htmlspecialchars($key) ?></th>
					<td>
						<!-- 顯示數字（預設顯示） -->
						<span id="<?= $field_id ?>_display"><?= number_format($value, 2) ?></span>

					</td>
				</tr>
			<?php endforeach; ?>
		</table>



        
        <!-- 核准申請表 -->
        <?php if (!empty($approved_requests)): ?>
            <h5 class="mt-5">當月核准的申請</h5>
            <table class="approved-requests table table-bordered">
                <thead  class="table-primary">
				<tr><th>類型</th>
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
    <h5 class="mt-5">各休假次數累計表</h5>
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


	
<?php if ($total_records > 0): ?>
    <h5 class="mt-5">特休變動紀錄</h5>
    <table class="table table-bordered">
        <thead class="table-primary">
            <tr>
                <th>年</th>
                <th>月</th>
                <th>天數</th>
                <th>小時</th>
                <th>狀態</th>
                <th>建立日期</th>
            </tr>
        </thead>
        <tbody>
            <?php foreach ($vacation_history_result as $row): ?>
                <tr>
                    <td><?= htmlspecialchars($row['year']) ?></td>
                    <td><?= htmlspecialchars($row['month']) ?></td>
                    <td><?= number_format($row['days'], 2) ?> 天</td>
                    <td><?= number_format($row['hours'], 1) ?> 小時</td>
                    <td><?= htmlspecialchars($row['status']) ?></td>
                    <td><?= htmlspecialchars($row['created_at']) ?></td>
                </tr>
            <?php endforeach; ?>
        </tbody>
    </table>

    <!-- 分頁按鈕 -->
    <nav>
        <ul class="pagination">
            <?php for ($i = 1; $i <= $total_pages; $i++): ?>
                <li class="page-item <?= ($i == $page) ? 'active' : '' ?>">
                    <a class="page-link" href="?employee_id=<?= $employee_id ?>&year=<?= $year ?>&month=<?= $month ?>&vacation_page=<?= $i ?>">
                        <?= $i ?>
                    </a>
                </li>
            <?php endfor; ?>
        </ul>
    </nav>
<?php else: ?>
    <p>查無特休變動紀錄。</p>
<?php endif; ?>


		<!-- 只有當有特休總覽紀錄時才顯示 -->
		<?php if ($has_vacation_summary): ?>
<!-- 🔵 員工特休彙總紀錄（新版） -->
<h5 class="mt-5">特休總覽</h5>
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
			<h5 class="mt-5">特休轉現金</h5>
			<table border="1"  class="table table-bordered">
				<tr>
					<td>選擇特休轉現金天數</td>
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
       <h5 class="mt-5">本月缺席時數表</h5>
        <table  class="table table-bordered">
             <thead  class="table-primary">
			<tr>
				<th>日期</th>
				<th>狀態</th>
				<th>缺席時數（分鐘）</th>
			</tr>
			</thead>
            <?php foreach ($absent_result as $absent): ?>
                <tr>
                    <td><?= htmlspecialchars($absent['date']) ?></td>
                    <td><?= htmlspecialchars($absent['status_text']) ?></td>
                    <td><?= htmlspecialchars($absent['absent_minutes']) ?></td>
                </tr>
            <?php endforeach; ?>
        </table>
        
        <h5 class="mt-5">總缺席時數</h5>
        <p>總計缺席：<?= htmlspecialchars($total_absent_minutes) ?> 分鐘</p>
		<?php endif; ?>
	
				<input type="hidden" name="employee_id" value="<?= htmlspecialchars($employee_id) ?>">
				<input type="hidden" name="year" value="<?= htmlspecialchars($year) ?>">
				<input type="hidden" name="month" value="<?= htmlspecialchars($month) ?>">

				<h5 class="mt-5"><?= htmlspecialchars($employee_name) ?> 本月應領薪資</h5>
				<table  class="table table-bordered">
				<thead  class="table-primary">
				<tr>
					<th>項目</th>
					<th>金額</th>
					<th>計算方式</th>
				</tr>
				</thead>
	
					
					<tr id="row_base_salary" style="display: <?= $base_salary > 0 ? 'table-row' : 'none' ?>;">
						<td>底薪</td>
						<td>
							<span id="base_salary_display"><?= htmlspecialchars($base_salary) ?></span>
							<input type="number" id="base_salary" name="base_salary" value="<?= htmlspecialchars($base_salary) ?>" style="display: none;"  required oninput="updateSalary()">
						</td>
						<td></td>
					</tr>
					<tr id="row_meal_allowance" style="display: <?= $meal_allowance > 0 ? 'table-row' : 'none' ?>;">
						<td>餐費</td>
						<td>
							<span id="meal_allowance_display"><?= htmlspecialchars($meal_allowance) ?></span>
							<input type="number" id="meal_allowance" name="meal_allowance" value="<?= htmlspecialchars($meal_allowance) ?>"  style="display: none;" oninput="updateSalary()">
						</td>
						<td></td>
					</tr>
					<tr id="row_attendance_bonus" style="display: <?= $attendance_bonus > 0 ? 'table-row' : 'none' ?>;">
						<td>全勤獎金</td>
						<td>
							<span id="attendance_bonus_display"><?= htmlspecialchars($attendance_bonus) ?></span>
							<input type="number" id="attendance_bonus" name="attendance_bonus" value="<?= htmlspecialchars($attendance_bonus) ?>"  style="display: none;" oninput="updateSalary()"></td>
						<td></td>
					</tr>
					
					<tr id="row_position_bonus" style="display: <?= $position_bonus > 0 ? 'table-row' : 'none' ?>;">
						<td>職務津貼</td>
						<td>
							<span id="position_bonus_display"><?= htmlspecialchars($position_bonus) ?></span>
							<input type="number" id="position_bonus" name="position_bonus" value="<?= htmlspecialchars($position_bonus) ?>"  style="display: none;" oninput="updateSalary()"></td>
						<td></td>
					</tr>
					<tr id="row_skill_bonus" style="display: <?= $skill_bonus > 0 ? 'table-row' : 'none' ?>;">
						<td>技能津貼</td>
						<td>
							<span id="skill_bonus_display"><?= htmlspecialchars($skill_bonus) ?></span>
							<input type="number" id="skill_bonus" name="skill_bonus" value="<?= htmlspecialchars($skill_bonus) ?>"  style="display: none;" oninput="updateSalary()"></td>
						<td></td>
					</tr>		
					<tr id="row_overtime_pay" style="display: <?= $overtime_pay > 0 ? 'table-row' : 'none' ?>;">
						<td>加班費</td>
						<td><?= $overtime_pay ?></td>
						<td>
							<?php foreach ($overtime_details as $ot): ?>
								<?= "{$ot['start']} ~ {$ot['end']}：{$ot['hours']} 小時 × 時薪 {$hourly_rate} × {$ot['rate']} 倍 = {$ot['pay']} 元<br>"; ?>
							<?php endforeach; ?>
						</td>
					</tr>

					<tr id="row_vacation_cash" style="display: none;">
						<td>特休轉現金</td>
						<td id="vacation_cash_amount_display"><?= htmlspecialchars($vacation_cash2) ?></td>
						<input type="hidden" id="vacation_cash" name="vacation_cash" value="<?= $vacation_cash2 ?>">
						
						<td>底薪 / 240 × (天數*8)</td>
					</tr>
					<!-- 總工資自動計算 -->
					<tr class="bold-row">
						<td>總工資</td>
						<td id="gross_salary_display"><?= htmlspecialchars($gross_salary) ?></td>
						<input type="hidden" id="gross_salary" name="gross_salary" value="<?= htmlspecialchars($gross_salary) ?>">
						<td></td>
					</tr>

					<tr>
						<td>勞保費</td>
						<td class="deduction-amount " id="labor_insurance_display"><?= htmlspecialchars($labor_insurance) ?></td>
							<input type="hidden" id="labor_insurance" name="labor_insurance" value="<?= htmlspecialchars($labor_insurance) ?>"  required oninput="updateSalary()">
						<td>依據勞保級距表</td>
					</tr>
					<tr>
						<td>健保費</td>
						<td class="deduction-amount" id="health_insurance_display"><?= htmlspecialchars($health_insurance) ?></td>
							<input type="hidden" id="health_insurance" name="health_insurance" value="<?= htmlspecialchars($health_insurance) ?>"  required oninput="updateSalary()">
						<td>依據健保級距表</td>
					</tr>
					<tr id="row_leave_deduction" style="display: <?= $leave_deduction > 0 ? 'table-row' : 'none' ?>;">
						<td>請假扣除</td>
						<td>
							<span class="deduction-amount" id="leave_deduction_display"><?= htmlspecialchars($leave_deduction) ?></span>
							<input type="number" id="leave_deduction" name="leave_deduction" value="<?= htmlspecialchars($leave_deduction) ?>"   style="display: none;" required oninput="updateSalary()">
						</td>
						<td>
							
<?php foreach ($leave_deductions as $leave): ?>

    <?php 
        $subtype = htmlspecialchars($leave['subtype'] ?? '');
        $hours = $leave['hours'] ?? 0;
        $deduct = $leave['deduct'] ?? 0;
        $salary_ratio = (float)($leave['salary_ratio'] ?? 0);
        $deduct_percent = 100 - $salary_ratio;
        $start_date = $leave['start_only_date'] ?? date('Y-m-d', strtotime($leave['start_date']));
        $rate_string = $salary_ratio === 0
            ? "（全額扣除）"
            : "（薪資比 {$salary_ratio}%，扣除比例 {$deduct_percent}%）";
    ?>
    <?= "[{$start_date}] {$subtype}：{$hours} 小時 × 時薪 {$hourly_rate} × 扣除比例 {$deduct_percent}% = {$deduct} 元 {$rate_string}<br>"; ?>
<?php endforeach; ?>




						</td>
					</tr>
					<tr id="row_absent_deduction" style="display: <?= $absent_deduction > 0 ? 'table-row' : 'none' ?>;">
						<td>缺席扣除</td>
						<td>
							<span class="deduction-amount" id="absent_deduction_display"><?= htmlspecialchars($absent_deduction) ?></span>
							<input type="number" id="absent_deduction" name="absent_deduction" value="<?= htmlspecialchars($absent_deduction) ?>"  style="display: none;"  required oninput="updateSalary()"></td>
						<td>缺席時數: <?= $total_absent_minutes ?> × 換算時薪:<?= ceil($base_salary/240) ?></td>
					</tr>

					<!-- 總扣除自動計算 -->
					<tr class="bold-row">
						<td>總扣除</td>
						<td class="deduction-amount" id="total_deductions_display"><?= htmlspecialchars($total_deductions) ?></td>
						<input type="hidden" id="total_deductions" name="total_deductions" value="<?= htmlspecialchars($total_deductions) ?>">
						<td></td>
					</tr>

					<!-- 實領薪資自動計算 -->
					<tr class="bold-row">
						<td>實領薪資</td>
						<td class="double-underline" id="net_salary_display"><?= htmlspecialchars($net_salary) ?></td>
						<input type="hidden" id="net_salary" name="net_salary" value="<?= htmlspecialchars($net_salary) ?>">
						<td>總工資 - 總扣除</td>
					</tr>
				</table>
				 <!-- 建議加入：儲存與匯出按鈕改為 Bootstrap 樣式 -->
        <div class="my-4 d-flex gap-2">
			<button class="btn btn-success" id="save_salary_btn" style="display: none;">儲存薪資</button>
            <?php if ($salary_record): ?>
                <button type="button" class="btn btn-warning" id="edit_salary_btn" onclick="enableSalaryEditing()">修改薪資</button>
            <?php else: ?>
                <button type="submit" class="btn btn-success" name="save_salary" id="save_salary_btn">儲存薪資</button>
            <?php endif; ?>
            <button type="button" class="btn btn-secondary" id="export_button" onclick="exportToImage()">匯出圖片</button>
        </div>

			</form>
			


    </div>
	</div>

<script src="https://cdnjs.cloudflare.com/ajax/libs/html2canvas/1.4.1/html2canvas.min.js"></script>

<script>
// ✅ 第 1 點：初始化資料
const salaryData = {
    base_salary: <?= $base_salary ?>,
    meal_allowance: <?= $meal_allowance ?>,
    attendance_bonus: <?= $attendance_bonus ?>,
    position_bonus: <?= $position_bonus ?>,
    skill_bonus: <?= $skill_bonus ?>,
    vacation_cash: <?= $vacation_cash2 ?>,
    overtime_pay: <?= $overtime_pay ?>,
    labor_insurance: <?= $labor_insurance ?>,
    health_insurance: <?= $health_insurance ?>,
    leave_deduction: <?= $leave_deduction ?>,
    absent_deduction: <?= $absent_deduction ?>
};

// ✅ 第 2 點：啟用編輯模式
function enableSalaryEditing() {
    document.getElementById('edit_salary_btn').style.display = 'none';
    document.getElementById('save_salary_btn').style.display = 'inline-block';

    const fields = [
        'base_salary', 'meal_allowance', 'attendance_bonus', 
        'position_bonus', 'skill_bonus', 'vacation_cash', 'overtime_pay',
        'labor_insurance', 'health_insurance', 
        'leave_deduction', 'absent_deduction'
    ];

    fields.forEach(field => {
        const display = document.getElementById(`${field}_display`);
        const input = document.getElementById(`${field}`);

        if (display) display.style.display = 'none';
        if (input) input.style.display = 'inline-block';
    });
}

// ✅ 第 3 點：即時計算總工資、扣除與實領薪資
function updateSalaryFromInputs() {
    salaryData.base_salary = parseFloat(document.getElementById('base_salary')?.value || 0);
    salaryData.meal_allowance = parseFloat(document.getElementById('meal_allowance')?.value || 0);
    salaryData.attendance_bonus = parseFloat(document.getElementById('attendance_bonus')?.value || 0);
    salaryData.position_bonus = parseFloat(document.getElementById('position_bonus')?.value || 0);
    salaryData.skill_bonus = parseFloat(document.getElementById('skill_bonus')?.value || 0);
    salaryData.vacation_cash = parseFloat(document.getElementById('vacation_cash')?.value || 0);
    salaryData.overtime_pay = parseFloat(document.getElementById('overtime_pay')?.value || 0);
    salaryData.labor_insurance = parseFloat(document.getElementById('labor_insurance')?.value || 0);
    salaryData.health_insurance = parseFloat(document.getElementById('health_insurance')?.value || 0);
    salaryData.leave_deduction = parseFloat(document.getElementById('leave_deduction')?.value || 0);
    salaryData.absent_deduction = parseFloat(document.getElementById('absent_deduction')?.value || 0);

    const gross = 
        salaryData.base_salary +
        salaryData.meal_allowance +
        salaryData.attendance_bonus +
        salaryData.position_bonus +
        salaryData.skill_bonus +
        salaryData.vacation_cash +
        salaryData.overtime_pay;

    const deductions = 
        salaryData.labor_insurance +
        salaryData.health_insurance +
        salaryData.leave_deduction +
        salaryData.absent_deduction;

    const net = gross - deductions;

    // ✅ 更新畫面
    document.getElementById('gross_salary_display').textContent = gross.toFixed(0);
    document.getElementById('total_deductions_display').textContent = deductions.toFixed(0);
    document.getElementById('net_salary_display').textContent = net.toFixed(0);

    // ✅ 更新隱藏 input 傳給後端
    document.getElementById('gross_salary').value = gross.toFixed(0);
    document.getElementById('total_deductions').value = deductions.toFixed(0);
    document.getElementById('net_salary').value = net.toFixed(0);
}
</script>






</body>
</html>


