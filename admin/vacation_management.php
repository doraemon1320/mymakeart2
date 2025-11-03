<?php
// 【PHP-1】載入資料庫連線與初始化查詢年月
include 'db_connect.php';

$currentYear = (int) date('Y');
$currentMonth = (int) date('m') - 1;
if ($currentMonth === 0) {
    $currentMonth = 12;
    $currentYear -= 1;
}

$selectedYear = isset($_POST['year']) ? max(2000, min(2100, (int) $_POST['year'])) : $currentYear;
$selectedMonth = isset($_POST['month']) ? max(1, min(12, (int) $_POST['month'])) : $currentMonth;

$employees = [];
$message = '';

// 【PHP-2】取得符合年資政策的員工清單
$policySql = "SELECT id, name, hire_date, TIMESTAMPDIFF(MONTH, hire_date, '$selectedYear-$selectedMonth-01') AS months_of_service FROM employees";
if ($result = $conn->query($policySql)) {
    while ($row = $result->fetch_assoc()) {
        $monthsOfService = (int) $row['months_of_service'];
        if ($monthsOfService > 0) {
            $sqlPolicy = "SELECT days FROM annual_leave_policy WHERE FLOOR(years_of_service * 12) = $monthsOfService LIMIT 1";
            $resultPolicy = $conn->query($sqlPolicy);
            if ($resultPolicy && $resultPolicy->num_rows > 0) {
                $policy = $resultPolicy->fetch_assoc();
                $row['leave_days'] = (int) $policy['days'];
                $employees[] = $row;
            }
        }
    }
}

if (empty($employees)) {
    $message = '本月無年資政策符合員工';
}

// 【PHP-3】儲存符合條件的特休額度
if ($_SERVER['REQUEST_METHOD'] === 'POST' && isset($_POST['save']) && !empty($_POST['employees'])) {
    foreach ($_POST['employees'] as $employee) {
        $employeeId = isset($employee['id']) ? (int) $employee['id'] : 0;
        $leaveDays = isset($employee['leave_days']) ? (int) $employee['leave_days'] : 0;
        if ($employeeId <= 0 || $leaveDays <= 0) {
            continue;
        }

        $checkSql = "SELECT COUNT(*) AS total FROM annual_leave_records WHERE employee_id = $employeeId AND year = $selectedYear AND month = $selectedMonth";
        $checkResult = $conn->query($checkSql);
        $checkRow = $checkResult ? $checkResult->fetch_assoc() : ['total' => 0];

        if ((int) $checkRow['total'] === 0) {
            $insertSql = "INSERT INTO annual_leave_records (employee_id, year, month, days, status) VALUES ($employeeId, $selectedYear, $selectedMonth, $leaveDays, '取得')";
            $conn->query($insertSql);
        }
    }
    echo "<script>alert('✅ 特休已成功新增！');</script>";
}

// 【PHP-4】特休紀錄查詢與統計
$records = [];
$recordSql = "SELECT a.id, e.name, a.year, a.month, a.days, a.status, a.created_at FROM annual_leave_records a JOIN employees e ON a.employee_id = e.id ORDER BY a.created_at DESC";
if ($resultRecords = $conn->query($recordSql)) {
    while ($row = $resultRecords->fetch_assoc()) {
        $records[] = $row;
    }
}

$summaryResult = [];
$summarySql = "
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

if ($summaryQuery = $conn->query($summarySql)) {
    while ($row = $summaryQuery->fetch_assoc()) {
        $totalHours = ($row['total_acquired_days'] * 8 + $row['total_acquired_hours'])
            - ($row['total_used_days'] * 8 + $row['total_used_hours'])
            - ($row['total_cash_days'] * 8 + $row['total_cash_hours']);

        if (
            (float) $row['total_acquired_days'] === 0.0 &&
            (float) $row['total_used_days'] === 0.0 &&
            (float) $row['total_cash_days'] === 0.0 &&
            (float) $row['total_acquired_hours'] === 0.0 &&
            (float) $row['total_used_hours'] === 0.0 &&
            (float) $row['total_cash_hours'] === 0.0
        ) {
            continue;
        }

        $row['remaining_days'] = (int) floor($totalHours / 8);
        $row['remaining_hours'] = (int) fmod($totalHours, 8);
        $summaryResult[] = $row;
    }
}

