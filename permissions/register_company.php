<?php

require_once "db_connect.php";

$errors = [];
$success = "";
$step = 'form'; // 預設流程

if (!isset($_SESSION['verification_code'])) {
    $_SESSION['verification_code'] = rand(100000, 999999);
}

// 第一階段：送出表單
if ($_SERVER["REQUEST_METHOD"] == "POST" && isset($_POST['submit_step1'])) {
    $form = [
        'company_name' => trim($_POST['company_name']),
        'username' => trim($_POST['username']),
        'password' => $_POST['password'],
        'confirm_password' => $_POST['confirm_password'],
        'email' => trim($_POST['email']),
        'company_tax_id' => trim($_POST['company_tax_id']),
        'contact_person' => trim($_POST['contact_person']),
        'contact_phone' => trim($_POST['contact_phone']),
        'owner' => trim($_POST['owner'])
    ];

    foreach (['company_name', 'username', 'password', 'confirm_password', 'email', 'company_tax_id', 'contact_person', 'contact_phone'] as $field) {
        if (empty($form[$field])) {
            $errors[] = "請填寫所有必填欄位";
            break;
        }
    }

    if ($form['password'] !== $form['confirm_password']) {
        $errors[] = "兩次輸入的密碼不一致";
    }

    $stmt = $conn->prepare("SELECT * FROM company_requests WHERE username = ? AND status = 'pending'");
    $stmt->bind_param("s", $form['username']);
    $stmt->execute();
    if ($stmt->get_result()->num_rows > 0) {
        $errors[] = "此帳號已申請，請等待審核或使用其他帳號";
    }

    if (empty($errors)) {
        $_SESSION['form_data'] = $form;
        $_SESSION['verification_code'] = rand(100000, 999999);
        $step = 'verify';
    }
}

// 第二階段：驗證碼
if ($_SERVER["REQUEST_METHOD"] == "POST" && isset($_POST['submit_step2'])) {
    $user_code = $_POST['verification_code'];
    if ($user_code == $_SESSION['verification_code']) {
        $form = $_SESSION['form_data'];
        $hash = password_hash($form['password'], PASSWORD_DEFAULT);
        $stmt = $conn->prepare("INSERT INTO company_requests 
            (company_name, username, password, email, company_tax_id, contact_person, contact_phone, owner, status) 
            VALUES (?, ?, ?, ?, ?, ?, ?, ?, 'pending')");
        $stmt->bind_param("ssssssss", $form['company_name'], $form['username'], $hash, $form['email'], $form['company_tax_id'], $form['contact_person'], $form['contact_phone'], $form['owner']);
        $stmt->execute();

        $success = "申請成功！請等待系統管理員審核";
        unset($_SESSION['form_data'], $_SESSION['verification_code']);
        $step = 'done';
    } else {
        $errors[] = "驗證碼錯誤，請重新輸入";
        $step = 'verify';
    }
}
?>

<!DOCTYPE html>
<html lang="zh-Hant">
<head>
    <meta charset="UTF-8">
    <title>公司帳號申請</title>
    <link rel="stylesheet" href="permissions.css">

	
</head>
<body>
<div class="container">
    <h1>公司帳號申請</h1>

    <?php foreach ($errors as $e): ?>
        <p class="error"><?= $e ?></p>
    <?php endforeach; ?>

    <?php if ($step === 'form'): ?>
        <form method="post">
            <label>公司名稱（必填）</label>
            <input type="text" name="company_name" required>

            <label>帳號（必填）</label>
            <input type="text" name="username" required>

            <label>密碼（必填）</label>
            <input type="password" name="password" required>

            <label>再次輸入密碼（必填）</label>
            <input type="password" name="confirm_password" required>

            <label>Email（必填）</label>
            <input type="email" name="email" required>

            <label>公司統編（必填）</label>
            <input type="text" name="company_tax_id" required>

            <label>聯絡人（必填）</label>
            <input type="text" name="contact_person" required>

            <label>聯絡人電話（必填）</label>
            <input type="text" name="contact_phone" required>

            <label>公司負責人（選填）</label>
            <input type="text" name="owner">

            <button type="submit" name="submit_step1">下一步</button>
        </form>

    <?php elseif ($step === 'verify'): ?>
        <form method="post">
            <p>模擬驗證碼已寄出，請輸入驗證碼完成申請。</p>
            <p><strong>您的驗證碼是：<?= $_SESSION['verification_code'] ?></strong></p>

            <label>請輸入驗證碼：</label>
            <input type="text" name="verification_code" required>

            <button type="submit" name="submit_step2">送出申請</button>
        </form>

    <?php elseif ($step === 'done'): ?>
        <p class="success"><?= $success ?></p>
        <a href="register_company.php">返回重新申請</a>
    <?php endif; ?>
</div>
</body>
</html>
