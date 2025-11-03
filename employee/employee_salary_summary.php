<?php
/**
 * 【後端-1】登入檢查
 * 只要帳號已登入即可檢視薪資列表。
 */
session_start();
if (!isset($_SESSION['user'])) {
    header('Location: ../login.php');
    exit;
}

/**
 * 【後端-2】資料庫連線
 */
$conn = new mysqli('localhost', 'root', '', 'mymakeart');
if ($conn->connect_error) {
    die('資料庫連接失敗：' . $conn->connect_error);
}

/**
 * 【後端-3】取得登入中的員工資訊
 */
$login_employee_id = (int)($_SESSION['user']['id'] ?? 0);
$login_employee_name = $_SESSION['user']['name'] ?? '';

/**
 * 【後端-4】篩選條件（年份／月份）
 */
$year  = isset($_GET['year'])  ? trim($_GET['year'])  : '';
$month = isset($_GET['month']) ? trim($_GET['month']) : '';

$where  = ['s.employee_id = ?'];
$params = [$login_employee_id];
$types  = 'i';

if ($year !== '') {
    $where[] = 's.year = ?';
    $params[] = $year;
    $types   .= 'i';
}
if ($month !== '') {
    $where[] = 's.month = ?';
    $params[] = $month;
    $types   .= 'i';
}
$where_sql = 'WHERE ' . implode(' AND ', $where);

/**
 * 【後端-5】查詢薪資資料
 */
$sql = "
    SELECT s.*, e.name
    FROM employee_monthly_salary s
    JOIN employees e ON s.employee_id = e.id
    $where_sql
    ORDER BY s.year DESC, s.month DESC
";
$stmt = $conn->prepare($sql);
$stmt->bind_param($types, ...$params);
$stmt->execute();
$result = $stmt->get_result();
$salary_rows = $result->fetch_all(MYSQLI_ASSOC);

/**
 * 【後端-6】對應欄位名稱與顯示欄位整理
 */
$column_labels = [
    'base_salary'      => '底薪',
    'allowance'        => '津貼',
    'meal_allowance'   => '伙食津貼',
    'attendance_bonus' => '全勤獎金',
    'position_bonus'   => '職務加給',
    'skill_bonus'      => '技能津貼',
    'vacation_cash'    => '特休轉現金',
    'overtime_pay'     => '加班費',
    'labor_insurance'  => '勞保費',
    'health_insurance' => '健保費',
    'leave_deduction'  => '請假扣薪',
    'absent_deduction' => '曠職扣薪',
    'deductions'       => '其他扣款'
];

$display_columns = [];
foreach ($column_labels as $key => $label) {
    foreach ($salary_rows as $row) {
        if (isset($row[$key]) && (float)$row[$key] != 0) {
            $display_columns[$key] = $label;
            break;
        }
    }
}

/**
 * 【後端-7】計算各月的加總數據與列表合計
 */
$total_addition = 0;
$total_deduction = 0;
$total_net = 0;

foreach ($salary_rows as &$row) {
    $addition = 0;
    $deduction = 0;
    foreach ($display_columns as $key => $label) {
        $value = (float)($row[$key] ?? 0);
        if (in_array($key, ['labor_insurance', 'health_insurance', 'leave_deduction', 'absent_deduction', 'deductions'], true)) {
            $deduction += $value;
        } else {
            $addition += $value;
        }
    }
    $row['addition_total'] = $addition;
    $row['deduction_total'] = $deduction;
    $row['net_total'] = $addition - $deduction;

    $total_addition  += $addition;
    $total_deduction += $deduction;
    $total_net       += ($addition - $deduction);
}
unset($row);

/**
 * 【後端-8】狀態文字轉換
 */
