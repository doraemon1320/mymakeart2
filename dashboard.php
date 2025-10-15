<?php
session_start();
if (!isset($_SESSION['user_id'])) {
    header('Location: login.php');
    exit;
}

$conn = new mysqli('localhost', 'root', '', 'mymakeart');
$user_id = $_SESSION['user_id'];

// 處理提交申請
if ($_SERVER['REQUEST_METHOD'] === 'POST') {
    $type = $_POST['type'];
    $reason = $_POST['reason'];
    $stmt = $conn->prepare("INSERT INTO requests (employee_id, type, reason) VALUES (?, ?, ?)");
    $stmt->bind_param('iss', $user_id, $type, $reason);
    $stmt->execute();
    $success = "申請提交成功！";
}

// 獲取用戶的申請
$requests = $conn->query("SELECT * FROM requests WHERE employee_id = $user_id");
?>

<!DOCTYPE html>
<html lang="zh-TW">
<head>
    <meta charset="UTF-8">
    <meta name="viewport" content="width=device-width, initial-scale=1.0">
    <title>員工首頁</title>
</head>
<body>
    <h1>歡迎，員工！</h1>

    <h2>提交申請</h2>
    <form method="POST" action="">
        <label>類型：</label>
        <select name="type">
            <option value="leave">請假</option>
            <option value="overtime">加班</option>
        </select>
        <br>
        <label>理由：</label>
        <textarea name="reason" required></textarea>
        <br>
        <button type="submit">提交</button>
    </form>
    <?php if (!empty($success)) echo "<p style='color:green;'>$success</p>"; ?>

    <h2>申請狀態</h2>
    <table border="1">
        <thead>
            <tr>
                <th>類型</th>
                <th>理由</th>
                <th>狀態</th>
                <th>申請時間</th>
            </tr>
        </thead>
        <tbody>
            <?php while ($row = $requests->fetch_assoc()): ?>
                <tr>
                    <td><?= htmlspecialchars($row['type']) ?></td>
                    <td><?= htmlspecialchars($row['reason']) ?></td>
                    <td><?= htmlspecialchars($row['status']) ?></td>
                    <td><?= htmlspecialchars($row['created_at']) ?></td>
                </tr>
            <?php endwhile; ?>
        </tbody>
    </table>
</body>
</html>