$eligibleCount = count($employees);
$recordCount = count($records);
$summaryCount = count($summaryResult);
?>
<!DOCTYPE html>
<html lang="zh-TW">
<head>
    <meta charset="UTF-8">
    <meta name="viewport" content="width=device-width, initial-scale=1.0">
    <title>特休額度檢查</title>
    <link href="https://cdn.jsdelivr.net/npm/bootstrap@5.3.3/dist/css/bootstrap.min.css" rel="stylesheet">
    <link rel="stylesheet" href="admin_navbar.css">
    <style>
        :root {
            --brand-sun: #ffcd00;
            --brand-rose: #e36386;
            --brand-ocean: #345d9d;
            --neutral-bg: #f6f7fb;
        }
        body {
            background: var(--neutral-bg);
        }
        .page-hero {
            background: linear-gradient(120deg, var(--brand-sun), var(--brand-rose), var(--brand-ocean));
            border-radius: 18px;
            padding: 32px;
            color: #fff;
            box-shadow: 0 12px 24px rgba(52, 93, 157, 0.18);
        }
        .page-hero h1 {
            font-weight: 700;
        }
        .page-hero p {
            margin-bottom: 0;
            font-size: 1.05rem;
        }
        .info-card {
            border-radius: 18px;
            border: 1px solid rgba(52, 93, 157, 0.15);
            background: #fff;
            box-shadow: 0 10px 20px rgba(52, 93, 157, 0.08);
        }
        .info-card h2 {
            font-size: 1.25rem;
            color: var(--brand-ocean);
            font-weight: 700;
        }
        .table thead th {
            background: var(--brand-ocean);
            color: #fff;
            border-color: var(--brand-ocean);
        }
        .badge-soft {
            background: rgba(255, 255, 255, 0.2);
            border: 1px solid rgba(255, 255, 255, 0.4);
            border-radius: 50px;
            padding: 6px 16px;
            display: inline-block;
            font-weight: 600;
        }
        .form-section {
            background: #fff;
            border-radius: 18px;
            padding: 24px;
            box-shadow: 0 8px 18px rgba(0, 0, 0, 0.08);
        }
        .export-area {
            background: #fff;
            border-radius: 12px;
            padding: 24px;
            box-shadow: 0 6px 14px rgba(52, 93, 157, 0.12);
        }
        .export-area .btn-secondary {
            background-color: var(--brand-ocean);
            border-color: var(--brand-ocean);
        }
        .export-area .btn-secondary:hover {
            background-color: #26497c;
            border-color: #26497c;
        }
    </style>
</head>
<body>
<?php include 'admin_navbar.php'; ?>

