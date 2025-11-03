// 【JS-1】全域常數與選項模板
const shiftMap = SHIFT_MAP || {};
const leaveLimit = LEAVE_LIMIT || {};
const leaveBaseLimit = LEAVE_BASE_LIMIT || {};
const employeeOptions = EMPLOYEES.map((e) => `<option value="${e.employee_number}">${e.employee_number} - ${e.name}</option>`).join('');
const leaveTypeList = Array.from(new Set([...LEAVETYPES, '特休假']));
const leaveTypeOptions = leaveTypeList.map((l) => `<option value="${l}">${l}</option>`).join('');
const employeeMap = EMPLOYEES.reduce((acc, emp) => {
  acc[emp.employee_number] = emp;
  return acc;
}, {});
let messageModal = null;
let messageModalLabel = null;
let messageModalBody = null;

// 【JS-2】時間換算工具
function timeToMinutes(timeStr) {
  if (!timeStr) return null;
  const [hour, minute] = timeStr.split(':').map(Number);
  return hour * 60 + minute;
}

function minutesToTime(minutes) {
  const hour = Math.floor(minutes / 60);
  const minute = minutes % 60;
  return `${String(hour).padStart(2, '0')}:${String(minute).padStart(2, '0')}`;
}

function isWithinBreak(minutes, breakStart, breakEnd) {
  if (breakStart === null || breakEnd === null) return false;
  return minutes > breakStart && minutes < breakEnd;
}

function formatNumber(value, digits = 1) {
  const num = Number(value);
  if (Number.isNaN(num)) return '0';
  if (Number.isInteger(num)) return String(num);
  return num.toFixed(digits);
}

// 【JS-3】浮動視窗初始化與提示工具
function initMessageModal() {
  const modalElement = document.getElementById('messageModal');
  if (!modalElement || typeof bootstrap === 'undefined') {
    return;
  }

  messageModal = new bootstrap.Modal(modalElement);
  messageModalLabel = modalElement.querySelector('#messageModalLabel');
  messageModalBody = modalElement.querySelector('#messageModalBody');

  modalElement.addEventListener('hidden.bs.modal', () => {
    if (initMessageModal.focusElement) {
      initMessageModal.focusElement.focus();
      initMessageModal.focusElement = null;
    }
  });
}

function showModalMessage(title, bodyHtml, focusElement = null) {
  if (!messageModal) {
    const plainTitle = title.replace(/<[^>]*>?/gm, '');
    const plainBody = bodyHtml.replace(/<[^>]*>?/gm, '');
    alert(`${plainTitle}\n${plainBody}`.trim()); // 後備方案
    return;
  }
  if (messageModalLabel) {
    messageModalLabel.textContent = title;
  }
  if (messageModalBody) {
    messageModalBody.innerHTML = bodyHtml;
  }
  initMessageModal.focusElement = focusElement;
  messageModal.show();
}

initMessageModal.focusElement = null;

// 【JS-4】頁面初始化
window.addEventListener('DOMContentLoaded', () => {
  initMessageModal();
  for (let i = 0; i < 5; i += 1) {
    addFormRow();
  }
});

// 【JS-5】新增表單列
function addFormRow() {
  const tbody = document.getElementById('formContainer');
  const row = document.createElement('tr');
  row.classList.add('align-middle');

  row.innerHTML = `
    <td>
      <select class="form-select" name="employee_number[]">
        <option value="">請選擇</option>
        ${employeeOptions}
      </select>
    </td>
    <td>
      <select class="form-select" name="subtype[]">
        <option value="">請選擇</option>
        ${leaveTypeOptions}
      </select>
    </td>
    <td><input type="date" class="form-control" name="start_date[]"></td>
    <td><input type="date" class="form-control" name="end_date[]"></td>
    <td class="text-center">
      <input type="checkbox" class="form-check-input fullday-check" checked>
    </td>
    <td class="time-cell">
      <select class="form-select" name="start_time[]"></select>
    </td>
    <td class="time-cell">
      <select class="form-select" name="end_time[]"></select>
    </td>
    <td><textarea class="form-control" name="reason[]" rows="1"></textarea></td>
  `;

  tbody.appendChild(row);
  bindEvents(row);
  reindexRows();
}

// 【JS-6】欄位事件綁定
function bindEvents(row) {
  const checkbox = row.querySelector('.fullday-check');
  const startInput = row.querySelector("input[name='start_date[]']");
  const endInput = row.querySelector("input[name='end_date[]']");
  const employeeSelect = row.querySelector("select[name='employee_number[]']");
  const leaveSelect = row.querySelector("select[name='subtype[]']");
  const startSelect = row.querySelector("select[name='start_time[]']");
  const endSelect = row.querySelector("select[name='end_time[]']");

  checkbox.addEventListener('change', () => {
    toggleTimeFields(row);
    validateTimeOrder(row);
  });
  startInput.addEventListener('change', () => {
    enforceFullDayIfDateDiffers(row);
    validateDateOrder(row);
  });
  endInput.addEventListener('change', () => {
    enforceFullDayIfDateDiffers(row);
    validateDateOrder(row);
  });
  employeeSelect.addEventListener('change', () => {
    enforceFullDayIfDateDiffers(row);
    toggleTimeFields(row);
    if (leaveSelect.value) {
      handleLeaveTypeChange(row);
    }
  });
  leaveSelect.addEventListener('change', () => handleLeaveTypeChange(row));
  startSelect.addEventListener('change', () => validateTimeOrder(row));
  endSelect.addEventListener('change', () => validateTimeOrder(row));

  toggleTimeFields(row);
}

