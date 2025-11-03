<?php
session_start();

// 【PHP-1】登入權限檢查
if (!isset($_SESSION['user']) || !isset($_SESSION['user']['role']) || $_SESSION['user']['role'] !== 'admin') {
    header('Location: login.php');
    exit;
}

// 【PHP-2】資料庫連線
$conn = new mysqli('localhost', 'root', '', 'mymakeart');
if ($conn->connect_error) {
    die('資料庫連線失敗：' . $conn->connect_error);
}

$success = '';
$error = '';

// 【PHP-3】班別類型選項設定
$typeOptions = [
    'custom' => '自訂班別',
    'regular' => '一般固定班',
    'flexible' => '彈性彈跳班',
    'night' => '夜間支援班'
];

// 【PHP-4】處理新增班別
if ($_SERVER['REQUEST_METHOD'] === 'POST' && isset($_POST['add_shift'])) {
    $name = trim($_POST['name'] ?? '');
    $start_time = $_POST['start_time'] ?? '';
    $end_time = $_POST['end_time'] ?? '';
    $type = $_POST['type'] ?? 'custom';

    if ($name === '' || $start_time === '' || $end_time === '') {
        $error = '請完整填寫新增班別欄位。';
    } elseif ($start_time >= $end_time) {
        $error = '下班時間必須晚於上班時間。';
    } else {
        $stmt = $conn->prepare('INSERT INTO shifts (name, start_time, end_time, type) VALUES (?, ?, ?, ?)');
        if ($stmt) {
            $stmt->bind_param('ssss', $name, $start_time, $end_time, $type);
            if ($stmt->execute()) {
                $success = '班別新增成功。';
            } else {
                $error = '新增班別失敗，請稍後再試。';
            }
            $stmt->close();
        }
    }
}

// 【PHP-5】處理刪除班別
if ($_SERVER['REQUEST_METHOD'] === 'POST' && isset($_POST['delete_shift'])) {
    $shift_id = (int)($_POST['shift_id'] ?? 0);
    if ($shift_id > 0) {
        $stmt = $conn->prepare('DELETE FROM shifts WHERE id = ?');
        if ($stmt) {
            $stmt->bind_param('i', $shift_id);
            if ($stmt->execute()) {
                $success = '班別刪除成功。';
            } else {
                $error = '刪除班別失敗，請稍後再試。';
            }
            $stmt->close();
        }
    }
}

// 【PHP-6】處理更新班別
if ($_SERVER['REQUEST_METHOD'] === 'POST' && isset($_POST['edit_shift'])) {
    $shift_id = (int)($_POST['shift_id'] ?? 0);
    $name = trim($_POST['name'] ?? '');
    $start_time = $_POST['start_time'] ?? '';
    $end_time = $_POST['end_time'] ?? '';
    $type = $_POST['type'] ?? 'custom';

    if ($shift_id <= 0 || $name === '' || $start_time === '' || $end_time === '') {
        $error = '更新失敗，請確認欄位是否完整。';
    } elseif ($start_time >= $end_time) {
        $error = '更新失敗，下班時間必須晚於上班時間。';
    } else {
        $stmt = $conn->prepare('UPDATE shifts SET name = ?, start_time = ?, end_time = ?, type = ? WHERE id = ?');
        if ($stmt) {
            $stmt->bind_param('ssssi', $name, $start_time, $end_time, $type, $shift_id);
            if ($stmt->execute()) {
                $success = '班別更新成功。';
            } else {
                $error = '更新班別失敗，請稍後再試。';
            }
            $stmt->close();
        }
    }
}

// 【PHP-7】取得班別列表
$shiftRows = [];
$shifts = $conn->query('SELECT * FROM shifts ORDER BY start_time ASC');
if ($shifts) {
    while ($row = $shifts->fetch_assoc()) {
        $shiftRows[] = $row;
    }
    $shifts->free();
}

