<?php
// 【PHP-1】載入共用連線與登入檢查
require_once '../db_connect.php';
if (!isset($_SESSION['user'])) {
    header('Location: ../login.php');
    exit;
}

// 【PHP-2】取得員工基本資料
$employeeId = (int)($_SESSION['user']['id'] ?? 0);
$employeeNumber = $_SESSION['user']['employee_number'] ?? '';
$userStmt = $conn->prepare('SELECT employee_number, name, username, department, profile_picture FROM employees WHERE id = ?');
$userStmt->bind_param('i', $employeeId);
$userStmt->execute();
$user = $userStmt->get_result()->fetch_assoc();
$userStmt->close();

$employeeName = $user['name'] ?? '同仁';
$employeeAccount = $user['username'] ?? '';
$employeeCode = $user['employee_number'] ?? $employeeNumber;
$employeeDept = $user['department'] ?? '未設定部門';

$defaultAvatar = '/mymakeart/employee/uploads/profile_pictures/default.jpg';
$profilePath = $defaultAvatar;
if (!empty($user['profile_picture'])) {
    if (preg_match('/^https?:\/\//', $user['profile_picture'])) {
        $profilePath = $user['profile_picture'];
    } else {
        $fileName = basename($user['profile_picture']);
        $localCandidate = __DIR__ . '/uploads/profile_pictures/' . $fileName;
        if (file_exists($localCandidate)) {
            $profilePath = '/mymakeart/employee/uploads/profile_pictures/' . $fileName;
        }
    }
}

// 【PHP-3】設定行事曆區間
$today = new DateTimeImmutable('today');
$currentMonth = (int)$today->format('n');
$currentYear = (int)$today->format('Y');
$previousMonth = (int)$today->modify('-1 month')->format('n');
$previousYear = (int)$today->modify('-1 month')->format('Y');
$calendarStart = $today->modify('-1 month')->format('Y-m-01');
$calendarEnd = $today->format('Y-m-t');

// 【PHP-4】撈取假日、請假與加班資料
$holidays = [];
$holidayStmt = $conn->prepare('SELECT holiday_date, description, is_working_day FROM holidays WHERE holiday_date BETWEEN ? AND ?');
$holidayStmt->bind_param('ss', $calendarStart, $calendarEnd);
$holidayStmt->execute();
$holidayResult = $holidayStmt->get_result();
while ($row = $holidayResult->fetch_assoc()) {
    $holidays[$row['holiday_date']] = [
        'description' => $row['description'],
        'is_working_day' => (bool)$row['is_working_day'],
    ];
}
$holidayStmt->close();

$approvedLeaves = [];
$leaveStmt = $conn->prepare("SELECT DATE(start_date) AS day, subtype FROM requests WHERE employee_number = ? AND status = 'Approved' AND type = '請假'");
$leaveStmt->bind_param('s', $employeeCode);
$leaveStmt->execute();
$leaveResult = $leaveStmt->get_result();
while ($row = $leaveResult->fetch_assoc()) {
    $approvedLeaves[$row['day']] = $row['subtype'];
}
$leaveStmt->close();

$approvedOvertimes = [];
$overtimeStmt = $conn->prepare("SELECT DATE(start_date) AS day FROM requests WHERE employee_number = ? AND status = 'Approved' AND type = '加班'");
$overtimeStmt->bind_param('s', $employeeCode);
$overtimeStmt->execute();
$overtimeResult = $overtimeStmt->get_result();
while ($row = $overtimeResult->fetch_assoc()) {
    $approvedOvertimes[$row['day']] = '加班';
}
$overtimeStmt->close();

// 【PHP-5】整理任務與申請統計
$summary = [
    'pending_tasks' => 0,
    'completed_tasks' => 0,
    'pending_requests' => 0,
    'approved_requests' => 0,
    'completion_rate' => 0,
];

$pendingCountStmt = $conn->prepare('SELECT COUNT(*) AS total FROM tasks t LEFT JOIN task_completions c ON c.task_id = t.id AND c.employee_id = ? WHERE t.assigned_to = ? AND c.task_id IS NULL');
$pendingCountStmt->bind_param('ii', $employeeId, $employeeId);
$pendingCountStmt->execute();
$summary['pending_tasks'] = (int)($pendingCountStmt->get_result()->fetch_assoc()['total'] ?? 0);
$pendingCountStmt->close();

$completedCountStmt = $conn->prepare('SELECT COUNT(*) AS total FROM task_completions WHERE employee_id = ?');
$completedCountStmt->bind_param('i', $employeeId);
$completedCountStmt->execute();
$summary['completed_tasks'] = (int)($completedCountStmt->get_result()->fetch_assoc()['total'] ?? 0);
$completedCountStmt->close();

