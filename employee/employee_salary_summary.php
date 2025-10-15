<?php
session_start();

// 只要登入即可（不限制 admin）
if (!isset($_SESSION['user'])) {
    header('Location: ../login.php');
    exit;
}

$conn = new mysqli('localhost', 'root', '', 'mymakeart');
if ($conn->connect_error) {
    die("資料庫連接失敗：" . $conn->connect_error);
}

// 目前登入員工 ID
$login_employee_id = (int)$_SESSION['user']['id'];

// 篩選參數（可空）
$year  = isset($_GET['year'])  ? trim($_GET['year'])  : '';
$month = isset($_GET['month']) ? trim($_GET['month']) : '';

// 查詢登入員工 + 篩選條件
$where = ["s.employee_id = ?"];
$params = [$login_employee_id];
$types  = "i";

if ($year !== '') {
    $where[] = "s.year = ?";
    $params[] = $year;
    $types   .= "i";
}
if ($month !== '') {
    $where[] = "s.month = ?";
    $params[] = $month;
    $types   .= "i";
}
$where_sql = 'WHERE ' . implode(' AND ', $where);

// 查詢薪資資料（只此員工）
$sql = "
SELECT s.*, e.name
FROM employee_monthly_salary s
JOIN employees e ON s.employee_id = e.id
$where_sql
ORDER BY s.year DESC, s.month DESC
";

// 用 prepared statement 以防注入
$stmt = $conn->prepare($sql);
$stmt->bind_param($types, ...$params);
$stmt->execute();
$res  = $stmt->get_result();
$data = $res->fetch_all(MYSQLI_ASSOC);

// 中文欄位對應
$columns = [
    'base_salary'       => '底薪',
    'allowance'         => '津貼',
    'meal_allowance'    => '伙食津貼',
    'attendance_bonus'  => '全勤獎金',
    'position_bonus'    => '職務加給',
    'skill_bonus'       => '技能津貼',
    'vacation_cash'     => '特休轉現金',
    'overtime_pay'      => '加班費',
    'labor_insurance'   => '勞保費',
    'health_insurance'  => '健保費',
    'leave_deduction'   => '請假扣薪',
    'absent_deduction'  => '曠職扣薪',
    'deductions'        => '其他扣款'
];

// 只顯示有值的欄位
$display_columns = [];
foreach ($columns as $key => $label) {
    foreach ($data as $row) {
        if (isset($row[$key]) && (float)$row[$key] != 0) {
            $display_columns[$key] = $label;
            break;
        }
    }
}

function format_status($status) {
    if ($status === 'Approved') return '已核准';
    if ($status === 'Pending')  return '待審核';
    if ($status === 'Rejected') return '已退回';
    return $status ?? '-';
}
?>
<!DOCTYPE html>
<html lang="zh-Hant">
<head>
    <meta charset="UTF-8">
    <title>我的薪資總表</title>
    <link href="https://cdn.jsdelivr.net/npm/bootstrap@5.3.3/dist/css/bootstrap.min.css" rel="stylesheet">
    <script src="https://cdn.jsdelivr.net/npm/bootstrap@5.3.3/dist/js/bootstrap.bundle.min.js"></script>
    <link rel="stylesheet" href="employee_navbar.css">
