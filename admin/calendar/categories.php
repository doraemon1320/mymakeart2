<?php
/* [PHP-1] 初始化與權限（依你提供） */
require_once __DIR__ . '/../../db_connect.php';
date_default_timezone_set('Asia/Taipei');

session_start();
// ✅ 權限檢查：只允許 admin 或 is_manager = 1 的人進入
if (!isset($_SESSION['user']) || ($_SESSION['user']['role'] !== 'admin' && $_SESSION['user']['is_manager'] != 1)) {
    header("Location: ../../login.php");
    exit;
}
$user_id = $_SESSION['user']['id'];
$username = htmlspecialchars($_SESSION['user']['username']);
?>
<!doctype html>
<html lang="zh-Hant">
<head>
<meta charset="utf-8">
<title>行事曆分類管理</title>
<meta name="viewport" content="width=device-width,initial-scale=1">
<link href="https://cdn.jsdelivr.net/npm/bootstrap@5.3.3/dist/css/bootstrap.min.css" rel="stylesheet">
<link href="https://cdn.jsdelivr.net/npm/bootstrap-icons@1.11.3/font/bootstrap-icons.min.css" rel="stylesheet">
<script src="https://code.jquery.com/jquery-3.7.1.min.js"></script>
</head>
<body>
<div class="container py-4">
  <div class="d-flex justify-content-between align-items-center mb-3">
    <h3 class="mb-0">分類管理（自訂）</h3>
    <a href="/admin/calendar/index.php" class="btn btn-outline-secondary"><i class="bi bi-calendar3"></i> 回行事曆</a>
  </div>

  <!-- [HTML-2] 新增表單 -->
  <div class="card mb-3">
    <div class="card-body">
      <form id="createForm" class="row g-3" novalidate>
        <div class="col-12 col-md-5">
          <label class="form-label">名稱 <span class="text-danger">*</span></label>
          <input name="name" type="text" class="form-control" required>
          <div class="invalid-feedback">請輸入名稱</div>
        </div>
        <div class="col-12 col-md-5">
          <label class="form-label">顏色 HEX <span class="text-danger">*</span></label>
          <div class="input-group">
            <span class="input-group-text p-1"><input id="new_color" type="color" value="#6f42c1" style="width:32px;height:32px;border:0;"></span>
            <input id="new_hex" name="color_hex" type="text" class="form-control" placeholder="#6f42c1" required pattern="^#[0-9A-Fa-f]{6}$">
          </div>
          <div class="invalid-feedback">顏色格式需為 #RRGGBB</div>
        </div>
        <div class="col-12 col-md-2 d-flex align-items-end">
          <button class="btn btn-primary w-100" type="submit"><i class="bi bi-plus-circle"></i> 新增</button>
        </div>
      </form>
    </div>
  </div>

  <!-- [HTML-3] 列表 -->
  <div class="table-responsive">
    <table class="table table-bordered align-middle">
      <thead class="table-primary">
        <tr>
          <th style="width:60px">ID</th>
          <th>名稱</th>
          <th style="width:220px">顏色</th>
          <th style="width:120px">啟用</th>
          <th style="width:140px">被使用數</th>
          <th style="width:220px">操作</th>
        </tr>
      </thead>
      <tbody id="categoryBody"></tbody>
    </table>
  </div>
</div>

<!-- [HTML-4] Toast -->
<div class="position-fixed bottom-0 end-0 p-3" style="z-index:1080">
  <div id="toast" class="toast align-items-center text-bg-success border-0" role="alert" aria-live="assertive" aria-atomic="true">
    <div class="d-flex">
      <div class="toast-body" id="toastBody">完成</div>
      <button type="button" class="btn-close btn-close-white me-2 m-auto" data-bs-dismiss="toast"></button>
    </div>
  </div>
</div>

