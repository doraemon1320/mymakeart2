<?php
session_start();

// ✅ 1️⃣ 登入權限檢查
if (!isset($_SESSION['user']) || $_SESSION['user']['role'] !== 'admin') {
    header('Location: ../login.php');
    exit;
}

$conn = new mysqli('localhost', 'root', '', 'mymakeart');

// ✅ 2️⃣ 檢查資料庫連線
if ($conn->connect_error) {
    die("資料庫連線失敗：" . $conn->connect_error);
}

// ✅ 3️⃣ 讀取假別 & 特休假
$leave_types = $conn->query("SELECT * FROM leave_types");
$annual_leave_policy = $conn->query("SELECT * FROM annual_leave_policy ORDER BY years_of_service ASC");

// ✅ 4️⃣ 處理表單提交（新增、修改、刪除）
if ($_SERVER['REQUEST_METHOD'] === 'POST') {
    if (isset($_POST['add_leave'])) {
        $name = $_POST['name'];
        $days_per_year = intval($_POST['days_per_year']);
        $salary_ratio = floatval($_POST['salary_ratio']);
        $eligibility = $_POST['eligibility'];
        $affect_attendance = isset($_POST['affect_attendance']) ? 1 : 0;
        $notes = $_POST['notes'];

        $stmt = $conn->prepare("INSERT INTO leave_types (name, days_per_year, salary_ratio, eligibility, affect_attendance, notes) VALUES (?, ?, ?, ?, ?, ?)");
        $stmt->bind_param("sidsis", $name, $days_per_year, $salary_ratio, $eligibility, $affect_attendance, $notes);
        $stmt->execute();
    } elseif (isset($_POST['edit_leave'])) {
        $leave_id = intval($_POST['leave_id']);
        $edit_name = $_POST['edit_name'];
        $edit_days_per_year = intval($_POST['edit_days_per_year']);
        $edit_salary_ratio = floatval($_POST['edit_salary_ratio']);
        $edit_eligibility = $_POST['edit_eligibility'];
        $edit_affect_attendance = isset($_POST['edit_affect_attendance']) ? 1 : 0;
        $edit_notes = $_POST['edit_notes'];

        $stmt = $conn->prepare("UPDATE leave_types SET name=?, days_per_year=?, salary_ratio=?, eligibility=?, affect_attendance=?, notes=? WHERE id=?");
        $stmt->bind_param("sidsisi", $edit_name, $edit_days_per_year, $edit_salary_ratio, $edit_eligibility, $edit_affect_attendance, $edit_notes, $leave_id);
        $stmt->execute();
    } elseif (isset($_POST['delete_leave'])) {
        $leave_id = intval($_POST['leave_id']);
        $stmt = $conn->prepare("DELETE FROM leave_types WHERE id=?");
        $stmt->bind_param("i", $leave_id);
        $stmt->execute();
    } elseif (isset($_POST['add_annual_leave'])) {
        $years_of_service = floatval($_POST['years_of_service']);
		$edit_years_of_service = floatval($_POST['edit_years_of_service']);
        $days = intval($_POST['days']);
       $stmt = $conn->prepare("INSERT INTO annual_leave_policy (years_of_service, days) VALUES (?, ?)");
		$stmt->bind_param("di", $years_of_service, $days); // "d" 代表 float 或 decimal
        $stmt->execute();
    } elseif (isset($_POST['edit_annual_leave'])) {
        $annual_leave_id = intval($_POST['annual_leave_id']);
        $edit_years_of_service = intval($_POST['edit_years_of_service']);
        $edit_days = intval($_POST['edit_days']);
$stmt = $conn->prepare("UPDATE annual_leave_policy SET years_of_service=?, days=? WHERE id=?");
$stmt->bind_param("ddi", $edit_years_of_service, $edit_days, $annual_leave_id);

        $stmt->execute();
    } elseif (isset($_POST['delete_annual_leave'])) {
        $annual_leave_id = intval($_POST['annual_leave_id']);
        $stmt = $conn->prepare("DELETE FROM annual_leave_policy WHERE id=?");
        $stmt->bind_param("i", $annual_leave_id);
        $stmt->execute();
    }

    header("Location: settings.php");
    exit();
}
?>

<!DOCTYPE html>
<html lang="zh-TW">
<head>
    <meta charset="UTF-8">
    <meta name="viewport" content="width=device-width, initial-scale=1.0">
    <title>假別管理</title>
    <link href="https://cdn.jsdelivr.net/npm/bootstrap@5.3.3/dist/css/bootstrap.min.css" rel="stylesheet">
    <link rel="stylesheet" href="admin_navbar.css">
</head>
<body>
	<?php include 'admin_navbar.php'; ?>
