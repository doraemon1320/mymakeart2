<?php
// PHP 功能 0：啟動 Session 與連線
if (session_status() === PHP_SESSION_NONE) {
    session_start();
}

require_once "../db_connect.php";

// PHP 功能 1：登入與參數檢核
if (!isset($_SESSION['user'])) {
    header("Location: ../login.php");
    exit;
}

$task_id = isset($_GET['id']) ? (int)$_GET['id'] : 0;
if ($task_id <= 0) {
    die('無效的任務識別碼。');
}

$current_user_id = (int)($_SESSION['user']['id'] ?? 0);
$is_manager = !empty($_SESSION['user']['is_manager']);
$is_admin = isset($_SESSION['user']['role']) && $_SESSION['user']['role'] === 'admin';

// PHP 功能 2：載入任務基本資料
$task_sql = "
    SELECT
        t.id,
        t.title,
        t.description,
        t.notes,
        t.task_type_id,
        t.case_type_id,
        t.task_status_id,
        t.assigned_by,
        t.assigned_to,
        t.start_date,
        t.due_date,
        t.time_slot,
        t.attachment_path,
        t.created_at,
        t.updated_at,
        assigner.name AS assigner_name,
        assignee.name AS assignee_name,
        tt.name AS type_name,
        tt.code AS workflow_code,
        ts.name AS status_name,
        ts.step_order,
        ts.is_terminal,
        tc.name AS case_name
    FROM tasks t
    JOIN employees assigner ON assigner.id = t.assigned_by
    JOIN employees assignee ON assignee.id = t.assigned_to
    JOIN task_types tt ON t.task_type_id = tt.id
    JOIN task_statuses ts ON t.task_status_id = ts.id
    LEFT JOIN task_case_types tc ON tc.id = t.case_type_id
    WHERE t.id = ?
";

$task_stmt = $conn->prepare($task_sql);
$task_stmt->bind_param("i", $task_id);
$task_stmt->execute();
$task = $task_stmt->get_result()->fetch_assoc();
$task_stmt->close();

if (!$task) {
    die('找不到對應的任務。');
}

$can_view = (
    (int)$task['assigned_to'] === $current_user_id ||
    (int)$task['assigned_by'] === $current_user_id ||
    $is_manager ||
    $is_admin
);

if (!$can_view) {
    die('您沒有權限檢視此任務。');
}

// PHP 功能 3：整理流程節點
$status_sql = "SELECT id, name, step_order, is_terminal FROM task_statuses WHERE workflow_code = ? ORDER BY step_order";
$status_stmt = $conn->prepare($status_sql);
$status_stmt->bind_param("s", $task['workflow_code']);
$status_stmt->execute();
$status_result = $status_stmt->get_result();

$status_steps = [];
$max_step = 0;
while ($row = $status_result->fetch_assoc()) {
    $status_steps[] = [
        'id' => (int)$row['id'],
        'name' => $row['name'],
        'step_order' => (int)$row['step_order'],
        'is_terminal' => (int)$row['is_terminal'],
    ];
    $max_step = max($max_step, (int)$row['step_order']);
}
$status_stmt->close();

$current_step = (int)$task['step_order'];
$progress_percent = $max_step > 0 ? round(($current_step / $max_step) * 100) : 0;
$progress_bar_class = ((int)$task['is_terminal'] === 1) ? 'bg-success' : 'bg-warning text-dark';

// PHP 功能 4：處理附件與敘述
$attachment_path = $task['attachment_path'] ?? '';
$attachment_exists = false;
$attachment_url = '';
$attachment_type = '';

if ($attachment_path) {
    $relative_path = ltrim($attachment_path, '/');
    $full_path = __DIR__ . '/../' . $relative_path;
    if (file_exists($full_path)) {
        $attachment_exists = true;
        $attachment_url = '../' . $relative_path;
        $extension = strtolower(pathinfo($relative_path, PATHINFO_EXTENSION));
        if (in_array($extension, ['jpg', 'jpeg', 'png', 'gif', 'webp'], true)) {
            $attachment_type = 'image';
        } elseif ($extension === 'pdf') {
            $attachment_type = 'pdf';
        } else {
            $attachment_type = 'file';
        }
    }
}

