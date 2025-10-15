<?php
session_start();

// ✅ 1️⃣ 登入權限檢查
if (!isset($_SESSION['user']) || $_SESSION['user']['role'] !== 'admin') {
    header('Location: login.php');
    exit;
}

$conn = new mysqli('localhost', 'root', '', 'mymakeart');
if ($conn->connect_error) {
    die("資料庫連接失敗：" . $conn->connect_error);
}

// ✅ 2️⃣ 檢查員工 ID 是否有效
if (!isset($_GET['id']) || !is_numeric($_GET['id'])) {
    die("❌ 無效的員工 ID");
}

$employee_id = (int)$_GET['id'];

// ✅ 3️⃣ 讀取員工詳細資料（包含班別 & 薪資結構）
$stmt = $conn->prepare("SELECT e.id, e.employee_number, e.name, e.username, e.gender, e.phone, e.address, e.hire_date, e.resignation_date, e.shift_id, s.base_salary, s.meal_allowance, s.attendance_bonus, s.position_bonus, s.skill_bonus, s.health_insurance, s.labor_insurance FROM employees e LEFT JOIN salary_structure s ON e.id = s.employee_id WHERE e.id = ?");
$stmt->bind_param('i', $employee_id);
$stmt->execute();
$result = $stmt->get_result();
$employee = $result->fetch_assoc();

if (!$employee) {
    die("❌ 員工資料不存在！");
}

// ✅ 4️⃣ 讀取所有班別（shifts 表）
$shift_result = $conn->query("SELECT id, name FROM shifts");

