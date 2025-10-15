<?php
include 'db_connect.php'; // 連線資料庫


// 取得當前年、月，並設定預設為上個月
$current_year = date("Y");
$current_month = date("m") - 1;
if ($current_month == 0) {
    $current_month = 12;
    $current_year -= 1;
}

// 設定預設的年份和月份
$selected_year = isset($_POST['year']) ? $_POST['year'] : $current_year;
$selected_month = isset($_POST['month']) ? $_POST['month'] : $current_month;

$employees = [];
$message = "";

// **🔹 自動查詢符合特休條件的員工**
$sql = "SELECT id, name, hire_date, TIMESTAMPDIFF(MONTH, hire_date, '$selected_year-$selected_month-01') AS months_of_service FROM employees";
$result = $conn->query($sql);

while ($row = $result->fetch_assoc()) {
    $months_of_service = intval($row['months_of_service']);

    if ($months_of_service > 0) {
        // **查詢是否符合特休條件**
        $sql_policy = "SELECT days FROM annual_leave_policy WHERE FLOOR(years_of_service * 12) = $months_of_service LIMIT 1";
        $result_policy = $conn->query($sql_policy);

        if ($result_policy && $result_policy->num_rows > 0) {
            $policy = $result_policy->fetch_assoc();
            $row['leave_days'] = $policy['days'];
            $employees[] = $row;
        }
    }
}

// **如果沒有符合條件的員工，顯示提示訊息**
if (empty($employees)) {
    $message = "本月無年資政策符合員工";
}

// **🔹 計算並儲存特休**
if ($_SERVER["REQUEST_METHOD"] == "POST" && isset($_POST['save'])) {
    if (!empty($_POST['employees'])) {
        foreach ($_POST['employees'] as $employee) {
            $employee_id = intval($employee['id']);
            $leave_days = intval($employee['leave_days']);

            // **檢查是否已經有相同年月的特休**
            $check_sql = "SELECT COUNT(*) AS total FROM annual_leave_records WHERE employee_id = $employee_id AND year = $selected_year AND month = $selected_month";
            $check_result = $conn->query($check_sql);
            $check_row = $check_result->fetch_assoc();

            if ($check_row['total'] == 0 && $leave_days > 0) {
                // **新增特休**
				$insert_sql = "INSERT INTO annual_leave_records (employee_id, year, month, days, status) 
				VALUES ($employee_id, $selected_year, $selected_month, $leave_days, '取得')";
                $conn->query($insert_sql);
            }
        }
        echo "<script>alert('✅ 特休已成功新增！');</script>";
    } else {
        echo "<script>alert('❌ 沒有符合條件的員工需要新增特休！');</script>";
    }
}

// 取得特休紀錄
$sql_records = "
    SELECT a.id, e.name, a.year, a.month, a.days, a.status, a.created_at 
    FROM annual_leave_records a
    JOIN employees e ON a.employee_id = e.id
    ORDER BY a.created_at DESC";
$result_records = $conn->query($sql_records);
$sql = "
    SELECT 
        e.id,
        e.name,
        SUM(CASE WHEN ar.status = '取得' THEN COALESCE(ar.days, 0) ELSE 0 END) AS total_acquired_days,
        SUM(CASE WHEN ar.status = '取得' THEN COALESCE(ar.hours, 0) ELSE 0 END) AS total_acquired_hours,
        SUM(CASE WHEN ar.status = '使用' THEN COALESCE(ar.days, 0) ELSE 0 END) AS total_used_days,
        SUM(CASE WHEN ar.status = '使用' THEN COALESCE(ar.hours, 0) ELSE 0 END) AS total_used_hours,
        SUM(CASE WHEN ar.status = '轉現金' THEN COALESCE(ar.days, 0) ELSE 0 END) AS total_cash_days,
        SUM(CASE WHEN ar.status = '轉現金' THEN COALESCE(ar.hours, 0) ELSE 0 END) AS total_cash_hours
    FROM annual_leave_records ar
    JOIN employees e ON ar.employee_id = e.id
    GROUP BY ar.employee_id
";
$result = $conn->query($sql);
$summary_result = [];

while ($row = $result->fetch_assoc()) {
    // 計算總剩餘時數（以小時計算）
    $total_hours = 
        ($row['total_acquired_days'] * 8 + $row['total_acquired_hours']) -
        ($row['total_used_days'] * 8 + $row['total_used_hours']) -
        ($row['total_cash_days'] * 8 + $row['total_cash_hours']);

    // 篩選：若三者皆為 0，表示無特休紀錄 ➜ 不加入彙總
    if (
        floatval($row['total_acquired_days']) === 0.0 &&
        floatval($row['total_used_days']) === 0.0 &&
        floatval($row['total_cash_days']) === 0.0 &&
        floatval($row['total_acquired_hours']) === 0.0 &&
        floatval($row['total_used_hours']) === 0.0 &&
        floatval($row['total_cash_hours']) === 0.0
    ) {
        continue;
    }

    // 拆分為剩餘天 + 小時（1 天 = 8 小時）
    $row['remaining_days'] = floor($total_hours / 8);
    $row['remaining_hours'] = fmod($total_hours, 8);

    $summary_result[] = $row;
}

