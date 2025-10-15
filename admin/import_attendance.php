<?php
session_start();

if (!isset($_SESSION['user']) || $_SESSION['user']['role'] !== 'admin') {
    header('Location: login.php');
    exit;
}

$conn = new mysqli('localhost', 'root', '', 'mymakeart');
$conn->set_charset('utf8mb4');
if ($conn->connect_error) {
    die("資料庫連線失敗：" . $conn->connect_error);
}

$message = "";
$error = "";
$failed_records = [];

if ($_SERVER['REQUEST_METHOD'] === 'POST') {
    if (isset($_FILES['attendance_file']) && $_FILES['attendance_file']['error'] === UPLOAD_ERR_OK) {
        $file_tmp_path = $_FILES['attendance_file']['tmp_name'];
        $file_content = file_get_contents($file_tmp_path);
        $file_content_utf8 = mb_convert_encoding($file_content, 'UTF-8', 'BIG5');
        $rows = explode("\n", trim($file_content_utf8));

        $success_count = 0;
        $failure_count = 0;

        foreach ($rows as $row) {
            $fields = str_getcsv($row);

            if (count($fields) === 5) {
                $log_date = DateTime::createFromFormat('Ymd', trim($fields[0]))->format('Y-m-d');
                $log_time = substr(trim($fields[1]), 0, 2) . ':' . substr(trim($fields[1]), 2, 2) . ':00';
                $employee_number = trim($fields[2]);
                $attendance_method = trim($fields[3]);
                $device_code = trim($fields[4]);

                $stmt_check = $conn->prepare("SELECT id FROM employees WHERE employee_number = ?");
                $stmt_check->bind_param('s', $employee_number);
                $stmt_check->execute();
                $result = $stmt_check->get_result();

                if ($result->num_rows === 0) {
                    $failure_count++;
                    $failed_records[] = "❌ 員工編號不存在：" . $row;
                    continue;
                }

                $valid_methods = ['指紋', '刷卡', '人臉', '掌靜脈'];
                if (!in_array($attendance_method, $valid_methods)) {
                    $failure_count++;
                    $failed_records[] = "❌ 考勤方式錯誤：" . $row;
                    continue;
                }

                $stmt = $conn->prepare("INSERT INTO attendance_logs (log_date, log_time, employee_number, attendance_method, device_code) VALUES (?, ?, ?, ?, ?)");
                $stmt->bind_param('sssss', $log_date, $log_time, $employee_number, $attendance_method, $device_code);

                if ($stmt->execute()) {
                    $success_count++;
                } else {
                    $failure_count++;
                    $failed_records[] = "❌ 插入失敗：" . $row;
                }
            } else {
                $failure_count++;
                $failed_records[] = "❌ 資料格式錯誤：" . $row;
            }
        }

        $message = "✅ 匯入完成！成功匯入：$success_count 筆，失敗：$failure_count 筆。";
    } else {
        $error = "❌ 上傳失敗，請選擇正確的 TXT 檔案並重試。";
    }
}
?>

<!DOCTYPE html>
<html lang="zh-TW">
<head>
    <meta charset="UTF-8">
    <meta name="viewport" content="width=device-width, initial-scale=1.0">
    <title>匯入考勤資料</title>
    <link href="https://cdn.jsdelivr.net/npm/bootstrap@5.3.3/dist/css/bootstrap.min.css" rel="stylesheet">
    <link rel="stylesheet" href="admin_navbar.css">
</head>
<body>
    <?php include 'admin_navbar.php'; ?>	
	
<div class="container mt-4">


    <h2 class="mb-3">匯入考勤資料</h2>
    <p class="text-muted">請選擇包含考勤數據的 TXT 檔案，上傳後系統會自動分析並匯入資料庫。</p>

    <form method="POST" enctype="multipart/form-data" class="mb-4">
        <div class="mb-3">
            <label for="attendance_file" class="form-label">選擇 TXT 檔案：</label>
            <input type="file" class="form-control" id="attendance_file" name="attendance_file" accept=".txt" required>
        </div>
        <button type="submit" class="btn btn-primary">上傳並匯入</button>
    </form>

    <?php if (!empty($message)): ?>
        <div class="alert alert-success"> <?= htmlspecialchars($message) ?> </div>
    <?php elseif (!empty($error)): ?>
        <div class="alert alert-danger"> <?= htmlspecialchars($error) ?> </div>
    <?php endif; ?>

    <?php if (!empty($failed_records)): ?>
        <div class="alert alert-warning">
            <h5 class="alert-heading">⚠️ 無法匯入的記錄</h5>
            <ul class="mb-0">
                <?php foreach ($failed_records as $failed_record): ?>
                    <li><?= htmlspecialchars($failed_record) ?></li>
                <?php endforeach; ?>
            </ul>
        </div>
    <?php endif; ?>
</div>
</body>
</html>
