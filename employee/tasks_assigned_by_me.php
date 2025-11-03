<?php
// PHP 功能 0：啟動 Session 與匯入連線
if (session_status() === PHP_SESSION_NONE) {
    session_start();
}

require_once "../db_connect.php";

// PHP 功能 1：登入檢核與在職確認
if (!isset($_SESSION['user'])) {
    header("Location: ../login.php");
    exit;
}

$user_id = (int)($_SESSION['user']['id'] ?? 0);
$is_admin = isset($_SESSION['user']['role']) && $_SESSION['user']['role'] === 'admin';
$is_manager = !empty($_SESSION['user']['is_manager']);

$active_condition = "((employment_status IS NULL OR employment_status = '' OR employment_status NOT IN ('離職','已離職','Resigned','Terminated')) AND (resignation_date IS NULL OR resignation_date = '' OR resignation_date = '0000-00-00' OR resignation_date > CURDATE()))";
$assign_auth_stmt = $conn->prepare("SELECT company_id FROM employees WHERE id = ? AND {$active_condition} LIMIT 1");
$assign_auth_stmt->bind_param("i", $user_id);
$assign_auth_stmt->execute();
$user_profile = $assign_auth_stmt->get_result()->fetch_assoc();
$assign_auth_stmt->close();

if (!$user_profile && !$is_admin && !$is_manager) {
    die('僅限在職同仁或管理者可檢視指派紀錄。');
}

$company_id = isset($user_profile['company_id']) ? (int)$user_profile['company_id'] : 0;

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

// PHP 功能 3：查詢我指派的任務
$assigned_sql = "
    SELECT
        t.id,
        t.title,
        t.description,
        t.start_date,
        t.due_date,
        t.time_slot,
        t.attachment_path,
        t.notes,
        t.updated_at,
        t.task_status_id,
        emp.id AS assignee_id,
        emp.name AS assignee_name,
        tt.name AS type_name,
        tt.code AS workflow_code,
        ts.name AS status_name,
        ts.step_order,
        ts.is_terminal,
        tc.name AS case_name,
        ts_max.max_step
    FROM tasks t
    JOIN employees emp ON t.assigned_to = emp.id
    JOIN task_types tt ON t.task_type_id = tt.id
    JOIN task_statuses ts ON t.task_status_id = ts.id
    LEFT JOIN task_case_types tc ON t.case_type_id = tc.id
    JOIN (
        SELECT workflow_code, MAX(step_order) AS max_step
        FROM task_statuses
        GROUP BY workflow_code
    ) ts_max ON ts.workflow_code = ts_max.workflow_code
    WHERE t.assigned_by = ?
    ORDER BY ts.is_terminal ASC, t.due_date ASC, emp.name ASC
";

$assigned_stmt = $conn->prepare($assigned_sql);
$assigned_stmt->bind_param("i", $user_id);
$assigned_stmt->execute();
$assigned_result = $assigned_stmt->get_result();

$ongoing_tasks = [];
$closed_tasks = [];
while ($row = $assigned_result->fetch_assoc()) {
    if ((int)$row['is_terminal'] === 1) {
        $closed_tasks[] = $row;
    } else {
        $ongoing_tasks[] = $row;
    }
}
$assigned_stmt->close();