?>

<!DOCTYPE html>
<html lang="zh-TW">
<head>
    <meta charset="UTF-8">
    <meta name="viewport" content="width=device-width, initial-scale=1.0">
    <title>特休計算</title>
    <link href="https://cdn.jsdelivr.net/npm/bootstrap@5.3.3/dist/css/bootstrap.min.css" rel="stylesheet">
    <link rel="stylesheet" href="admin_navbar.css">
</head>
<body>
	 <?php include 'admin_navbar.php'; ?>
<div class="container mt-4">
   
    <h2 class="mb-4">查詢符合年資政策的員工</h2>

    <form method="post" id="dateForm" class="row g-3 mb-4">
        <div class="col-md-3">
            <label for="year" class="form-label">選擇年份：</label>
            <input type="number" id="year" name="year" value="<?= $selected_year ?>" required class="form-control" onchange="reloadPage()">
        </div>
        <div class="col-md-3">
            <label for="month" class="form-label">選擇月份：</label>
            <select id="month" name="month" class="form-select" onchange="reloadPage()">
                <?php for ($m = 1; $m <= 12; $m++): ?>
                    <option value="<?= $m ?>" <?= ($m == $selected_month) ? 'selected' : '' ?>><?= $m ?></option>
                <?php endfor; ?>
            </select>
        </div>
    </form>

    <?php if (!empty($message)): ?>
        <div class="alert alert-warning"><?= $message ?></div>
    <?php endif; ?>

    <?php if (!empty($employees)): ?>
    <div id="eligible-employees" class="mb-5">
        <h4>符合年資政策的員工</h4>
        <form method="post">
            <table class="table table-bordered">
                <thead class="table-primary">
                    <tr>
                        <th>員工名稱</th>
                        <th>年資（個月）</th>
                        <th>符合的特休天數</th>
                    </tr>
                </thead>
                <tbody>
                    <?php foreach ($employees as $employee): ?>
                    <tr>
                        <td><?= $employee['name'] ?></td>
                        <td><?= $employee['months_of_service'] ?> 個月（約 <?= round($employee['months_of_service'] / 12, 2) ?> 年）</td>
                        <td><?= $employee['leave_days'] ?> 天</td>
                    </tr>
                    <input type="hidden" name="employees[<?= $employee['id'] ?>][id]" value="<?= $employee['id'] ?>">
                    <input type="hidden" name="employees[<?= $employee['id'] ?>][leave_days]" value="<?= $employee['leave_days'] ?>">
                    <?php endforeach; ?>
                </tbody>
            </table>
            <input type="hidden" name="year" value="<?= $selected_year ?>">
            <input type="hidden" name="month" value="<?= $selected_month ?>">
            <button type="submit" name="save" class="btn btn-success">計算並儲存</button>
        </form>
    </div>
    <?php endif; ?>

    <div id="vacation-records" class="mb-5">
        <h4>特休紀錄</h4>
        <table class="table table-bordered">
            <thead class="table-primary">
                <tr>
                    <th>員工名稱</th>
                    <th>年份</th>
                    <th>月份</th>
                    <th>特休天數</th>
                    <th>狀態</th>
                    <th>建立時間</th>
                </tr>
            </thead>
            <tbody>
                <?php while ($row = $result_records->fetch_assoc()): ?>
                <tr>
                    <td><?= $row['name'] ?></td>
                    <td><?= $row['year'] ?></td>
                    <td><?= $row['month'] ?></td>
                    <td><?= $row['days'] ?></td>
                    <td><?= $row['status'] ?></td>
                    <td><?= $row['created_at'] ?></td>
                </tr>
                <?php endwhile; ?>
            </tbody>
        </table>
    </div>

    <div id="summary-records" class="mb-5">
        <h4>員工特休彙總紀錄</h4>
        <table class="table table-bordered">
            <thead class="table-primary">
                <tr>
                    <th>員工名稱</th>
                    <th>取得特休天數</th>
                    <th>取得特休小時</th>
                    <th>已使用特休天數</th>
                    <th>已使用特休小時</th>
                    <th>已轉現金特休天數</th>
                    <th>已轉現金特休小時</th>
                    <th>剩餘特休天數</th>
                    <th>剩餘特休小時</th>
                </tr>
            </thead>
            <tbody>
                <?php foreach ($summary_result as $row): ?>
                <tr>
                    <td><?= htmlspecialchars($row['name']) ?></td>
                    <td><?= $row['total_acquired_days'] ?></td>
                    <td><?= $row['total_acquired_hours'] ?></td>
                    <td><?= $row['total_used_days'] ?></td>
                    <td><?= $row['total_used_hours'] ?></td>
                    <td><?= $row['total_cash_days'] ?></td>
                    <td><?= $row['total_cash_hours'] ?></td>
                    <td><?= $row['remaining_days'] ?></td>
                    <td><?= $row['remaining_hours'] ?></td>
                </tr>
                <?php endforeach; ?>
            </tbody>
        </table>
    </div>

    <h5>選擇要匯出的表格</h5>
    <div class="mb-3">
        <div class="form-check">
            <input type="checkbox" class="form-check-input export-checkbox" value="eligible-employees" id="chk1">
            <label class="form-check-label" for="chk1">符合年資政策的員工</label>
        </div>
        <div class="form-check">
            <input type="checkbox" class="form-check-input export-checkbox" value="vacation-records" id="chk2">
            <label class="form-check-label" for="chk2">特休紀錄</label>
        </div>
        <div class="form-check">
            <input type="checkbox" class="form-check-input export-checkbox" value="summary-records" id="chk3">
            <label class="form-check-label" for="chk3">特休彙總紀錄</label>
        </div>
        <button type="button" class="btn btn-secondary mt-2" onclick="exportSelectedSections()">匯出圖片</button>
    </div>