function format_status(?string $status): string
{
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
    <meta name="viewport" content="width=device-width, initial-scale=1">
    <title>我的薪資總表</title>
    <link href="https://cdn.jsdelivr.net/npm/bootstrap@5.3.3/dist/css/bootstrap.min.css" rel="stylesheet">
    <script src="https://code.jquery.com/jquery-3.7.1.min.js"></script>
    <script src="https://cdn.jsdelivr.net/npm/bootstrap@5.3.3/dist/js/bootstrap.bundle.min.js"></script>
    <link rel="stylesheet" href="employee_navbar.css">
    <style>
        :root {
            --brand-yellow: #ffcd00;
            --brand-rose: #e36386;
            --brand-blue: #345d9d;
            --brand-dark: #1d2a44;
        }
        body {
            background: #f8f9fb;
        }
        .page-title {
            color: var(--brand-dark);
            font-weight: 700;
            letter-spacing: 0.08rem;
        }
        .title-deco {
            width: 52px;
            height: 6px;
            background: linear-gradient(90deg, var(--brand-yellow), var(--brand-rose));
            border-radius: 999px;
        }
        .filter-card {
            border-left: 6px solid var(--brand-blue);
            border-radius: 14px;
        }
        .filter-card .card-header {
            background: rgba(52, 93, 157, 0.08);
            color: var(--brand-blue);
            font-weight: 600;
        }
        .filter-card .form-label {
            font-weight: 600;
            color: var(--brand-dark);
        }
        .btn-brand-primary {
            background-color: var(--brand-blue);
            color: #fff;
            border-color: var(--brand-blue);
        }
        .btn-brand-primary:hover,
        .btn-brand-primary:focus {
            background-color: #274472;
            border-color: #274472;
        }
        .btn-brand-outline {
            color: var(--brand-blue);
            border-color: var(--brand-blue);
        }
        .btn-brand-outline:hover,
        .btn-brand-outline:focus {
            color: #fff;
            background: var(--brand-blue);
        }
        .summary-badge {
            background: rgba(255, 205, 0, 0.15);
            border-left: 6px solid var(--brand-yellow);
            border-radius: 12px;
        }
        .summary-badge h2 {
            color: var(--brand-dark);
            font-size: 1.25rem;
            font-weight: 700;
            margin-bottom: 0.25rem;
        }
        .summary-badge span {
            color: var(--brand-blue);
            font-weight: 600;
        }
        .salary-table tbody tr {
            transition: transform .2s ease, box-shadow .2s ease;
        }
        .salary-table tbody tr:hover {
            transform: translateY(-3px);
            box-shadow: 0 8px 18px rgba(52, 93, 157, 0.15);
        }
        .salary-table td,
        .salary-table th {
            vertical-align: middle;
        }
        .salary-table .text-income {
            color: #1b7a3a;
            font-weight: 700;
        }
        .salary-table .text-deduction {
            color: #b32d3a;
            font-weight: 700;
        }
        .salary-table .text-net {
            color: var(--brand-blue);
            font-weight: 700;
        }
        .table-caption {
            color: var(--brand-dark);
            font-size: 0.95rem;
            margin-bottom: 0;
        }
        .export-zone .btn {
            min-width: 150px;
        }
        @media (max-width: 575.98px) {
            .summary-badge h2 {
                font-size: 1.05rem;
            }
            .export-zone .btn {
                width: 100%;
            }
        }
    </style>
</head>
<body>
    <?php include 'employee_navbar.php'; ?>

    <main class="container py-4">
        <div class="d-flex align-items-center gap-3 mb-3">
            <div class="title-deco"></div>
            <div>
                <h1 class="page-title mb-1">我的薪資總表</h1>
                <p class="text-muted mb-0">檢視 <?= htmlspecialchars($login_employee_name) ?> 的歷史薪資與各項津貼、扣款紀錄，並可快速導出報表。</p>
            </div>
        </div>

        <section class="card shadow-sm filter-card mb-4">
            <div class="card-header d-flex align-items-center justify-content-between">
                <span>查詢條件</span>
                <small class="text-muted">可依年份、月份篩選薪資資料</small>
            </div>
            <div class="card-body">
                <form class="row g-3" id="filterForm" method="get">
                    <div class="col-md-4">
                        <label class="form-label" for="yearInput">年份</label>
                        <input type="number" class="form-control" id="yearInput" name="year" value="<?= htmlspecialchars($year) ?>" placeholder="例如 2024">
                    </div>
                    <div class="col-md-4">
                        <label class="form-label" for="monthSelect">月份</label>
                        <select class="form-select" id="monthSelect" name="month">
                            <option value="">全部月份</option>
                            <?php for ($m = 1; $m <= 12; $m++): ?>
                                <option value="<?= $m ?>" <?= ($month !== '' && (int)$month === $m) ? 'selected' : '' ?>><?= $m ?> 月</option>
                            <?php endfor; ?>
                        </select>
                    </div>
                    <div class="col-md-4 d-flex align-items-end gap-2">
                        <button type="submit" class="btn btn-brand-primary w-100">套用篩選</button>
                        <button type="button" class="btn btn-brand-outline w-100" id="resetFilter">清除條件</button>
                    </div>
                </form>
            </div>
        </section>

        <section class="summary-badge p-4 mb-4">
            <div class="row g-3 align-items-center">
                <div class="col-md-4 col-sm-6">
                    <h2>累計應發</h2>
                    <span><?= number_format($total_addition) ?> 元</span>
                </div>
                <div class="col-md-4 col-sm-6">
                    <h2>累計扣除</h2>
                    <span><?= number_format($total_deduction) ?> 元</span>
                </div>
                <div class="col-md-4 col-sm-12">
                    <h2>累計實領</h2>
                    <span><?= number_format($total_net) ?> 元</span>
                </div>
            </div>
        </section>

        <section class="card shadow-sm">
            <div class="card-header bg-white">
                <div class="d-flex flex-column flex-lg-row justify-content-between align-items-lg-center">
                    <div>
                        <h2 class="h5 text-dark mb-1">薪資資料列表</h2>
                        <p class="table-caption">點擊列表可檢視對應月份的詳細薪資明細</p>
                    </div>
                    <div class="export-zone d-flex gap-2 mt-3 mt-lg-0">
                        <button type="button" class="btn btn-outline-secondary" id="exportImage">匯出圖片</button>
                        <button type="button" class="btn btn-outline-secondary" id="exportPDF">匯出 PDF</button>
                    </div>
                </div>
            </div>
            <div class="card-body p-0">
                <div class="table-responsive">
                    <table class="table table-bordered table-hover align-middle text-center salary-table mb-0" id="salaryTable">
                        <thead class="table-primary">
                            <tr>
                                <th scope="col">姓名</th>
                                <th scope="col">年份</th>
                                <th scope="col">月份</th>
                                <?php foreach ($display_columns as $label): ?>
                                    <th scope="col"><?= $label ?></th>
                                <?php endforeach; ?>
                                <th scope="col">應發總額</th>
                                <th scope="col">扣款總額</th>
                                <th scope="col">實領薪資</th>
                                <th scope="col">狀態</th>
                                <th scope="col">操作</th>
                            </tr>
                        </thead>
                        <tbody>
                        <?php if (empty($salary_rows)): ?>
                            <tr>
                                <td colspan="<?= 5 + count($display_columns) ?>" class="py-4">目前查無對應的薪資紀錄，請調整篩選條件。</td>
                            </tr>
                        <?php else: ?>
                            <?php foreach ($salary_rows as $row):
                                $detail_link = sprintf(
                                    'employee_salary_detail.php?year=%d&month=%d&employee_id=%d',
                                    (int)$row['year'],
                                    (int)$row['month'],
                                    (int)$row['employee_id']
                                );
                            ?>
                            <tr data-href="<?= htmlspecialchars($detail_link) ?>">
                                <td><?= htmlspecialchars($row['name']) ?></td>
                                <td><?= (int)$row['year'] ?></td>
                                <td><?= (int)$row['month'] ?></td>
                                <?php foreach ($display_columns as $key => $label): ?>
                                    <td><?= ($row[$key] !== null && $row[$key] !== '') ? number_format((float)$row[$key]) : '-' ?></td>
                                <?php endforeach; ?>
                                <td class="text-income"><?= number_format((float)$row['addition_total']) ?></td>
                                <td class="text-deduction"><?= number_format((float)$row['deduction_total']) ?></td>
                                <td class="text-net"><?= number_format((float)$row['net_total']) ?></td>
                                <td><?= format_status($row['status'] ?? null) ?></td>
                                <td>
                                    <a class="btn btn-sm btn-brand-primary" href="<?= htmlspecialchars($detail_link) ?>">檢視明細</a>
                                </td>
                            </tr>
                            <?php endforeach; ?>
                        <?php endif; ?>
                        </tbody>
                    </table>
                </div>
            </div>
        </section>
    </main>

    <script src="https://cdn.jsdelivr.net/npm/html2canvas@1.4.1/dist/html2canvas.min.js"></script>
    <script src="https://cdnjs.cloudflare.com/ajax/libs/jspdf/2.5.1/jspdf.umd.min.js"></script>
    <script>
    // 【前端JS-1】表格列點擊導向明細頁
    $(document).on('click', '#salaryTable tbody tr', function (e) {
        if ($(e.target).closest('a, button').length) {
            return;
        }
        const href = $(this).data('href');
        if (href) {
            window.location.href = href;
        }
    });

    // 【前端JS-2】重置篩選條件
    $('#resetFilter').on('click', function () {
        $('#yearInput').val('');
        $('#monthSelect').val('');
        $('#filterForm').trigger('submit');
    });

    // 【前端JS-3】匯出圖片功能
    $('#exportImage').on('click', function () {
        html2canvas(document.querySelector('#salaryTable')).then(canvas => {
            const link = document.createElement('a');
            link.download = 'salary_summary.png';
            link.href = canvas.toDataURL('image/png');
            link.click();
        });
    });

    // 【前端JS-4】匯出 PDF 功能
    $('#exportPDF').on('click', function () {
        html2canvas(document.querySelector('#salaryTable')).then(canvas => {
            const imgData = canvas.toDataURL('image/png');
            const pdf = new jspdf.jsPDF('p', 'mm', 'a4');
            const pdfW = pdf.internal.pageSize.getWidth();
            const imgW = pdfW;
            const imgH = canvas.height * imgW / canvas.width;
            let position = 0;

            if (imgH <= pdf.internal.pageSize.getHeight()) {
                pdf.addImage(imgData, 'PNG', 0, position, imgW, imgH);
            } else {
                const pageHeight = pdf.internal.pageSize.getHeight();
                const canvasPage = document.createElement('canvas');
                const ctx = canvasPage.getContext('2d');
                let renderedHeight = 0;

                while (renderedHeight < canvas.height) {
                    const sliceHeight = Math.min(canvas.height - renderedHeight, Math.floor(canvas.width * (pageHeight / imgW)));
                    canvasPage.width = canvas.width;
                    canvasPage.height = sliceHeight;
                    ctx.clearRect(0, 0, canvasPage.width, canvasPage.height);
                    ctx.drawImage(canvas, 0, renderedHeight, canvas.width, sliceHeight, 0, 0, canvas.width, sliceHeight);
                    const imgPart = canvasPage.toDataURL('image/png');

                    if (renderedHeight > 0) {
                        pdf.addPage();
                    }
                    pdf.addImage(imgPart, 'PNG', 0, 0, imgW, pageHeight);
                    renderedHeight += sliceHeight;
                }
            }
            pdf.save('salary_summary.pdf');
        });
    });
    </script>
</body>
</html>