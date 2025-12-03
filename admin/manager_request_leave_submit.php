<?php
require_once "../db_connect.php";
if (session_status() === PHP_SESSION_NONE) {
    session_start();
}

// 【PHP-1】結果統計
$messages = [];
$success = 0;
$skipped = 0;

// 【PHP-1A】時間刻度驗證函式
function isValidHalfHour(string $time): bool
{
    return (bool)preg_match('/^(?:[01]\d|2[0-3]):(?:00|30)$/', $time);
}

// 【PHP-1B】班別時間工具
$defaultShift = [
    'start_time' => '09:00',
    'end_time' => '18:00',
    'break_start' => '12:00',
    'break_end' => '13:00',
];

function timeToMinutes(string $time): int
{
    [$hour, $minute] = array_map('intval', explode(':', $time));
    return $hour * 60 + $minute;
}

function buildShiftSegments(array $shift): array
{
    $start = timeToMinutes($shift['start_time'] ?? '00:00');
    $end = timeToMinutes($shift['end_time'] ?? '00:00');
    if ($start >= $end) {
        return [];
    }

    $segments = [];
    $breakStartRaw = isset($shift['break_start']) ? timeToMinutes($shift['break_start']) : null;
    $breakEndRaw = isset($shift['break_end']) ? timeToMinutes($shift['break_end']) : null;

    if ($breakStartRaw !== null && $breakEndRaw !== null) {
        $breakStart = min(max($breakStartRaw, $start), $end);
        $breakEnd = min(max($breakEndRaw, $start), $end);
        if ($breakEnd > $breakStart) {
            if ($breakStart > $start) {
                $segments[] = [$start, $breakStart];
            }
if ($breakEnd < $end) {
                $segments[] = [$breakEnd, $end];
            }
            if (empty($segments)) {
                $segments[] = [$start, $end];
            }
            return $segments;
        }
    }

    $segments[] = [$start, $end];
    return $segments;
}

function isTimeAllowedByShift(string $time, array $shift): bool
{
    $minutes = timeToMinutes($time);
    foreach (buildShiftSegments($shift) as [$segStart, $segEnd]) {
        if ($minutes >= $segStart && $minutes <= $segEnd) {
            return true;
        }
    }
    return false;
}

// 【PHP-1C】特休剩餘時數計算（依規則：取得 - 使用 - 轉現金，不分年度）
function fetchAnnualLeaveBalance(mysqli $conn, int $employeeId): array
{
    $grant_stmt = $conn->prepare("SELECT COALESCE(SUM(days),0) AS total_days, COALESCE(SUM(hours),0) AS total_hours FROM annual_leave_records WHERE employee_id = ? AND status = '取得'");
    $grant_stmt->bind_param('i', $employeeId);
    $grant_stmt->execute();
    $grant = $grant_stmt->get_result()->fetch_assoc() ?: ['total_days' => 0, 'total_hours' => 0];
    $grant_stmt->close();

    $used_stmt = $conn->prepare("SELECT COALESCE(SUM(days),0) AS total_days, COALESCE(SUM(hours),0) AS total_hours FROM annual_leave_records WHERE employee_id = ? AND status IN ('使用','轉現金')");
    $used_stmt->bind_param('i', $employeeId);
    $used_stmt->execute();
    $used = $used_stmt->get_result()->fetch_assoc() ?: ['total_days' => 0, 'total_hours' => 0];
    $used_stmt->close();

    $grantHours = ($grant['total_days'] * 8) + (float)$grant['total_hours'];
    $usedHours = ($used['total_days'] * 8) + (float)$used['total_hours'];
    $remainHours = max(0, $grantHours - $usedHours);

    return [
        'grant_hours' => $grantHours,
        'used_hours' => $usedHours,
        'remain_hours' => $remainHours,
    ];
}

