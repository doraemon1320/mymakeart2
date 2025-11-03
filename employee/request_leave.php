<?php
// 【PHP-1】Session 驗證與登入限制
session_start();
if (!isset($_SESSION['user'])) {
    header('Location: ../login.php');
    exit;
}

mysqli_report(MYSQLI_REPORT_ERROR | MYSQLI_REPORT_STRICT);

// 【PHP-2】建立資料庫連線
try {
    $conn = new mysqli('localhost', 'root', '', 'mymakeart');
    $conn->set_charset('utf8mb4');
} catch (Exception $e) {
    die('資料庫連接失敗：' . $e->getMessage());
}

// 【PHP-3】取得員工資料與班別
$employee_number = $_SESSION['user']['employee_number'] ?? null;
if (!$employee_number) {
    die('未能獲取員工編號，請重新登入！');
}

$employee_stmt = $conn->prepare('SELECT id, name, shift_id FROM employees WHERE employee_number = ? LIMIT 1');
$employee_stmt->bind_param('s', $employee_number);
$employee_stmt->execute();
$employee = $employee_stmt->get_result()->fetch_assoc();

if (!$employee) {
    die('找不到員工資料，請聯繫人事。');
}

$employee_id = (int)$employee['id'];
$employee_name = $employee['name'] ?? ($_SESSION['user']['name'] ?? '');
$shift_id = (int)($employee['shift_id'] ?? 0);

$shift_data = [
    'start_time' => '09:00:00',
    'end_time' => '18:00:00',
    'break_start' => '12:00:00',
    'break_end' => '13:00:00',
];

if ($shift_id > 0) {
    $shift_stmt = $conn->prepare('SELECT start_time, end_time, break_start, break_end FROM shifts WHERE id = ? LIMIT 1');
    $shift_stmt->bind_param('i', $shift_id);
    $shift_stmt->execute();
    $shift_row = $shift_stmt->get_result()->fetch_assoc();
    if ($shift_row) {
        foreach (['start_time', 'end_time', 'break_start', 'break_end'] as $field) {
            if (!empty($shift_row[$field])) {
                $shift_data[$field] = $shift_row[$field];
            }
        }
    }
}

$full_day_hours = max(0, (strtotime($shift_data['end_time']) - strtotime($shift_data['start_time'])) / 3600);
if (!empty($shift_data['break_start']) && !empty($shift_data['break_end'])) {
    $full_day_hours -= max(0, (strtotime($shift_data['break_end']) - strtotime($shift_data['break_start'])) / 3600);
}
if ($full_day_hours <= 0) {
    $full_day_hours = 8;
}

// 【PHP-4】特休假統計函式
function calculateAnnualLeaveSummary(mysqli $conn, int $employee_id, float $full_day_hours): array
{
    if ($full_day_hours <= 0) {
        $full_day_hours = 8.0;
    }

    $summary = [
        'granted_hours' => 0.0,
        'granted_days_display' => 0,
        'granted_hours_display' => 0.0,
        'used_hours' => 0.0,
        'used_days_display' => 0,
        'used_hours_display' => 0.0,
        'remain_hours' => 0.0,
        'remain_days' => 0,
        'remain_hours_display' => 0.0,
        'has_quota' => false,
        'full_day_hours' => $full_day_hours,
    ];

    $stmt_grant = $conn->prepare('SELECT COALESCE(SUM(days),0) AS total_days, COALESCE(SUM(hours),0) AS total_hours FROM annual_leave_records WHERE employee_id = ? AND status = ?');
    $status_grant = '取得';
    $stmt_grant->bind_param('is', $employee_id, $status_grant);
    $stmt_grant->execute();
    $grant = $stmt_grant->get_result()->fetch_assoc();

    $stmt_used = $conn->prepare("SELECT COALESCE(SUM(days),0) AS total_days, COALESCE(SUM(hours),0) AS total_hours FROM annual_leave_records WHERE employee_id = ? AND status IN ('使用','轉現金')");
    $stmt_used->bind_param('i', $employee_id);
    $stmt_used->execute();
    $used = $stmt_used->get_result()->fetch_assoc();

    $granted_days_total = floatval($grant['total_days'] ?? 0);
    $granted_hours_total = floatval($grant['total_hours'] ?? 0);
    $granted_hours = $granted_days_total * $full_day_hours + $granted_hours_total;

    $used_days_total = floatval($used['total_days'] ?? 0);
    $used_hours_total = floatval($used['total_hours'] ?? 0);
    $used_hours = $used_days_total * $full_day_hours + $used_hours_total;

    $remain_hours = max(0, $granted_hours - $used_hours);

    $summary['granted_hours'] = round($granted_hours, 1);
    $summary['used_hours'] = round($used_hours, 1);
    $summary['remain_hours'] = round($remain_hours, 1);

    $summary['granted_days_display'] = (int)floor($granted_hours / $full_day_hours);
    $summary['granted_hours_display'] = round($granted_hours - $summary['granted_days_display'] * $full_day_hours, 1);

    $summary['used_days_display'] = (int)floor($used_hours / $full_day_hours);
    $summary['used_hours_display'] = round($used_hours - $summary['used_days_display'] * $full_day_hours, 1);

    $summary['remain_days'] = (int)floor($remain_hours / $full_day_hours);
    $summary['remain_hours_display'] = round($remain_hours - $summary['remain_days'] * $full_day_hours, 1);

    $summary['has_quota'] = $remain_hours > 0.01;

    return $summary;
}