<div class="container my-5">
    <div class="page-hero mb-4">
        <div class="row align-items-center g-3">
            <div class="col-lg-8">
                <h1 class="mb-2">特休額度檢查</h1>
                <p>依照企業年資政策快速盤點當月可獲得特休的員工，並支援匯出留存與彙總分析，協助人事單位掌握年度特休動態。</p>
            </div>
            <div class="col-lg-4 text-lg-end">
                <span class="badge-soft">查詢月份：<?= htmlspecialchars($selectedYear) ?> 年 <?= htmlspecialchars($selectedMonth) ?> 月</span>
            </div>
        </div>
    </div>

    <div class="row g-4 mb-4">
        <div class="col-md-4">
            <div class="info-card p-4 text-center">
                <h2 class="mb-2">符合政策人數</h2>
                <div class="display-6 text-warning fw-bold"><?= $eligibleCount ?></div>
                <small class="text-muted">完成年資門檻的員工</small>
            </div>
        </div>
        <div class="col-md-4">
            <div class="info-card p-4 text-center">
                <h2 class="mb-2">特休紀錄</h2>
                <div class="display-6 text-danger fw-bold"><?= $recordCount ?></div>
                <small class="text-muted">已建立之取得／使用／轉換紀錄</small>
            </div>
        </div>
        <div class="col-md-4">
            <div class="info-card p-4 text-center">
                <h2 class="mb-2">彙總筆數</h2>
                <div class="display-6 text-primary fw-bold"><?= $summaryCount ?></div>
                <small class="text-muted">具有可運算特休餘額的員工</small>
            </div>
        </div>
    </div>

    <div class="form-section mb-5">
        <h2 class="mb-3">查詢條件</h2>
        <p class="text-muted mb-4">請選擇要計算的年份與月份，系統會自動比對年資政策並列出可新增特休額度的員工名單。</p>
        <form method="post" id="dateForm" class="row g-3">
            <div class="col-md-3">
                <label for="year" class="form-label">選擇年份</label>
                <input type="number" id="year" name="year" value="<?= htmlspecialchars($selectedYear) ?>" required class="form-control" min="2000" max="2100">
            </div>
            <div class="col-md-3">
                <label for="month" class="form-label">選擇月份</label>
                <select id="month" name="month" class="form-select">
                    <?php for ($m = 1; $m <= 12; $m++): ?>
                        <option value="<?= $m ?>" <?= ($m === $selectedMonth) ? 'selected' : '' ?>><?= $m ?> 月</option>
                    <?php endfor; ?>
                </select>
            </div>
            <div class="col-md-6 d-flex align-items-end justify-content-md-end">
                <button type="submit" class="btn btn-primary px-4">重新載入資料</button>
            </div>
        </form>
    </div>

    <?php if (!empty($message)): ?>
        <div class="alert alert-warning shadow-sm"><?= htmlspecialchars($message) ?></div>
    <?php endif; ?>

    <?php if (!empty($employees)): ?>
    <div id="eligible-employees" class="mb-5">
        <h2 class="mb-3">符合年資政策的員工</h2>
        <p class="text-muted">下列表格列出符合條件的員工與對應可取得的特休天數，確認後請按「儲存年度特休」將資料寫入紀錄。</p>
        <form method="post">
            <div class="table-responsive">
                <table class="table table-bordered align-middle text-center">
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
                            <td><?= htmlspecialchars($employee['name']) ?></td>
                            <td><?= (int) $employee['months_of_service'] ?> 個月（約 <?= round($employee['months_of_service'] / 12, 2) ?> 年）</td>
                            <td class="fw-bold text-primary"><?= (int) $employee['leave_days'] ?> 天</td>
                        </tr>
                        <input type="hidden" name="employees[<?= (int) $employee['id'] ?>][id]" value="<?= (int) $employee['id'] ?>">
                        <input type="hidden" name="employees[<?= (int) $employee['id'] ?>][leave_days]" value="<?= (int) $employee['leave_days'] ?>">
                        <?php endforeach; ?>
                    </tbody>
                </table>
            </div>
            <input type="hidden" name="year" value="<?= htmlspecialchars($selectedYear) ?>">
            <input type="hidden" name="month" value="<?= htmlspecialchars($selectedMonth) ?>">
            <button type="submit" name="save" class="btn btn-success">儲存年度特休</button>
        </form>
    </div>
    <?php endif; ?>

    <div id="vacation-records" class="mb-5">
        <h2 class="mb-3">特休紀錄</h2>
        <p class="text-muted">顯示所有員工的特休取得、使用與轉現金紀錄，可依匯出功能將畫面保存為圖檔。</p>
        <div class="table-responsive">
            <table class="table table-bordered align-middle text-center">
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
                    <?php if (!empty($records)): ?>
                        <?php foreach ($records as $row): ?>
                        <tr>
                            <td><?= htmlspecialchars($row['name']) ?></td>
                            <td><?= (int) $row['year'] ?></td>
                            <td><?= (int) $row['month'] ?></td>
                            <td><?= (float) $row['days'] ?></td>
                            <td><?= htmlspecialchars($row['status']) ?></td>
                            <td><?= htmlspecialchars($row['created_at']) ?></td>
                        </tr>
                        <?php endforeach; ?>
                    <?php else: ?>
                        <tr>
                            <td colspan="6" class="text-muted">尚無特休紀錄</td>
                        </tr>
                    <?php endif; ?>
                </tbody>
            </table>
        </div>
    </div>

    <div id="summary-records" class="mb-5">
        <h2 class="mb-3">員工特休彙總紀錄</h2>
        <p class="text-muted">彙整各員工目前累計的取得、使用、轉換與剩餘特休，便於快速掌握年度餘額。</p>
        <div class="table-responsive">
            <table class="table table-bordered align-middle text-center">
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
                    <?php if (!empty($summaryResult)): ?>
                        <?php foreach ($summaryResult as $row): ?>
                        <tr>
                            <td><?= htmlspecialchars($row['name']) ?></td>
                            <td><?= (float) $row['total_acquired_days'] ?></td>
                            <td><?= (float) $row['total_acquired_hours'] ?></td>
                            <td><?= (float) $row['total_used_days'] ?></td>
                            <td><?= (float) $row['total_used_hours'] ?></td>
                            <td><?= (float) $row['total_cash_days'] ?></td>
                            <td><?= (float) $row['total_cash_hours'] ?></td>
                            <td class="fw-bold text-success"><?= (int) $row['remaining_days'] ?></td>
                            <td class="fw-bold text-success"><?= (int) $row['remaining_hours'] ?></td>
                        </tr>
                        <?php endforeach; ?>
                    <?php else: ?>
                        <tr>
                            <td colspan="9" class="text-muted">目前尚無可彙總的特休紀錄</td>
                        </tr>
                    <?php endif; ?>
                </tbody>
            </table>
        </div>
    </div>

    <div class="export-area">
        <h2 class="mb-3">匯出工具</h2>
        <p class="text-muted">可自選要匯出的區塊，系統將自動整合並輸出為高解析度圖檔，方便備份或提供主管簽核。</p>
        <div class="form-check mb-2">
            <input type="checkbox" class="form-check-input export-checkbox" value="eligible-employees" id="chk1">
            <label class="form-check-label" for="chk1">符合年資政策的員工</label>
        </div>
        <div class="form-check mb-2">
            <input type="checkbox" class="form-check-input export-checkbox" value="vacation-records" id="chk2">
            <label class="form-check-label" for="chk2">特休紀錄</label>
        </div>
        <div class="form-check mb-3">
            <input type="checkbox" class="form-check-input export-checkbox" value="summary-records" id="chk3">
            <label class="form-check-label" for="chk3">特休彙總紀錄</label>
        </div>
        <button type="button" class="btn btn-secondary" onclick="exportSelectedSections()">匯出圖片</button>
    </div>
</div>

<script src="https://code.jquery.com/jquery-3.7.1.min.js"></script>
<script src="https://cdn.jsdelivr.net/npm/bootstrap@5.3.3/dist/js/bootstrap.bundle.min.js"></script>
<script src="https://cdnjs.cloudflare.com/ajax/libs/html2canvas/1.4.1/html2canvas.min.js"></script>
<script>
// 【JS-1】重新載入查詢年月
$(document).ready(function () {
    $('#year, #month').on('change', function () {
        $('#dateForm').trigger('submit');
    });
});

// 【JS-2】匯出勾選區塊
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
        const link = document.createElement('a');
        const now = new Date();
        link.download = `特休報表_${now.getFullYear()}-${now.getMonth() + 1}_${Date.now()}.png`;
        link.href = canvas.toDataURL('image/png');
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
