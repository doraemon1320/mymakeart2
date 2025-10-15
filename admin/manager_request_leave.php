<?php
require_once "../db_connect.php";
if (session_status() === PHP_SESSION_NONE) session_start();

// ✅ 1. 權限檢查
if (!isset($_SESSION['user']) || $_SESSION['user']['role'] !== 'admin') {
    header("Location: ../login.php");
    exit;
}

// ✅ 2. 年度選擇邏輯
$year_now = date("Y");
$year_options = [$year_now, $year_now - 1, $year_now - 2];
$selected_year = $_GET['year'] ?? $year_now;

// ✅ 3. 抓假別清單
$leave_types = [];
$res_types = $conn->query("SELECT id, name, days_per_year FROM leave_types");
while ($row = $res_types->fetch_assoc()) {
    $leave_types[$row['id']] = [
        'name' => $row['name'],
        'days_per_year' => $row['days_per_year']
    ];
}

// ✅ 4. 有效員工清單
$employees_data = [];
$res = $conn->query("SELECT id, name, employee_number FROM employees WHERE role = 'employee' AND (resignation_date IS NULL OR resignation_date = '')");
while ($emp = $res->fetch_assoc()) {
    $employees_data[] = $emp;
}

// ✅ 第 5 點：員工假別統計（依據 requests + annual_leave_records 分開統計）
$summary_by_employee = [];

