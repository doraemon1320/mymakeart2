/* [JS-B.1] 由後端注入 TOOLKIT 生成兩份（出發/回廠） */
function makeCheckCard(it, idx, phase){
  const planQty = it.qty || '';
  const label = it.name || it.item || `項目${idx+1}`;
  return `
    <div class="border rounded p-2 mb-2">
      <div class="d-flex justify-content-between align-items-center">
        <div>
          <div><strong>${label}</strong> ${it.required?' <span class="badge bg-danger">必要</span>':''}</div>
          ${planQty? `<div class="small text-muted">建議數量：${planQty}</div>`:''}
        </div>
        <div class="d-flex align-items-center gap-2">
          <div class="form-check">
            <input class="form-check-input chk-ok" type="checkbox">
            <label class="form-check-label">齊全</label>
          </div>
          <input class="form-control form-control-sm chk-qty" style="width:100px" placeholder="實帶數">
        </div>
      </div>
      <input class="form-control form-control-sm mt-2 chk-note" placeholder="備註（選填）">
    </div>
  `;
}
function renderPhaseLists(){
  const listA = (TOOLKIT.suggested_tools||[]).map((it,i)=>makeCheckCard(it,i,'departure')).join('')
    +(TOOLKIT.checklist||[]).map((it,i)=>makeCheckCard(it,i,'departure')).join('');
  $('#toolDepartureList').html(listA||'<div class="text-muted">無樣板，請在工單編輯頁設定類別樣板。</div>');

  const listB = (TOOLKIT.suggested_tools||[]).map((it,i)=>makeCheckCard(it,i,'return')).join('')
    +(TOOLKIT.checklist||[]).map((it,i)=>makeCheckCard(it,i,'return')).join('');
  $('#toolReturnList').html(listB||'<div class="text-muted">無樣板。</div>');
}
renderPhaseLists();

/* [JS-B.2] 收集表單 */
function collectChecklist($root){
  const arr=[];
  $root.find('.border.rounded').each(function(){
    const label = $(this).find('strong').text().trim();
    arr.push({
      label,
      ok: $(this).find('.chk-ok').is(':checked'),
      qty_actual: $(this).find('.chk-qty').val().trim(),
      note: $(this).find('.chk-note').val().trim()
    });
  });
  return arr;
}

/* [JS-B.3] 送出出發/回廠 */
$('#btnSaveDeparture').on('click', ()=>{
  const data = collectChecklist($('#formDeparture'));
  $.post('api_tool_check_save.php', {
    order_id: ORDER_ID,
    phase: 'departure',
    checklist_json: JSON.stringify(data),
    notes: $('#formDeparture [name="notes"]').val()
  }, res=>{
    alert(res.msg||'已儲存');
    if(res.ok) location.reload();
  }, 'json');
});
$('#btnSaveReturn').on('click', ()=>{
  const data = collectChecklist($('#formReturn'));
  $.post('api_tool_check_save.php', {
    order_id: ORDER_ID,
    phase: 'return',
    checklist_json: JSON.stringify(data),
    notes: $('#formReturn [name="notes"]').val()
  }, res=>{
    alert(res.msg||'已儲存');
    if(res.ok) location.reload();
  }, 'json');
});