if ($_SERVER['REQUEST_METHOD'] === 'POST') {
    // 【PHP-2】擷取表單陣列
    $employee_numbers = array_values($_POST['employee_number'] ?? []);
    $leave_types = array_values($_POST['subtype'] ?? []);
    $start_dates = array_values($_POST['start_date'] ?? []);
    $end_dates = array_values($_POST['end_date'] ?? []);
    $start_times = array_values($_POST['start_time'] ?? []);
    $end_times = array_values($_POST['end_time'] ?? []);
    $fulldays = $_POST['fullday'] ?? [];
    $reasons = array_values($_POST['reason'] ?? []);

    $rowCount = max(count($employee_numbers), count($leave_types), count($start_dates), count($end_dates));

    for ($i = 0; $i < $rowCount; $i++) {
        $employee_number = trim($employee_numbers[$i] ?? '');
        $subtype = trim($leave_types[$i] ?? '');
        $start_date = trim($start_dates[$i] ?? '');
        $end_date = trim($end_dates[$i] ?? '');
        $reason = trim($reasons[$i] ?? '');
        $start_time = trim($start_times[$i] ?? '');
        $end_time = trim($end_times[$i] ?? '');
        $is_fullday = !empty($fulldays[$i]);

        // 【PHP-3】判斷空列
        if ($employee_number === '' && $subtype === '' && $start_date === '' && $end_date === '' && $reason === '') {
            continue;
        }

        // 【PHP-4】基本欄位檢查
        $missing = [];
        if ($employee_number === '') $missing[] = '員工';
        if ($subtype === '') $missing[] = '假別';
        if ($start_date === '') $missing[] = '起始日';
        if ($end_date === '') $missing[] = '結束日';

        if ($is_fullday) {
            if ($start_time !== '' || $end_time !== '') {
                $missing[] = '整天請假不需填寫時間';
            }
        } else {
            if ($start_time === '') $missing[] = '起始時間';
            if ($end_time === '') $missing[] = '結束時間';
        }

        if (!empty($missing)) {
            $skipped++;
            $messages[] = "<div class='alert alert-warning'>第 " . ($i + 1) . " 筆略過：" . implode('、', $missing) . '</div>';
            continue;
        }

        if ($end_date < $start_date) {
            $skipped++;
            $messages[] = "<div class='alert alert-warning'>第 " . ($i + 1) . " 筆略過：結束日不可早於起始日</div>";
            continue;
        }

        if (!$is_fullday) {
            if ($start_date !== $end_date) {
                $skipped++;
                $messages[] = "<div class='alert alert-warning'>第 " . ($i + 1) . " 筆略過：跨日請假僅能勾選整天</div>";
                continue;
            }
            if ($start_time >= $end_time) {
                $skipped++;
                $messages[] = "<div class='alert alert-warning'>第 " . ($i + 1) . " 筆略過：結束時間需晚於起始時間</div>";
                continue;
            }
            if (!isValidHalfHour($start_time) || !isValidHalfHour($end_time)) {
                $skipped++;
                $messages[] = "<div class='alert alert-warning'>第 " . ($i + 1) . " 筆略過：時間需為 30 分鐘刻度</div>";
                continue;
            }
        }

        // 【PHP-5】查詢員工與班別
        $stmt = $conn->prepare("SELECT id, name, shift_id FROM employees WHERE employee_number = ?");
        $stmt->bind_param('s', $employee_number);
        $stmt->execute();
        $emp = $stmt->get_result()->fetch_assoc();
        $stmt->close();

        if (!$emp) {
            $skipped++;
            $messages[] = "<div class='alert alert-danger'>第 " . ($i + 1) . " 筆略過：找不到員工</div>";
            continue;
        }

        $employee_id = (int)$emp['id'];
        $employee_name = $emp['name'] ?? '';
        $shift_id = (int)$emp['shift_id'];

        $stmt = $conn->prepare("SELECT start_time, end_time, break_start, break_end FROM shifts WHERE id = ?");
        $stmt->bind_param('i', $shift_id);
        $stmt->execute();
        $shiftRow = $stmt->get_result()->fetch_assoc() ?: [];
        $stmt->close();

        $shift = [
            'start_time' => isset($shiftRow['start_time']) && $shiftRow['start_time'] !== '' ? $shiftRow['start_time'] : $defaultShift['start_time'],
            'end_time' => isset($shiftRow['end_time']) && $shiftRow['end_time'] !== '' ? $shiftRow['end_time'] : $defaultShift['end_time'],
            'break_start' => isset($shiftRow['break_start']) && $shiftRow['break_start'] !== '' ? $shiftRow['break_start'] : $defaultShift['break_start'],
            'break_end' => isset($shiftRow['break_end']) && $shiftRow['break_end'] !== '' ? $shiftRow['break_end'] : $defaultShift['break_end'],
        ];

        if (timeToMinutes($shift['start_time']) >= timeToMinutes($shift['end_time'])) {
            $skipped++;
            $messages[] = "<div class='alert alert-danger'>第 " . ($i + 1) . " 筆略過：班別時間設定有誤</div>";
            continue;
        }

        if ($is_fullday) {
            $start_time = $shift['start_time'];
            $end_time = $shift['end_time'];
        } else {
            if (!isTimeAllowedByShift($start_time, $shift) || !isTimeAllowedByShift($end_time, $shift)) {
                $skipped++;
                $messages[] = "<div class='alert alert-warning'>第 " . ($i + 1) . " 筆略過：時間需符合班別可請假時段</div>";
                continue;
            }
        }

        $shift_start = $shift['start_time'];
        $shift_end = $shift['end_time'];
        $break_start = $shift['break_start'];
        $break_end = $shift['break_end'];

        $start_dt = "$start_date $start_time";
        $end_dt = "$end_date $end_time";

        if (strtotime($start_dt) >= strtotime($end_dt)) {
            $skipped++;
            $messages[] = "<div class='alert alert-warning'>第 " . ($i + 1) . " 筆略過：起訖時間錯誤</div>";
            continue;
        }

        // 【PHP-6】計算請假時數
        $leave_hours = 0.0;
        $start_ts = strtotime($start_dt);
        $end_ts = strtotime($end_dt);
        $current = $start_ts;

        while ($current < $end_ts) {
            $cur_day = date('Y-m-d', $current);
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

            $current = strtotime('+1 day', strtotime($cur_day));
        }

        $leave_hours = round($leave_hours, 1);

        if ($leave_hours < 0.5) {
            $skipped++;
            $messages[] = "<div class='alert alert-info'>第 " . ($i + 1) . " 筆略過：請假不足 0.5 小時</div>";
            continue;
        }

        if (abs(($leave_hours * 60 / 30) - round($leave_hours * 60 / 30)) > 0.01) {
            $skipped++;
            $messages[] = "<div class='alert alert-warning'>第 " . ($i + 1) . " 筆略過：請假時數需為 30 分鐘倍數</div>";
            continue;
        }

        // 【PHP-6A】特休額度檢查：不得超過剩餘可用時數
        if ($subtype === '特休假') {
            $balance = fetchAnnualLeaveBalance($conn, $employee_id);
            if ($leave_hours - $balance['remain_hours'] > 0.0001) {
                $skipped++;
                $messages[] = "<div class='alert alert-warning'>第 " . ($i + 1) . " 筆略過：特休剩餘 " . number_format($balance['remain_hours'], 1) . " 小時，不足以抵扣本次申請</div>";
                continue;
            }
        }

        // 【PHP-7】寫入 requests
        $stmt = $conn->prepare("INSERT INTO requests (employee_id, employee_number, name, type, subtype, reason, start_date, end_date, status) VALUES (?, ?, ?, '請假', ?, ?, ?, ?, 'Approved')");
        $stmt->bind_param('issssss', $employee_id, $employee_number, $employee_name, $subtype, $reason, $start_dt, $end_dt);
        $stmt->execute();
        $stmt->close();

        // 【PHP-8】特休假紀錄
        if ($subtype === '特休假') {
            if ($is_fullday) {
                $days = (strtotime($end_date) - strtotime($start_date)) / 86400 + 1;
                for ($d = 0; $d < $days; $d++) {
                    $use_date = date('Y-m-d', strtotime("$start_date +$d days"));
                    $year = (int)date('Y', strtotime($use_date));
                    $month = (int)date('n', strtotime($use_date));
                    $day = (int)date('j', strtotime($use_date));
                    $stmt = $conn->prepare("INSERT INTO annual_leave_records (employee_id, year, month, day, days, status, created_at) VALUES (?, ?, ?, ?, 1, '使用', NOW())");
                    $stmt->bind_param('iiis', $employee_id, $year, $month, $day);
                    $stmt->execute();
                    $stmt->close();
                }
            } else {
                $year = (int)date('Y', strtotime($start_dt));
                $stmt = $conn->prepare("INSERT INTO annual_leave_records (employee_id, year, hours, status, created_at) VALUES (?, ?, ?, '使用', NOW())");
                $stmt->bind_param('iid', $employee_id, $year, $leave_hours);
                $stmt->execute();
                    $stmt->close();
                }
            } else {
                $year = (int)date('Y', strtotime($start_dt));
                $stmt = $conn->prepare("INSERT INTO annual_leave_records (employee_id, year, hours, status, created_at) VALUES (?, ?, ?, '使用', NOW())");
                $stmt->bind_param('iid', $employee_id, $year, $leave_hours);
                $stmt->execute();
                $stmt->close();
            }
        }

        $success++;
        $messages[] = "<div class='alert alert-success'>第 " . ($i + 1) . " 筆成功送出</div>";
    }
}

// 【PHP-9】呈現結果
?>
<!DOCTYPE html>
<html lang="zh-Hant">
<head>
  <meta charset="UTF-8">
  <title>請假送出結果</title>
  <link href="https://cdn.jsdelivr.net/npm/bootstrap@5.3.3/dist/css/bootstrap.min.css" rel="stylesheet">
</head>
<body class="bg-light py-4">
  <div class="container">
    <div class="card shadow-sm">
      <div class="card-header bg-primary text-white">
        <h4 class="mb-0">請假送出結果</h4>
      </div>
      <div class="card-body">
        <p class="text-muted">成功：<?= $success ?> 筆，略過：<?= $skipped ?> 筆</p>
        <?= implode("\n", $messages) ?>
        <hr>
        <a href="manager_request_leave.php" class="btn btn-outline-primary">返回請假頁面</a>
      </div>
    </div>
  </div>
</body>
</html>
