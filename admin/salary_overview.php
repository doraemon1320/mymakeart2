<?php
// ==============================【PHP-1】權限檢查與連線設定==============================
session_start();
if (!isset($_SESSION['user']) || $_SESSION['user']['role'] !== 'admin') {
    header('Location: ../login.php');
    exit;
}

$conn = new mysqli('localhost', 'root', '', 'mymakeart');
if ($conn->connect_error) {
    die('資料庫連線失敗：' . $conn->connect_error);
}

// ==============================【PHP-2】過濾表單條件==============================
$year = isset($_GET['year']) && ctype_digit($_GET['year']) ? $_GET['year'] : '';
$month = isset($_GET['month']) && ctype_digit($_GET['month']) ? $_GET['month'] : '';
$employee_id = isset($_GET['employee_id']) && ctype_digit($_GET['employee_id']) ? $_GET['employee_id'] : '';

// ==============================【PHP-3】取得員工選單資料==============================
$employees = [];
$employeeNameMap = [];
$employee_sql = 'SELECT id, name FROM employees ORDER BY name ASC';
$employee_result = $conn->query($employee_sql);
while ($row = $employee_result->fetch_assoc()) {
    $employees[] = $row;
    $employeeNameMap[(string)$row['id']] = $row['name'];
}

// ==============================【PHP-4】組裝薪資查詢==============================
$conditions = [];
$types = '';
$params = [];

if ($year !== '') {
    $conditions[] = 's.year = ?';
    $types .= 'i';
    $params[] = (int)$year;
}
if ($month !== '') {
    $conditions[] = 's.month = ?';
    $types .= 'i';
    $params[] = (int)$month;
}
if ($employee_id !== '') {
    $conditions[] = 's.employee_id = ?';
    $types .= 'i';
    $params[] = (int)$employee_id;
}

$sql = 'SELECT s.*, e.name FROM employee_monthly_salary s JOIN employees e ON s.employee_id = e.id';
if ($conditions) {
    $sql .= ' WHERE ' . implode(' AND ', $conditions);
}
$sql .= ' ORDER BY s.year DESC, s.month DESC, e.name ASC';

$stmt = $conn->prepare($sql);
if ($stmt === false) {
    die('查詢失敗：' . $conn->error);
}
if ($params) {
    $stmt->bind_param($types, ...$params);
}
$stmt->execute();
$result = $stmt->get_result();
$data = $result->fetch_all(MYSQLI_ASSOC);

// ==============================【PHP-5】欄位對應與資料整理==============================
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
        if (isset($row[$key]) && (float)$row[$key] !== 0.0) {
            $display_columns[$key] = $label;
            break;
        }
    }
}

$processedData = [];
$summary = [
    'record_count' => count($data),
    'employee_count' => count(array_unique(array_column($data, 'employee_id'))),
    'total_add' => 0,
    'total_sub' => 0,
    'total_net' => 0
];

foreach ($data as $row) {
    $total_add = 0;
    $total_sub = 0;
    foreach ($display_columns as $key => $label) {
        $value = (float)($row[$key] ?? 0);
        if (in_array($key, ['labor_insurance', 'health_insurance', 'leave_deduction', 'absent_deduction', 'deductions'], true)) {
            $total_sub += $value;
        } else {
            $total_add += $value;
        }
    }
    $net = $total_add - $total_sub;
    $row['total_add'] = $total_add;
    $row['total_sub'] = $total_sub;
    $row['total_net'] = $net;
    $processedData[] = $row;

    $summary['total_add'] += $total_add;
    $summary['total_sub'] += $total_sub;
    $summary['total_net'] += $net;
}