$requestStatsStmt = $conn->prepare("SELECT 
    SUM(CASE WHEN status = 'Pending' THEN 1 ELSE 0 END) AS pending_total,
    SUM(CASE WHEN status = 'Approved' THEN 1 ELSE 0 END) AS approved_total
FROM requests WHERE employee_number = ?");
$requestStatsStmt->bind_param('s', $employeeCode);
$requestStatsStmt->execute();
$requestStats = $requestStatsStmt->get_result()->fetch_assoc();
$summary['pending_requests'] = (int)($requestStats['pending_total'] ?? 0);
$summary['approved_requests'] = (int)($requestStats['approved_total'] ?? 0);
$requestStatsStmt->close();

$totalTasks = $summary['pending_tasks'] + $summary['completed_tasks'];
if ($totalTasks > 0) {
    $summary['completion_rate'] = round(($summary['completed_tasks'] / $totalTasks) * 100);
}

// 【PHP-6】取得近期待辦任務
$pendingTasks = [];
$pendingTasksStmt = $conn->prepare('SELECT t.id, t.title, t.due_date, t.description, e.name AS assigner_name FROM tasks t JOIN employees e ON e.id = t.assigned_by LEFT JOIN task_completions c ON c.task_id = t.id AND c.employee_id = ? WHERE t.assigned_to = ? AND c.task_id IS NULL ORDER BY t.due_date ASC LIMIT 5');
$pendingTasksStmt->bind_param('ii', $employeeId, $employeeId);
$pendingTasksStmt->execute();
$pendingResult = $pendingTasksStmt->get_result();
while ($row = $pendingResult->fetch_assoc()) {
    $pendingTasks[] = $row;
}
$pendingTasksStmt->close();

// 【PHP-7】整理 30 天內即將到來的假日資訊
$upcomingHolidays = [];
$limitDate = $today->modify('+30 days');
foreach ($holidays as $date => $info) {
    $holidayDate = new DateTimeImmutable($date);
    if ($holidayDate >= $today && $holidayDate <= $limitDate) {
        $upcomingHolidays[] = [
            'date' => $holidayDate->format('Y-m-d'),
            'description' => $info['description'],
            'is_working_day' => $info['is_working_day'],
        ];
    }
}
usort($upcomingHolidays, static function (array $a, array $b) {
    return strcmp($a['date'], $b['date']);
});

// 【PHP-8】引入行事曆產生函式
include 'generate_calendar.php';
?>
<!DOCTYPE html>
<html lang="zh-Hant">
<head>
    <meta charset="UTF-8">
    <title>員工首頁</title>
    <meta name="viewport" content="width=device-width, initial-scale=1">
    <link rel="stylesheet" href="https://cdn.jsdelivr.net/npm/bootstrap@5.3.3/dist/css/bootstrap.min.css">
    <link rel="stylesheet" href="https://cdn.jsdelivr.net/npm/bootstrap-icons@1.11.3/font/bootstrap-icons.min.css">
    <link rel="stylesheet" href="employee_navbar.css">
    <link rel="stylesheet" href="employee_home.css">
</head>
<body class="bg-soft">
<?php include 'employee_navbar.php'; ?>

<div class="container py-4">
    <div class="card hero-card mb-4">
        <div class="card-body d-flex flex-column flex-md-row align-items-md-center justify-content-between gap-4">
            <div class="d-flex align-items-center gap-3">
                <img src="<?= htmlspecialchars($profilePath) ?>" class="employee-photo" alt="員工大頭照">
                <div>
                    <p class="welcome-subtitle mb-1">您好，<?= htmlspecialchars($employeeDept) ?>｜工號 <?= htmlspecialchars($employeeCode) ?></p>
                    <h1 class="welcome-title h3 mb-0">歡迎回來，<?= htmlspecialchars($employeeName) ?>！</h1>
                    <p class="welcome-meta mb-0">帳號：<?= htmlspecialchars($employeeAccount) ?>｜今日 <?= $today->format('Y/m/d') ?></p>
                </div>
            </div>
            <div class="text-md-end">
                <p class="completion-label mb-2">任務完成率</p>
                <div class="progress rounded-pill shadow-sm" style="height: 12px;">
                    <div class="progress-bar bg-brand-ocean" role="progressbar" style="width: <?= $summary['completion_rate'] ?>%;" aria-valuenow="<?= $summary['completion_rate'] ?>" aria-valuemin="0" aria-valuemax="100"></div>
                </div>
                <p class="completion-value mt-2 mb-0"><strong><?= $summary['completion_rate'] ?>%</strong>（完成 <?= $summary['completed_tasks'] ?> / 共 <?= $totalTasks ?> 筆）</p>
            </div>
        </div>
    </div>

    <div class="row g-3 mb-4">
        <div class="col-md-3 col-sm-6">
            <div class="metric-card sun h-100">
                <div class="metric-icon"><i class="bi bi-list-task"></i></div>
                <div>
                    <div class="metric-label">待完成任務</div>
                    <div class="metric-value"><?= $summary['pending_tasks'] ?></div>
                </div>
            </div>
        </div>
        <div class="col-md-3 col-sm-6">
            <div class="metric-card ocean h-100">
                <div class="metric-icon"><i class="bi bi-check-circle-fill"></i></div>
                <div>
                    <div class="metric-label">已完成任務</div>
                    <div class="metric-value"><?= $summary['completed_tasks'] ?></div>
                </div>
            </div>
        </div>
        <div class="col-md-3 col-sm-6">
            <div class="metric-card rose h-100">
                <div class="metric-icon"><i class="bi bi-envelope-open"></i></div>
                <div>
                    <div class="metric-label">審核中申請</div>
                    <div class="metric-value"><?= $summary['pending_requests'] ?></div>
                </div>
            </div>
        </div>
        <div class="col-md-3 col-sm-6">
            <div class="metric-card ocean-light h-100">
                <div class="metric-icon"><i class="bi bi-calendar2-check"></i></div>
                <div>
                    <div class="metric-label">核准紀錄總數</div>
                    <div class="metric-value"><?= $summary['approved_requests'] ?></div>
                </div>
            </div>
        </div>
    </div>

    <div class="row g-4 mb-4">
        <div class="col-xl-7">
            <div class="card h-100">
                <div class="card-header ocean text-white"><i class="bi bi-flag-fill me-2"></i>近期待辦任務</div>
                <div class="card-body p-0">
                    <div class="table-responsive task-table">
                        <table class="table table-bordered table-hover align-middle mb-0" id="taskTable">
                            <thead class="table-primary">
                                <tr>
                                    <th style="width: 40%;">任務內容</th>
                                    <th style="width: 20%;">指派人</th>
                                    <th style="width: 20%;">截止日</th>
                                    <th style="width: 20%;">備註</th>
                                </tr>
                            </thead>
                            <tbody>
                            <?php if (count($pendingTasks) === 0): ?>
                                <tr><td colspan="4" class="text-center text-muted py-4">目前沒有待處理的任務。</td></tr>
                            <?php else: ?>
                                <?php foreach ($pendingTasks as $task): ?>
                                    <?php
                                        $dueDateText = '未設定';
                                        $dueBadge = '';
                                        $dueDateAttr = '';
                                        if (!empty($task['due_date'])) {
                                            $dueDate = new DateTimeImmutable($task['due_date']);
                                            $dueDateText = $dueDate->format('Y/m/d');
                                            $dueDateAttr = $dueDate->format('Y-m-d');
                                            if ($dueDate < $today) {
                                                $dueBadge = '<span class="badge rounded-pill text-bg-danger ms-2">已逾期</span>';
                                            } elseif ($dueDate <= $today->modify('+3 days')) {
                                                $dueBadge = '<span class="badge rounded-pill text-bg-warning text-dark ms-2">即將到期</span>';
                                            }
                                        }
                                    ?>
                                    <tr data-due-date="<?= htmlspecialchars($dueDateAttr) ?>">
                                        <td class="task-title">
                                            <span><?= htmlspecialchars($task['title']) ?></span>
                                            <?php if (!empty($task['description'])): ?>
                                                <div class="text-muted small mt-1"><?= nl2br(htmlspecialchars($task['description'])) ?></div>
                                            <?php endif; ?>
                                        </td>
                                        <td><?= htmlspecialchars($task['assigner_name']) ?></td>
                                        <td><?= $dueDateText ?><?= $dueBadge ?></td>
                                        <td>
                                            <a href="tasks_list.php" class="btn btn-sm btn-outline-primary">前往詳情</a>
                                        </td>
                                    </tr>
                                <?php endforeach; ?>
                            <?php endif; ?>
                            </tbody>
                        </table>
                    </div>
                </div>
            </div>
        </div>
        <div class="col-xl-5">
            <div class="card mb-4">
                <div class="card-header sun text-brand-ink"><i class="bi bi-calendar-event-fill me-2"></i>30 天內假日提醒</div>
                <div class="card-body">
                    <?php if (count($upcomingHolidays) === 0): ?>
                        <p class="text-muted mb-0">近期無需特別留意的假期。</p>
                    <?php else: ?>
                        <?php foreach ($upcomingHolidays as $holiday): ?>
                            <div class="holiday-chip">
                                <div>
                                    <div class="date"><?= htmlspecialchars(date('Y/m/d', strtotime($holiday['date']))) ?></div>
                                    <div class="note"><?= htmlspecialchars($holiday['description']) ?></div>
                                </div>
                                <span class="tag <?= $holiday['is_working_day'] ? 'work' : 'rest' ?>">
                                    <?= $holiday['is_working_day'] ? '補班' : '放假' ?>
                                </span>
                            </div>
                        <?php endforeach; ?>
                    <?php endif; ?>
                </div>
            </div>
            <div class="card quick-links">
                <div class="card-header ocean text-white"><i class="bi bi-lightning-charge-fill me-2"></i>快速操作</div>
                <div class="card-body d-grid gap-2">
                    <a href="request_leave.php" class="btn btn-warning w-100 text-start"><i class="bi bi-pencil-square me-2"></i>申請請假／加班</a>
                    <a href="history_requests.php" class="btn btn-outline-primary w-100 text-start"><i class="bi bi-journal-text me-2"></i>檢視申請紀錄</a>
                    <a href="tasks_list.php" class="btn btn-outline-secondary w-100 text-start"><i class="bi bi-card-checklist me-2"></i>管理我的任務</a>
                    <a href="notifications.php" class="btn btn-outline-rose w-100 text-start"><i class="bi bi-bell-fill me-2"></i>查看最新通知</a>
                </div>
            </div>
        </div>
    </div>

    <div class="card mb-4">
        <div class="card-header ocean text-white"><i class="bi bi-journal-arrow-down me-2"></i>近 5 筆請假／加班申請紀錄</div>
        <div class="card-body p-0">
            <div class="table-responsive">
                <table class="table table-bordered table-hover align-middle mb-0">
                    <thead class="table-primary">
                        <tr>
                            <th>申請類型</th>
                            <th>細項</th>
                            <th>事由</th>
                            <th>審核狀態</th>
                            <th>起始時間</th>
                            <th>結束時間</th>
                        </tr>
                    </thead>
                    <tbody id="leaveRequestsTableBody">
                        <tr><td colspan="6" class="text-center text-muted py-4">資料載入中...</td></tr>
                    </tbody>
                </table>
            </div>
            <div id="leaveRequestPagination" class="p-3 text-center"></div>
        </div>
    </div>

    <div class="card mb-4">
        <div class="card-header rose text-white"><i class="bi bi-hourglass-split me-2"></i>特休／補休使用紀錄</div>
        <div class="card-body p-0">
            <div class="table-responsive">
                <table class="table table-bordered table-hover align-middle mb-0">
                    <thead class="table-primary">
                        <tr>
                            <th>年度</th>
                            <th>月份</th>
                            <th>日期</th>
                            <th>類型</th>
                            <th>天數</th>
                            <th>時數</th>
                            <th>備註</th>
                        </tr>
                    </thead>
                    <tbody id="annualLeaveTableBody">
                        <tr><td colspan="7" class="text-center text-muted py-4">資料載入中...</td></tr>
                    </tbody>
                </table>
            </div>
            <div id="pagination" class="p-3 text-center"></div>
        </div>
    </div>

    <div class="card calendar-card">
        <div class="card-header ocean text-white"><i class="bi bi-calendar3 me-2"></i>行事曆（上月與本月）</div>
        <div class="card-body calendar-container">
            <?= generate_calendar($previousMonth, $previousYear, $holidays, $approvedLeaves, $approvedOvertimes) ?>
            <?= generate_calendar($currentMonth, $currentYear, $holidays, $approvedLeaves, $approvedOvertimes) ?>
        </div>
    </div>
</div>

<script src="https://code.jquery.com/jquery-3.7.1.min.js"></script>
<script src="https://cdn.jsdelivr.net/npm/bootstrap@5.3.3/dist/js/bootstrap.bundle.min.js"></script>
<script>
// 【JS-1】載入特休與補休紀錄
function loadAnnualLeaveRecords(page = 1) {
    fetch(`get_annual_leave_records.php?page=${page}`)
        .then(response => response.json())
        .then(data => {
            const tbody = document.getElementById('annualLeaveTableBody');
            tbody.innerHTML = '';

            if (!data.records || data.records.length === 0) {
                tbody.innerHTML = '<tr><td colspan="7" class="text-center text-muted py-4">目前尚無特休紀錄。</td></tr>';
            } else {
                data.records.forEach(record => {
                    tbody.innerHTML += `
                        <tr>
                            <td>${record.year}</td>
                            <td>${record.month}</td>
                            <td>${record.day}</td>
                            <td>${record.type}</td>
                            <td>${record.days ?? '-'}</td>
                            <td>${record.hours ?? '-'}</td>
                            <td>${record.note ?? '-'}</td>
                        </tr>`;
                });
            }

            const pagination = document.getElementById('pagination');
            pagination.innerHTML = '';
            if (data.totalPages > 1) {
                pagination.innerHTML += `<div class="mb-2 text-muted">第 ${data.currentPage} 頁，共 ${data.totalPages} 頁</div>`;
                if (data.currentPage > 1) {
                    pagination.innerHTML += `<button class="btn btn-sm btn-outline-secondary me-1" onclick="loadAnnualLeaveRecords(${data.currentPage - 1})">← 上一頁</button>`;
                }
                for (let i = 1; i <= data.totalPages; i += 1) {
                    const active = i === data.currentPage ? 'btn-primary' : 'btn-outline-primary';
                    pagination.innerHTML += `<button class="btn btn-sm ${active} me-1" onclick="loadAnnualLeaveRecords(${i})">${i}</button>`;
                }
                if (data.currentPage < data.totalPages) {
                    pagination.innerHTML += `<button class="btn btn-sm btn-outline-secondary" onclick="loadAnnualLeaveRecords(${data.currentPage + 1})">下一頁 →</button>`;
                }
            }
        });
}

// 【JS-2】載入近期請假與加班紀錄
function loadLeaveRequestPage(page = 1) {
    fetch(`get_leave_requests.php?page=${page}`)
        .then(response => response.json())
        .then(data => {
            const tbody = document.getElementById('leaveRequestsTableBody');
            tbody.innerHTML = '';

            if (!data.records || data.records.length === 0) {
                tbody.innerHTML = '<tr><td colspan="6" class="text-center text-muted py-4">目前尚無申請紀錄。</td></tr>';
            } else {
                data.records.forEach(row => {
                    let statusText = '🔴 不通過';
                    if (row.status === 'Approved') {
                        statusText = '🟢 已核准';
                    } else if (row.status === 'Pending') {
                        statusText = '🟡 審核中';
                    }
                    tbody.innerHTML += `
                        <tr>
                            <td>${row.type}</td>
                            <td>${row.subtype}</td>
                            <td>${row.reason}</td>
                            <td>${statusText}</td>
                            <td>${row.start_date}</td>
                            <td>${row.end_date}</td>
                        </tr>`;
                });
            }

            const pagination = document.getElementById('leaveRequestPagination');
            pagination.innerHTML = '';
            if (data.totalPages > 1) {
                pagination.innerHTML += `<div class="mb-2 text-muted">第 ${data.currentPage} 頁，共 ${data.totalPages} 頁</div>`;
                if (data.currentPage > 1) {
                    pagination.innerHTML += `<button class="btn btn-sm btn-outline-secondary me-1" onclick="loadLeaveRequestPage(${data.currentPage - 1})">← 上一頁</button>`;
                }
                for (let i = 1; i <= data.totalPages; i += 1) {
                    const active = i === data.currentPage ? 'btn-primary' : 'btn-outline-primary';
                    pagination.innerHTML += `<button class="btn btn-sm ${active} me-1" onclick="loadLeaveRequestPage(${i})">${i}</button>`;
                }
                if (data.currentPage < data.totalPages) {
                    pagination.innerHTML += `<button class="btn btn-sm btn-outline-secondary" onclick="loadLeaveRequestPage(${data.currentPage + 1})">下一頁 →</button>`;
                }
            }
        });
}

// 【JS-3】為即將到期或逾期的任務加上樣式提示
function markTaskDeadlineStatus() {
    const today = new Date();
    const soon = new Date();
    soon.setDate(today.getDate() + 3);

    $('#taskTable tbody tr').each(function () {
        const dueDateText = $(this).data('due-date');
        if (!dueDateText) {
            return;
        }
        const dueDate = new Date(dueDateText);
        if (Number.isNaN(dueDate.getTime())) {
            return;
        }
        if (dueDate < today) {
            $(this).addClass('task-overdue');
        } else if (dueDate <= soon) {
            $(this).addClass('task-soon');
        }
    });
}

// 【JS-4】頁面初始化
$(function () {
    loadAnnualLeaveRecords();
    loadLeaveRequestPage();
    markTaskDeadlineStatus();
});
</script>
</body>
</html>