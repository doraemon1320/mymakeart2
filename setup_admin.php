<?php
if (session_status() === PHP_SESSION_NONE) session_start();
$conn = new mysqli('localhost', 'root', '', 'mymakeart');
if ($conn->connect_error) {
    die("資料庫連接失敗：" . $conn->connect_error);
}

// 設定 admin 登入資訊
$username = 'admin';
$password = 't55220'; // 原始密碼
$employee_number = 'admin_0';
$hashed_password = password_hash($password, PASSWORD_DEFAULT);

// 檢查是否已存在 admin 帳號
$check = $conn->prepare("SELECT * FROM employees WHERE username = ?");
$check->bind_param("s", $username);
$check->execute();
$result = $check->get_result();

if ($result->num_rows > 0) {
    echo "<h2>❗️ 帳號 admin 已存在，無需重複建立。</h2>";
} else {
    $stmt = $conn->prepare("INSERT INTO employees 
        (employee_number, username, password, name, email, role, company_id, role_id) 
        VALUES (?, ?, ?, ?, ?, 'admin', NULL, NULL)");
    $name = "系統管理員";
    $email = "admin@example.com";
    $stmt->bind_param("sssss", $employee_number, $username, $hashed_password, $name, $email);
    if ($stmt->execute()) {
        echo "<h2>✅ 成功建立 admin 帳號！</h2>";
        echo "<p>帳號：<strong>admin</strong></p>";
        echo "<p>密碼：<strong>t55220</strong></p>";
    } else {
        echo "<h2>❌ 建立 admin 帳號失敗：</h2><p>" . $stmt->error . "</p>";
    }
}
?>
