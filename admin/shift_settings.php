<?php

session_start();

// ✅ 1️⃣ 登入權限檢查
if (!isset($_SESSION['user']) || !isset($_SESSION['user']['role']) || $_SESSION['user']['role'] !== 'admin') {
    header('Location: login.php');
    exit;
}

$conn = new mysqli('localhost', 'root', '', 'mymakeart');
if ($conn->connect_error) {
    die("資料庫連接失敗：" . $conn->connect_error);
}

// ✅ 處理新增班別
if ($_SERVER['REQUEST_METHOD'] === 'POST' && isset($_POST['add_shift'])) {
    $name = $_POST['name'];
    $start_time = $_POST['start_time'];
    $end_time = $_POST['end_time'];
    $type = $_POST['type'];

    if ($start_time >= $end_time) {
        $error = "下班時間必須晚於上班時間！";
    } else {
        $stmt = $conn->prepare("
            INSERT INTO shifts (name, start_time, end_time, type) 
            VALUES (?, ?, ?, ?)
        ");
        $stmt->bind_param('ssss', $name, $start_time, $end_time, $type);
        if ($stmt->execute()) {
            $success = "班別新增成功！";
        } else {
            $error = "新增班別失敗，請稍後再試！";
        }
    }
}

// ✅ 處理刪除班別
if ($_SERVER['REQUEST_METHOD'] === 'POST' && isset($_POST['delete_shift'])) {
    $shift_id = $_POST['shift_id'];

    $stmt = $conn->prepare("DELETE FROM shifts WHERE id = ?");
    $stmt->bind_param('i', $shift_id);
    if ($stmt->execute()) {
        $success = "班別刪除成功！";
    } else {
        $error = "刪除班別失敗，請稍後再試！";
    }
}

// ✅ 處理更新班別
if ($_SERVER['REQUEST_METHOD'] === 'POST' && isset($_POST['edit_shift'])) {
    $shift_id = $_POST['shift_id'];
    $name = $_POST['name'];
    $start_time = $_POST['start_time'];
    $end_time = $_POST['end_time'];
    $type = $_POST['type'];

    if ($start_time >= $end_time) {
        $error = "下班時間必須晚於上班時間！";
    } else {
        $stmt = $conn->prepare("
            UPDATE shifts 
            SET name = ?, start_time = ?, end_time = ?, type = ? 
            WHERE id = ?
        ");
        $stmt->bind_param('ssssi', $name, $start_time, $end_time, $type, $shift_id);
        if ($stmt->execute()) {
            $success = "班別更新成功！";
        } else {
            $error = "更新班別失敗，請稍後再試！";
        }
    }
}

// ✅ 獲取班別列表
$shifts = $conn->query("SELECT * FROM shifts ORDER BY id ASC");
?>

<!DOCTYPE html>
<html lang="zh-TW">
<head>
    <meta charset="UTF-8">
    <meta name="viewport" content="width=device-width, initial-scale=1.0">
    <title>班別設定</title>
    <link href="https://cdn.jsdelivr.net/npm/bootstrap@5.3.3/dist/css/bootstrap.min.css" rel="stylesheet">
    <link rel="stylesheet" href="admin_navbar.css">
</head>
<body>
	 <?php include 'admin_navbar.php'; ?>
<div class="container mt-4">
   
    
    <h1 class="mb-4">班別設定</h1>

    <!-- 新增班別 -->
    <div class="card mb-4">
        <div class="card-header bg-primary text-white">新增班別</div>
        <div class="card-body">
            <form method="POST" action="" class="row g-3">
                <div class="col-md-3">
                    <label for="name" class="form-label">班別名稱</label>
                    <input type="text" id="name" name="name" class="form-control" required>
                </div>
                <div class="col-md-2">
                    <label for="start_time" class="form-label">上班時間</label>
                    <input type="time" id="start_time" name="start_time" class="form-control" required>
                </div>
                <div class="col-md-2">
                    <label for="end_time" class="form-label">下班時間</label>
                    <input type="time" id="end_time" name="end_time" class="form-control" required>
                </div>
                <div class="col-md-2">
                    <label for="type" class="form-label">類型</label>
                    <select id="type" name="type" class="form-select" required>
                        <option value="custom">自訂</option>
                    </select>
                </div>
                <div class="col-md-3 d-flex align-items-end">
                    <button type="submit" name="add_shift" class="btn btn-success w-100">新增班別</button>
                </div>
            </form>
        </div>
    </div>

    <!-- 現有班別列表 -->
    <div class="card">
        <div class="card-header bg-primary text-white">現有班別</div>
        <div class="card-body p-0">
            <table class="table table-bordered m-0">
                <thead class="table-light">
                    <tr>
                        <th>班別名稱</th>
                        <th>上班時間</th>
                        <th>下班時間</th>
                        <th>類型</th>
                        <th>操作</th>
                    </tr>
                </thead>
                <tbody>
                <?php while ($shift = $shifts->fetch_assoc()): ?>
                    <tr>
                        <td><?= htmlspecialchars($shift['name']) ?></td>
                        <td><?= htmlspecialchars($shift['start_time']) ?></td>
                        <td><?= htmlspecialchars($shift['end_time']) ?></td>
                        <td><?= htmlspecialchars($shift['type']) ?></td>
                        <td>
                            <form method="POST" action="" class="d-flex flex-wrap gap-2">
                                <!-- 編輯 -->
                                <input type="hidden" name="shift_id" value="<?= $shift['id'] ?>">
                                <input type="text" name="name" value="<?= htmlspecialchars($shift['name']) ?>" class="form-control form-control-sm" required>
                                <input type="time" name="start_time" value="<?= htmlspecialchars($shift['start_time']) ?>" class="form-control form-control-sm" required>
                                <input type="time" name="end_time" value="<?= htmlspecialchars($shift['end_time']) ?>" class="form-control form-control-sm" required>
                                <select name="type" class="form-select form-select-sm">
                                    <option value="custom" <?= $shift['type'] == 'custom' ? 'selected' : '' ?>>自訂</option>
                                </select>
                                <button type="submit" name="edit_shift" class="btn btn-warning btn-sm">更新</button>
                            </form>
                            <form method="POST" action="" class="mt-1">
                                <input type="hidden" name="shift_id" value="<?= $shift['id'] ?>">
                                <button type="submit" name="delete_shift" class="btn btn-danger btn-sm" onclick="return confirm('確定要刪除這個班別嗎？');">刪除</button>
                            </form>
                        </td>
                    </tr>
                <?php endwhile; ?>
                </tbody>
            </table>
        </div>
    </div>

    <?php if (!empty($success)): ?>
        <div class="alert alert-success mt-3"><?= $success ?></div>
    <?php elseif (!empty($error)): ?>
        <div class="alert alert-danger mt-3"><?= $error ?></div>
    <?php endif; ?>
</div>

<script src="https://cdn.jsdelivr.net/npm/bootstrap@5.3.3/dist/js/bootstrap.bundle.min.js"></script>
</body>
</html>

