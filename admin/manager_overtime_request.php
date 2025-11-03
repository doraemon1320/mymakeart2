<?php
require_once "db_connect.php";

// PHP功能1：取得員工與班別資訊
$sql = "SELECT e.employee_number, e.name, s.start_time, s.end_time
        FROM employees e
        LEFT JOIN shifts s ON e.shift_id = s.id
        WHERE e.role = 'employee'";
$result = $conn->query($sql);

$employeeOptions = [];
$shiftMap = [];
if ($result) {
    while ($emp = $result->fetch_assoc()) {
        $empNo = htmlspecialchars($emp['employee_number'] ?? '', ENT_QUOTES, 'UTF-8');
        $empName = htmlspecialchars($emp['name'] ?? '', ENT_QUOTES, 'UTF-8');
        $startTime = isset($emp['start_time']) && $emp['start_time'] !== null ? substr($emp['start_time'], 0, 5) : '';
        $endTime = isset($emp['end_time']) && $emp['end_time'] !== null ? substr($emp['end_time'], 0, 5) : '';

        if ($startTime === '') {
            $startTime = '09:00';
        }
        if ($endTime === '') {
            $endTime = '18:00';
        }

        $employeeOptions[] = sprintf('<option value="%s">%s - %s</option>', $empNo, $empNo, $empName);

        $shiftMap[$empNo] = [
            'start_time' => $startTime,
            'end_time'   => $endTime,
        ];
    }
}

// PHP功能2：準備前端所需常數
$defaultRows = 5;
$shiftJson = !empty($shiftMap) ? json_encode($shiftMap, JSON_UNESCAPED_UNICODE) : '{}';
?>
<!DOCTYPE html>
<html lang="zh-Hant">
<head>
  <meta charset="UTF-8">
  <meta name="viewport" content="width=device-width, initial-scale=1">
  <title>主管代填加班單</title>
  <link href="https://cdn.jsdelivr.net/npm/bootstrap@5.3.3/dist/css/bootstrap.min.css" rel="stylesheet">
  <link rel="stylesheet" href="admin_navbar.css">
  <script src="https://code.jquery.com/jquery-3.6.0.min.js"></script>
  <script src="https://cdn.jsdelivr.net/npm/bootstrap@5.3.3/dist/js/bootstrap.bundle.min.js"></script>
  <style>
    :root {
      --brand-yellow: #ffcd00;
      --brand-pink: #e36386;
      --brand-blue: #345d9d;
    }

    body {
      background-color: #f4f6fb;
      color: #2d2d2d;
    }

    .hero-card {
      background: linear-gradient(120deg, rgba(52, 93, 157, 0.95), rgba(227, 99, 134, 0.9));
      border-radius: 24px;
      box-shadow: 0 20px 35px rgba(52, 93, 157, 0.25);
      color: #fff;
      overflow: hidden;
      position: relative;
    }

    .hero-card::after {
      content: '';
      position: absolute;
      inset: 0;
      background: radial-gradient(circle at top right, rgba(255, 205, 0, 0.4), transparent 45%);
      pointer-events: none;
    }

    .hero-title {
      font-weight: 700;
      letter-spacing: 0.08em;
    }

    .hero-subtitle {
      color: rgba(255, 255, 255, 0.85);
    }

    .alert-brand {
      background-color: rgba(255, 205, 0, 0.18);
      border: 1px solid rgba(255, 205, 0, 0.6);
      color: #5b4500;
    }

    .table thead.table-primary th {
      background-color: var(--brand-yellow);
      color: #2b2b2b;
      border-color: rgba(255, 205, 0, 0.6);
    }

    .multi-form-row td:first-child {
      min-width: 220px;
    }

    .shift-label {
      font-size: 0.85rem;
    }

    .btn-add-row {
      border: 1px dashed var(--brand-blue);
      color: var(--brand-blue);
      font-weight: 600;
    }

    .btn-add-row:hover {
      background-color: rgba(52, 93, 157, 0.08);
      color: var(--brand-blue);
    }

    .btn-submit-brand {
      background-color: var(--brand-blue);
      border-color: var(--brand-blue);
      color: #fff;
      font-weight: 600;
      letter-spacing: 1px;
    }

    .btn-submit-brand:hover {
      background-color: #274472;
      border-color: #274472;
    }

    .w-md-auto {
      width: 100%;
    }

    @media (min-width: 768px) {
      .w-md-auto {
        width: auto !important;
      }
    }

    @media (max-width: 768px) {
      .multi-form-row td:first-child {
        min-width: auto;
      }
    }
  </style>
</head>
<body>
<?php include "admin_navbar.php"; ?>

