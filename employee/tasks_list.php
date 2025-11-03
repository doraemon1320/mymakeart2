<?php
// PHP 功能 0：啟動 Session 以維持登入狀態
if (session_status() === PHP_SESSION_NONE) {
    session_start();
}

require_once "../db_connect.php";

// PHP 功能 1：登入狀態確認
if (!isset($_SESSION['user'])) {
    header("Location: ../login.php");
    exit;
}

$employee_id = (int)$_SESSION['user']['id'];
$active_condition = "((employment_status IS NULL OR employment_status = '' OR employment_status NOT IN ('離職','已離職','Resigned','Terminated')) AND (resignation_date IS NULL OR resignation_date = '' OR resignation_date = '0000-00-00' OR resignation_date > CURDATE()))";

$assign_auth_stmt = $conn->prepare("SELECT id FROM employees WHERE id = ? AND {$active_condition} LIMIT 1");
$assign_auth_stmt->bind_param("i", $employee_id);
$assign_auth_stmt->execute();
$can_assign_task = (bool)$assign_auth_stmt->get_result()->fetch_assoc();
$assign_auth_stmt->close();

$active_tasks = [];
$completed_tasks = [];

// PHP 功能 2：載入任務狀態流程
$status_map = [];
$status_sql = "SELECT id, name, workflow_code, step_order, is_terminal FROM task_statuses ORDER BY workflow_code, step_order";
$status_result = $conn->query($status_sql);
while ($row = $status_result->fetch_assoc()) {
    $workflow = $row['workflow_code'];
    if (!isset($status_map[$workflow])) {
        $status_map[$workflow] = [];
    }
    $status_map[$workflow][] = $row;
}

// PHP 功能 3：查詢進行中任務
$active_sql = "
    SELECT
        t.id, t.title, t.description, t.start_date, t.due_date, t.time_slot,
        t.attachment_path, t.notes, t.updated_at, t.task_status_id,
        e.name AS assigner_name,
        tt.name AS type_name, tt.code AS type_code,
        ts.name AS status_name, ts.step_order, ts.is_terminal, ts.workflow_code,
        tc.name AS case_name,
        ts_max.max_step
    FROM tasks t
    JOIN employees e ON t.assigned_by = e.id
    JOIN task_types tt ON t.task_type_id = tt.id
    JOIN task_statuses ts ON t.task_status_id = ts.id
    LEFT JOIN task_case_types tc ON t.case_type_id = tc.id
    JOIN (
        SELECT workflow_code, MAX(step_order) AS max_step
        FROM task_statuses
        GROUP BY workflow_code
    ) ts_max ON ts.workflow_code = ts_max.workflow_code
    WHERE t.assigned_to = ? AND ts.is_terminal = 0
    ORDER BY t.due_date ASC, ts.step_order ASC
";

$stmt = $conn->prepare($active_sql);
$stmt->bind_param("i", $employee_id);
$stmt->execute();
$result = $stmt->get_result();
while ($row = $result->fetch_assoc()) {
    $active_tasks[] = $row;
}
$stmt->close();

// PHP 功能 4：查詢已完成任務
$completion_subquery = "SELECT task_id, employee_id, MAX(completed_at) AS completed_at FROM task_completions GROUP BY task_id, employee_id";

$completed_sql = "
    SELECT
        t.id, t.title, t.description, t.start_date, t.due_date, t.time_slot,
        t.attachment_path, t.notes, t.updated_at,
        e.name AS assigner_name,
        tt.name AS type_name, tt.code AS type_code,
        ts.name AS status_name, ts.step_order, ts.workflow_code,
        tc.name AS case_name,
        ts_max.max_step,
        COALESCE(c.completed_at, t.updated_at) AS completed_at
    FROM tasks t
    JOIN employees e ON t.assigned_by = e.id
    JOIN task_types tt ON t.task_type_id = tt.id
    JOIN task_statuses ts ON t.task_status_id = ts.id
    LEFT JOIN task_case_types tc ON t.case_type_id = tc.id
    JOIN (
        SELECT workflow_code, MAX(step_order) AS max_step
        FROM task_statuses
        GROUP BY workflow_code
    ) ts_max ON ts.workflow_code = ts_max.workflow_code
    LEFT JOIN ({$completion_subquery}) c ON c.task_id = t.id AND c.employee_id = ?
    WHERE t.assigned_to = ? AND ts.is_terminal = 1
    ORDER BY completed_at DESC, t.updated_at DESC
";

$stmt = $conn->prepare($completed_sql);
$stmt->bind_param("ii", $employee_id, $employee_id);
$stmt->execute();
$result = $stmt->get_result();
while ($row = $result->fetch_assoc()) {
    $completed_tasks[] = $row;
}
$stmt->close();