// ==============================【PHP-6】依月份整理手風琴資料==============================
$groupedByPeriod = [];
$defaultOpenKey = '';
foreach ($processedData as $row) {
    $periodKey = sprintf('%04d-%02d', $row['year'], $row['month']);
    if (!isset($groupedByPeriod[$periodKey])) {
        $groupedByPeriod[$periodKey] = [
            'label' => sprintf('%d年%02d月', $row['year'], $row['month']),
            'rows' => [],
            'summary' => [
                'total_add' => 0,
                'total_sub' => 0,
                'total_net' => 0,
                'record_count' => 0,
                'employee_ids' => []
            ]
        ];
        if ($defaultOpenKey === '') {
            $defaultOpenKey = $periodKey;
        }
    }

    $groupedByPeriod[$periodKey]['rows'][] = $row;
    $groupedByPeriod[$periodKey]['summary']['total_add'] += $row['total_add'];
    $groupedByPeriod[$periodKey]['summary']['total_sub'] += $row['total_sub'];
    $groupedByPeriod[$periodKey]['summary']['total_net'] += $row['total_net'];
    $groupedByPeriod[$periodKey]['summary']['record_count']++;
    $groupedByPeriod[$periodKey]['summary']['employee_ids'][$row['employee_id']] = true;
}
foreach ($groupedByPeriod as &$periodInfo) {
    $periodInfo['summary']['employee_count'] = count($periodInfo['summary']['employee_ids']);
    unset($periodInfo['summary']['employee_ids']);
}
unset($periodInfo);

$exportPeriodLabel = '';
if ($year !== '' && $month !== '') {
    $exportPeriodLabel = sprintf('%d年%02d月', $year, $month);
} elseif ($defaultOpenKey !== '' && isset($groupedByPeriod[$defaultOpenKey])) {
    $exportPeriodLabel = $groupedByPeriod[$defaultOpenKey]['label'];
}

// ==============================【PHP-6-1】匯出檔案命名組合==============================
$selectedEmployeeName = ($employee_id !== '' && isset($employeeNameMap[$employee_id])) ? $employeeNameMap[$employee_id] : '';
$exportNameParts = [];

if ($year !== '') {
    if ($month === '') {
        $exportNameParts[] = $year . '年度';
    } else {
        $exportNameParts[] = sprintf('%d年%02d月', $year, $month);
    }
} elseif ($month !== '') {
    $exportNameParts[] = sprintf('%02d月', $month);
}

if ($selectedEmployeeName !== '') {
    $exportNameParts[] = $selectedEmployeeName;
}

if ($exportNameParts) {
    $exportFileLabel = implode('-', $exportNameParts) . '-員工薪資總攬';
} elseif ($exportPeriodLabel !== '') {
    $exportFileLabel = $exportPeriodLabel . '-員工薪資總攬';
} else {
    $exportFileLabel = '員工薪資總攬';
}

// ==============================【PHP-7】工具函式==============================
function format_status($status)
{
    if ($status === 'Approved') {
        return '已核准';
    }
    if ($status === 'Rejected') {
        return '已退回';
    }
    return '待審核';
}

function format_currency($value)
{
    return $value === null || $value === '' ? '' : number_format((float)$value, 0);
}

