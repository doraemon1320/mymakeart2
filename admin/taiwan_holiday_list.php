<?php
// （1）PHP：初始化與資料庫處理
session_start();

if (!isset($_SESSION['user']) || $_SESSION['user']['role'] !== 'admin') {
    header('Location: ../login.php');
    exit;
}

$conn = new mysqli('localhost', 'root', '', 'mymakeart');
if ($conn->connect_error) {
    die('資料庫連線失敗：' . $conn->connect_error);
}

if ($_SERVER['REQUEST_METHOD'] === 'POST') {
    header('Content-Type: application/json; charset=utf-8');
    $action = $_POST['action'] ?? '';
    $response = ['success' => false, 'message' => '未知的操作'];

    if ($action === 'create') {
        $date = $_POST['holiday_date'] ?? '';
        $description = trim($_POST['description'] ?? '');
        $type = $_POST['type'] ?? 'General';
        $isWorkingDay = isset($_POST['is_working_day']) ? (int)$_POST['is_working_day'] : 0;

        if (!$date || !$description) {
            $response['message'] = '日期與名稱不可空白。';
        } else {
            $stmt = $conn->prepare('SELECT id FROM holidays WHERE holiday_date = ?');
            $stmt->bind_param('s', $date);
            $stmt->execute();
            $stmt->store_result();

            if ($stmt->num_rows > 0) {
                $response['message'] = '此日期已存在，無法重複建立。';
            } else {
                $stmtInsert = $conn->prepare('INSERT INTO holidays (holiday_date, description, type, is_working_day) VALUES (?, ?, ?, ?)');
                $stmtInsert->bind_param('sssi', $date, $description, $type, $isWorkingDay);
                if ($stmtInsert->execute()) {
                    $response = ['success' => true, 'message' => '新增成功。'];
                } else {
                    $response['message'] = '資料庫新增失敗。';
                }
                $stmtInsert->close();
            }
            $stmt->close();
        }
    } elseif ($action === 'update') {
        $id = (int)($_POST['id'] ?? 0);
        $date = $_POST['holiday_date'] ?? '';
        $description = trim($_POST['description'] ?? '');
        $type = $_POST['type'] ?? 'General';
        $isWorkingDay = isset($_POST['is_working_day']) ? (int)$_POST['is_working_day'] : 0;

        if (!$id || !$date || !$description) {
            $response['message'] = '請完整填寫日期與名稱。';
        } else {
            $stmt = $conn->prepare('SELECT id FROM holidays WHERE holiday_date = ? AND id <> ?');
            $stmt->bind_param('si', $date, $id);
            $stmt->execute();
            $stmt->store_result();

            if ($stmt->num_rows > 0) {
                $response['message'] = '此日期已存在於其他紀錄。';
            } else {
                $stmtUpdate = $conn->prepare('UPDATE holidays SET holiday_date = ?, description = ?, type = ?, is_working_day = ? WHERE id = ?');
                $stmtUpdate->bind_param('sssii', $date, $description, $type, $isWorkingDay, $id);
                if ($stmtUpdate->execute()) {
                    $response = ['success' => true, 'message' => '更新成功。'];
                } else {
                    $response['message'] = '資料庫更新失敗。';
                }
                $stmtUpdate->close();
            }
            $stmt->close();
        }
    } elseif ($action === 'delete') {
        $id = (int)($_POST['id'] ?? 0);
        if (!$id) {
            $response['message'] = '缺少要刪除的資料。';
        } else {
            $stmtDelete = $conn->prepare('DELETE FROM holidays WHERE id = ?');
            $stmtDelete->bind_param('i', $id);
            if ($stmtDelete->execute()) {
                $response = ['success' => true, 'message' => '刪除完成。'];
            } else {
                $response['message'] = '資料庫刪除失敗。';
            }
            $stmtDelete->close();
        }
    }

    echo json_encode($response, JSON_UNESCAPED_UNICODE);
    $conn->close();
    exit;
}

$currentYear = (int)date('Y');
$selectedYear = isset($_GET['year']) && is_numeric($_GET['year']) ? (int)$_GET['year'] : $currentYear;
$selectedMonth = $_GET['month'] ?? 'all';
if (!in_array($selectedMonth, array_merge(['all'], array_map('strval', range(1, 12))), true)) {
    $selectedMonth = 'all';
}

