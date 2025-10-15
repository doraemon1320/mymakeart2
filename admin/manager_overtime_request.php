<?php
require_once "db_connect.php";

// 權限限制
if (!isset($_SESSION['user']) || $_SESSION['user']['role'] !== 'admin') {
    header('Location: ../login.php');
    exit;
}

$employees = $conn->query("SELECT employee_number, name FROM employees WHERE role = 'employee'");
$employeeOptions = [];
while ($emp = $employees->fetch_assoc()) {
    $employeeOptions[] = "<option value='{$emp['employee_number']}'>{$emp['employee_number']} - {$emp['name']}</option>";
}
?>

<!DOCTYPE html>
<html lang="zh-Hant">
<head>
  <meta charset="UTF-8">
  <title>主管代填加班單</title>
  <link href="https://cdn.jsdelivr.net/npm/bootstrap@5.3.3/dist/css/bootstrap.min.css" rel="stylesheet">
  <script src="https://cdn.jsdelivr.net/npm/bootstrap@5.3.3/dist/js/bootstrap.bundle.min.js"></script>
  <link rel="stylesheet" href="admin_navbar.css">
</head>
<body>
<?php include "admin_navbar.php"; ?>

<div class="container mt-4">
  <h1 class="mb-3">主管代填加班單</h1>
  <div class="alert alert-info">請填寫加班日期與加班起訖時間，時數至少需達 0.5 小時。</div>

  <form action="manager_overtime_submit.php" method="post" onsubmit="return validateForm()">
    <div class="table-responsive">
      <table class="table table-bordered table-sm align-middle text-center">
        <thead class="table-light">
          <tr>
            <th>員工</th>
            <th>起始日期</th>
            <th>結束日期</th>
            <th>起始時間</th>
            <th>結束時間</th>
            <th>加班原因</th>
          </tr>
        </thead>
        <tbody id="formContainer">
        <?php for ($i = 0; $i < 5; $i++): ?>
          <tr class="multi-form-row">
            <td>
              <select name="employee_number[]" class="form-select">
                <option value="">請選擇</option>
                <?= implode('', $employeeOptions) ?>
              </select>
            </td>
            <td><input type="date" name="start_day[]" class="form-control"></td>
            <td><input type="date" name="end_day[]" class="form-control"></td>
            <td>
              <select name="start_hour[]" class="form-select"><?php
                for ($h = 0; $h < 24; $h++) foreach ([0, 30] as $m):
                  $v = sprintf('%02d:%02d', $h, $m);
              ?><option value="<?= $v ?>"><?= $v ?></option><?php endforeach; ?></select>
            </td>
            <td>
              <select name="end_hour[]" class="form-select"><?php
                for ($h = 0; $h < 24; $h++) foreach ([0, 30] as $m):
                  $v = sprintf('%02d:%02d', $h, $m);
              ?><option value="<?= $v ?>"><?= $v ?></option><?php endforeach; ?></select>
            </td>
            <td><textarea name="reason[]" class="form-control" rows="1"></textarea></td>
          </tr>
        <?php endfor; ?>
        </tbody>
      </table>
    </div>

    <div class="d-flex justify-content-between my-3">
      <button type="button" class="btn btn-outline-secondary" onclick="addFormRow()">➕ 新增一筆</button>
      <button type="submit" class="btn btn-success">送出加班單</button>
    </div>
  </form>
</div>

<script>
function reindexRows() {
  const rows = document.querySelectorAll("#formContainer tr");
  rows.forEach((row, index) => {
    // 若要補充索引用 name，例如：start_hour[0] 可在此補上
  });
}

function addFormRow() {
  const tbody = document.getElementById("formContainer");
  const template = document.querySelector(".multi-form-row");
  const row = template.cloneNode(true);
  row.querySelectorAll("input, select, textarea").forEach(el => el.value = "");
  tbody.appendChild(row);
  reindexRows();
}

function validateForm() {
  const rows = document.querySelectorAll("#formContainer tr");
  let hasAnyFilled = false;
  let valid = true;

  rows.forEach((row, i) => {
    const emp = row.querySelector("select[name='employee_number[]']").value;
    const startDay = row.querySelector("input[name='start_day[]']").value;
    const endDay = row.querySelector("input[name='end_day[]']").value;
    const startHour = row.querySelector("select[name='start_hour[]']").value;
    const endHour = row.querySelector("select[name='end_hour[]']").value;

    if (!emp && !startDay && !endDay) return;
    hasAnyFilled = true;

    if (!emp || !startDay || !endDay) {
      alert(`第 ${i + 1} 筆未填完整`);
      valid = false;
      return;
    }

    const [sh, sm] = startHour.split(":").map(Number);
    const [eh, em] = endHour.split(":").map(Number);
    const mins = (eh * 60 + em) - (sh * 60 + sm);
    if (mins < 30) {
      alert(`第 ${i + 1} 筆加班時數需至少 0.5 小時`);
      valid = false;
      return;
    }
  });

  if (!hasAnyFilled) {
    return confirm("您尚未輸入任何加班資料，確定送出嗎？");
  }

  return valid;
}

document.addEventListener("DOMContentLoaded", () => {
  reindexRows();
});
</script>
</body>
</html>
