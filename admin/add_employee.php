<?php
// 【PHP-1】權限檢查與資料庫連線
require_once '../db_connect.php';
if (!isset($_SESSION['user']) || $_SESSION['user']['role'] !== 'admin') {
    header('Location: ../login.php');
    exit;
}

// 【PHP-2】載入班別資料並組成陣列
$shift_list = [];
$shift_query = $conn->query("SELECT id, name, start_time, end_time FROM shifts ORDER BY id ASC");
if ($shift_query) {
    while ($shift_row = $shift_query->fetch_assoc()) {
        $shift_list[] = $shift_row;
    }
}

// 【PHP-3】表單回饋與預設列資料準備
$feedback_success = [];
$feedback_errors = [];
$prefill_rows = [];
$minimum_rows = 3;

if ($_SERVER['REQUEST_METHOD'] === 'POST') {
    $employee_numbers = $_POST['employee_number'] ?? [];
    $usernames = $_POST['username'] ?? [];
    $names = $_POST['name'] ?? [];
    $hire_dates = $_POST['hire_date'] ?? [];
    $passwords = $_POST['password'] ?? [];
    $shift_ids = $_POST['shift_id'] ?? [];

    $row_count = max(
        count($employee_numbers),
        count($usernames),
        count($names),
        count($hire_dates),
        count($passwords),
        count($shift_ids)
    );

    $rows_data = [];
    for ($i = 0; $i < $row_count; $i++) {
        $rows_data[$i] = [
            'row_index' => $i + 1,
            'employee_number' => trim($employee_numbers[$i] ?? ''),
            'username' => trim($usernames[$i] ?? ''),
            'name' => trim($names[$i] ?? ''),
            'hire_date' => trim($hire_dates[$i] ?? ''),
            'password_plain' => $passwords[$i] ?? '',
            'shift_id' => trim((string)($shift_ids[$i] ?? '')),
            'status' => 'pending',
            'error_message' => ''
        ];
    }

    // 【PHP-4】檢查同次填寫工號是否重複
    $dup_map = [];
    foreach ($rows_data as $idx => $row) {
        $emp_no = $row['employee_number'];
        if ($emp_no === '') {
            continue;
        }
        if (isset($dup_map[$emp_no])) {
            $first_idx = $dup_map[$emp_no];
            if ($rows_data[$first_idx]['status'] !== 'error') {
                $rows_data[$first_idx]['status'] = 'error';
                $rows_data[$first_idx]['error_message'] = '工號重複，請確認後重新填寫。';
                $feedback_errors[] = "第 {$rows_data[$first_idx]['row_index']} 列：工號重複。";
            }
            $rows_data[$idx]['status'] = 'error';
            $rows_data[$idx]['error_message'] = '工號重複，請確認後重新填寫。';
            $feedback_errors[] = "第 {$row['row_index']} 列：工號重複。";
        } else {
            $dup_map[$emp_no] = $idx;
        }
    }

    // 【PHP-5】建立查詢與新增的預備敘述
    $check_stmt = $conn->prepare('SELECT id FROM employees WHERE employee_number = ?');
    $insert_stmt = $conn->prepare('INSERT INTO employees (employee_number, username, password, name, hire_date, shift_id, role) VALUES (?, ?, ?, ?, ?, ?, "employee")');

    foreach ($rows_data as $idx => $row) {
        if ($row['status'] === 'error') {
            $rows_data[$idx]['password_plain'] = '';
            continue;
        }

        $is_blank = (
            $row['employee_number'] === '' &&
            $row['username'] === '' &&
            $row['name'] === '' &&
            $row['hire_date'] === '' &&
            $row['password_plain'] === '' &&
            $row['shift_id'] === ''
        );

        if ($is_blank) {
            $rows_data[$idx]['status'] = 'empty';
            $rows_data[$idx]['password_plain'] = '';
            continue;
        }

        $missing_fields = [];
        if ($row['employee_number'] === '') {
            $missing_fields[] = '工號';
        }
        if ($row['username'] === '') {
            $missing_fields[] = '使用者帳號';
        }
        if ($row['name'] === '') {
            $missing_fields[] = '姓名';
        }
        if ($row['hire_date'] === '') {
            $missing_fields[] = '入職日期';
        }
        if ($row['password_plain'] === '') {
            $missing_fields[] = '登入密碼';
        }
        if ($row['shift_id'] === '') {
            $missing_fields[] = '班別';
        }

        if (!empty($missing_fields)) {
            $rows_data[$idx]['status'] = 'error';
            $rows_data[$idx]['error_message'] = '請補齊：' . implode('、', $missing_fields) . '。';
            $feedback_errors[] = "第 {$row['row_index']} 列：請補齊 " . implode('、', $missing_fields) . '。';
            $rows_data[$idx]['password_plain'] = '';
            continue;
        }

        $hire_date_obj = DateTime::createFromFormat('Y-m-d', $row['hire_date']);
        if (!$hire_date_obj) {
            $rows_data[$idx]['status'] = 'error';
            $rows_data[$idx]['error_message'] = '入職日期格式錯誤，請使用 YYYY-MM-DD。';
            $feedback_errors[] = "第 {$row['row_index']} 列：入職日期格式錯誤。";
            $rows_data[$idx]['password_plain'] = '';
            continue;
        }

        if (!ctype_digit($row['shift_id'])) {
            $rows_data[$idx]['status'] = 'error';
            $rows_data[$idx]['error_message'] = '班別資料有誤，請重新選擇。';
            $feedback_errors[] = "第 {$row['row_index']} 列：班別資料有誤。";
            $rows_data[$idx]['password_plain'] = '';
            continue;
        }

        $emp_no = $row['employee_number'];
        $check_stmt->bind_param('s', $emp_no);
        $check_stmt->execute();
        $check_result = $check_stmt->get_result();
        if ($check_result && $check_result->num_rows > 0) {
            $rows_data[$idx]['status'] = 'error';
            $rows_data[$idx]['error_message'] = '此工號已存在於系統中。';
            $feedback_errors[] = "第 {$row['row_index']} 列：工號 {$emp_no} 已存在。";
            $rows_data[$idx]['password_plain'] = '';
            continue;
        }

        $password_hash = password_hash($row['password_plain'], PASSWORD_DEFAULT);
        $username = $row['username'];
        $name = $row['name'];
        $hire_date = $row['hire_date'];
        $shift_id = (int)$row['shift_id'];

        $insert_stmt->bind_param('sssssi', $emp_no, $username, $password_hash, $name, $hire_date, $shift_id);
        if ($insert_stmt->execute()) {
            $rows_data[$idx]['status'] = 'success';
            $rows_data[$idx]['error_message'] = '';
            $feedback_success[] = "第 {$row['row_index']} 列（工號：{$emp_no}）新增成功。";
        } else {
            $rows_data[$idx]['status'] = 'error';
            $rows_data[$idx]['error_message'] = '新增時發生錯誤，請稍後再試。';
            $feedback_errors[] = "第 {$row['row_index']} 列：新增失敗。";
        }

        $rows_data[$idx]['password_plain'] = '';
    }

    if (isset($check_stmt)) {
        $check_stmt->close();
    }
    if (isset($insert_stmt)) {
        $insert_stmt->close();
    }

    $prefill_rows = array_values(array_filter($rows_data, function ($row) {
        return $row['status'] === 'error';
    }));
}