$month_options = range(1, 12);
?>
<!DOCTYPE html>
<html lang="zh-Hant">
<head>
    <meta charset="UTF-8">
    <title>員工薪資總攬</title>
    <link href="https://cdn.jsdelivr.net/npm/bootstrap@5.3.3/dist/css/bootstrap.min.css" rel="stylesheet">
    <link href="https://cdn.jsdelivr.net/npm/bootstrap-icons@1.11.3/font/bootstrap-icons.css" rel="stylesheet">
    <script src="https://code.jquery.com/jquery-3.7.1.min.js"></script>
    <script src="https://cdn.jsdelivr.net/npm/bootstrap@5.3.3/dist/js/bootstrap.bundle.min.js"></script>
    <link rel="stylesheet" href="styles.css">
    <link rel="stylesheet" href="admin_navbar.css">
    <style>
        body {
            background: linear-gradient(135deg, rgba(255, 205, 0, 0.08), rgba(52, 93, 157, 0.08));
        }
        .page-title {
            font-weight: 700;
            color: #345d9d;
            letter-spacing: 1px;
        }
        .guide-alert {
            border-left: 6px solid #ffcd00;
            background: rgba(255, 255, 255, 0.95);
            box-shadow: 0 6px 16px rgba(52, 93, 157, 0.15);
        }
        .filter-card {
            border: none;
            border-radius: 16px;
            box-shadow: 0 10px 24px rgba(0, 0, 0, 0.08);
        }
        .filter-card .card-header {
            background: linear-gradient(90deg, #ffcd00, #e36386);
            color: #212529;
            font-weight: 700;
            letter-spacing: 1px;
        }
        .filter-card .card-body {
            background: #ffffff;
        }
        .filter-card label {
            font-weight: 600;
            color: #345d9d;
        }
        .filter-card .form-control,
        .filter-card .form-select {
            min-height: 48px;
            border-radius: 10px;
        }
        .filter-card .btn-apply {
            background-color: #345d9d;
            border-color: #345d9d;
            font-weight: 700;
            min-height: 48px;
            border-radius: 30px;
        }
        .summary-card {
            border: 0;
            border-radius: 16px;
            box-shadow: 0 10px 24px rgba(52, 93, 157, 0.18);
            background-color: #ffffff;
        }
        .summary-card .card-body {
            padding: 20px;
        }
        .summary-label {
            color: #6c757d;
            font-weight: 600;
            letter-spacing: 1px;
        }
        .summary-value {
            font-size: 26px;
            font-weight: 700;
        }
        .accordion-item {
            border: none;
            border-radius: 16px !important;
            overflow: hidden;
            box-shadow: 0 12px 28px rgba(52, 93, 157, 0.15);
            margin-bottom: 18px;
        }
        .accordion-button {
            background: linear-gradient(90deg, rgba(255, 205, 0, 0.18), rgba(52, 93, 157, 0.18));
            font-weight: 700;
            color: #345d9d;
        }
        .accordion-button:not(.collapsed) {
            color: #ffffff;
            background: linear-gradient(90deg, #345d9d, #e36386);
            box-shadow: inset 0 -4px 12px rgba(0, 0, 0, 0.08);
        }
        .accordion-button:focus {
            box-shadow: none;
            border-color: transparent;
        }
        .accordion-body {
            background-color: #ffffff;
        }
        .mini-summary {
            background: rgba(255, 205, 0, 0.12);
            border-radius: 12px;
            padding: 16px;
        }
        .mini-summary .item-label {
            font-size: 14px;
            color: #6c757d;
        }
        .mini-summary .item-value {
            font-size: 20px;
            font-weight: 700;
        }
        .table thead {
            background-color: #ffcd00;
            color: #212529;
        }
        .table tbody tr:hover {
            background-color: rgba(52, 93, 157, 0.08);
        }
        .status-badge {
            border-radius: 30px;
            padding: 6px 14px;
            font-weight: 600;
        }
        .status-approved {
            background-color: rgba(52, 157, 118, 0.15);
            color: #217346;
        }
        .status-pending {
            background-color: rgba(255, 205, 0, 0.2);
            color: #8a6d00;
        }
        .status-reject {
            background-color: rgba(227, 99, 134, 0.2);
            color: #b02a37;
        }
        .table tfoot {
            background-color: rgba(227, 99, 134, 0.12);
            font-weight: 700;
        }
        .export-area .btn {
            border-radius: 30px;
            font-weight: 600;
            min-width: 160px;
        }
    </style>
</head>
<body>
<?php include 'admin_navbar.php'; ?>
<div class="container mt-4" id="exportArea" data-export-period="<?= htmlspecialchars($exportPeriodLabel) ?>" data-export-label="<?= htmlspecialchars($exportFileLabel) ?>" data-default-key="<?= htmlspecialchars($defaultOpenKey) ?>">
    <h1 class="mb-3 page-title">員工薪資總攬</h1>
    <div class="alert alert-info guide-alert mb-4">
        請先設定查詢條件，可快速檢視各月份薪資發放狀況；如需下載報表，系統會自動整理並開啟所有月份手風琴內容。
    </div>

    <div class="card filter-card mb-4">
        <div class="card-header">查詢條件</div>
        <div class="card-body">
            <form class="row g-3 align-items-end">
                <div class="col-12 col-md-4 col-lg-3">
                    <label class="form-label">年份</label>
                    <input type="number" class="form-control" name="year" value="<?= htmlspecialchars($year) ?>" placeholder="例如：2024">
                </div>
                <div class="col-12 col-md-4 col-lg-3">
                    <label class="form-label">月份</label>
                    <select class="form-select" name="month">
                        <option value="">全部</option>
                        <?php foreach ($month_options as $m): ?>
                            <option value="<?= $m ?>" <?= $month === (string)$m ? 'selected' : '' ?>><?= $m ?></option>
                        <?php endforeach; ?>
                    </select>
                </div>
                <div class="col-12 col-md-4 col-lg-4">
                    <label class="form-label">員工</label>
                    <select class="form-select" name="employee_id">
                        <option value="">全部人員</option>
                        <?php foreach ($employees as $emp): ?>
                            <option value="<?= $emp['id'] ?>" <?= $employee_id === (string)$emp['id'] ? 'selected' : '' ?>><?= htmlspecialchars($emp['name']) ?></option>
                        <?php endforeach; ?>
                    </select>
                </div>
                <div class="col-12 col-lg-2 d-grid">
                    <button type="submit" class="btn btn-primary btn-apply">套用條件</button>
                </div>
            </form>
        </div>
    </div>

    <div class="row g-4 mb-4">
        <div class="col-md-4">
            <div class="card summary-card">
                <div class="card-body">
                    <div class="d-flex justify-content-between align-items-center">
                        <div>
                            <div class="summary-label">總加項</div>
                            <div class="summary-value text-success"><?= number_format($summary['total_add'], 0) ?></div>
                        </div>
                        <i class="bi bi-cash-stack" style="font-size: 36px; color: #2f9d62;"></i>
                    </div>
                    <div class="mt-3 text-muted small">包含底薪、各項津貼與獎金，協助掌握整體加項趨勢。</div>
                </div>
            </div>
        </div>
        <div class="col-md-4">
            <div class="card summary-card">
                <div class="card-body">
                    <div class="d-flex justify-content-between align-items-center">
                        <div>
                            <div class="summary-label">總扣項</div>
                            <div class="summary-value text-danger"><?= number_format($summary['total_sub'], 0) ?></div>
                        </div>
                        <i class="bi bi-receipt" style="font-size: 36px; color: #e36386;"></i>
                    </div>
                    <div class="mt-3 text-muted small">含勞健保、請假與其他扣款，協助檢視扣項比例。</div>
                </div>
            </div>
        </div>
        <div class="col-md-4">
            <div class="card summary-card">
                <div class="card-body">
                    <div class="d-flex justify-content-between align-items-center">
                        <div>
                            <div class="summary-label">實發薪資總計</div>
                            <div class="summary-value" style="color: #ff8a65;"><?= number_format($summary['total_net'], 0) ?></div>
                        </div>
                        <i class="bi bi-graph-up" style="font-size: 36px; color: #ff8a65;"></i>
                    </div>
                    <div class="mt-3 text-muted small">綜合加扣項後的發放金額，用於追蹤各月實際支出。</div>
                </div>
            </div>
        </div>
    </div>

    <div id="accordionWrapper">
        <?php if (empty($groupedByPeriod)): ?>
            <div class="alert alert-warning shadow-sm">目前沒有符合條件的薪資資料，請調整查詢條件。</div>
        <?php else: ?>
            <div class="accordion" id="salaryAccordion">
                <?php foreach ($groupedByPeriod as $periodKey => $periodInfo): ?>
                    <?php
                    $collapseId = 'collapse_' . str_replace('-', '_', $periodKey);
                    $isOpen = ($periodKey === $defaultOpenKey);
                    $periodSummary = $periodInfo['summary'];
                    ?>
                    <div class="accordion-item" data-period-key="<?= htmlspecialchars($periodKey) ?>">
                        <h2 class="accordion-header" id="heading_<?= htmlspecialchars($collapseId) ?>">
                            <button class="accordion-button <?= $isOpen ? '' : 'collapsed' ?>" type="button" data-bs-toggle="collapse" data-bs-target="#<?= htmlspecialchars($collapseId) ?>" aria-expanded="<?= $isOpen ? 'true' : 'false' ?>" aria-controls="<?= htmlspecialchars($collapseId) ?>">
                                <div class="w-100 d-flex flex-wrap justify-content-between align-items-center">
                                    <span><?= htmlspecialchars($periodInfo['label']) ?>員工薪資明細</span>
                                    <div class="d-flex flex-wrap gap-3 small">
                                        <span><i class="bi bi-people-fill me-1"></i>員工數：<?= number_format($periodSummary['employee_count']) ?></span>
                                        <span><i class="bi bi-journal-text me-1"></i>筆數：<?= number_format($periodSummary['record_count']) ?></span>
                                        <span><i class="bi bi-cash-coin me-1 text-success"></i>總加項 <?= number_format($periodSummary['total_add'], 0) ?></span>
                                        <span><i class="bi bi-receipt-cutoff me-1 text-danger"></i>總扣項 <?= number_format($periodSummary['total_sub'], 0) ?></span>
                                        <span><i class="bi bi-wallet2 me-1 text-primary"></i>實發 <?= number_format($periodSummary['total_net'], 0) ?></span>
                                    </div>
                                </div>
                            </button>
                        </h2>
                        <div id="<?= htmlspecialchars($collapseId) ?>" class="accordion-collapse collapse <?= $isOpen ? 'show' : '' ?>" data-bs-parent="#salaryAccordion">
                            <div class="accordion-body">
                                <div class="mini-summary mb-3">
                                    <div class="row g-3">
                                        <div class="col-12 col-md-4">
                                            <div class="item-label">總加項</div>
                                            <div class="item-value text-success"><?= number_format($periodSummary['total_add'], 0) ?></div>
                                        </div>
                                        <div class="col-12 col-md-4">
                                            <div class="item-label">總扣項</div>
                                            <div class="item-value text-danger"><?= number_format($periodSummary['total_sub'], 0) ?></div>
                                        </div>
                                        <div class="col-12 col-md-4">
                                            <div class="item-label">實發薪資</div>
                                            <div class="item-value text-primary"><?= number_format($periodSummary['total_net'], 0) ?></div>
                                        </div>
                                    </div>
                                </div>
                                <div class="table-responsive">
                                    <table class="table table-bordered table-hover text-center align-middle mb-0">
                                        <thead class="table-primary">
                                        <tr>
                                            <th>姓名</th>
                                            <th>年份</th>
                                            <th>月份</th>
                                            <?php foreach ($display_columns as $label): ?>
                                                <th><?= $label ?></th>
                                            <?php endforeach; ?>
                                            <th>總加項</th>
                                            <th>總扣項</th>
                                            <th>實領薪資</th>
                                            <th>狀態</th>
                                        </tr>
                                        </thead>
                                        <tbody>
                                        <?php foreach ($periodInfo['rows'] as $row): ?>
                                            <?php
                                            $link = "employee_salary_report.php?year={$row['year']}&month={$row['month']}&employee_id={$row['employee_id']}";
                                            $statusClass = 'status-pending';
                                            if ($row['status'] === 'Approved') {
                                                $statusClass = 'status-approved';
                                            } elseif ($row['status'] === 'Rejected') {
                                                $statusClass = 'status-reject';
                                            }
                                            ?>
                                            <tr class="data-row" data-link="<?= htmlspecialchars($link) ?>">
                                                <td><?= htmlspecialchars($row['name']) ?></td>
                                                <td><?= htmlspecialchars($row['year']) ?></td>
                                                <td><?= htmlspecialchars($row['month']) ?></td>
                                                <?php foreach ($display_columns as $key => $label): ?>
                                                    <td><?= format_currency($row[$key] ?? null) ?></td>
                                                <?php endforeach; ?>
                                                <td class="text-success fw-bold"><?= number_format($row['total_add'], 0) ?></td>
                                                <td class="text-danger fw-bold"><?= number_format($row['total_sub'], 0) ?></td>
                                                <td class="text-primary fw-bold"><?= number_format($row['total_net'], 0) ?></td>
                                                <td><span class="status-badge <?= $statusClass ?>"><?= format_status($row['status']) ?></span></td>
                                            </tr>
                                        <?php endforeach; ?>
                                        </tbody>
                                        <tfoot>
                                        <tr>
                                            <td colspan="<?= 3 + count($display_columns) ?>" class="text-end">合計</td>
                                            <td class="text-success"><?= number_format($periodSummary['total_add'], 0) ?></td>
                                            <td class="text-danger"><?= number_format($periodSummary['total_sub'], 0) ?></td>
                                            <td class="text-primary"><?= number_format($periodSummary['total_net'], 0) ?></td>
                                            <td></td>
                                        </tr>
                                        </tfoot>
                                    </table>
                                </div>
                            </div>
                        </div>
                    </div>
                <?php endforeach; ?>
            </div>
        <?php endif; ?>
    </div>

    <div class="export-area mt-4 d-flex flex-wrap gap-3">
        <button class="btn btn-outline-secondary" onclick="exportImage()">匯出圖片</button>
        <button class="btn btn-outline-secondary" onclick="exportPDF()">匯出 PDF</button>
        <a class="btn btn-outline-primary" href="employee_salary_report.php" target="_blank">前往個人薪資報表</a>
    </div>
</div>

<script src="https://cdn.jsdelivr.net/npm/html2canvas@1.4.1/dist/html2canvas.min.js"></script>
<script src="https://cdnjs.cloudflare.com/ajax/libs/jspdf/2.5.1/jspdf.umd.min.js"></script>
<script>
// ==============================【JS-1】表格點擊導向個人薪資明細==============================
$(document).on('click', '.data-row', function () {
    const target = $(this).data('link');
    if (target) {
        window.location = target;
    }
});

// ==============================【JS-2】開啟全部手風琴以利匯出==============================
function openAccordionForExport() {
    const states = [];
    $('#salaryAccordion .accordion-item').each(function () {
        const collapse = $(this).find('.accordion-collapse');
        const button = $(this).find('.accordion-button');
        const isOpen = collapse.hasClass('show');
        states.push({ collapse, button, isOpen });
        if (!isOpen) {
            collapse.addClass('show');
            button.removeClass('collapsed').attr('aria-expanded', 'true');
        }
    });
    return states;
}

// ==============================【JS-3】匯出後還原手風琴狀態==============================
function restoreAccordionStates(states) {
    states.forEach(state => {
        if (!state.isOpen) {
            state.collapse.removeClass('show');
            state.button.addClass('collapsed').attr('aria-expanded', 'false');
        } else {
            state.collapse.addClass('show');
            state.button.removeClass('collapsed').attr('aria-expanded', 'true');
        }
    });
}

// ==============================【JS-4】匯出成圖片==============================
function exportImage() {
    const exportTarget = document.querySelector('#exportArea');
    if (!exportTarget) {
        return;
    }
    const states = openAccordionForExport();
    const exportLabel = $('#exportArea').data('exportLabel');
    const fileName = (exportLabel ? exportLabel : '員工薪資總攬') + '.png';

    html2canvas(exportTarget, { scale: 2 }).then(canvas => {
        const link = document.createElement('a');
        link.download = fileName;
        link.href = canvas.toDataURL();
        link.click();
    }).finally(() => {
        restoreAccordionStates(states);
    });
}

// ==============================【JS-5】匯出成 PDF==============================
function exportPDF() {
    const exportTarget = document.querySelector('#exportArea');
    if (!exportTarget) {
        return;
    }
    const states = openAccordionForExport();
    const exportLabel = $('#exportArea').data('exportLabel');
    const fileName = (exportLabel ? exportLabel : '員工薪資總攬') + '.pdf';

    html2canvas(exportTarget, { scale: 2 }).then(canvas => {
        const imgData = canvas.toDataURL('image/png');
        const pdf = new jspdf.jsPDF('l', 'pt', 'a4');
        const pageWidth = pdf.internal.pageSize.getWidth();
        const pageHeight = pdf.internal.pageSize.getHeight();
        const pdfWidth = pageWidth;
        const pdfHeight = (canvas.height * pdfWidth) / canvas.width;
        let heightLeft = pdfHeight;
        let position = 0;

        pdf.addImage(imgData, 'PNG', 0, position, pdfWidth, pdfHeight);
        heightLeft -= pageHeight;

        while (heightLeft > 0) {
            position = heightLeft - pdfHeight;
            pdf.addPage();
            pdf.addImage(imgData, 'PNG', 0, position, pdfWidth, pdfHeight);
            heightLeft -= pageHeight;
        }

        pdf.save(fileName);
    }).finally(() => {
        restoreAccordionStates(states);
    });
}

// ==============================【JS-6】啟用 Bootstrap 提示==============================
$(function () {
    $('[data-bs-toggle="tooltip"]').tooltip();
});
</script>
</body>
</html>