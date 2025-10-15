<?php
session_start();

// ✅ 登入權限檢查（只允許 admin）
if (!isset($_SESSION['user']) || $_SESSION['user']['role'] !== 'admin') {
    header('Location: login.php');
    exit;
}

$conn = new mysqli('localhost', 'root', '', 'mymakeart');
if ($conn->connect_error) {
    die("資料庫連接失敗：" . $conn->connect_error);
}

$message = '';
$insert_count = 0;
$skip_count = 0;

if ($_SERVER['REQUEST_METHOD'] === 'POST' && isset($_FILES['holidays_file'])) {
    $file_tmp = $_FILES['holidays_file']['tmp_name'];
    $file_type = $_FILES['holidays_file']['type'];

    if ($file_type === 'application/json') {
        $json_data = file_get_contents($file_tmp);
        $data = json_decode($json_data, true);

        if ($data !== null && isset($data['holidays']) && isset($data['workdays'])) {
            $stmt_check = $conn->prepare("SELECT 1 FROM holidays WHERE holiday_date = ?");
            $stmt_insert = $conn->prepare("
                INSERT INTO holidays (holiday_date, description, is_working_day) 
                VALUES (?, ?, ?)
            ");

            foreach ($data['holidays'] as $date => $desc) {
                $stmt_check->bind_param('s', $date);
                $stmt_check->execute();
                $stmt_check->store_result();

                if ($stmt_check->num_rows === 0) {
                    $is_working_day = 0;
                    $stmt_insert->bind_param('ssi', $date, $desc, $is_working_day);
                    $stmt_insert->execute();
                    $insert_count++;
                } else {
                    $skip_count++;
                }
            }

            foreach ($data['workdays'] as $date => $desc) {
                $stmt_check->bind_param('s', $date);
                $stmt_check->execute();
                $stmt_check->store_result();

                if ($stmt_check->num_rows === 0) {
                    $is_working_day = 1;
                    $stmt_insert->bind_param('ssi', $date, $desc, $is_working_day);
                    $stmt_insert->execute();
                    $insert_count++;
                } else {
                    $skip_count++;
                }
            }

            $message = "✅ 匯入完成：新增 {$insert_count} 筆，略過 {$skip_count} 筆已存在日期。";
        } else {
            $message = "❌ JSON 格式錯誤或內容不完整。";
        }
    } else {
        $message = "❌ 請上傳副檔名為 .json 的檔案。";
    }
}
?>

<!DOCTYPE html>
<html lang="zh-TW">
<head>
    <meta charset="UTF-8">
    <title>上傳台灣假日資料</title>
    <link href="https://cdn.jsdelivr.net/npm/bootstrap@5.3.3/dist/css/bootstrap.min.css" rel="stylesheet">
    <link rel="stylesheet" href="admin_navbar.css">
    <style>
        pre {
            background: #f8f9fa;
            padding: 10px;
            border: 1px solid #dee2e6;
            font-family: monospace;
            font-size: 14px;
            white-space: pre-wrap;
        }
    </style>
</head>
<body>
<?php include 'admin_navbar.php';?>
<div class="container mt-4">
    <h1 class="mb-3">上傳台灣假日 JSON 檔案</h1>
    <p>此功能將台灣官方假日與補班日匯入系統，<strong class="text-danger">系統會自動略過已存在的日期</strong>。</p>

    <div class="mb-3">
        <strong>格式範例：</strong>
        <pre>
{
  "holidays": {
    "2025-01-01": "元旦",
    "2025-02-28": "和平紀念日"
  },
  "workdays": {
    "2025-02-17": "補班日"
  }
}
        </pre>
    </div>

    <?php if (!empty($message)): ?>
        <div class="alert <?= ($insert_count > 0) ? 'alert-success' : 'alert-danger' ?>">
            <?= htmlspecialchars($message) ?>
        </div>
    <?php endif; ?>

    <form method="POST" enctype="multipart/form-data" class="border p-3 rounded bg-light">
        <div class="mb-3">
            <label for="holidays_file" class="form-label">選擇 JSON 檔案：</label>
            <input type="file" name="holidays_file" id="holidays_file" accept=".json" required class="form-control">
        </div>
        <button type="submit" class="btn btn-primary">上傳並匯入</button>
    </form>
</div>

<script src="https://cdn.jsdelivr.net/npm/bootstrap@5.3.3/dist/js/bootstrap.bundle.min.js"></script>
</body>
</html>