</div>

<script src="https://cdn.jsdelivr.net/npm/bootstrap@5.3.3/dist/js/bootstrap.bundle.min.js"></script>
<script src="https://cdnjs.cloudflare.com/ajax/libs/html2canvas/1.4.1/html2canvas.min.js"></script>
<script>
function reloadPage() {
    document.getElementById("dateForm").submit();
}
function exportSelectedSections() {
    const selectedValues = Array.from(document.querySelectorAll('.export-checkbox:checked')).map(c => c.value);
    if (!selectedValues.length) {
        alert('請至少選擇一個要匯出的表格！');
        return;
    }
    const exportContainer = document.createElement('div');
    exportContainer.style.background = '#fff';
    exportContainer.style.padding = '20px';
    exportContainer.style.position = 'absolute';
    exportContainer.style.left = '-9999px';
    document.body.appendChild(exportContainer);
    selectedValues.forEach(id => {
        const section = document.getElementById(id);
        if (section) {
            const clone = section.cloneNode(true);
            clone.style.marginBottom = '30px';
            exportContainer.appendChild(clone);
        }
    });
    html2canvas(exportContainer, { scale: 2, useCORS: true, scrollY: 0 }).then(canvas => {
        const link = document.createElement("a");
        const now = new Date();
        link.download = `特休報表_${now.getFullYear()}-${now.getMonth()+1}_${Date.now()}.png`;
        link.href = canvas.toDataURL("image/png");
        link.click();
        document.body.removeChild(exportContainer);
    }).catch(error => {
        console.error('匯出錯誤：', error);
        alert('匯出圖片失敗，請稍後再試。');
    });
}
</script>
</body>
</html>

<?php
$conn->close();
?>
<script src="https://cdnjs.cloudflare.com/ajax/libs/html2canvas/1.4.1/html2canvas.min.js"></script>
<script>
function exportSelectedSections() {
    const selectedValues = Array.from(document.querySelectorAll('.export-checkbox:checked')).map(c => c.value);

    if (selectedValues.length === 0) {
        alert('請至少選擇一個要匯出的表格！');
        return;
    }

    // 建立一個容器用來包裝要匯出的內容
    const exportContainer = document.createElement('div');
    exportContainer.style.background = '#fff';
    exportContainer.style.padding = '20px';
    exportContainer.style.position = 'absolute';
    exportContainer.style.left = '-9999px';
    document.body.appendChild(exportContainer);

    selectedValues.forEach(id => {
        const section = document.getElementById(id);
        if (section) {
            const clone = section.cloneNode(true);
            clone.style.marginBottom = '30px';
            exportContainer.appendChild(clone);
        }
    });

    html2canvas(exportContainer, {
        scale: 2,
        useCORS: true,
        scrollY: 0
    }).then(canvas => {
        const link = document.createElement("a");
        const now = new Date();
        const filename = `特休報表_${now.getFullYear()}-${now.getMonth() + 1}_${Date.now()}.png`;
        link.download = filename;
        link.href = canvas.toDataURL("image/png");
        link.click();
        document.body.removeChild(exportContainer);
    }).catch(error => {
        console.error('匯出錯誤：', error);
        alert('匯出圖片失敗，請稍後再試。');
    });
}
</script>