$conn->close();
?>
<!DOCTYPE html>
<html lang="zh-TW">
<head>
    <meta charset="UTF-8">
    <meta name="viewport" content="width=device-width, initial-scale=1.0">
    <title>班別設定</title>
    <link href="https://cdn.jsdelivr.net/npm/bootstrap@5.3.3/dist/css/bootstrap.min.css" rel="stylesheet">
    <link rel="stylesheet" href="https://cdn.jsdelivr.net/npm/bootstrap-icons@1.11.3/font/bootstrap-icons.css">
    <link rel="stylesheet" href="admin_navbar.css">
    <style>
        :root {
            --brand-gold: #ffcd00;
            --brand-rose: #e36386;
            --brand-blue: #345d9d;
            --brand-blue-dark: #1f3f74;
        }
        body {
            background: linear-gradient(180deg, rgba(255, 205, 0, 0.08) 0%, rgba(227, 99, 134, 0.12) 40%, rgba(52, 93, 157, 0.08) 100%);
            font-family: 'Noto Sans TC', 'Microsoft JhengHei', sans-serif;
        }
        .page-wrapper {
            padding: 32px 0 48px;
        }
        .section-title {
            font-weight: 700;
            color: var(--brand-blue-dark);
            letter-spacing: 0.06em;
        }
        .section-subtitle {
            color: rgba(33, 37, 41, 0.7);
        }
        .card-brand {
            border: none;
            border-radius: 18px;
            box-shadow: 0 18px 34px rgba(31, 63, 116, 0.12);
            overflow: hidden;
        }
        .card-brand .card-header {
            border: none;
            background: linear-gradient(135deg, rgba(52, 93, 157, 0.92), rgba(227, 99, 134, 0.85));
            color: #ffffff;
            font-weight: 700;
            letter-spacing: 0.08em;
        }
        .card-brand .card-body {
            padding: 24px;
            background: #ffffff;
        }
        .btn-brand {
            background: linear-gradient(90deg, var(--brand-blue), var(--brand-rose));
            border: none;
            color: #ffffff;
            font-weight: 600;
            letter-spacing: 0.05em;
        }
        .btn-brand:hover {
            background: linear-gradient(90deg, var(--brand-blue-dark), var(--brand-rose));
            color: #ffffff;
        }
        .badge-type {
            padding: 0.45em 0.75em;
            border-radius: 999px;
            font-size: 0.85rem;
            font-weight: 600;
        }
        .table > :not(caption) > * > * {
            padding: 0.9rem 0.75rem;
        }
        .table tbody tr {
            transition: transform 0.2s ease, box-shadow 0.2s ease;
        }
        .table tbody tr:hover {
            transform: translateY(-2px);
            box-shadow: inset 0 0 0 1px rgba(52, 93, 157, 0.18);
        }
        .filter-note {
            background: rgba(255, 205, 0, 0.12);
            border-left: 4px solid var(--brand-gold);
            padding: 12px 16px;
            border-radius: 10px;
            color: rgba(33, 37, 41, 0.75);
        }
        .form-label {
            font-weight: 600;
            color: var(--brand-blue-dark);
        }
        .form-control:focus,
        .form-select:focus {
            border-color: var(--brand-blue);
            box-shadow: 0 0 0 .2rem rgba(52, 93, 157, 0.25);
        }
        .empty-row {
            text-align: center;
            padding: 60px 0;
            color: rgba(33, 37, 41, 0.6);
            font-size: 1.05rem;
        }
        .modal-header {
            background: var(--brand-blue);
            color: #ffffff;
        }
        .modal-footer .btn {
            font-weight: 600;
        }
    </style>
