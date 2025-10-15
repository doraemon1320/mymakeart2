<?php
session_start();

// ✅ 確保只有 `admin` 能存取
if (!isset($_SESSION['user']) || $_SESSION['user']['role'] !== 'admin') {
    header('Location: login.php');
    exit;
}

$conn = new mysqli('localhost', 'root', '', 'mymakeart');

// ✅ 檢查資料庫連接
if ($conn->connect_error) {
    die("資料庫連接失敗：" . $conn->connect_error);
}

// ✅ 獲取班別選項
$shifts = $conn->query("
    SELECT id, name 
    FROM shifts 
    ORDER BY id ASC
");

// ✅ 處理新增員工
$success = "";
$error = "";

if ($_SERVER['REQUEST_METHOD'] === 'POST') {
    $employee_number = trim($_POST['employee_number']);
    $username = trim($_POST['username']);
    $name = trim($_POST['name']);
    $hire_date = $_POST['hire_date'];
    $password = password_hash($_POST['password'], PASSWORD_DEFAULT); // ✅ 改用 `password_hash()`
    $shift_id = $_POST['shift_id'];

    // 確保 `employee_number` 唯一
    $check_stmt = $conn->prepare("SELECT id FROM employees WHERE employee_number = ?");
    $check_stmt->bind_param('s', $employee_number);
    $check_stmt->execute();
    $check_result = $check_stmt->get_result();

    if ($check_result->num_rows > 0) {
        $error = "❌ 工號已存在，請使用其他工號！";
    } else {
        // 插入新員工資料
        $stmt = $conn->prepare("
            INSERT INTO employees (employee_number, username, password, name, hire_date, shift_id, role) 
            VALUES (?, ?, ?, ?, ?, ?, 'employee')
        ");
        $stmt->bind_param('sssssi', $employee_number, $username, $password, $name, $hire_date, $shift_id);

        if ($stmt->execute()) {
            $success = "✅ 員工新增成功！";
        } else {
            $error = "❌ 新增員工失敗，請稍後再試！";
        }
    }
}
?>

<!DOCTYPE html>
<html lang="zh-TW">
<head>
    <meta charset="UTF-8">
    <meta name="viewport" content="width=device-width, initial-scale=1.0">
    <title>新增員工</title>
    <link href="https://cdn.jsdelivr.net/npm/bootstrap@5.3.3/dist/css/bootstrap.min.css" rel="stylesheet">
    <link rel="stylesheet" href="admin_navbar.css">
</head>
<body>
    <?php include 'admin_navbar.php'; ?>

    <div class="container mt-4">
        <h1 class="mb-4">新增員工</h1>

        <?php if (!empty($success)): ?>
            <div class="alert alert-success"> <?= htmlspecialchars($success) ?> </div>
        <?php elseif (!empty($error)): ?>
            <div class="alert alert-danger"> <?= htmlspecialchars($error) ?> </div>
        <?php endif; ?>

        <form method="POST" action="" class="row g-3">
            <div class="col-md-6">
                <label for="employee_number" class="form-label">工號：</label>
                <input type="text" id="employee_number" name="employee_number" class="form-control" required>
            </div>

            <div class="col-md-6">
                <label for="username" class="form-label">使用者帳號：</label>
                <input type="text" id="username" name="username" class="form-control" required>
            </div>

            <div class="col-md-6">
                <label for="name" class="form-label">姓名：</label>
                <input type="text" id="name" name="name" class="form-control" required>
            </div>

            <div class="col-md-6">
                <label for="password" class="form-label">密碼：</label>
                <input type="password" id="password" name="password" class="form-control" required>
            </div>

            <div class="col-md-6">
                <label for="hire_date" class="form-label">入職日期：</label>
                <input type="date" id="hire_date" name="hire_date" class="form-control" required>
            </div>

            <div class="col-md-6">
                <label for="shift_id" class="form-label">班別：</label>
                <select id="shift_id" name="shift_id" class="form-select" required>
                    <?php while ($shift = $shifts->fetch_assoc()): ?>
                        <option value="<?= $shift['id'] ?>"><?= htmlspecialchars($shift['name']) ?></option>
                    <?php endwhile; ?>
                </select>
            </div>

            <div class="col-12">
                <button type="submit" class="btn btn-success">新增員工</button>
                <a href="employee_list.php" class="btn btn-secondary ms-2">← 返回員工列表</a>
            </div>
        </form>
    </div>
</body>
</html>
