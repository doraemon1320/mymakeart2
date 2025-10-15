/* [JS-D.1] 驗收清單收集 */
function collectChecklist(){
  const arr=[];
  document.querySelectorAll('.acc-item').forEach(el=>{
    if(el.checked) arr.push(el.value);
  });
  return arr;
}

/* [JS-D.2] 簽名板 */
const canvas = document.getElementById('signCanvas');
const ctx = canvas.getContext('2d');
let drawing=false, paths=[], current=[];
function resizeCanvas(){
  const dpr = window.devicePixelRatio || 1;
  const cssW = canvas.clientWidth, cssH = canvas.clientHeight;
  canvas.width = Math.floor(cssW * dpr);
  canvas.height = Math.floor(cssH * dpr);
  ctx.scale(dpr, dpr);
  redraw();
}
function redraw(){
  ctx.clearRect(0,0,canvas.width,canvas.height);
  ctx.lineWidth = 2; ctx.lineJoin='round'; ctx.lineCap='round';
  ctx.strokeStyle = '#111';
  paths.forEach(p=>{
    ctx.beginPath();
    p.forEach((pt,idx)=>{
      if(idx===0) ctx.moveTo(pt.x, pt.y);
      else ctx.lineTo(pt.x, pt.y);
    });
    ctx.stroke();
  });
}
function pointerPos(e){
  const rect = canvas.getBoundingClientRect();
  const x = (e.touches? e.touches[0].clientX : e.clientX) - rect.left;
  const y = (e.touches? e.touches[0].clientY : e.clientY) - rect.top;
  return {x,y};
}
function startDraw(e){ drawing=true; current=[]; current.push(pointerPos(e)); }
function moveDraw(e){ if(!drawing) return; current.push(pointerPos(e)); redraw(); }
function endDraw(){ if(!drawing) return; drawing=false; if(current.length){ paths.push(current); current=[]; } redraw(); }

canvas.addEventListener('mousedown', startDraw);
canvas.addEventListener('mousemove', moveDraw);
canvas.addEventListener('mouseup', endDraw);
canvas.addEventListener('mouseleave', endDraw);
canvas.addEventListener('touchstart', (e)=>{ e.preventDefault(); startDraw(e); }, {passive:false});
canvas.addEventListener('touchmove',  (e)=>{ e.preventDefault(); moveDraw(e); }, {passive:false});
canvas.addEventListener('touchend',   (e)=>{ e.preventDefault(); endDraw(e); }, {passive:false});
window.addEventListener('resize', resizeCanvas);
resizeCanvas();

$('#btnClear').on('click', ()=>{ paths=[]; redraw(); });
$('#btnUndo').on('click', ()=>{ paths.pop(); redraw(); });

/* [JS-D.3] 儲存簽名（上傳PNG） */
$('#btnSaveSign').on('click', ()=>{
  if(!paths.length) return alert('尚未簽名');
  const dataUrl = canvas.toDataURL('image/png');
  $.post('api_signature_upload.php', {order_id: ORDER_ID, data: dataUrl}, res=>{
    alert(res.msg||'已儲存');
    if(res.ok) location.reload();
  }, 'json');
});

/* [JS-D.4] 送出驗收（清單＋備註） */
$('#btnFinishAcc').on('click', ()=>{
  const checklist = collectChecklist();
  const notes = $('[name="acceptance_notes"]').val();
  $.post('api_acceptance_save.php', {
    order_id: ORDER_ID,
    checklist_json: JSON.stringify(checklist),
    acceptance_notes: notes
  }, res=>{
    alert(res.msg||'已儲存');
    if(res.ok) window.location.href = 'order_view.php?id='+ORDER_ID;
  }, 'json');
});
