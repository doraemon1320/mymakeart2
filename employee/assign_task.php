<?php
// PHP 功能 0：確保啟動 Session
if (session_status() === PHP_SESSION_NONE) {
    session_start();
}

require_once "../db_connect.php";

// PHP 功能 1：登入狀態與權限確認
if (!isset($_SESSION['user'])) {
    header("Location: ../login.php");
    exit;
}

$assigner_id = (int)$_SESSION['user']['id'];
$is_admin = isset($_SESSION['user']['role']) && $_SESSION['user']['role'] === 'admin';

// PHP 功能 2：確認指派者為在職員工並取得所屬公司
$active_condition = "((employment_status IS NULL OR employment_status = '' OR employment_status NOT IN ('離職','已離職','Resigned','Terminated')) AND (resignation_date IS NULL OR resignation_date = '' OR resignation_date = '0000-00-00' OR resignation_date > CURDATE()))";
$assigner_stmt = $conn->prepare("SELECT company_id FROM employees WHERE id = ? AND {$active_condition} LIMIT 1");
$assigner_stmt->bind_param("i", $assigner_id);
$assigner_stmt->execute();
$assigner_data = $assigner_stmt->get_result()->fetch_assoc();
$assigner_stmt->close();

if (!$assigner_data) {
    die("僅限本公司在職同仁可指派任務，請聯絡管理者確認在職狀態。");
}

$company_id = isset($assigner_data['company_id']) ? (int)$assigner_data['company_id'] : null;

// PHP 功能 3：載入可指派的在職同仁清單
$employees = [];
$employees_sql = "SELECT id, name FROM employees WHERE id != ? AND {$active_condition}";
$bind_types = "i";
$bind_params = [$assigner_id];

if ($company_id > 0) {
    $employees_sql .= " AND company_id = ?";
    $bind_types .= "i";
    $bind_params[] = $company_id;
}

$employees_sql .= " ORDER BY name ASC";
$employees_stmt = $conn->prepare($employees_sql);
$employees_stmt->bind_param($bind_types, ...$bind_params);
$employees_stmt->execute();
$employees_result = $employees_stmt->get_result();
while ($row = $employees_result->fetch_assoc()) {
    $employees[] = [
        'id' => (int)$row['id'],
        'name' => $row['name']
    ];
}
$employees_stmt->close();
$has_assignable_employees = !empty($employees);

// PHP 功能 4：載入任務類型與預設節點
$task_types = [];
$type_map = [];
$task_types_result = $conn->query("SELECT id, code, name, description, default_status_id FROM task_types ORDER BY id ASC");
if ($task_types_result) {
    while ($row = $task_types_result->fetch_assoc()) {
        $task_types[] = $row;
        $type_map[$row['id']] = [
            'code' => $row['code'],
            'name' => $row['name'],
            'default_status_id' => (int)$row['default_status_id']
        ];
    }
}

// PHP 功能 5：載入案件類型清單
$case_types = [];
$case_types_result = $conn->query("SELECT id, name, description FROM task_case_types ORDER BY display_order ASC, id ASC");
if ($case_types_result) {
    while ($row = $case_types_result->fetch_assoc()) {
        $case_types[] = $row;
    }
}

// PHP 功能 6：整理多步驟流程節點
$status_by_workflow = [];
$status_result = $conn->query("SELECT id, workflow_code, step_order, name, description, is_terminal FROM task_statuses ORDER BY workflow_code ASC, step_order ASC");
if ($status_result) {
    while ($row = $status_result->fetch_assoc()) {
        $workflow = $row['workflow_code'];
        if (!isset($status_by_workflow[$workflow])) {
            $status_by_workflow[$workflow] = [];
        }
        $status_by_workflow[$workflow][] = [
            'id' => (int)$row['id'],
            'name' => $row['name'],
            'description' => $row['description'],
            'step_order' => (int)$row['step_order'],
            'is_terminal' => (int)$row['is_terminal']
        ];
    }
}

// PHP 功能 7：設定流程對應標題
$workflow_titles = [
    'construction' => '施工流程',
    'design' => '設計流程',
    'quotation' => '報價流程'
];
?>

