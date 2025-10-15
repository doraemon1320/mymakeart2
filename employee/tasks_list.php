<?php
require_once "../db_connect.php";

// 登入檢查
if (!isset($_SESSION['user'])) {
    header("Location: ../login.php");
    exit;
}

$employee_id = $_SESSION['user']['id'];

// 未完成任務
$stmt = $conn->prepare("SELECT t.*, e.name AS assigner_name FROM tasks t JOIN employees e ON t.assigned_by = e.id WHERE t.assigned_to = ? AND t.id NOT IN (SELECT task_id FROM task_completions WHERE employee_id = ?) ORDER BY t.due_date ASC");
$stmt->bind_param("ii", $employee_id, $employee_id);
$stmt->execute();
$tasks_result = $stmt->get_result();

// 已完成任務
$stmt2 = $conn->prepare("SELECT t.*, e.name AS assigner_name, c.completed_at FROM tasks t JOIN employees e ON t.assigned_by = e.id JOIN task_completions c ON c.task_id = t.id WHERE t.assigned_to = ? AND c.employee_id = ? ORDER BY c.completed_at DESC");
$stmt2->bind_param("ii", $employee_id, $employee_id);
$stmt2->execute();
$completed_result = $stmt2->get_result();
?>
<!DOCTYPE html>
<html lang="zh-Hant">
<head>
    <meta charset="UTF-8">
    <title>我的任務清單</title>
    <link href="https://cdn.jsdelivr.net/npm/bootstrap@5.3.0/dist/css/bootstrap.min.css" rel="stylesheet">
    <link rel="stylesheet" href="employee_navbar.css">
</head>
<body class="bg-light">
<?php include 'employee_navbar.php'; ?>
<div class="container py-4">
    <h2 class="mb-4">🗂 我的任務清單</h2>

    <div class="card mb-4">
        <div class="card-header bg-primary text-white">📌 進行中任務</div>
        <div class="card-body">
            <?php if ($tasks_result->num_rows > 0): ?>
                <ul class="list-group">
                    <?php while ($task = $tasks_result->fetch_assoc()): ?>
                        <li class="list-group-item">
                            <div class="d-flex justify-content-between align-items-center">
                                <div>
                                    <strong><?= htmlspecialchars($task['title']) ?></strong>
                                    <br><small>由 <?= htmlspecialchars($task['assigner_name']) ?> 指派，截止日 <?= $task['due_date'] ?></small>
                                    <br><small class="text-muted"><?= nl2br(htmlspecialchars($task['description'])) ?></small>
                                </div>
                                <form method="POST" action="task_mark_done.php" class="ms-3">
                                    <input type="hidden" name="task_id" value="<?= $task['id'] ?>">
                                    <button type="submit" class="btn btn-success btn-sm">✔ 完成</button>
                                </form>
                            </div>
                        </li>
                    <?php endwhile; ?>
                </ul>
            <?php else: ?>
                <p class="text-muted">目前沒有尚未完成的任務。</p>
            <?php endif; ?>
        </div>
    </div>

    <div class="card">
        <div class="card-header bg-secondary text-white">✅ 已完成任務</div>
        <div class="card-body">
            <?php if ($completed_result->num_rows > 0): ?>
                <ul class="list-group">
                    <?php while ($task = $completed_result->fetch_assoc()): ?>
                        <li class="list-group-item">
                            <strong><?= htmlspecialchars($task['title']) ?></strong>
                            <br><small>完成時間：<?= $task['completed_at'] ?>，由 <?= htmlspecialchars($task['assigner_name']) ?> 指派</small>
                        </li>
                    <?php endwhile; ?>
                </ul>
            <?php else: ?>
                <p class="text-muted">目前尚無已完成任務。</p>
            <?php endif; ?>
        </div>
    </div>
</div>
</body>
</html>