if (empty($prefill_rows)) {
    for ($i = 0; $i < $minimum_rows; $i++) {
        $prefill_rows[] = [
            'row_index' => $i + 1,
            'employee_number' => '',
            'username' => '',
            'name' => '',
            'hire_date' => '',
            'shift_id' => '',
            'status' => 'blank',
            'error_message' => ''
        ];
    }
}
?>
<!DOCTYPE html>
<html lang="zh-Hant">
<head>
    <meta charset="UTF-8">
    <meta name="viewport" content="width=device-width, initial-scale=1.0">
    <title>新增員工</title>
    <link href="https://cdn.jsdelivr.net/npm/bootstrap@5.3.3/dist/css/bootstrap.min.css" rel="stylesheet">
    <link rel="stylesheet" href="admin_navbar.css">
    <script src="https://code.jquery.com/jquery-3.7.1.min.js"></script>
    <script src="https://cdn.jsdelivr.net/npm/bootstrap@5.3.3/dist/js/bootstrap.bundle.min.js"></script>
    <style>
        :root {
            --brand-yellow: #FFCD00;
            --brand-pink: #E36386;
            --brand-blue: #345D9D;
        }
        body {
            background: #f6f8fb;
            font-family: 'Noto Sans TC', 'Microsoft JhengHei', sans-serif;
        }
        .page-banner {
            background: linear-gradient(120deg, var(--brand-blue), var(--brand-pink));
            border-radius: 18px;
            padding: 28px 32px;
            color: #fff;
            box-shadow: 0 10px 30px rgba(52, 93, 157, 0.15);
        }
        .page-banner h1 {
            font-weight: 700;
            letter-spacing: 1px;
        }
        .page-banner p {
            margin-bottom: 0;
            opacity: 0.9;
        }
        .content-card {
            margin-top: -40px;
            background: #fff;
            border-radius: 16px;
            padding: 28px;
            box-shadow: 0 12px 35px rgba(0, 0, 0, 0.08);
        }
        .table thead th {
            vertical-align: middle;
            font-weight: 600;
            font-size: 15px;
        }
        .table tbody td {
            vertical-align: middle;
        }
        .row-number {
            font-weight: 600;
            color: var(--brand-blue);
        }
        .table-warning-cell {
            background-color: rgba(255, 205, 0, 0.12);
        }
        .action-cell .btn {
            min-width: 90px;
        }
        .action-cell small {
            display: block;
            margin-top: 6px;
        }
        .note-badge {
            background-color: rgba(255, 205, 0, 0.15);
            color: var(--brand-blue);
            border-radius: 8px;
            padding: 6px 12px;
            font-size: 14px;
        }
        .table thead tr {
            background-color: rgba(255, 205, 0, 0.25);
        }
        .table thead tr th.table-primary {
            background-color: rgba(255, 205, 0, 0.45) !important;
            color: #2b2b2b;
        }
        .info-list li::marker {
            color: var(--brand-blue);
        }
        .required-mark::after {
            content: '＊';
            color: #dc3545;
            margin-left: 2px;
        }
        @media (max-width: 992px) {
            .content-card {
                padding: 20px 16px;
            }
            .table-responsive {
                border-radius: 12px;
            }
        }
    </style>