</head>
<body>
<?php include 'admin_navbar.php'; ?>
<main class="page-wrapper">
    <div class="container">
        <div class="row align-items-center mb-4 g-3">
            <div class="col-lg-8">
                <h1 class="section-title mb-2">班別設定</h1>
                <p class="section-subtitle mb-0">統一管理各班別資訊，協助排班與打卡流程更順暢。</p>
            </div>
            <div class="col-lg-4 text-lg-end">
                <div class="filter-note d-inline-flex align-items-center gap-2">
                    <i class="bi bi-info-circle-fill text-warning"></i>
                    <span>班別調整後即時生效，請通知相關同仁。</span>
                </div>
            </div>
        </div>

        <?php if ($success !== ''): ?>
            <div class="alert alert-success alert-dismissible fade show" role="alert">
                <i class="bi bi-check-circle-fill me-2"></i><?= htmlspecialchars($success) ?>
                <button type="button" class="btn-close" data-bs-dismiss="alert" aria-label="關閉"></button>
            </div>
        <?php elseif ($error !== ''): ?>
            <div class="alert alert-danger alert-dismissible fade show" role="alert">
                <i class="bi bi-exclamation-triangle-fill me-2"></i><?= htmlspecialchars($error) ?>
                <button type="button" class="btn-close" data-bs-dismiss="alert" aria-label="關閉"></button>
            </div>
        <?php endif; ?>

        <div class="row g-4 align-items-stretch">
            <div class="col-lg-4">
                <div class="card card-brand h-100">
                    <div class="card-header">
                        <i class="bi bi-plus-circle me-2"></i>新增班別
                    </div>
                    <div class="card-body">
                        <form method="POST" action="" class="row g-3" id="createShiftForm">
                            <div class="col-12">
                                <label for="name" class="form-label">班別名稱</label>
                                <input type="text" id="name" name="name" class="form-control" placeholder="例：早班" required>
                            </div>
                            <div class="col-md-6">
                                <label for="start_time" class="form-label">上班時間</label>
                                <input type="time" id="start_time" name="start_time" class="form-control" required>
                            </div>
                            <div class="col-md-6">
                                <label for="end_time" class="form-label">下班時間</label>
                                <input type="time" id="end_time" name="end_time" class="form-control" required>
                            </div>
                            <div class="col-12">
                                <label for="type" class="form-label">班別類型</label>
                                <select id="type" name="type" class="form-select" required>
                                    <?php foreach ($typeOptions as $value => $label): ?>
                                        <option value="<?= htmlspecialchars($value) ?>" <?= $value === 'custom' ? 'selected' : '' ?>><?= htmlspecialchars($label) ?></option>
                                    <?php endforeach; ?>
                                </select>
                            </div>
                            <div class="col-12">
                                <div class="form-text text-muted">若班別時間跨日，請以隔日時間為主並向人資說明。</div>
                            </div>
                            <div class="col-12 d-grid">
                                <button type="submit" name="add_shift" class="btn btn-brand btn-lg">送出新增</button>
                            </div>
                        </form>
                    </div>
                </div>
            </div>

            <div class="col-lg-8">
                <div class="card card-brand h-100">
                    <div class="card-header">
                        <i class="bi bi-calendar-week me-2"></i>班別列表
                    </div>
                    <div class="card-body">
                        <div class="row g-3 align-items-center mb-3">
                            <div class="col-md-8">
                                <div class="input-group">
                                    <span class="input-group-text bg-white"><i class="bi bi-search"></i></span>
                                    <input type="text" id="shiftKeyword" class="form-control" placeholder="輸入班別名稱或類型關鍵字">
                                </div>
                            </div>
                            <div class="col-md-4 text-md-end">
                                <span class="text-muted small">目前共有 <strong><?= count($shiftRows) ?></strong> 組班別。</span>
                            </div>
                        </div>

                        <div class="table-responsive">
                            <table class="table table-bordered table-hover align-middle mb-0">
                                <thead class="table-primary">
                                    <tr>
                                        <th class="text-center" style="width: 12%;">#</th>
                                        <th>班別名稱</th>
                                        <th style="width: 18%;">上班時間</th>
                                        <th style="width: 18%;">下班時間</th>
                                        <th style="width: 16%;">類型</th>
                                        <th style="width: 16%;" class="text-center">操作</th>
                                    </tr>
                                </thead>
                                <tbody id="shiftTableBody">
                                <?php if (count($shiftRows) === 0): ?>
                                    <tr>
                                        <td colspan="6" class="empty-row">尚未建立任何班別，請從左側新增。</td>
                                    </tr>
                                <?php else: ?>
                                    <?php foreach ($shiftRows as $index => $shift): ?>
                                        <tr data-shift-name="<?= htmlspecialchars($shift['name']) ?>" data-shift-type="<?= htmlspecialchars($shift['type']) ?>">
                                            <td class="text-center fw-semibold"><?= $index + 1 ?></td>
                                            <td class="fw-semibold text-primary"><?= htmlspecialchars($shift['name']) ?></td>
                                            <td><?= htmlspecialchars(substr($shift['start_time'], 0, 5)) ?></td>
                                            <td><?= htmlspecialchars(substr($shift['end_time'], 0, 5)) ?></td>
                                            <td>
                                                <?php
                                                    $label = $typeOptions[$shift['type']] ?? ('自訂：' . $shift['type']);
                                                    $badgeClass = 'bg-info-subtle text-info';
                                                    switch ($shift['type']) {
                                                        case 'regular':
                                                            $badgeClass = 'bg-primary-subtle text-primary';
                                                            break;
                                                        case 'flexible':
                                                            $badgeClass = 'bg-warning-subtle text-warning';
                                                            break;
                                                        case 'night':
                                                            $badgeClass = 'bg-dark text-white';
                                                            break;
                                                    }
                                                ?>
                                                <span class="badge badge-type <?= $badgeClass ?>"><?= htmlspecialchars($label) ?></span>
                                            </td>
                                            <td class="text-center">
                                                <div class="d-flex justify-content-center gap-2">
                                                    <button type="button"
                                                            class="btn btn-outline-primary btn-sm edit-shift-btn"
                                                            data-bs-toggle="modal"
                                                            data-bs-target="#editShiftModal"
                                                            data-id="<?= (int)$shift['id'] ?>"
                                                            data-name="<?= htmlspecialchars($shift['name'], ENT_QUOTES) ?>"
                                                            data-start="<?= htmlspecialchars(substr($shift['start_time'], 0, 5)) ?>"
                                                            data-end="<?= htmlspecialchars(substr($shift['end_time'], 0, 5)) ?>"
                                                            data-type="<?= htmlspecialchars($shift['type'], ENT_QUOTES) ?>">
                                                        <i class="bi bi-pencil-square me-1"></i>編輯
                                                    </button>
                                                    <form method="POST" action="" onsubmit="return confirm('確定要刪除這個班別嗎？');">
                                                        <input type="hidden" name="shift_id" value="<?= (int)$shift['id'] ?>">
                                                        <button type="submit" name="delete_shift" class="btn btn-outline-danger btn-sm">
                                                            <i class="bi bi-trash me-1"></i>刪除
                                                        </button>
                                                    </form>
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
        </div>
    </div>
