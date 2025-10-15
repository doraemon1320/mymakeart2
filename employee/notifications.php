<?php
session_start();
if (!isset($_SESSION['user']) ) {
    header('Location: ../login.php');
    exit;
}

$conn = new mysqli('localhost', 'root', '', 'mymakeart');
if ($conn->connect_error) {
    die("資料庫連接失敗：" . $conn->connect_error);
}

$employee_number = $_SESSION['user']['employee_number'];

// 撈取通知
$stmt = $conn->prepare("SELECT message, created_at FROM notifications WHERE employee_number = ? ORDER BY created_at DESC");
$stmt->bind_param('s', $employee_number);
$stmt->execute();
$result = $stmt->get_result();

// 更新為已讀
$update_stmt = $conn->prepare("UPDATE notifications SET is_read = 1 WHERE employee_number = ?");
$update_stmt->bind_param('s', $employee_number);
$update_stmt->execute();
?>
<!DOCTYPE html>
<html lang="zh-TW">
<head>
    <meta charset="UTF-8">
    <title>查看通知</title>
    <meta name="viewport" content="width=device-width, initial-scale=1.0">
    <!-- Bootstrap & CSS -->
    <link href="https://cdn.jsdelivr.net/npm/bootstrap@5.3.3/dist/css/bootstrap.min.css" rel="stylesheet">
    <link rel="stylesheet" href="employee_navbar.css">
    <link rel="stylesheet" href="styles.css">
</head>
<body class="bg-light">
<?php include 'employee_navbar.php'; ?>

<div class="container my-4">
    <h4 class="mb-3"><i class="bi bi-bell me-2"></i>查看通知</h4>

    <?php if ($result->num_rows > 0): ?>
        <ul class="list-group">
            <?php while ($row = $result->fetch_assoc()): ?>
                <li class="list-group-item">
                    <p class="mb-1"><?= htmlspecialchars($row['message']) ?></p>
                    <small class="text-muted">時間：<?= htmlspecialchars($row['created_at']) ?></small>
                </li>
            <?php endwhile; ?>
        </ul>
    <?php else: ?>
        <div class="alert alert-info">目前沒有新通知。</div>
    <?php endif; ?>
</div>
</body>
</html>
