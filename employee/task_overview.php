<?php
require_once "../db_connect.php";

// 登入與權限檢查
if (!isset($_SESSION['user']) || $_SESSION['user']['role'] !== 'admin') {
    header("Location: ../login.php");
    exit;
}

$supervisor_id = $_SESSION['user']['id'];
$selected_year = $_GET['year'] ?? date("Y");
$selected_month = $_GET['month'] ?? date("m");

// 遞迴抓取所有下屬
function getSubordinates($conn, $supervisor_id) {
    $list = [];
    $stmt = $conn->prepare("SELECT id FROM employees WHERE supervisor_id = ?");
    $stmt->bind_param("i", $supervisor_id);
    $stmt->execute();
    $result = $stmt->get_result();

    while ($row = $result->fetch_assoc()) {
        $list[] = $row['id'];
        $list = array_merge($list, getSubordinates($conn, $row['id']));
    }
    return $list;
}

$subordinate_ids = getSubordinates($conn, $supervisor_id);
if (empty($subordinate_ids)) $subordinate_ids = [-1]; // 防止 SQL 錯誤

$id_placeholders = implode(',', array_fill(0, count($subordinate_ids), '?'));

// 查詢所有下屬資料
$params = $subordinate_ids;
$types = str_repeat('i', count($params));

$stmt = $conn->prepare("SELECT id, name FROM employees WHERE id IN ($id_placeholders)");
$stmt->bind_param($types, ...$params);
$stmt->execute();
$employees_result = $stmt->get_result();
$employees = [];
while ($row = $employees_result->fetch_assoc()) {
    $employees[] = $row;
}
$stmt->close();

// 建立年份與月份選項
$year_options = range(date("Y") - 3, date("Y"));
$month_options = range(1, 12);

$workflow_labels = [
    'construction' => '施工',
    'design' => '設計',
    'quotation' => '報價'
];

$employee_stats = [];
foreach ($employees as $emp) {
    $eid = (int)$emp['id'];
    $workflow_stmt = $conn->prepare("SELECT ts.workflow_code, COUNT(*) AS total, SUM(CASE WHEN ts.is_terminal = 1 THEN 1 ELSE 0 END) AS done, SUM(CASE WHEN ts.is_terminal = 0 THEN 1 ELSE 0 END) AS active, SUM(CASE WHEN ts.is_terminal = 0 AND t.due_date < CURDATE() THEN 1 ELSE 0 END) AS overdue FROM tasks t JOIN task_statuses ts ON t.task_status_id = ts.id WHERE t.assigned_to = ? AND YEAR(t.due_date) = ? AND MONTH(t.due_date) = ? GROUP BY ts.workflow_code");
    $workflow_stmt->bind_param("iii", $eid, $selected_year, $selected_month);
    $workflow_stmt->execute();
    $workflow_result = $workflow_stmt->get_result();

    $total = 0;
    $done = 0;
    $active = 0;
    $overdue = 0;
    $workflows = [
        'construction' => ['active' => 0, 'done' => 0],
        'design' => ['active' => 0, 'done' => 0],
        'quotation' => ['active' => 0, 'done' => 0]
    ];

    while ($row = $workflow_result->fetch_assoc()) {
        $code = $row['workflow_code'];
        $total += (int)$row['total'];
        $done += (int)$row['done'];
        $active += (int)$row['active'];
        $overdue += (int)$row['overdue'];
        if (!isset($workflows[$code])) {
            $workflows[$code] = ['active' => 0, 'done' => 0];
        }
        $workflows[$code]['active'] = (int)$row['active'];
        $workflows[$code]['done'] = (int)$row['done'];
    }

    $workflow_stmt->close();

    $percent = $total > 0 ? round(($done / $total) * 100) : 0;

    $employee_stats[] = [
        'id' => $eid,
        'name' => $emp['name'],
        'total' => $total,
        'done' => $done,
        'active' => $active,
        'overdue' => $overdue,
        'percent' => $percent,
        'workflows' => $workflows
    ];
}
?>

