<?php
// ✅ 第一點：登入驗證
session_start();
if (!isset($_SESSION['user']) ) {
    header("Location: ../login.php");
    exit;
}

// ✅ 第二點：資料庫連線
$conn = new mysqli('localhost', 'root', '', 'mymakeart');
if ($conn->connect_error) {
    die("連線錯誤：" . $conn->connect_error);
}

// ✅ 第三點：取得登入使用者資料（包含大頭照與工號）
$user_id = $_SESSION['user']['id'];
$user_stmt = $conn->prepare("SELECT employee_number, name, username, profile_picture FROM employees WHERE id = ?");
$user_stmt->bind_param("i", $user_id);
$user_stmt->execute();
$user = $user_stmt->get_result()->fetch_assoc();

// ✅ 第四點：初始化年月（本月與上月）
$currMonth = date('n');
$currYear = date('Y');
$prevMonth = date('n', strtotime('-1 month'));
$prevYear = date('Y', strtotime('-1 month'));

// ✅ 第五點：取得核准假別與國定假日資料
$employee_number = $_SESSION['user']['employee_number'];
$approvedLeaves = [];
$approvedOvertimes = [];
$holidays = [];

// ✅ 第六點：取得 holidays 資料表（含描述與是否補班）
$holidayResult = $conn->query("SELECT holiday_date, description, is_working_day FROM holidays WHERE holiday_date BETWEEN '$prevYear-$prevMonth-01' AND '$currYear-$currMonth-31'");
while ($row = $holidayResult->fetch_assoc()) {
    $holidays[$row['holiday_date']] = [
        'description' => $row['description'],
        'is_working_day' => $row['is_working_day']
    ];
}

// ✅ 第七點：撈核准請假資料
$approvedLeaves = [];
$stmt = $conn->prepare("SELECT DATE(start_date) AS day, subtype FROM requests WHERE employee_number = ? AND status = 'Approved' AND type = '請假'");
$stmt->bind_param("s", $employee_number);
$stmt->execute();
$result = $stmt->get_result();
while ($row = $result->fetch_assoc()) {
    $approvedLeaves[$row['day']] = $row['subtype']; // ✅ 改為鍵值對
}

// ✅ 第八點：撈核准加班資料
$approvedOvertimes = [];
$stmt2 = $conn->prepare("SELECT DATE(start_date) AS day FROM requests WHERE employee_number = ? AND status = 'Approved' AND type = '加班'");
$stmt2->bind_param("s", $employee_number);
$stmt2->execute();
$result2 = $stmt2->get_result();
while ($row = $result2->fetch_assoc()) {
    $approvedOvertimes[$row['day']] = '加班'; // ✅ 日期對應「加班」
}

// ✅ 第九點：引入行事曆產生函式
include 'generate_calendar.php';
?>

<!-- ✅ 第十點：畫面開始 -->
<!DOCTYPE html>
<html lang="zh-TW">
<head>
    <meta charset="UTF-8">
    <title>員工首頁</title>
    <link rel="stylesheet" href="https://cdn.jsdelivr.net/npm/bootstrap@5.3.3/dist/css/bootstrap.min.css">
    <link rel="stylesheet" href="employee_navbar.css">
    <link rel="stylesheet" href="employee_home.css">
</head>
<body class="bg-light">
<?php include 'employee_navbar.php'; ?>

<?php
// 放在 include navbar 後、輸出 HTML 前
$default_avatar = '../employee/uploads/profile_pictures/default.jpg';

// 若資料庫有路徑且檔案存在，就用使用者的大頭照；否則改用預設大頭照
$profile_path = (!empty($user['profile_picture']) && file_exists($user['profile_picture']))
    ? $user['profile_picture']
    : $default_avatar;
?>

<div class="container mt-4">
    <!-- ✅ 第十一點：員工基本資訊 -->
    <div class="d-flex align-items-center mb-3">
        <img src="<?= htmlspecialchars($profile_path) ?>" class="employee-photo me-3" alt="員工大頭照">
        <div>
            <h5>👤 歡迎，<?= htmlspecialchars($user['name']) ?></h5>
            <small>工號：<?= htmlspecialchars($user['employee_number']) ?>｜帳號：<?= htmlspecialchars($user['username']) ?></small>
        </div>
    </div>

    <!-- ✅ 第十二點：任務區塊 -->
    <div class="card mb-3 border-warning">
        <div class="card-header bg-warning"><i class="bi bi-list-task"></i> 我的任務清單（進行中）</div>
        <div class="card-body" id="taskList"><p>目前沒有尚未完成的任務。</p></div>
    </div>

    <!-- ✅ 第十三點：請假與特休紀錄 -->
    <div class="row equal-height-row">
        <div class="col-lg-8">
            <div class="card mb-3 border-primary">
                <div class="card-header bg-primary text-white">近 5 筆請假/加班申請紀錄</div>
                <div class="card-body p-0">
                    <div class="table-responsive">
                        <table class="table table-sm mb-0">
                            <thead><tr><th>類型</th><th>假別</th><th>理由</th><th>狀態</th><th>起始</th><th>結束</th></tr></thead>
                            <tbody id="leaveRequestsTableBody">
                                <tr><td colspan="6" class="text-center">載入中...</td></tr>
                            </tbody>
                        </table>
                    </div>
                    <div id="leaveRequestPagination" class="p-2 text-center"></div>
                </div>
            </div>
        </div>
        <div class="col-lg-4">
            <div class="card mb-3 border-success">
                <div class="card-header bg-success text-white">特休紀錄</div>
                <div class="card-body p-0">
                    <table class="table table-sm mb-0">
                        <thead><tr><th>年</th><th>月</th><th>日</th><th>取得/使用</th><th>天數</th><th>小時</th><th>備註</th></tr></thead>
                        <tbody id="annualLeaveTableBody">
                            <tr><td colspan="7" class="text-center">載入中...</td></tr>
                        </tbody>
                    </table>
                    <div id="pagination" class="p-2 text-center"></div>
                </div>
            </div>
        </div>
    </div>

    <!-- ✅ 第十四點：行事曆 -->
    <div class="card border-secondary">
        <div class="card-header bg-secondary text-white">行事曆（上月 & 本月）</div>
        <div class="card-body calendar-container">
            <?= generate_calendar($prevMonth, $prevYear, $holidays, $approvedLeaves, $approvedOvertimes) ?>
            <?= generate_calendar($currMonth, $currYear, $holidays, $approvedLeaves, $approvedOvertimes) ?>
        </div>
    </div>
