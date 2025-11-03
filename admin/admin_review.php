<?php
session_start();

// PHP 功能 1：權限檢查，僅允許管理者進入
if (!isset($_SESSION['user']) || ($_SESSION['user']['role'] ?? '') !== 'admin') {
    header('Location: ../login.php');
    exit;
}

// PHP 功能 2：建立資料庫連線
$conn = new mysqli('localhost', 'root', '', 'mymakeart');
if ($conn->connect_error) {
    die('資料庫連接失敗：' . $conn->connect_error);
}

// PHP 功能 3：統一處理狀態格式
function normalize_status(string $status): string
{
    $status = strtolower(trim($status));
    return match ($status) {
        'approved' => 'Approved',
        'rejected' => 'Rejected',
        default => 'Pending',
    };
}

// PHP 功能 4：計算請假時數（以小時計）
function calculate_leave_hours(?string $start, ?string $end): float
{
    if (empty($start) || empty($end)) {
        return 0.0;
    }

    $start_ts = strtotime($start);
    $end_ts   = strtotime($end);
    if ($start_ts === false || $end_ts === false || $end_ts <= $start_ts) {
        return 0.0;
    }

    $hours = ($end_ts - $start_ts) / 3600;
    return round(max($hours, 0), 2);
}

// PHP 功能 5：核准時建立特休扣除紀錄
function apply_annual_leave_deduction(mysqli $conn, array $request): void
{
    if (($request['type'] ?? '') !== '請假' || ($request['subtype'] ?? '') !== '特休假') {
        return;
    }

    $note = 'request:' . ($request['id'] ?? 0);
    $check_stmt = $conn->prepare('SELECT COUNT(*) AS cnt FROM annual_leave_records WHERE note = ?');
    $check_stmt->bind_param('s', $note);
    $check_stmt->execute();
    $exists = (int)($check_stmt->get_result()->fetch_assoc()['cnt'] ?? 0) > 0;
    $check_stmt->close();

    if ($exists) {
        return;
    }

    $hours = calculate_leave_hours($request['start_date'] ?? null, $request['end_date'] ?? null);
    if ($hours <= 0) {
        return;
    }

    $employee_id = (int)($request['employee_id'] ?? 0);
    if ($employee_id <= 0) {
        return;
    }

    $start_time = strtotime($request['start_date'] ?? '');
    if ($start_time === false) {
        return;
    }

    $year  = (int)date('Y', $start_time);
    $month = (int)date('n', $start_time);
    $day   = (int)date('j', $start_time);

    $days_value  = 0.0; // 以小時記錄
    $hours_value = $hours;

    $insert_stmt = $conn->prepare(
        'INSERT INTO annual_leave_records (employee_id, year, month, day, days, status, note, created_at, hours)
         VALUES (?, ?, ?, ?, ?, "使用", ?, NOW(), ?)'
    );
    $insert_stmt->bind_param('iiiidsd', $employee_id, $year, $month, $day, $days_value, $note, $hours_value);
    $insert_stmt->execute();
    $insert_stmt->close();
}

// PHP 功能 6：拒絕已核准紀錄時刪除特休扣除（歸還）
function restore_annual_leave_deduction(mysqli $conn, int $request_id): void
{
    $note = 'request:' . $request_id;
    $delete_stmt = $conn->prepare('DELETE FROM annual_leave_records WHERE note = ?');
    $delete_stmt->bind_param('s', $note);
    $delete_stmt->execute();
    $delete_stmt->close();
}

$success = '';
$error = '';
$filter_employee = trim($_GET['employee'] ?? '');
$filter_status   = trim($_GET['status'] ?? '');
$filter_type     = trim($_GET['type'] ?? '');

// PHP 功能 7：整理前端可用的篩選條件
$status_map = [
    'Pending' => '審查中',
    'Approved' => '批准',
    'Rejected' => '拒絕'
];

if ($filter_status !== '') {
    $filter_status = normalize_status($filter_status);
    if (!isset($status_map[$filter_status])) {
        $filter_status = '';
    }
}

