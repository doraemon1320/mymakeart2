<?php
session_start();

if (!isset($_SESSION['user']) || $_SESSION['user']['role'] !== 'admin') {
    header('Location: ../login.php');
    exit;
}

$conn = new mysqli('localhost', 'root', '', 'mymakeart');
if ($conn->connect_error) {
    die("資料庫連接失敗：" . $conn->connect_error);
}

$year = $_GET['year'] ?? '';
$month = $_GET['month'] ?? '';
$employee_id = $_GET['employee_id'] ?? '';

// 取得員工清單
$employees = [];
$result = $conn->query("SELECT id, name FROM employees ORDER BY name ASC");
while ($row = $result->fetch_assoc()) {
    $employees[] = $row;
}

// 撈出所有符合條件的薪資資料
$where = [];
if ($year !== '') $where[] = "year = '" . $conn->real_escape_string($year) . "'";
if ($month !== '') $where[] = "month = '" . $conn->real_escape_string($month) . "'";
if ($employee_id !== '') $where[] = "employee_id = '" . (int)$employee_id . "'";
$where_sql = $where ? 'WHERE ' . implode(' AND ', $where) : '';

$sql = "
SELECT s.*, e.name 
FROM employee_monthly_salary s
JOIN employees e ON s.employee_id = e.id
$where_sql
ORDER BY s.year DESC, s.month DESC, e.name ASC
";

$data = $conn->query($sql)->fetch_all(MYSQLI_ASSOC);

// 中文欄位對應
$columns = [
    'base_salary' => '底薪',
    'allowance' => '津貼',
    'meal_allowance' => '伙食津貼',
    'attendance_bonus' => '全勤獎金',
    'position_bonus' => '職務加給',
    'skill_bonus' => '技能津貼',
    'vacation_cash' => '特休轉現金',
    'overtime_pay' => '加班費',
    'labor_insurance' => '勞保費',
    'health_insurance' => '健保費',
    'leave_deduction' => '請假扣薪',
    'absent_deduction' => '曠職扣薪',
    'deductions' => '其他扣款'
];

$display_columns = [];
foreach ($columns as $key => $label) {
    foreach ($data as $row) {
        if (!empty($row[$key]) && $row[$key] != 0) {
            $display_columns[$key] = $label;
            break;
        }
    }
}

function format_status($status) {
    return $status === 'Approved' ? '已核准' : '待審核';
}
?>
<!DOCTYPE html>
<html lang="zh-Hant">
<head>
    <meta charset="UTF-8">
    <title>員工薪資總攬</title>
    <link href="https://cdn.jsdelivr.net/npm/bootstrap@5.3.3/dist/css/bootstrap.min.css" rel="stylesheet">
    <script src="https://cdn.jsdelivr.net/npm/bootstrap@5.3.3/dist/js/bootstrap.bundle.min.js"></script>
	<link rel="stylesheet" href="styles.css">
    <link rel="stylesheet" href="admin_navbar.css">
</head>
<?php include 'admin_navbar.php'; ?>
<body>
<div class="container mt-4">
    <h1>員工薪資總表</h2>
    <form class="row g-3 mb-4">
        <div class="col-md-3">
            <label class="form-label">年份</label>
            <input type="number" class="form-control" name="year" value="<?= htmlspecialchars($year) ?>">
        </div>
		<div class="col-md-3">
            <label class="form-label">月份</label>
            <select class="form-select" name="month">
				<option value="">請選擇</option>
                <?php for ($m = 1; $m <= 12; $m++): 
                   
                    ?>
                    <option value="<?= $m ?>" <?= $month == $m ? 'selected' : '' ?>><?= $m ?></option>
                <?php endfor; ?>
            </select>
        </div>
        <div class="col-md-4">
            <label class="form-label">員工</label>
            <select class="form-select" name="employee_id">
                <option value="">全部</option>
                <?php foreach ($employees as $emp): ?>
                    <option value="<?= $emp['id'] ?>" <?= $employee_id == $emp['id'] ? 'selected' : '' ?>><?= $emp['name'] ?></option>
                <?php endforeach; ?>
            </select>
        </div>
        <div class="col-md-2 d-flex align-items-end">
            <button type="submit" class="btn btn-primary w-100">搜尋</button>
        </div>
    </form>

    <div class="table-responsive">
        <table class="table table-bordered align-middle text-center " id="salaryTable">
            <thead class=" table-primary">
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
            <?php foreach ($data as $row):
                $add = 0;
                $sub = 0;
                foreach ($display_columns as $key => $label) {
                    $val = (float)($row[$key] ?? 0);
                    if (in_array($key, ['labor_insurance','health_insurance','leave_deduction','absent_deduction','deductions'])) $sub += $val;
                    else $add += $val;
                }
                $net = $add - $sub;
                $link = "employee_salary_report.php?year={$row['year']}&month={$row['month']}&employee_id={$row['employee_id']}";
                ?>
                <tr onclick="window.location='<?= $link ?>'" style="cursor:pointer;">
                    <td><?= htmlspecialchars($row['name']) ?></td>
                    <td><?= htmlspecialchars($row['year']) ?></td>
                    <td><?= htmlspecialchars($row['month']) ?></td>
                    <?php foreach ($display_columns as $key => $label): ?>
                        <td><?= $row[$key] !== null ? number_format($row[$key], 0) : '' ?></td>
                    <?php endforeach; ?>
                    <td class="text-success fw-bold"><?= number_format($add, 0) ?></td>
                    <td class="text-danger fw-bold"><?= number_format($sub, 0) ?></td>
                    <td class="text-primary fw-bold"><?= number_format($net, 0) ?></td>
                    <td><?= format_status($row['status']) ?></td>
                </tr>
            <?php endforeach; ?>
            </tbody>
        </table>
    </div>
    <div class="mt-3">
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
        link.download = 'salary_table.png';
        link.href = canvas.toDataURL();
        link.click();
    });
}
function exportPDF() {
    html2canvas(document.querySelector('#salaryTable')).then(canvas => {
        const imgData = canvas.toDataURL('image/png');
        const pdf = new jspdf.jsPDF();
        const imgProps = pdf.getImageProperties(imgData);
        const pdfWidth = pdf.internal.pageSize.getWidth();
        const pdfHeight = (imgProps.height * pdfWidth) / imgProps.width;
        pdf.addImage(imgData, 'PNG', 0, 0, pdfWidth, pdfHeight);
        pdf.save('salary_table.pdf');
    });
}
</script>
</body>
</html>