<!DOCTYPE html>
<html lang="zh-Hant">
<head>
    <meta charset="UTF-8">
    <title>任務追蹤總覽</title>
    <link href="https://cdn.jsdelivr.net/npm/bootstrap@5.3.3/dist/css/bootstrap.min.css" rel="stylesheet">
    <link rel="stylesheet" href="employee_navbar.css">
    <style>
        body { background-color: #f6f7fb; }
        .table thead.table-primary { background-color: #FFCD00; color: #1f1f1f; }
        .card-header-brand { background: linear-gradient(90deg, #345D9D 0%, #FFCD00 100%); color: #1f1f1f; font-weight: 700; }
        .badge-workflow { background-color: #345D9D; }
        .badge-workflow.done { background-color: #28a745; }
        .stat-card { border-left: 6px solid #345D9D; }
    </style>
</head>
<body>
<?php include 'employee_navbar.php'; ?>
<div class="container py-4">
    <div class="d-flex justify-content-between align-items-center flex-wrap mb-4">
        <h2 class="fw-bold text-primary">📊 任務完成統計（<?= $selected_year ?> 年 <?= $selected_month ?> 月）</h2>
    </div>

    <form method="get" class="row g-3 mb-4">
        <div class="col-auto">
            <select name="year" class="form-select">
                <?php foreach ($year_options as $year): ?>
                    <option value="<?= $year ?>" <?= $selected_year == $year ? 'selected' : '' ?>><?= $year ?></option>
                <?php endforeach; ?>
            </select>
        </div>
        <div class="col-auto">
            <select name="month" class="form-select">
                <?php foreach ($month_options as $month): ?>
                    <option value="<?= $month ?>" <?= $selected_month == $month ? 'selected' : '' ?>><?= $month ?></option>
                <?php endforeach; ?>
            </select>
        </div>
        <div class="col-auto">
            <button class="btn btn-primary">查詢</button>
        </div>
    </form>

    <div class="card shadow-sm border-0 mb-4">
        <div class="card-header card-header-brand">任務量表格</div>
        <div class="card-body p-0">
            <?php if (!empty($employee_stats)): ?>
                <div class="table-responsive">
                    <table class="table table-bordered align-middle mb-0">
                        <thead class="table-primary text-center">
                            <tr>
                                <th rowspan="2">員工</th>
                                <th rowspan="2">總任務</th>
                                <th rowspan="2">進行中</th>
                                <th rowspan="2">已完成</th>
                                <th rowspan="2">逾期</th>
                                <th colspan="2">施工流程</th>
                                <th colspan="2">設計流程</th>
                                <th colspan="2">報價流程</th>
                            </tr>
                            <tr>
                                <th>進行中</th>
                                <th>結案</th>
                                <th>進行中</th>
                                <th>結案</th>
                                <th>進行中</th>
                                <th>結案</th>
                            </tr>
                        </thead>
                        <tbody class="text-center">
                        <?php foreach ($employee_stats as $stat): ?>
                            <tr>
                                <td class="text-start fw-semibold"><?= htmlspecialchars($stat['name']) ?></td>
                                <td><?= $stat['total'] ?></td>
                                <td><?= $stat['active'] ?></td>
                                <td><?= $stat['done'] ?></td>
                                <td><?= $stat['overdue'] ?></td>
                                <td><?= $stat['workflows']['construction']['active'] ?></td>
                                <td><?= $stat['workflows']['construction']['done'] ?></td>
                                <td><?= $stat['workflows']['design']['active'] ?></td>
                                <td><?= $stat['workflows']['design']['done'] ?></td>
                                <td><?= $stat['workflows']['quotation']['active'] ?></td>
                                <td><?= $stat['workflows']['quotation']['done'] ?></td>
                            </tr>
                        <?php endforeach; ?>
                        </tbody>
                    </table>
                </div>
            <?php else: ?>
                <div class="p-4 text-muted">目前沒有可統計的下屬任務資料。</div>
            <?php endif; ?>
        </div>
    </div>

    <?php foreach ($employee_stats as $stat): ?>
        <div class="card shadow-sm border-0 mb-4">
            <div class="card-body">
                <div class="d-flex justify-content-between align-items-center flex-wrap mb-3">
                    <h5 class="fw-bold text-primary mb-0"><?= htmlspecialchars($stat['name']) ?></h5>
                    <span class="badge bg-light text-dark">完成率 <?= $stat['percent'] ?>%</span>
                </div>
                <div class="row g-3 mb-3">
                    <div class="col-md-3">
                        <div class="card stat-card shadow-sm border-0 h-100">
                            <div class="card-body">
                                <div class="text-muted small">總任務</div>
                                <div class="display-6 fw-bold text-primary"><?= $stat['total'] ?></div>
                            </div>
                        </div>
                    </div>
                    <div class="col-md-3">
                        <div class="card stat-card shadow-sm border-0 h-100" style="border-color:#FFCD00;">
                            <div class="card-body">
                                <div class="text-muted small">進行中</div>
                                <div class="display-6 fw-bold text-warning"><?= $stat['active'] ?></div>
                            </div>
                        </div>
                    </div>
                    <div class="col-md-3">
                        <div class="card stat-card shadow-sm border-0 h-100" style="border-color:#28a745;">
                            <div class="card-body">
                                <div class="text-muted small">已完成</div>
                                <div class="display-6 fw-bold text-success"><?= $stat['done'] ?></div>
                            </div>
                        </div>
                    </div>
                    <div class="col-md-3">
                        <div class="card stat-card shadow-sm border-0 h-100" style="border-color:#E36386;">
                            <div class="card-body">
                                <div class="text-muted small">逾期</div>
                                <div class="display-6 fw-bold text-danger"><?= $stat['overdue'] ?></div>
                            </div>
                        </div>
                    </div>
                </div>
                <div class="progress mb-3" style="height: 18px;">
                    <div class="progress-bar bg-success" style="width: <?= $stat['percent'] ?>%">
                        <?= $stat['percent'] ?>%
                    </div>
                </div>
                <div class="d-flex flex-wrap gap-2">
                    <?php foreach ($stat['workflows'] as $code => $data): ?>
                        <span class="badge badge-workflow">
                            <?= $workflow_labels[$code] ?? strtoupper($code) ?> 進行中 <?= $data['active'] ?> 件
                        </span>
                        <span class="badge badge-workflow done">
                            <?= $workflow_labels[$code] ?? strtoupper($code) ?> 結案 <?= $data['done'] ?> 件
                        </span>
                    <?php endforeach; ?>
                </div>
            </div>
        </div>
    <?php endforeach; ?>
</div>
</body>
</html>