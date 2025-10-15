<?php
session_start();
if (!isset($_SESSION['user'])) {
    header('Location: ../login.php');
    exit;
}

$conn = new mysqli('localhost', 'root', '', 'mymakeart');
if ($conn->connect_error) {
    die("資料庫連接失敗：" . $conn->connect_error);
}

$employee_number = $_SESSION['user']['employee_number'] ?? null;
if (!$employee_number) {
    die("未能獲取員工編號，請重新登入！");
}

$success = $error = "";

// 撈取假別
$leave_types_result = $conn->query("SELECT name FROM leave_types");
$leave_types = [];
while ($row = $leave_types_result->fetch_assoc()) {
    $leave_types[] = $row['name'];
}

// 提交表單
if ($_SERVER['REQUEST_METHOD'] === 'POST') {
    $type = ($_POST['type'] === 'leave') ? '請假' : '加班';
    $subtype = $_POST['subtype'] ?? null;
    $reason = $_POST['reason'];
    $start_datetime = $_POST['start_datetime'];
    $end_datetime = $_POST['end_datetime'];

    if (strtotime($start_datetime) > strtotime($end_datetime)) {
        $error = "❌ 起始時間不能晚於結束時間！";
    } else {
        $stmt = $conn->prepare("INSERT INTO requests (employee_number, type, subtype, reason, start_date, end_date, status) VALUES (?, ?, ?, ?, ?, ?, 'pending')");
        $stmt->bind_param('ssssss', $employee_number, $type, $subtype, $reason, $start_datetime, $end_datetime);
        if ($stmt->execute()) {
            $success = "✅ 申請已提交！";
        } else {
            $error = "❌ 提交失敗，請稍後再試。";
        }
    }
}
?>
<!DOCTYPE html>
<html lang="zh-TW">
<head>
    <meta charset="UTF-8">
    <title>申請請假/加班</title>
    <meta name="viewport" content="width=device-width, initial-scale=1.0">
    <!-- Bootstrap 與自訂樣式 -->
    <link rel="stylesheet" href="https://cdn.jsdelivr.net/npm/bootstrap@5.3.3/dist/css/bootstrap.min.css">
    <link rel="stylesheet" href="employee_navbar.css">
    <link rel="stylesheet" href="styles.css">
</head>
<body class="bg-light">
<?php include 'employee_navbar.php'; ?>

<div class="container my-4">
    <h4 class="mb-3"><i class="bi bi-calendar-plus me-2"></i>申請請假/加班</h4>

    <?php if (!empty($error)): ?>
        <div class="alert alert-danger"><?= $error ?></div>
    <?php elseif (!empty($success)): ?>
        <div class="alert alert-success"><?= $success ?></div>
    <?php endif; ?>

    <form method="POST" action="" class="bg-white p-4 border rounded shadow-sm">
        <div class="row mb-3">
            <div class="col-md-4">
                <label for="type" class="form-label">申請類型：</label>
                <select id="type" name="type" class="form-select" required>
                    <option value="leave">請假</option>
                    <option value="overtime">加班</option>
                </select>
            </div>
            <div class="col-md-4" id="subtype-container">
                <label for="subtype" class="form-label">假別：</label>
                <select id="subtype" name="subtype" class="form-select">
                    <option value="特休假">特休假</option>
                    <?php foreach ($leave_types as $leave): ?>
                        <option value="<?= htmlspecialchars($leave) ?>"><?= htmlspecialchars($leave) ?></option>
                    <?php endforeach; ?>
                </select>
            </div>
        </div>

        <div class="mb-3">
            <label for="reason" class="form-label">申請理由：</label>
            <textarea id="reason" name="reason" rows="3" class="form-control" required></textarea>
        </div>

        <div class="row mb-3">
            <div class="col-md-6">
                <label for="start_datetime" class="form-label">起始時間：</label>
                <input type="datetime-local" id="start_datetime" name="start_datetime" class="form-control" required>
            </div>
            <div class="col-md-6">
                <label for="end_datetime" class="form-label">結束時間：</label>
                <input type="datetime-local" id="end_datetime" name="end_datetime" class="form-control" required>
            </div>
        </div>

        <div class="text-center">
            <button type="submit" class="btn btn-primary px-4">提交申請</button>
        </div>
    </form>
</div>

<script>
    const typeSelect = document.getElementById('type');
    const subtypeContainer = document.getElementById('subtype-container');

    typeSelect.addEventListener('change', function () {
        if (this.value === 'overtime') {
            subtypeContainer.style.display = 'none';
        } else {
            subtypeContainer.style.display = 'block';
        }
    });

    if (typeSelect.value === 'overtime') {
        subtypeContainer.style.display = 'none';
    }
</script>
</body>
</html>
