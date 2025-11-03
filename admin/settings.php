<?php
session_start();

// ✅【PHP-1】登入權限檢查
if (!isset($_SESSION['user']) || $_SESSION['user']['role'] !== 'admin') {
    header('Location: ../login.php');
    exit;
}

// ✅【PHP-2】資料庫連線
$conn = new mysqli('localhost', 'root', '', 'mymakeart');
if ($conn->connect_error) {
    die('資料庫連線失敗：' . $conn->connect_error);
}

// ✅【PHP-3】表單處理：假別與特休假設定
if ($_SERVER['REQUEST_METHOD'] === 'POST') {
    if (isset($_POST['add_leave'])) {
        $name = trim($_POST['name']);
        $days_per_year = intval($_POST['days_per_year']);
        $salary_ratio = floatval($_POST['salary_ratio']);
        $eligibility = trim($_POST['eligibility']);
        $affect_attendance = isset($_POST['affect_attendance']) ? 1 : 0;
        $notes = trim($_POST['notes']);

        $stmt = $conn->prepare('INSERT INTO leave_types (name, days_per_year, salary_ratio, eligibility, affect_attendance, notes) VALUES (?, ?, ?, ?, ?, ?)');
        $stmt->bind_param('sidsis', $name, $days_per_year, $salary_ratio, $eligibility, $affect_attendance, $notes);
        $stmt->execute();
        $stmt->close();
    } elseif (isset($_POST['edit_leave'])) {
        $leave_id = intval($_POST['leave_id']);
        $edit_name = trim($_POST['edit_name']);
        $edit_days_per_year = intval($_POST['edit_days_per_year']);
        $edit_salary_ratio = floatval($_POST['edit_salary_ratio']);
        $edit_eligibility = trim($_POST['edit_eligibility']);
        $edit_affect_attendance = isset($_POST['edit_affect_attendance']) ? 1 : 0;
        $edit_notes = trim($_POST['edit_notes']);

        $stmt = $conn->prepare('UPDATE leave_types SET name = ?, days_per_year = ?, salary_ratio = ?, eligibility = ?, affect_attendance = ?, notes = ? WHERE id = ?');
        $stmt->bind_param('sidsisi', $edit_name, $edit_days_per_year, $edit_salary_ratio, $edit_eligibility, $edit_affect_attendance, $edit_notes, $leave_id);
        $stmt->execute();
        $stmt->close();
    } elseif (isset($_POST['delete_leave'])) {
        $leave_id = intval($_POST['leave_id']);
        $stmt = $conn->prepare('DELETE FROM leave_types WHERE id = ?');
        $stmt->bind_param('i', $leave_id);
        $stmt->execute();
        $stmt->close();
    } elseif (isset($_POST['add_annual_leave'])) {
        $years_of_service = floatval($_POST['years_of_service']);
        $days = intval($_POST['days']);

        $stmt = $conn->prepare('INSERT INTO annual_leave_policy (years_of_service, days) VALUES (?, ?)');
        $stmt->bind_param('di', $years_of_service, $days);
        $stmt->execute();
        $stmt->close();
    } elseif (isset($_POST['edit_annual_leave'])) {
        $annual_leave_id = intval($_POST['annual_leave_id']);
        $edit_years_of_service = floatval($_POST['edit_years_of_service']);
        $edit_days = intval($_POST['edit_days']);

        $stmt = $conn->prepare('UPDATE annual_leave_policy SET years_of_service = ?, days = ? WHERE id = ?');
        $stmt->bind_param('ddi', $edit_years_of_service, $edit_days, $annual_leave_id);
        $stmt->execute();
        $stmt->close();
    } elseif (isset($_POST['delete_annual_leave'])) {
        $annual_leave_id = intval($_POST['annual_leave_id']);
        $stmt = $conn->prepare('DELETE FROM annual_leave_policy WHERE id = ?');
        $stmt->bind_param('i', $annual_leave_id);
        $stmt->execute();
        $stmt->close();
    }

    header('Location: settings.php');
    exit();
}

