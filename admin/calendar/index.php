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

/* [PHP-2] 月份預設（本月），支援 URL ?ym=YYYY-MM */
$ym = isset($_GET['ym']) ? $_GET['ym'] : date('Y-m');
$firstDay = DateTime::createFromFormat('Y-m-d', $ym . '-01');
if (!$firstDay) $firstDay = new DateTime('first day of this month');
$viewYear = (int)$firstDay->format('Y');
$viewMonth = (int)$firstDay->format('m');
?>
<!doctype html>
<html lang="zh-Hant">
<head>
<meta charset="utf-8">
<title>行事曆總覽</title>
<meta name="viewport" content="width=device-width,initial-scale=1">
<!-- [HTML-1] Bootstrap 5 + Icons + jQuery -->
<link href="https://cdn.jsdelivr.net/npm/bootstrap@5.3.3/dist/css/bootstrap.min.css" rel="stylesheet">
<link href="https://cdn.jsdelivr.net/npm/bootstrap-icons@1.11.3/font/bootstrap-icons.min.css" rel="stylesheet">
<script src="https://code.jquery.com/jquery-3.7.1.min.js"></script>
<style>
/* [HTML-2] 月曆樣式（週日為首；跨月淡色；今日高亮） */
.calendar-table td{vertical-align: top; height:132px; position:relative;}
.calendar-table .date-badge{position:absolute; top:6px; right:8px; font-size:.85rem; opacity:.85;}
.event-chip{display:block; white-space:nowrap; overflow:hidden; text-overflow:ellipsis; padding:2px 6px; margin:2px 0; border-radius:.25rem; font-size:.8rem; color:#fff;}
.event-chip:hover{filter:brightness(1.05);}
.event-more{font-size:.8rem; margin-top:2px;}
.table-day-other{background:#fbfcfd; color:#6c757d;}
.table-day-other .date-badge{opacity:.45;}
.table-day-other .event-chip{opacity:.7;}
.table-day-today, .table-day-today.table-primary{background:#cfe2ff!important;}
.legend-badge{display:inline-block; padding:.25rem .5rem; border-radius:.35rem; color:#fff; font-size:.8rem; margin-right:.5rem; margin-bottom:.5rem;}
/* 載入遮罩 */
.loading-overlay{position:fixed; inset:0; background:rgba(255,255,255,.6); display:none; align-items:center; justify-content:center; z-index:1050;}
/* 空狀態 */
.empty-state{border:1px dashed #ced4da; border-radius:.5rem; padding:1rem; text-align:center; color:#6c757d;}
</style>
</head>
<body>
<div class="container py-4">

  <!-- [HTML-3] 標題列與操作 -->
  <div class="row align-items-center mb-3">
    <div class="col">
      <h3 class="mb-0">行事曆總覽</h3>
      <div class="text-muted">月視圖（週日開始）</div>
    </div>
    <div class="col-auto d-flex gap-2">
      <a class="btn btn-outline-secondary" href="/admin/calendar/categories.php"><i class="bi bi-tags"></i> 分類管理</a>
      <a class="btn btn-success" href="/admin/calendar/item_edit.php"><i class="bi bi-plus-lg"></i> 新增事件</a>
    </div>
  </div>

  <!-- [HTML-4] 篩選卡片 -->
  <div class="card mb-3">
    <div class="card-body">
      <div class="row g-3">
        <div class="col-12 col-md-4">
          <label class="form-label">事件來源</label>
          <div class="form-check">
            <input class="form-check-input filter-type" type="checkbox" value="custom" id="type_custom" checked>
            <label class="form-check-label" for="type_custom">其他自訂</label>
          </div>
          <div class="form-text">本階段僅顯示「自訂」。</div>
        </div>
        <div class="col-12 col-md-4">
          <label class="form-label" for="category_ids">分類</label>
          <select id="category_ids" class="form-select" multiple></select>
          <div class="form-text">僅列出啟用中的分類</div>
        </div>
        <div class="col-12 col-md-4">
          <label class="form-label" for="q">關鍵字</label>
          <input id="q" type="text" class="form-control" placeholder="搜尋標題或地點">
        </div>
      </div>
    </div>
  </div>

  <!-- [HTML-5] 月份切換工具列 -->
  <div class="d-flex justify-content-between align-items-center mb-2">
    <div class="btn-group btn-group-sm" role="group">
      <button id="btnPrev" class="btn btn-outline-secondary"><i class="bi bi-caret-left-fill"></i> 上月</button>
      <button id="btnToday" class="btn btn-outline-primary"><i class="bi bi-geo-alt"></i> 今天</button>
      <button id="btnNext" class="btn btn-outline-secondary">下月 <i class="bi bi-caret-right-fill"></i></button>
    </div>
    <h5 id="ymTitle" class="mb-0"></h5>
  </div>

  <!-- [HTML-6] 月曆表格（週日到週六） -->
  <div class="table-responsive">
    <table id="calendarTable" class="table table-bordered calendar-table">
      <thead class="table-primary">
        <tr>
          <th>日</th><th>一</th><th>二</th><th>三</th><th>四</th><th>五</th><th>六</th>
        </tr>
      </thead>
      <tbody id="calendarBody"></tbody>
    </table>
  </div>

  <!-- [HTML-7] 圖例 -->
  <div id="legend" class="mb-3"></div>

  <!-- [HTML-8] 空狀態 -->
  <div id="emptyState" class="empty-state d-none"><i class="bi bi-calendar4-week"></i> 本月沒有符合條件的事件</div>

  <!-- [HTML-9] 事件詳情 Modal -->
  <div class="modal fade" id="eventModal" tabindex="-1" aria-hidden="true">
    <div class="modal-dialog modal-dialog-scrollable">
      <div class="modal-content">
        <div class="modal-header">
          <h5 class="modal-title"><i class="bi bi-info-circle"></i> 事件詳情</h5>
          <button type="button" class="btn-close" data-bs-dismiss="modal"></button>
        </div>
        <div class="modal-body">
          <div id="eventDetail"></div>
        </div>
        <div class="modal-footer">
          <a id="editLink" class="btn btn-primary">編輯</a>
          <button id="deleteBtn" class="btn btn-danger">刪除</button>
          <button type="button" class="btn btn-secondary" data-bs-dismiss="modal">關閉</button>
        </div>
      </div>
    </div>
  </div>

</div>

<!-- [HTML-10] 全域載入遮罩 -->
<div class="loading-overlay" id="loadingOverlay">
  <div class="spinner-border text-primary" role="status" aria-label="loading"></div>
</div>

<!-- [HTML-11] Bootstrap JS -->
<script src="https://cdn.jsdelivr.net/npm/bootstrap@5.3.3/dist/js/bootstrap.bundle.min.js"></script>
<script>
/* [JS-1] 全域狀態 */
let viewYear = <?php echo $viewYear; ?>;
let viewMonth = <?php echo $viewMonth; ?>; // 1-12
let cachedEvents = {}; // key: 'YYYY-MM' → 陣列
const modal = new bootstrap.Modal(document.getElementById('eventModal'));
const overlay = $('#loadingOverlay');

/* [JS-2] 載入分類多選 + 圖例 */
let _categories = [];
function loadCategories() {
  return $.getJSON('/api/calendar/categories_list.php', {active:1}).then(res => {
    const $sel = $('#category_ids').empty();
    _categories = res.success ? res.data : [];
    if (res.success) {
      res.data.forEach(c => {
        $sel.append(`<option value="${c.id}">${c.name} (${c.use_count})</option>`);
      });
      // 圖例
      const $legend = $('#legend').empty();
      res.data.forEach(c=>{
        $legend.append(`<span class="legend-badge" style="background:${c.color_hex}">${c.name}</span>`);
      });
      // 若無分類也顯示自訂顏色說明
      if (res.data.length===0){
        $legend.append(`<span class="legend-badge" style="background:#6f42c1">自訂</span>`);
      }
    }
  });
}

/* [JS-3] 週日為首的月格 */
function buildMonthGrid(year, month) {
  $('#ymTitle').text(`${year} 年 ${String(month).padStart(2,'0')} 月`);
  const first = new Date(year, month-1, 1);
  const dayOfWeek = first.getDay();       // 0=Sun..6=Sat
  const start = new Date(first);
  start.setDate(first.getDate() - dayOfWeek);

  const $body = $('#calendarBody').empty();
  for (let w=0; w<6; w++) {
    const tr = $('<tr/>');
    for (let d=0; d<7; d++) {
      const cellDate = new Date(start);
      cellDate.setDate(start.getDate() + w*7 + d);
      const y = cellDate.getFullYear();
      const m = cellDate.getMonth()+1;
      const dd = cellDate.getDate();
      const today = new Date();
      const isThisMonth = (m === month);
      const isToday = (y===today.getFullYear() && m===today.getMonth()+1 && dd===today.getDate());

      const td = $('<td/>')
        .attr('data-date', `${y}-${String(m).padStart(2,'0')}-${String(dd).padStart(2,'0')}`)
        .toggleClass('table-day-other', !isThisMonth)   // 跨月淡色
        .toggleClass('table-primary', isToday)          // 今日背景
        .append(`<span class="date-badge badge ${isToday?'bg-primary':'bg-secondary'}">${dd}</span>`)
        .append('<div class="events"></div>');
      tr.append(td);
    }
    $body.append(tr);
  }
}

/* [JS-4] 取得當月事件 */
function fetchMonthEvents(year, month) {
  const key = `${year}-${String(month).padStart(2,'0')}`;
  if (cachedEvents[key]) return $.Deferred().resolve(cachedEvents[key]).promise();

  const start = new Date(year, month-1, 1);
  const end = new Date(year, month, 0);
  const params = {
    start: `${start.getFullYear()}-${String(start.getMonth()+1).padStart(2,'0')}-01`,
    end: `${end.getFullYear()}-${String(end.getMonth()+1).padStart(2,'0')}-${String(end.getDate()).padStart(2,'0')}`,
    types: $('.filter-type:checked').map((_,el)=>el.value).get().join(','),
    q: $('#q').val(),
    category_ids: ($('#category_ids').val()||[]).join(',')
  };
  overlay.show();
  return $.getJSON('/api/calendar/list.php', params).then(res => {
    overlay.hide();
    if (res.success) cachedEvents[key] = res.data;
    return res.data || [];
  }).fail(()=>overlay.hide());
}

/* [JS-5] 渲染事件 + 空狀態 + Tooltip */
function renderEvents(year, month, events) {
  $('#emptyState').toggleClass('d-none', events.length>0);
  $('#calendarBody td .events').empty();

  // 依天分組
  const map = {};
  events.forEach(ev => {
    const sd = new Date(ev.start_datetime.replace(' ','T'));
    const ed = new Date(ev.end_datetime.replace(' ','T'));
    for (let d = new Date(sd); d <= ed; d.setDate(d.getDate()+1)) {
      const key = `${d.getFullYear()}-${String(d.getMonth()+1).padStart(2,'0')}-${String(d.getDate()).padStart(2,'0')}`;
      (map[key] ||= []).push(ev);
    }
  });

  $('#calendarBody td').each(function(){
    const dateStr = $(this).data('date');
    const arr = map[dateStr] || [];
    const $box = $(this).find('.events');
    let shown = 0;
    const maxShow = 4;

    arr.forEach(ev => {
      if (shown >= maxShow) return;
      const color = ev.color_hex || ev.category_color || '#6f42c1';
      const timeLabel = ev.all_day ? '整天' :
        (new Date(ev.start_datetime.replace(' ','T')).toTimeString().slice(0,5) +
        '–' + new Date(ev.end_datetime.replace(' ','T')).toTimeString().slice(0,5));
      const loc = ev.location?`＠${ev.location}`:'';
      const chip = $(
        `<a href="javascript:;" class="event-chip" style="background:${color}" data-id="${ev.id}" data-bs-toggle="tooltip" title="${timeLabel} ${loc}">
           ${ev.title}
         </a>`
      );
      chip.on('click', () => openEvent(ev.id));
      $box.append(chip);
      shown++;
    });
    if (arr.length > shown) {
      $box.append($(`<div class="event-more text-muted">+${arr.length - shown}</div>`));
    }
  });

  // 啟用 Bootstrap Tooltip
  const tooltipTriggerList = document.querySelectorAll('[data-bs-toggle="tooltip"]');
  [...tooltipTriggerList].forEach(el => new bootstrap.Tooltip(el));
}

/* [JS-6] 事件詳情 */
function openEvent(id){
  $.getJSON('/api/calendar/item_get.php', {id}).then(res=>{
    if(!res.success){ alert(res.message||'讀取失敗'); return; }
    const ev = res.data;
    const html = `
      <div class="mb-2"><span class="badge text-bg-secondary">${ev.type}</span>
        ${ev.category_name?`<span class="badge" style="background:${ev.category_color||'#6f42c1'}">${ev.category_name}</span>`:''}
      </div>
      <div class="mb-2"><strong>${ev.title}</strong></div>
      <div class="mb-2">${ev.all_day?'整天':(ev.start_datetime+' ~ '+ev.end_datetime)}</div>
      <div class="mb-2">${ev.location?('地點：'+ev.location):''}</div>
      <div class="mb-2">${ev.note?('備註：'+ev.note):''}</div>
      <div class="mb-2">可見性：${ev.visibility}</div>
    `;
    $('#eventDetail').html(html);
    $('#editLink').attr('href','/admin/calendar/item_edit.php?id='+ev.id);
    $('#deleteBtn').off('click').on('click', ()=>deleteEvent(ev.id));
    modal.show();
  });
}
function deleteEvent(id){
  if(!confirm('確定刪除這個事件？')) return;
  $.post('/api/calendar/item_delete.php', {id}).then(res=>{
    try{res=JSON.parse(res);}catch(e){}
    if(res.success){
      cachedEvents = {};
      reload();
      modal.hide();
    }else{
      alert(res.message||'刪除失敗');
    }
  });
}

/* [JS-7] 載入 + 重載 + 快捷鍵 */
function reload(){
  buildMonthGrid(viewYear, viewMonth);
  fetchMonthEvents(viewYear, viewMonth).then(data=>renderEvents(viewYear, viewMonth, data));
}

/* [JS-8] 綁定 */
$(function(){
  loadCategories().then(reload);

  $('#btnPrev').on('click', ()=>{ viewMonth--; if(viewMonth<1){viewMonth=12; viewYear--;} cachedEvents={}; reload(); });
  $('#btnNext').on('click', ()=>{ viewMonth++; if(viewMonth>12){viewMonth=1; viewYear++;} cachedEvents={}; reload(); });
  $('#btnToday').on('click', ()=>{ const t=new Date(); viewYear=t.getFullYear(); viewMonth=t.getMonth()+1; cachedEvents={}; reload(); });
  $('#q, #category_ids, .filter-type').on('change keyup', ()=>{ cachedEvents = {}; reload(); });

  // 鍵盤左右鍵切月
  $(document).on('keydown', (e)=>{
    if (e.key==='ArrowLeft'){ $('#btnPrev').click(); }
    else if (e.key==='ArrowRight'){ $('#btnNext').click(); }
  });
});
</script>
</body>
</html>