</head>
<body>
<?php include 'employee_navbar.php'; ?>
<div class="container mt-4">
    <h1 class="mb-3">我的薪資總表</h1>

    <form class="row g-3 mb-4">
        <div class="col-md-4">
            <label class="form-label">年份</label>
            <input type="number" class="form-control" name="year" value="<?= htmlspecialchars($year) ?>">
        </div>
        <div class="col-md-4">
            <label class="form-label">月份</label>
            <select class="form-select" name="month">
                <option value="">全部</option>
                <?php for ($m = 1; $m <= 12; $m++): ?>
                    <option value="<?= $m ?>" <?= ($month !== '' && (int)$month === $m) ? 'selected' : '' ?>><?= $m ?></option>
                <?php endfor; ?>
            </select>
        </div>
        <div class="col-md-4 d-flex align-items-end">
            <button type="submit" class="btn btn-primary w-100">搜尋</button>
        </div>
    </form>

    <div class="table-responsive">
        <table class="table table-bordered align-middle text-center" id="salaryTable">
            <thead class="table-primary">
                <tr>
                    <th>姓名</th>
                    <th>年份</th>
                    <th>月份</th>
                    <?php foreach ($display_columns as $label): ?>
                        <th><?= $label ?></th>
                    <?php endforeach; ?>
                    <th>總工資</th>
                    <th>總扣除</th>
                    <th>實領薪資</th>
                    <th>狀態</th>
                </tr>
            </thead>
            <tbody>
            <?php if (empty($data)): ?>
                <tr><td colspan="<?= 3 + count($display_columns) + 4; ?>">查無資料</td></tr>
            <?php else: ?>
                <?php foreach ($data as $row):
                    $add = 0; $sub = 0;
                    foreach ($display_columns as $key => $label) {
                        $val = (float)($row[$key] ?? 0);
                        if (in_array($key, ['labor_insurance','health_insurance','leave_deduction','absent_deduction','deductions'])) {
                            $sub += $val;
                        } else {
                            $add += $val;
                        }
                    }
                    $net  = $add - $sub;
                    // 同員工自己的詳情頁
                    $link = "employee_salary_detail.php?year={$row['year']}&month={$row['month']}&employee_id={$row['employee_id']}";
                ?>
                <tr onclick="window.location='<?= $link ?>'" style="cursor:pointer;">
                    <td><?= htmlspecialchars($row['name']) ?></td>
                    <td><?= (int)$row['year'] ?></td>
                    <td><?= (int)$row['month'] ?></td>
                    <?php foreach ($display_columns as $key => $label): ?>
                        <td><?= ($row[$key] !== null && $row[$key] !== '') ? number_format((float)$row[$key], 0) : '' ?></td>
                    <?php endforeach; ?>
                    <td class="text-success fw-bold"><?= number_format($add, 0) ?></td>
                    <td class="text-danger fw-bold"><?= number_format($sub, 0) ?></td>
                    <td class="text-primary fw-bold"><?= number_format($net, 0) ?></td>
                    <td><?= format_status($row['status'] ?? null) ?></td>
                </tr>
                <?php endforeach; ?>
            <?php endif; ?>
            </tbody>
        </table>
    </div>

    <div class="mt-3 d-flex gap-2">
        <button class="btn btn-outline-secondary" onclick="exportImage()">匯出圖片</button>
        <button class="btn btn-outline-secondary" onclick="exportPDF()">匯出 PDF</button>
    </div>
</div>

<script src="https://cdn.jsdelivr.net/npm/html2canvas@1.4.1/dist/html2canvas.min.js"></script>
<script src="https://cdnjs.cloudflare.com/ajax/libs/jspdf/2.5.1/jspdf.umd.min.js"></script>
<script>
function exportImage() {
    html2canvas(document.querySelector('#salaryTable')).then(canvas => {
        const link = document.createElement('a');
        link.download = 'my_salary_table.png';
        link.href = canvas.toDataURL('image/png');
        link.click();
    });
}
function exportPDF() {
    html2canvas(document.querySelector('#salaryTable')).then(canvas => {
        const imgData = canvas.toDataURL('image/png');
        const pdf = new jspdf.jsPDF('p', 'mm', 'a4');
        const pdfW = pdf.internal.pageSize.getWidth();
        const pdfH = pdf.internal.pageSize.getHeight();
        const imgW = pdfW;
        const imgH = canvas.height * imgW / canvas.width;
        let y = 0;
        // 若表格超過一頁，自動分頁
        if (imgH <= pdfH) {
            pdf.addImage(imgData, 'PNG', 0, 0, imgW, imgH);
        } else {
            const pageHeight = pdfH;
            let remaining = imgH;
            const canvasPage = document.createElement('canvas');
            const ctx = canvasPage.getContext('2d');
            let sX = 0, sY = 0, sW = canvas.width, sH = Math.floor(canvas.width * (pageHeight / imgW));
            while (remaining > 0) {
                canvasPage.width = sW;
                canvasPage.height = sH;
                ctx.drawImage(canvas, sX, sY, sW, sH, 0, 0, sW, sH);
                const imgPart = canvasPage.toDataURL('image/png');
                if (y > 0) pdf.addPage();
                pdf.addImage(imgPart, 'PNG', 0, 0, imgW, pageHeight);
                sY += sH;
                remaining -= pageHeight;
                y += pageHeight;
            }
        }
        pdf.save('my_salary_table.pdf');
    });

}
</script>
</body>
</html>
