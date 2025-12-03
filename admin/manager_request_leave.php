<?php
require_once "../db_connect.php";
if (session_status() === PHP_SESSION_NONE) session_start();

// 【PHP-1】權限檢查：僅允許管理員進入
if (!isset($_SESSION['user']) || $_SESSION['user']['role'] !== 'admin') {
    header("Location: ../login.php");
    exit;
}

// 【PHP-2】年份參數：統一採用當年度資料
$selected_year = date("Y");

// 【PHP-3】取得假別設定
$leave_types = [];
$leave_type_limits = [];
$type_stmt = $conn->query("SELECT id, name, days_per_year FROM leave_types");
while ($row = $type_stmt->fetch_assoc()) {
    $leave_types[$row['id']] = [
        'name' => $row['name'],
        'days_per_year' => (int)$row['days_per_year']
    ];
    $leave_type_limits[$row['name']] = (int)$row['days_per_year'];
}

// 【PHP-4】取得有效員工清單
$employees_data = [];
$employee_stmt = $conn->query("SELECT id, name, employee_number FROM employees WHERE role = 'employee' AND (resignation_date IS NULL OR resignation_date = '')");
while ($emp = $employee_stmt->fetch_assoc()) {
    $employees_data[] = $emp;
}

// 【PHP-5】取得班別時間（含休息時間）
$shift_map = [];
$shift_detail_map = [];
$shift_stmt = $conn->query("SELECT e.id AS employee_id, e.employee_number, s.start_time, s.end_time, s.break_start, s.break_end FROM employees e JOIN shifts s ON e.shift_id = s.id");
while ($row = $shift_stmt->fetch_assoc()) {
    $start_time = substr($row['start_time'], 0, 5);
    $end_time = substr($row['end_time'], 0, 5);
    $break_start = $row['break_start'] ? substr($row['break_start'], 0, 5) : null;
    $break_end = $row['break_end'] ? substr($row['break_end'], 0, 5) : null;

    $shift_map[$row['employee_number']] = [
        'start_time' => $start_time,
        'end_time' => $end_time,
        'break_start' => $break_start,
        'break_end' => $break_end
    ];

    $shift_detail_map[$row['employee_id']] = [
        'start_time' => $start_time,
        'end_time' => $end_time,
        'break_start' => $break_start,
        'break_end' => $break_end
    ];
}

// 【PHP-6】時間運算輔助函式
function maxDateTime(DateTime $a, DateTime $b): DateTime
{
    return $a > $b ? clone $a : clone $b;
}

function minDateTime(DateTime $a, DateTime $b): DateTime
{
    return $a < $b ? clone $a : clone $b;
}

function clampLeaveRange(DateTime $start, DateTime $end, DateTime $rangeStart, DateTime $rangeEnd): ?array
{
    if ($end < $rangeStart || $start > $rangeEnd) {
        return null;
    }

    $clampedStart = $start < $rangeStart ? clone $rangeStart : clone $start;
    $clampedEnd = $end > $rangeEnd ? clone $rangeEnd : clone $end;

    if ($clampedStart > $clampedEnd) {
        return null;
    }

    return [$clampedStart, $clampedEnd];
}

// 【PHP-7】整理員工假別統計
$summary_by_employee = [];
$year_start = new DateTime("$selected_year-01-01 00:00:00");
$year_end = new DateTime("$selected_year-12-31 23:59:59");