$yearOptions = [];
$yearResult = $conn->query("SELECT DISTINCT YEAR(holiday_date) AS year_value FROM holidays ORDER BY year_value DESC");
if ($yearResult) {
    while ($yearRow = $yearResult->fetch_assoc()) {
        $yearOptions[] = (int)$yearRow['year_value'];
    }
    $yearResult->free();
}
if (!in_array($currentYear, $yearOptions, true)) {
    $yearOptions[] = $currentYear;
}
if (!in_array($selectedYear, $yearOptions, true)) {
    $yearOptions[] = $selectedYear;
}
rsort($yearOptions);

$holidays = [];
$query = 'SELECT id, holiday_date, description, type, is_working_day FROM holidays WHERE YEAR(holiday_date) = ?';
$queryParams = [$selectedYear];

if ($selectedMonth !== 'all') {
    $query .= ' AND MONTH(holiday_date) = ?';
    $queryParams[] = (int)$selectedMonth;
}

$query .= ' ORDER BY holiday_date ASC';
$stmtList = $conn->prepare($query);
if ($stmtList) {
    if (count($queryParams) === 2) {
        $stmtList->bind_param('ii', $queryParams[0], $queryParams[1]);
    } else {
        $stmtList->bind_param('i', $queryParams[0]);
    }
    $stmtList->execute();
    $result = $stmtList->get_result();
    if ($result) {
        while ($row = $result->fetch_assoc()) {
            $holidays[] = $row;
        }
        $result->free();
    }
    $stmtList->close();
}
$totalCount = count($holidays);
?>
<!DOCTYPE html>
<html lang="zh-TW">
<head>
    <meta charset="UTF-8">
    <title>台灣假日清單</title>
    <link href="https://cdn.jsdelivr.net/npm/bootstrap@5.3.3/dist/css/bootstrap.min.css" rel="stylesheet">
    <link rel="stylesheet" href="admin_navbar.css">
    <script src="https://code.jquery.com/jquery-3.7.1.min.js"></script>
    <script src="https://cdn.jsdelivr.net/npm/bootstrap@5.3.3/dist/js/bootstrap.bundle.min.js"></script>
    <style>
        :root {
            --brand-yellow: #ffcd00;
            --brand-pink: #e36386;
            --brand-blue: #345d9d;
        }
        body {
            background: #f8f9fb;
        }
        .page-header {
            border-radius: 12px;
            background: linear-gradient(135deg, var(--brand-yellow), var(--brand-pink));
            color: #343a40;
            padding: 24px;
            box-shadow: 0 10px 25px rgba(227, 99, 134, 0.25);
        }
        .page-header h1 {
            font-weight: 700;
            letter-spacing: 1px;
        }
        .page-header p {
            margin-bottom: 0;
            font-size: 1.05rem;
        }
        .badge-holiday {
            background-color: var(--brand-blue);
        }
        .badge-workday {
            background-color: var(--brand-pink);
        }
        .btn-brand {
            background-color: var(--brand-blue);
            color: #fff;
        }
        .btn-brand:hover {
            background-color: #24467a;
            color: #fff;
        }
        .table thead th {
            font-size: 0.95rem;
            text-align: center;
        }
        .table tbody td {
            vertical-align: middle;
        }
        .card-custom {
            border: 1px solid rgba(52, 93, 157, 0.15);
            border-radius: 16px;
        }
        .card-custom .card-header {
            background: linear-gradient(135deg, rgba(52, 93, 157, 0.95), rgba(227, 99, 134, 0.9));
        }
    </style>