// ✅ 5️⃣ 更新員工資料（包含薪資結構）
if ($_SERVER['REQUEST_METHOD'] === 'POST') {
    if (isset($_POST['update_employee'])) {
        $shift_id = $_POST['shift_id'] ?? null;
        $resignation_date = $_POST['resignation_date'] ?? null;
        $address = $_POST['address'] ?? null;
        $phone = $_POST['phone'] ?? null;
        $new_password = $_POST['password'] ?? null;

        if (!empty($new_password)) {
            $hashed_password = password_hash($new_password, PASSWORD_DEFAULT);
            $stmt = $conn->prepare("UPDATE employees SET shift_id = ?, resignation_date = ?, address = ?, phone = ?, password = ? WHERE id = ?");
            $stmt->bind_param('issssi', $shift_id, $resignation_date, $address, $phone, $hashed_password, $employee_id);
        } else {
            $stmt = $conn->prepare("UPDATE employees SET shift_id = ?, resignation_date = ?, address = ?, phone = ? WHERE id = ?");
            $stmt->bind_param('isssi', $shift_id, $resignation_date, $address, $phone, $employee_id);
        }
        $stmt->execute();
    }

    if (isset($_POST['update_salary'])) {
    $base_salary = intval($_POST['base_salary'] ?? 0);
    $meal_allowance = intval($_POST['meal_allowance'] ?? 0);
    $attendance_bonus = intval($_POST['attendance_bonus'] ?? 0);
    $position_bonus = intval($_POST['position_bonus'] ?? 0);
    $skill_bonus = intval($_POST['skill_bonus'] ?? 0);
    $labor_insurance = intval($_POST['labor_insurance'] ?? 0);
    $health_insurance = ($_POST['health_insurance'] !== '' && $_POST['health_insurance'] !== null)
        ? intval($_POST['health_insurance'])
        : 0;

    $stmt = $conn->prepare("
        INSERT INTO salary_structure 
            (employee_id, base_salary, meal_allowance, attendance_bonus, position_bonus, skill_bonus, health_insurance, labor_insurance) 
        VALUES (?, ?, ?, ?, ?, ?, ?, ?)
        ON DUPLICATE KEY UPDATE 
            base_salary = VALUES(base_salary),
            meal_allowance = VALUES(meal_allowance),
            attendance_bonus = VALUES(attendance_bonus),
            position_bonus = VALUES(position_bonus),
            skill_bonus = VALUES(skill_bonus),
            health_insurance = VALUES(health_insurance),
            labor_insurance = VALUES(labor_insurance)
    ");
    if (!$stmt) {
        die("❌ SQL 準備錯誤：" . $conn->error);
    }

    $stmt->bind_param('iiiiiiii', $employee_id, $base_salary, $meal_allowance, $attendance_bonus, $position_bonus, $skill_bonus, $health_insurance, $labor_insurance);

    if (!$stmt->execute()) {
        die("❌ SQL 執行錯誤：" . $stmt->error);
    } else {
        echo "✅ 更新成功，影響筆數：" . $stmt->affected_rows;
    }

    $stmt->close();
}


    header("Location: employee_detail.php?id=$employee_id&success=1");
    exit();
}
?>
<!DOCTYPE html>
<html lang="zh-TW">
<head>
    <meta charset="UTF-8">
    <title>員工詳細資料</title>
    <link href="https://cdn.jsdelivr.net/npm/bootstrap@5.3.3/dist/css/bootstrap.min.css" rel="stylesheet">
    <style>
        .modal {
            display: none;
            position: fixed;
            z-index: 9999;
            left: 0;
            top: 0;
            width: 100%;
            height: 100%;
            overflow: auto;
            background-color: rgba(0,0,0,0.4);
        }
        .modal-content {
            background-color: #fff;

            margin: 5% auto;
            padding: 20px;
            border: 1px solid #ccc;
            width: 90%;
            max-width: 800px;
            border-radius: 8px;
        }
        .close {
            color: #aaa;
            float: right;
            font-size: 28px;
            font-weight: bold;
            cursor: pointer;
        }
        .edit-btn {
            margin: 10px 10px 20px 0;
        }
    </style>
</head>
<body>
<?php include 'admin_navbar.php'; ?>
<div class="container mt-4">
    <h1 class="mb-4">員工詳細資料</h1>
    <table class="table table-bordered">
        <tr><th>工號</th><td><?= htmlspecialchars($employee['employee_number']) ?></td></tr>
        <tr><th>姓名</th><td><?= htmlspecialchars($employee['name']) ?></td></tr>
        <tr><th>帳號</th><td><?= htmlspecialchars($employee['username']) ?></td></tr>
        <tr><th>性別</th><td><?= htmlspecialchars($employee['gender']) ?></td></tr>
        <tr><th>電話</th><td><?= htmlspecialchars($employee['phone'] ?? '無') ?></td></tr>
        <tr><th>地址</th><td><?= htmlspecialchars($employee['address'] ?? '無') ?></td></tr>
        <tr><th>到職日</th><td><?= htmlspecialchars($employee['hire_date']) ?></td></tr>
        <tr><th>離職日</th><td><?= htmlspecialchars($employee['resignation_date'] ?? '無') ?></td></tr>
        <tr><th>班別</th>
            <td>
                <select class="form-select" name="shift_id" disabled>
                    <?php while ($shift = $shift_result->fetch_assoc()): ?>
                        <option value="<?= $shift['id'] ?>" <?= ($shift['id'] == $employee['shift_id']) ? 'selected' : '' ?>>
                            <?= htmlspecialchars($shift['name']) ?>
                        </option>
                    <?php endwhile; ?>
                </select>
            </td>
        </tr>
    </table>

    <div class="mb-3">
        <button class="btn btn-outline-primary me-2" onclick="openModal('editEmployeeModal')">✏️ 修改員工資料</button>
        <button class="btn btn-outline-warning" onclick="openModal('editSalaryModal')">💰 修改薪資結構</button>
    </div>

    <!-- 回上一頁 -->
    <a href="employee_list.php" class="btn btn-secondary">← 回員工資料列表</a>

    <!-- 員工資料 Modal -->
    <div id="editEmployeeModal" class="modal">
        <div class="modal-content">
            <span class="close" onclick="closeModal('editEmployeeModal')">&times;</span>
            <h2>修改員工資料</h2>
            <form method="POST">
                <label>新密碼（留空則不修改）：</label><input class="form-control" type="password" name="password">
                <label>電話：</label><input class="form-control" type="text" name="phone" value="<?= $employee['phone'] ?>">
                <label>地址：</label><input class="form-control" type="text" name="address" value="<?= $employee['address'] ?>">
                <label>離職日期：</label><input class="form-control" type="date" name="resignation_date" value="<?= $employee['resignation_date'] ?>">
                <label>班別：</label>
                <select class="form-select" name="shift_id">
                    <?php mysqli_data_seek($shift_result, 0); while ($shift = $shift_result->fetch_assoc()): ?>
                        <option value="<?= $shift['id'] ?>" <?= ($shift['id'] == $employee['shift_id']) ? 'selected' : '' ?>>
                            <?= htmlspecialchars($shift['name']) ?>
                        </option>
                    <?php endwhile; ?>
                </select>
                <button class="btn btn-success mt-3" type="submit" name="update_employee">更新員工資料</button>
            </form>
        </div>
    </div>

    <!-- 薪資結構 Modal -->
    <div id="editSalaryModal" class="modal">
        <div class="modal-content">
            <span class="close" onclick="closeModal('editSalaryModal')">&times;</span>
            <h2>修改薪資結構</h2>
            <form method="POST">
                <div class="row">
                    <div class="col-md-6">
                        <h4>📈 正項</h4>
                        <label>底薪：</label><input class="form-control" type="number" name="base_salary" value="<?= $employee['base_salary'] ?>">
                        <label>伙食費：</label><input class="form-control" type="number" name="meal_allowance" value="<?= $employee['meal_allowance'] ?>">
                        <label>全勤獎金：</label><input class="form-control" type="number" name="attendance_bonus" value="<?= $employee['attendance_bonus'] ?>">
                        <label>職務加給：</label><input class="form-control" type="number" name="position_bonus" value="<?= $employee['position_bonus'] ?>">
                        <label>技術津貼：</label><input class="form-control" type="number" name="skill_bonus" value="<?= $employee['skill_bonus'] ?>">
                    </div>
                    <div class="col-md-6">
                        <h4>📉 負項</h4>
                        <label>勞保費：</label><input class="form-control" type="number" name="labor_insurance" value="<?= $employee['labor_insurance'] ?>">
                        <label>健保費（可選填）：</label><input class="form-control" type="number" name="health_insurance" value="<?= $employee['health_insurance'] ?>">
                    </div>
                </div>
                <button class="btn btn-success mt-3" type="submit" name="update_salary">更新薪資</button>
            </form>
        </div>
    </div>
</div>
<script>
function openModal(id) {
    document.getElementById(id).style.display = 'block';
}
function closeModal(id) {
    document.getElementById(id).style.display = 'none';
}
</script>
</body>
</html>
