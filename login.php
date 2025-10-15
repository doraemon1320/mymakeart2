<?php
if (session_status() === PHP_SESSION_NONE) session_start();
require_once "db_connect.php";

$error = "";

if ($_SERVER['REQUEST_METHOD'] === 'POST') {
    $username = $_POST['username'] ?? '';
    $password = $_POST['password'] ?? '';
    $selected_role = $_POST['role'] ?? 'employee'; // 預設為員工身份

    $stmt = $conn->prepare("SELECT * FROM employees WHERE username = ?");
    $stmt->bind_param("s", $username);
    $stmt->execute();
    $result = $stmt->get_result();
    $user = $result->fetch_assoc();

    if ($user && password_verify($password, $user['password'])) {
        $actual_role = $user['role'];

        // ✅ 員工不得以管理者身份登入
        if ($selected_role === 'admin' && $actual_role !== 'admin') {
            $error = "❌ 您沒有管理者權限，請重新選擇登入身份";
        } else {
            $_SESSION['user'] = [
                'id'             => $user['id'],
                'employee_number'=> $user['employee_number'],
                'username'       => $user['username'],
                'name'           => $user['name'],
                'role'           => $actual_role,
                'company_id'     => $user['company_id'],
                'role_id'        => $user['role_id'],
                'login_as'       => $selected_role // ✅ 記錄本次登入身份
            ];

            if ($selected_role === 'admin') {
                header("Location: admin/admin_home.php");
            } else {
                header("Location: employee/employee_home.php");
            }
            exit;
        }
    } else {
        $error = "帳號或密碼錯誤，請重新輸入";
    }
}
?>

<!DOCTYPE html>
<html lang="zh-Hant">
<head>
    <meta charset="UTF-8">
    <title>登入系統</title>
    <link rel="stylesheet" href="login.css">
    <style>
        body {
            font-family: "Microsoft JhengHei", sans-serif;
        }
        .login-container {
            width: 350px;
            margin: 60px auto;
            border: 1px solid #ccc;
            padding: 30px;
            box-shadow: 0 0 10px #aaa;
            background-color: #fff;
        }
        h1 {
            text-align: center;
            margin-bottom: 25px;
        }
        label {
            display: block;
            margin-top: 12px;
        }
        input[type="text"],
        input[type="password"],
        select {
            width: 100%;
            padding: 8px;
            margin-top: 5px;
            box-sizing: border-box;
        }
        .error {
            color: red;
            text-align: center;
            margin-bottom: 15px;
        }
        button {
            margin-top: 20px;
            width: 100%;
            padding: 10px;
            background-color: #5cb85c;
            color: white;
            border: none;
            cursor: pointer;
            font-size: 16px;
        }
        button:hover {
            background-color: #4cae4c;
        }
    </style>
</head>
<body>
<div class="login-container">
    <h1>登入系統</h1>

    <?php if ($error): ?>
        <p class="error"><?= htmlspecialchars($error) ?></p>
    <?php endif; ?>

    <form method="post">
        <label for="username">帳號</label>
        <input type="text" id="username" name="username" required>

        <label for="password">密碼</label>
        <input type="password" id="password" name="password" required>

        <label for="role">選擇登入身份</label>
        <select name="role" id="role" required>
            <option value="employee">員工</option>
            <option value="admin">管理者</option>
        </select>

        <button type="submit">登入</button>
    </form>
</div>
</body>
</html>