$employee_options = [];
$employee_result = $conn->query(
    'SELECT DISTINCT COALESCE(e.name, "") AS name
     FROM requests r
     LEFT JOIN employees e ON r.employee_number = e.employee_number
     WHERE COALESCE(e.name, "") <> ""
     ORDER BY name ASC'
);
if ($employee_result) {
    while ($row = $employee_result->fetch_assoc()) {
        $employee_options[] = $row['name'];
    }
    $employee_result->close();
}

$type_options = [];
$type_result = $conn->query(
    'SELECT DISTINCT r.type AS type_value
     FROM requests r
     WHERE COALESCE(r.type, "") <> ""
     ORDER BY r.type ASC'
);
if ($type_result) {
    while ($row = $type_result->fetch_assoc()) {
        $type_options[] = $row['type_value'];
    }
    $type_result->close();
}

// PHP 功能 8：處理審核表單送出
if ($_SERVER['REQUEST_METHOD'] === 'POST' && isset($_POST['request_id'], $_POST['status'])) {
    $request_id = (int)$_POST['request_id'];
    $new_status = normalize_status($_POST['status']);

    if (!in_array($new_status, ['Approved', 'Rejected'], true)) {
        $error = '無效的狀態操作。';
    } else {
        $detail_stmt = $conn->prepare(
            'SELECT r.*, e.name AS employee_name FROM requests r
             LEFT JOIN employees e ON r.employee_number = e.employee_number
             WHERE r.id = ?'
        );
        $detail_stmt->bind_param('i', $request_id);
        $detail_stmt->execute();
        $request_info = $detail_stmt->get_result()->fetch_assoc();
        $detail_stmt->close();

        if (!$request_info) {
            $error = '找不到對應的申請紀錄。';
        } else {
            $original_status = normalize_status($request_info['status'] ?? 'Pending');

            $update_stmt = $conn->prepare('UPDATE requests SET status = ? WHERE id = ?');
            $update_stmt->bind_param('si', $new_status, $request_id);

            if ($update_stmt->execute()) {
                $employee_number = $request_info['employee_number'] ?? '';
                if ($employee_number !== '') {
                    $message = '您的申請已被' . ($new_status === 'Approved' ? '批准' : '拒絕');
                    $notify_stmt = $conn->prepare('INSERT INTO notifications (employee_number, message) VALUES (?, ?)');
                    $notify_stmt->bind_param('ss', $employee_number, $message);
                    $notify_stmt->execute();
                    $notify_stmt->close();
                }

                if ($new_status === 'Approved' && $original_status !== 'Approved') {
                    apply_annual_leave_deduction($conn, $request_info);
                }

                if ($new_status === 'Rejected' && $original_status === 'Approved') {
                    restore_annual_leave_deduction($conn, $request_id);
                }

                $success = '申請狀態已成功更新！';
            } else {
                $error = '更新申請狀態時發生錯誤，請稍後再試。';
            }
            $update_stmt->close();
        }
    }
}

// PHP 功能 9：依篩選條件撈取申請資料
$base_sql = 'SELECT r.id, r.type, r.subtype, r.reason, r.status, r.start_date, r.end_date, r.created_at,
                    r.employee_id, r.employee_number, e.name
             FROM requests r
             LEFT JOIN employees e ON r.employee_number = e.employee_number
             WHERE 1=1';
$bind_types = '';
$bind_params = [];

if ($filter_employee !== '') {
    $base_sql .= ' AND COALESCE(e.name, "") = ?';
    $bind_types .= 's';
    $bind_params[] = $filter_employee;
}

if ($filter_status !== '') {
    $base_sql .= ' AND r.status = ?';
    $bind_types .= 's';
    $bind_params[] = $filter_status;
}

if ($filter_type !== '') {
    $base_sql .= ' AND COALESCE(r.type, "") = ?';
    $bind_types .= 's';
    $bind_params[] = $filter_type;
}

$base_sql .= ' ORDER BY r.created_at DESC';