// PHP 功能 4：計算統計資訊
$total_assigned = count($ongoing_tasks) + count($closed_tasks);
$active_count = count($ongoing_tasks);
$closed_count = count($closed_tasks);
$overdue_count = 0;
$today = date('Y-m-d');
foreach ($ongoing_tasks as $task) {
    if ($task['due_date'] < $today) {
        $overdue_count++;
    }
}
?>
<!DOCTYPE html>
<html lang="zh-Hant">
<head>
    <meta charset="UTF-8">
    <title>我指派的任務</title>
    <link href="https://cdn.jsdelivr.net/npm/bootstrap@5.3.3/dist/css/bootstrap.min.css" rel="stylesheet">
    <link rel="stylesheet" href="employee_navbar.css">
    <style>
        body { background-color: #f4f6fc; }
        h2 { color: #345D9D; }
        .summary-card { border-left: 6px solid #345D9D; }
        .summary-card.yellow { border-color: #FFCD00; }
        .summary-card.pink { border-color: #E36386; }
        .badge-status { background-color: #345D9D; }
        .table thead.table-primary { background-color: #FFCD00; color: #1f1f1f; }
        .overdue-row { background-color: #fde2e7; }
        .card-header.table-primary { background-color: #345D9D; color: #fff; }
    </style>
</head>
<body>
<?php include 'employee_navbar.php'; ?>
<div class="container py-4">
    <div class="d-flex justify-content-between align-items-center flex-wrap mb-3">
        <h2 class="fw-bold">🧭 我指派的任務管理</h2>
        <div class="btn-group">
            <a href="assign_task.php" class="btn btn-outline-primary btn-sm">➕ 指派新任務</a>
            <a href="tasks_list.php" class="btn btn-outline-secondary btn-sm">📋 查看我被指派的任務</a>
        </div>
    </div>

    <div class="row g-3 mb-4">
        <div class="col-md-3">
            <div class="card shadow-sm summary-card">
                <div class="card-body">
                    <div class="text-muted text-uppercase small">總指派數</div>
                    <div class="d-flex justify-content-between align-items-end">
                        <span class="display-6 fw-bold text-primary"><?= $total_assigned ?></span>
                        <span class="badge bg-light text-dark">任務</span>
                    </div>
                </div>
            </div>
        </div>
        <div class="col-md-3">
            <div class="card shadow-sm summary-card yellow">
                <div class="card-body">
                    <div class="text-muted text-uppercase small">進行中</div>
                    <div class="d-flex justify-content-between align-items-end">
                        <span class="display-6 fw-bold text-warning"><?= $active_count ?></span>
                        <span class="badge bg-warning text-dark">追蹤中</span>
                    </div>
                </div>
            </div>
        </div>
        <div class="col-md-3">
            <div class="card shadow-sm summary-card pink">
                <div class="card-body">
                    <div class="text-muted text-uppercase small">逾期提醒</div>
                    <div class="d-flex justify-content-between align-items-end">
                        <span class="display-6 fw-bold text-danger"><?= $overdue_count ?></span>
                        <span class="badge bg-danger">需留意</span>
                    </div>
                </div>
            </div>
        </div>
        <div class="col-md-3">
            <div class="card shadow-sm summary-card" style="border-color:#E36386;">
                <div class="card-body">
                    <div class="text-muted text-uppercase small">已結案</div>
                    <div class="d-flex justify-content-between align-items-end">
                        <span class="display-6 fw-bold text-success"><?= $closed_count ?></span>
                        <span class="badge bg-success">完成</span>
                    </div>
                </div>
            </div>
        </div>
    </div>

    <div class="card shadow-sm border-0 mb-4">
        <div class="card-header table-primary fw-semibold">🛠️ 進行中任務</div>
        <div class="card-body p-0">
            <?php if ($active_count > 0): ?>
                <div class="table-responsive">
                    <table class="table table-bordered align-middle mb-0">
                        <thead class="table-primary">
                        <tr class="text-center">
                            <th style="width:26%">任務名稱</th>
                            <th style="width:15%">案件類型</th>
                            <th style="width:15%">任務類型</th>
                            <th style="width:12%">截止日期</th>
                            <th style="width:15%">進度</th>
                            <th style="width:17%">操作</th>
                        </tr>
                        </thead>
                        <tbody>
                        <?php foreach ($ongoing_tasks as $task):
                            $progress = $task['max_step'] > 0 ? round(($task['step_order'] / $task['max_step']) * 100) : 0;
                            $is_overdue = $task['due_date'] < $today;
                            $workflow_statuses = $status_map[$task['workflow_code']] ?? [];
                        ?>
                            <tr class="<?= $is_overdue ? 'overdue-row' : '' ?>">
                                <td>
                                    <a href="task_detail.php?id=<?= $task['id'] ?>" class="fw-semibold text-decoration-none text-primary">
                                        <?= htmlspecialchars($task['title']) ?>
                                    </a>
                                    <div class="small text-muted">指派給 <?= htmlspecialchars($task['assignee_name']) ?></div>
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
                                        <div id="progress-bar-assign-<?= $task['id'] ?>" class="progress-bar bg-warning text-dark" role="progressbar" style="width: <?= $progress ?>%;">
                                            <?= $progress ?>%
                                        </div>
                                    </div>
                                    <div id="progress-text-assign-<?= $task['id'] ?>" class="small text-muted text-center mt-1">目前 <?= htmlspecialchars($task['status_name']) ?>（第 <?= $task['step_order'] ?> / <?= $task['max_step'] ?> 階段）</div>
                                </td>
                                <td>
                                    <?php if (!empty($workflow_statuses)): ?>
                                        <select class="form-select form-select-sm status-select"
                                            data-task-id="<?= $task['id'] ?>"
                                            data-update-url="task_mark_done.php"
                                            data-redirect="tasks_assigned_by_me.php"
                                            data-progress-bar="progress-bar-assign-<?= $task['id'] ?>"
                                            data-progress-text="progress-text-assign-<?= $task['id'] ?>"
                                            data-max-step="<?= $task['max_step'] ?>"
                                            data-feedback-target="assign-feedback-<?= $task['id'] ?>">
                                            <?php foreach ($workflow_statuses as $status): ?>
                                                <option value="<?= $status['id'] ?>"
                                                    data-order="<?= $status['step_order'] ?>"
                                                    data-terminal="<?= $status['is_terminal'] ?>"
                                                    <?= $status['id'] == $task['task_status_id'] ? 'selected' : '' ?>>
                                                    第 <?= $status['step_order'] ?> 階段－<?= htmlspecialchars($status['name']) ?><?= $status['is_terminal'] ? '（結案）' : '' ?>
                                                </option>
                                            <?php endforeach; ?>
                                        </select>
                                        <div id="assign-feedback-<?= $task['id'] ?>" class="small text-muted mt-2"></div>
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
                <div class="p-4 text-muted">目前沒有進行中的指派任務。</div>
            <?php endif; ?>
        </div>
    </div>

    <div class="card shadow-sm border-0">
        <div class="card-header bg-secondary text-white fw-semibold">🎯 已結案任務</div>
        <div class="card-body p-0">
            <?php if ($closed_count > 0): ?>
                <div class="table-responsive">
                    <table class="table table-bordered align-middle mb-0">
                        <thead class="table-primary">
                        <tr class="text-center">
                            <th style="width:26%">任務名稱</th>
                            <th style="width:18%">案件類型</th>
                            <th style="width:16%">任務類型</th>
                            <th style="width:15%">指派對象</th>
                            <th style="width:12%">截止日期</th>
                            <th style="width:13%">結案節點</th>
                        </tr>
                        </thead>
                        <tbody>
                        <?php foreach ($closed_tasks as $task): ?>
                            <tr>
                                <td>
                                    <a href="task_detail.php?id=<?= $task['id'] ?>" class="fw-semibold text-decoration-none text-primary">
                                        <?= htmlspecialchars($task['title']) ?>
                                    </a>
                                </td>
                                <td class="text-center">
                                    <?= $task['case_name'] ? htmlspecialchars($task['case_name']) : '<span class="text-muted">—</span>' ?>
                                </td>
                                <td class="text-center">
                                    <span class="badge bg-success-subtle text-success"><?= htmlspecialchars($task['type_name']) ?></span>
                                </td>
                                <td class="text-center"><?= htmlspecialchars($task['assignee_name']) ?></td>
                                <td class="text-center"><?= $task['due_date'] ?></td>
                                <td class="text-center">
                                    <span class="badge bg-success"><?= htmlspecialchars($task['status_name']) ?></span>
                                    <div class="small text-muted mt-1">更新於 <?= $task['updated_at'] ?></div>
                                </td>
                            </tr>
                        <?php endforeach; ?>
                        </tbody>
                    </table>
                </div>
            <?php else: ?>
                <div class="p-4 text-muted">目前尚無已結案的指派任務。</div>
            <?php endif; ?>
        </div>
    </div>
</div>
<script src="https://code.jquery.com/jquery-3.7.1.min.js"></script>
<script src="task_progress.js"></script>
</body>
</html>