foreach ($employees_data as $emp) {
    $employee_id = $emp['id'];
    $employee_number = $emp['employee_number'];
    $summary_by_employee[$employee_number] = [];

    foreach ($leave_types as $type_id => $type_data) {
        $leave_name = $type_data['name'];
        $max = $type_data['days_per_year'];
        $used_days = 0;
        $used_hours = 0;

      
            // 🔹 其他假別從 requests 中查詢（跨年也納入）
            $stmt = $conn->prepare("
                SELECT start_date, end_date FROM requests
                WHERE employee_id = ? AND subtype = ? AND status = 'Approved'
                AND (
                    (YEAR(start_date) = ? OR YEAR(end_date) = ?)
                    OR (start_date <= ? AND end_date >= ?)
                )
            ");
            $year_start = "$selected_year-01-01 00:00:00";
            $year_end = "$selected_year-12-31 23:59:59";
            $stmt->bind_param("isssss", $employee_id, $leave_name, $selected_year, $selected_year, $year_end, $year_start);
            $stmt->execute();
            $result = $stmt->get_result();

            while ($row = $result->fetch_assoc()) {
                $s = new DateTime($row['start_date']);
                $e = new DateTime($row['end_date']);

                // ✅ 計算交集時間（跨年修正）
                $start = max($s, new DateTime($year_start));
                $end = min($e, new DateTime($year_end));
                if ($start > $end) continue;

                // 🕒 查詢班別
                $shift = [
                    'start' => '09:00', 'end' => '18:00',
                    'break_start' => '12:00', 'break_end' => '13:00'
                ];
                $emp_stmt = $conn->prepare("SELECT s.start_time, s.end_time, s.break_start, s.break_end FROM employees e JOIN shifts s ON e.shift_id = s.id WHERE e.id = ?");
                $emp_stmt->bind_param("i", $employee_id);
                $emp_stmt->execute();
                $shift_row = $emp_stmt->get_result()->fetch_assoc();
                if ($shift_row) $shift = $shift_row;

                // 🧮 計算總時數（以每個工作日計算）
                $period = new DatePeriod($start, new DateInterval('P1D'), (clone $end)->modify('+1 day'));
                foreach ($period as $date) {
                    $day_str = $date->format('Y-m-d');
                    $work_start = new DateTime("$day_str {$shift['start_time']}");
                    $work_end = new DateTime("$day_str {$shift['end_time']}");
                    $break_start = new DateTime("$day_str {$shift['break_start']}");
                    $break_end = new DateTime("$day_str {$shift['break_end']}");

                    // ⏰ 有效區間（限於請假範圍與班別範圍的交集）
                    $actual_start = max($work_start, $start);
                    $actual_end = min($work_end, $end);
                    if ($actual_start >= $actual_end) continue;

                    $total_minutes = ($actual_end->getTimestamp() - $actual_start->getTimestamp()) / 60;

                    // 🔸 扣除休息時間
                    if ($actual_start < $break_end && $actual_end > $break_start) {
                        $rest_start = max($actual_start, $break_start);
                        $rest_end = min($actual_end, $break_end);
                        $rest_minutes = ($rest_end->getTimestamp() - $rest_start->getTimestamp()) / 60;
                        $total_minutes -= max($rest_minutes, 0);
                    }

                    $used_hours += round($total_minutes / 60, 2);
                }
				
				// ✅ 特休假統計
// ✅ 先處理特休假：無年份限制，統計取得、使用、轉現金
// ✅ 特休假（跨年統計）— 天數與小時也需進位處理
$stmt = $conn->prepare("SELECT SUM(days) AS total_days, SUM(hours) AS total_hours 
                        FROM annual_leave_records 
                        WHERE employee_id = ? AND status IN ('使用','轉現金')");
$stmt->bind_param("i", $employee_id);
$stmt->execute();
$annual_used = $stmt->get_result()->fetch_assoc();
$used_annual_days_raw = intval($annual_used['total_days'] ?? 0);
$used_annual_hours_raw = floatval($annual_used['total_hours'] ?? 0);

// ✅ 進位處理（8 小時 = 1 天）
$total_used_hours = $used_annual_days_raw * 8 + $used_annual_hours_raw;
$used_annual_days = floor($total_used_hours / 8);
$used_annual_hours = round($total_used_hours - ($used_annual_days * 8), 1);

$stmt = $conn->prepare("SELECT SUM(days) AS total_grant 
                        FROM annual_leave_records 
                        WHERE employee_id = ? AND status = '取得'");
$stmt->bind_param("i", $employee_id);
$stmt->execute();
$grant = $stmt->get_result()->fetch_assoc();
$annual_limit = intval($grant['total_grant'] ?? 0);

// 若有取得過才顯示特休假
if ($annual_limit > 0 || $used_annual_days > 0 || $used_annual_hours > 0) {
    $total_used = $used_annual_days + round($used_annual_hours / 8, 3);
    $remain = max(0, $annual_limit - $total_used);
    $remain_days = floor($remain);
    $remain_hours = round(($remain - $remain_days) * 8, 1);

    $summary_by_employee[$employee_number]['特休假'] = [
        'limit' => $annual_limit,
        'used_days' => $used_annual_days,
        'used_hours' => $used_annual_hours,
        'remain_days' => $remain_days,
        'remain_hours' => $remain_hours,
    ];
}


            
        }

        // ✅ 若完全沒有請過則跳過
        if ($used_days === 0 && $used_hours === 0) continue;

        // ✅ 換算總時數 ➜ 天數 + 小時（每 8 小時為 1 天）
        $total_hours = $used_days * 8 + $used_hours;
        $final_used_days = floor($total_hours / 8);
        $final_used_hours = round($total_hours - ($final_used_days * 8), 1);

        $remain_total = max(0, $max * 8 - $total_hours);
        $remain_days = floor($remain_total / 8);
        $remain_hours = round($remain_total - $remain_days * 8, 1);

        $summary_by_employee[$employee_number][$leave_name] = [
            'limit' => $max,
            'used_days' => $final_used_days,
            'used_hours' => $final_used_hours,
            'remain_days' => $remain_days,
            'remain_hours' => $remain_hours,
        ];
    }
}





// ✅ 6. 員工班別時間
$shift_map = [];
$res_shift = $conn->query("SELECT e.employee_number, s.start_time, s.end_time FROM employees e JOIN shifts s ON e.shift_id = s.id");
while ($row = $res_shift->fetch_assoc()) {
    $shift_map[$row['employee_number']] = [
        'start_time' => $row['start_time'],
        'end_time' => $row['end_time']
    ];
}

// ✅ 7. JSON 傳入 JS
$shift_json = json_encode($shift_map);
$remain_json = json_encode($summary_by_employee);
$leave_json = json_encode(array_values(array_column($leave_types, 'name')));
$employee_json = json_encode($employees_data);
?>
<!DOCTYPE html>
<html lang="zh-Hant">
<head>
    <meta charset="UTF-8">
    <title>主管代填請假</title>
    <meta name="viewport" content="width=device-width, initial-scale=1">
    <link href="https://cdn.jsdelivr.net/npm/bootstrap@5.3.3/dist/css/bootstrap.min.css" rel="stylesheet">
    <script src="https://cdn.jsdelivr.net/npm/bootstrap@5.3.3/dist/js/bootstrap.bundle.min.js"></script>
    <link rel="stylesheet" href="admin_navbar.css">
</head>
<style>
select[readonly] {
  pointer-events: none;
  background-color: #f8f9fa;
  color: #6c757d;
}
</style>
<body>
<?php include "admin_navbar.php"; ?>

<div class="container mt-4">
    <h1 class="mb-3">主管代填請假</h1>

    <!-- 🔘 第一點：年份切換 -->
    <form method="get" class="mb-3">
        <label class="form-label">年份：</label>
        <select name="year" class="form-select w-auto d-inline" onchange="this.form.submit()">
            <?php foreach ($year_options as $y): ?>
                <option value="<?= $y ?>" <?= $y == $selected_year ? 'selected' : '' ?>><?= $y ?></option>
            <?php endforeach; ?>
        </select>
    </form>

    <!-- 🔘 第二點：假別手風琴統計 -->
    <div class="accordion mb-4" id="leaveSummaryAccordion">
        <?php foreach ($employees_data as $index => $emp): 
            $records = $summary_by_employee[$emp['employee_number']] ?? [];
        ?>
            <div class="accordion-item">
                <h2 class="accordion-header" id="heading<?= $index ?>">
                    <button class="accordion-button collapsed" type="button" data-bs-toggle="collapse"
                            data-bs-target="#collapse<?= $index ?>">
                        <?= $emp['employee_number'] ?> - <?= $emp['name'] ?>
                    </button>
                </h2>
                <div id="collapse<?= $index ?>" class="accordion-collapse collapse">
                    <div class="accordion-body">
                        <?php if (empty($records)): ?>
                            <div class="text-muted">📄 無請假紀錄</div>
                        <?php else: ?>
                            <table class="table table-bordered table-sm">
                                <thead class="table-light">
                                    <tr>
                                        <th>假別</th>
                                        <th>上限</th>
                                        <th>已用(天)</th>
                                        <th>已用(小時)</th>
                                        <th>剩餘(天)</th>
                                        <th>剩餘(小時)</th>
                                    </tr>
                                </thead>
                                <tbody>
                                    <?php foreach ($records as $type => $data): ?>
                                        <tr>
                                            <td><?= $type ?></td>
                                            <td><?= $data['limit'] ?></td>
                                            <td><?= $data['used_days'] ?></td>
                                            <td><?= $data['used_hours'] ?></td>
                                            <td><?= $data['remain_days'] ?></td>
                                            <td><?= $data['remain_hours'] ?></td>
                                        </tr>
                                    <?php endforeach; ?>
                                </tbody>
                            </table>
                        <?php endif; ?>
                    </div>
                </div>
            </div>
        <?php endforeach; ?>
    </div>

    <!-- 🔘 第三點：請假表單填寫區 -->
   <h5 class="bg-primary text-white p-2 rounded">請假表單</h5>
	<?php if (!empty($_SESSION['leave_submit_errors'])): ?>
    <div class="alert alert-danger">
        <h6 class="mb-2 fw-bold">⚠️ 請假資料送出失敗：</h6>
        <ul class="mb-0 ps-3">
            <?php foreach ($_SESSION['leave_submit_errors'] as $msg): ?>
                <li><?= htmlspecialchars($msg) ?></li>
            <?php endforeach; ?>
        </ul>
    </div>
    <?php unset($_SESSION['leave_submit_errors']); ?>
<?php endif; ?>

<?php if (!empty($_SESSION['leave_submit_success']) || !empty($_SESSION['leave_submit_skipped'])): ?>
    <div class="alert alert-info">
        ✅ 成功送出 <?= $_SESSION['leave_submit_success'] ?? 0 ?> 筆，
        略過 <?= $_SESSION['leave_submit_skipped'] ?? 0 ?> 筆
    </div>
    <?php unset($_SESSION['leave_submit_success'], $_SESSION['leave_submit_skipped']); ?>
<?php endif; ?>

	
<form method="post" action="manager_request_leave_submit.php" onsubmit="return validateForm()">
  <div class="table-responsive mb-3">
    <table class="table table-bordered text-center" id="leaveFormTable">
      <thead class="table-light">
        <tr>
          <th>員工</th>
          <th>假別</th>
          <th>起始日</th>
          <th>結束日</th>
          <th>整天</th>
          <th>起始時間</th>
          <th>結束時間</th>
          <th>原因</th>
        </tr>
      </thead>
      <tbody id="formContainer">
        <!-- JS 會自動載入預設 5 筆 -->
      </tbody>
    </table>
  </div>
  <div class="d-flex justify-content-between mb-4">
    <button type="button" class="btn btn-outline-secondary" onclick="addFormRow()">➕ 新增一筆</button>
    <button type="submit" class="btn btn-success">送出請假單</button>
  </div>
</form>

</div>

<!-- 🔘 JS 常數傳入 -->
<script>
const EMPLOYEES = <?= $employee_json ?>;
const LEAVETYPES = <?= $leave_json ?>;
const SHIFT_MAP = <?= $shift_json ?>;
const LEAVE_LIMIT = <?= $remain_json ?>;
</script>
<script src="manager_request_leave.js"></script>
</body>
</html>