<div class="container mt-4">
    
    
    <h1 class="mb-4">假別管理</h1>

    <!-- 假別管理 -->
    <div class="card mb-4">
        <div class="card-header d-flex justify-content-between align-items-center">
            <span>目前假別</span>
            <button class="btn btn-success btn-sm" data-bs-toggle="modal" data-bs-target="#addLeaveModal">新增假別</button>
        </div>
        <div class="card-body p-0">
            <table class="table table-bordered table-striped table-hover mb-0">
                <thead class="table-primary">
                    <tr>
                        <th>名稱</th>
                        <th>天數(年)</th>
                        <th>資薪比例</th>
                        <th>申請資格</th>
                        <th>全勤影響</th>
                        <th>備註</th>
                        <th>操作</th>
                    </tr>
                </thead>
                <tbody>
                <?php while ($row = $leave_types->fetch_assoc()): ?>
                    <tr>
                        <td><?= htmlspecialchars($row['name']) ?></td>
                        <td><?= $row['days_per_year'] ?></td>
                        <td><?= $row['salary_ratio'] ?></td>
                        <td><?= htmlspecialchars($row['eligibility']) ?></td>
                        <td><?= $row['affect_attendance'] ? '✔' : '✖' ?></td>
                        <td><?= htmlspecialchars($row['notes']) ?></td>
                        <td>
                            <button class="btn btn-warning btn-sm"
                                onclick='openEditModal(
                                    <?= json_encode($row['id']) ?>,
                                    <?= json_encode($row['name']) ?>,
                                    <?= json_encode($row['days_per_year']) ?>,
                                    <?= json_encode($row['salary_ratio']) ?>,
                                    <?= json_encode($row['eligibility']) ?>,
                                    <?= json_encode($row['affect_attendance']) ?>,
                                    <?= json_encode($row['notes']) ?>
                                )'
                                data-bs-toggle="modal" data-bs-target="#editLeaveModal">✏</button>
                            <form method="POST" style="display:inline;" onsubmit="return confirmDelete('<?= addslashes($row['name']) ?>')">
                                <input type="hidden" name="leave_id" value="<?= $row['id'] ?>">
                                <button type="submit" name="delete_leave" class="btn btn-danger btn-sm">🗑</button>
                            </form>
                        </td>
                    </tr>
                <?php endwhile; ?>
                </tbody>
            </table>
        </div>
    </div>

    <!-- 特休假管理 -->
    <div class="card">
        <div class="card-header d-flex justify-content-between align-items-center">
            <span>目前特休假</span>
            <button class="btn btn-success btn-sm" data-bs-toggle="modal" data-bs-target="#addAnnualLeaveModal">新增特休假</button>
        </div>
        <div class="card-body p-0">
            <table class="table table-bordered table-striped table-hover mb-0">
                <thead class="table-primary">
                    <tr>
                        <th>年資（年）</th>
                        <th>天數（天）</th>
                        <th>操作</th>
                    </tr>
                </thead>
                <tbody>
                <?php while ($row = $annual_leave_policy->fetch_assoc()): ?>
                    <tr>
                        <td><?= number_format($row['years_of_service'], 1) ?> 年</td>
                        <td><?= $row['days'] ?> 天</td>
                        <td>
                            <button class="btn btn-warning btn-sm"
                                onclick="openEditAnnualLeaveModal('<?= $row['id'] ?>', '<?= number_format($row['years_of_service'], 1) ?>', '<?= $row['days'] ?>')"
                                data-bs-toggle="modal" data-bs-target="#editAnnualLeaveModal">✏</button>
                            <form method="POST" style="display:inline;" onsubmit="return confirmDeleteAnnualLeave('<?= number_format($row['years_of_service'], 1) ?>')">
                                <input type="hidden" name="annual_leave_id" value="<?= $row['id'] ?>">
                                <button type="submit" name="delete_annual_leave" class="btn btn-danger btn-sm">🗑</button>
                            </form>
                        </td>
                    </tr>
                <?php endwhile; ?>
                </tbody>
            </table>
        </div>
    </div>
</div>

<!-- 新增假別 Modal -->
<div class="modal fade" id="addLeaveModal" tabindex="-1">
    <div class="modal-dialog">
        <form method="POST" class="modal-content">
            <div class="modal-header">
                <h5 class="modal-title">新增假別</h5>
                <button type="button" class="btn-close" data-bs-dismiss="modal"></button>
            </div>
            <div class="modal-body">
                <div class="mb-2">
                    <label class="form-label">名稱</label>
                    <input type="text" name="name" class="form-control" required>
                </div>
                <div class="mb-2">
                    <label class="form-label">天數(年)</label>
                    <input type="number" name="days_per_year" class="form-control" required>
                </div>
                <div class="mb-2">
                    <label class="form-label">資薪比例</label>
                    <input type="number" step="0.01" name="salary_ratio" class="form-control" required>
                </div>
                <div class="mb-2">
                    <label class="form-label">申請資格</label>
                    <input type="text" name="eligibility" class="form-control">
                </div>
                <div class="form-check mb-2">
                    <input type="checkbox" name="affect_attendance" class="form-check-input" id="affect_attendance">
                    <label class="form-check-label" for="affect_attendance">全勤影響</label>
                </div>
                <div class="mb-2">
                    <label class="form-label">備註</label>
                    <textarea name="notes" class="form-control"></textarea>
                </div>
            </div>
            <div class="modal-footer">
                <button type="submit" name="add_leave" class="btn btn-primary">新增</button>
            </div>
        </form>
    </div>
