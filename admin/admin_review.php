<?php
session_start();

// ✅ 權限檢查：僅限 admin
if (!isset($_SESSION['user']) || $_SESSION['user']['role'] !== 'admin') {
    header('Location: ../login.php');
    exit;
}

$conn = new mysqli('localhost', 'root', '', 'mymakeart');
if ($conn->connect_error) {
    die("資料庫連接失敗：" . $conn->connect_error);
}

$success = "";
$error = "";

// ✅ 處理審核操作
if ($_SERVER['REQUEST_METHOD'] === 'POST' && isset($_POST['request_id']) && isset($_POST['status'])) {
    $request_id = $_POST['request_id'];
    $new_status = $_POST['status'];

    if (!in_array($new_status, ['Approved', 'Rejected'])) {
        $error = "無效的狀態操作。";
    } else {
        $stmt = $conn->prepare("UPDATE requests SET status = ? WHERE id = ?");
        $stmt->bind_param('si', $new_status, $request_id);

        if ($stmt->execute()) {
            $employee_stmt = $conn->prepare("SELECT employee_number FROM requests WHERE id = ?");
            $employee_stmt->bind_param('i', $request_id);
            $employee_stmt->execute();
            $employee_result = $employee_stmt->get_result();
            $employee = $employee_result->fetch_assoc();
            $employee_number = $employee['employee_number'];

            $message = "您的申請已被" . ($new_status === 'Approved' ? "批准" : "拒絕");
            $notify_stmt = $conn->prepare("INSERT INTO notifications (employee_number, message) VALUES (?, ?)");
            $notify_stmt->bind_param('ss', $employee_number, $message);
            $notify_stmt->execute();

            $success = "申請狀態已成功更新！";
        } else {
            $error = "更新申請狀態時發生錯誤，請稍後再試。";
        }
    }
}

// ✅ 撈出請假/加班申請資料
$requests = $conn->query("
    SELECT r.id, r.type, r.subtype, r.reason, r.status, r.start_date, r.end_date, r.created_at, e.name 
    FROM requests r 
    JOIN employees e ON r.employee_number = e.employee_number
    ORDER BY r.created_at DESC
");



$status_map = [
    'Pending' => '審查中',
    'Approved' => '批准',
    'Rejected' => '拒絕'
];
?>

<!DOCTYPE html>
<html lang="zh-TW">
<head>
    <meta charset="UTF-8">
    <title>審核申請</title>
    <link rel="stylesheet" href="https://cdn.jsdelivr.net/npm/bootstrap@5.3.3/dist/css/bootstrap.min.css">
    <link rel="stylesheet" href="admin_navbar.css">
</head>
<body class="bg-light">
    <?php include 'admin_navbar.php'; ?>

    <div class="container mt-4">
        <h3>審核申請</h3>

        <?php if ($success): ?>
            <div class="alert alert-success"><?= htmlspecialchars($success) ?></div>
        <?php elseif ($error): ?>
            <div class="alert alert-danger"><?= htmlspecialchars($error) ?></div>
        <?php endif; ?>

        <div class="table-responsive">
            <table class="table table-bordered table-hover table-sm align-middle">
                <thead class="table-dark">
                    <tr>
                        <th>姓名</th>
                        <th>類型</th>
                        <th>假別</th>
                        <th>理由</th>
                        <th>起始時間</th>
                        <th>結束時間</th>
                        <th>狀態</th>
                        <th>操作</th>
                    </tr>
                </thead>
                <tbody>
                <?php while ($row = $requests->fetch_assoc()): ?>
                    <tr>
                        <td><?= htmlspecialchars($row['name']) ?></td>
                        <td><?= htmlspecialchars($row['type']) ?></td>
                        <td><?= htmlspecialchars($row['subtype']) ?></td>
                        <td><?= htmlspecialchars($row['reason']) ?></td>
                        <td><?= htmlspecialchars($row['start_date']) ?></td>
                        <td><?= htmlspecialchars($row['end_date']) ?></td>
                        <td>
                            <?php if ($row['status'] === 'Pending'): ?>
                                <form method="POST" class="d-flex gap-2">
                                    <input type="hidden" name="request_id" value="<?= $row['id'] ?>">
                                    <button type="submit" name="status" value="Approved" class="btn btn-success btn-sm">批准</button>
                                    <button type="submit" name="status" value="Rejected" class="btn btn-danger btn-sm">拒絕</button>
                                </form>
                            <?php else: ?>
                                <span class="badge <?= $row['status'] === 'Approved' ? 'bg-success' : 'bg-danger' ?>">
                                    <?= $status_map[$row['status']] ?>
                                </span>
                            <?php endif; ?>
                        </td>
                        <td>
                            <form method="GET" action="update_status.php">
                                <input type="hidden" name="request_id" value="<?= $row['id'] ?>">
                                <button type="submit" class="btn btn-outline-primary btn-sm">更正</button>
                            </form>
                        </td>
                    </tr>
                <?php endwhile; ?>
                </tbody>
            </table>
        </div>
    </div>
</body>
</html>
