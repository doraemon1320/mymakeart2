<?php
// PHP 功能 0：啟動 Session 與載入資料庫
if (session_status() === PHP_SESSION_NONE) {
    session_start();
}

require_once "../db_connect.php";

// PHP 功能 1：整理輸入參數與回應工具
$task_id = isset($_POST['task_id']) ? (int)$_POST['task_id'] : 0;
$new_status_id = isset($_POST['new_status_id']) ? (int)$_POST['new_status_id'] : 0;
$redirect = isset($_POST['redirect']) && trim($_POST['redirect']) !== '' ? trim($_POST['redirect']) : 'tasks_list.php';
$is_ajax = isset($_POST['ajax']) && $_POST['ajax'] === '1';

$respond = function (bool $success, string $message, array $extra = []) use ($is_ajax, $redirect) {
    if ($is_ajax) {
        header('Content-Type: application/json; charset=utf-8');
        echo json_encode(array_merge([
            'success' => $success,
            'message' => $message,
        ], $extra), JSON_UNESCAPED_UNICODE);
    } else {
        $_SESSION['task_update_feedback'] = [
            'success' => $success,
            'message' => $message,
        ];
        header("Location: {$redirect}");
    }
    exit;
};

// PHP 功能 2：登入檢核
if (!isset($_SESSION['user'])) {
    if ($is_ajax) {
        $respond(false, '尚未登入，請重新登入後再試。');
    }
    header("Location: ../login.php");
    exit;
}

$current_user_id = (int)($_SESSION['user']['id'] ?? 0);
$is_manager = !empty($_SESSION['user']['is_manager']);
$is_admin = isset($_SESSION['user']['role']) && $_SESSION['user']['role'] === 'admin';

if ($task_id <= 0 || $new_status_id <= 0) {
    $respond(false, '任務或狀態參數不足，無法更新。');
}

// PHP 功能 3：讀取任務與權限資訊
$task_sql = "
    SELECT
        t.id,
        t.assigned_by,
        t.assigned_to,
        t.task_status_id,
        tt.code AS workflow_code
    FROM tasks t
    JOIN task_types tt ON t.task_type_id = tt.id
    WHERE t.id = ?
";

$task_stmt = $conn->prepare($task_sql);
$task_stmt->bind_param("i", $task_id);
$task_stmt->execute();
$task_info = $task_stmt->get_result()->fetch_assoc();
$task_stmt->close();

if (!$task_info) {
    $respond(false, '找不到指定的任務。');
}

$can_update = (
    (int)$task_info['assigned_to'] === $current_user_id ||
    (int)$task_info['assigned_by'] === $current_user_id ||
    $is_manager ||
    $is_admin
);

if (!$can_update) {
    $respond(false, '您沒有權限調整此任務的狀態。');
}

$current_status_id = (int)$task_info['task_status_id'];
$workflow_code = $task_info['workflow_code'];

// PHP 功能 4：取得流程節點與最大階段
$status_sql = "SELECT id, name, step_order, is_terminal FROM task_statuses WHERE workflow_code = ? ORDER BY step_order ASC";
$status_stmt = $conn->prepare($status_sql);
$status_stmt->bind_param("s", $workflow_code);
$status_stmt->execute();
$status_result = $status_stmt->get_result();

$status_lookup = [];
$max_step = 0;
while ($row = $status_result->fetch_assoc()) {
    $status_id = (int)$row['id'];
    $status_lookup[$status_id] = [
        'name' => $row['name'],
        'step_order' => (int)$row['step_order'],
        'is_terminal' => (int)$row['is_terminal'],
    ];
    $max_step = max($max_step, (int)$row['step_order']);
}
$status_stmt->close();

if (empty($status_lookup)) {
    $respond(false, '尚未設定此任務類型的流程節點。');
}

if (!isset($status_lookup[$new_status_id])) {
    $respond(false, '指定的任務節點不存在或不屬於此流程。');
}

$selected_status = $status_lookup[$new_status_id];
$progress_percent = $max_step > 0 ? (int)round(($selected_status['step_order'] / $max_step) * 100) : 0;

// PHP 功能 5：必要時更新資料表
if ($new_status_id !== $current_status_id) {
    $conn->begin_transaction();

    $update_stmt = $conn->prepare("UPDATE tasks SET task_status_id = ?, updated_at = NOW() WHERE id = ?");
    $update_stmt->bind_param("ii", $new_status_id, $task_id);
    if (!$update_stmt->execute()) {
        $conn->rollback();
        $update_stmt->close();
        $respond(false, '更新任務狀態時發生錯誤，請稍後再試。');
    }
    $update_stmt->close();

    if ($selected_status['is_terminal'] === 1) {
        $check_stmt = $conn->prepare("SELECT id FROM task_completions WHERE task_id = ? AND employee_id = ? LIMIT 1");
        $check_stmt->bind_param("ii", $task_id, $task_info['assigned_to']);
        $check_stmt->execute();
        $already_done = $check_stmt->get_result()->fetch_assoc();
        $check_stmt->close();

        if (!$already_done) {
            $insert_stmt = $conn->prepare("INSERT INTO task_completions (task_id, employee_id, completed_at) VALUES (?, ?, NOW())");
            $insert_stmt->bind_param("ii", $task_id, $task_info['assigned_to']);
            if (!$insert_stmt->execute()) {
                $conn->rollback();
                $insert_stmt->close();
                $respond(false, '紀錄任務完成狀態時發生錯誤。');
            }
            $insert_stmt->close();
        }
    }

    $conn->commit();
}

// PHP 功能 6：回傳狀態結果
$respond(true, '任務狀態已更新。', [
    'status_id' => $new_status_id,
    'status_name' => $selected_status['name'],
    'step_order' => $selected_status['step_order'],
    'max_step' => $max_step,
    'progress_percent' => $progress_percent,
    'is_terminal' => $selected_status['is_terminal'],
    'refresh' => $selected_status['is_terminal'] === 1,
]);