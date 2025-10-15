<?php
session_start();

// 檢查是否為管理員
if (!isset($_SESSION['user_id']) || $_SESSION['role'] !== 'admin') {
    header('Location: login.php');
    exit;
}

// 連接資料庫
$conn = new mysqli('localhost', 'root', '', 'mymakeart');

// 處理更新申請狀態
if ($_SERVER['REQUEST_METHOD'] === 'POST' && isset($_POST['request_id'], $_POST['status'])) {
    $status = $_POST['status'];
    $request_id = $_POST['request_id'];

    // 更新申請狀態
    $stmt = $conn->prepare("UPDATE requests SET status = ? WHERE id = ?");
    $stmt->bind_param('si', $status, $request_id);
    $stmt->execute();

    // 發送通知
    $stmt = $conn->prepare("INSERT INTO notifications (employee_id, message) VALUES ((SELECT employee_id FROM requests WHERE id = ?), ?)");
    $message = "您的申請已被" . ($status === 'approved' ? '批准' : '拒絕');
    $stmt->bind_param('is', $request_id, $message);
    $stmt->execute();

    $success = "申請狀態已更新！";
}

// 獲取所有申請
$requests = $conn->query("SELECT r.id, r.type, r.reason, r.status, r.created_at, e.name 
                          FROM requests r 
                          JOIN employees e ON r.employee_id = e.id 
                          ORDER BY r.created_at DESC");

?>

<!DOCTYPE html>
<html lang="zh-TW">
<head>
    <meta charset="UTF-8">
    <meta name="viewport" content="width=device-width, initial-scale=1.0">
    <title>管理員首頁</title>
</head>
<body>
    <h1>管理員 - 申請審批</h1>

    <!-- 新增員工按鈕 -->
    <a href="add_employee.php" style="display: inline-block; margin-bottom: 20px; padding: 10px; background-color: #4CAF50; color: white; text-decoration: none;">新增員工</a>

    <?php if (!empty($success)): ?>
        <p style="color: green;"><?= $success ?></p>
    <?php endif; ?>

    <h2>所有申請</h2>
    <table border="1" cellpadding="10" cellspacing="0">
        <thead>
            <tr>
                <th>員工姓名</th>
                <th>申請類型</th>
                <th>理由</th>
                <th>狀態</th>
                <th>申請時間</th>
                <th>操作</th>
            </tr>
        </thead>
        <tbody>
            <?php while ($row = $requests->fetch_assoc()): ?>
                <tr>
                    <td><?= htmlspecialchars($row['name']) ?></td>
                    <td><?= htmlspecialchars($row['type'] === 'leave' ? '請假' : '加班') ?></td>
                    <td><?= htmlspecialchars($row['reason']) ?></td>
                    <td><?= htmlspecialchars($row['status'] === 'pending' ? '待審批' : ($row['status'] === 'approved' ? '批准' : '拒絕')) ?></td>
                    <td><?= htmlspecialchars($row['created_at']) ?></td>
                    <td>
                        <?php if ($row['status'] === 'pending'): ?>
                            <form method="POST" action="" style="display: inline;">
                                <input type="hidden" name="request_id" value="<?= $row['id'] ?>">
                                <button type="submit" name="status" value="approved" style="background-color: #4CAF50; color: white; padding: 5px 10px; border: none; cursor: pointer;">批准</button>
                                <button type="submit" name="status" value="rejected" style="background-color: #f44336; color: white; padding: 5px 10px; border: none; cursor: pointer;">拒絕</button>
                            </form>
                        <?php else: ?>
                            已處理
                        <?php endif; ?>
                    </td>
                </tr>
            <?php endwhile; ?>
        </tbody>
    </table>
</body>
</html>
