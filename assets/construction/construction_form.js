/* [JS-A.1] 元素參考 */
const $tblSugBody = $('#tblSuggested tbody');
const $tblChkBody = $('#tblChecklist tbody');
const $snapshot = $('#toolkit_snapshot_json');

/* [JS-A.2] 初始化表格 */
function renderTables(data){
  $tblSugBody.empty();
  (data.suggested_tools||[]).forEach((t, i)=>{
    $tblSugBody.append(`
      <tr>
        <td><input class="form-control sug-name" value="${t.name||''}"></td>
        <td><input class="form-control sug-qty" style="max-width:100px" value="${t.qty||''}"></td>
        <td class="text-center">
          <input type="checkbox" class="form-check-input sug-req" ${t.required?'checked':''}>
        </td>
        <td class="text-center"><button type="button" class="btn btn-sm btn-outline-danger btnDelRow">刪</button></td>
      </tr>
    `);
  });

  $tblChkBody.empty();
  (data.checklist||[]).forEach((c,i)=>{
    $tblChkBody.append(`
      <tr>
        <td><input class="form-control chk-item" value="${c.item||''}"></td>
        <td class="text-center">
          <input type="checkbox" class="form-check-input chk-req" ${c.required?'checked':''}>
        </td>
        <td class="text-center"><button type="button" class="btn btn-sm btn-outline-danger btnDelRow">刪</button></td>
      </tr>
    `);
  });
}
renderTables(initToolkit);

/* [JS-A.3] 新增列 */
$('#btnAddTool').on('click', ()=>{
  $tblSugBody.append(`
    <tr>
      <td><input class="form-control sug-name"></td>
      <td><input class="form-control sug-qty" style="max-width:100px"></td>
      <td class="text-center"><input type="checkbox" class="form-check-input sug-req"></td>
      <td class="text-center"><button type="button" class="btn btn-sm btn-outline-danger btnDelRow">刪</button></td>
    </tr>
  `);
});
$('#btnAddCheck').on('click', ()=>{
  $tblChkBody.append(`
    <tr>
      <td><input class="form-control chk-item"></td>
      <td class="text-center"><input type="checkbox" class="form-check-input chk-req"></td>
      <td class="text-center"><button type="button" class="btn btn-sm btn-outline-danger btnDelRow">刪</button></td>
    </tr>
  `);
});
$(document).on('click','.btnDelRow', function(){ $(this).closest('tr').remove(); });

/* [JS-A.4] 類別變更→載入樣板 */
$('#category_id').on('change', function(){
  const id = $(this).val();
  if(!id){ renderTables({suggested_tools:[], checklist:[]}); return; }
  $.getJSON('api_category_snapshot.php', {id}, (res)=>{
    if(res.ok){ renderTables(res.data); } else { alert(res.msg||'讀取失敗'); }
  });
});

/* [JS-A.5] 表單提交前→組快照 */
$('#orderForm').on('submit', function(){
  const sug=[], chk=[];
  $tblSugBody.find('tr').each(function(){
    const name=$(this).find('.sug-name').val().trim();
    if(!name) return;
    sug.push({
      name,
      qty: $(this).find('.sug-qty').val().trim(),
      required: $(this).find('.sug-req').is(':checked')
    });
  });
  $tblChkBody.find('tr').each(function(){
    const item=$(this).find('.chk-item').val().trim();
    if(!item) return;
    chk.push({ item, required: $(this).find('.chk-req').is(':checked') });
  });
  $snapshot.val(JSON.stringify({suggested_tools:sug, checklist:chk}));
});