$requests_stmt = $conn->prepare($base_sql);
if ($requests_stmt === false) {
    die('查詢申請資料時發生錯誤：' . $conn->error);
}

if ($bind_types !== '') {
    $requests_stmt->bind_param($bind_types, ...$bind_params);
}

$requests_stmt->execute();
$requests = $requests_stmt->get_result();
$requests_stmt->close();
?>
<!DOCTYPE html>
<html lang="zh-TW">
<head>
    <meta charset="UTF-8">
    <title>審核申請</title>
    <link rel="stylesheet" href="https://cdn.jsdelivr.net/npm/bootstrap@5.3.3/dist/css/bootstrap.min.css">
    <script src="https://code.jquery.com/jquery-3.7.1.min.js"></script>
    <link rel="stylesheet" href="admin_navbar.css">
</head>
<body class="bg-light">
    <?php include 'admin_navbar.php'; ?>

    <div class="container py-4">
        <div class="row gy-4">
            <div class="col-12">
                <div class="p-4 p-md-5 rounded-4 shadow-sm text-white" style="background: linear-gradient(135deg, #345D9D 0%, #E36386 55%, #FFCD00 100%);">
                    <h2 class="fw-bold mb-1">審核管理中心</h2>
                    <p class="mb-0">快速掌握加班、假期與特休申請狀態，維持團隊流程一致。</p>
                </div>
            </div>

            <?php if ($success || $error): ?>
                <div class="col-12">
                    <?php if ($success): ?>
                        <div class="alert alert-success mb-0"><?= htmlspecialchars($success) ?></div>
                    <?php else: ?>
                        <div class="alert alert-danger mb-0"><?= htmlspecialchars($error) ?></div>
                    <?php endif; ?>
                </div>
            <?php endif; ?>

            <div class="col-12">
                <div class="card border-0 shadow-sm">
                    <div class="card-header border-0" style="background-color: rgba(52, 93, 157, 0.12);">
                        <h5 class="mb-0 fw-bold" style="color: #345D9D;">申請篩選</h5>
                    </div>
                    <div class="card-body">
                        <form method="GET" class="row g-3 align-items-end">
                            <div class="col-md-4 col-lg-3">
                                <label for="filterEmployee" class="form-label fw-bold text-secondary">依員工</label>
                                <select id="filterEmployee" name="employee" class="form-select">
                                    <option value="">全部員工</option>
                                    <?php foreach ($employee_options as $name): ?>
                                        <option value="<?= htmlspecialchars($name) ?>" <?= $filter_employee === $name ? 'selected' : '' ?>><?= htmlspecialchars($name) ?></option>
                                    <?php endforeach; ?>
                                </select>
                            </div>
                            <div class="col-md-4 col-lg-3">
                                <label for="filterStatus" class="form-label fw-bold text-secondary">依狀態</label>
                                <select id="filterStatus" name="status" class="form-select">
                                    <option value="">全部狀態</option>
                                    <?php foreach ($status_map as $value => $label): ?>
                                        <option value="<?= $value ?>" <?= $filter_status === $value ? 'selected' : '' ?>><?= $label ?></option>
                                    <?php endforeach; ?>
                                </select>
                            </div>
                            <div class="col-md-4 col-lg-3">
                                <label for="filterType" class="form-label fw-bold text-secondary">依類型</label>
                                <select id="filterType" name="type" class="form-select">
                                    <option value="">全部類型</option>
                                    <?php foreach ($type_options as $type_item): ?>
                                        <option value="<?= htmlspecialchars($type_item) ?>" <?= $filter_type === $type_item ? 'selected' : '' ?>><?= htmlspecialchars($type_item) ?></option>
                                    <?php endforeach; ?>
                                </select>
                            </div>
                            <div class="col-md-12 col-lg-3 d-flex gap-2">
                                <button type="submit" class="btn w-100 text-white" style="background-color: #345D9D;">
                                    套用篩選
                                </button>
                                <a href="admin_review.php" class="btn btn-outline-secondary w-100">
                                    清除篩選
                                </a>
                            </div>
                        </form>
                    </div>
                </div>
            </div>

            <div class="col-12">
                <div class="table-responsive shadow-sm">
                    <table class="table table-bordered table-hover align-middle mb-0">
                        <thead class="table-primary text-center align-middle">
                            <tr>
                                <th scope="col">姓名</th>
                                <th scope="col">類型</th>
                                <th scope="col">假別</th>
                                <th scope="col" class="w-25">理由</th>
                                <th scope="col">起始時間</th>
                                <th scope="col">結束時間</th>
                                <th scope="col">狀態</th>
                                <th scope="col">操作</th>
                            </tr>
                        </thead>
                        <tbody>
                        <?php if ($requests && $requests->num_rows > 0): ?>
                            <?php while ($row = $requests->fetch_assoc()): ?>
                                <?php $normalized_status = normalize_status($row['status'] ?? 'Pending'); ?>
                                <tr>
                                    <td class="text-center fw-semibold"><?= htmlspecialchars($row['name'] ?? '—') ?></td>
                                    <td class="text-center"><?= htmlspecialchars($row['type'] ?? '—') ?></td>
                                    <td class="text-center"><?= htmlspecialchars($row['subtype'] ?? '—') ?></td>
                                    <td class="text-start text-break"><?= htmlspecialchars($row['reason'] ?? '') ?></td>
                                    <td class="text-center"><?= htmlspecialchars($row['start_date'] ?? '') ?></td>
                                    <td class="text-center"><?= htmlspecialchars($row['end_date'] ?? '') ?></td>
                                    <td class="text-center">
                                        <?php if ($normalized_status === 'Pending'): ?>
                                            <span class="badge rounded-pill px-3 py-2" style="background-color: #FFCD00; color: #343a40;">待審核</span>
                                        <?php elseif ($normalized_status === 'Approved'): ?>
                                            <span class="badge rounded-pill px-3 py-2" style="background-color: #345D9D;">已批准</span>
                                        <?php else: ?>
                                            <span class="badge rounded-pill px-3 py-2" style="background-color: #E36386;">已拒絕</span>
                                        <?php endif; ?>
                                    </td>
                                    <td class="text-center">
                                        <?php if ($normalized_status === 'Pending'): ?>
                                            <form method="POST" class="d-flex flex-wrap justify-content-center gap-2">
                                                <input type="hidden" name="request_id" value="<?= (int)($row['id'] ?? 0) ?>">
                                                <button type="submit" name="status" value="Approved" class="btn btn-sm text-white px-3 js-review-submit" data-action="批准" style="background-color: #345D9D; border-color: #345D9D;">批准</button>
                                                <button type="submit" name="status" value="Rejected" class="btn btn-sm text-white px-3 js-review-submit" data-action="拒絕" style="background-color: #E36386; border-color: #E36386;">拒絕</button>
                                            </form>
                                        <?php else: ?>
                                            <form method="GET" action="update_status.php" class="d-inline-flex">
                                                <input type="hidden" name="request_id" value="<?= (int)($row['id'] ?? 0) ?>">
                                                <button type="submit" class="btn btn-outline-primary btn-sm px-3">更正</button>
                                            </form>
                                        <?php endif; ?>
                                    </td>
                                </tr>
                            <?php endwhile; ?>
                        <?php else: ?>
                            <tr>
                                <td colspan="8" class="text-center text-muted py-4">目前沒有符合條件的申請資料。</td>
                            </tr>
                        <?php endif; ?>
                        </tbody>
                    </table>
                </div>
            </div>
        </div>
    </div>

    <script src="https://cdn.jsdelivr.net/npm/bootstrap@5.3.3/dist/js/bootstrap.bundle.min.js"></script>
    <script>
        // JS 功能 1：送出前再次確認，避免誤操作
        $(document).on('click', '.js-review-submit', function (event) {
            const actionText = $(this).data('action') || '處理';
            if (!confirm(`確定要${actionText}這筆申請嗎？`)) {
                event.preventDefault();
            }
        });
    </script>
</body>
</html>