<script src="https://cdn.jsdelivr.net/npm/bootstrap@5.3.3/dist/js/bootstrap.bundle.min.js"></script>
<script>
/* [JS-1] Toast */
const toast = new bootstrap.Toast(document.getElementById('toast'));
function showToast(msg, ok=true){
  const $t = $('#toast');
  $t.toggleClass('text-bg-success', ok).toggleClass('text-bg-danger', !ok);
  $('#toastBody').text(msg);
  toast.show();
}

/* [JS-2] 載入列表 */
function loadList(){
  $.getJSON('/api/calendar/categories_list.php').then(res=>{
    const $tbody = $('#categoryBody').empty();
    if(!res.success) { showToast(res.message||'讀取失敗', false); return; }
    res.data.forEach(c=>{
      const tr = $(`
        <tr data-id="${c.id}">
          <td>${c.id}</td>
          <td><input class="form-control form-control-sm name" value="${c.name}" required></td>
          <td>
            <div class="input-group input-group-sm">
              <span class="input-group-text p-1"><input type="color" class="color_picker" value="${c.color_hex}" style="width:28px;height:28px;border:0;"></span>
              <input class="form-control color_hex" value="${c.color_hex}" pattern="^#[0-9A-Fa-f]{6}$">
            </div>
          </td>
          <td>
            <div class="form-check form-switch">
              <input class="form-check-input is_active" type="checkbox" ${c.is_active==1?'checked':''}>
            </div>
          </td>
          <td>${c.use_count}</td>
          <td class="d-flex gap-2">
            <button class="btn btn-sm btn-primary btn-save"><i class="bi bi-save"></i> 儲存</button>
            <button class="btn btn-sm btn-danger btn-del"><i class="bi bi-trash"></i> 刪除</button>
          </td>
        </tr>
      `);
      tr.find('.color_picker').on('input', function(){ tr.find('.color_hex').val(this.value); });
      tr.find('.btn-save').on('click', ()=>saveRow(tr));
      tr.find('.btn-del').on('click', ()=>delRow(tr, c.use_count, c.name));
      $tbody.append(tr);
    });
  });
}

/* [JS-3] 新增 */
$('#new_color').on('input', function(){ $('#new_hex').val(this.value); });
$('#createForm').on('submit', function(e){
  e.preventDefault();
  const form = this;
  if(!form.checkValidity()){ form.classList.add('was-validated'); return; }
  $.post('/api/calendar/categories_save.php', $(this).serialize()).then(res=>{
    try{res=JSON.parse(res);}catch(e){}
    if(res.success){ form.reset(); $('#new_color').val('#6f42c1'); showToast('已新增'); loadList(); }
    else{ showToast(res.message||'新增失敗', false); }
  });
});

/* [JS-4] 儲存列 */
function saveRow(tr){
  const id = tr.data('id');
  const name = tr.find('.name').val().trim();
  const hex = tr.find('.color_hex').val().trim();
  const is_active = tr.find('.is_active').prop('checked')?1:0;
  if(!name){ showToast('請輸入名稱', false); return; }
  if(!/^#[0-9A-Fa-f]{6}$/.test(hex)){ showToast('顏色格式需為 #RRGGBB', false); return; }

  const payload = { id, name, color_hex: hex, is_active };
  $.post('/api/calendar/categories_save.php', payload).then(res=>{
    try{res=JSON.parse(res);}catch(e){}
    if(res.success){ showToast('已更新'); loadList(); }
    else{ showToast(res.message||'更新失敗', false); }
  });
}

/* [JS-5] 刪除列 */
function delRow(tr, useCount, name){
  if(useCount>0){ showToast('此分類已有事件使用，不可刪除。請先改為未使用或停用。', false); return; }
  if(!confirm(`確定刪除「${name}」？`)) return;
  $.post('/api/calendar/categories_delete.php', {id: tr.data('id')}).then(res=>{
    try{res=JSON.parse(res);}catch(e){}
    if(res.success){ showToast('已刪除'); loadList(); }
    else{ showToast(res.message||'刪除失敗', false); }
  });
}

/* [JS-6] 初始化 */
$(function(){ loadList(); });
</script>
</body>
</html>