// ✅【PHP-4】資料讀取：假別與特休假列表
$leave_types = $conn->query('SELECT * FROM leave_types ORDER BY id ASC');
$annual_leave_policy = $conn->query('SELECT * FROM annual_leave_policy ORDER BY years_of_service ASC');
?>
<!DOCTYPE html>
<html lang="zh-TW">
<head>
    <meta charset="UTF-8">
    <meta name="viewport" content="width=device-width, initial-scale=1.0">
    <title>假別與特休設定</title>
    <link href="https://cdn.jsdelivr.net/npm/bootstrap@5.3.3/dist/css/bootstrap.min.css" rel="stylesheet">
    <link rel="stylesheet" href="admin_navbar.css">
    <style>
        body {
            background: #f6f7fb;
            font-family: 'Noto Sans TC', 'Microsoft JhengHei', sans-serif;
        }
        .brand-hero {
            background: linear-gradient(135deg, rgba(52, 93, 157, 0.95), rgba(227, 99, 134, 0.9));
            border-radius: 24px;
            padding: 32px;
            color: #fff;
            box-shadow: 0 12px 32px rgba(52, 93, 157, 0.25);
            margin-top: 24px;
        }
        .brand-hero h1 {
            font-weight: 700;
            letter-spacing: 1px;
        }
        .brand-hero p {
            margin-bottom: 0;
            opacity: 0.9;
        }
        .card-custom {
            border: none;
            border-radius: 18px;
            box-shadow: 0 10px 24px rgba(52, 93, 157, 0.12);
            overflow: hidden;
        }
        .card-custom .card-header {
            background: rgba(255, 205, 0, 0.15);
            border-bottom: 0;
            padding: 18px 24px;
            font-weight: 600;
            color: #345d9d;
        }
        .table thead.table-primary {
            background-color: rgba(255, 205, 0, 0.9) !important;
            color: #345d9d;
        }
        .table tbody tr:hover {
            background-color: rgba(227, 99, 134, 0.08);
        }
        .btn-gradient {
            background: linear-gradient(120deg, #ffcd00, #e36386);
            border: none;
            color: #345d9d;
            font-weight: 600;
            box-shadow: 0 6px 15px rgba(227, 99, 134, 0.35);
        }
        .btn-gradient:hover {
            color: #22406c;
            background: linear-gradient(120deg, #ffe07a, #f08aa8);
        }
        .info-badge {
            background: rgba(52, 93, 157, 0.1);
            color: #345d9d;
            border-radius: 50px;
            padding: 6px 18px;
            font-size: 0.95rem;
        }
        .table-action button {
            min-width: 40px;
        }
        .modal-header {
            background: linear-gradient(120deg, rgba(52, 93, 157, 0.9), rgba(227, 99, 134, 0.9));
            color: #fff;
        }
        .form-label {
            font-weight: 600;
            color: #345d9d;
        }
    </style>
</head>
<body>
    <?php include 'admin_navbar.php'; ?>

    <div class="container py-4">
        <div class="bg-white shadow-sm rounded-4 p-3 d-flex align-items-center gap-3">
            <img src="../LOGO/LOGO-05.png" alt="企業LOGO" class="img-fluid" style="width: 72px; height: 72px; object-fit: contain;">
            <div>
                <h2 class="mb-1 text-primary fw-bold">系統設定中心</h2>
                <div class="text-muted">集中管理假別與特休規則，維持一致的休假標準</div>
            </div>
        </div>

        <div class="brand-hero mt-4">
            <div class="row align-items-center g-3">
                <div class="col-md-8">
                    <h1 class="mb-3">假別與特休設定</h1>
                    <p>管理各項假別、設定特休門檻與天數，確保員工休假權益與制度透明。</p>
                    <span class="info-badge">更新後即時生效，請妥善確認資訊</span>
                </div>
                <div class="col-md-4 text-md-end text-center">
                    <button class="btn btn-gradient me-2" data-bs-toggle="modal" data-bs-target="#addLeaveModal">新增假別</button>
                    <button class="btn btn-gradient" data-bs-toggle="modal" data-bs-target="#addAnnualLeaveModal">新增特休規則</button>
                </div>
            </div>
        </div>

        <div class="row mt-4 g-4">
            <div class="col-lg-7">
                <div class="card card-custom">
                    <div class="card-header d-flex justify-content-between align-items-center">
                        <span>假別列表與設定</span>
                        <button class="btn btn-sm btn-gradient" data-bs-toggle="modal" data-bs-target="#addLeaveModal">＋ 新增假別</button>
                    </div>
                    <div class="card-body p-0">
                        <div class="table-responsive">
                            <table class="table table-bordered mb-0 align-middle">
                                <thead class="table-primary">
                                    <tr>
                                        <th>名稱</th>
                                        <th class="text-center">天數 / 年</th>
                                        <th class="text-center">薪資比例</th>
                                        <th>申請資格</th>
                                        <th class="text-center">全勤影響</th>
                                        <th>備註</th>
                                        <th class="text-center">操作</th>
                                    </tr>
                                </thead>
                                <tbody>
                                    <?php if ($leave_types && $leave_types->num_rows > 0): ?>
                                        <?php while ($row = $leave_types->fetch_assoc()): ?>
                                            <tr>
                                                <td><?= htmlspecialchars($row['name']) ?></td>
                                                <td class="text-center"><?= intval($row['days_per_year']) ?></td>
                                                <td class="text-center"><?= number_format($row['salary_ratio'], 2) ?></td>
                                                <td><?= nl2br(htmlspecialchars($row['eligibility'])) ?></td>
                                                <td class="text-center">
                                                    <span class="badge <?= $row['affect_attendance'] ? 'bg-danger-subtle text-danger' : 'bg-success-subtle text-success' ?>">
                                                        <?= $row['affect_attendance'] ? '影響' : '不影響' ?>
                                                    </span>
                                                </td>
                                                <td><?= nl2br(htmlspecialchars($row['notes'])) ?></td>
                                                <td class="text-center table-action">
                                                    <button class="btn btn-warning btn-sm" data-bs-toggle="modal" data-bs-target="#editLeaveModal"
                                                        onclick='openEditModal(<?= json_encode($row['id']) ?>, <?= json_encode($row['name']) ?>, <?= json_encode($row['days_per_year']) ?>, <?= json_encode($row['salary_ratio']) ?>, <?= json_encode($row['eligibility']) ?>, <?= json_encode($row['affect_attendance']) ?>, <?= json_encode($row['notes']) ?>)'>✏</button>
                                                    <form method="POST" class="d-inline" onsubmit="return confirmDelete(<?= json_encode($row['name']) ?>);">
                                                        <input type="hidden" name="leave_id" value="<?= $row['id'] ?>">
                                                        <button type="submit" name="delete_leave" class="btn btn-danger btn-sm">🗑</button>
                                                    </form>
                                                </td>
                                            </tr>
                                        <?php endwhile; ?>
                                    <?php else: ?>
                                        <tr>
                                            <td colspan="7" class="text-center text-muted py-4">目前尚未建立任何假別設定</td>
                                        </tr>
                                    <?php endif; ?>
                                </tbody>
                            </table>
                        </div>
                    </div>
                </div>
            </div>
            <div class="col-lg-5">
                <div class="card card-custom h-100">
                    <div class="card-header d-flex justify-content-between align-items-center">
                        <span>特休規則</span>
                        <button class="btn btn-sm btn-gradient" data-bs-toggle="modal" data-bs-target="#addAnnualLeaveModal">＋ 新增特休</button>
                    </div>
                    <div class="card-body p-0">
                        <div class="table-responsive">
                            <table class="table table-bordered mb-0 align-middle">
                                <thead class="table-primary">
                                    <tr>
                                        <th class="text-center">年資（年）</th>
                                        <th class="text-center">給假天數</th>
                                        <th class="text-center">操作</th>
                                    </tr>
                                </thead>
                                <tbody>
                                    <?php if ($annual_leave_policy && $annual_leave_policy->num_rows > 0): ?>
                                        <?php while ($row = $annual_leave_policy->fetch_assoc()): ?>
                                            <tr>
                                                <td class="text-center"><?= rtrim(rtrim(number_format($row['years_of_service'], 1), '0'), '.') ?></td>
                                                <td class="text-center"><?= intval($row['days']) ?></td>
                                                <td class="text-center table-action">
                                                    <button class="btn btn-warning btn-sm" data-bs-toggle="modal" data-bs-target="#editAnnualLeaveModal"
                                                        onclick="openEditAnnualLeaveModal('<?= $row['id'] ?>', '<?= rtrim(rtrim(number_format($row['years_of_service'], 1), '0'), '.') ?>', '<?= intval($row['days']) ?>')">✏</button>
                                                    <form method="POST" class="d-inline" onsubmit="return confirmDeleteAnnualLeave('<?= rtrim(rtrim(number_format($row['years_of_service'], 1), '0'), '.') ?>');">
                                                        <input type="hidden" name="annual_leave_id" value="<?= $row['id'] ?>">
                                                        <button type="submit" name="delete_annual_leave" class="btn btn-danger btn-sm">🗑</button>
                                                    </form>
                                                </td>
                                            </tr>
                                        <?php endwhile; ?>
                                    <?php else: ?>
                                        <tr>
                                            <td colspan="3" class="text-center text-muted py-4">目前尚未設定特休規則</td>
                                        </tr>
                                    <?php endif; ?>
                                </tbody>
                            </table>
                        </div>
                    </div>
                </div>
            </div>
        </div>
    </div>

    <!-- 新增假別 Modal -->
    <div class="modal fade" id="addLeaveModal" tabindex="-1">
        <div class="modal-dialog modal-dialog-centered">
            <form method="POST" class="modal-content border-0">
                <div class="modal-header">
                    <h5 class="modal-title">新增假別</h5>
                    <button type="button" class="btn-close" data-bs-dismiss="modal" aria-label="關閉"></button>
                </div>
                <div class="modal-body">
                    <div class="mb-3">
                        <label class="form-label">假別名稱</label>
                        <input type="text" name="name" class="form-control" required>
                    </div>
                    <div class="mb-3">
                        <label class="form-label">年度核發天數</label>
                        <input type="number" name="days_per_year" class="form-control" min="0" required>
                    </div>
                    <div class="mb-3">
                        <label class="form-label">給薪比例</label>
                        <input type="number" step="0.01" name="salary_ratio" class="form-control" min="0" required>
                    </div>
                    <div class="mb-3">
                        <label class="form-label">申請資格</label>
                        <textarea name="eligibility" class="form-control" rows="2" placeholder="請輸入可申請人員或條件"></textarea>
                    </div>
                    <div class="form-check form-switch mb-3">
                        <input type="checkbox" name="affect_attendance" class="form-check-input" id="affect_attendance">
                        <label class="form-check-label" for="affect_attendance">影響全勤獎金</label>
                    </div>
                    <div class="mb-3">
                        <label class="form-label">備註說明</label>
                        <textarea name="notes" class="form-control" rows="2"></textarea>
                    </div>
                </div>
                <div class="modal-footer">
                    <button type="button" class="btn btn-light" data-bs-dismiss="modal">取消</button>
                    <button type="submit" name="add_leave" class="btn btn-gradient">確認新增</button>
                </div>
            </form>
        </div>
    </div>

    <!-- 編輯假別 Modal -->
    <div class="modal fade" id="editLeaveModal" tabindex="-1">
        <div class="modal-dialog modal-dialog-centered">
            <form method="POST" class="modal-content border-0">
                <div class="modal-header">
                    <h5 class="modal-title">編輯假別</h5>
                    <button type="button" class="btn-close" data-bs-dismiss="modal" aria-label="關閉"></button>
                </div>
                <div class="modal-body">
                    <input type="hidden" id="edit_leave_id" name="leave_id">
                    <div class="mb-3">
                        <label class="form-label">假別名稱</label>
                        <input type="text" id="edit_name" name="edit_name" class="form-control" required>
                    </div>
                    <div class="mb-3">
                        <label class="form-label">年度核發天數</label>
                        <input type="number" id="edit_days_per_year" name="edit_days_per_year" class="form-control" min="0" required>
                    </div>
                    <div class="mb-3">
                        <label class="form-label">給薪比例</label>
                        <input type="number" step="0.01" id="edit_salary_ratio" name="edit_salary_ratio" class="form-control" min="0" required>
                    </div>
                    <div class="mb-3">
                        <label class="form-label">申請資格</label>
                        <textarea id="edit_eligibility" name="edit_eligibility" class="form-control" rows="2"></textarea>
                    </div>
                    <div class="form-check form-switch mb-3">
                        <input type="checkbox" id="edit_affect_attendance" name="edit_affect_attendance" class="form-check-input">
                        <label class="form-check-label" for="edit_affect_attendance">影響全勤獎金</label>
                    </div>
                    <div class="mb-3">
                        <label class="form-label">備註說明</label>
                        <textarea id="edit_notes" name="edit_notes" class="form-control" rows="2"></textarea>
                    </div>
                </div>
                <div class="modal-footer">
                    <button type="button" class="btn btn-light" data-bs-dismiss="modal">取消</button>
                    <button type="submit" name="edit_leave" class="btn btn-gradient">儲存變更</button>
                </div>
            </form>
        </div>
    </div>

    <!-- 新增特休 Modal -->
    <div class="modal fade" id="addAnnualLeaveModal" tabindex="-1">
        <div class="modal-dialog modal-dialog-centered">
            <form method="POST" class="modal-content border-0">
                <div class="modal-header">
                    <h5 class="modal-title">新增特休規則</h5>
                    <button type="button" class="btn-close" data-bs-dismiss="modal" aria-label="關閉"></button>
                </div>
                <div class="modal-body">
                    <div class="mb-3">
                        <label class="form-label">年資（年）</label>
                        <input type="number" step="0.5" name="years_of_service" class="form-control" min="0" required>
                    </div>
                    <div class="mb-3">
                        <label class="form-label">給假天數</label>
                        <input type="number" name="days" class="form-control" min="0" required>
                    </div>
                </div>
                <div class="modal-footer">
                    <button type="button" class="btn btn-light" data-bs-dismiss="modal">取消</button>
                    <button type="submit" name="add_annual_leave" class="btn btn-gradient">確認新增</button>
                </div>
            </form>
        </div>
    </div>

    <!-- 編輯特休 Modal -->
    <div class="modal fade" id="editAnnualLeaveModal" tabindex="-1">
        <div class="modal-dialog modal-dialog-centered">
            <form method="POST" class="modal-content border-0">
                <div class="modal-header">
                    <h5 class="modal-title">編輯特休規則</h5>
                    <button type="button" class="btn-close" data-bs-dismiss="modal" aria-label="關閉"></button>
                </div>
                <div class="modal-body">
                    <input type="hidden" id="edit_annual_leave_id" name="annual_leave_id">
                    <div class="mb-3">
                        <label class="form-label">年資（年）</label>
                        <input type="number" step="0.5" id="edit_years_of_service" name="edit_years_of_service" class="form-control" min="0" required>
                    </div>
                    <div class="mb-3">
                        <label class="form-label">給假天數</label>
                        <input type="number" id="edit_days" name="edit_days" class="form-control" min="0" required>
                    </div>
                </div>
                <div class="modal-footer">
                    <button type="button" class="btn btn-light" data-bs-dismiss="modal">取消</button>
                    <button type="submit" name="edit_annual_leave" class="btn btn-gradient">儲存變更</button>
                </div>
            </form>
        </div>
    </div>

    <script src="https://code.jquery.com/jquery-3.7.1.min.js"></script>
    <script src="https://cdn.jsdelivr.net/npm/bootstrap@5.3.3/dist/js/bootstrap.bundle.min.js"></script>
    <script>
        // ✅【JS-1】假別資料帶入編輯彈窗
        function openEditModal(id, name, days, salary, eligibility, attendance, notes) {
            $('#edit_leave_id').val(id);
            $('#edit_name').val(name);
            $('#edit_days_per_year').val(days);
            $('#edit_salary_ratio').val(parseFloat(salary).toFixed(2));
            $('#edit_eligibility').val(eligibility);
            $('#edit_affect_attendance').prop('checked', attendance == 1);
            $('#edit_notes').val(notes);
        }

        // ✅【JS-2】特休資料帶入編輯彈窗
        function openEditAnnualLeaveModal(id, years, days) {
            $('#edit_annual_leave_id').val(id);
            $('#edit_years_of_service').val(years);
            $('#edit_days').val(days);
        }

        // ✅【JS-3】刪除前確認視窗
        function confirmDelete(name) {
            return confirm(`確定要刪除【${name}】嗎？此操作無法復原。`);
        }

        function confirmDeleteAnnualLeave(years) {
            return confirm(`確定要刪除年資為【${years}】的特休規則嗎？此操作無法復原。`);
        }
    </script>
</body>
</html>