$annual_leave_summary = calculateAnnualLeaveSummary($conn, $employee_id, $full_day_hours);

// 【PHP-5】撈取假別（排除特休假，由額度決定是否顯示）
$leave_types = [];
$leave_types_result = $conn->query('SELECT name FROM leave_types ORDER BY id ASC');
while ($row = $leave_types_result->fetch_assoc()) {
    if ($row['name'] !== '特休假') {
        $leave_types[] = $row['name'];
    }
}

$success = $error = '';

// 【PHP-6】處理表單送出
if ($_SERVER['REQUEST_METHOD'] === 'POST') {
    $type_value = $_POST['type'] ?? 'leave';
    $type = ($type_value === 'leave') ? '請假' : '加班';
    $subtype = trim($_POST['subtype'] ?? '');
    if ($type === '加班') {
        $subtype = '加班';
    }

    $reason = trim($_POST['reason'] ?? '');
    $start_datetime = $_POST['start_datetime'] ?? '';
    $end_datetime = $_POST['end_datetime'] ?? '';

    if ($type === '請假' && $subtype === '') {
        $error = '❌ 請選擇假別！';
    } elseif (!$reason || !$start_datetime || !$end_datetime) {
        $error = '❌ 請完整填寫申請資訊！';
    } elseif (strtotime($start_datetime) >= strtotime($end_datetime)) {
        $error = '❌ 起始時間不能晚於或等於結束時間！';
    } elseif ($type === '請假' && $subtype === '特休假' && !$annual_leave_summary['has_quota']) {
        $error = '❌ 特休假額度不足，請改選其他假別。';
    } else {
        try {
            $conn->begin_transaction();

            $request_stmt = $conn->prepare('INSERT INTO requests (employee_id, employee_number, name, type, subtype, reason, start_date, end_date, status) VALUES (?, ?, ?, ?, ?, ?, ?, ?, \'pending\')');
            $request_stmt->bind_param('isssssss', $employee_id, $employee_number, $employee_name, $type, $subtype, $reason, $start_datetime, $end_datetime);
            $request_stmt->execute();

            if ($type === '請假' && $subtype === '特休假') {
                $start_dt = new DateTime($start_datetime);
                $end_dt = new DateTime($end_datetime);

                $start_day = new DateTime($start_dt->format('Y-m-d'));
                $end_day = new DateTime($end_dt->format('Y-m-d'));
                $period_end = (clone $end_day)->modify('+1 day');
                $period = new DatePeriod($start_day, new DateInterval('P1D'), $period_end);

                $records = [];
                $records_hours_total = 0.0;

                foreach ($period as $day) {
                    $day_str = $day->format('Y-m-d');
                    $day_start = new DateTime($day_str . ' ' . $shift_data['start_time']);
                    $day_end = new DateTime($day_str . ' ' . $shift_data['end_time']);
                    if ($day_end <= $day_start) {
                        continue;
                    }

                    $actual_start = $start_dt > $day_start ? clone $start_dt : clone $day_start;
                    $actual_end = $end_dt < $day_end ? clone $end_dt : clone $day_end;
                    if ($actual_start >= $actual_end) {
                        continue;
                    }

                    $duration_hours = ($actual_end->getTimestamp() - $actual_start->getTimestamp()) / 3600;
                    $break_overlap = 0.0;

                    if (!empty($shift_data['break_start']) && !empty($shift_data['break_end'])) {
                        $break_start_dt = new DateTime($day_str . ' ' . $shift_data['break_start']);
                        $break_end_dt = new DateTime($day_str . ' ' . $shift_data['break_end']);
                        if ($actual_start < $break_end_dt && $actual_end > $break_start_dt) {
                            $rest_start = $actual_start > $break_start_dt ? clone $actual_start : clone $break_start_dt;
                            $rest_end = $actual_end < $break_end_dt ? clone $actual_end : clone $break_end_dt;
                            $break_overlap = max(0, ($rest_end->getTimestamp() - $rest_start->getTimestamp()) / 3600);
                        }
                    }

                    $effective_hours = max(0, $duration_hours - $break_overlap);
                    if ($effective_hours <= 0) {
                        continue;
                    }

                    if (abs($effective_hours - $annual_leave_summary['full_day_hours']) < 0.01) {
                        $records[] = [
                            'year' => (int)$day->format('Y'),
                            'month' => (int)$day->format('n'),
                            'day' => (int)$day->format('j'),
                            'days' => 1,
                            'hours' => null,
                        ];
                        $records_hours_total += $annual_leave_summary['full_day_hours'];
                    } else {
                        $hours_used = round($effective_hours, 2);
                        $records[] = [
                            'year' => (int)$day->format('Y'),
                            'month' => (int)$day->format('n'),
                            'day' => (int)$day->format('j'),
                            'days' => null,
                            'hours' => $hours_used,
                        ];
                        $records_hours_total += $hours_used;
                    }
                }

                if (empty($records)) {
                    throw new Exception('無法計算特休假時數，請確認班別與時間設定。');
                }

                if ($records_hours_total - $annual_leave_summary['remain_hours'] > 0.01) {
                    throw new Exception('特休假剩餘不足，請重新確認時間。');
                }

                $insert_day_stmt = $conn->prepare('INSERT INTO annual_leave_records (employee_id, year, month, day, days, status, created_at) VALUES (?, ?, ?, ?, ?, \'使用\', NOW())');
                $insert_hour_stmt = $conn->prepare('INSERT INTO annual_leave_records (employee_id, year, hours, status, created_at) VALUES (?, ?, ?, \'使用\', NOW())');

                foreach ($records as $record) {
                    if (!is_null($record['days'])) {
                        $insert_day_stmt->bind_param('iiiii', $employee_id, $record['year'], $record['month'], $record['day'], $record['days']);
                        $insert_day_stmt->execute();
                    } else {
                        $insert_hour_stmt->bind_param('iid', $employee_id, $record['year'], $record['hours']);
                        $insert_hour_stmt->execute();
                    }
                }
            }

            $conn->commit();
            $success = '✅ 申請已提交！';
            $annual_leave_summary = calculateAnnualLeaveSummary($conn, $employee_id, $full_day_hours);
        } catch (Exception $e) {
            $conn->rollback();
            $error = '❌ ' . $e->getMessage();
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
    <link rel="stylesheet" href="https://cdn.jsdelivr.net/npm/bootstrap@5.3.3/dist/css/bootstrap.min.css">
    <link rel="stylesheet" href="employee_navbar.css">
    <link rel="stylesheet" href="styles.css">
    <style>
        :root {
            --brand-gold: #ffcd00;
            --brand-rose: #e36386;
            --brand-blue: #345d9d;
            --brand-dark: #1e2a4a;
        }
        .brand-banner {
            background: linear-gradient(120deg, var(--brand-blue) 0%, var(--brand-rose) 55%, var(--brand-gold) 100%);
            box-shadow: 0 15px 25px rgba(30, 42, 74, 0.3);
        }
        .brand-banner h2 {
            letter-spacing: 0.08em;
        }
        .brand-panel {
            background: #ffffff;
            border-radius: 1rem;
            border: 1px solid rgba(30, 42, 74, 0.08);
            box-shadow: 0 10px 25px rgba(30, 42, 74, 0.1);
            overflow: hidden;
        }
        .brand-panel-header {
            background: rgba(52, 93, 157, 0.95);
            color: #fff;
            padding: 1rem 1.25rem;
            font-weight: 600;
        }
        .brand-panel-body {
            padding: 1.5rem;
            background: linear-gradient(180deg, rgba(255, 255, 255, 0.95) 0%, rgba(255, 255, 255, 0.8) 100%);
        }
        .table-primary {
            --bs-table-bg: rgba(52, 93, 157, 0.9);
            --bs-table-border-color: rgba(52, 93, 157, 0.9);
            color: #fff;
        }
        .remain-highlight {
            background: rgba(255, 205, 0, 0.18);
            color: #1e2a4a;
            font-weight: 600;
        }
        .step-badge {
            background: rgba(255, 205, 0, 0.2);
            color: var(--brand-dark);
            border-radius: 999px;
            padding: 0.35rem 0.9rem;
            font-weight: 600;
        }
        .reminder-card {
            border-left: 4px solid var(--brand-rose);
            background: rgba(227, 99, 134, 0.12);
            border-radius: 0.75rem;
            padding: 0.85rem 1rem;
        }
        .brand-badge {
            display: inline-flex;
            align-items: center;
            gap: 0.4rem;
            padding: 0.35rem 0.75rem;
            border-radius: 999px;
            background: rgba(255, 255, 255, 0.2);
            font-weight: 600;
        }
        .btn-brand {
            background: var(--brand-gold);
            border-color: var(--brand-gold);
            color: #1e2a4a;
            font-weight: 600;
            padding: 0.6rem 2.5rem;
        }
        .btn-brand:hover {
            background: #ffd533;
            border-color: #ffd533;
            color: #1e2a4a;
        }
        .form-section-title {
            color: var(--brand-blue);
            font-weight: 700;
            border-left: 4px solid var(--brand-rose);
            padding-left: 0.75rem;
            margin-bottom: 1rem;
            letter-spacing: 0.05em;
        }
        .annual-hint-box {
            display: none;
            background: rgba(255, 205, 0, 0.18);
            border-radius: 0.75rem;
            padding: 0.75rem 1rem;
            color: var(--brand-dark);
            border: 1px dashed rgba(52, 93, 157, 0.35);
            margin-top: 0.75rem;
        }
    </style>
</head>
<body class="bg-light">
<?php include 'employee_navbar.php'; ?>

<div class="container my-4">
    <div class="brand-banner rounded-4 px-4 py-4 mb-4 text-white">
        <div class="row align-items-center g-3">
            <div class="col-lg-8">
                <h2 class="fw-bold mb-2">請假與加班申請中心</h2>
                <p class="mb-0">請依流程完成資料填寫並送出，系統會同步通知主管與人事，確保假勤紀錄即時更新。</p>
            </div>
            <div class="col-lg-4 text-lg-end">
                <span class="brand-badge">班別標準工時：<?= number_format($annual_leave_summary['full_day_hours'], 1) ?> 小時</span>
            </div>
        </div>
    </div>

    <?php if (!empty($error)): ?>
        <div class="alert alert-danger shadow-sm"><?= htmlspecialchars($error) ?></div>
    <?php elseif (!empty($success)): ?>
        <div class="alert alert-success shadow-sm"><?= htmlspecialchars($success) ?></div>
    <?php endif; ?>

    <div class="row g-4 mb-4">
        <div class="col-lg-7">
            <div class="brand-panel h-100">
                <div class="brand-panel-header">請假流程說明</div>
                <div class="brand-panel-body">
                    <div class="row g-3 mb-3">
                        <div class="col-sm-4">
                            <div class="step-badge text-center">1. 填寫申請</div>
                        </div>
                        <div class="col-sm-4">
                            <div class="step-badge text-center">2. 主管審核</div>
                        </div>
                        <div class="col-sm-4">
                            <div class="step-badge text-center">3. 人事銷假</div>
                        </div>
                    </div>
                    <ol class="mb-4 text-secondary">
                        <li class="mb-2">請先確認特休剩餘額度，再選擇假別及起訖時間。</li>
                        <li class="mb-2">提交後系統會自動通知主管審核，請留意通知中心訊息。</li>
                        <li class="mb-2">如需修改或取消，請於審核前聯繫人事單位協助處理。</li>
                    </ol>
                    <div class="row g-3">
                        <div class="col-md-6">
                            <div class="reminder-card h-100">
                                <div class="fw-semibold mb-1">小時制特休</div>
                                <div class="small mb-0">系統依據班別工時計算可請時數，跨日或部分時段也會自動扣除休息時間。</div>
                            </div>
                        </div>
                        <div class="col-md-6">
                            <div class="reminder-card h-100" style="border-left-color: var(--brand-blue); background: rgba(52, 93, 157, 0.1);">
                                <div class="fw-semibold mb-1">申請注意</div>
                                <div class="small mb-0">加班申請僅需填寫起訖時間；請假若無特休額度，選單將自動隱藏「特休假」。</div>
                            </div>
                        </div>
                    </div>
                </div>
            </div>
        </div>
        <div class="col-lg-5">
            <div class="brand-panel h-100">
                <div class="brand-panel-header">特休假概況</div>
                <div class="brand-panel-body">
                    <table class="table table-bordered mb-3">
                        <thead class="table-primary text-center">
                            <tr>
                                <th>項目</th>
                                <th>天數</th>
                                <th>小時</th>
                            </tr>
                        </thead>
                        <tbody class="text-center align-middle">
                            <tr>
                                <td>已取得</td>
                                <td><?= $annual_leave_summary['granted_days_display'] ?></td>
                                <td><?= $annual_leave_summary['granted_hours_display'] ?></td>
                            </tr>
                            <tr>
                                <td>已使用</td>
                                <td><?= $annual_leave_summary['used_days_display'] ?></td>
                                <td><?= $annual_leave_summary['used_hours_display'] ?></td>
                            </tr>
                            <tr class="remain-highlight">
                                <td>剩餘可請</td>
                                <td><?= $annual_leave_summary['remain_days'] ?></td>
                                <td><?= $annual_leave_summary['remain_hours_display'] ?></td>
                            </tr>
                        </tbody>
                    </table>
                    <p class="mb-1 small text-muted">若當日僅請幾個小時，系統會自動保留剩餘時數供下次申請使用。</p>
                    <p class="mb-0 small text-muted">剩餘總時數：<span class="fw-semibold text-primary"><?= $annual_leave_summary['remain_hours'] ?></span> 小時</p>
                </div>
            </div>
        </div>
    </div>

    <form method="POST" action="" class="bg-white p-4 border rounded-4 shadow-sm">
        <h5 class="form-section-title">申請資料填寫</h5>
        <div class="row g-3 mb-3">
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
                    <?php if ($annual_leave_summary['has_quota']): ?>
                        <option value="特休假">特休假</option>
                    <?php endif; ?>
                    <?php foreach ($leave_types as $leave): ?>
                        <option value="<?= htmlspecialchars($leave) ?>"><?= htmlspecialchars($leave) ?></option>
                    <?php endforeach; ?>
                </select>
                <?php if ($annual_leave_summary['has_quota']): ?>
                    <div class="form-text text-success">特休剩餘：<?= $annual_leave_summary['remain_days'] ?> 天 <?= $annual_leave_summary['remain_hours_display'] ?> 小時</div>
                <?php else: ?>
                    <div class="form-text text-danger">目前特休額度已用完。</div>
                <?php endif; ?>
                <div id="annual-hint" class="annual-hint-box">
                    <div class="fw-semibold mb-1">特休扣除方式</div>
                    <div class="small mb-0">依班別標準 <?= number_format($annual_leave_summary['full_day_hours'], 1) ?> 小時計算，會自動扣除午休等休息時段。</div>
                </div>
            </div>
        </div>

        <div class="mb-3">
            <label for="reason" class="form-label">申請理由：</label>
            <textarea id="reason" name="reason" rows="3" class="form-control" required placeholder="請輸入申請原因，必要時可補充交接資訊"></textarea>
        </div>

        <div class="row g-3 mb-4">
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
            <button type="submit" class="btn btn-brand">提交申請</button>
        </div>
    </form>
</div>

<script src="https://code.jquery.com/jquery-3.7.1.min.js"></script>
<script>
    // 【JS-1】切換申請類型顯示假別
    const typeSelect = document.getElementById('type');
    const subtypeContainer = document.getElementById('subtype-container');

    function toggleSubtype() {
        if (typeSelect.value === 'overtime') {
            subtypeContainer.style.display = 'none';
        } else {
            subtypeContainer.style.display = 'block';
        }
    }

    typeSelect.addEventListener('change', toggleSubtype);
    toggleSubtype();

    // 【JS-2】提示特休假扣除額度
    $(document).on('change', '#subtype', function () {
        if ($(this).val() === '特休假') {
            $('#annual-hint').slideDown();
        } else {
            $('#annual-hint').slideUp();
        }
    });

    $('#subtype').trigger('change');
</script>
</body>
</html>