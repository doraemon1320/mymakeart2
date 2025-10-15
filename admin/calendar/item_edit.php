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

/* [PHP-2] 編輯模式載入資料 */
$id = isset($_GET['id']) ? intval($_GET['id']) : 0;
$event = null;
if ($id>0) {
  $stmt = $conn->prepare("
    SELECT ci.*, cc.name AS category_name, cc.color_hex AS category_color
    FROM calendar_items ci
    LEFT JOIN calendar_categories cc ON cc.id=ci.category_id
    WHERE ci.id=?");
  $stmt->bind_param('i', $id);
  $stmt->execute();
  $event = $stmt->get_result()->fetch_assoc();
  $stmt->close();
}
?>
<!doctype html>
<html lang="zh-Hant">
<head>
<meta charset="utf-8">
<title><?php echo $id? '編輯事件':'新增事件'; ?></title>
<meta name="viewport" content="width=device-width,initial-scale=1">
<!-- [HTML-1] Bootstrap + Icons + jQuery -->
<link href="https://cdn.jsdelivr.net/npm/bootstrap@5.3.3/dist/css/bootstrap.min.css" rel="stylesheet">
<link href="https://cdn.jsdelivr.net/npm/bootstrap-icons@1.11.3/font/bootstrap-icons.min.css" rel="stylesheet">
<script src="https://code.jquery.com/jquery-3.7.1.min.js"></script>
<style>
/* [HTML-2] Sticky 操作列與色塊預覽 */
.sticky-actions{position:sticky; bottom:0; z-index:100; background:#fff; border-top:1px solid #dee2e6; padding:.75rem;}
.color-preview{width:28px; height:28px; border-radius:.35rem; border:1px solid #ced4da;}
</style>
</head>
<body>
<div class="container py-4">
  <!-- [HTML-3] 頁首 -->
  <div class="row mb-3">
    <div class="col">
      <h3 class="mb-0"><?php echo $id? '編輯事件':'新增事件'; ?></h3>
      <div class="text-muted">清楚「送出」按鈕與完整驗證</div>
    </div>
    <div class="col-auto"><a href="/admin/calendar/index.php" class="btn btn-outline-secondary"><i class="bi bi-calendar3"></i> 回行事曆</a></div>
  </div>

  <!-- [HTML-4] 表單卡片 -->
  <form id="eventForm" class="row g-3" novalidate>
    <input type="hidden" name="id" value="<?php echo $event['id'] ?? ''; ?>">
    <div class="col-12">
      <div class="card">
        <div class="card-body">
          <!-- [HTML-4-1] 基本資訊 -->
          <div class="row g-3">
            <div class="col-12 col-md-8">
              <label class="form-label">標題 <span class="text-danger">*</span></label>
              <input name="title" type="text" class="form-control" required value="<?php echo htmlspecialchars($event['title'] ?? ''); ?>">
              <div class="invalid-feedback">請輸入標題</div>
            </div>
            <div class="col-12 col-md-4">
              <label class="form-label">類型</label>
              <input type="text" class="form-control" value="custom（自訂）" disabled>
              <input type="hidden" name="type" value="custom">
            </div>

            <div class="col-6 col-md-3">
              <label class="form-label">開始日期 <span class="text-danger">*</span></label>
              <input id="start_date" name="start_date" type="date" class="form-control" required value="<?php
                echo isset($event['start_datetime'])? substr($event['start_datetime'],0,10) : date('Y-m-d'); ?>">
              <div class="invalid-feedback">請選擇開始日期</div>
            </div>
            <div class="col-6 col-md-3">
              <label class="form-label">開始時間</label>
              <input id="start_time" name="start_time" type="time" class="form-control" value="<?php
                echo isset($event['start_datetime'])? substr($event['start_datetime'],11,5) : '09:00'; ?>">
            </div>
            <div class="col-6 col-md-3">
              <label class="form-label">結束日期 <span class="text-danger">*</span></label>
              <input id="end_date" name="end_date" type="date" class="form-control" required value="<?php
                echo isset($event['end_datetime'])? substr($event['end_datetime'],0,10) : date('Y-m-d'); ?>">
              <div class="invalid-feedback">請選擇結束日期</div>
            </div>
            <div class="col-6 col-md-3">
              <label class="form-label">結束時間</label>
              <input id="end_time" name="end_time" type="time" class="form-control" value="<?php
                echo isset($event['end_datetime'])? substr($event['end_datetime'],11,5) : '18:00'; ?>">
            </div>

            <div class="col-12">
              <div class="form-check">
                <input id="all_day" name="all_day" class="form-check-input" type="checkbox" value="1" <?php
                  echo (isset($event['all_day']) && $event['all_day'])? 'checked':''; ?>>
                <label class="form-check-label" for="all_day">整天（自動使用 00:00–23:59）</label>
              </div>
            </div>

            <div class="col-12 col-md-4">
              <label class="form-label">分類</label>
              <select name="category_id" id="category_id" class="form-select">
                <option value="">（無）</option>
                <?php
                  $r = $conn->query("SELECT id,name,color_hex FROM calendar_categories WHERE is_active=1 ORDER BY name");
                  while($row=$r->fetch_assoc()){
                    $sel = (isset($event['category_id']) && $event['category_id']==$row['id'])? 'selected':'';
                    echo "<option data-color='{$row['color_hex']}' value='{$row['id']}' {$sel}>".htmlspecialchars($row['name'])."</option>";
                  }
                ?>
              </select>
              <div class="form-text">若未選擇顏色，將使用分類顏色</div>
            </div>

            <div class="col-12 col-md-4">
              <label class="form-label">顏色（覆蓋分類色）</label>
              <div class="input-group">
                <span class="input-group-text p-1"><input id="color_picker" type="color" value="<?php echo htmlspecialchars($event['color_hex'] ?? '#6f42c1'); ?>" style="width:32px;height:32px;border:0;"></span>
                <input id="color_hex" name="color_hex" type="text" class="form-control" placeholder="#6f42c1"
                       value="<?php echo htmlspecialchars($event['color_hex'] ?? ''); ?>" pattern="^#[0-9A-Fa-f]{6}$">
                <span class="input-group-text"><div id="color_preview" class="color-preview"></div></span>
              </div>
              <div class="form-text">留空則套用分類色</div>
              <div class="invalid-feedback">顏色格式需為 #RRGGBB</div>
            </div>

            <div class="col-12 col-md-4">
              <label class="form-label">可見性</label>
              <select name="visibility" class="form-select">
                <?php
                  $vis = $event['visibility'] ?? 'internal';
                  foreach(['public'=>'公開','internal'=>'內部'] as $k=>$v){
                    $sel = $vis===$k ? 'selected':'';
                    echo "<option value='{$k}' {$sel}>{$v}</option>";
                  }
                ?>
              </select>
            </div>

            <div class="col-12 col-md-6">
              <label class="form-label">地點</label>
              <input name="location" type="text" class="form-control" value="<?php echo htmlspecialchars($event['location'] ?? ''); ?>">
            </div>

            <div class="col-12">
              <label class="form-label">備註</label>
              <textarea name="note" class="form-control" rows="4"><?php echo htmlspecialchars($event['note'] ?? ''); ?></textarea>
            </div>
          </div>
        </div>

        <!-- [HTML-4-2] Sticky 操作列：清楚的「送出」按鈕 -->
        <div class="sticky-actions">
          <div class="d-flex gap-2">
            <button id="btnSubmitBack" type="submit" class="btn btn-primary">
              <span class="spinner-border spinner-border-sm me-1 d-none" id="spBack"></span>
              送出並返回
            </button>
            <button id="btnSubmitStay" type="button" class="btn btn-outline-primary">
              <span class="spinner-border spinner-border-sm me-1 d-none" id="spStay"></span>
              送出並留在此
            </button>
            <a href="/admin/calendar/index.php" class="btn btn-secondary ms-auto">取消</a>
            <?php if($id): ?>
              <button id="btnDelete" type="button" class="btn btn-danger"><i class="bi bi-trash"></i> 刪除</button>
            <?php endif; ?>
          </div>
        </div>
      </div>
    </div>
  </form>
</div>

<!-- [HTML-5] Toast 容器 -->
<div class="position-fixed bottom-0 end-0 p-3" style="z-index:1080">
  <div id="toast" class="toast align-items-center text-bg-success border-0" role="alert" aria-live="assertive" aria-atomic="true">
    <div class="d-flex">
      <div class="toast-body" id="toastBody">已儲存</div>
      <button type="button" class="btn-close btn-close-white me-2 m-auto" data-bs-dismiss="toast" aria-label="Close"></button>
    </div>
  </div>
</div>

<script src="https://cdn.jsdelivr.net/npm/bootstrap@5.3.3/dist/js/bootstrap.bundle.min.js"></script>
<script>
/* [JS-1] 工具：顯示 Toast */
const toast = new bootstrap.Toast(document.getElementById('toast'));
function showToast(msg, ok=true){
  const $t = $('#toast');
  $t.toggleClass('text-bg-success', ok).toggleClass('text-bg-danger', !ok);
  $('#toastBody').text(msg);
  toast.show();
}

/* [JS-2] All-day 切換 + 顏色預覽 + 分類預設色提示 */
function toggleAllDay(disabled){
  $('#start_time,#end_time').prop('disabled', disabled).toggleClass('bg-light', disabled);
}
function setPreviewColor(hex){
  $('#color_preview').css('background', hex||'#ffffff');
}
function normalizeHex(v){
  if(!v) return '';
  if(/^#[0-9A-Fa-f]{6}$/.test(v)) return v.toUpperCase();
  return v;
}

$(function(){
  toggleAllDay($('#all_day').prop('checked'));
  $('#all_day').on('change', function(){ toggleAllDay(this.checked); });

  // 顏色同步
  setPreviewColor($('#color_hex').val() || $('#color_picker').val());
  $('#color_picker').on('input', function(){
    if(!$('#color_hex').val()) setPreviewColor(this.value);
  });
  $('#color_hex').on('input', function(){
    setPreviewColor(normalizeHex(this.value));
  });
  $('#category_id').on('change', function(){
    if(!$('#color_hex').val()){
      const hex = $(this).find('option:selected').data('color') || '#6f42c1';
      setPreviewColor(hex);
    }
  });

  /* [JS-3] 驗證 + 送出（兩種行為） */
  function gatherAndValidate(){
    // HTML5 required 驗證
    const form = document.getElementById('eventForm');
    if(!form.checkValidity()){
      form.classList.add('was-validated');
      return null;
    }
    // 進一步時間檢查
    const allDay = $('#all_day').prop('checked');
    let sd = $('#start_date').val(), ed = $('#end_date').val();
    let st = allDay ? '00:00' : ($('#start_time').val() || '00:00');
    let et = allDay ? '23:59' : ($('#end_time').val() || '23:59');
    const start = new Date(`${sd}T${st}:00`);
    const end   = new Date(`${ed}T${et}:00`);
    if (start > end){
      showToast('開始時間不可晚於結束時間', false);
      $('#end_date,#end_time').addClass('is-invalid');
      return null;
    } else {
      $('#end_date,#end_time').removeClass('is-invalid');
    }
    // 顏色格式
    const hex = $('#color_hex').val().trim();
    if (hex && !/^#[0-9A-Fa-f]{6}$/.test(hex)){
      showToast('顏色格式需為 #RRGGBB', false);
      $('#color_hex').addClass('is-invalid');
      return null;
    } else {
      $('#color_hex').removeClass('is-invalid');
    }
    // 序列化
    return $('#eventForm').serialize();
  }

  function doSubmit(redirect){
    const payload = gatherAndValidate();
    if (!payload) return;

    // 按鈕狀態
    $('#btnSubmitBack,#btnSubmitStay').prop('disabled', true);
    (redirect?$('#spBack'):$('#spStay')).removeClass('d-none');

    $.post('/api/calendar/item_save.php', payload).then(res=>{
      try{res=JSON.parse(res);}catch(e){}
      if(res.success){
        showToast('已儲存', true);
        if (redirect){
          setTimeout(()=>window.location.href='/admin/calendar/index.php', 300);
        } else {
          // 留在頁面，若為新增則轉為編輯模式
          if (!$('input[name=id]').val() && res.id){
            window.location.href='/admin/calendar/item_edit.php?id='+res.id;
          }
        }
      }else{
        showToast(res.message||'儲存失敗', false);
      }
    }).fail(()=>{
      showToast('連線失敗', false);
    }).always(()=>{
      $('#btnSubmitBack,#btnSubmitStay').prop('disabled', false);
      $('#spBack,#spStay').addClass('d-none');
    });
  }

  // 送出並返回
  $('#btnSubmitBack').on('click', function(e){ e.preventDefault(); doSubmit(true); });
  // 送出並留在此
  $('#btnSubmitStay').on('click', function(){ doSubmit(false); });

  /* [JS-4] 刪除 */
  $('#btnDelete').on('click', function(){
    if(!confirm('確定刪除？')) return;
    $.post('/api/calendar/item_delete.php', {id:$('input[name=id]').val()}).then(res=>{
      try{res=JSON.parse(res);}catch(e){}
      if(res.success){ window.location.href='/admin/calendar/index.php'; }
      else{ showToast(res.message||'刪除失敗', false); }
    });
  });
});
</script>
</body>
</html>
