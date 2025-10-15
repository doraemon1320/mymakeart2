<?php
require_once "db_connect.php";
if (session_status() === PHP_SESSION_NONE) session_start();

// ✅ 權限驗證（主管）
if (!isset($_SESSION['user']) || $_SESSION['user']['role'] !== 'admin') {
    header('Location: ../login.php');
    exit;
}

// ✅ 接收表單資料
$emp_numbers   = $_POST['employee_number'] ?? [];
$reasons       = $_POST['reason'] ?? [];
$start_days    = $_POST['start_day'] ?? [];
$end_days      = $_POST['end_day'] ?? [];
$start_times   = $_POST['start_hour'] ?? [];
$end_times     = $_POST['end_hour'] ?? [];
$fulldays      = $_POST['fullday'] ?? [];

$now = date("Y-m-d H:i:s");
$successCount = 0;
$type = "加班";
$subtype = "加班";

// ✅ Bootstrap 開頭 HTML
echo "<!DOCTYPE html><html lang='zh-Hant'><head>
    <meta charset='UTF-8'>
    <title>送出結果</title>
    <link href='https://cdn.jsdelivr.net/npm/bootstrap@5.3.3/dist/css/bootstrap.min.css' rel='stylesheet'>
    </head><body class='p-4'><div class='container'>";

for ($i = 0; $i < count($emp_numbers); $i++) {
    $emp_no = trim($emp_numbers[$i] ?? '');
    $reason = trim($reasons[$i] ?? '');
    $start_day = trim($start_days[$i] ?? '');
    $end_day = trim($end_days[$i] ?? '');
    $is_fullday = array_key_exists($i, $fulldays) && $fulldays[$i] == '1';
    $start_time = $is_fullday ? null : trim($start_times[$i] ?? '');
    $end_time = $is_fullday ? null : trim($end_times[$i] ?? '');

    // ✅ 補結束日
    if ($is_fullday && $start_day && !$end_day) {
        $end_day = $start_day;
    }

    // ✅ 基本欄位檢查
    if (!$emp_no || !$start_day || !$end_day) {
        echo "<div class='alert alert-warning'>第 " . ($i + 1) . " 筆略過：缺少必要欄位</div>";
        continue;
    }

    if (!$is_fullday && ($start_time === '' || $end_time === '')) {
        echo "<div class='alert alert-warning'>第 " . ($i + 1) . " 筆略過：未填加班時間</div>";
        continue;
    }

    // ✅ 取得員工資料
    $stmt = $conn->prepare("SELECT id, name, shift_id FROM employees WHERE employee_number = ?");
    $stmt->bind_param("s", $emp_no);
    $stmt->execute();
    $emp = $stmt->get_result()->fetch_assoc();
    if (!$emp) {
        echo "<div class='alert alert-warning'>第 " . ($i + 1) . " 筆略過：找不到員工 {$emp_no}</div>";
        continue;
    }

    $employee_id = $emp['id'];
    $name = $emp['name'];
    $shift_id = $emp['shift_id'] ?? null;

    // ✅ 組合加班時間
    if ($is_fullday && $shift_id) {
        $stmt2 = $conn->prepare("SELECT start_time, end_time FROM shifts WHERE id = ?");
        $stmt2->bind_param("i", $shift_id);
        $stmt2->execute();
        $shift = $stmt2->get_result()->fetch_assoc();
        if (!$shift) {
            echo "<div class='alert alert-warning'>第 " . ($i + 1) . " 筆略過：班別不存在</div>";
            continue;
        }
        $start = $start_day . ' ' . $shift['start_time'];
        $end = $end_day . ' ' . $shift['end_time'];
    } else {
        $start = $start_day . ' ' . $start_time . ":00";
        $end = $end_day . ' ' . $end_time . ":00";
        $ts_start = strtotime($start);
        $ts_end = strtotime($end);
        if ($ts_end <= $ts_start || ($ts_end - $ts_start) < 1800) {
            echo "<div class='alert alert-warning'>第 " . ($i + 1) . " 筆略過：加班時數不足 0.5 小時</div>";
            continue;
        }
    }

    // ✅ 寫入資料庫
    $status = "Approved";
    $stmt = $conn->prepare("INSERT INTO requests (employee_id, employee_number, name, type, subtype, reason, start_date, end_date, status, created_at)
                            VALUES (?, ?, ?, ?, ?, ?, ?, ?, ?, ?)");
    if (!$stmt) {
        echo "<div class='alert alert-danger'>SQL 準備失敗：" . $conn->error . "</div>";
        continue;
    }
    $stmt->bind_param("isssssssss", $employee_id, $emp_no, $name, $type, $subtype, $reason, $start, $end, $status, $now);
    if ($stmt->execute()) {
        $successCount++;
    } else {
        echo "<div class='alert alert-danger'>第 " . ($i + 1) . " 筆寫入失敗：" . $stmt->error . "</div>";
        continue;
    }

    // ✅ 發送通知
    $msg = "主管已代你填寫加班：$start ~ $end";
    $stmt2 = $conn->prepare("INSERT INTO notifications (employee_number, message, is_read, created_at)
                             VALUES (?, ?, 0, ?)");
    $stmt2->bind_param("sss", $emp_no, $msg, $now);
    $stmt2->execute();
}

// ✅ 結果顯示
if ($successCount > 0) {
    echo "<div class='alert alert-success'>✅ 成功送出 {$successCount} 筆加班紀錄。</div>";
} else {
    echo "<div class='alert alert-danger'>❌ 沒有有效的加班資料被送出。</div>";
}

echo "<a class='btn btn-primary mt-3' href='manager_overtime_request.php'>返回加班表單</a>";
echo "</div></body></html>";
?>
