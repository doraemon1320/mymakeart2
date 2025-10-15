<?php
require_once "../db_connect.php";

// ✅ 僅限系統 admin 使用
if (!isset($_SESSION['user']) || $_SESSION['user']['username'] !== 'admin') {
    $_SESSION['login_error'] = "僅限系統管理員使用";
    header("Location: ../login.php");
    exit;
}

// 刪除處理
if (isset($_GET['delete'])) {
    $id = intval($_GET['delete']);
    $conn->query("DELETE FROM permissions WHERE id = $id");
    header("Location: permissions_list.php");
    exit;
}

// 撈全部權限資料
$permissions = $conn->query("SELECT * FROM permissions ORDER BY module_name, action");
?>

<!DOCTYPE html>
<html lang="zh-Hant">
<head>
    <meta charset="UTF-8">
    <title>權限功能清單管理</title>
    <link rel="stylesheet" href="permissions_list.css">
</head>
<body>
<div class="container">
    <h1>🔐 權限功能清單管理</h1>

    <a href="permissions_form.php" class="add-btn">➕ 新增權限</a>

    <table>
        <thead>
        <tr>
            <th>模組</th>
            <th>操作</th>
            <th>Key</th>
            <th>頁面</th>
            <th>分類</th>
            <th>操作</th>
        </tr>
        </thead>
        <tbody>
        <?php while ($p = $permissions->fetch_assoc()): ?>
            <tr>
                <td><?= htmlspecialchars($p['module_name']) ?></td>
                <td><?= $p['action'] ?></td>
                <td><?= $p['key_code'] ?></td>
                <td><?= $p['page_path'] ?></td>
                <td><?= $p['category'] ?></td>
                <td>
                    <a href="permissions_form.php?id=<?= $p['id'] ?>">✏️ 編輯</a> |
                    <a href="permissions_list.php?delete=<?= $p['id'] ?>" onclick="return confirm('確定要刪除嗎？')">🗑️ 刪除</a>
                </td>
            </tr>
        <?php endwhile; ?>
        </tbody>
    </table>
</div>
</body>
</html>
