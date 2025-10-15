<?php
session_start();

// ✅ 確保 `session` 變數存在，否則回到 `login.php`
if (!isset($_SESSION['user']) || $_SESSION['user']['role'] !== 'admin') {
    header('Location: login.php');
    exit;
}

// 連接資料庫
$conn = new mysqli('localhost', 'root', '', 'mymakeart');

// 檢查資料庫連接
if ($conn->connect_error) {
    die("資料庫連接失敗：" . $conn->connect_error);
}

// ✅ 取得審核歷程
$audit_logs = $conn->query("
    SELECT a.request_id, a.previous_status, a.new_status, a.updated_at, e.name AS admin_name 
    FROM audit_logs a
    JOIN employees e ON a.admin_id = e.id
    ORDER BY a.updated_at DESC
");

// ✅ 狀態對應中文
$status_map = [
    'pending' => '審查中',
    'approved' => '批准',
    'rejected' => '拒絕'
];
?>

<!DOCTYPE html>
<html lang="zh-TW">
<head>
    <meta charset="UTF-8">
    <meta name="viewport" content="width=device-width, initial-scale=1.0">
    <title>查詢審核歷程</title>
    <link rel="stylesheet" href="styles.css">
</head>
<body>
    <div class="container">
        <!-- 返回按鈕 -->
        <a href="admin_review.php" class="button-back">返回審核申請</a>

        <h1>查詢審核歷程</h1>
        <p class="description">此頁面顯示所有歷史審核記錄，包括操作人員、原狀態、新狀態和更新時間。</p>

        <!-- 歷程列表 -->
        <table class="audit-table">
            <thead>
                <tr>
                    <th>申請 ID</th>
                    <th>原狀態</th>
                    <th>新狀態</th>
                    <th>更新時間</th>
                    <th>操作人員</th>
                </tr>
            </thead>
            <tbody>
                <?php if ($audit_logs->num_rows > 0): ?>
                    <?php while ($row = $audit_logs->fetch_assoc()): ?>
                        <tr>
                            <td><?= htmlspecialchars($row['request_id']) ?></td>
                            <td><?= htmlspecialchars($status_map[$row['
