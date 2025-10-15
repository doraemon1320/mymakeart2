<?php
session_start();
header('Content-Type: application/json');

// ✅ 權限檢查：僅 admin 可操作
if (!isset($_SESSION['user']) || $_SESSION['user']['role'] !== 'admin') {
    echo json_encode(['success' => false, 'message' => '未授權操作']);
    exit;
}

// ✅ 接收資料
$data = json_decode(file_get_contents('php://input'), true);
$employee_number = $data['employee_number'] ?? '';
$attendance_data = $data['attendance_data'] ?? [];

if (!$employee_number || empty($attendance_data)) {
    echo json_encode(['success' => false, 'message' => '資料不完整']);
    exit;
}

// ✅ 資料庫連線
$conn = new mysqli('localhost', 'root', '', 'mymakeart');
if ($conn->connect_error) {
    echo json_encode(['success' => false, 'message' => '資料庫連接失敗', 'error' => $conn->connect_error]);
    exit;
}

foreach ($attendance_data as $row) {
    $date = $row['date'];
    $first_time_raw = $row['first_time'];
    $last_time_raw = $row['last_time'];
    $status_text = $row['status_text'] ?? '';
    $absent_minutes = intval($row['absent_minutes'] ?? 0);

    // ✅ 判斷是否為時間格式（HH:MM:SS），若不是則保留原始中文
    $first_time = preg_match('/^\d{2}:\d{2}(:\d{2})?$/', $first_time_raw) ? date('H:i:s', strtotime($first_time_raw)) : $first_time_raw;
    $last_time = preg_match('/^\d{2}:\d{2}(:\d{2})?$/', $last_time_raw) ? date('H:i:s', strtotime($last_time_raw)) : $last_time_raw;

    // ✅ 檢查是否已有資料
    $check_stmt = $conn->prepare("SELECT id FROM saved_attendance WHERE employee_number = ? AND date = ?");
    $check_stmt->bind_param("ss", $employee_number, $date);
    $check_stmt->execute();
    $check_result = $check_stmt->get_result();

    if ($check_result->num_rows > 0) {
        // ✅ 更新原有資料
        $update_stmt = $conn->prepare("UPDATE saved_attendance SET first_time = ?, last_time = ?, status_text = ?, absent_minutes = ? WHERE employee_number = ? AND date = ?");
        $update_stmt->bind_param("sssiss", $first_time, $last_time, $status_text, $absent_minutes, $employee_number, $date);
        if (!$update_stmt->execute()) {
            echo json_encode(['success' => false, 'message' => '更新失敗', 'error' => $update_stmt->error]);
            exit;
        }
    } else {
        // ✅ 新增資料
        $insert_stmt = $conn->prepare("INSERT INTO saved_attendance (employee_number, date, first_time, last_time, status_text, absent_minutes) VALUES (?, ?, ?, ?, ?, ?)");
        $insert_stmt->bind_param("sssssi", $employee_number, $date, $first_time, $last_time, $status_text, $absent_minutes);
        if (!$insert_stmt->execute()) {
            echo json_encode(['success' => false, 'message' => '新增失敗', 'error' => $insert_stmt->error]);
            exit;
        }
    }
}

echo json_encode(['success' => true]);
?>