</head>
<body>
<?php include 'admin_navbar.php'; ?>
<div class="container py-4">
    <div class="row g-4 align-items-center mb-4">
        <div class="col-12">
            <div class="page-header">
                <h1 class="mb-2">台灣假日清單</h1>
                <p>依年份或月份檢視假日資訊，支援快速新增、調整與刪除資料。</p>
            </div>
        </div>
    </div>

    <div id="feedback" class="alert d-none" role="alert"></div>

    <div class="card card-custom shadow-sm">
        <div class="card-header text-white">
            <div class="row g-3 align-items-center">
                <div class="col-lg-4">
                    <h2 class="h5 mb-1">年度假日資料一覽</h2>
                    <small>共 <?= $totalCount ?> 筆資料</small>
                </div>
                <div class="col-lg-5">
                    <form class="row g-2 align-items-end" method="get">
                        <div class="col-6">
                            <label for="filter_year" class="form-label mb-1">查詢年份</label>
                            <select class="form-select" id="filter_year" name="year">
                                <?php foreach ($yearOptions as $yearOption): ?>
                                    <option value="<?= $yearOption ?>" <?= $yearOption === $selectedYear ? 'selected' : '' ?>><?= $yearOption ?></option>
                                <?php endforeach; ?>
                            </select>
                        </div>
                        <div class="col-6">
                            <label for="filter_month" class="form-label mb-1">查詢月份</label>
                            <select class="form-select" id="filter_month" name="month">
                                <option value="all" <?= $selectedMonth === 'all' ? 'selected' : '' ?>>全年</option>
                                <?php for ($m = 1; $m <= 12; $m++): ?>
                                    <option value="<?= $m ?>" <?= (string)$m === (string)$selectedMonth ? 'selected' : '' ?>><?= $m ?> 月</option>
                                <?php endfor; ?>
                            </select>
                        </div>
                        <div class="col-12 text-end">
                            <button type="submit" class="btn btn-light btn-sm fw-semibold">套用查詢</button>
                        </div>
                    </form>
                </div>
                <div class="col-lg-3 text-lg-end">
                    <button class="btn btn-warning fw-bold" data-bs-toggle="modal" data-bs-target="#createModal">新增假日</button>
                </div>
            </div>
        </div>
        <div class="card-body">
            <div class="table-responsive">
                <table class="table table-bordered table-hover align-middle">
                    <thead class="table-primary">
                        <tr>
                            <th style="width: 15%;">日期</th>
                            <th style="width: 40%;">假日名稱</th>
                            <th style="width: 20%;">性質</th>
                            <th style="width: 25%;">操作</th>
                        </tr>
                    </thead>
                    <tbody>
                        <?php if (empty($holidays)): ?>
                            <tr>
                                <td colspan="4" class="text-center text-muted">尚無符合條件的假日資料，請調整查詢條件或新增資料。</td>
                            </tr>
                        <?php else: ?>
                            <?php foreach ($holidays as $holiday): ?>
                                <tr data-id="<?= (int)$holiday['id'] ?>"
                                    data-date="<?= htmlspecialchars($holiday['holiday_date']) ?>"
                                    data-description="<?= htmlspecialchars($holiday['description']) ?>"
                                    data-type="<?= htmlspecialchars($holiday['type']) ?>"
                                    data-working="<?= (int)$holiday['is_working_day'] ?>">
                                    <td class="text-center fw-semibold"><?= htmlspecialchars($holiday['holiday_date']) ?></td>
                                    <td><?= htmlspecialchars($holiday['description']) ?></td>
                                    <td class="text-center">
                                        <?php if ((int)$holiday['is_working_day'] === 1): ?>
                                            <span class="badge badge-workday">補班日</span>
                                        <?php else: ?>
                                            <span class="badge badge-holiday">放假</span>
                                        <?php endif; ?>
                                    </td>
                                    <td class="text-center">
                                        <div class="d-flex justify-content-center gap-2">
                                            <button class="btn btn-sm btn-brand btn-edit" data-bs-toggle="modal" data-bs-target="#editModal">修改</button>
                                            <button class="btn btn-sm btn-danger btn-delete">刪除</button>
                                        </div>
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

<?php $conn->close(); ?>

<!-- （2）HTML：新增假日表單 -->
<div class="modal fade" id="createModal" tabindex="-1" aria-labelledby="createModalLabel" aria-hidden="true">
    <div class="modal-dialog">
        <div class="modal-content">
            <div class="modal-header" style="background-color: var(--brand-blue);">
                <h5 class="modal-title text-white" id="createModalLabel">新增假日</h5>
                <button type="button" class="btn-close" data-bs-dismiss="modal" aria-label="Close"></button>
            </div>
            <form id="createForm">
                <div class="modal-body">
                    <div class="mb-3">
                        <label for="create_date" class="form-label">日期</label>
                        <input type="date" class="form-control" id="create_date" name="holiday_date" required>
                    </div>
                    <div class="mb-3">
                        <label for="create_description" class="form-label">假日名稱</label>
                        <input type="text" class="form-control" id="create_description" name="description" maxlength="100" required>
                    </div>
                    <input type="hidden" name="type" value="General">
                    <div class="mb-3">
                        <label class="form-label">性質</label>
                        <div class="form-check">
                            <input class="form-check-input" type="radio" name="is_working_day" id="create_holiday" value="0" checked>
                            <label class="form-check-label" for="create_holiday">放假</label>
                        </div>
                        <div class="form-check">
                            <input class="form-check-input" type="radio" name="is_working_day" id="create_workday" value="1">
                            <label class="form-check-label" for="create_workday">補班日</label>
                        </div>
                    </div>
                </div>
                <div class="modal-footer">
                    <button type="button" class="btn btn-secondary" data-bs-dismiss="modal">取消</button>
                    <button type="submit" class="btn btn-brand">儲存</button>
                </div>
            </form>
        </div>
    </div>
