<?php
session_start();

// 檢查使用者是否登入且為管理員
if (!isset($_SESSION['user']) || $_SESSION['user']['role'] !== 'admin') {
    header('Location: ../login.php');
    exit;
}

$conn = new mysqli('localhost', 'root', '', 'mymakeart');
if ($conn->connect_error) {
    die("資料庫連接失敗：" . $conn->connect_error);
}

// 初始化變數
$employee_number = $_GET['employee_number'] ?? '';
$year = $_GET['year'] ?? date('Y', strtotime('first day of last month'));
$month = $_GET['month'] ?? date('m', strtotime('first day of last month'));
$start_date = "$year-$month-01";
$end_date = date('Y-m-t', strtotime($start_date));
$attendance_data = [];
// ✅ 2️⃣ 查詢員工班別資訊
$shift_query = $conn->prepare("
    SELECT shifts.name AS shift_name, shifts.start_time, shifts.end_time
    FROM employees
    JOIN shifts ON employees.shift_id = shifts.id
    WHERE employees.employee_number = ?
");
$shift_query->bind_param('s', $employee_number);
$shift_query->execute();
$shift_result = $shift_query->get_result();
$shift_info = $shift_result->fetch_assoc();

// ✅ 3️⃣ 設定班別資訊
$shift_name = $shift_info['shift_name'] ?? '無班別資訊';
$shift_start_time = $shift_info['start_time'] ?? '-';
$shift_end_time = $shift_info['end_time'] ?? '-';


// ✅ 2️⃣ 設定班別的 `上班時間` 和 `下班時間`
$shift_start_time = $shift_info['start_time'] ?? '01:00:00';
$shift_end_time = $shift_info['end_time'] ?? '01:00:00';

// 獲取員工清單
$employee_result = $conn->query("
    SELECT employee_number, name 
    FROM employees 
    ORDER BY employee_number
");
if ($employee_result) {
    $employee_list = $employee_result->fetch_all(MYSQLI_ASSOC);
    if (empty($employee_number)) {
        $employee_number = $employee_list[0]['employee_number'] ?? '';
    }
}

// 查詢政府行事曆
$holiday_result = $conn->query("SELECT * FROM holidays");
$holidays = $holiday_result->fetch_all(MYSQLI_ASSOC);
$holiday_map = [];
foreach ($holidays as $holiday) {
    $holiday_map[$holiday['date']] = [
        'description' => $holiday['description'],
        'is_working_day' => $holiday['is_working_day'] ?? 0
    ];
}

// 只查詢核准通過的請假/加班申請
$request_result = $conn->prepare("
    SELECT type, subtype, reason, start_date, end_date, status 
    FROM requests 
    WHERE employee_number = ? AND status = 'approved' AND start_date BETWEEN ? AND ?
");
$request_result->bind_param('sss', $employee_number, $start_date, $end_date);
$request_result->execute();
$approved_requests = $request_result->get_result()->fetch_all(MYSQLI_ASSOC);

// 查詢考勤記錄（依打卡記錄整理）
$attendance_result = $conn->prepare("
    SELECT log_date, MIN(log_time) AS 第一筆時間, MAX(log_time) AS 最後一筆時間
    FROM attendance_logs
    WHERE employee_number = ? AND log_date BETWEEN ? AND ?
    GROUP BY log_date
    ORDER BY log_date
");
$attendance_result->bind_param('sss', $employee_number, $start_date, $end_date);
$attendance_result->execute();
$raw_attendance_logs = $attendance_result->get_result()->fetch_all(MYSQLI_ASSOC);

// 查詢已儲存的考勤數據
$saved_attendance_result = $conn->prepare("
    SELECT date, first_time, last_time, status_text, absent_hours, absent_minutes 
    FROM saved_attendance
    WHERE employee_number = ? AND date BETWEEN ? AND ?
");
$saved_attendance_result->bind_param('sss', $employee_number, $start_date, $end_date);
$saved_attendance_result->execute();
$saved_attendance_data = $saved_attendance_result->get_result()->fetch_all(MYSQLI_ASSOC);
$saved_attendance = [];
foreach ($saved_attendance_data as $row) {
    $saved_attendance[$row['date']] = $row;
}

// 整理打卡記錄（合併每天的第一筆與最後一筆打卡）
$attendance_logs = [];
foreach ($raw_attendance_logs as $log) {
    $date = $log['log_date'];
    $first_time = $log['第一筆時間'];
    $last_time = $log['最後一筆時間'];
    $attendance_logs[$date] = ['first_time' => $first_time, 'last_time' => $last_time];
}

// ✅ 1️⃣ 修正 `日期範圍`，確保 `foreach` 迴圈不會 `多存一天`
$dates_in_month = [];
$current_date = new DateTime($start_date);
$end_date_obj = new DateTime($end_date);

while ($current_date <= $end_date_obj) {
    $dates_in_month[] = clone $current_date;
    $current_date->modify('+1 day');
}


// 查詢假別清單（修正查詢）
$leave_type_result = $conn->query("SELECT id, name FROM leave_types");
$leave_types = $leave_type_result->fetch_all(MYSQLI_ASSOC);
$leave_map = [];
foreach ($leave_types as $leave) {
    $leave_map[$leave['id']] = $leave['name'];
}
foreach ($dates_in_month as $date) {
			$is_holiday = false;
			$is_weekend = false;
			$has_approved_leave = false;
$current_date = $date->format('Y-m-d');
    // 預設使用打卡記錄；若無打卡則設為 '-'
    $first_time = $attendance_logs[$current_date]['first_time'] ?? '-';
    $last_time = $attendance_logs[$current_date]['last_time'] ?? '-';
    $status_class = 'normal';
    $default_status = [];
    $absent_hours = 0;
    $absent_minutes = 0;
    
    if (isset($saved_attendance[$current_date])) {
        $first_time = $saved_attendance[$current_date]['first_time'];
        $last_time = $saved_attendance[$current_date]['last_time'];
        $default_status = explode(',', $saved_attendance[$current_date]['status_text']);
        $absent_hours = $saved_attendance[$current_date]['absent_hours'];
        $absent_minutes = $saved_attendance[$current_date]['absent_minutes'];
    } else {
        if (isset($holiday_map[$current_date])) {
            if ($holiday_map[$current_date]['is_working_day']) {
                $status_class = 'working-day';
                $default_status[] = '補班';
            } else {
                $status_class = 'holiday';
                $first_time = $holiday_map[$current_date]['description'];
                $last_time = $holiday_map[$current_date]['description'];
                $default_status[] = '國定假日';
            }
        } elseif (!isset($attendance_logs[$current_date])) {
			// ✅ 1️⃣ 檢查當天是星期幾
			$day_of_week = date('N', strtotime($current_date)); // 1=星期一, 7=星期日
			$is_saturday = ($day_of_week == 6); // 是否為禮拜六
			$is_sunday = ($day_of_week == 7);   // 是否為禮拜日
			$is_weekend = ($is_saturday || $is_sunday); // 是否為週末
			$is_holiday = isset($holiday_map[$current_date]) && !$holiday_map[$current_date]['is_working_day'];
			$is_working_day = isset($holiday_map[$current_date]) && $holiday_map[$current_date]['is_working_day'];

			// 檢查是否有核准的請假申請
			$has_approved_leave = false;
			foreach ($approved_requests as $req) {
				if ($req['type'] === '請假') {
					$reqStartDate = date('Y-m-d', strtotime($req['start_date']));
					$reqEndDate = date('Y-m-d', strtotime($req['end_date']));
					if ($current_date >= $reqStartDate && $current_date <= $reqEndDate) {
						$has_approved_leave = true;
						break;
					}
				}
			}
			// ✅ 1️⃣ 如果當天是週六、週日，且無補班，則視為國定假日
			if ($is_weekend && !$is_working_day) {
				$is_holiday = true;
			}

			// ✅ 2️⃣ 檢查當天是否有核准通過的請假
			$has_approved_leave = false;
			foreach ($approved_requests as $req) {
				if ($req['type'] === '請假') {
					$reqStartDate = date('Y-m-d', strtotime($req['start_date']));
					$reqEndDate = date('Y-m-d', strtotime($req['end_date']));
					if ($current_date >= $reqStartDate && $current_date <= $reqEndDate) {
						$has_approved_leave = true;
						break;
					}
				}
			}

			// ✅ 1️⃣ 初始化變數，避免 `Undefined variable` 錯誤
			if ($is_saturday && !$is_working_day) {
					$status_class = 'weekend';
					$first_time = '禮拜六';
					$last_time = '禮拜六';
					$default_status[] = '國定假日';
				} elseif ($is_sunday && !$is_working_day) {
					$status_class = 'weekend';
					$first_time = '禮拜日';
					$last_time = '禮拜日';
					$default_status[] = '國定假日';
				}

			// ✅ 5️⃣ 如果有核准的請假申請，不標記為曠職
			elseif ($has_approved_leave) {
				$status_class = 'leave-approved';
				$first_time = '請假';
				$last_time = '請假';
				$default_status[] = '請假';
			}
			// ✅ 6️⃣ 如果沒有請假、不是國定假日，則標記為曠職
			else {
				$status_class = 'absent';
				$default_status[] = '曠職';
			}
		}elseif ($first_time === $last_time && $first_time !== '-') {
            $recorded_time = $first_time;
            $diff_start = abs(strtotime($current_date . ' ' . $recorded_time) - strtotime($current_date . ' ' . $shift_start_time));
            $diff_end   = abs(strtotime($current_date . ' ' . $recorded_time) - strtotime($current_date . ' ' . $shift_end_time));
            if ($diff_start < $diff_end) {
                $last_time = '漏打卡';
                $default_status[] = '漏打卡(下班)';
            } else {
                $first_time = '漏打卡';
                $default_status[] = '漏打卡(上班)';
            }
            $status_class = 'multi-status';
        } else {
            $default_status[] = '正常出勤';
        }
    }
    
// ✅ 1️⃣ 檢查核准的請假申請
$approvedLeaveRequest = null;
foreach ($approved_requests as $req) {
    if ($req['type'] === '請假') {
        $reqStartDate = date('Y-m-d', strtotime($req['start_date']));
        $reqEndDate = date('Y-m-d', strtotime($req['end_date']));
        
        if ($current_date >= $reqStartDate && $current_date <= $reqEndDate) {
            $approvedLeaveRequest = $req;
            break;
        }
    }
}

// ✅ 2️⃣ 設定請假狀態，並確保狀態選單預選 `subtype`
if ($approvedLeaveRequest !== null) {
    $leaveType = $approvedLeaveRequest['subtype']; // ✅ 直接存儲 `leave_types` 表中的中文名稱
    
    // ✅ 如果 `subtype` 存在於 `default_status`，則預設選取
    if (!in_array($leaveType, $default_status)) {
        $default_status[] = $leaveType;
    }
}

// ✅ 3️⃣ 檢查核准的加班申請
$approvedOvertimeRequest = null;
foreach ($approved_requests as $req) {
    if ($req['type'] === '加班') {
        $reqStartDate = date('Y-m-d', strtotime($req['start_date']));
        $reqEndDate = date('Y-m-d', strtotime($req['end_date']));

        if ($current_date >= $reqStartDate && $current_date <= $reqEndDate) {
            $approvedOvertimeRequest = $req;
            break;
        }
    }
}

// ✅ 4️⃣ 設定加班狀態，並確保狀態選單預選 `加班申請`
if ($approvedOvertimeRequest !== null) {
    if (!in_array('加班申請', $default_status)) {
        $default_status[] = '加班申請';
    }
}

// ✅ 3️⃣ 計算遲到、早退時間（以秒計算）
if (preg_match('/^\d{2}:\d{2}:\d{2}$/', $first_time) && preg_match('/^\d{2}:\d{2}:\d{2}$/', $last_time)) {
    $shift_start_ts = strtotime($current_date . ' ' . $shift_start_time);
    $shift_end_ts   = strtotime($current_date . ' ' . $shift_end_time);
    $first_time_ts  = strtotime($current_date . ' ' . $first_time);
    $last_time_ts   = strtotime($current_date . ' ' . $last_time);

    $tardy = ($first_time_ts > $shift_start_ts) ? ($first_time_ts - $shift_start_ts) : 0;
    $early = ($last_time_ts < $shift_end_ts) ? ($shift_end_ts - $last_time_ts) : 0;
	// ✅ 1️⃣ 初始化 `$early`，避免 Undefined variable 錯誤
	$early = 0;
	

	
	// ✅ 2️⃣ 如果 `last_time` 為有效時間，則計算 `早退`
	if (preg_match('/^\d{2}:\d{2}:\d{2}$/', $last_time)) {
		$early = ($last_time_ts < $shift_end_ts) ? ($shift_end_ts - $last_time_ts) : 0;
	}

	// ✅ 3️⃣ 如果 `早退`，則標記 `早退`
	if ($early > 0) {
		if (!in_array('早退', $default_status)) {
			$default_status[] = '早退';
		}
	}

    $calculated_absent_seconds = $tardy + $early;

    // ✅ 4️⃣ 只有在 `遲到`、`早退` 或 `曠職` 時，才計算缺席時間
	if (!isset($saved_attendance[$current_date])) { // 只有當沒有儲存紀錄時才計算
		if (in_array('遲到', $default_status) || in_array('早退', $default_status) || in_array('曠職', $default_status)) {
			$absent_minutes = floor($calculated_absent_seconds / 60); // ✅ 計算到分鐘
		} else {
			$absent_minutes = 0; // ✅ 如果沒有這些狀態，則缺席時間為 0
		}
	}

}
	
	// ✅ 4️⃣ 如果 `曠職`，則整個班別時數計算為 `缺席時數`
	if (!isset($saved_attendance[$current_date])) { // 只有當沒有儲存紀錄時才計算
	if (in_array('曠職', $default_status)) {
		$shift_duration_seconds = strtotime($shift_end_time) - strtotime($shift_start_time);
		$absent_minutes = floor($shift_duration_seconds / 60)-60;
	}}
	// ✅ 2️⃣ 如果 `遲到` 或 `早退`，則移除 `正常出勤`
    if (in_array('遲到', $default_status) || in_array('早退', $default_status)) {
        $default_status = array_diff($default_status, ['正常出勤']);
    }
	// ✅ 5️⃣ 設定 `曠職` 判斷（基於班別資訊）
	if (!$is_holiday && !$is_weekend && !$has_approved_leave && $first_time === '-' && $last_time === '-') {
		if (!in_array('曠職', $default_status)) {
			$default_status[] = '曠職';
		}
	}

	// ✅ 1️⃣ 判斷是否需要儲存資料
	$should_save = false;

	// ✅ 2️⃣ 如果是 `曠職`，則需要儲存
	if (in_array('曠職', $default_status)) {
		$should_save = true;
	}

	// ✅ 3️⃣ 如果有 `打卡紀錄`，則需要儲存
	if ($first_time !== '-' || $last_time !== '-') {
		$should_save = true;
	}

	// ✅ 4️⃣ 如果是 `國定假日`，則不儲存
	if (in_array('國定假日', $default_status)) {
		$should_save = true;
	}

	// ✅ 5️⃣ 如果 `上下班時間內` 存在 `中文標記`，則存中文資料
	if (preg_match('/[^\x00-\x7F]+/', $first_time) || preg_match('/[^\x00-\x7F]+/', $last_time)) {
		$first_time = ($first_time !== '-') ? $first_time : '-';
		$last_time = ($last_time !== '-') ? $last_time : '-';
		$should_save = true;
	}


    
if ($should_save) {
	$attendance_data[] = [
		'date' => $current_date,
		'first_time' => $first_time,
		'last_time' => $last_time,
		'status_class' => $status_class,
		'default_status' => $default_status,
		'absent_minutes' => $absent_minutes      // 計算分鐘
	];
}
}



?>
<!DOCTYPE html>
<html lang="zh-TW">
<head>
    <meta charset="UTF-8">
    <meta name="viewport" content="width=device-width, initial-scale=1.0">
    <title>考勤紀錄表</title>
    <link rel="stylesheet" href="styles.css">
    <link rel="stylesheet" href="attendance_list.css">
    <link rel="stylesheet" href="admin_navbar.css">
</head>
<body>
    <div class="container">
        <!-- 導航列 -->
        <?php include 'admin_navbar.php'; ?>
        <h1>考勤紀錄表</h1>
        <!-- 查詢表單，欄位排列在同一排，月份改成下拉選單 -->
        <form method="GET" action="" class="attendance-form">
            <div class="form-group-inline">
                <label for="employee_number">選擇員工：</label>
                <select id="employee_number" name="employee_number" required>
                    <option value="">請選擇員工</option>
                    <?php foreach ($employee_list as $employee): ?>
                        <option value="<?= htmlspecialchars($employee['employee_number']) ?>" <?= $employee_number === $employee['employee_number'] ? 'selected' : '' ?>>
                            <?= htmlspecialchars($employee['employee_number'] . ' - ' . $employee['name']) ?>
                        </option>
                    <?php endforeach; ?>
                </select>
                <label for="year">年份：</label>
                <input type="number" id="year" name="year" value="<?= htmlspecialchars($year) ?>" required>
                <label for="month">月份：</label>
                <select id="month" name="month" required>
                    <?php 
                        for ($m = 1; $m <= 12; $m++) {
                            $month_val = str_pad($m, 2, '0', STR_PAD_LEFT);
                            $selected = ($month_val == $month) ? 'selected' : '';
                            echo "<option value=\"$month_val\" $selected>$month_val</option>";
                        }
                    ?>
                </select>
                <button type="submit">查詢</button>
            </div>
        </form>
		<!-- ✅ 4️⃣ 顯示員工班別資訊 -->
		<h2>員工班別資訊</h2>
		<table class="shift-info-table">
			<tr>
				<th>班別名稱</th>
				<td><?= htmlspecialchars($shift_name) ?></td>
			</tr>
			<tr>
				<th>上班時間</th>
				<td><?= htmlspecialchars($shift_start_time) ?></td>
			</tr>
			<tr>
				<th>下班時間</th>
				<td><?= htmlspecialchars($shift_end_time) ?></td>
			</tr>
		</table>
        <!-- 核准申請表 -->
        <h2>當月核准的申請</h2>
        <table class="approved-requests">
            <thead>
                <tr>
                    <th>類型</th>
                    <th>假別</th>
                    <th>理由</th>
                    <th>起始日期與時間</th>
                    <th>結束日期與時間</th>
                    <th>狀態</th>
                </tr>
            </thead>
            <tbody>
                <?php if (!empty($approved_requests)): ?>
					<?php foreach ($approved_requests as $request): ?>
						<tr>
							<td><?= htmlspecialchars($request['type']) ?></td>
							<td>
								<?= htmlspecialchars($leave_map[$request['subtype']] ?? $request['subtype']) ?>
							</td>
							<td><?= htmlspecialchars($request['reason'] ?? '-') ?></td>
							<td><?= htmlspecialchars($request['start_date']) ?></td>
							<td><?= htmlspecialchars($request['end_date']) ?></td>
							<td>
								<?= $request['status'] === 'approved' ? '<span class="status-green">已核准</span>' : '<span class="status-red">未核准</span>' ?>
							</td>
						</tr>
					<?php endforeach; ?>
				<?php else: ?>
					<tr>
						<td colspan="6">本月無核准的申請記錄。</td>
					</tr>
				<?php endif; ?>

            </tbody>
        </table>
        <!-- 考勤紀錄表 -->
        <table class="attendance-table">
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
                    <tr class="status-row <?= htmlspecialchars($record['status_class']) ?>" data-status-class="<?= htmlspecialchars($record['status_class']) ?>">
                        <td><?= htmlspecialchars($record['date']) ?></td>
                        <td><?= htmlspecialchars($record['first_time']) ?></td>
                        <td><?= htmlspecialchars($record['last_time']) ?></td>
						<td>
							<select class="status-select" multiple onchange="updateRowColor(this)">
								<option value="正常出勤" <?= in_array('正常出勤', $record['default_status']) ? 'selected' : '' ?>>正常出勤</option>
								<option value="漏打卡(上班)" <?= in_array('漏打卡(上班)', $record['default_status']) ? 'selected' : '' ?>>漏打卡(上班)</option>
								<option value="漏打卡(下班)" <?= in_array('漏打卡(下班)', $record['default_status']) ? 'selected' : '' ?>>漏打卡(下班)</option>
								<option value="國定假日" <?= in_array('國定假日', $record['default_status']) ? 'selected' : '' ?>>國定假日</option>
								<option value="曠職" <?= in_array('曠職', $record['default_status']) ? 'selected' : '' ?>>曠職</option>
								<option value="早退" <?= in_array('早退', $record['default_status']) ? 'selected' : '' ?>>早退</option>
								<option value="遲到" <?= in_array('遲到', $record['default_status']) ? 'selected' : '' ?>>遲到</option>
								<?php foreach ($leave_map as $id => $name): ?>
									<option value="<?= htmlspecialchars($name) ?>" <?= in_array($name, $record['default_status']) ? 'selected' : '' ?>><?= htmlspecialchars($name) ?></option>
								<?php endforeach; ?>
							</select>
						</td>
                        <td contenteditable="true">
                           <?= htmlspecialchars($record['absent_minutes']) ?> 分
                        </td>
                    </tr>
                <?php endforeach; ?>
            </tbody>
        </table>
        <button id="save">儲存</button>
    </div>

    <script>
    //狀態列顏色判定
		function updateRowColor(selectElement) {
		const row = selectElement.closest('tr');  // 取得當前行
		const selectedValues = Array.from(selectElement.selectedOptions).map(option => option.value);

		// 先移除所有可能的狀態顏色類別
		row.classList.remove("status-normal", "status-yellow", "status-orange", "status-purple", "status-red");

		// 判斷並套用正確的顏色
		if (selectedValues.includes("正常出勤")) {
			row.classList.add("status-normal");  // 淺綠色
		} else if (selectedValues.includes("漏打卡(上班)") || selectedValues.includes("漏打卡(下班)")) {
			row.classList.add("status-yellow");  // 黃色
		} else if (selectedValues.includes("國定假日")) {
			row.classList.add("status-purple");  // 淺紫色
		} else if (selectedValues.includes("遲到") || selectedValues.includes("早退") || selectedValues.includes("曠職")) {
			row.classList.add("status-red");  // 淡紅色
		} else if (selectedValues.some(value => value.includes("假"))) { 
			row.classList.add("status-orange");  // 淺橘色 (各種請假)
		}
	}

	// **確保所有行在頁面載入時都應用顏色**
	document.addEventListener("DOMContentLoaded", function () {
		document.querySelectorAll(".status-select").forEach(select => {
			updateRowColor(select);
		});
	});

    // 儲存功能
    document.getElementById('save').addEventListener('click', function () {
        const rows = document.querySelectorAll('.attendance-table tbody tr');
        const data = [];

        rows.forEach(row => {
            const date = row.cells[0].textContent.trim();
            const first_time = row.cells[1].textContent.trim();
            const last_time = row.cells[2].textContent.trim();
            const status_select = row.cells[3].querySelectorAll('option');
            const status_text = Array.from(status_select)
                .filter(option => option.selected)
                .map(option => option.value)
                .join(',');
            const absent_time = row.cells[4].textContent.trim().split(' ');
            const absent_hours = parseInt(absent_time[2]) || 0
			const absent_minutes = parseInt(absent_time[0]) || 0


            data.push({
                date,
                first_time,
                last_time,
                status_text,
				absent_minutes,
                absent_hours
				
            });
        });

        fetch('save_attendance.php', {
            method: 'POST',
            headers: { 'Content-Type': 'application/json' },
            body: JSON.stringify({
                employee_number: '<?= htmlspecialchars($employee_number) ?>',
                attendance_data: data
            })
        })
        .then(response => response.json())
        .then(result => {
            if (result.success) {
                alert(result.message);
            } else {
                alert('儲存失敗：' + result.message);
            }
        })
        .catch(error => {
            console.error('儲存錯誤：', error);
            alert('儲存失敗，請檢查伺服器是否正常運行。');
        });
    });
    </script>
</body>
</html>