<!DOCTYPE html>
<html lang="zh-Hant">
<head>
    <meta charset="UTF-8">
    <title>指派任務</title>
    <link href="https://cdn.jsdelivr.net/npm/bootstrap@5.3.3/dist/css/bootstrap.min.css" rel="stylesheet">
    <link rel="stylesheet" href="employee_navbar.css">
    <style>
        body {
            background-color: #f6f7fb;
        }
        .section-title {
            color: #345D9D;
            font-weight: 700;
            letter-spacing: 0.06em;
        }
        .card-header-brand {
            background: linear-gradient(90deg, #345D9D 0%, #FFCD00 100%);
            color: #1f1f1f;
            font-weight: 700;
        }
        .badge-flow {
            background-color: #E36386;
        }
        .status-helper {
            border-left: 6px solid #FFCD00;
            background-color: #fff7d6;
        }
        .table-status thead tr {
            background-color: #FFCD00;
        }
    </style>
</head>
<body>
<?php include 'employee_navbar.php'; ?>
<div class="container py-4">
    <div class="alert alert-info border-0 shadow-sm mb-4">
        🔐 管理者可更正所有任務，其他同仁僅能更正自己指派的任務，請於指派時備註重要資訊方便後續追蹤。
    </div>
    <?php if (!$has_assignable_employees): ?>
        <div class="alert alert-warning border-0 shadow-sm mb-4">
            ⚠️ 目前無符合條件的在職同仁可指派，請聯絡管理者確認人員在職狀態或公司別設定。
        </div>
    <?php endif; ?>
    <div class="row g-4 align-items-start">
        <div class="col-lg-7">
            <div class="card shadow-sm border-0">
                <div class="card-header card-header-brand">
                    <div class="d-flex justify-content-between align-items-center">
                        <span>📋 指派新任務</span>
                        <span class="badge text-bg-warning text-dark">快速建立</span>
                    </div>
                </div>
                <div class="card-body">
                    <form method="post" action="assign_task_submit.php" enctype="multipart/form-data" class="needs-validation" novalidate>
                        <div class="mb-3">
                            <label class="form-label fw-semibold">任務標題</label>
                            <input type="text" name="title" class="form-control" placeholder="例：保順里現場勘查" required>
                            <div class="invalid-feedback">請輸入任務標題</div>
                        </div>

                        <div class="mb-3">
                            <label class="form-label fw-semibold">任務說明</label>
                            <textarea name="description" class="form-control" rows="3" placeholder="補充任務背景、注意事項"></textarea>
                        </div>

                        <div class="row g-3">
                            <div class="col-md-6">
                                <label class="form-label fw-semibold">案件類型</label>
                                <select name="case_type_id" class="form-select">
                                    <option value="">（選填）請選擇案件類型</option>
                                    <?php foreach ($case_types as $case): ?>
                                        <option value="<?= $case['id'] ?>"><?= htmlspecialchars($case['name']) ?></option>
                                    <?php endforeach; ?>
                                </select>
                            </div>
                            <div class="col-md-6">
                                <label class="form-label fw-semibold">任務類型</label>
                                <select name="task_type_id" id="task_type_id" class="form-select" required>
                                    <?php foreach ($task_types as $type): ?>
                                        <option value="<?= $type['id'] ?>">
                                            <?= htmlspecialchars($type['name']) ?>
                                        </option>
                                    <?php endforeach; ?>
                                </select>
                            </div>
                        </div>

                        <div class="mt-3 p-3 rounded status-helper">
                            <div class="d-flex justify-content-between align-items-center">
                                <div>
                                    <span class="fw-semibold">任務狀態節點</span>
                                    <small class="text-muted d-block" id="statusHint">會依任務類型自動帶入預設節點，可視需求調整。</small>
                                </div>
                                <span class="badge rounded-pill badge-flow">流程控管</span>
                            </div>
                            <select name="task_status_id" id="task_status_id" class="form-select mt-3" required></select>
                        </div>

                        <div class="row g-3 mt-1">
                            <div class="col-md-6">
                                <label class="form-label fw-semibold">開始日期</label>
                                <input type="date" name="start_date" class="form-control" required>
                            </div>
                            <div class="col-md-6">
                                <label class="form-label fw-semibold">截止日期</label>
                                <input type="date" name="due_date" class="form-control" required>
                            </div>
                        </div>

                        <div class="row g-3 mt-1">
                            <div class="col-md-6">
                                <label class="form-label fw-semibold">預計時段</label>
                                <input type="text" name="time_slot" class="form-control" placeholder="例：09:00-12:00">
                            </div>
                            <div class="col-md-6">
                                <label class="form-label fw-semibold">任務附件</label>
                                <input type="file" name="attachment" class="form-control" accept=".jpg,.jpeg,.png,.gif,.webp,.pdf">
                                <div class="form-text text-muted">支援 JPG / PNG / GIF / WEBP / PDF，若無附件可留白。</div>
                            </div>
                        </div>

                        <div class="mt-3">
                            <label class="form-label fw-semibold">備註紀錄</label>
                            <textarea name="notes" class="form-control" rows="2" placeholder="可記錄額外提醒或檢核重點"></textarea>
                        </div>

                        <div class="mt-3">
                            <label class="form-label fw-semibold">指派給哪些員工（可多選）</label>
                            <select name="assigned_to[]" class="form-select" multiple required size="6" <?= $has_assignable_employees ? '' : 'disabled' ?>>
                                <?php if ($has_assignable_employees): ?>
                                    <?php foreach ($employees as $emp): ?>
                                        <option value="<?= $emp['id'] ?>"><?= htmlspecialchars($emp['name']) ?></option>
                                    <?php endforeach; ?>
                                <?php else: ?>
                                    <option value="">目前無可指派的在職同仁</option>
                                <?php endif; ?>
                            </select>
                            <small class="text-muted">按住 Ctrl 或 Command 可多選成員。<?= $has_assignable_employees ? '' : ' 若需協助請聯絡管理者。' ?></small>
                        </div>

                        <div class="text-end mt-4">
                            <button type="submit" class="btn btn-warning text-dark px-4" <?= $has_assignable_employees ? '' : 'disabled' ?>>✅ 送出任務</button>
                        </div>
                    </form>
                </div>
            </div>
        </div>
        <div class="col-lg-5">
            <h4 class="section-title mb-3">多步驟任務狀態節點</h4>
            <?php foreach ($status_by_workflow as $workflow => $steps): ?>
                <div class="card shadow-sm border-0 mb-3">
                    <div class="card-header table-primary fw-semibold">
                        <?= htmlspecialchars($workflow_titles[$workflow] ?? strtoupper($workflow)) ?>
                    </div>
                    <div class="card-body p-0">
                        <div class="table-responsive">
                            <table class="table table-bordered table-sm table-status align-middle mb-0">
                                <thead>
                                    <tr class="text-center">
                                        <th style="width:20%">步驟</th>
                                        <th style="width:35%">節點名稱</th>
                                        <th>追蹤重點</th>
                                    </tr>
                                </thead>
                                <tbody>
                                <?php foreach ($steps as $step): ?>
                                    <tr>
                                        <td class="text-center fw-semibold">第 <?= $step['step_order'] ?> 階段<?= $step['is_terminal'] ? '<br><span class="badge bg-success mt-2">結案</span>' : '' ?></td>
                                        <td><?= htmlspecialchars($step['name']) ?></td>
                                        <td><?= htmlspecialchars($step['description'] ?? '—') ?></td>
                                    </tr>
                                <?php endforeach; ?>
                                </tbody>
                            </table>
                        </div>
                    </div>
                </div>
            <?php endforeach; ?>
        </div>
    </div>
</div>

<script src="https://code.jquery.com/jquery-3.7.1.min.js"></script>
<script>
// JS 功能 1：任務類型切換時更新狀態節點
const typeMap = <?= json_encode($type_map, JSON_UNESCAPED_UNICODE) ?>;
const statusMap = <?= json_encode($status_by_workflow, JSON_UNESCAPED_UNICODE) ?>;

function updateStatusOptions(typeId) {
    const info = typeMap[typeId] || null;
    const workflowCode = info ? info.code : null;
    const $statusSelect = $('#task_status_id');
    const $statusHint = $('#statusHint');
    $statusSelect.empty();

    if (!workflowCode || !statusMap[workflowCode] || statusMap[workflowCode].length === 0) {
        $statusSelect.append('<option value="">目前流程尚未設定節點</option>');
        $statusSelect.prop('disabled', true);
        $statusHint.text('尚未設定此類型的任務節點，請先至系統設定補齊流程。');
        return;
    }

    $statusSelect.prop('disabled', false);
    const defaultId = info ? info.default_status_id : null;
    statusMap[workflowCode].forEach(function(status){
        const label = `第${status.step_order}階段－${status.name}${status.is_terminal ? '（結案）' : ''}`;
        const option = $('<option>').val(status.id).text(label);
        if (defaultId && Number(defaultId) === Number(status.id)) {
            option.prop('selected', true);
        }
        $statusSelect.append(option);
    });

    if (info) {
        $statusHint.text(`${info.name}：共 ${statusMap[workflowCode].length} 個節點，可依任務進度切換。`);
    }
}

// JS 功能 2：表單驗證與初始載入
$(function(){
    const firstTypeId = $('#task_type_id').val();
    updateStatusOptions(firstTypeId);

    $('#task_type_id').on('change', function(){
        updateStatusOptions($(this).val());
    });

    $('.needs-validation').on('submit', function(event) {
        if (!this.checkValidity()) {
            event.preventDefault();
            event.stopPropagation();
        }
        $(this).addClass('was-validated');
    });
});
</script>
</body>
</html>