<div class="container mt-4">
  <div class="hero-card p-4 p-md-5 mb-4">
    <div class="row align-items-center g-4 position-relative">
      <div class="col-12">
        <h1 class="hero-title mb-3">主管代填加班</h1>
        <p class="hero-subtitle mb-0">依據員工班別自動鎖定可申請的加班時段，避免與正常工時重疊並輕鬆完成填報。</p>
      </div>
    </div>
  </div>

  <div class="alert alert-brand" role="alert">
    ✅ 加班僅能申請於正常班別時間以外的區段，請確認結束時間晚於起始時間，且整體時數至少 0.5 小時。
  </div>

  <form action="manager_overtime_submit.php" method="post" onsubmit="return validateForm()">
    <div class="table-responsive mb-3">
      <table class="table table-bordered align-middle text-center">
        <thead class="table-primary">
          <tr>
            <th class="text-nowrap">員工</th>
            <th>起始日期</th>
            <th>起始時間</th>
            <th>結束日期</th>
            <th>結束時間</th>
            <th>加班原因</th>
          </tr>
        </thead>
        <tbody id="formContainer">
        <?php for ($i = 0; $i < $defaultRows; $i++): ?>
          <tr class="multi-form-row align-middle">
            <td class="text-start">
              <select name="employee_number[]" class="form-select employee-select">
                <option value="">請選擇</option>
                <?= implode('', $employeeOptions) ?>
              </select>
              <small class="text-muted d-block mt-1 shift-label">班別時間：09:00 ~ 18:00（請先選擇員工）</small>
            </td>
            <td><input type="date" name="start_day[]" class="form-control"></td>
            <td>
              <select name="start_hour[]" class="form-select" disabled>
                <option value="">請先選擇員工</option>
              </select>
            </td>
            <td><input type="date" name="end_day[]" class="form-control"></td>
            <td>
              <select name="end_hour[]" class="form-select" disabled>
                <option value="">請先選擇員工</option>
              </select>
            </td>
            <td><textarea name="reason[]" class="form-control" rows="1" placeholder="請填寫加班原因"></textarea></td>
          </tr>
        <?php endfor; ?>
        </tbody>
      </table>
    </div>

    <div class="d-flex flex-column flex-md-row gap-3 justify-content-between mb-4">
      <button type="button" class="btn btn-add-row w-100 w-md-auto" onclick="addFormRow()">➕ 新增一筆</button>
      <button type="submit" class="btn btn-submit-brand w-100 w-md-auto">送出加班單</button>
    </div>
  </form>
</div>