</div>

<!-- ✅ 第十五點：載入 JS 動態資料 -->
<script>
function loadAnnualLeaveRecords(page = 1) {
    fetch(`get_annual_leave_records.php?page=${page}`)
        .then(res => res.json())
        .then(data => {
            const tbody = document.getElementById('annualLeaveTableBody');
            tbody.innerHTML = '';

            if (data.records.length === 0) {
                tbody.innerHTML = '<tr><td colspan="7" class="text-center">暫無紀錄</td></tr>';
            } else {
                data.records.forEach(r => {
                    tbody.innerHTML += `
                        <tr>
                            <td>${r.year}</td>
                            <td>${r.month}</td>
                            <td>${r.day}</td>
                            <td>${r.type}</td>
                            <td>${r.days ?? '-'}</td>
                            <td>${r.hours ?? '-'}</td>
                            <td>${r.note ?? '-'}</td>
                        </tr>`;
                });
            }

            const p = document.getElementById('pagination');
            p.innerHTML = '';
            if (data.totalPages > 1) {
                p.innerHTML += `<div class="mb-2 text-muted">第 ${data.currentPage} 頁 / 共 ${data.totalPages} 頁</div>`;
                if (data.currentPage > 1) {
                    p.innerHTML += `<button class="btn btn-sm btn-outline-secondary me-1" onclick="loadAnnualLeaveRecords(${data.currentPage - 1})">← 上一頁</button>`;
                }
                for (let i = 1; i <= data.totalPages; i++) {
                    const active = i === data.currentPage ? 'btn-primary' : 'btn-outline-primary';
                    p.innerHTML += `<button class="btn btn-sm ${active} me-1" onclick="loadAnnualLeaveRecords(${i})">${i}</button>`;
                }
                if (data.currentPage < data.totalPages) {
                    p.innerHTML += `<button class="btn btn-sm btn-outline-secondary" onclick="loadAnnualLeaveRecords(${data.currentPage + 1})">下一頁 →</button>`;
                }
            }
        });
}

function loadLeaveRequestPage(page = 1) {
    fetch(`get_leave_requests.php?page=${page}`)
        .then(res => res.json())
        .then(data => {
            const tbody = document.getElementById('leaveRequestsTableBody');
            tbody.innerHTML = '';

            if (data.records.length === 0) {
                tbody.innerHTML = '<tr><td colspan="6" class="text-center">暫無紀錄</td></tr>';
            } else {
                data.records.forEach(row => {
                    const statusText = row.status === 'Approved' ? '🟢 通過' :
                                       row.status === 'Pending'  ? '🟡 審核中' : '🔴 不通過';
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

            const p = document.getElementById('leaveRequestPagination');
            p.innerHTML = '';
            if (data.totalPages > 1) {
                p.innerHTML += `<div class="mb-2 text-muted">第 ${data.currentPage} 頁 / 共 ${data.totalPages} 頁</div>`;
                if (data.currentPage > 1) {
                    p.innerHTML += `<button class="btn btn-sm btn-outline-secondary me-1" onclick="loadLeaveRequestPage(${data.currentPage - 1})">← 上一頁</button>`;
                }
                for (let i = 1; i <= data.totalPages; i++) {
                    const active = i === data.currentPage ? 'btn-primary' : 'btn-outline-primary';
                    p.innerHTML += `<button class="btn btn-sm ${active} me-1" onclick="loadLeaveRequestPage(${i})">${i}</button>`;
                }
                if (data.currentPage < data.totalPages) {
                    p.innerHTML += `<button class="btn btn-sm btn-outline-secondary" onclick="loadLeaveRequestPage(${data.currentPage + 1})">下一頁 →</button>`;
                }
            }
        });
}

// 頁面初始化時執行
loadAnnualLeaveRecords();
loadLeaveRequestPage();
</script>

</body>
</html>
