<?php
require_once "../db_connect.php";
session_start();

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

// 建立年份與月份選項
$year_options = range(date("Y") - 3, date("Y"));
$month_options = range(1, 12);
?>

<!DOCTYPE html>
<html lang="zh-Hant">
<head>
    <meta charset="UTF-8">
    <title>任務追蹤總覽</title>
    <link href="https://cdn.jsdelivr.net/npm/bootstrap@5.3.0/dist/css/bootstrap.min.css" rel="stylesheet">
</head>
<body class="bg-light">
<div class="container py-4">
    <h2 class="mb-4">📊 任務完成統計（<?= $selected_year ?> 年 <?= $selected_month ?> 月）</h2>

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

    <div class="card">
        <div class="card-body">
            <?php while ($emp = $employees_result->fetch_assoc()):
                $eid = $emp['id'];
                $stmt = $conn->prepare("
                    SELECT 
                        COUNT(*) AS total,
                        SUM(CASE WHEN c.id IS NOT NULL THEN 1 ELSE 0 END) AS done,
                        SUM(CASE WHEN c.id IS NULL AND t.due_date < CURDATE() THEN 1 ELSE 0 END) AS overdue
                    FROM tasks t
                    LEFT JOIN task_completions c ON t.id = c.task_id AND c.employee_id = ?
                    WHERE t.assigned_to = ?
                      AND YEAR(t.due_date) = ?
                      AND MONTH(t.due_date) = ?
                ");
                $stmt->bind_param("iiii", $eid, $eid, $selected_year, $selected_month);
                $stmt->execute();
                $stats = $stmt->get_result()->fetch_assoc();

                $total = $stats['total'];
                $done = $stats['done'];
                $overdue = $stats['overdue'];
                $percent = $total > 0 ? round(($done / $total) * 100) : 0;
            ?>
                <div class="mb-4">
                    <h5><?= htmlspecialchars($emp['name']) ?></h5>
                    <div class="mb-1">
                        ✅ 完成 <?= $done ?> / 🔢 總共 <?= $total ?> 
                        <?= $overdue > 0 ? " ⏰ 逾期 $overdue 筆" : "" ?>
                    </div>
                    <div class="progress">
                        <div class="progress-bar bg-success" style="width: <?= $percent ?>%">
                            <?= $percent ?>%
                        </div>
                    </div>
                </div>
            <?php endwhile; ?>
        </div>
    </div>
</div>
</body>
</html>
