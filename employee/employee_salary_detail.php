<?php
// 【PHP-1】登入檢查：僅允許登入員工檢視自己的薪資
session_start();
if (!isset($_SESSION['user'])) {
    header('Location: ../login.php');
    exit;
}

$employeeId = (int)($_SESSION['user']['id'] ?? 0);
if ($employeeId <= 0) {
    die('無法辨識使用者身分，請重新登入。');
}

// 【PHP-2】資料庫連線
$conn = new mysqli('localhost', 'root', '', 'mymakeart');
if ($conn->connect_error) {
    die('資料庫連線失敗：' . $conn->connect_error);
}
$conn->set_charset('utf8mb4');

// 【PHP-3】處理查詢年月參數（預設為上一個月）
$defaultLastMonthTs = strtotime('first day of last month');
$defaultYear  = (int)date('Y', $defaultLastMonthTs);
$defaultMonth = (int)date('n', $defaultLastMonthTs);

$year  = isset($_GET['year'])  ? (int)$_GET['year']  : $defaultYear;
$month = isset($_GET['month']) ? (int)$_GET['month'] : $defaultMonth;

if ($year < 2000 || $year > 2100) {
    $year = $defaultYear;
}
if ($month < 1 || $month > 12) {
    $month = $defaultMonth;
}

// 【PHP-4】忽略非本人查詢的 employee_id（維持安全性）
if (isset($_GET['employee_id']) && (int)$_GET['employee_id'] !== $employeeId) {
    // 員工端僅能檢視自己的資料，因此忽略網址上不同的 ID
}

// 【PHP-5】取得員工基本資料
$employeeStmt = $conn->prepare('SELECT employee_number, hire_date, name, department FROM employees WHERE id = ?');
$employeeStmt->bind_param('i', $employeeId);
$employeeStmt->execute();
$employeeRow = $employeeStmt->get_result()->fetch_assoc() ?: [];
$employeeNumber = $employeeRow['employee_number'] ?? '';
$employeeName   = $employeeRow['name'] ?? '';
$hireDate       = $employeeRow['hire_date'] ?? '';
$department     = $employeeRow['department'] ?? '';
$employeeStmt->close();

// 【PHP-6】取得員工預設薪資結構
$structureStmt = $conn->prepare('SELECT * FROM salary_structure WHERE employee_id = ?');
$structureStmt->bind_param('i', $employeeId);
$structureStmt->execute();
$salaryStructure = $structureStmt->get_result()->fetch_assoc() ?: [];
$structureStmt->close();

// 【PHP-7】取得指定年月的薪資紀錄
$salaryStmt = $conn->prepare('SELECT * FROM employee_monthly_salary WHERE employee_id = ? AND year = ? AND month = ?');
$salaryStmt->bind_param('iii', $employeeId, $year, $month);
$salaryStmt->execute();
$salaryRecord = $salaryStmt->get_result()->fetch_assoc() ?: [];
$salaryStmt->close();

// 【PHP-8】整理薪資金額（若該月無明細則以預設值呈現）
$baseSalary      = $salaryRecord['base_salary']      ?? ($salaryStructure['base_salary']      ?? 0);
$mealAllowance   = $salaryRecord['meal_allowance']   ?? ($salaryStructure['meal_allowance']   ?? 0);
$attendanceBonus = $salaryRecord['attendance_bonus'] ?? ($salaryStructure['attendance_bonus'] ?? 0);
$positionBonus   = $salaryRecord['position_bonus']   ?? ($salaryStructure['position_bonus']   ?? 0);
$skillBonus      = $salaryRecord['skill_bonus']      ?? ($salaryStructure['skill_bonus']      ?? 0);
$laborInsurance  = $salaryRecord['labor_insurance']  ?? ($salaryStructure['labor_insurance']  ?? 0);
$healthInsurance = $salaryRecord['health_insurance'] ?? ($salaryStructure['health_insurance'] ?? 0);
$leaveDeduction  = isset($salaryRecord['leave_deduction'])  ? (float)$salaryRecord['leave_deduction']  : 0;
$absentDeduction = isset($salaryRecord['absent_deduction']) ? (float)$salaryRecord['absent_deduction'] : 0;
$vacationCash    = isset($salaryRecord['vacation_cash'])    ? (float)$salaryRecord['vacation_cash']    : 0;
$overtimePay     = isset($salaryRecord['overtime_pay'])     ? (float)$salaryRecord['overtime_pay']     : 0;

$hourlyRate = $baseSalary > 0 ? (int)ceil($baseSalary / 240) : 0;

// 【PHP-9】計算查詢期間（同一整月）
$startOfMonth = sprintf('%04d-%02d-01', $year, $month);
$endOfMonth   = date('Y-m-t', strtotime($startOfMonth));

