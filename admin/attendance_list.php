<?php
session_start();
if (!isset($_SESSION['user']) || $_SESSION['user']['role'] !== 'admin') {
    header('Location: ../login.php');
    exit;
}
$conn = new mysqli('localhost', 'root', '', 'mymakeart');
if ($conn->connect_error) die("資料庫連接失敗：" . $conn->connect_error);

// 初始化查詢條件
$employee_number = $_GET['employee_number'] ?? '';
$year = $_GET['year'] ?? date('Y', strtotime('first day of last month'));
$month = $_GET['month'] ?? date('m', strtotime('first day of last month'));
$start_date = "$year-$month-01";
$end_date = date('Y-m-t', strtotime($start_date));  // 結尾：當月最後一天
$attendance_data = [];

// 員工班別資料
$shift_query = $conn->prepare("
    SELECT shifts.name AS shift_name, shifts.start_time, shifts.end_time, shifts.break_start, shifts.break_end
    FROM employees
    JOIN shifts ON employees.shift_id = shifts.id
    WHERE employees.employee_number = ?
");
$shift_query->bind_param('s', $employee_number);
$shift_query->execute();
$shift_info = $shift_query->get_result()->fetch_assoc();
$shift_name = $shift_info['shift_name'] ?? '無班別資訊';
$shift_start_time = $shift_info['start_time'] ?? '09:00:00';
$shift_end_time = $shift_info['end_time'] ?? '18:00:00';
$break_start_time = $shift_info['break_start'] ?? '12:00:00';
$break_end_time = $shift_info['break_end'] ?? '13:00:00';

// 所有員工清單
$employee_list = [];
$employee_result = $conn->query("SELECT * FROM employees ORDER BY employee_number");
if ($employee_result) {
    $employee_list = $employee_result->fetch_all(MYSQLI_ASSOC);
    if (empty($employee_number) && !empty($employee_list)) {
        $employee_number = $employee_list[0]['employee_number'];
    }
}

// 行事曆假日
$holiday_map = [];
$holiday_result = $conn->query("SELECT * FROM holidays");
foreach ($holiday_result->fetch_all(MYSQLI_ASSOC) as $row) {
    $holiday_map[$row['holiday_date']] = [
        'description' => $row['description'],
        'is_working_day' => $row['is_working_day']
    ];
}

// 請假資料
$request_result = $conn->prepare("
    SELECT * FROM requests
    WHERE employee_number = ? AND status = 'Approved' AND start_date <= ? AND end_date >= ?
");
$request_result->bind_param('sss', $employee_number, $end_date, $start_date);
$request_result->execute();
$approved_requests = $request_result->get_result()->fetch_all(MYSQLI_ASSOC);

// 打卡紀錄
$attendance_logs = [];
$log_result = $conn->prepare("
    SELECT log_date, MIN(log_time) AS first_time, MAX(log_time) AS last_time
    FROM attendance_logs
    WHERE employee_number = ? AND log_date BETWEEN ? AND ?
    GROUP BY log_date
");
$log_result->bind_param('sss', $employee_number, $start_date, $end_date);
$log_result->execute();
foreach ($log_result->get_result()->fetch_all(MYSQLI_ASSOC) as $row) {
    $attendance_logs[$row['log_date']] = [
        'first_time' => $row['first_time'],
        'last_time' => $row['last_time']
    ];
}

// 已儲存的出勤資料
$saved_attendance = [];
$saved_result = $conn->prepare("
    SELECT date, first_time, last_time, status_text, absent_minutes
    FROM saved_attendance
    WHERE employee_number = ? AND date BETWEEN ? AND ?
");
$saved_result->bind_param('sss', $employee_number, $start_date, $end_date);
$saved_result->execute();
foreach ($saved_result->get_result()->fetch_all(MYSQLI_ASSOC) as $row) {
    $saved_attendance[$row['date']] = $row;
}

// 請假假別對照表
$leave_map = [];
$leave_result = $conn->query("SELECT id, name FROM leave_types");
foreach ($leave_result->fetch_all(MYSQLI_ASSOC) as $row) {
    $leave_map[$row['id']] = $row['name'];
}

// 日期列表
$dates_in_month = [];
$current = new DateTime($start_date);
$last_day = new DateTime($end_date);
while ($current <= $last_day) {
    $dates_in_month[] = $current->format('Y-m-d');
    $current->modify('+1 day');
}

// ✅ 第 1 點：建立本月每日的考勤資料
foreach ($dates_in_month as $day) {
    $first_time = $attendance_logs[$day]['first_time'] ?? '-';
    $last_time = $attendance_logs[$day]['last_time'] ?? '-';
    $default_status = [];
    $status_class = 'normal';
    $absent_minutes = 0;

    // ✅ 第 2 點：如果已有儲存過的出勤紀錄，直接使用（覆蓋打卡資料）
    if (isset($saved_attendance[$day])) {
        $first_time = $saved_attendance[$day]['first_time'];
        $last_time = $saved_attendance[$day]['last_time'];
        $default_status = explode(',', $saved_attendance[$day]['status_text']);
        $absent_minutes = $saved_attendance[$day]['absent_minutes'];

    } else {
        // ✅ 第 3 點：檢查當天是否有核准的請假或加班
        $has_approved = false;
        foreach ($approved_requests as $req) {
            $req_start = date('Y-m-d', strtotime($req['start_date']));
            $req_end = date('Y-m-d', strtotime($req['end_date']));
            if ($day >= $req_start && $day <= $req_end) {
                $has_approved = true;
                $status = ($req['type'] === '請假') ? ($leave_map[$req['subtype']] ?? $req['subtype']) : '加班申請';
                $first_time = $last_time = $status;
                $default_status[] = $status;
                $status_class = ($req['type'] === '請假') ? 'leave-approved' : 'overtime';
                break;
            }
        }

        // ✅ 第 4 點：若無請假資料，進一步判斷是否是假日
        if (!$has_approved) {
            $day_of_week = date('N', strtotime($day));
            $is_weekend = ($day_of_week >= 6);
            $is_holiday = isset($holiday_map[$day]) && !$holiday_map[$day]['is_working_day'];
            $is_working_day = isset($holiday_map[$day]) && $holiday_map[$day]['is_working_day'];

            // ✅ 第 5 點：週末或國定假日（非補班日），直接顯示為假日
            if ($is_weekend && !$is_working_day) {
                $first_time = $last_time = ($day_of_week == 6 ? '禮拜六' : '禮拜日');
                $default_status[] = '國定假日';
                $status_class = 'holiday';

            } elseif ($is_holiday) {
                $first_time = $last_time = $holiday_map[$day]['description'];
                $default_status[] = '國定假日';
                $status_class = 'holiday';

            // ✅ 第 6 點：若無打卡紀錄 ➜ 曠職
            } elseif ($first_time === '-' && $last_time === '-') {
                $default_status[] = '曠職';
                $status_class = 'absent';

                $work_seconds = strtotime("$day $shift_end_time") - strtotime("$day $shift_start_time");
                $rest_seconds = strtotime("$day $break_end_time") - strtotime("$day $break_start_time");
                $absent_minutes = floor(($work_seconds - $rest_seconds) / 60);

            // ✅ 第 7 點：有打卡 ➜ 判斷是否遲到 / 早退
            } else {
                if (strtotime($first_time) > strtotime($shift_start_time)) {
                    $default_status[] = '遲到';
                    $status_class = 'red';
                }
                if (strtotime($last_time) < strtotime($shift_end_time)) {
                    $default_status[] = '早退';
                    $status_class = 'red';
                }
            }
        }
    }

    // ✅ 第 8 點：若尚未指定任何狀態 ➜ 視為正常出勤
    if (empty($default_status)) {
        $default_status[] = '正常出勤';
        $status_class = 'normal';
    }

    // ✅ 第 9 點：儲存每日考勤資料
    $attendance_data[] = [
        'date' => $day,
        'first_time' => $first_time,
        'last_time' => $last_time,
        'status_class' => $status_class,
        'default_status' => $default_status,
        'absent_minutes' => $absent_minutes
    ];
}

// ✅ 第 10 點：若全月缺勤總分鐘數 < 30 ➜ 全部歸零（不扣薪）
if (array_sum(array_column($attendance_data, 'absent_minutes')) < 30) {
    foreach ($attendance_data as &$row) {
        $row['absent_minutes'] = 0;
    }
}

?>






<html lang="zh-TW">
<head>
    <meta charset="UTF-8">
    <meta name="viewport" content="width=device-width, initial-scale=1.0">
    <title>考勤紀錄表</title>
	    <link href="https://cdn.jsdelivr.net/npm/bootstrap@5.3.3/dist/css/bootstrap.min.css" rel="stylesheet">
    <script src="https://cdn.jsdelivr.net/npm/bootstrap@5.3.3/dist/js/bootstrap.bundle.min.js"></script>
    <link rel="stylesheet" href="styles.css">
    <link rel="stylesheet" href="attendance_list.css">
    <link rel="stylesheet" href="admin_navbar.css">
	
</head>
	
<body>
        <!-- 導航列 -->
        <?php include 'admin_navbar.php'; ?>	
    <div class="container">

       <!-- 顯示查詢表單 -->
<div class="container mt-4">
    <h2>考勤紀錄表</h2>
    <form method="GET" class="row g-3 align-items-center mb-4">
        <div class="col-md-4">
            <label class="form-label">選擇員工</label>
            <select id="employee_number" name="employee_number" class="form-select" required>

                <option value="">請選擇</option>
                <?php foreach ($employee_list as $employee): ?>
                    <option value="<?= $employee['employee_number'] ?>" <?= $employee_number === $employee['employee_number'] ? 'selected' : '' ?>>
                        <?= $employee['employee_number'] . ' - ' . $employee['name'] ?>
                    </option>
                <?php endforeach; ?>
            </select>
        </div>
        <div class="col-md-2">
            <label class="form-label">年份</label>
            <input type="number" name="year" class="form-control" value="<?= htmlspecialchars($year) ?>" required>
        </div>
        <div class="col-md-2">
            <label class="form-label">月份</label>
            <select name="month" class="form-select" required>
                <?php for ($m = 1; $m <= 12; $m++): ?>
                    <?php $val = str_pad($m, 2, "0", STR_PAD_LEFT); ?>
                    <option value="<?= $val ?>" <?= $val == $month ? 'selected' : '' ?>><?= $val ?></option>
                <?php endfor; ?>
            </select>
        </div>
        <div class="col-md-2">
            <label class="form-label d-block">&nbsp;</label>
            <button type="submit" class="btn btn-primary w-100">查詢</button>
        </div>
    </form>
	<div class="export-area">
		<!-- 班別資訊 -->
    <div class="mb-4 shift-info-table">
        <h5>班別資訊</h5>
        <table class="table table-bordered">
            <tr><th>班別</th><td><?= $shift_name ?></td></tr>
            <tr><th>上班時間</th><td><?= $shift_start_time ?></td></tr>
            <tr><th>下班時間</th><td><?= $shift_end_time ?></td></tr>
        </table>
    </div>

    <!-- 核准申請 -->
    <div class="mb-4 approved-requests">
        <h5>當月核准的申請</h5>
        <table class="table table-bordered attendance-table">
            <thead>
                <tr>
                    <th>類型</th>
                    <th>假別</th>
                    <th>理由</th>
                    <th>起始</th>
                    <th>結束</th>
                    <th>狀態</th>
                </tr>
            </thead>
            <tbody>
                <?php if (!empty($approved_requests)): ?>
                    <?php foreach ($approved_requests as $request): ?>
                        <tr>
                            <td><?= $request['type'] ?></td>
                            <td><?= $leave_map[$request['subtype']] ?? $request['subtype'] ?></td>
                            <td><?= $request['reason'] ?></td>
                            <td><?= $request['start_date'] ?></td>
                            <td><?= $request['end_date'] ?></td>
                            <td><?= $request['status'] === 'Approved' ? '<span class="text-success">已核准</span>' : '<span class="text-danger">未核准</span>' ?></td>
                        </tr>
                    <?php endforeach; ?>
                <?php else: ?>
                    <tr><td colspan="6">本月無核准申請</td></tr>
                <?php endif; ?>
            </tbody>
        </table>
    </div>
		<div class="attendance-export-area">

        <!-- 考勤紀錄表 -->
		<table id="table" class="table table-bordered attendance-table" >
			<thead>
				<tr>
					<th>日期</th>
					<th>上班時間</th>
					<th>下班時間</th>
					<th>狀態</th>
					<th>缺席時數</th>
				</tr>
			</thead>
			<tbody>
				<?php foreach ($attendance_data as $record): ?>
					<tr class="status-row status-<?= htmlspecialchars($record['status_class']) ?>">
						<td class="date"><?= htmlspecialchars($record['date']) ?></td>
						<td class="first-time"><?= htmlspecialchars($record['first_time']) ?></td>
						<td class="last-time"><?= htmlspecialchars($record['last_time']) ?></td>


						<!-- 狀態 (預設純文字，修改時變下拉選單) -->
						<td>
							<span class="status-text"><?= implode(', ', $record['default_status']) ?></span>
							<select class="status-select" multiple style="display: none;" onchange="updateRowColor(this)">
								<option value="正常出勤" <?= in_array('正常出勤', $record['default_status']) ? 'selected' : '' ?>>正常出勤</option>
								<option value="漏打卡(上班)" <?= in_array('漏打卡(上班)', $record['default_status']) ? 'selected' : '' ?>>漏打卡(上班)</option>
								<option value="漏打卡(下班)" <?= in_array('漏打卡(下班)', $record['default_status']) ? 'selected' : '' ?>>漏打卡(下班)</option>
								<option value="國定假日" <?= in_array('國定假日', $record['default_status']) ? 'selected' : '' ?>>國定假日</option>
								<option value="曠職" <?= in_array('曠職', $record['default_status']) ? 'selected' : '' ?>>曠職</option>
								<option value="早退" <?= in_array('早退', $record['default_status']) ? 'selected' : '' ?>>早退</option>
								<option value="遲到" <?= in_array('遲到', $record['default_status']) ? 'selected' : '' ?>>遲到</option>
								<option value="颱風假" <?= in_array('颱風假', $record['default_status']) ? 'selected' : '' ?>>颱風假</option>
								<?php foreach ($leave_map as $id => $name): ?>
									<option value="<?= htmlspecialchars($name) ?>" <?= in_array($name, $record['default_status']) ? 'selected' : '' ?>><?= htmlspecialchars($name) ?></option>
								<?php endforeach; ?>
							</select>
						</td>
						


						<!-- 缺席時數 (預設顯示數值，修改時可編輯) -->
						<td>
							<span class="absent-text"><?= htmlspecialchars($record['absent_minutes']) ?> 分</span>
<input type="number" class="absent-input form-control" value="<?= htmlspecialchars($record['absent_minutes']) ?>" style="display: none; width: 80px;">

						</td>
					</tr>
				<?php endforeach; ?>
			</tbody>
		</table>
		</div>
		</div>
<div class="d-flex gap-3 mt-4 mb-3">
    <button id="edit_button" class="btn btn-warning" onclick="enableEditing()">✏️ 修改</button>
    <button id="cancel_button" class="btn btn-outline-danger" style="display: none;" onclick="cancelEditing()">❌ 取消</button>
    <button id="save_button" class="btn btn-success" onclick="saveAttendanceToServer()">💾 儲存</button>
    <button id="export_button" class="btn btn-secondary" onclick="exportToImage()">📸 匯出圖片</button>
	
</div>

    </div>
<script>
// ✅ 1. 啟用編輯模式
function enableEditing() {
    // 隱藏目前顯示的文字狀態與缺勤欄位
    document.querySelectorAll('.status-text, .absent-text').forEach(el => el.style.display = 'none');
    // 顯示可編輯欄位（下拉與輸入框）
    document.querySelectorAll('.status-select, .absent-input').forEach(el => el.style.display = 'inline-block');

    // 切換按鈕顯示
    document.getElementById('edit_button').style.display = 'none';
    document.getElementById('save_button').style.display = 'inline-block';
    document.getElementById('cancel_button').style.display = 'inline-block';
}

// ✅ 2. 取消編輯模式
function cancelEditing() {
    // 隱藏編輯欄位
    document.querySelectorAll('.status-select, .absent-input').forEach(el => el.style.display = 'none');
    // 顯示文字欄位
    document.querySelectorAll('.status-text, .absent-text').forEach(el => el.style.display = 'inline-block');

    // 還原按鈕
    document.getElementById('edit_button').style.display = 'inline-block';
    document.getElementById('cancel_button').style.display = 'none';
}

// ✅ 3. 更新狀態顏色（根據選取值）
function updateRowColor(selectElement) {
    const row = selectElement.closest('tr');
    const selectedValues = Array.from(selectElement.selectedOptions).map(option => option.value);

    // 移除所有顏色類別
    row.classList.remove("status-normal", "status-yellow", "status-orange", "status-purple", "status-red");

    // 判斷並套用對應顏色
    if (selectedValues.includes("正常出勤")) {
        row.classList.add("status-normal");
    } else if (selectedValues.includes("漏打卡(上班)") || selectedValues.includes("漏打卡(下班)")) {
        row.classList.add("status-yellow");
    } else if (selectedValues.includes("國定假日")) {
        row.classList.add("status-purple");
    } else if (selectedValues.includes("遲到") || selectedValues.includes("早退") || selectedValues.includes("曠職")) {
        row.classList.add("status-red");
    } else if (selectedValues.some(value => value.includes("假"))) {
        row.classList.add("status-orange");
    }
}

// ✅ 4. 載入時預設套用每列的顏色
document.addEventListener("DOMContentLoaded", function () {
    document.querySelectorAll(".status-select").forEach(select => {
        updateRowColor(select);
    });
});

// ✅ 5. 收集畫面上的資料為陣列格式
function collectAttendanceData() {
    const rows = document.querySelectorAll('.attendance-table tbody tr');
    const attendanceData = [];

    rows.forEach(row => {
        const date = row.querySelector('.date')?.textContent.trim();

		function isTimeFormat(str) {
			return /^\d{2}:\d{2}(:\d{2})?$/.test(str);
		}

		const rawFirstTime = row.querySelector('.first-time')?.textContent.trim() || '';
		const rawLastTime = row.querySelector('.last-time')?.textContent.trim() || '';

		const firstTime = isTimeFormat(rawFirstTime) ? rawFirstTime : rawFirstTime;
		const lastTime = isTimeFormat(rawLastTime) ? rawLastTime : rawLastTime;


        // 多重選取狀態文字
        const selectedOptions = row.querySelector('.status-select')?.selectedOptions || [];
        const statusText = Array.from(selectedOptions).map(opt => opt.value).join(',');

        const absent = row.querySelector('.absent-input')?.value || "0";

        if (date) {
            attendanceData.push({
                date: date,
                first_time: firstTime || null,
                last_time: lastTime || null,
                status_text: statusText || '',
                absent_minutes: parseInt(absent) || 0
            });
        }
    });

    return attendanceData;
}

// ✅ 6. 儲存至後端 PHP
function saveAttendanceToServer() {
    const employeeNumber = document.querySelector('select[name="employee_number"]').value;
    const attendanceData = collectAttendanceData();

    if (!employeeNumber || attendanceData.length === 0) {
        alert("資料不完整，無法儲存！");
        return;
    }

    fetch('save_attendance.php', {
        method: 'POST',
        headers: { 'Content-Type': 'application/json' },
        body: JSON.stringify({
            employee_number: employeeNumber,
            attendance_data: attendanceData
        })
    })
    .then(response => response.json())
    .then(data => {
        if (data.success) {
            alert("✅ 資料已儲存成功！");
            location.reload();
        } else {
            alert("❌ 儲存失敗：" + data.message);
            if (data.error) console.error(data.error);
        }
    })
    .catch(error => {
        alert("❌ 發生錯誤，請稍後再試");
        console.error(error);
    });
}

// ✅ 7. 匯出圖片
function exportToImage() {
    const exportSection = document.createElement("div");
    exportSection.style.padding = "20px";
    exportSection.style.background = "#ffffff";
    exportSection.style.width = "100%";
    exportSection.style.maxWidth = "1200px";
    exportSection.style.margin = "0 auto";

    const shiftInfo = document.querySelector(".shift-info-table")?.cloneNode(true);
    const approvedRequests = document.querySelector(".approved-requests")?.cloneNode(true);
    const attendanceTable = document.querySelector(".attendance-export-area")?.cloneNode(true);

    if (!shiftInfo || !approvedRequests || !attendanceTable) {
        alert("找不到匯出區塊，請確認表格是否正確包在對應 class 中！");
        return;
    }

    const year = document.querySelector('[name="year"]').value;
    const month = document.querySelector('[name="month"]').value;
    const employeeSelect = document.querySelector('[name="employee_number"]');
    const employeeName = employeeSelect.options[employeeSelect.selectedIndex].text.split(" - ")[1] || "未知員工";

    const title = document.createElement("h2");
    title.textContent = `${year}年${month}月 ${employeeName} 員工考勤資訊`;
    title.style.textAlign = "center";
    title.style.marginBottom = "20px";
    title.style.background = "#e0e0e0"; // ✅ 標題底色
    title.style.padding = "10px";

    exportSection.appendChild(title);
    exportSection.appendChild(shiftInfo);
    exportSection.appendChild(approvedRequests);
    exportSection.appendChild(attendanceTable);

    exportSection.style.position = "absolute";
    exportSection.style.left = "-9999px";
    document.body.appendChild(exportSection);

    html2canvas(exportSection, {
        scale: 2,
        useCORS: true,
        width: exportSection.offsetWidth,
    }).then(canvas => {
        const link = document.createElement("a");
        link.href = canvas.toDataURL("image/png");
        link.download = `${year}年${month}月 ${employeeName} 員工考勤資訊.png`;
        link.click();
        document.body.removeChild(exportSection);
    }).catch(error => {
        console.error("匯出圖片失敗：", error);
        alert("匯出圖片失敗，請稍後再試！");
    });
}
</script>
<script src="https://cdnjs.cloudflare.com/ajax/libs/html2canvas/1.4.1/html2canvas.min.js"></script>


</body>
</html>


