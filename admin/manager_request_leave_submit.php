<?php
require_once "../db_connect.php";
if (session_status() === PHP_SESSION_NONE) session_start();

$messages = [];
$success = 0;
$skipped = 0;

if ($_SERVER["REQUEST_METHOD"] === "POST") {
    $employee_numbers = $_POST['employee_number'] ?? [];
    $leave_types = $_POST['subtype'] ?? [];
    $start_dates = $_POST['start_date'] ?? [];
    $end_dates = $_POST['end_date'] ?? [];
    $start_times = $_POST['start_time'] ?? [];
    $end_times = $_POST['end_time'] ?? [];
    $fulldays = $_POST['fullday'] ?? [];
    $reasons = $_POST['reason'] ?? [];

    foreach ($employee_numbers as $i => $employee_number) {
        $subtype = trim($leave_types[$i] ?? '');
        $start_date = trim($start_dates[$i] ?? '');
        $end_date = trim($end_dates[$i] ?? '');
        $reason = trim($reasons[$i] ?? '');
        $is_fullday = isset($fulldays[$i]) ? 1 : 0;

        $start_time = $start_times[$i] ?? '';
        $end_time = $end_times[$i] ?? '';

        // ✅ 檢查欄位
        $missing = [];
        if (!$employee_number) $missing[] = "員工";
        if (!$subtype) $missing[] = "假別";
        if (!$start_date) $missing[] = "起始日";
        if (!$end_date) $missing[] = "結束日";
        if (!$is_fullday && ($start_time === "" || $end_time === "")) {
            $missing[] = "起訖時間";
        }

        if (!empty($missing)) {
            $skipped++;
            $messages[] = "<div class='alert alert-warning'>第 " . ($i + 1) . " 筆略過：缺少欄位（" . implode("、", $missing) . "）</div>";
            continue;
        }

        // 🔍 員工與班別查詢
        $stmt = $conn->prepare("SELECT id, name, shift_id FROM employees WHERE employee_number = ?");
        $stmt->bind_param("s", $employee_number);
        $stmt->execute();
        $emp = $stmt->get_result()->fetch_assoc();
        if (!$emp) {
            $skipped++;
            $messages[] = "<div class='alert alert-danger'>第 " . ($i + 1) . " 筆略過：找不到員工</div>";
            continue;
        }

        $employee_id = $emp['id'];
        $employee_name = $emp['name'] ?? '';
        $shift_id = $emp['shift_id'];

        $stmt = $conn->prepare("SELECT start_time, end_time, break_start, break_end FROM shifts WHERE id = ?");
        $stmt->bind_param("i", $shift_id);
        $stmt->execute();
        $shift = $stmt->get_result()->fetch_assoc();
        if (!$shift) {
            $skipped++;
            $messages[] = "<div class='alert alert-danger'>第 " . ($i + 1) . " 筆略過：找不到班別設定</div>";
            continue;
        }

        $shift_start = $shift['start_time'];
        $shift_end = $shift['end_time'];
        $break_start = $shift['break_start'];
        $break_end = $shift['break_end'];

        // 整天或跨日 ➜ 使用班別時間
        if ($is_fullday || $start_date !== $end_date) {
            $start_time = $shift_start;
            $end_time = $shift_end;
            $is_fullday = 1;
        }

        $start_dt = "$start_date $start_time";
        $end_dt = "$end_date $end_time";

        if (strtotime($start_dt) >= strtotime($end_dt)) {
            $skipped++;
            $messages[] = "<div class='alert alert-warning'>第 " . ($i + 1) . " 筆略過：起訖時間錯誤</div>";
            continue;
        }

        // ✅ 計算請假時數
        $leave_hours = 0;
        $start_ts = strtotime($start_dt);
        $end_ts = strtotime($end_dt);
        $current = $start_ts;

        while ($current < $end_ts) {
            $cur_day = date("Y-m-d", $current);
            $cur_start = strtotime("$cur_day $shift_start");
            $cur_end = strtotime("$cur_day $shift_end");
            $cur_break_start = strtotime("$cur_day $break_start");
            $cur_break_end = strtotime("$cur_day $break_end");

            $range_start = max($current, $cur_start);
            $range_end = min($end_ts, $cur_end);

            if ($range_end > $range_start) {
                $duration = $range_end - $range_start;
                $break_overlap = max(0, min($range_end, $cur_break_end) - max($range_start, $cur_break_start));
                $leave_hours += ($duration - $break_overlap) / 3600;
            }

            $current = strtotime("+1 day", strtotime($cur_day));
        }

        $leave_hours = round($leave_hours, 1);
        if ($leave_hours < 0.5) {
            $skipped++;
            $messages[] = "<div class='alert alert-info'>第 " . ($i + 1) . " 筆略過：請假不足 0.5 小時</div>";
            continue;
        }

        // ✅ 寫入 requests（⚠ 修正參數數量為 7）
        $stmt = $conn->prepare("INSERT INTO requests (employee_id, employee_number, name, type, subtype, reason, start_date, end_date, status)
                                VALUES (?, ?, ?, '請假', ?, ?, ?, ?, 'Approved')");
        $stmt->bind_param("issssss", $employee_id, $employee_number, $employee_name, $subtype, $reason, $start_dt, $end_dt);
        $stmt->execute();

        // ✅ 寫入 annual_leave_records（特休）
        if ($subtype === "特休假") {
            if ($is_fullday) {
                $days = (strtotime($end_date) - strtotime($start_date)) / 86400 + 1;
                for ($d = 0; $d < $days; $d++) {
                    $use_date = date("Y-m-d", strtotime($start_date . " +$d days"));
                    $year = date("Y", strtotime($use_date));
                    $month = date("n", strtotime($use_date));
                    $day = date("j", strtotime($use_date));
                    $stmt = $conn->prepare("INSERT INTO annual_leave_records (employee_id, year, month, day, days, status, created_at)
                                            VALUES (?, ?, ?, ?, 1, '使用', NOW())");
                    $stmt->bind_param("iiis", $employee_id, $year, $month, $day);
                    $stmt->execute();
                }
            } else {
                $year = date("Y", strtotime($start_dt));
                $stmt = $conn->prepare("INSERT INTO annual_leave_records (employee_id, year, hours, status, created_at)
                                        VALUES (?, ?, ?, '使用', NOW())");
                $stmt->bind_param("iid", $employee_id, $year, $leave_hours);
                $stmt->execute();
            }
        }

        $success++;
        $messages[] = "<div class='alert alert-success'>第 " . ($i + 1) . " 筆成功送出</div>";
    }
}

// ✅ 顯示結果頁面
?>
<!DOCTYPE html>
<html lang="zh-Hant">
<head>
  <meta charset="UTF-8">
  <title>請假送出結果</title>
  <link href="https://cdn.jsdelivr.net/npm/bootstrap@5.3.3/dist/css/bootstrap.min.css" rel="stylesheet">
</head>
<body class="p-4">
  <div class="container">
    <h2 class="mb-3">📋 請假送出結果</h2>
    <?= implode("\n", $messages) ?>
    <hr>
    <a href="manager_request_leave.php" class="btn btn-primary">⬅ 返回請假頁面</a>
  </div>
</body>
</html>