</div>

<!-- 修改假別 Modal -->
<div class="modal fade" id="editLeaveModal" tabindex="-1">
    <div class="modal-dialog">
        <form method="POST" class="modal-content">
            <div class="modal-header">
                <h5 class="modal-title">修改假別</h5>
                <button type="button" class="btn-close" data-bs-dismiss="modal"></button>
            </div>
            <div class="modal-body">
                <input type="hidden" id="edit_leave_id" name="leave_id">
                <div class="mb-2">
                    <label class="form-label">名稱</label>
                    <input type="text" id="edit_name" name="edit_name" class="form-control" required>
                </div>
                <div class="mb-2">
                    <label class="form-label">天數(年)</label>
                    <input type="number" id="edit_days_per_year" name="edit_days_per_year" class="form-control" required>
                </div>
                <div class="mb-2">
                    <label class="form-label">資薪比例</label>
                    <input type="number" step="0.01" id="edit_salary_ratio" name="edit_salary_ratio" class="form-control" required>
                </div>
                <div class="mb-2">
                    <label class="form-label">申請資格</label>
                    <input type="text" id="edit_eligibility" name="edit_eligibility" class="form-control">
                </div>
                <div class="form-check mb-2">
                    <input type="checkbox" id="edit_affect_attendance" name="edit_affect_attendance" class="form-check-input">
                    <label class="form-check-label" for="edit_affect_attendance">全勤影響</label>
                </div>
                <div class="mb-2">
                    <label class="form-label">備註</label>
                    <textarea id="edit_notes" name="edit_notes" class="form-control"></textarea>
                </div>
            </div>
            <div class="modal-footer">
                <button type="submit" name="edit_leave" class="btn btn-warning">更新</button>
            </div>
        </form>
    </div>
</div>

<!-- 新增特休假 Modal -->
<div class="modal fade" id="addAnnualLeaveModal" tabindex="-1">
    <div class="modal-dialog">
        <form method="POST" class="modal-content">
            <div class="modal-header">
                <h5 class="modal-title">新增特休假</h5>
                <button type="button" class="btn-close" data-bs-dismiss="modal"></button>
            </div>
            <div class="modal-body">
                <div class="mb-2">
                    <label class="form-label">年資（年）</label>
                    <input type="number" step="0.5" name="years_of_service" class="form-control" required>
                </div>
                <div class="mb-2">
                    <label class="form-label">天數（天）</label>
                    <input type="number" name="days" class="form-control" required>
                </div>
            </div>
            <div class="modal-footer">
                <button type="submit" name="add_annual_leave" class="btn btn-primary">新增</button>
            </div>
        </form>
    </div>
</div>

<!-- 修改特休假 Modal -->
<div class="modal fade" id="editAnnualLeaveModal" tabindex="-1">
    <div class="modal-dialog">
        <form method="POST" class="modal-content">
            <div class="modal-header">
                <h5 class="modal-title">修改特休假</h5>
                <button type="button" class="btn-close" data-bs-dismiss="modal"></button>
            </div>
            <div class="modal-body">
                <input type="hidden" id="edit_annual_leave_id" name="annual_leave_id">
                <div class="mb-2">
                    <label class="form-label">年資（年）</label>
                    <input type="number" step="0.5" id="edit_years_of_service" name="edit_years_of_service" class="form-control" required>
                </div>
                <div class="mb-2">
                    <label class="form-label">天數（天）</label>
                    <input type="number" id="edit_days" name="edit_days" class="form-control" required>
                </div>
            </div>
            <div class="modal-footer">
                <button type="submit" name="edit_annual_leave" class="btn btn-warning">更新</button>
            </div>
        </form>
    </div>
</div>

<script>
function openEditModal(id, name, days, salary, eligibility, attendance, notes) {
    document.getElementById("edit_leave_id").value = id;
    document.getElementById("edit_name").value = name;
    document.getElementById("edit_days_per_year").value = days;
    document.getElementById("edit_salary_ratio").value = salary;
    document.getElementById("edit_eligibility").value = eligibility;
    document.getElementById("edit_affect_attendance").checked = attendance == 1;
    document.getElementById("edit_notes").value = notes;
}
function openEditAnnualLeaveModal(id, years, days) {
    document.getElementById("edit_annual_leave_id").value = id;
    document.getElementById("edit_years_of_service").value = years;
    document.getElementById("edit_days").value = days;
}
function confirmDelete(name) {
    return confirm(`確定要刪除【${name}】嗎？此操作無法復原！`);
}
function confirmDeleteAnnualLeave(years) {
    return confirm(`確定要刪除【${years}年特休假】嗎？此操作無法復原！`);
}
</script>

<script src="https://cdn.jsdelivr.net/npm/bootstrap@5.3.3/dist/js/bootstrap.bundle.min.js"></script>
</body>
</html>
