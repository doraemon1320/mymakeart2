<?php
require_once "db_connect.php";

// ✅ 僅限 admin 登入
if ($_SESSION['user']['username'] !== 'admin') {
    echo "<h2>此頁僅限系統管理員（admin）使用</h2>";
    exit;
}

// ✅ 處理核准與駁回
if ($_SERVER['REQUEST_METHOD'] === 'POST') {
    $action = $_POST['action'];
    $request_id = $_POST['request_id'];

    // 讀取申請資料
    $stmt = $conn->prepare("SELECT * FROM company_requests WHERE id = ? AND status = 'pending'");
    $stmt->bind_param("i", $request_id);
    $stmt->execute();
    $request = $stmt->get_result()->fetch_assoc();

    if (!$request) {
        $message = "申請資料不存在或已被處理";
    } elseif ($action === 'approve') {
        // ✅ 1. 建立公司
        $stmt = $conn->prepare("INSERT INTO companies (name, code) VALUES (?, ?)");
        $company_code = 'CMP' . time(); // 可改為唯一編碼邏輯
        $stmt->bind_param("ss", $request['company_name'], $company_code);
        $stmt->execute();
        $company_id = $stmt->insert_id;

        // ✅ 2. 建立該公司最高角色（ADMIN）
        $role_name = '最高管理人';
        $stmt = $conn->prepare("INSERT INTO roles (company_id, name, description) VALUES (?, ?, ?)");
        $desc = "此角色擁有該公司完整存取權限";
        $stmt->bind_param("iss", $company_id, $role_name, $desc);
        $stmt->execute();
        $role_id = $stmt->insert_id;

        // ✅ 3. 建立該員工
        $stmt = $conn->prepare("INSERT INTO employees 
            (employee_number, username, password, name, email, role, company_id, role_id) 
            VALUES (?, ?, ?, ?, ?, 'admin', ?, ?)");
        $emp_no = 'admin_' . $company_id;
        $stmt->bind_param("ssssssi", $emp_no, $request['username'], $request['password'], $request['contact_person'], $request['email'], $company_id, $role_id);
        $stmt->execute();

        // ✅ 4. 更新該筆申請為已核准
        $stmt = $conn->prepare("UPDATE company_requests SET status = 'approved' WHERE id = ?");
        $stmt->bind_param("i", $request_id);
        $stmt->execute();

        $message = "✅ 已成功核准並建立公司與最高權限帳號";
    } elseif ($action === 'reject') {
        $stmt = $conn->prepare("UPDATE company_requests SET status = 'rejected' WHERE id = ?");
        $stmt->bind_param("i", $request_id);
        $stmt->execute();
        $message = "❌ 已駁回申請";
    }
}

// 🔍 抓取所有待審核資料
$pending = $conn->query("SELECT * FROM company_requests WHERE status = 'pending' ORDER BY created_at DESC");
?>

<!DOCTYPE html>
<html lang="zh-Hant">
<head>
    <meta charset="UTF-8">
    <title>公司帳號審核 - 系統管理員</title>
    <link rel="stylesheet" href="company_requests.css">
</head>
<body>
<div class="container">
    <h1>待審核公司帳號申請</h1>

    <?php if (!empty($message)): ?>
        <p class="notice"><?= $message ?></p>
    <?php endif; ?>

    <?php if ($pending->num_rows === 0): ?>
        <p class="empty">目前沒有待審核的申請。</p>
    <?php else: ?>
        <table>
            <thead>
            <tr>
                <th>公司名稱</th>
                <th>帳號</th>
                <th>Email</th>
                <th>聯絡人</th>
                <th>電話</th>
                <th>申請時間</th>
                <th>操作</th>
            </tr>
            </thead>
            <tbody>
            <?php while ($row = $pending->fetch_assoc()): ?>
                <tr>
                    <td><?= htmlspecialchars($row['company_name']) ?></td>
                    <td><?= htmlspecialchars($row['username']) ?></td>
                    <td><?= htmlspecialchars($row['email']) ?></td>
                    <td><?= htmlspecialchars($row['contact_person']) ?></td>
                    <td><?= htmlspecialchars($row['contact_phone']) ?></td>
                    <td><?= $row['created_at'] ?></td>
                    <td>
                        <form method="post" style="display:inline;">
                            <input type="hidden" name="request_id" value="<?= $row['id'] ?>">
                            <button type="submit" name="action" value="approve">✅ 核准</button>
                            <button type="submit" name="action" value="reject">❌ 駁回</button>
                        </form>
                    </td>
                </tr>
            <?php endwhile; ?>
            </tbody>
        </table>
    <?php endif; ?>
</div>
</body>
</html>
