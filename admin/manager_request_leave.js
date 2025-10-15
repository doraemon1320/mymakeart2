// ✅ manager_request_leave.js（已修正）
// v1.3 - 修正欄位名稱錯誤與無效 onchange 呼叫

// 全域常數
const shiftMap = SHIFT_MAP || {};
const leaveLimit = LEAVE_LIMIT || {};
const employeeOptions = EMPLOYEES.map(e => `<option value="${e.employee_number}">${e.employee_number} - ${e.name}</option>`).join('');
const leaveTypeOptions = LEAVETYPES.map(l => `<option value="${l}">${l}</option>`).join('');

// 預設載入五列
window.addEventListener('DOMContentLoaded', () => {
  for (let i = 0; i < 5; i++) addFormRow();
});

// ➕ 新增表單列
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
        <option value="特休假">特休假</option>
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

// 🔄 綁定欄位互動
function bindEvents(row) {
  const checkbox = row.querySelector(".fullday-check");
  const startInput = row.querySelector("input[name='start_date[]']");
  const endInput = row.querySelector("input[name='end_date[]']");

  checkbox.addEventListener("change", () => toggleTimeFields(row));
  startInput.addEventListener("change", () => enforceFullDayIfDateDiffers(row));
  endInput.addEventListener("change", () => enforceFullDayIfDateDiffers(row));

  toggleTimeFields(row);
}

// 🎯 勾選整天時自動帶入班別時間並鎖定
function toggleTimeFields(row) {
  const checkbox = row.querySelector(".fullday-check");
  const emp = row.querySelector("select[name='employee_number[]']").value;
  const [startSelect, endSelect] = row.querySelectorAll(".time-cell select");

  if (checkbox.checked) {
    if (shiftMap[emp]) {
      startSelect.innerHTML = `<option value="${shiftMap[emp].start_time}">${shiftMap[emp].start_time}</option>`;
      endSelect.innerHTML = `<option value="${shiftMap[emp].end_time}">${shiftMap[emp].end_time}</option>`;
    } else {
      startSelect.innerHTML = `<option value="">-</option>`;
      endSelect.innerHTML = `<option value="">-</option>`;
    }

    // ✅ 改成不 disabled，但加 readonly + 背景色提示
    startSelect.disabled = false;
    endSelect.disabled = false;
    startSelect.setAttribute("readonly", true);
    endSelect.setAttribute("readonly", true);
    startSelect.classList.add("bg-light");
    endSelect.classList.add("bg-light");
  } else {
    startSelect.innerHTML = generateTimeOptions();
    endSelect.innerHTML = generateTimeOptions();

    // ✅ 移除 readonly 與樣式
    startSelect.removeAttribute("readonly");
    endSelect.removeAttribute("readonly");
    startSelect.classList.remove("bg-light");
    endSelect.classList.remove("bg-light");

    startSelect.disabled = false;
    endSelect.disabled = false;
  }
}


// ⛔ 若跨日則強制整天
function enforceFullDayIfDateDiffers(row) {
  const start = row.querySelector("input[name='start_date[]']").value;
  const end = row.querySelector("input[name='end_date[]']").value;
  const checkbox = row.querySelector(".fullday-check");

  if (!start || !end) return;

  if (start !== end) {
    checkbox.checked = true;
    checkbox.disabled = true;
  } else {
    checkbox.disabled = false;
  }

  toggleTimeFields(row);
}

// ⏰ 產生 30 分單位的時間選項
function generateTimeOptions() {
  let options = '';
  for (let h = 0; h < 24; h++) {
    for (let m of [0, 30]) {
      const t = `${String(h).padStart(2, '0')}:${String(m).padStart(2, '0')}`;
      options += `<option value="${t}">${t}</option>`;
    }
  }
  return options;
}

// ✅ 重新編號 checkbox name 與補上 value=1
function reindexRows() {
  const checkboxes = document.querySelectorAll(".fullday-check");
  checkboxes.forEach((chk, i) => {
    chk.name = `fullday[${i}]`;
    chk.value = '1';
  });
}

// 🧪 前端表單驗證（防呆）
function validateForm() {
  const rows = document.querySelectorAll('#formContainer tr');
  let valid = true;

  rows.forEach((row, i) => {
    const emp = row.querySelector("select[name='employee_number[]']").value;
    const type = row.querySelector("select[name='subtype[]']").value;
    const start = row.querySelector("input[name='start_day[]']").value;
    const end = row.querySelector("input[name='end_date[]']").value;
    const checkbox = row.querySelector(".fullday-check");
    const start_time = row.querySelector("select[name='start_time[]']").value;
    const end_time = row.querySelector("select[name='end_time[]']").value;

    if (emp && type && start && end) {
      if (!checkbox.checked && (!start_time || !end_time || start_time === '-' || end_time === '-')) {
        alert(`第 ${i + 1} 筆請假時間未填寫完整`);
        valid = false;
      }
    }
  });

  return valid;
}