// 【JS-7】依整天狀態更新時間欄位
function toggleTimeFields(row) {
  const checkbox = row.querySelector('.fullday-check');
  const employeeNumber = row.querySelector("select[name='employee_number[]']").value;
  const [startSelect, endSelect] = row.querySelectorAll('.time-cell select');

  if (!employeeNumber) {
    const placeholder = '<option value="">請先選擇員工</option>';
    startSelect.innerHTML = placeholder;
    endSelect.innerHTML = placeholder;
    startSelect.disabled = true;
    endSelect.disabled = true;
    startSelect.removeAttribute('readonly');
    endSelect.removeAttribute('readonly');
    startSelect.classList.remove('bg-light');
    endSelect.classList.remove('bg-light');
    return;
  }

  startSelect.disabled = false;
  endSelect.disabled = false;

  if (checkbox.checked) {
    const shift = shiftMap[employeeNumber] || {};
    const startValue = shift.start_time || '';
    const endValue = shift.end_time || '';
    startSelect.innerHTML = startValue ? `<option value="${startValue}">${startValue}</option>` : '<option value="">無可請假時段</option>';
    endSelect.innerHTML = endValue ? `<option value="${endValue}">${endValue}</option>` : '<option value="">無可請假時段</option>';
    startSelect.value = startValue;
    endSelect.value = endValue;
    startSelect.setAttribute('readonly', true);
    endSelect.setAttribute('readonly', true);
    startSelect.classList.add('bg-light');
    endSelect.classList.add('bg-light');
  } else {
    const previousStart = startSelect.value;
    const previousEnd = endSelect.value;
    const options = generateTimeOptions(employeeNumber);
    startSelect.innerHTML = options;
    endSelect.innerHTML = options;

    startSelect.removeAttribute('readonly');
    endSelect.removeAttribute('readonly');
    startSelect.classList.remove('bg-light');
    endSelect.classList.remove('bg-light');

    if ([...startSelect.options].some((opt) => opt.value === previousStart)) {
      startSelect.value = previousStart;
    }
    if ([...endSelect.options].some((opt) => opt.value === previousEnd)) {
      endSelect.value = previousEnd;
    }
  }
}

// 【JS-8】跨日強制整天
function enforceFullDayIfDateDiffers(row) {
  const start = row.querySelector("input[name='start_date[]']").value;
  const end = row.querySelector("input[name='end_date[]']").value;
  const checkbox = row.querySelector('.fullday-check');

  if (!start || !end) {
    toggleTimeFields(row);
    return;
  }

  if (start !== end) {
    checkbox.checked = true;
    checkbox.disabled = true;
  } else {
    checkbox.disabled = false;
  }

  toggleTimeFields(row);
}

// 【JS-9】依班別產生 30 分鐘區間
function generateTimeOptions(employeeNumber) {
  const shift = shiftMap[employeeNumber];
  if (!shift || !shift.start_time || !shift.end_time) {
    return '<option value="">無可請假時段</option>';
  }

  const startMinutes = timeToMinutes(shift.start_time);
  const endMinutes = timeToMinutes(shift.end_time);
  const breakStart = timeToMinutes(shift.break_start);
  const breakEnd = timeToMinutes(shift.break_end);

  if (startMinutes === null || endMinutes === null || startMinutes >= endMinutes) {
    return '<option value="">無可請假時段</option>';
  }

  let options = '<option value="">請選擇</option>';
  for (let minute = startMinutes; minute <= endMinutes; minute += 30) {
    if (minute !== startMinutes && minute !== endMinutes && isWithinBreak(minute, breakStart, breakEnd)) {
      continue;
    }
    options += `<option value="${minutesToTime(minute)}">${minutesToTime(minute)}</option>`;
  }
  return options;
}

// 【JS-10】checkbox 重新編號
function reindexRows() {
  document.querySelectorAll('.fullday-check').forEach((chk, index) => {
    chk.name = `fullday[${index}]`;
    chk.value = '1';
  });
}

