<?php
require_once "../db_connect.php";

// ✅ 僅限系統 admin 使用
if (!isset($_SESSION['user']) || $_SESSION['user']['username'] !== 'admin') {
    $_SESSION['login_error'] = "僅限系統管理員使用";
    header("Location: ../login.php");
    exit;
}

$id = $_GET['id'] ?? null;
$mode = $id ? "edit" : "create";
$error = "";
$success = "";
$form = [
    'module_name' => '',
    'action' => '',
    'key_code' => '',
    'page_path' => '',
    'category' => ''
];

// 撈資料（編輯模式）
if ($mode === "edit") {
    $stmt = $conn->prepare("SELECT * FROM permissions WHERE id = ?");
    $stmt->bind_param("i", $id);
    $stmt->execute();
    $data = $stmt->get_result()->fetch_assoc();
    if (!$data) die("找不到資料");
    $form = $data;
}

// 儲存
if ($_SERVER['REQUEST_METHOD'] === 'POST') {
    $form['module_name'] = trim($_POST['module_name']);
    $form['action'] = trim($_POST['action']);
    $form['key_code'] = trim($_POST['key_code']);
    $form['page_path'] = trim($_POST['page_path']);
    $form['category'] = trim($_POST['category']);

    if ($form['module_name'] === "" || $form['action'] === "" || $form['key_code'] === "") {
        $error = "模組名稱、操作、key code 為必填";
    } else {
        if ($mode === "create") {
            $stmt = $conn->prepare("INSERT INTO permissions (module_name, action, key_code, page_path, category) VALUES (?, ?, ?, ?, ?)");
            $stmt->bind_param("sssss", $form['module_name'], $form['action'], $form['key_code'], $form['page_path'], $form['category']);
            $stmt->execute();
        } else {
            $stmt = $conn->prepare("UPDATE permissions SET module_name=?, action=?, key_code=?, page_path=?, category=? WHERE id = ?");
            $stmt->bind_param("sssssi", $form['module_name'], $form['action'], $form['key_code'], $form['page_path'], $form['category'], $id);
            $stmt->execute();
        }
        $success = "✅ 權限功能已" . ($mode === "create" ? "新增" : "更新");
    }
}
?>

<!DOCTYPE html>
<html lang="zh-Hant">
<head>
    <meta charset="UTF-8">
    <title><?= $mode === "edit" ? "編輯" : "新增" ?>權限功能</title>
    <link rel="stylesheet" href="permissions_form.css">
</head>
<body>
<div class="container">
    <h1><?= $mode === "edit" ? "✏️ 編輯" : "➕ 新增" ?> 權限功能</h1>

    <?php if ($error): ?><p class="error"><?= $error ?></p><?php endif; ?>
    <?php if ($success): ?><p class="success"><?= $success ?></p>
        <p><a href="permissions_list.php">🔙 返回權限列表</a></p>
    <?php else: ?>

    <form method="post">
        <label>模組名稱 *</label>
        <input type="text" name="module_name" value="<?= htmlspecialchars($form['module_name']) ?>" required>

        <label>操作 (view / create / edit / delete / print / approve...)</label>
        <input type="text" name="action" value="<?= htmlspecialchars($form['action']) ?>" required>

        <label>權限代碼 key_code *</label>
        <input type="text" name="key_code" value="<?= htmlspecialchars($form['key_code']) ?>" required>

        <label>對應頁面（例如 admin_review.php）</label>
        <input type="text" name="page_path" value="<?= htmlspecialchars($form['page_path']) ?>">

        <label>分類（人事 / 出勤 / 公司 / 請假 / 權限 ...）</label>
        <input type="text" name="category" value="<?= htmlspecialchars($form['category']) ?>">

        <button type="submit">💾 <?= $mode === "edit" ? "儲存變更" : "新增" ?></button>
    </form>
    <p><a href="permissions_list.php">🔙 返回權限列表</a></p>
    <?php endif; ?>
</div>
</body>
</html>
