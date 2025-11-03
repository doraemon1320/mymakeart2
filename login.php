<?php
// (PHP-1) 啟動 Session 與載入資料庫連線
if (session_status() === PHP_SESSION_NONE) {
    session_start();
}
require_once "db_connect.php";

// (PHP-2) 預設狀態與表單回填值
$error = "";
$values = [
    'username' => ''
];

// (PHP-3) 監聽登入請求
if ($_SERVER['REQUEST_METHOD'] === 'POST') {
    // (PHP-3-1) 取得並整理表單輸入
    $values['username'] = trim($_POST['username'] ?? '');
    $password = $_POST['password'] ?? '';

    // (PHP-3-2) 驗證帳號密碼
    $stmt = $conn->prepare("SELECT id, employee_number, username, name, role, company_id, role_id, password FROM employees WHERE username = ?");
    $stmt->bind_param("s", $values['username']);
    $stmt->execute();
    $result = $stmt->get_result();
    $user = $result->fetch_assoc();

    // (PHP-3-3) 建立登入 Session 並導向員工首頁
    if ($user && password_verify($password, $user['password'])) {
        $_SESSION['user'] = [
            'id'              => $user['id'],
            'employee_number' => $user['employee_number'],
            'username'        => $user['username'],
            'name'            => $user['name'],
            'role'            => $user['role'],
	    'is_manager'     => (int)($user['is_manager'] ?? 0),
            'company_id'      => $user['company_id'],
            'role_id'         => $user['role_id'],
            'login_as'        => 'employee'
        ];

        header("Location: employee/employee_home.php");
        exit;
    }

    // (PHP-3-4) 回應錯誤訊息
    $error = "帳號或密碼錯誤，請重新輸入";
}
?>

<!DOCTYPE html>
<html lang="zh-Hant">
<head>
    <meta charset="UTF-8">
    <title>登入系統</title>
    <link rel="stylesheet" href="https://cdn.jsdelivr.net/npm/bootstrap@5.3.3/dist/css/bootstrap.min.css">
    <link rel="stylesheet" href="login.css">

</head>
<body class="login-body">
<div class="container py-5">
    <div class="row justify-content-center">
        <div class="col-md-8 col-lg-6">
            <div class="login-frame position-relative overflow-hidden">
                <span class="decor decor-1"></span>
                <span class="decor decor-2"></span>
                <div class="login-surface position-relative bg-white rounded-4 border border-primary-subtle shadow-sm p-4 p-md-5">
                    <div class="text-center mb-4">
                        <div class="brand-logo mx-auto mb-3">
                            <img src="LOGO/LOGO-06.png" alt="MY創藝企業識別" class="img-fluid">
                        </div>
                        <h1 class="fw-bold text-brand-blue mb-1">員工登入</h1>
                        <p class="text-brand-gray mb-0">請使用公司提供之帳號密碼完成登入</p>
                    </div>

                    <?php if ($error): ?>
                        <div class="alert alert-danger mb-4" role="alert">
                            <?= htmlspecialchars($error) ?>
                        </div>
                    <?php endif; ?>

                    <form method="post" class="needs-validation" novalidate>
                        <div class="table-responsive mb-0">
                            <table class="table table-bordered align-middle mb-4 brand-table">
                                <thead class="table-primary text-center">
                                    <tr>
                                        <th colspan="2" class="py-3">登入資料填寫</th>
                                    </tr>
                                </thead>
                                <tbody>
                                    <tr>
                                        <th scope="row" class="text-nowrap text-center bg-brand-light">帳號</th>
                                        <td>
                                            <input type="text" id="username" name="username" class="form-control" value="<?= htmlspecialchars($values['username']) ?>" required>
                                            <div class="invalid-feedback">請輸入帳號</div>
                                        </td>
                                    </tr>
                                    <tr>
                                        <th scope="row" class="text-nowrap text-center bg-brand-light">密碼</th>
                                        <td>
                                            <input type="password" id="password" name="password" class="form-control" required>
                                            <div class="invalid-feedback">請輸入密碼</div>
                                        </td>
                                    </tr>
                                    <tr>
                                        <td colspan="2" class="text-center bg-brand-light">
                                            <button id="ligin" type="submit" class="btn btn-brand px-5">登入</button>
                                        </td>
                                    </tr>
                                </tbody>
                            </table>
                        </div>
                    </form>
                </div>
            </div>
        </div>
    </div>
</div>

<script src="https://code.jquery.com/jquery-3.7.1.min.js"></script>
<script src="https://cdn.jsdelivr.net/npm/bootstrap@5.3.3/dist/js/bootstrap.bundle.min.js"></script>
<script>
// (JS-1) 初始化頁面互動
$(function () {
    const form = $("form.needs-validation");

    // (JS-2) 啟動 Bootstrap 驗證流程
    form.on("submit", function (event) {
        if (!this.checkValidity()) {
            event.preventDefault();
            event.stopPropagation();
        }
        $(this).addClass("was-validated");
    });

    // (JS-3) 聚焦與快捷鍵
    $("#username").trigger("focus");
    $("#password").on("keypress", function (event) {
        if (event.key === "Enter") {
            form.trigger("submit");
        }
    });
});
</script>
</body>
</html>
