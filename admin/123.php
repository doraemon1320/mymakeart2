<?php
session_start();

// 檢查是否登入
if (!isset($_SESSION['user']) || $_SESSION['user']['role'] !== 'admin') {
    header('Location: ../login.php');
    exit;
}

$conn = new mysqli('localhost', 'root', '', 'mymakeart');
if ($conn->connect_error) {
    die("資料庫連接失敗：" . $conn->connect_error);
}

// 設定預設年、月（預設為上個月）
$last_month = date('m', strtotime('first day of last month'));
$last_year = date('Y', strtotime('first day of last month'));

$year = $_GET['year'] ?? $last_year;
$month = $_GET['month'] ?? $last_month;
$employee_id = $_GET['employee_id'] ?? '';

// 獲取員工清單
$employee_result = $conn->query("SELECT * FROM employees ORDER BY id ASC");
$employee_list = $employee_result->fetch_all(MYSQLI_ASSOC);
$employee_query = $conn->prepare("SELECT employee_number, hire_date FROM employees WHERE id = ?");
$employee_query->bind_param('s', $employee_id);
$employee_query->execute();
$employee_result = $employee_query->get_result()->fetch_assoc();
$employee_number = $employee_result['employee_number'] ?? null;


$hire_date = $employee_result['hire_date'] ?? null;


// 取得員工薪資結構
$salary_query = $conn->prepare("SELECT * FROM salary_structure WHERE employee_id = ?");
$salary_query->bind_param('s', $employee_id);
$salary_query->execute();
$salary_result = $salary_query->get_result()->fetch_assoc();

$base_salary = $salary_result['base_salary'] ?? 0;
$meal_allowance = $salary_result['meal_allowance'] ?? 0;
$attendance_bonus = $salary_result['attendance_bonus'] ?? 0;
$position_bonus = $salary_result['position_bonus'] ?? 0;
$skill_bonus = $salary_result['skill_bonus'] ?? 0;
$health_insurance = $salary_result['health_insurance'] ?? 0;
$labor_insurance = $salary_result['labor_insurance'] ?? 0;

$start_date = "$year-$month-01";
$end_date = date('Y-m-t', strtotime($start_date)); // 當月最後一天


// 過濾為 0 的薪資項目
$salary_data = [
    '底薪' => $salary_result['base_salary'] ?? 0,
    '伙食費' => $salary_result['meal_allowance'] ?? 0,
    '全勤獎金' => $salary_result['attendance_bonus'] ?? 0,
    '職務加給' => $salary_result['position_bonus'] ?? 0,
    '技術津貼' => $salary_result['skill_bonus'] ?? 0,
    '健保費' => $salary_result['health_insurance'] ?? 0,
    '勞保費' => $salary_result['labor_insurance'] ?? 0
];
$salary_data = array_filter($salary_data, function ($value) { return $value > 0; });