</head>
<body>
    <?php include 'admin_navbar.php'; ?>

    <div class="container py-4">
        <div class="page-banner mb-4">
            <h1 class="mb-2">新增員工名單</h1>
            <p>可批次輸入多筆新進人員資料，完成後系統將自動建立員工帳號與班別設定。</p>
        </div>

        <div class="content-card">
            <div class="row g-3 align-items-center mb-4">
                <div class="col-lg-8">
                    <div class="note-badge">
                        建議先確認工號與班別設定，避免重複建立。若同筆資料有錯誤會以醒目顏色標註並保留於表格中供修正。
                    </div>
                </div>
                <div class="col-lg-4 text-lg-end text-start">
                    <button type="button" class="btn btn-outline-secondary me-2" id="clearRows">清除表格</button>
                    <button type="button" class="btn btn-primary" id="addRow">新增一列</button>
                </div>
            </div>

            <?php if (!empty($feedback_success)): ?>
                <div class="alert alert-success" role="alert">
                    <strong>新增成功：</strong>
                    <ul class="mb-0 ps-4">
                        <?php foreach ($feedback_success as $msg): ?>
                            <li><?= htmlspecialchars($msg) ?></li>
                        <?php endforeach; ?>
                    </ul>
                </div>
            <?php endif; ?>

            <?php if (!empty($feedback_errors)): ?>
                <div class="alert alert-danger" role="alert">
                    <strong>需要修正：</strong>
                    <ul class="mb-0 ps-4">
                        <?php foreach ($feedback_errors as $msg): ?>
                            <li><?= htmlspecialchars($msg) ?></li>
                        <?php endforeach; ?>
                    </ul>
                </div>
            <?php endif; ?>

            <form method="POST" id="employeeForm">
                <div class="table-responsive">
                    <table class="table table-bordered align-middle text-center">
                        <thead>
                            <tr class="table-primary">
                                <th scope="col" style="width: 80px;">序號</th>
                                <th scope="col" class="required-mark">工號</th>
                                <th scope="col" class="required-mark">使用者帳號</th>
                                <th scope="col" class="required-mark">姓名</th>
                                <th scope="col" class="required-mark">入職日期</th>
                                <th scope="col" class="required-mark">登入密碼</th>
                                <th scope="col" class="required-mark">班別</th>
                                <th scope="col" style="width: 220px;">列工具 / 備註</th>
                            </tr>
                        </thead>
                        <tbody id="employeeRows">
                            <?php foreach ($prefill_rows as $row): ?>
                                <?php $has_error = ($row['status'] === 'error'); ?>
                                <tr class="employee-row <?= $has_error ? 'table-warning-cell' : '' ?>">
                                    <td class="row-number"></td>
                                    <td>
                                        <input type="text" name="employee_number[]" class="form-control" value="<?= htmlspecialchars($row['employee_number']) ?>" placeholder="例如：E24001">
                                    </td>
                                    <td>
                                        <input type="text" name="username[]" class="form-control" value="<?= htmlspecialchars($row['username']) ?>" placeholder="登入帳號">
                                    </td>
                                    <td>
                                        <input type="text" name="name[]" class="form-control" value="<?= htmlspecialchars($row['name']) ?>" placeholder="中文姓名">
                                    </td>
                                    <td>
                                        <input type="date" name="hire_date[]" class="form-control" value="<?= htmlspecialchars($row['hire_date']) ?>">
                                    </td>
                                    <td>
                                        <input type="password" name="password[]" class="form-control" value="">
                                    </td>
                                    <td>
                                        <select name="shift_id[]" class="form-select">
                                            <option value="">請選擇</option>
                                            <?php foreach ($shift_list as $shift): ?>
                                                <?php
                                                    $label = $shift['name'];
                                                    if (!empty($shift['start_time']) && !empty($shift['end_time'])) {
                                                        $label .= '（' . substr($shift['start_time'], 0, 5) . ' - ' . substr($shift['end_time'], 0, 5) . '）';
                                                    }
                                                ?>
                                                <option value="<?= htmlspecialchars($shift['id']) ?>" <?= ($row['shift_id'] !== '' && (int)$row['shift_id'] === (int)$shift['id']) ? 'selected' : '' ?>>
                                                    <?= htmlspecialchars($label) ?>
                                                </option>
                                            <?php endforeach; ?>
                                        </select>
                                    </td>
                                    <td class="action-cell">
                                        <button type="button" class="btn btn-outline-danger btn-sm remove-row">刪除此列</button>
                                        <?php if (!empty($row['error_message'])): ?>
                                            <small class="text-danger d-block mt-2"><?= htmlspecialchars($row['error_message']) ?></small>
                                        <?php else: ?>
                                            <small class="text-muted d-block mt-2">填寫完成即可送出</small>
                                        <?php endif; ?>
                                    </td>
                                </tr>
                            <?php endforeach; ?>
                        </tbody>
                    </table>
                </div>

                <div class="d-flex justify-content-between align-items-center mt-4 flex-column flex-lg-row gap-3">
                    <ul class="info-list mb-0 ps-3">
                        <li>送出後即可在「員工管理 &gt; 員工資料」檢視新建帳號。</li>
                        <li>若同時新增多筆資料，系統會依序處理並回饋成功或錯誤訊息。</li>
                    </ul>
                    <div>
                        <button type="submit" class="btn btn-success btn-lg px-5">送出新增名單</button>
                    </div>
                </div>
            </form>
        </div>
    </div>

    <script>
        // 【JS-1】重新計算序號
        function refreshRowNumber() {
            $('#employeeRows .employee-row').each(function (index) {
                $(this).find('.row-number').text(index + 1);
            });
        }

        // 【JS-2】建立新列樣板
        function createRowTemplate() {
            return $(
                `<tr class="employee-row">
                    <td class="row-number"></td>
                    <td><input type="text" name="employee_number[]" class="form-control" placeholder="例如：E24001"></td>
                    <td><input type="text" name="username[]" class="form-control" placeholder="登入帳號"></td>
                    <td><input type="text" name="name[]" class="form-control" placeholder="中文姓名"></td>
                    <td><input type="date" name="hire_date[]" class="form-control"></td>
                    <td><input type="password" name="password[]" class="form-control"></td>
                    <td>
                        <select name="shift_id[]" class="form-select">
                            <option value="">請選擇</option>
                            <?php foreach ($shift_list as $shift): ?>
                                <?php
                                    $label = $shift['name'];
                                    if (!empty($shift['start_time']) && !empty($shift['end_time'])) {
                                        $label .= '（' . substr($shift['start_time'], 0, 5) . ' - ' . substr($shift['end_time'], 0, 5) . '）';
                                    }
                                ?>
                                <option value="<?= htmlspecialchars($shift['id']) ?>"><?= htmlspecialchars($label) ?></option>
                            <?php endforeach; ?>
                        </select>
                    </td>
                    <td class="action-cell">
                        <button type="button" class="btn btn-outline-danger btn-sm remove-row">刪除此列</button>
                        <small class="text-muted d-block mt-2">填寫完成即可送出</small>
                    </td>
                </tr>`
            );
        }

        // 【JS-3】新增與刪除列事件
        $('#addRow').on('click', function () {
            const newRow = createRowTemplate();
            $('#employeeRows').append(newRow);
            refreshRowNumber();
        });

        $('#employeeRows').on('click', '.remove-row', function () {
            const rows = $('#employeeRows .employee-row');
            if (rows.length <= 1) {
                alert('至少需保留一列以供填寫。');
                return;
            }
            $(this).closest('tr').remove();
            refreshRowNumber();
        });

        // 【JS-4】清除表格並恢復預設列
        $('#clearRows').on('click', function () {
            if (!confirm('確定要清除所有欄位嗎？')) {
                return;
            }
            $('#employeeRows').empty();
            for (let i = 0; i < <?= (int)$minimum_rows ?>; i++) {
                $('#employeeRows').append(createRowTemplate());
            }
            refreshRowNumber();
        });

        // 【JS-5】送出前檢核
        $('#employeeForm').on('submit', function () {
            let hasFilled = false;
            let isValid = true;
            const usedNumbers = {};

            $('#employeeRows .employee-row').each(function (index) {
                const empNo = $(this).find('input[name="employee_number[]"]').val().trim();
                const username = $(this).find('input[name="username[]"]').val().trim();
                const name = $(this).find('input[name="name[]"]').val().trim();
                const hireDate = $(this).find('input[name="hire_date[]"]').val().trim();
                const password = $(this).find('input[name="password[]"]').val();
                const shiftId = $(this).find('select[name="shift_id[]"]').val();

                const isBlank = !empNo && !username && !name && !hireDate && !password && !shiftId;

                if (isBlank) {
                    return;
                }

                hasFilled = true;

                if (!empNo || !username || !name || !hireDate || !password || !shiftId) {
                    alert(`第 ${index + 1} 列尚有未填欄位，請確認。`);
                    isValid = false;
                    return false;
                }

                if (usedNumbers[empNo]) {
                    alert(`第 ${index + 1} 列的工號與第 ${usedNumbers[empNo]} 列重複，請重新輸入。`);
                    isValid = false;
                    return false;
                }
                usedNumbers[empNo] = index + 1;
            });

            if (!isValid) {
                return false;
            }

            if (!hasFilled) {
                return confirm('尚未輸入任何資料，確定仍要送出嗎？');
            }
            return true;
        });

        $(document).ready(function () {
            refreshRowNumber();
        });
    </script>
</body>
</html>