</main>

<!-- 編輯班別 Modal -->
<div class="modal fade" id="editShiftModal" tabindex="-1" aria-labelledby="editShiftModalLabel" aria-hidden="true">
    <div class="modal-dialog modal-dialog-centered">
        <div class="modal-content">
            <div class="modal-header">
                <h5 class="modal-title" id="editShiftModalLabel"><i class="bi bi-pencil-square me-2"></i>編輯班別</h5>
                <button type="button" class="btn-close btn-close-white" data-bs-dismiss="modal" aria-label="關閉"></button>
            </div>
            <form method="POST" action="" id="editShiftForm">
                <div class="modal-body">
                    <input type="hidden" name="shift_id" id="edit_shift_id">
                    <div class="mb-3">
                        <label for="edit_name" class="form-label">班別名稱</label>
                        <input type="text" class="form-control" id="edit_name" name="name" required>
                    </div>
                    <div class="row g-3">
                        <div class="col-md-6">
                            <label for="edit_start_time" class="form-label">上班時間</label>
                            <input type="time" class="form-control" id="edit_start_time" name="start_time" required>
                        </div>
                        <div class="col-md-6">
                            <label for="edit_end_time" class="form-label">下班時間</label>
                            <input type="time" class="form-control" id="edit_end_time" name="end_time" required>
                        </div>
                    </div>
                    <div class="mt-3">
                        <label for="edit_type" class="form-label">班別類型</label>
                        <select class="form-select" id="edit_type" name="type" required>
                            <?php foreach ($typeOptions as $value => $label): ?>
                                <option value="<?= htmlspecialchars($value) ?>"><?= htmlspecialchars($label) ?></option>
                            <?php endforeach; ?>
                        </select>
                    </div>
                </div>
                <div class="modal-footer">
                    <button type="button" class="btn btn-outline-secondary" data-bs-dismiss="modal">取消</button>
                    <button type="submit" name="edit_shift" class="btn btn-brand">儲存變更</button>
                </div>
            </form>
        </div>
    </div>