$description = trim($task['description'] ?? '');
$notes = trim($task['notes'] ?? '');
?>
<!DOCTYPE html>
<html lang="zh-Hant">
<head>
    <meta charset="UTF-8">
    <title>任務詳情 - <?= htmlspecialchars($task['title']) ?></title>
    <link href="https://cdn.jsdelivr.net/npm/bootstrap@5.3.3/dist/css/bootstrap.min.css" rel="stylesheet">
    <link rel="stylesheet" href="employee_navbar.css">
    <style>
        body { background-color: #f6f7fb; }
        h1 { color: #345D9D; }
        .badge-status { background-color: #345D9D; }
        .info-card { border-left: 6px solid #345D9D; }
        .info-card.yellow { border-color: #FFCD00; }
        .info-card.pink { border-color: #E36386; }
        .timeline-step { border-left: 4px solid #D0D8EF; }
        .timeline-step.active { border-left-color: #345D9D; background-color: #eef2fb; }
        .timeline-step.completed { border-left-color: #FFCD00; }
        .attachment-preview { max-width: 280px; border: 3px solid #FFCD00; border-radius: 12px; }
        .section-title { color: #345D9D; font-weight: 700; letter-spacing: 0.05em; }
        .btn-outline-brand { color: #345D9D; border-color: #345D9D; }
        .btn-outline-brand:hover { background-color: #345D9D; color: #fff; }
    </style>
</head>
<body>
<?php include 'employee_navbar.php'; ?>
<div class="container py-4">
    <div class="d-flex justify-content-between align-items-center flex-wrap mb-4">
        <div>
            <h1 class="fw-bold mb-1">任務詳情</h1>
            <div class="text-muted">任務階段：第 <?= $current_step ?> / <?= $max_step ?> 階段</div>
        </div>
        <div class="d-flex gap-2">
            <a href="tasks_list.php" class="btn btn-outline-brand btn-sm">返回我的任務</a>
            <a href="tasks_assigned_by_me.php" class="btn btn-outline-brand btn-sm">返回我指派的任務</a>
        </div>
    </div>

    <div class="row g-3 mb-4">
        <div class="col-md-4">
            <div class="card shadow-sm info-card">
                <div class="card-body">
                    <div class="text-muted small">任務狀態</div>
                    <div class="d-flex align-items-center gap-2 mt-2">
                        <span class="badge badge-status"><?= htmlspecialchars($task['status_name']) ?></span>
                        <?php if ((int)$task['is_terminal'] === 1): ?>
                            <span class="badge bg-success">已結案</span>
                        <?php endif; ?>
                    </div>
                    <div class="progress mt-3" style="height: 14px;">
                        <div class="progress-bar <?= $progress_bar_class ?>" style="width: <?= $progress_percent ?>%;">
                            <?= $progress_percent ?>%
                        </div>
                    </div>
                    <div class="small text-muted mt-2">流程總計 <?= $max_step ?> 階段</div>
                </div>
            </div>
        </div>
        <div class="col-md-4">
            <div class="card shadow-sm info-card yellow">
                <div class="card-body">
                    <div class="text-muted small">案件與任務類型</div>
                    <div class="fw-semibold mt-2">案件：<?= $task['case_name'] ? htmlspecialchars($task['case_name']) : '—' ?></div>
                    <div class="fw-semibold mt-1">任務：<?= htmlspecialchars($task['type_name']) ?></div>
                    <div class="small text-muted mt-2">建立時間：<?= $task['created_at'] ?></div>
                </div>
            </div>
        </div>
        <div class="col-md-4">
            <div class="card shadow-sm info-card pink">
                <div class="card-body">
                    <div class="text-muted small">負責與指派資訊</div>
                    <div class="mt-2">指派者：<?= htmlspecialchars($task['assigner_name']) ?></div>
                    <div class="mt-1">負責人：<?= htmlspecialchars($task['assignee_name']) ?></div>
                    <div class="mt-2">開始日期：<?= $task['start_date'] ?></div>
                    <div class="mt-1">截止日期：<?= $task['due_date'] ?></div>
                    <div class="mt-1">時段：<?= $task['time_slot'] ? htmlspecialchars($task['time_slot']) : '—' ?></div>
                </div>
            </div>
        </div>
    </div>

    <div class="card shadow-sm border-0 mb-4">
        <div class="card-header table-primary fw-semibold">📄 任務說明</div>
        <div class="card-body">
            <?php if ($description !== ''): ?>
                <p class="mb-0" style="white-space: pre-line;"><?= htmlspecialchars($description) ?></p>
            <?php else: ?>
                <span class="text-muted">未提供任務說明。</span>
            <?php endif; ?>
        </div>
    </div>

    <div class="row g-4 mb-4">
        <div class="col-lg-6">
            <div class="card shadow-sm border-0 h-100">
                <div class="card-header table-primary fw-semibold">📝 備註紀錄</div>
                <div class="card-body">
                    <?php if ($notes !== ''): ?>
                        <p class="mb-0" style="white-space: pre-line;"><?= htmlspecialchars($notes) ?></p>
                    <?php else: ?>
                        <span class="text-muted">目前沒有備註紀錄。</span>
                    <?php endif; ?>
                </div>
            </div>
        </div>
        <div class="col-lg-6">
            <div class="card shadow-sm border-0 h-100">
                <div class="card-header table-primary fw-semibold">📎 任務附件</div>
                <div class="card-body d-flex align-items-center justify-content-center" style="min-height: 220px;">
                    <?php if ($attachment_exists): ?>
                        <?php if ($attachment_type === 'image'): ?>
                            <a href="<?= htmlspecialchars($attachment_url) ?>" target="_blank" class="text-decoration-none text-center">
                                <img src="<?= htmlspecialchars($attachment_url) ?>" alt="任務附件縮圖" class="attachment-preview mb-2">
                                <div class="small text-muted">點擊可查看原圖</div>
                            </a>
                        <?php elseif ($attachment_type === 'pdf'): ?>
                            <a href="<?= htmlspecialchars($attachment_url) ?>" class="btn btn-outline-brand" target="_blank">下載 PDF 附件</a>
                        <?php else: ?>
                            <a href="<?= htmlspecialchars($attachment_url) ?>" class="btn btn-outline-brand" target="_blank">下載附件</a>
                        <?php endif; ?>
                    <?php else: ?>
                        <span class="text-muted">尚未上傳附件。</span>
                    <?php endif; ?>
                </div>
            </div>
        </div>
    </div>

    <div class="card shadow-sm border-0 mb-4">
        <div class="card-header table-primary fw-semibold">🔄 流程節點</div>
        <div class="card-body">
            <?php if (!empty($status_steps)): ?>
                <div class="list-group">
                    <?php foreach ($status_steps as $step):
                        $is_current = $step['id'] === (int)$task['task_status_id'];
                        $is_finished = $step['step_order'] < $current_step;
                        $item_class = 'list-group-item timeline-step';
                        if ($is_current) {
                            $item_class .= ' active';
                        } elseif ($is_finished) {
                            $item_class .= ' completed';
                        }
                    ?>
                        <div class="<?= $item_class ?> d-flex justify-content-between align-items-center py-3">
                            <div>
                                <div class="fw-semibold">第 <?= $step['step_order'] ?> 階段：<?= htmlspecialchars($step['name']) ?></div>
                                <?php if ($step['is_terminal']): ?>
                                    <div class="small text-muted">此階段為結案節點</div>
                                <?php endif; ?>
                            </div>
                            <?php if ($is_current): ?>
                                <span class="badge bg-primary">目前位置</span>
                            <?php elseif ($is_finished): ?>
                                <span class="badge bg-warning text-dark">已完成</span>
                            <?php else: ?>
                                <span class="badge bg-light text-muted">待進行</span>
                            <?php endif; ?>
                        </div>
                    <?php endforeach; ?>
                </div>
            <?php else: ?>
                <span class="text-muted">此任務類型尚未設定流程節點。</span>
            <?php endif; ?>
        </div>
    </div>
</div>
</body>
</html>