// 只查詢核准通過的請假/加班申請
$request_query = $conn->prepare("SELECT type, subtype, reason, start_date, end_date, status FROM requests WHERE employee_number = ? AND status = 'approved' AND start_date BETWEEN ? AND ?");
$request_query->bind_param('sss', $employee_number, $start_date, $end_date);
$request_query->execute();
$approved_requests = $request_query->get_result()->fetch_all(MYSQLI_ASSOC);
// 查詢請假扣除計算
$leave_deduction = 0;
$leave_query = $conn->prepare("
    SELECT r.subtype, 
           SUM(TIMESTAMPDIFF(MINUTE, r.start_date, r.end_date)) AS total_minutes, 
           l.salary_ratio 
    FROM requests r 
    JOIN leave_types l ON r.subtype = l.name 
    WHERE r.employee_number = ? 
    AND r.status = 'approved' 
    AND YEAR(r.start_date) = ? 
    AND MONTH(r.start_date) = ? 
    GROUP BY r.subtype
");


$leave_query->bind_param('sss', $employee_number, $year, $month);
$leave_query->execute();
$leave_results = $leave_query->get_result()->fetch_all(MYSQLI_ASSOC);

foreach ($leave_results as $leave) {
    $leave_deduction += floor((($leave['total_minutes'] / 60)-1) * floor($base_salary / 240) * ((100-$leave['salary_ratio'])/100));
}

// 查詢缺席時數紀錄，並排除缺席時數為 0 的記錄
$absent_query = $conn->prepare("SELECT date, status_text, absent_minutes FROM saved_attendance WHERE employee_number = ? AND YEAR(date) = ? AND MONTH(date) = ? AND absent_minutes > 0");
$absent_query->bind_param('sss', $employee_number, $year, $month);
$absent_query->execute();
$absent_result = $absent_query->get_result()->fetch_all(MYSQLI_ASSOC);

$total_absent_minutes = 0;
foreach ($absent_result as $absent) {
    $total_absent_minutes += $absent['absent_minutes'];
}
$absent_deduction = floor(($total_absent_minutes / 60) * ($base_salary / 240));




// 查詢年度假別設定
$leave_type_query = $conn->query("SELECT name, days_per_year FROM leave_types");
$leave_types = $leave_type_query->fetch_all(MYSQLI_ASSOC);
$leave_count = [];
foreach ($leave_types as $leave) {
    $leave_count[$leave['name']] = [
        'total_days' => $leave['days_per_year'],
        'used_days' => 0
    ];
}

// 查詢當年度已使用的請假天數
$leave_usage_query = $conn->prepare("SELECT subtype, SUM(TIMESTAMPDIFF(DAY, start_date, end_date) + 1) AS used_days FROM requests WHERE employee_number = ? AND status = 'approved' AND YEAR(start_date) = ? GROUP BY subtype");
$leave_usage_query->bind_param('ss', $employee_number, $year);
$leave_usage_query->execute();
$used_leaves = $leave_usage_query->get_result()->fetch_all(MYSQLI_ASSOC);

foreach ($used_leaves as $used) {
    if ($used['used_days'] > 0 && isset($leave_count[$used['subtype']])) {
        $leave_count[$used['subtype']]['used_days'] = $used['used_days'];
    }
}
// 查詢 employee_vacation 資料
$vacation_query = $conn->prepare("SELECT total_vacation, used_vacation, converted_vacation, remaining_vacation FROM employee_vacation WHERE employee_number = ?");
$vacation_query->bind_param('s', $employee_number);
$vacation_query->execute();
$vacation_result = $vacation_query->get_result()->fetch_assoc();

$total_vacation = $vacation_result['total_vacation'] ?? 0;
$used_vacation = $vacation_result['used_vacation'] ?? 0;
$converted_vacation = $vacation_result['converted_vacation'] ?? 0;
$remaining_vacation = $vacation_result['remaining_vacation'] ?? 0;


$hire_datetime = DateTime::createFromFormat('Y-m-d', $hire_date);
$current_datetime = DateTime::createFromFormat('Y-m-d', "$year-$month-01");

// 計算年資（依入職月份計算）
$hire_datetime = new DateTime($hire_date);
$current_datetime = new DateTime("$year-$month-01");
$interval = $hire_datetime->diff($current_datetime);
$years_of_service = $interval->y;
$months_of_service = $interval->m;
$years_of_service_exact = round($years_of_service + ($months_of_service / 12), 1);

// 取得符合特休資格的條件
$vacation_query = $conn->query("SELECT years_of_service, days FROM annual_leave_policy ORDER BY years_of_service ASC");
$vacation_policies = $vacation_query->fetch_all(MYSQLI_ASSOC);

$vacation_days = 0;
$is_vacation_month = false;

foreach ($vacation_policies as $policy) {
    if ($policy['years_of_service'] == 0.5 && $months_of_service == 6) {
        $vacation_days = $policy['days'];
        $is_vacation_month = true;
        break;
    }
    if ($years_of_service_exact >= $policy['years_of_service'] && $hire_datetime->format('m') == $month) {
        $vacation_days = $policy['days'];
        $is_vacation_month = true;
    }
}
// 總計薪資
$gross_salary = $base_salary + $meal_allowance + $attendance_bonus + $position_bonus + $skill_bonus;
$total_deductions = $absent_deduction + $health_insurance + $labor_insurance;
$net_salary = $gross_salary - $total_deductions;


if ($_SERVER['REQUEST_METHOD'] === 'POST' && isset($_POST['save_salary'])) {
    $stmt = $conn->prepare("
        INSERT INTO employee_monthly_salary 
        (employee_id, year, month, base_salary, meal_allowance, attendance_bonus, position_bonus, skill_bonus, 
        gross_salary, health_insurance, labor_insurance, leave_deduction, absent_deduction, total_deductions, net_salary)
        VALUES (?, ?, ?, ?, ?, ?, ?, ?, ?, ?, ?, ?, ?, ?, ?)
        ON DUPLICATE KEY UPDATE 
        base_salary = VALUES(base_salary), meal_allowance = VALUES(meal_allowance), attendance_bonus = VALUES(attendance_bonus),
        position_bonus = VALUES(position_bonus), skill_bonus = VALUES(skill_bonus), gross_salary = VALUES(gross_salary),
        health_insurance = VALUES(health_insurance), labor_insurance = VALUES(labor_insurance),
        leave_deduction = VALUES(leave_deduction), absent_deduction = VALUES(absent_deduction),
        total_deductions = VALUES(total_deductions), net_salary = VALUES(net_salary), updated_at = CURRENT_TIMESTAMP
    ");
    
    $stmt->bind_param('iiiiiiiiiiiiiii', 
        $employee_id, $year, $month, $base_salary, $meal_allowance, $attendance_bonus, 
        $position_bonus, $skill_bonus, $gross_salary, $health_insurance, 
        $labor_insurance, $leave_deduction, $absent_deduction, $total_deductions, $net_salary
    );

    if ($stmt->execute()) {
        echo "<p class='success'>薪資數據已成功儲存！</p>";
    } else {
        echo "<p class='error'>儲存失敗：" . $stmt->error . "</p>";
    }
}

?>

<!DOCTYPE html>
<html lang="zh-TW">
<head>
    <meta charset="UTF-8">
    <meta name="viewport" content="width=device-width, initial-scale=1.0">
    <title>員工薪資報表</title>
    <link rel="stylesheet" href="styles.css">
    <link rel="stylesheet" href="admin_navbar.css">
</head>
<body>
    <?php include 'admin_navbar.php'; ?>
    <div class="container">
        <h1>員工薪資報表</h1>
		<form method="GET" action="">
            <label>選擇員工：</label>
            <select name="employee_id" required>
				<?php foreach ($employee_list as $employee): ?>
					<option value="<?= htmlspecialchars($employee['id']) ?>" <?= ($employee_id == $employee['id']) ? 'selected' : '' ?>>
						<?= htmlspecialchars($employee['id'] . ' - ' . $employee['name']) ?>
					</option>
				<?php endforeach; ?>
			</select>

            <label>年份：</label>
            <input type="number" name="year" value="<?= htmlspecialchars($year) ?>" required>
            <label>月份：</label>
            <input type="number" name="month" value="<?= htmlspecialchars($month) ?>" required>
            <button type="submit">查詢</button>
        </form>
		
        <h2>員工薪資結構</h2>
        <table>
            <?php foreach ($salary_data as $key => $value): ?>
                <tr><th><?= htmlspecialchars($key) ?></th><td><?= htmlspecialchars($value) ?></td></tr>
            <?php endforeach; ?>
        </table>
        
        <!-- 核准申請表 -->
        <?php if (!empty($approved_requests)): ?>
            <h2>當月核准的申請</h2>
            <table class="approved-requests">
                <tr><th>類型</th><th>假別</th><th>理由</th><th>起始日期與時間</th><th>結束日期與時間</th><th>狀態</th></tr>
                <?php foreach ($approved_requests as $request): ?>
                    <tr>
                        <td><?= htmlspecialchars($request['type']) ?></td>
                        <td><?= htmlspecialchars($request['subtype']) ?></td>
                        <td><?= htmlspecialchars($request['reason'] ?? '-') ?></td>
                        <td><?= htmlspecialchars($request['start_date']) ?></td>
                        <td><?= htmlspecialchars($request['end_date']) ?></td>
                        <td><?= $request['status'] === 'approved' ? '<span class="status-green">已核准</span>' : '<span class="status-red">未核准</span>' ?></td>
                    </tr>
                <?php endforeach; ?>
            </table>
        <?php endif; ?>

            </tbody>
        </table>
 		
	 	<?php if (!empty($used_leaves)): ?>
        <h2>各休假次數累計表</h2>
        <table>
            <tr><th>假別</th><th>年度可休天數</th><th>已使用天數</th><th>剩餘天數</th></tr>
            <?php foreach ($leave_count as $leave_type => $count): ?>
                <?php if ($count['used_days'] > 0): ?>
                    <tr>
                        <td><?= htmlspecialchars($leave_type) ?></td>
                        <td><?= htmlspecialchars($count['total_days']) ?></td>
                        <td><?= htmlspecialchars($count['used_days']) ?></td>
                        <td><?= htmlspecialchars(max(0, $count['total_days'] - $count['used_days'])) ?></td>
                    </tr>
                <?php endif; ?>
            <?php endforeach; ?>
        </table>
	  	<?php endif; ?>
		
		<?php if ($vacation_result): ?>
		<h2>員工特休管理</h2>
        <table>
            <tr><th>職涯累計特休天數</th><th>已休特休天數</th><th>已換錢特休天數</th><th>剩餘特休天數</th></tr>
            <tr>
                <td><?= htmlspecialchars($total_vacation) ?> 天</td>
                <td><?= htmlspecialchars($used_vacation) ?> 天</td>
                <td><?= htmlspecialchars($converted_vacation) ?> 天</td>
                <td><?= htmlspecialchars($remaining_vacation) ?> 天</td>
            </tr>
        </table>
		<?php endif; ?>
	 	<?php if (!empty($absent_result)): ?>
       <h2>本月缺席時數表</h2>
        <table>
            <tr><th>日期</th><th>狀態</th><th>缺席時數（分鐘）</th></tr>
            <?php foreach ($absent_result as $absent): ?>
                <tr>
                    <td><?= htmlspecialchars($absent['date']) ?></td>
                    <td><?= htmlspecialchars($absent['status_text']) ?></td>
                    <td><?= htmlspecialchars($absent['absent_minutes']) ?></td>
                </tr>
            <?php endforeach; ?>
        </table>
        
        <h2>總缺席時數</h2>
        <p>總計缺席：<?= htmlspecialchars($total_absent_minutes) ?> 分鐘</p>
		<?php endif; ?>
	
            <h2>特休假紀錄（年資達標月份顯示）</h2>
            <table>
                <tr><th>員工入職年月</th><th>年資</th><th>可取得特休天數</th></tr>
                <tr>
                    <td><?= htmlspecialchars($hire_datetime->format('Y-m')) ?></td>
                    <td><?= htmlspecialchars($years_of_service) ?> 年 <?= htmlspecialchars($months_of_service) ?> 個月</td>
                    <td><input type="number" name="vacation_days" value="<?= htmlspecialchars($vacation_days) ?>" required> 天</td>
                </tr>
            </table>
		<form method="POST" action="">
		<input type="hidden" name="employee_id" value="<?= htmlspecialchars($employee_id) ?>">
		<input type="hidden" name="year" value="<?= htmlspecialchars($year) ?>">
		<input type="hidden" name="month" value="<?= htmlspecialchars($month) ?>">
        <h2>員工本月應領薪資</h2>
		<table>
            <tr><th>項目</th><th>金額</th><th>計算方式</th></tr>
            <tr><td>底薪</td><td><input type="number" name="base_salary" value="<?= htmlspecialchars($gross_salary) ?>" required></td><td>固定薪額</td></tr>
			<tr><td>餐費</td><td><input type="number" name="meal_allowance" value="<?= htmlspecialchars($meal_allowance) ?>"></td><td>固定薪額</td></tr>
			<tr><td>全勤獎金</td><td><input type="number" name="attendance_bonus" value="<?= htmlspecialchars($attendance_bonus) ?>"></td><td>固定薪額</td></tr>
			<tr><td>職務津貼</td><td><input type="number" name="position_bonus" value="<?= htmlspecialchars($position_bonus) ?>"></td><td>固定薪額</td></tr>		<tr><td>技能津貼</td><td><input type="number" name="skill_bonus" value="<?= htmlspecialchars($skill_bonus) ?>"></td><td>固定薪額</td></tr>		
			<tr><td>總工資</td><td><input type="number" name="gross_salary" value="<?= htmlspecialchars($gross_salary) ?>"></td><td>固定薪額</td></tr>	
            <tr><td>勞保費</td><td><input type="number" name="labor_insurance" value="<?= htmlspecialchars($labor_insurance) ?>" required></td><td>固定金額</td></tr>
            <tr><td>健保費</td><td><input type="number" name="health_insurance" value="<?= htmlspecialchars($health_insurance) ?>" required></td><td>固定金額</td></tr>
            <tr><tr>
				<td>請假扣除</td>
				<td><input type="number" name="leave_deduction" value="<?= htmlspecialchars($leave_deduction) ?>" required></td>
				<td>
					<?php foreach ($leave_results as $leave): ?>
						<?= $leave['subtype'] ?>: <?= $leave['total_minutes']/60-1 ?? 0 ?> 小時 × 換算時薪: <?= floor($base_salary/240) ?> × 假別比率: <?= $leave['salary_ratio'] ?? 0 ?><br>
					<?php endforeach; ?>
				</td>
			</tr></tr>
            <tr><td>缺席扣除</td><td><input type="number" name="absent_deduction" value="<?= htmlspecialchars($absent_deduction) ?>" required></td><td>缺席時數: <?= $total_absent_minutes ?> × 換算時薪:<?= floor($base_salary/240) ?></td></tr>
			<tr><td>總扣除</td><td><input type="number" name="total_deductions" value="<?= htmlspecialchars($total_deductions) ?>"></td><td>固定薪額</td></tr>	
            <tr><td>實領薪資</td><td><input type="number" name="net_salary" value="<?= htmlspecialchars($net_salary) ?>"></td><td>應領薪資 - 總扣除</td></tr>
        </table>
		<button type="submit" name="save_salary">儲存薪資</button>
	</form>
    </div>

</body>
</html>