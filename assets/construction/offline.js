/* [JS-C.1] 基本設定 */
const KEY_QUEUE = 'mymakeart:construction:queue';
const $net = $('#netStatus');
const $tbl = $('#tblOffline tbody');

/* [JS-C.2] 網路狀態顯示 */
function renderNet(){
  if(navigator.onLine){
    $net.removeClass('alert-warning').addClass('alert-success').text('● 線上（送出將直接上傳）');
  }else{
    $net.removeClass('alert-success').addClass('alert-warning').text('● 離線（已啟用本機暫存）');
  }
}
window.addEventListener('online', ()=>{ renderNet(); tryFlush(); });
window.addEventListener('offline', ()=>{ renderNet(); });
renderNet();

/* [JS-C.3] Queue 操作 */
function getQueue(){
  try{ return JSON.parse(localStorage.getItem(KEY_QUEUE)||'[]'); }catch(e){ return []; }
}
function setQueue(arr){ localStorage.setItem(KEY_QUEUE, JSON.stringify(arr)); }
function pushQueue(item){
  const arr = getQueue();
  arr.unshift(item);
  setQueue(arr);
  renderQueue();
}
function removeQueue(idx){
  const arr = getQueue();
  arr.splice(idx,1);
  setQueue(arr);
  renderQueue();
}
function renderQueue(){
  const arr = getQueue();
  $tbl.empty();
  if(!arr.length){ $tbl.append('<tr><td colspan="3" class="text-muted text-center">無離線暫存</td></tr>'); return; }
  arr.forEach((x,i)=>{
    $tbl.append(`
      <tr>
        <td>${new Date(x.saved_at).toLocaleString()}</td>
        <td class="small">${x.data.work_content ? x.data.work_content.slice(0,30)+'...' : '-'}</td>
        <td>
          <button class="btn btn-sm btn-primary btnFlush" data-i="${i}">送出</button>
          <button class="btn btn-sm btn-outline-danger btnDel" data-i="${i}">刪除</button>
        </td>
      </tr>
    `);
  });
}
renderQueue();

/* [JS-C.4] 定位 */
$('#btnGeo').on('click', ()=>{
  if(!navigator.geolocation){ return alert('本裝置不支援定位'); }
  navigator.geolocation.getCurrentPosition(pos=>{
    $('[name="gps_lat"]').val(pos.coords.latitude.toFixed(6));
    $('[name="gps_lng"]').val(pos.coords.longitude.toFixed(6));
  }, err=>{ alert('定位失敗'); });
});

/* [JS-C.5] 收集表單資料 */
function collectForm(){
  const f = $('#dailyForm').serializeArray().reduce((m, x)=> (m[x.name]=x.value, m), {});
  return {
    order_id: Number(ORDER_ID),
    date: f.date,
    start_time: f.start_time || '',
    end_time: f.end_time || '',
    weather: f.weather || '',
    contact_on_site: f.contact_on_site || '',
    contact_phone: f.contact_phone || '',
    work_content: f.work_content || '',
    gps_lat: f.gps_lat || '',
    gps_lng: f.gps_lng || '',
    notes: f.notes || '',
    workforce_json: null, materials_json: null, equipment_json: null
  };
}

/* [JS-C.6] 暫存 */
$('#btnSaveOffline').on('click', ()=>{
  const data = collectForm();
  if(!data.work_content){ return alert('請填寫施工內容'); }
  pushQueue({ type:'daily_log', api:'api_daily_log_save.php', data, saved_at: Date.now() });
  alert('已暫存於本機。');
});

/* [JS-C.7] 立即送出（線上） */
$('#btnSubmit').on('click', ()=>{
  const data = collectForm();
  if(!data.work_content){ return alert('請填寫施工內容'); }
  if(!navigator.onLine){
    // 改成暫存
    pushQueue({ type:'daily_log', api:'api_daily_log_save.php', data, saved_at: Date.now() });
    alert('目前離線，已暫存，恢復連線後會自動嘗試送出。');
    return;
  }
  $.post('api_daily_log_save.php', data, res=>{
    if(res.ok){
      alert('已上傳');
      location.href = 'order_view.php?id='+ORDER_ID;
    }else{
      alert(res.msg||'上傳失敗');
    }
  }, 'json');
});

/* [JS-C.8] 佇列手動操作 */
$(document).on('click','.btnDel', function(){
  removeQueue(Number($(this).data('i')));
});
$(document).on('click','.btnFlush', function(){
  const i = Number($(this).data('i'));
  flushOne(i);
});

/* [JS-C.9] 自動回送 */
function flushOne(i){
  const arr = getQueue();
  const it = arr[i]; if(!it) return;
  if(!navigator.onLine){ return alert('仍為離線狀態'); }
  $.post(it.api, it.data, res=>{
    if(res.ok){
      removeQueue(i);
    }else{
      alert(res.msg||'送出失敗');
    }
  }, 'json').fail(()=> alert('請求失敗'));
}
function tryFlush(){
  const arr = getQueue();
  if(!arr.length || !navigator.onLine) return;
  // 逐筆發送（簡易版）
  let i=arr.length-1;
  const loop = ()=>{
    if(i<0) { renderQueue(); return; }
    $.post(arr[i].api, arr[i].data, res=>{
      if(res.ok){ arr.splice(i,1); setQueue(arr); }
      i--; loop();
    },'json').fail(()=>{ i--; loop(); });
  };
  loop();
}