// 【PHP-10】取得該月核准的請假與加班申請
$requestStmt = $conn->prepare('
    SELECT type, subtype, reason, start_date, end_date, status
    FROM requests
    WHERE employee_id = ?
      AND status = "Approved"
      AND (
            (start_date BETWEEN ? AND ?) OR
            (end_date BETWEEN ? AND ?) OR
            (start_date <= ? AND end_date >= ?)
      )
');
$requestStmt->bind_param(
    'issssss',
    $employeeId,
    $startOfMonth, $endOfMonth,
    $startOfMonth, $endOfMonth,
    $startOfMonth, $endOfMonth
);
$requestStmt->execute();
$approvedRequests = $requestStmt->get_result()->fetch_all(MYSQLI_ASSOC);
$requestStmt->close();

// 【PHP-11】取得缺席資料並計算缺席分鐘
$absentStmt = $conn->prepare('
    SELECT date, status_text, absent_minutes
    FROM saved_attendance
    WHERE employee_number = ?
      AND YEAR(date) = ?
      AND MONTH(date) = ?
      AND absent_minutes > 0
');
$absentStmt->bind_param('sii', $employeeNumber, $year, $month);
$absentStmt->execute();
$absentRows = $absentStmt->get_result()->fetch_all(MYSQLI_ASSOC);
$absentStmt->close();

$totalAbsentMinutes = 0;
foreach ($absentRows as $row) {
    $totalAbsentMinutes += (int)($row['absent_minutes'] ?? 0);
}

// 【PHP-12】取得班別資訊，供請假工時計算
$shiftStmt = $conn->prepare('
    SELECT s.start_time, s.end_time, s.break_start, s.break_end
    FROM shifts s
    JOIN employees e ON s.id = e.shift_id
    WHERE e.id = ?
');
$shiftStmt->bind_param('i', $employeeId);
$shiftStmt->execute();
$shiftRow = $shiftStmt->get_result()->fetch_assoc() ?: [
    'start_time'  => '09:00:00',
    'end_time'    => '18:00:00',
    'break_start' => '12:00:00',
    'break_end'   => '13:00:00'
];
$shiftStmt->close();

// 【PHP-13】計算請假扣薪明細（若薪資紀錄已有金額則不重新計算）
$leaveDeductionDetails = [];
if (empty($salaryRecord['leave_deduction'])) {
    $leaveStmt = $conn->prepare('
        SELECT r.subtype, r.start_date, r.end_date, l.salary_ratio
        FROM requests r
        JOIN leave_types l ON r.subtype = l.name
        WHERE r.employee_id = ?
          AND r.status = "Approved"
          AND (
                (r.start_date BETWEEN ? AND ?) OR
                (r.end_date BETWEEN ? AND ?) OR
                (r.start_date <= ? AND r.end_date >= ?)
          )
    ');
    $leaveStmt->bind_param(
        'issssss',
        $employeeId,
        $startOfMonth, $endOfMonth,
        $startOfMonth, $endOfMonth,
        $startOfMonth, $endOfMonth
    );
    $leaveStmt->execute();
    $leaveRows = $leaveStmt->get_result()->fetch_all(MYSQLI_ASSOC);
    $leaveStmt->close();

    $calculatedAmounts = [];
    foreach ($leaveRows as $leaveRow) {
        $hours = calculateLeaveHoursByShift($leaveRow['start_date'], $leaveRow['end_date'], $shiftRow);
        $salaryRatio   = (float)($leaveRow['salary_ratio'] ?? 0);
        $deductPercent = max(0, 100 - $salaryRatio);
        $amount        = (int)ceil($hourlyRate * $hours * ($deductPercent / 100));

        $calculatedAmounts[] = $amount;
        $leaveDeductionDetails[] = [
            'date'           => date('Y-m-d', strtotime($leaveRow['start_date'])),
            'type'           => $leaveRow['subtype'] ?? '其他假別',
            'hours'          => round($hours, 1),
            'hourly_rate'    => $hourlyRate,
            'deduct_percent' => $deductPercent,
            'amount'         => $amount,
        ];
    }
    $leaveDeduction = array_sum($calculatedAmounts);
}

// 【PHP-14】統計各假別使用情況
$leaveTypesResult = $conn->query('SELECT name, days_per_year FROM leave_types');
$leaveStatistic = [];
while ($type = $leaveTypesResult->fetch_assoc()) {
    $leaveStatistic[$type['name']] = [
        'total_days' => (float)$type['days_per_year'],
        'used_days'  => 0,
        'used_hours' => 0,
    ];
}
$leaveTypesResult->free();

$usedStmt = $conn->prepare('SELECT subtype, start_date, end_date FROM requests WHERE employee_id = ? AND status = "Approved"');
$usedStmt->bind_param('i', $employeeId);
$usedStmt->execute();
$usedRows = $usedStmt->get_result()->fetch_all(MYSQLI_ASSOC);
$usedStmt->close();

foreach ($usedRows as $row) {
    $hours = calculateLeaveHoursByShift($row['start_date'], $row['end_date'], $shiftRow);
    $days = floor($hours / 8);
    $remainHours = fmod($hours, 8);
    $subtype = $row['subtype'];
    if (isset($leaveStatistic[$subtype])) {
        $leaveStatistic[$subtype]['used_days']  += $days;
        $leaveStatistic[$subtype]['used_hours'] += $remainHours;
    }
}
$usedLeaveStatistic = array_filter(
    $leaveStatistic,
    fn($item) => $item['used_days'] > 0 || $item['used_hours'] > 0
);

// 【PHP-15】取得特休紀錄與彙總
$historyStmt = $conn->prepare('
    SELECT year, month, days AS vacation_days, hours, status, created_at
    FROM annual_leave_records
    WHERE employee_id = ?
    ORDER BY created_at DESC
    LIMIT 10
');
$historyStmt->bind_param('i', $employeeId);
$historyStmt->execute();
$vacationHistory = $historyStmt->get_result()->fetch_all(MYSQLI_ASSOC);
$historyStmt->close();
$hasVacationHistory = !empty($vacationHistory);

$summaryStmt = $conn->prepare('
    SELECT
        SUM(CASE WHEN status = "取得" THEN days  ELSE 0 END) AS total_acquired_days,
        SUM(CASE WHEN status = "取得" THEN hours ELSE 0 END) AS total_acquired_hours,
        SUM(CASE WHEN status = "使用" THEN days  ELSE 0 END) AS total_used_days,
        SUM(CASE WHEN status = "使用" THEN hours ELSE 0 END) AS total_used_hours,
        SUM(CASE WHEN status = "轉現金" THEN days ELSE 0 END) AS total_cash_days,
        SUM(CASE WHEN status = "轉現金" THEN hours ELSE 0 END) AS total_cash_hours
    FROM annual_leave_records
    WHERE employee_id = ?
');
$summaryStmt->bind_param('i', $employeeId);
$summaryStmt->execute();
$vacationSummary = $summaryStmt->get_result()->fetch_assoc() ?: [];
$summaryStmt->close();
$hasVacationSummary = array_sum(array_map('floatval', $vacationSummary)) > 0;

// 【PHP-16】計算加班拆解（若薪資紀錄未寫死金額）
$overtimeDetails = [];
if (empty($salaryRecord['overtime_pay'])) {
    foreach ($approvedRequests as $request) {
        if ($request['type'] !== '加班') {
            continue;
        }
        $startTs = strtotime($request['start_date']);
        $endTs   = strtotime($request['end_date']);
        if (!$startTs || !$endTs || $endTs <= $startTs) {
            continue;
        }

        $durationHours = round(($endTs - $startTs) / 3600, 2);
        $isHoliday = isHoliday(date('Y-m-d', $startTs), $conn);
        $segments = [];
        $remaining = $durationHours;

        if ($isHoliday) {
            if ($remaining > 8) {
                $segments[] = ['hours' => 2, 'rate' => 1.34];
                $segments[] = ['hours' => 6, 'rate' => 1.67];
                $segments[] = ['hours' => $remaining - 8, 'rate' => 2.67];
            } elseif ($remaining > 2) {
                $segments[] = ['hours' => 2, 'rate' => 1.34];
                $segments[] = ['hours' => $remaining - 2, 'rate' => 1.67];
            } else {
                $segments[] = ['hours' => $remaining, 'rate' => 1.34];
            }
        } else {
            if ($remaining > 2) {
                $segments[] = ['hours' => 2, 'rate' => 1.34];
                $segments[] = ['hours' => $remaining - 2, 'rate' => 1.67];
            } else {
                $segments[] = ['hours' => $remaining, 'rate' => 1.34];
            }
        }

        $accumulatedHours = 0;
        foreach ($segments as $segment) {
            $segmentHours = (float)$segment['hours'];
            $segmentRate  = (float)$segment['rate'];
            $payAmount    = (int)ceil($hourlyRate * $segmentHours * $segmentRate);
            $overtimePay += $payAmount;

            $overtimeDetails[] = [
                'start'        => $request['start_date'],
                'end'          => $request['end_date'],
                'hours'        => $segmentHours,
                'rate'         => $segmentRate,
                'pay'          => $payAmount,
                'range_label'  => buildOvertimeRangeLabel($accumulatedHours, $segmentHours),
                'is_holiday'   => $isHoliday,
            ];
            $accumulatedHours += $segmentHours;
        }
    }
}

// 【PHP-17】薪資加總
$grossSalary     = $baseSalary + $mealAllowance + $attendanceBonus + $positionBonus + $skillBonus + $vacationCash + $overtimePay;
$totalDeductions = $laborInsurance + $healthInsurance + $leaveDeduction + $absentDeduction;
$netSalary       = $grossSalary - $totalDeductions;

// 【PHP-18】整理前端顯示所需的薪資項目
$earningItems = [];
$earningConfigs = [
    ['label' => '底薪',       'amount' => $baseSalary,      'note' => $salaryRecord['base_salary_note']      ?? ''],
    ['label' => '餐費補助',   'amount' => $mealAllowance,   'note' => $salaryRecord['meal_allowance_note']   ?? ''],
    ['label' => '全勤獎金',   'amount' => $attendanceBonus, 'note' => $salaryRecord['attendance_bonus_note'] ?? ''],
    ['label' => '職務加給',   'amount' => $positionBonus,   'note' => $salaryRecord['position_bonus_note']   ?? ''],
    ['label' => '技術津貼',   'amount' => $skillBonus,      'note' => $salaryRecord['skill_bonus_note']      ?? ''],
    ['label' => '特休轉現金', 'amount' => $vacationCash,    'note' => $salaryRecord['vacation_cash_note']    ?? '底薪 ÷ 240 × 8 小時 × 天數'],
    ['label' => '加班費',     'amount' => $overtimePay,     'note' => $salaryRecord['overtime_note']         ?? ''],
];
foreach ($earningConfigs as $config) {
    if ((float)$config['amount'] > 0) {
        $earningItems[] = $config;
    }
}

$deductionItems = [];
$deductionConfigs = [
    ['label' => '勞保費',   'amount' => $laborInsurance,  'note' => $salaryRecord['labor_insurance_note']  ?? '依照投保級距計算'],
    ['label' => '健保費',   'amount' => $healthInsurance, 'note' => $salaryRecord['health_insurance_note'] ?? '依照投保級距計算'],
    ['label' => '請假扣除', 'amount' => $leaveDeduction,  'note' => $salaryRecord['leave_deduction_note']  ?? ''],
    ['label' => '缺席扣除', 'amount' => $absentDeduction, 'note' => $salaryRecord['absent_deduction_note'] ?? ''],
];
foreach ($deductionConfigs as $config) {
    if ((float)$config['amount'] > 0) {
        $deductionItems[] = $config;
    }
}

// 【PHP-19】建立匯出所需的公司資訊
$companyName = '麥創藝有限公司';

// 【PHP-20】取得員工可切換的年月清單
$monthStmt = $conn->prepare('SELECT DISTINCT year, month FROM employee_monthly_salary WHERE employee_id = ? ORDER BY year DESC, month DESC');
$monthStmt->bind_param('i', $employeeId);
$monthStmt->execute();
$monthRows = $monthStmt->get_result()->fetch_all(MYSQLI_ASSOC);
$monthStmt->close();

$yearOptions = [];
foreach ($monthRows as $row) {
    $y = (int)$row['year'];
    if (!in_array($y, $yearOptions, true)) {
        $yearOptions[] = $y;
    }
}
if (!in_array($year, $yearOptions, true)) {
    $yearOptions[] = $year;
}
rsort($yearOptions);

$monthOptions = range(1, 12);

// 【PHP-21】共用函式
function calculateLeaveHoursByShift($start, $end, $shift)
{
    $startTs = strtotime($start);
    $endTs   = strtotime($end);
    if (!$startTs || !$endTs || $endTs <= $startTs) {
        return 0;
    }

    $totalSeconds = 0;
    $currentTs = $startTs;
    while ($currentTs <= $endTs) {
        $currentDate = date('Y-m-d', $currentTs);
        $shiftStart  = strtotime("{$currentDate} {$shift['start_time']}");
        $shiftEnd    = strtotime("{$currentDate} {$shift['end_time']}");
        $breakStart  = strtotime("{$currentDate} {$shift['break_start']}");
        $breakEnd    = strtotime("{$currentDate} {$shift['break_end']}");

        $actualStart = max($currentTs, $shiftStart);
        $actualEnd   = min($endTs, $shiftEnd);

        if ($actualEnd <= $actualStart) {
            $currentTs = strtotime('+1 day', strtotime($currentDate));
            continue;
        }

        $duration = $actualEnd - $actualStart;
        $breakOverlap = max(0, min($actualEnd, $breakEnd) - max($actualStart, $breakStart));
        $workSeconds = max(0, $duration - $breakOverlap);
        $totalSeconds += $workSeconds;

        $currentTs = strtotime('+1 day', strtotime($currentDate));
    }
    return round($totalSeconds / 3600, 1);
}

function isHoliday($date, $conn)
{
    $dow = (int)date('w', strtotime($date));
    $stmt = $conn->prepare('SELECT is_working_day FROM holidays WHERE holiday_date = ?');
    $stmt->bind_param('s', $date);
    $stmt->execute();
    $row = $stmt->get_result()->fetch_assoc();
    $stmt->close();

    if ($row !== null) {
        return (int)$row['is_working_day'] === 0;
    }
    return ($dow === 0 || $dow === 6);
}

function buildOvertimeRangeLabel($startHour, $segmentHours)
{
    $from = $startHour + 1;
    $to   = $startHour + $segmentHours;
    if (abs($segmentHours - 1) < 0.01) {
        return sprintf('第 %s 小時', formatDecimalText($from));
    }
    return sprintf('第 %s - %s 小時', formatDecimalText($from), formatDecimalText($to));
}

function formatCurrency($amount, $decimals = 0)
{
    return number_format((float)$amount, $decimals, '.', ',');
}

function formatDecimalText($value, $precision = 1)
{
    if ($value === '' || $value === null) {
        return '0';
    }
    return rtrim(rtrim(number_format((float)$value, $precision, '.', ''), '0'), '.');
}
?>
<!DOCTYPE html>
<html lang="zh-Hant">
<head>
    <meta charset="UTF-8">
    <meta name="viewport" content="width=device-width, initial-scale=1">
    <title>我的薪資明細</title>
    <link href="https://cdn.jsdelivr.net/npm/bootstrap@5.3.3/dist/css/bootstrap.min.css" rel="stylesheet">
    <link rel="stylesheet" href="employee_navbar.css">
    <style>
        :root {
            --brand-gold: #ffcd00;
            --brand-pink: #e36386;
            --brand-blue: #345d9d;
            --brand-dark: #1f2a44;
        }
        body {
            background: #f8f9fb;
        }
        .salary-hero {
            background: linear-gradient(135deg, rgba(52,93,157,0.95), rgba(227,99,134,0.9));
            color: #fff;
        }
        .salary-hero .info-chip {
            background: rgba(0, 0, 0, 0.2);
            border-radius: 999px;
            padding: 6px 16px;
            font-size: 0.95rem;
        }
        .brand-card {
            border-radius: 18px;
            overflow: hidden;
            border: none;
        }
        .brand-card .card-header {
            background: var(--brand-blue);
            color: #fff;
            font-weight: 600;
            letter-spacing: 0.05em;
            padding: 1rem 1.5rem;
        }
        .brand-card .card-body {
            background: #fff;
            padding: 1.5rem;
        }
        .summary-badge {
            display: inline-flex;
            align-items: center;
            gap: 6px;
            padding: 10px 18px;
            border-radius: 14px;
            color: #fff;
            font-weight: 600;
        }
        .summary-badge.gross { background: var(--brand-gold); color: #1f1f1f; }
        .summary-badge.deduction { background: var(--brand-pink); }
        .summary-badge.net { background: var(--brand-blue); }
        .table thead.table-primary th {
            background: rgba(255, 205, 0, 0.35);
            color: #2b2b2b;
        }
        .table > :not(caption) > * > * {
            padding: 0.9rem 1.35rem;
        }
        .table tbody tr td:first-child {
            font-weight: 600;
        }
        .status-approved {
            color: #0f5132;
            font-weight: 600;
        }
        .list-dot {
            position: relative;
            padding-left: 18px;
        }
        .list-dot::before {
            content: '';
            width: 8px;
            height: 8px;
            border-radius: 50%;
            background: var(--brand-pink);
            position: absolute;
            left: 0;
            top: 8px;
        }
        .export-btn {
            background: var(--brand-blue);
            border: none;
            color: #fff;
        }
        .export-btn:hover {
            background: #274677;
            color: #fff;
        }
        .overtime-badge {
            display: inline-flex;
            gap: 6px;
            align-items: center;
            padding: 4px 10px;
            border-radius: 999px;
            font-size: 0.85rem;
            background: rgba(52, 93, 157, 0.1);
            color: var(--brand-blue);
        }
        .holiday-badge {
            background: rgba(227, 99, 134, 0.12);
            color: var(--brand-pink);
        }
        .salary-table-note {
            white-space: pre-line;
        }
    </style>
</head>
<body>
<?php include 'employee_navbar.php'; ?>
<main class="container py-4 px-3 px-md-4 px-lg-5" id="salaryDetail">
    <div id="salaryDetailCapture" class="d-flex flex-column gap-4">
        <section class="salary-hero rounded-4 p-4 shadow-sm">
            <div class="row g-4 align-items-center">
                <div class="col-lg-8">
                    <div class="d-flex align-items-center gap-3 mb-3">
                        <h1 class="h2 mb-0 fw-bold">我的薪資明細</h1>
                        <span class="badge bg-light text-dark fs-6"><?= htmlspecialchars($department ?: '所屬部門') ?></span>
                    </div>
                    <p class="mb-3">您好，<?= htmlspecialchars($employeeName) ?>。以下為 <?= htmlspecialchars($year) ?> 年 <?= htmlspecialchars($month) ?> 月的薪資資訊，請詳閱每一項細節。</p>
                    <div class="d-flex flex-wrap gap-2">
                        <span class="info-chip">期間：<?= htmlspecialchars($year) ?> 年 <?= htmlspecialchars($month) ?> 月</span>
                        <span class="info-chip">員工編號：<?= htmlspecialchars($employeeNumber ?: '未建檔') ?></span>
                        <?php if (!empty($hireDate)): ?>
                        <span class="info-chip">到職日：<?= htmlspecialchars($hireDate) ?></span>
                        <?php endif; ?>
                    </div>
                </div>
                <div class="col-lg-4">
                    <div class="card border-0 shadow-sm">
                        <div class="card-header text-center fw-semibold" style="background: rgba(255,205,0,0.2); color: var(--brand-dark);">查詢其他月份</div>
                        <div class="card-body">
                            <form class="row g-2" method="get">
                                <div class="col-12">
                                    <label class="form-label">年份</label>
                                    <select name="year" class="form-select">
                                        <?php foreach ($yearOptions as $optionYear): ?>
                                            <option value="<?= $optionYear ?>" <?= ($optionYear === $year) ? 'selected' : '' ?>><?= $optionYear ?></option>
                                        <?php endforeach; ?>
                                    </select>
                                </div>
                                <div class="col-12">
                                    <label class="form-label">月份</label>
                                    <select name="month" class="form-select">
                                        <?php foreach ($monthOptions as $optionMonth): ?>
                                            <option value="<?= $optionMonth ?>" <?= ($optionMonth === $month) ? 'selected' : '' ?>><?= $optionMonth ?></option>
                                        <?php endforeach; ?>
                                    </select>
                                </div>
                                <input type="hidden" name="employee_id" value="<?= $employeeId ?>">
                                <div class="col-12 d-grid">
                                    <button type="submit" class="btn btn-dark">切換</button>
                                </div>
                            </form>
                        </div>
                    </div>
                </div>
            </div>
        </section>

        <section class="row g-3">
            <div class="col-md-4">
                <span class="summary-badge gross w-100 justify-content-center">
                    <span>總工資</span>
                    <strong><?= formatCurrency($grossSalary) ?></strong>
                </span>
            </div>
            <div class="col-md-4">
                <span class="summary-badge deduction w-100 justify-content-center">
                    <span>總扣除</span>
                    <strong><?= formatCurrency($totalDeductions) ?></strong>
                </span>
            </div>
            <div class="col-md-4">
                <span class="summary-badge net w-100 justify-content-center">
                    <span>實領薪資</span>
                    <strong><?= formatCurrency($netSalary) ?></strong>
                </span>
            </div>
        </section>

        <section class="brand-card shadow-sm">
            <div class="card-header">薪資項目明細</div>
            <div class="card-body">
                <div class="row g-4">
                    <div class="col-lg-6">
                        <h2 class="h5 fw-bold mb-3">收入與補助</h2>
                        <div class="table-responsive">
                            <table class="table table-bordered align-middle">
                                <thead class="table-primary">
                                    <tr>
                                        <th style="width: 30%;">項目</th>
                                        <th style="width: 20%;">金額</th>
                                        <th>說明</th>
                                    </tr>
                                </thead>
                                <tbody>
                                    <?php if (empty($earningItems)): ?>
                                    <tr><td colspan="3" class="text-center text-muted">本月無收入類項目</td></tr>
                                    <?php else: ?>
                                        <?php foreach ($earningItems as $item): ?>
                                        <tr>
                                            <td><?= htmlspecialchars($item['label']) ?></td>
                                            <td class="text-end"><?= formatCurrency($item['amount']) ?></td>
                                            <td class="salary-table-note"><?= htmlspecialchars($item['note']) ?></td>
                                        </tr>
                                        <?php endforeach; ?>
                                    <?php endif; ?>
                                    <tr class="table-light fw-bold">
                                        <td>小計</td>
                                        <td class="text-end"><?= formatCurrency($grossSalary) ?></td>
                                        <td>所有收入項目合計</td>
                                    </tr>
                                </tbody>
                            </table>
                        </div>
                    </div>
                    <div class="col-lg-6">
                        <h2 class="h5 fw-bold mb-3">扣除項目</h2>
                        <div class="table-responsive">
                            <table class="table table-bordered align-middle">
                                <thead class="table-primary">
                                    <tr>
                                        <th style="width: 30%;">項目</th>
                                        <th style="width: 20%;">金額</th>
                                        <th>說明</th>
                                    </tr>
                                </thead>
                                <tbody>
                                    <?php if (empty($deductionItems)): ?>
                                    <tr><td colspan="3" class="text-center text-muted">本月無扣除類項目</td></tr>
                                    <?php else: ?>
                                        <?php foreach ($deductionItems as $item): ?>
                                        <tr>
                                            <td><?= htmlspecialchars($item['label']) ?></td>
                                            <td class="text-end text-danger">-<?= formatCurrency($item['amount']) ?></td>
                                            <td class="salary-table-note">
                                                <?php if (!empty($item['note'])): ?>
                                                    <?= htmlspecialchars($item['note']) ?>
                                                <?php elseif ($item['label'] === '請假扣除' && !empty($leaveDeductionDetails)): ?>
                                                    <?php foreach ($leaveDeductionDetails as $detail): ?>
                                                        <div class="list-dot mb-1">
                                                            <?= htmlspecialchars($detail['date']) ?>｜<?= htmlspecialchars($detail['type']) ?>｜<?= formatDecimalText($detail['hours']) ?> 小時 × 時薪 <?= formatCurrency($detail['hourly_rate']) ?> × 扣除 <?= formatCurrency($detail['deduct_percent']) ?>% = <?= formatCurrency($detail['amount']) ?> 元
                                                        </div>
                                                    <?php endforeach; ?>
                                                <?php elseif ($item['label'] === '缺席扣除'): ?>
                                                    缺席 <?= formatDecimalText($totalAbsentMinutes / 60) ?> 小時（<?= $totalAbsentMinutes ?> 分） × 時薪 <?= formatCurrency($hourlyRate) ?> 元
                                                <?php else: ?>
                                                    -
                                                <?php endif; ?>
                                            </td>
                                        </tr>
                                        <?php endforeach; ?>
                                    <?php endif; ?>
                                    <tr class="table-light fw-bold">
                                        <td>小計</td>
                                        <td class="text-end text-danger">-<?= formatCurrency($totalDeductions) ?></td>
                                        <td>所有扣除項目合計</td>
                                    </tr>
                                    <tr class="table-primary fw-bold">
                                        <td>實領薪資</td>
                                        <td class="text-end text-primary"><?= formatCurrency($netSalary) ?></td>
                                        <td>總工資 − 總扣除</td>
                                    </tr>
                                </tbody>
                            </table>
                        </div>
                    </div>
                </div>
            </div>
        </section>

        <?php if (!empty($overtimeDetails)): ?>
        <section class="brand-card shadow-sm">
            <div class="card-header">加班費拆解</div>
            <div class="card-body">
                <div class="table-responsive">
                    <table class="table table-bordered align-middle">
                        <thead class="table-primary">
                            <tr>
                                <th>起訖時間</th>
                                <th>時段</th>
                                <th>時數</th>
                                <th>倍率</th>
                                <th>金額</th>
                                <th>備註</th>
                            </tr>
                        </thead>
                        <tbody>
                            <?php foreach ($overtimeDetails as $detail): ?>
                            <tr>
                                <td><?= htmlspecialchars($detail['start']) ?><br>~ <?= htmlspecialchars($detail['end']) ?></td>
                                <td><?= htmlspecialchars($detail['range_label']) ?></td>
                                <td class="text-end"><?= formatDecimalText($detail['hours']) ?></td>
                                <td class="text-end">× <?= number_format($detail['rate'], 2) ?></td>
                                <td class="text-end"><?= formatCurrency($detail['pay']) ?></td>
                                <td>
                                    <span class="overtime-badge <?= $detail['is_holiday'] ? 'holiday-badge' : '' ?>">
                                        <?= $detail['is_holiday'] ? '假日加班' : '平日加班' ?>
                                    </span>
                                </td>
                            </tr>
                            <?php endforeach; ?>
                        </tbody>
                    </table>
                </div>
            </div>
        </section>
        <?php endif; ?>

        <?php if (!empty($approvedRequests)): ?>
        <section class="brand-card shadow-sm">
            <div class="card-header">當月核准的申請紀錄</div>
            <div class="card-body">
                <div class="table-responsive">
                    <table class="table table-bordered align-middle">
                        <thead class="table-primary">
                            <tr>
                                <th>類型</th>
                                <th>細項</th>
                                <th>申請原因</th>
                                <th>開始時間</th>
                                <th>結束時間</th>
                                <th>狀態</th>
                            </tr>
                        </thead>
                        <tbody>
                            <?php foreach ($approvedRequests as $request): ?>
                            <tr>
                                <td><?= htmlspecialchars($request['type']) ?></td>
                                <td><?= htmlspecialchars($request['subtype'] ?? '-') ?></td>
                                <td><?= htmlspecialchars($request['reason'] ?? '-') ?></td>
                                <td><?= htmlspecialchars($request['start_date']) ?></td>
                                <td><?= htmlspecialchars($request['end_date']) ?></td>
                                <td><span class="status-approved">已核准</span></td>
                            </tr>
                            <?php endforeach; ?>
                        </tbody>
                    </table>
                </div>
            </div>
        </section>
        <?php endif; ?>

        <?php if (!empty($usedLeaveStatistic)): ?>
        <section class="brand-card shadow-sm">
            <div class="card-header">年度假別使用概況</div>
            <div class="card-body">
                <div class="table-responsive">
                    <table class="table table-bordered align-middle text-center">
                        <thead class="table-primary">
                            <tr>
                                <th>假別</th>
                                <th>年度可休天數</th>
                                <th>已使用天數</th>
                                <th>已使用小時</th>
                                <th>剩餘天數</th>
                                <th>剩餘小時</th>
                            </tr>
                        </thead>
                        <tbody>
                            <?php foreach ($usedLeaveStatistic as $leaveType => $stat): ?>
                            <?php
                                $totalHours = $stat['total_days'] * 8;
                                $usedHours  = $stat['used_days'] * 8 + $stat['used_hours'];
                                $remainHours = max(0, $totalHours - $usedHours);
                                $remainDays  = floor($remainHours / 8);
                                $remainHour  = fmod($remainHours, 8);
                            ?>
                            <tr>
                                <td><?= htmlspecialchars($leaveType) ?></td>
                                <td><?= formatDecimalText($stat['total_days']) ?></td>
                                <td><?= formatDecimalText($stat['used_days']) ?></td>
                                <td><?= formatDecimalText($stat['used_hours']) ?></td>
                                <td><?= formatDecimalText($remainDays) ?></td>
                                <td><?= formatDecimalText($remainHour) ?></td>
                            </tr>
                            <?php endforeach; ?>
                        </tbody>
                    </table>
                </div>
            </div>
        </section>
        <?php endif; ?>

        <?php if ($hasVacationHistory): ?>
        <section class="brand-card shadow-sm">
            <div class="card-header">近期特休調整紀錄</div>
            <div class="card-body">
                <div class="table-responsive">
                    <table class="table table-bordered align-middle text-center">
                        <thead class="table-primary">
                            <tr>
                                <th>年份</th>
                                <th>月份</th>
                                <th>天數</th>
                                <th>小時</th>
                                <th>狀態</th>
                                <th>建立時間</th>
                            </tr>
                        </thead>
                        <tbody>
                            <?php foreach ($vacationHistory as $history): ?>
                            <tr>
                                <td><?= htmlspecialchars($history['year']) ?></td>
                                <td><?= htmlspecialchars($history['month']) ?></td>
                                <td><?= formatDecimalText($history['vacation_days'] ?? 0) ?></td>
                                <td><?= formatDecimalText($history['hours'] ?? 0) ?></td>
                                <td><?= htmlspecialchars($history['status']) ?></td>
                                <td><?= htmlspecialchars($history['created_at']) ?></td>
                            </tr>
                            <?php endforeach; ?>
                        </tbody>
                    </table>
                </div>
            </div>
        </section>
        <?php endif; ?>

        <?php if ($hasVacationSummary): ?>
        <section class="brand-card shadow-sm">
            <div class="card-header">特休彙總資訊</div>
            <div class="card-body">
                <div class="table-responsive">
                    <table class="table table-bordered align-middle text-center">
                        <thead class="table-primary">
                            <tr>
                                <th>累計取得天數</th>
                                <th>累計取得小時</th>
                                <th>已使用天數</th>
                                <th>已使用小時</th>
                                <th>轉現金天數</th>
                                <th>轉現金小時</th>
                                <th>推估剩餘天數</th>
                                <th>推估剩餘小時</th>
                            </tr>
                        </thead>
                        <tbody>
                            <?php
                                $acquiredHours = (float)($vacationSummary['total_acquired_hours'] ?? 0);
                                $acquiredDays  = (float)($vacationSummary['total_acquired_days'] ?? 0);
                                $usedHours     = (float)($vacationSummary['total_used_hours'] ?? 0);
                                $usedDays      = (float)($vacationSummary['total_used_days'] ?? 0);
                                $cashHours     = (float)($vacationSummary['total_cash_hours'] ?? 0);
                                $cashDays      = (float)($vacationSummary['total_cash_days'] ?? 0);

                                $remainHours = max(0, ($acquiredDays * 8 + $acquiredHours) - ($usedDays * 8 + $usedHours) - ($cashDays * 8 + $cashHours));
                                $remainDays  = floor($remainHours / 8);
                                $remainHour  = fmod($remainHours, 8);
                            ?>
                            <tr>
                                <td><?= formatDecimalText($acquiredDays) ?></td>
                                <td><?= formatDecimalText($acquiredHours) ?></td>
                                <td><?= formatDecimalText($usedDays) ?></td>
                                <td><?= formatDecimalText($usedHours) ?></td>
                                <td><?= formatDecimalText($cashDays) ?></td>
                                <td><?= formatDecimalText($cashHours) ?></td>
                                <td><?= formatDecimalText($remainDays) ?></td>
                                <td><?= formatDecimalText($remainHour) ?></td>
                            </tr>
                        </tbody>
                    </table>
                </div>
            </div>
        </section>
        <?php endif; ?>

        <?php if (!empty($absentRows)): ?>
        <section class="brand-card shadow-sm">
            <div class="card-header">缺席紀錄</div>
            <div class="card-body">
                <div class="table-responsive">
                    <table class="table table-bordered align-middle text-center">
                        <thead class="table-primary">
                            <tr>
                                <th>日期</th>
                                <th>狀態說明</th>
                                <th>缺席分鐘</th>
                            </tr>
                        </thead>
                        <tbody>
                            <?php foreach ($absentRows as $absent): ?>
                            <tr>
                                <td><?= htmlspecialchars($absent['date']) ?></td>
                                <td><?= htmlspecialchars($absent['status_text'] ?? '-') ?></td>
                                <td><?= htmlspecialchars($absent['absent_minutes']) ?></td>
                            </tr>
                            <?php endforeach; ?>
                        </tbody>
                    </table>
                </div>
            </div>
        </section>
        <?php endif; ?>

        <section class="d-flex justify-content-end">
            <button class="btn export-btn px-4 py-2" type="button" onclick="exportToImage()">匯出圖片</button>
        </section>
    </div>
</main>

<script src="https://cdn.jsdelivr.net/npm/bootstrap@5.3.3/dist/js/bootstrap.bundle.min.js"></script>
<script src="https://cdnjs.cloudflare.com/ajax/libs/html2canvas/1.4.1/html2canvas.min.js"></script>
<script>
// 【JS-1】匯出當月薪資明細為圖片
function exportToImage() {
    const captureTarget = document.getElementById('salaryDetailCapture');
    const exportWrapper = captureTarget.cloneNode(true);
    exportWrapper.style.padding = '24px';
    exportWrapper.style.background = '#ffffff';
    exportWrapper.style.width = captureTarget.offsetWidth + 'px';

    const title = document.createElement('h2');
    title.textContent = '<?= $companyName ?> <?= htmlspecialchars($year) ?>年<?= htmlspecialchars($month) ?>月 <?= htmlspecialchars($employeeName) ?> 薪資報表';
    title.style.textAlign = 'center';
    title.style.marginBottom = '24px';
    exportWrapper.prepend(title);

    const tempContainer = document.createElement('div');
    tempContainer.style.position = 'fixed';
    tempContainer.style.left = '-9999px';
    tempContainer.style.top = '0';
    tempContainer.style.width = captureTarget.offsetWidth + 'px';
    tempContainer.appendChild(exportWrapper);
    document.body.appendChild(tempContainer);

    html2canvas(exportWrapper, { scale: 2, useCORS: true }).then(canvas => {
        const link = document.createElement('a');
        link.href = canvas.toDataURL('image/png');
        link.download = `<?= $companyName ?>_<?= htmlspecialchars($employeeName) ?>_<?= htmlspecialchars($year) ?>年<?= htmlspecialchars($month) ?>月薪資明細.png`;
        link.click();
        document.body.removeChild(tempContainer);
    }).catch(error => {
        console.error('匯出失敗', error);
        alert('匯出圖片時發生問題，請稍後再試。');
        document.body.removeChild(tempContainer);
    });
}
</script>
</body>
</html>