foreach ($employees_data as $emp) {
    $employee_id = $emp['id'];
    $employee_number = $emp['employee_number'];
    $summary_by_employee[$employee_number] = [];

    $shift = $shift_detail_map[$employee_id] ?? [
        'start_time' => '09:00',
        'end_time' => '18:00',
        'break_start' => '12:00',
        'break_end' => '13:00'
    ];

    foreach ($leave_types as $type_data) {
        $leave_name = $type_data['name'];

        // 【PHP-7-1】特休假採用 annual_leave_records 統計（含取得時數）
        if ($leave_name === '特休假') {
            $usage_stmt = $conn->prepare("SELECT SUM(days) AS total_days, SUM(hours) AS total_hours FROM annual_leave_records WHERE employee_id = ? AND status IN ('使用','轉現金')");
            $usage_stmt->bind_param("i", $employee_id);
            $usage_stmt->execute();
            $usage = $usage_stmt->get_result()->fetch_assoc();

            $used_days_raw = intval($usage['total_days'] ?? 0);
            $used_hours_raw = floatval($usage['total_hours'] ?? 0);
            $used_total_hours = $used_days_raw * 8 + $used_hours_raw;
            $used_days = floor($used_total_hours / 8);
            $used_hours = round($used_total_hours - ($used_days * 8), 1);

            $grant_stmt = $conn->prepare("SELECT SUM(days) AS total_grant_days, SUM(hours) AS total_grant_hours FROM annual_leave_records WHERE employee_id = ? AND status = '取得'");
            $grant_stmt->bind_param("i", $employee_id);
            $grant_stmt->execute();
            $grant = $grant_stmt->get_result()->fetch_assoc();

            $grant_days_raw = floatval($grant['total_grant_days'] ?? 0);
            $grant_hours_raw = floatval($grant['total_grant_hours'] ?? 0);
            $grant_total_hours = $grant_days_raw * 8 + $grant_hours_raw;
            $limit_days_display = floor($grant_total_hours / 8);
            $limit_hours_display = round($grant_total_hours - ($limit_days_display * 8), 1);

            $remain_total_hours = max(0, $grant_total_hours - $used_total_hours);
            $remain_days = floor($remain_total_hours / 8);
            $remain_hours = round($remain_total_hours - ($remain_days * 8), 1);

            $summary_by_employee[$employee_number]['特休假'] = [
                'limit' => $limit_days_display + ($limit_hours_display > 0 ? $limit_hours_display / 8 : 0),
                'limit_days' => $limit_days_display,
                'limit_hours_display' => $limit_hours_display,
                'limit_hours_total' => round($grant_total_hours, 1),
                'used_days' => $used_days,
                'used_hours' => $used_hours,
                'remain_days' => $remain_days,
                'remain_hours' => $remain_hours,
            ];
            continue;
        }

        // 【PHP-7-2】一般假別統計：依核准的 requests
        $stmt = $conn->prepare("
            SELECT start_date, end_date FROM requests
            WHERE employee_id = ? AND subtype = ? AND status = 'Approved'
            AND (
                YEAR(start_date) = ? OR YEAR(end_date) = ?
                OR (start_date <= ? AND end_date >= ?)
            )
        ");
        $year_end_str = $year_end->format('Y-m-d H:i:s');
        $year_start_str = $year_start->format('Y-m-d H:i:s');
        $stmt->bind_param("isssss", $employee_id, $leave_name, $selected_year, $selected_year, $year_end_str, $year_start_str);
        $stmt->execute();
        $result = $stmt->get_result();

        $used_minutes = 0;
        while ($row = $result->fetch_assoc()) {
            $leave_start = new DateTime($row['start_date']);
            $leave_end = new DateTime($row['end_date']);
            $clamped = clampLeaveRange($leave_start, $leave_end, $year_start, $year_end);
            if (!$clamped) {
                continue;
            }
            [$effective_start, $effective_end] = $clamped;

            $period = new DatePeriod(
                new DateTime($effective_start->format('Y-m-d')),
                new DateInterval('P1D'),
                (clone $effective_end)->modify('+1 day')
            );

            foreach ($period as $date) {
                $day_str = $date->format('Y-m-d');
                $work_start = new DateTime("$day_str {$shift['start_time']}");
                $work_end = new DateTime("$day_str {$shift['end_time']}");

                $day_start = ($day_str === $effective_start->format('Y-m-d')) ? maxDateTime($work_start, $effective_start) : clone $work_start;
                $day_end = ($day_str === $effective_end->format('Y-m-d')) ? minDateTime($work_end, $effective_end) : clone $work_end;

                if ($day_start >= $day_end) {
                    continue;
                }

                $total_minutes = ($day_end->getTimestamp() - $day_start->getTimestamp()) / 60;

                if (!empty($shift['break_start']) && !empty($shift['break_end'])) {
                    $break_start_dt = new DateTime("$day_str {$shift['break_start']}");
                    $break_end_dt = new DateTime("$day_str {$shift['break_end']}");
                    if ($day_start < $break_end_dt && $day_end > $break_start_dt) {
                        $rest_start = maxDateTime($day_start, $break_start_dt);
                        $rest_end = minDateTime($day_end, $break_end_dt);
                        $rest_minutes = ($rest_end->getTimestamp() - $rest_start->getTimestamp()) / 60;
                        $total_minutes -= max($rest_minutes, 0);
                    }
                }

                $used_minutes += max($total_minutes, 0);
            }
        }

        $used_total_hours = round($used_minutes / 60, 2);
        $used_days = floor($used_total_hours / 8);
        $used_hours = round($used_total_hours - ($used_days * 8), 1);

        $max_hours = $type_data['days_per_year'] * 8;
        $remain_total_hours = max(0, $max_hours - $used_total_hours);
        $remain_days = floor($remain_total_hours / 8);
        $remain_hours = round($remain_total_hours - ($remain_days * 8), 1);

        $summary_by_employee[$employee_number][$leave_name] = [
            'limit' => $type_data['days_per_year'],
            'used_days' => $used_days,
            'used_hours' => $used_hours,
            'remain_days' => $remain_days,
            'remain_hours' => $remain_hours,
        ];
    }
}

// 【PHP-8】轉換成前端所需的 JSON
$shift_json = json_encode($shift_map, JSON_UNESCAPED_UNICODE);
$remain_json = json_encode($summary_by_employee, JSON_UNESCAPED_UNICODE);
$leave_json = json_encode(array_values(array_column($leave_types, 'name')), JSON_UNESCAPED_UNICODE);
$employee_json = json_encode($employees_data, JSON_UNESCAPED_UNICODE);
$leave_limit_json = json_encode($leave_type_limits, JSON_UNESCAPED_UNICODE);
?>
<!DOCTYPE html>
<html lang="zh-Hant">
<head>
    <meta charset="UTF-8">
    <title>主管代填請假</title>
    <meta name="viewport" content="width=device-width, initial-scale=1">
    <link href="https://cdn.jsdelivr.net/npm/bootstrap@5.3.3/dist/css/bootstrap.min.css" rel="stylesheet">
    <script src="https://cdn.jsdelivr.net/npm/bootstrap@5.3.3/dist/js/bootstrap.bundle.min.js"></script>
    <link rel="stylesheet" href="admin_navbar.css">
    <style>
        .page-header-band {
            background: linear-gradient(135deg, rgba(52, 93, 157, 0.92), rgba(227, 99, 134, 0.85));
            color: #fff;
            border-radius: 1rem;
            padding: 1.5rem;
        }
        .card-brand {
            border-top: 5px solid #ffcd00;
            box-shadow: 0 15px 35px rgba(52, 93, 157, 0.15);
        }
        .accordion-button:not(.collapsed) {
            background-color: rgba(255, 205, 0, 0.18);
            color: #345d9d;
            font-weight: 700;
        }
        select[readonly] {
            pointer-events: none;
            background-color: #f8f9fa;
            color: #6c757d;
        }
        .btn-brand-primary {
            background-color: #345d9d;
            color: #fff;
            border: none;
        }
        .btn-brand-primary:hover {
            background-color: #e36386;
            color: #fff;
        }
        .btn-brand-success {
            background-color: #2fb986;
            color: #fff;
            border: none;
        }
        .btn-brand-success:hover {
            background-color: #1f8a63;
            color: #fff;
        }
    </style>
</head>
<body>
<?php include "admin_navbar.php"; ?>

<div class="container py-4">
    <div class="row mb-4">
        <div class="col-12">
            <div class="page-header-band d-flex flex-column flex-lg-row justify-content-between align-items-lg-center gap-3">
                <div>
                    <h1 class="h3 mb-1">主管代填請假</h1>
                    <p class="mb-0">依據班別自動帶入可請假時段，維護申請正確性。</p>
                </div>
          
            </div>
        </div>
    </div>

    <div class="row g-4">
        <div class="col-12">
            <div class="card card-brand">
                <div class="card-header bg-transparent border-0">
                    <h2 class="h5 mb-0 text-primary">請假表單</h2>
                    <small class="text-muted">依員工班別限制可選時段，並即時提示各假別剩餘可用量。</small>
                </div>
                <div class="card-body">
                    <?php if (!empty($_SESSION['leave_submit_errors'])): ?>
                        <div class="alert alert-danger">
                            <h6 class="mb-2 fw-bold">⚠️ 請假資料送出失敗：</h6>
                            <ul class="mb-0 ps-3">
                                <?php foreach ($_SESSION['leave_submit_errors'] as $msg): ?>
                                    <li><?= htmlspecialchars($msg) ?></li>
                                <?php endforeach; ?>
                            </ul>
                        </div>
                        <?php unset($_SESSION['leave_submit_errors']); ?>
                    <?php endif; ?>

                    <?php if (!empty($_SESSION['leave_submit_success']) || !empty($_SESSION['leave_submit_skipped'])): ?>
                        <div class="alert alert-info">
                            ✅ 成功送出 <?= $_SESSION['leave_submit_success'] ?? 0 ?> 筆，略過 <?= $_SESSION['leave_submit_skipped'] ?? 0 ?> 筆。
                        </div>
                        <?php unset($_SESSION['leave_submit_success'], $_SESSION['leave_submit_skipped']); ?>
                    <?php endif; ?>

                    <form method="post" action="manager_request_leave_submit.php" onsubmit="return validateForm()" class="mt-3">
                        <div class="table-responsive mb-3">
                            <table class="table table-bordered text-center" id="leaveFormTable">
                                <thead class="table-primary">
                                    <tr>
                                        <th>員工</th>
                                        <th>假別</th>
                                        <th>起始日</th>
                                        <th>結束日</th>
                                        <th>整天</th>
                                        <th>起始時間</th>
                                        <th>結束時間</th>
                                        <th>原因</th>
                                    </tr>
                                </thead>
                                <tbody id="formContainer"></tbody>
                            </table>
                        </div>
                        <div class="d-flex flex-column flex-md-row justify-content-between gap-3">
                            <button type="button" class="btn btn-brand-primary" onclick="addFormRow()">➕ 新增一筆</button>
                            <button type="submit" class="btn btn-brand-success">送出請假單</button>
                        </div>
                    </form>
                </div>
            </div>
        </div>
    </div>
</div>

<div class="modal fade" id="messageModal" tabindex="-1" aria-labelledby="messageModalLabel" aria-hidden="true">
    <div class="modal-dialog modal-dialog-centered">
        <div class="modal-content">
            <div class="modal-header" style="background: linear-gradient(135deg, #345d9d, #e36386);">
                <h5 class="modal-title text-white" id="messageModalLabel">提醒視窗</h5>
                <button type="button" class="btn-close btn-close-white" data-bs-dismiss="modal" aria-label="關閉"></button>
            </div>
            <div class="modal-body" id="messageModalBody"></div>
            <div class="modal-footer">
                <button type="button" class="btn btn-brand-primary" data-bs-dismiss="modal">我了解了</button>
            </div>
        </div>
    </div>
</div>

<script>
const EMPLOYEES = <?= $employee_json ?>;
const LEAVETYPES = <?= $leave_json ?>;
const SHIFT_MAP = <?= $shift_json ?>;
const LEAVE_LIMIT = <?= $remain_json ?>;
const LEAVE_BASE_LIMIT = <?= $leave_limit_json ?>;
</script>
<script src="manager_request_leave.js"></script>
</body>
</html>