// 【JS-11】假別資訊提示
function handleLeaveTypeChange(row) {
  const employeeNumber = row.querySelector("select[name='employee_number[]']").value;
  const leaveType = row.querySelector("select[name='subtype[]']").value;

  if (!leaveType) {
    return;
  }
  if (!employeeNumber) {
    row.querySelector("select[name='subtype[]']").value = '';
    showModalMessage('請先選擇員工', '<p class="mb-0">請先在該列選擇員工，再查看假別使用資訊。</p>');
    return;
  }

  const employeeInfo = employeeMap[employeeNumber] || {};
  const employeeLabel = `${employeeNumber} ${employeeInfo.name || ''}`.trim();
  const detailMap = leaveLimit[employeeNumber] || {};
  const detail = detailMap[leaveType] || null;
  const baseLimit = Number(leaveBaseLimit[leaveType] ?? 0);
  const limitDays = Number(detail ? detail.limit : baseLimit);
  const usedDays = Number(detail ? detail.used_days : 0);
  const usedHours = Number(detail ? detail.used_hours : 0);
  const remainDays = Number(detail ? detail.remain_days : Math.max(limitDays - usedDays, 0));
  const remainHours = Number(
    detail ? detail.remain_hours : Math.max(limitDays * 8 - (usedDays * 8 + usedHours), 0),
  );

  const infoTable = `
    <div class="mb-3 fw-bold">${employeeLabel}</div>
    <div class="table-responsive">
      <table class="table table-bordered text-center mb-0">
        <thead class="table-primary">
          <tr>
            <th>假別</th>
            <th>年度上限(天)</th>
            <th>已用(天)</th>
            <th>已用(小時)</th>
            <th>剩餘(天)</th>
            <th>剩餘(小時)</th>
          </tr>
        </thead>
        <tbody>
          <tr>
            <td>${leaveType}</td>
            <td>${formatNumber(limitDays)}</td>
            <td>${formatNumber(usedDays)}</td>
            <td>${formatNumber(usedHours)}</td>
            <td>${formatNumber(remainDays)}</td>
            <td>${formatNumber(remainHours)}</td>
          </tr>
        </tbody>
      </table>
    </div>
  `;

  showModalMessage('假別使用情況', infoTable);
}

// 【JS-12】結束日期與時間順序檢查
function validateDateOrder(row) {
  const startInput = row.querySelector("input[name='start_date[]']");
  const endInput = row.querySelector("input[name='end_date[]']");
  const start = startInput.value;
  const end = endInput.value;

  if (start && end && end < start) {
    endInput.value = '';
    showModalMessage('日期順序提醒', '<p class="mb-0">結束日不可早於起始日，請重新選擇。</p>', endInput);
  }
}

function validateTimeOrder(row) {
  const checkbox = row.querySelector('.fullday-check');
  if (checkbox.checked) {
    return;
  }

  const startSelect = row.querySelector("select[name='start_time[]']");
  const endSelect = row.querySelector("select[name='end_time[]']");
  const startTime = startSelect.value;
  const endTime = endSelect.value;

  if (startTime && endTime && timeToMinutes(endTime) <= timeToMinutes(startTime)) {
    endSelect.value = '';
    showModalMessage('時間順序提醒', '<p class="mb-0">結束時間需大於起始時間，請重新選擇。</p>', endSelect);
  }
}

// 【JS-13】送出前檢核
function validateForm() {
  const rows = document.querySelectorAll('#formContainer tr');

  for (let i = 0; i < rows.length; i += 1) {
    const row = rows[i];
    const emp = row.querySelector("select[name='employee_number[]']").value;
    const type = row.querySelector("select[name='subtype[]']").value;
    const start = row.querySelector("input[name='start_date[]']").value;
    const end = row.querySelector("input[name='end_date[]']").value;
    const checkbox = row.querySelector('.fullday-check');
    const startTimeSelect = row.querySelector("select[name='start_time[]']");
    const endTimeSelect = row.querySelector("select[name='end_time[]']");
    const startTime = startTimeSelect.value;
    const endTime = endTimeSelect.value;

    if (!emp && !type && !start && !end && !startTime && !endTime) {
      continue;
    }

    if (!emp || !type || !start || !end) {
      showModalMessage(
        `第 ${i + 1} 筆欄位未完成`,
        '<p class="mb-0">請確認員工、假別及起訖日期皆已填寫完整。</p>',
        !emp ? row.querySelector("select[name='employee_number[]']") : !type
          ? row.querySelector("select[name='subtype[]']")
          : !start
            ? row.querySelector("input[name='start_date[]']")
            : row.querySelector("input[name='end_date[]']"),
      );
      return false;
    }

    if (!checkbox.checked) {
      if (!startTime || !endTime) {
        showModalMessage(
          `第 ${i + 1} 筆時間未填寫完整`,
          '<p class="mb-0">請填寫起始與結束時間，或改勾選整天。</p>',
          !startTime ? startTimeSelect : endTimeSelect,
        );
        return false;
      }
      if (timeToMinutes(endTime) <= timeToMinutes(startTime)) {
        showModalMessage(
          `第 ${i + 1} 筆時間順序錯誤`,
          '<p class="mb-0">結束時間需大於起始時間，請重新選擇。</p>',
          endTimeSelect,
        );
        return false;
      }
    }
  }

  return true;
}