<script>
  // JS功能1：建立時間陣列
  const SHIFT_MAP = <?= $shiftJson ?>;
  const TIME_LIST = (function () {
    const times = [];
    for (let h = 0; h < 24; h++) {
      for (const m of [0, 30]) {
        const hh = String(h).padStart(2, '0');
        const mm = String(m).padStart(2, '0');
        times.push(`${hh}:${mm}`);
      }
    }
    return times;
  })();

  // JS功能2：時間字串轉分鐘
  function timeToMinutes(timeStr) {
    if (!timeStr) return null;
    const parts = timeStr.split(':');
    const hours = parseInt(parts[0], 10);
    const minutes = parseInt(parts[1], 10);
    if (Number.isNaN(hours) || Number.isNaN(minutes)) return null;
    return hours * 60 + minutes;
  }

  // JS功能3：格式化日期（避免時區誤差）
  function formatDate(dateObj) {
    const y = dateObj.getFullYear();
    const m = String(dateObj.getMonth() + 1).padStart(2, '0');
    const d = String(dateObj.getDate()).padStart(2, '0');
    return `${y}-${m}-${d}`;
  }

  // JS功能4：產生加班可選時間
  function buildTimeOptions(shift) {
    let options = '<option value="">請選擇</option>';
    if (!shift) {
      TIME_LIST.forEach(t => {
        options += `<option value="${t}">${t}</option>`;
      });
      return options;
    }

    const startMinutes = timeToMinutes(shift.start_time);
    const endMinutes = timeToMinutes(shift.end_time);

    TIME_LIST.forEach(t => {
      const current = timeToMinutes(t);
      let disabled = false;

      if (startMinutes !== null && endMinutes !== null) {
        if (startMinutes < endMinutes) {
          if (current > startMinutes && current < endMinutes) {
            disabled = true;
          }
        } else if (startMinutes > endMinutes) {
          if (current > startMinutes || current < endMinutes) {
            disabled = true;
          }
        }
      }

      const label = disabled ? `${t}（上班）` : t;
      options += `<option value="${t}" ${disabled ? 'disabled' : ''}>${label}</option>`;
    });

    return options;
  }

  // JS功能5：更新班別顯示
  function updateShiftLabel($row, shift, hasEmployee) {
    const $label = $row.find('.shift-label');
    if (!hasEmployee) {
      $label.text('班別時間：09:00 ~ 18:00（請先選擇員工）');
    } else if (shift) {
      $label.text(`班別時間：${shift.start_time} ~ ${shift.end_time}`);
    } else {
      $label.text('班別時間：09:00 ~ 18:00，可依需求調整加班時段');
    }
  }

  // JS功能6：更新時間下拉選單
  function updateTimeSelects($row, shift, hasEmployee) {
    const $start = $row.find("select[name='start_hour[]']");
    const $end = $row.find("select[name='end_hour[]']");

    if (!hasEmployee) {
      const placeholder = '<option value="">請先選擇員工</option>';
      $start.html(placeholder).prop('disabled', true);
      $end.html(placeholder).prop('disabled', true);
      return;
    }

    const options = buildTimeOptions(shift);
    $start.html(options).prop('disabled', false);
    $end.html(options).prop('disabled', false);
  }

  // JS功能7：初始化資料列事件
  function initializeRow($row) {
    updateShiftLabel($row, null, false);
    updateTimeSelects($row, null, false);

    const $employeeSelect = $row.find("select[name='employee_number[]']");
    $employeeSelect.off('change').on('change', function () {
      const empNo = $(this).val();
      const shift = SHIFT_MAP[empNo] || null;
      const hasEmployee = !!empNo;
      updateShiftLabel($row, shift, hasEmployee);
      updateTimeSelects($row, shift, hasEmployee);
    });
  }

  // JS功能8：重新編號列資訊
  function reindexRows() {
    $('#formContainer tr').each(function (index) {
      $(this).attr('data-row-index', index + 1);
    });
  }

  // JS功能9：新增一筆資料列
  function addFormRow() {
    const $tbody = $('#formContainer');
    const $template = $tbody.find('tr').first().clone();

    $template.find('input').val('');
    $template.find('textarea').val('');
    $template.find('select').val('');
    $template.find('.shift-label').text('班別時間：09:00 ~ 18:00（請先選擇員工）');

    $tbody.append($template);
    initializeRow($template);
    reindexRows();
  }

  // JS功能10：檢查是否與班別重疊
  function hasShiftOverlap(startDay, endDay, startTime, endTime, shift) {
    if (!shift) return false;
    const startDateTime = new Date(`${startDay}T${startTime}:00`);
    const endDateTime = new Date(`${endDay}T${endTime}:00`);
    if (!(startDateTime instanceof Date) || Number.isNaN(startDateTime.getTime())) return false;
    if (!(endDateTime instanceof Date) || Number.isNaN(endDateTime.getTime())) return false;

    const shiftStartMinutes = timeToMinutes(shift.start_time);
    const shiftEndMinutes = timeToMinutes(shift.end_time);

    const cursor = new Date(startDateTime.getTime());
    cursor.setHours(0, 0, 0, 0);
    cursor.setDate(cursor.getDate() - 1);

    while (cursor <= endDateTime) {
      const dayStr = formatDate(cursor);
      const shiftStart = new Date(`${dayStr}T${shift.start_time}:00`);
      let shiftEnd = new Date(`${dayStr}T${shift.end_time}:00`);

      if (shiftStartMinutes !== null && shiftEndMinutes !== null && shiftStartMinutes >= shiftEndMinutes) {
        shiftEnd.setDate(shiftEnd.getDate() + 1);
      }

      if (startDateTime < shiftEnd && endDateTime > shiftStart) {
        return true;
      }

      cursor.setDate(cursor.getDate() + 1);
    }

    return false;
  }

  // JS功能11：前端表單驗證
  function validateForm() {
    let hasAnyFilled = false;
    let valid = true;

    $('#formContainer tr').each(function (index) {
      if (!valid) return false;
      const $row = $(this);
      const emp = $row.find("select[name='employee_number[]']").val();
      const startDay = $row.find("input[name='start_day[]']").val();
      const endDay = $row.find("input[name='end_day[]']").val();
      const startHour = $row.find("select[name='start_hour[]']").val();
      const endHour = $row.find("select[name='end_hour[]']").val();
      const reason = ($row.find("textarea[name='reason[]']").val() || '').trim();

      if (!emp && !startDay && !endDay && !startHour && !endHour && reason === '') {
        return;
      }

      hasAnyFilled = true;

      if (!emp || !startDay || !endDay || !startHour || !endHour) {
        alert(`第 ${index + 1} 筆資料尚未填寫完整。`);
        valid = false;
        return false;
      }

      const startDateTime = new Date(`${startDay}T${startHour}:00`);
      const endDateTime = new Date(`${endDay}T${endHour}:00`);

      if (Number.isNaN(startDateTime.getTime()) || Number.isNaN(endDateTime.getTime())) {
        alert(`第 ${index + 1} 筆的日期或時間格式有誤。`);
        valid = false;
        return false;
      }

      if (endDateTime <= startDateTime) {
        alert(`第 ${index + 1} 筆的結束時間需晚於起始時間。`);
        valid = false;
        return false;
      }

      const durationMinutes = (endDateTime - startDateTime) / 60000;
      if (durationMinutes < 30) {
        alert(`第 ${index + 1} 筆加班時數需至少 0.5 小時。`);
        valid = false;
        return false;
      }

      const shift = SHIFT_MAP[emp] || null;
      if (hasShiftOverlap(startDay, endDay, startHour, endHour, shift)) {
        alert(`第 ${index + 1} 筆加班時間與正常上班時間重疊，請重新調整。`);
        valid = false;
        return false;
      }
    });

    if (!valid) {
      return false;
    }

    if (!hasAnyFilled) {
      return confirm('您尚未輸入任何加班資料，確定要送出嗎？');
    }

    return true;
  }

  // JS功能12：頁面初始化流程
  $(document).ready(function () {
    $('#formContainer tr').each(function () {
      initializeRow($(this));
    });
    reindexRows();
  });
</script>
</body>
</html>