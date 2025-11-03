<?php
// PHP 功能 0：確保啟動 Session
if (session_status() === PHP_SESSION_NONE) {
    session_start();
}

require_once "../db_connect.php";

// PHP 功能 1：登入與指派者資格確認
if (!isset($_SESSION['user'])) {
    header("Location: ../login.php");
    exit;
}

$assigner_id = (int)$_SESSION['user']['id'];
$active_condition = "((employment_status IS NULL OR employment_status = '' OR employment_status NOT IN ('離職','已離職','Resigned','Terminated')) AND (resignation_date IS NULL OR resignation_date = '' OR resignation_date = '0000-00-00' OR resignation_date > CURDATE()))";
$assigner_stmt = $conn->prepare("SELECT company_id FROM employees WHERE id = ? AND {$active_condition} LIMIT 1");
$assigner_stmt->bind_param("i", $assigner_id);
$assigner_stmt->execute();
$assigner_data = $assigner_stmt->get_result()->fetch_assoc();
$assigner_stmt->close();

if (!$assigner_data) {
    die("僅限在職同仁可指派任務，請確認人員狀態後再試。");
}

$company_id = isset($assigner_data['company_id']) ? (int)$assigner_data['company_id'] : null;

// PHP 功能 2：整理表單欄位並檢核必填
$title = trim($_POST['title'] ?? '');
$description = trim($_POST['description'] ?? '');
$assigned_ids_input = $_POST['assigned_to'] ?? [];
$assigned_ids = array_unique(array_map('intval', (array)$assigned_ids_input));
$assigned_ids = array_values(array_filter($assigned_ids, function ($id) use ($assigner_id) {
    return $id > 0 && $id !== $assigner_id;
}));
$task_type_id = isset($_POST['task_type_id']) ? (int)$_POST['task_type_id'] : 0;
$case_type_id = isset($_POST['case_type_id']) && $_POST['case_type_id'] !== '' ? (int)$_POST['case_type_id'] : null;
$task_status_id = isset($_POST['task_status_id']) ? (int)$_POST['task_status_id'] : 0;
$start_date = $_POST['start_date'] ?? '';
$due_date = $_POST['due_date'] ?? '';
$time_slot = trim($_POST['time_slot'] ?? '');
$attachment_path = trim($_POST['attachment_path'] ?? '');
$notes = trim($_POST['notes'] ?? '');

if ($title === '' || empty($assigned_ids) || !$task_type_id || $start_date === '' || $due_date === '') {
    die("資料不足或未選擇在職同仁，請確認必填欄位。");
}

// PHP 功能 3：確認任務類型與預設節點
$type_stmt = $conn->prepare("SELECT id, code, default_status_id FROM task_types WHERE id = ?");
$type_stmt->bind_param("i", $task_type_id);
$type_stmt->execute();
$type_info = $type_stmt->get_result()->fetch_assoc();
$type_stmt->close();

if (!$type_info) {
    die("無效的任務類型");
}

$workflow_code = $type_info['code'];
$default_status_id = (int)$type_info['default_status_id'];

if ($case_type_id !== null) {
    $case_stmt = $conn->prepare("SELECT id FROM task_case_types WHERE id = ?");
    $case_stmt->bind_param("i", $case_type_id);
    $case_stmt->execute();
    if (!$case_stmt->get_result()->fetch_assoc()) {
        $case_type_id = null;
    }
    $case_stmt->close();
}

if ($task_status_id) {
    $status_stmt = $conn->prepare("SELECT id FROM task_statuses WHERE id = ? AND workflow_code = ?");
    $status_stmt->bind_param("is", $task_status_id, $workflow_code);
    $status_stmt->execute();
    if (!$status_stmt->get_result()->fetch_assoc()) {
        $task_status_id = $default_status_id;
    }
    $status_stmt->close();
} else {
    $task_status_id = $default_status_id;
}

// PHP 功能 4：檢核可指派名單僅含在職同仁
$placeholders = implode(',', array_fill(0, count($assigned_ids), '?'));
$verify_sql = "SELECT id FROM employees WHERE id IN ({$placeholders}) AND {$active_condition}";
$verify_types = str_repeat('i', count($assigned_ids));
$verify_params = $assigned_ids;

if ($company_id > 0) {
    $verify_sql .= " AND company_id = ?";
    $verify_types .= 'i';
    $verify_params[] = $company_id;
}

$verify_stmt = $conn->prepare($verify_sql);
$verify_stmt->bind_param($verify_types, ...$verify_params);
$verify_stmt->execute();
$verify_result = $verify_stmt->get_result();
$valid_ids = [];
while ($row = $verify_result->fetch_assoc()) {
    $valid_ids[] = (int)$row['id'];
}
$verify_stmt->close();

if (count($valid_ids) !== count($assigned_ids)) {
    die("僅能指派給本公司在職中的同仁，請重新確認名單。");
}

$time_slot = $time_slot !== '' ? $time_slot : null;
$attachment_path = $attachment_path !== '' ? $attachment_path : null;
$notes = $notes !== '' ? $notes : null;

$insert_sql = "
    INSERT INTO tasks (
        title, description, task_type_id, case_type_id, task_status_id,
        assigned_by, assigned_to, start_date, due_date, time_slot, attachment_path, notes
    ) VALUES (?, ?, ?, ?, ?, ?, ?, ?, ?, ?, ?, ?)
";

// PHP 功能 5：批次寫入任務資料
$conn->begin_transaction();
$stmt = $conn->prepare($insert_sql);

if (!$stmt) {
    $conn->rollback();
    die("指派任務時發生錯誤，請稍後再試。");
}

foreach ($assigned_ids as $employee_id) {
    $case_value = $case_type_id;
    $stmt->bind_param(
        "ssiiiiiissss",
        $title,
        $description,
        $task_type_id,
        $case_value,
        $task_status_id,
        $assigner_id,
        $employee_id,
        $start_date,
        $due_date,
        $time_slot,
        $attachment_path,
        $notes
    );
    if (!$stmt->execute()) {
        $conn->rollback();
        $stmt->close();
        die("指派任務時發生錯誤，請稍後再試。");
    }
}

$stmt->close();
$conn->commit();

header("Location: tasks_list.php");
exit;