<?php
require_once "../db_connect.php";
session_start();

if (!isset($_SESSION['user']) || $_SESSION['user']['role'] !== 'admin') {
    header("Location: ../login.php");
    exit;
}

$assigner_id = $_SESSION['user']['id'];
$employees = $conn->query("SELECT id, name FROM employees WHERE id != $assigner_id ORDER BY name ASC");
?>

<!DOCTYPE html>
<html lang="zh-Hant">
<head>
    <meta charset="UTF-8">
    <title>指派任務</title>
    <link href="https://cdn.jsdelivr.net/npm/bootstrap@5.3.0/dist/css/bootstrap.min.css" rel="stylesheet">
</head>
<body class="bg-light">
<div class="container py-4">
    <h2 class="mb-4">📋 指派新任務</h2>

    <form method="post" action="assign_task_submit.php">
        <div class="mb-3">
            <label class="form-label">任務標題</label>
            <input type="text" name="title" class="form-control" required>
        </div>

        <div class="mb-3">
            <label class="form-label">任務說明</label>
            <textarea name="description" class="form-control" rows="3"></textarea>
        </div>

        <div class="mb-3">
            <label class="form-label">指派給哪些員工（可多選）</label>
            <select name="assigned_to[]" class="form-select" multiple required>
                <?php while ($row = $employees->fetch_assoc()): ?>
                    <option value="<?= $row['id'] ?>"><?= htmlspecialchars($row['name']) ?></option>
                <?php endwhile; ?>
            </select>
        </div>

        <div class="mb-3">
            <label class="form-label">任務類型</label>
            <select name="type" class="form-select" id="type-select" required>
                <option value="one-time">一次性</option>
                <option value="recurring">重複性</option>
            </select>
        </div>

        <div id="repeat-options" class="mb-3" style="display:none;">
            <label class="form-label">重複週期</label>
            <select name="repeat_cycle" class="form-select">
                <option value="daily">每日</option>
                <option value="weekly">每週</option>
                <option value="monthly">每月</option>
                <option value="yearly">每年</option>
            </select>
            <small class="text-muted">（例：每月5號，每年12/25 可於後續進階設定中擴充）</small>
        </div>

        <div class="mb-3">
            <label class="form-label">開始日期</label>
            <input type="date" name="start_date" class="form-control" required>
        </div>

        <div class="mb-3">
            <label class="form-label">到期日期</label>
            <input type="date" name="due_date" class="form-control" required>
        </div>

        <button type="submit" class="btn btn-primary">✅ 送出任務</button>
    </form>
</div>

<script>
document.getElementById('type-select').addEventListener('change', function () {
    document.getElementById('repeat-options').style.display = this.value === 'recurring' ? 'block' : 'none';
});
</script>
</body>
</html>