$active_count = count($active_tasks);
$completed_count = count($completed_tasks);
$overdue_count = 0;
$today = date('Y-m-d');

// PHP 功能 5：計算逾期與統計
foreach ($active_tasks as $task) {
    if ($task['due_date'] < $today) {
        $overdue_count++;
    }
}
?>
<!DOCTYPE html>
<html lang="zh-Hant">
<head>
    <meta charset="UTF-8">
    <title>我的任務清單</title>
    <link href="https://cdn.jsdelivr.net/npm/bootstrap@5.3.3/dist/css/bootstrap.min.css" rel="stylesheet">
    <link rel="stylesheet" href="employee_navbar.css">
    <style>
        body { background-color: #f6f7fb; }
        .summary-card { border-left: 6px solid #345D9D; }
        .summary-card.yellow { border-color: #FFCD00; }
        .summary-card.pink { border-color: #E36386; }
        .badge-status { background-color: #345D9D; }
        .badge-progress { background-color: #FFCD00; color: #1f1f1f; }
        .table thead.table-primary { background-color: #FFCD00; color: #1f1f1f; }
        .overdue-row { background-color: #fde2e7; }
    </style>
</head>
<body>
<?php include 'employee_navbar.php'; ?>
<div class="container py-4">
    <div class="d-flex justify-content-between align-items-center flex-wrap mb-3">
        <h2 class="fw-bold text-primary">🗂 我的任務清單</h2>
        <?php if ($can_assign_task): ?>
            <a href="assign_task.php" class="btn btn-outline-primary btn-sm">📌 指派任務</a>
        <?php else: ?>
            <span class="btn btn-outline-secondary btn-sm disabled" aria-disabled="true">📌 僅限在職同仁可指派</span>
        <?php endif; ?>
    </div>

    <div class="row g-3 mb-4">
        <div class="col-md-4">
            <div class="card shadow-sm summary-card">
                <div class="card-body">
                    <div class="text-muted text-uppercase small">進行中</div>
                    <div class="d-flex justify-content-between align-items-end">
                        <span class="display-6 fw-bold text-primary"><?= $active_count ?></span>
                        <span class="badge bg-light text-dark">任務</span>
                    </div>
                </div>
            </div>
        </div>
        <div class="col-md-4">
            <div class="card shadow-sm summary-card yellow">
                <div class="card-body">
                    <div class="text-muted text-uppercase small">逾期提醒</div>
                    <div class="d-flex justify-content-between align-items-end">
                        <span class="display-6 fw-bold text-warning"><?= $overdue_count ?></span>
                        <span class="badge bg-warning text-dark">需優先處理</span>
                    </div>
                </div>
            </div>
        </div>
        <div class="col-md-4">
            <div class="card shadow-sm summary-card pink">
                <div class="card-body">
                    <div class="text-muted text-uppercase small">已完成</div>
                    <div class="d-flex justify-content-between align-items-end">
                        <span class="display-6 fw-bold text-danger"><?= $completed_count ?></span>
                        <span class="badge bg-danger">結案紀錄</span>
                    </div>
                </div>
            </div>
        </div>
    </div>

    <div class="card shadow-sm border-0 mb-4">
        <div class="card-header table-primary fw-semibold">📌 進行中任務</div>
        <div class="card-body p-0">
            <?php if ($active_count > 0): ?>
                <div class="table-responsive">
                    <table class="table table-bordered align-middle mb-0">
                        <thead class="table-primary">
                            <tr class="text-center">
                                <th style="width:28%">任務名稱</th>
                                <th style="width:15%">案件類型</th>
                                <th style="width:15%">任務類型</th>
                                <th style="width:12%">截止日期</th>
                                <th style="width:15%">進度</th>
                                <th style="width:15%">操作</th>
                            </tr>
                        </thead>
                        <tbody>
                        <?php foreach ($active_tasks as $task):
                            $progress = $task['max_step'] > 0 ? round(($task['step_order'] / $task['max_step']) * 100) : 0;
                            $is_overdue = $task['due_date'] < $today;
                            $workflow_statuses = $status_map[$task['workflow_code']] ?? [];
                        ?>
                            <tr class="<?= $is_overdue ? 'overdue-row' : '' ?>">
                                <td>
                                    <a href="task_detail.php?id=<?= $task['id'] ?>" class="fw-semibold text-decoration-none text-primary">
                                        <?= htmlspecialchars($task['title']) ?>
                                    </a>
                                    <div class="small text-muted">由 <?= htmlspecialchars($task['assigner_name']) ?> 指派</div>
                                </td>
                                <td class="text-center">
                                    <?= $task['case_name'] ? htmlspecialchars($task['case_name']) : '<span class="text-muted">—</span>' ?>
                                </td>
                                <td class="text-center">
                                    <span class="badge badge-status"><?= htmlspecialchars($task['type_name']) ?></span>
                                </td>
                                <td class="text-center">
                                    <div><?= $task['due_date'] ?></div>
                                    <?php if ($is_overdue): ?>
                                        <span class="badge bg-danger mt-1">逾期</span>
                                    <?php endif; ?>
                                </td>
                                <td>
                                    <div class="progress" style="height: 16px;">
                                        <div id="progress-bar-<?= $task['id'] ?>" class="progress-bar bg-warning text-dark" role="progressbar" style="width: <?= $progress ?>%;">
                                            <?= $progress ?>%
                                        </div>
                                    </div>
                                    <div id="progress-text-<?= $task['id'] ?>" class="small text-muted text-center mt-1">目前 <?= htmlspecialchars($task['status_name']) ?>（第 <?= $task['step_order'] ?> / <?= $task['max_step'] ?> 階段）</div>
                                </td>
                                <td>
                                    <?php if (!empty($workflow_statuses)): ?>
                                        <select class="form-select form-select-sm status-select"
                                            data-task-id="<?= $task['id'] ?>"
                                            data-update-url="task_mark_done.php"
                                            data-redirect="tasks_list.php"
                                            data-progress-bar="progress-bar-<?= $task['id'] ?>"
                                            data-progress-text="progress-text-<?= $task['id'] ?>"
                                            data-max-step="<?= $task['max_step'] ?>"
                                            data-feedback-target="feedback-<?= $task['id'] ?>">
                                            <?php foreach ($workflow_statuses as $status): ?>
                                                <option value="<?= $status['id'] ?>"
                                                    data-order="<?= $status['step_order'] ?>"
                                                    data-terminal="<?= $status['is_terminal'] ?>"
                                                    <?= $status['id'] == $task['task_status_id'] ? 'selected' : '' ?>>
                                                    第 <?= $status['step_order'] ?> 階段－<?= htmlspecialchars($status['name']) ?><?= $status['is_terminal'] ? '（結案）' : '' ?>
                                                </option>
                                            <?php endforeach; ?>
                                        </select>
                                        <div id="feedback-<?= $task['id'] ?>" class="small text-muted mt-2"></div>
                                    <?php else: ?>
                                        <span class="badge bg-secondary">尚未設定流程</span>
                                    <?php endif; ?>
                                </td>
                            </tr>
                        <?php endforeach; ?>
                        </tbody>
                    </table>
                </div>
            <?php else: ?>
                <div class="p-4 text-muted">目前沒有尚未完成的任務。</div>
            <?php endif; ?>
        </div>
    </div>

    <div class="card shadow-sm border-0">
        <div class="card-header bg-secondary text-white fw-semibold">✅ 已完成任務</div>
        <div class="card-body p-0">
            <?php if ($completed_count > 0): ?>
                <div class="table-responsive">
                    <table class="table table-bordered align-middle mb-0">
                        <thead class="table-primary">
                            <tr class="text-center">
                                <th style="width:28%">任務名稱</th>
                                <th style="width:18%">案件類型</th>
                                <th style="width:18%">任務類型</th>
                                <th style="width:14%">截止日期</th>
                                <th style="width:12%">完成節點</th>
                                <th style="width:10%">完成時間</th>
                            </tr>
                        </thead>
                        <tbody>
                        <?php foreach ($completed_tasks as $task): ?>
                            <tr>
                                <td>
                                    <a href="task_detail.php?id=<?= $task['id'] ?>" class="fw-semibold text-decoration-none text-primary">
                                        <?= htmlspecialchars($task['title']) ?>
                                    </a>
                                    <div class="small text-muted">由 <?= htmlspecialchars($task['assigner_name']) ?> 指派</div>
                                </td>
                                <td class="text-center">
                                    <?= $task['case_name'] ? htmlspecialchars($task['case_name']) : '<span class="text-muted">—</span>' ?>
                                </td>
                                <td class="text-center">
                                    <span class="badge bg-success-subtle text-success"><?= htmlspecialchars($task['type_name']) ?></span>
                                </td>
                                <td class="text-center"><?= $task['due_date'] ?></td>
                                <td class="text-center">
                                    <span class="badge bg-success"><?= htmlspecialchars($task['status_name']) ?></span>
                                </td>
                                <td class="text-center"><?= $task['completed_at'] ?></td>
                            </tr>
                        <?php endforeach; ?>
                        </tbody>
                    </table>
                </div>
            <?php else: ?>
                <div class="p-4 text-muted">目前尚無已完成任務。</div>
            <?php endif; ?>
        </div>
    </div>
</div>
<script src="https://code.jquery.com/jquery-3.7.1.min.js"></script>
<script src="task_progress.js"></script>
</body>
</html>