</div>

<script src="https://code.jquery.com/jquery-3.7.1.min.js"></script>
<script src="https://cdn.jsdelivr.net/npm/bootstrap@5.3.3/dist/js/bootstrap.bundle.min.js"></script>
<script>
// 【JS-1】班別時間驗證函式
function validateTimeRange(start, end) {
    return start && end && start < end;
}

// 【JS-2】新增班別表單即時檢查
$(document).on('change', '#createShiftForm input[type="time"]', function () {
    const start = $('#start_time').val();
    const end = $('#end_time').val();
    const isValid = validateTimeRange(start, end);
    $('#end_time')[0].setCustomValidity(isValid ? '' : '下班時間必須晚於上班時間。');
    if (!isValid) {
        $('#end_time').addClass('is-invalid');
        if (!$('#createShiftForm .invalid-feedback').length) {
            $('#end_time').after('<div class="invalid-feedback">下班時間必須晚於上班時間。</div>');
        }
    } else {
        $('#end_time').removeClass('is-invalid');
        $('#createShiftForm .invalid-feedback').remove();
    }
});

// 【JS-3】表格關鍵字搜尋過濾
$(document).on('input', '#shiftKeyword', function () {
    const keyword = $(this).val().toLowerCase();
    $('#shiftTableBody tr').each(function () {
        const name = ($(this).data('shift-name') || '').toString().toLowerCase();
        const type = ($(this).data('shift-type') || '').toString().toLowerCase();
        const matched = keyword === '' || name.includes(keyword) || type.includes(keyword);
        $(this).toggle(matched);
    });
});

// 【JS-4】開啟編輯視窗帶入資料
$('#editShiftModal').on('show.bs.modal', function (event) {
    const button = $(event.relatedTarget);
    const id = button.data('id');
    const name = button.data('name');
    const start = button.data('start');
    const end = button.data('end');
    const type = button.data('type');

    $('#edit_shift_id').val(id);
    $('#edit_name').val(name);
    $('#edit_start_time').val(start);
    $('#edit_end_time').val(end);
    $('#edit_type').val(type);
});

// 【JS-5】編輯表單時間檢查
$(document).on('change', '#editShiftForm input[type="time"]', function () {
    const start = $('#edit_start_time').val();
    const end = $('#edit_end_time').val();
    if (!validateTimeRange(start, end)) {
        $('#edit_end_time')[0].setCustomValidity('下班時間必須晚於上班時間。');
    } else {
        $('#edit_end_time')[0].setCustomValidity('');
    }
});
</script>
</body>
</html>