</div>

<!-- （3）HTML：修改假日表單 -->
<div class="modal fade" id="editModal" tabindex="-1" aria-labelledby="editModalLabel" aria-hidden="true">
    <div class="modal-dialog">
        <div class="modal-content">
            <div class="modal-header" style="background-color: var(--brand-pink);">
                <h5 class="modal-title text-white" id="editModalLabel">修改假日</h5>
                <button type="button" class="btn-close" data-bs-dismiss="modal" aria-label="Close"></button>
            </div>
            <form id="editForm">
                <input type="hidden" id="edit_id" name="id">
                <div class="modal-body">
                    <div class="mb-3">
                        <label for="edit_date" class="form-label">日期</label>
                        <input type="date" class="form-control" id="edit_date" name="holiday_date" required>
                    </div>
                    <div class="mb-3">
                        <label for="edit_description" class="form-label">假日名稱</label>
                        <input type="text" class="form-control" id="edit_description" name="description" maxlength="100" required>
                    </div>
                    <input type="hidden" id="edit_type" name="type" value="General">
                    <div class="mb-3">
                        <label class="form-label">性質</label>
                        <div class="form-check">
                            <input class="form-check-input" type="radio" name="edit_is_working_day" id="edit_holiday" value="0">
                            <label class="form-check-label" for="edit_holiday">放假</label>
                        </div>
                        <div class="form-check">
                            <input class="form-check-input" type="radio" name="edit_is_working_day" id="edit_workday" value="1">
                            <label class="form-check-label" for="edit_workday">補班日</label>
                        </div>
                    </div>
                </div>
                <div class="modal-footer">
                    <button type="button" class="btn btn-secondary" data-bs-dismiss="modal">取消</button>
                    <button type="submit" class="btn btn-brand">更新</button>
                </div>
            </form>
        </div>
    </div>
</div>

<script>
// （4）JS：資料互動與事件處理
function showFeedback(message, isSuccess = true) {
    const feedback = $('#feedback');
    feedback.removeClass('d-none alert-success alert-danger')
        .addClass(isSuccess ? 'alert-success' : 'alert-danger')
        .text(message)
        .fadeIn();
    setTimeout(() => {
        feedback.fadeOut();
    }, 2600);
}

$('#createForm').on('submit', function (e) {
    e.preventDefault();
    const formData = $(this).serializeArray();
    formData.push({name: 'action', value: 'create'});

    $.post('taiwan_holiday_list.php', formData, function (response) {
        if (response.success) {
            showFeedback(response.message, true);
            setTimeout(() => window.location.reload(), 800);
        } else {
            showFeedback(response.message, false);
        }
    }, 'json');
});

$('.btn-edit').on('click', function () {
    const row = $(this).closest('tr');
    const working = Number(row.data('working'));
    $('#edit_id').val(row.data('id'));
    $('#edit_date').val(row.data('date'));
    $('#edit_description').val(row.data('description'));
    $('#edit_type').val(row.data('type'));
    if (working === 1) {
        $('#edit_workday').prop('checked', true);
    } else {
        $('#edit_holiday').prop('checked', true);
    }
});

$('#editForm').on('submit', function (e) {
    e.preventDefault();
    const payload = {
        action: 'update',
        id: $('#edit_id').val(),
        holiday_date: $('#edit_date').val(),
        description: $('#edit_description').val(),
        type: $('#edit_type').val(),
        is_working_day: $('input[name="edit_is_working_day"]:checked').val()
    };

    $.post('taiwan_holiday_list.php', payload, function (response) {
        if (response.success) {
            showFeedback(response.message, true);
            setTimeout(() => window.location.reload(), 800);
        } else {
            showFeedback(response.message, false);
        }
    }, 'json');
});

$('.btn-delete').on('click', function () {
    if (!confirm('確定要刪除此筆假日資料嗎？')) {
        return;
    }
    const row = $(this).closest('tr');
    const id = row.data('id');

    $.post('taiwan_holiday_list.php', {action: 'delete', id}, function (response) {
        if (response.success) {
            showFeedback(response.message, true);
            setTimeout(() => window.location.reload(), 600);
        } else {
            showFeedback(response.message, false);
        }
    }, 'json');
});
</script>
</body>
</html>