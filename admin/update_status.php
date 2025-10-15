<?php
session_start();

// 檢查使用者是否登入且為管理員
if (!isset($_SESSION['user']) || $_SESSION['user']['role'] !== 'admin') {
    header('Location: ../login.php');
    exit;
}

$conn = new mysqli('localhost', 'root', '', 'mymakeart');

// 檢查資料庫連接
if ($conn->connect_error) {
    die("資料庫連接失敗：" . $conn->connect_error);
}

// 初始化變數
$request_id = $_GET['request_id'] ?? null;
$error = "";
$request = null;

// 確認是否有 request_id 傳入
if (!$request_id || !is_numeric($request_id)) {
    die("無效的申請 ID！");
}

// 查詢申請記錄及相關員工資訊
$stmt = $conn->prepare("
    SELECT 
        r.id, 
        r.type, 
        r.subtype, 
        r.reason, 
        r.status, 
        r.start_date, 
        r.end_date, 
        e.name AS employee_name, 
        r.employee_number 
    FROM requests r
    JOIN employees e ON r.employee_number = e.employee_number
    WHERE r.id = ?
");
$stmt->bind_param('i', $request_id);
$stmt->execute();
$result = $stmt->get_result();

if ($result->num_rows === 1) {
    $request = $result->fetch_assoc();
} else {
    die("找不到對應的申請記錄！");
}

// 更新申請狀態
if ($_SERVER['REQUEST_METHOD'] === 'POST') {
    $new_status = $_POST['status'] ?? null;

    if (!in_array($new_status, ['approved', 'rejected'])) {
        $error = "無效的狀態操作。";
    } else {
        $update_stmt = $conn->prepare("UPDATE requests SET status = ? WHERE id = ?");
        $update_stmt->bind_param('si', $new_status, $request_id);

        if ($update_stmt->execute()) {
            // 發送通知
            $notify_stmt = $conn->prepare("
                INSERT INTO notifications (employee_number, message) 
                VALUES (?, ?)
            ");
            $message = "您的申請已被" . ($new_status === 'approved' ? '批准' : '拒絕');
            $notify_stmt->bind_param('ss', $request['employee_number'], $message);
            $notify_stmt->execute();

            $success = "申請狀態已成功更新！";
        } else {
            $error = "更新申請狀態時發生錯誤，請稍後再試。";
        }
    }
}
?>

<!DOCTYPE html>
<html lang="zh-TW">
<head>
    <meta charset="UTF-8">
    <meta name="viewport" content="width=device-width, initial-scale=1.0">
    <title>更正申請狀態</title>
    <link rel="stylesheet" href="styles.css">
   <link rel="stylesheet" href="admin_navbar.css">
</head>
<body>
    <div class="container">
        <!-- 導航列 -->
        <?php include 'admin_navbar.php'; ?>

        <h1>更正申請狀態</h1>

        <?php if (!empty($success)): ?>
            <p class="success"><?= htmlspecialchars($success) ?></p>
        <?php elseif (!empty($error)): ?>
            <p class="error"><?= htmlspecialchars($error) ?></p>
        <?php endif; ?>

        <?php if ($request): ?>
            <table class="request-detail-table">
                <tr>
                    <th>員工姓名：</th>
                    <td><?= htmlspecialchars($request['employee_name']) ?></td>
                </tr>
                <tr>
                    <th>類型：</th>
                    <td><?= htmlspecialchars($request['type'] === 'leave' ? '請假' : '加班') ?></td>
                </tr>
                <tr>
                    <th>假別：</th>
                    <td><?= htmlspecialchars($request['subtype'] ?? '-') ?></td>
                </tr>
                <tr>
                    <th>理由：</th>
                    <td><?= htmlspecialchars($request['reason']) ?></td>
                </tr>
                <tr>
                    <th>起始日期：</th>
                    <td><?= htmlspecialchars($request['start_date']) ?></td>
                </tr>
                <tr>
                    <th>結束日期：</th>
                    <td><?= htmlspecialchars($request['end_date']) ?></td>
                </tr>
                <tr>
                    <th>目前狀態：</th>
                    <td>
                        <?php if ($request['status'] === 'pending'): ?>
                            <span class="status-yellow">審查中</span>
                        <?php elseif ($request['status'] === 'approved'): ?>
                            <span class="status-green">已批准</span>
                        <?php else: ?>
                            <span class="status-red">已拒絕</span>
                        <?php endif; ?>
                    </td>
                </tr>
            </table>

            <h2>更正狀態</h2>
            <form method="POST" action="">
                <label for="status">更改狀態：</label>
                <select id="status" name="status" required>
                    <option value="approved" <?= $request['status'] === 'approved' ? 'selected' : '' ?>>批准</option>
                    <option value="rejected" <?= $request['status'] === 'rejected' ? 'selected' : '' ?>>拒絕</option>
                </select>
                <button type="submit">更新</button>
            </form>
        <?php else: ?>
            <p class="error">找不到申請記錄。</p>
        <?php endif; ?>
    </div>
</body>
</html>
