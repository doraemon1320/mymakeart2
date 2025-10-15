<?php
/***************************************
 * [PHP-1] 基本設定與小工具
 ***************************************/
mb_internal_encoding('UTF-8');
header_remove('X-Powered-By');

function h($s){ return htmlspecialchars((string)$s, ENT_QUOTES, 'UTF-8'); }

function parse_json_payload() {
    // 來源一：檔案上傳
    if (!empty($_FILES['json_file']['tmp_name']) && is_uploaded_file($_FILES['json_file']['tmp_name'])) {
        $txt = file_get_contents($_FILES['json_file']['tmp_name']);
        return $txt;
    }
    // 來源二：貼上文字
    if (!empty($_POST['json_text'])) {
        return (string)$_POST['json_text'];
    }
    return '';
}

function decode_report_json($txt, &$err) {
    $err = '';
    if ($txt === '') { $err = '沒有收到 JSON 內容。'; return null; }
    $data = json_decode($txt, true);
    if (!is_array($data)) { $err = 'JSON 解析失敗。'; return null; }
    // 粗略驗證
    if (!isset($data['files']) || !is_array($data['files'])) {
        $err = 'JSON 結構不正確：缺少 files 陣列。'; return null;
    }
    return $data;
}

function calc_summary($data) {
    // 回傳：summaryRows, failedRows(若掃描器有填), totals
    $rows = [];
    $tot = ['files'=>0,'placed'=>0,'missing'=>0,'liveText'=>0];
    $failed = isset($data['failed']) && is_array($data['failed']) ? $data['failed'] : [];

    foreach ($data['files'] as $f) {
        $docPath = isset($f['docPath']) ? $f['docPath'] : '';
        $docName = isset($f['docName']) ? $f['docName'] : '';
        $abCount = isset($f['artboards']) ? (int)$f['artboards'] : 0;

        $placedArr = isset($f['placed']) && is_array($f['placed']) ? $f['placed'] : [];
        $textsArr  = isset($f['texts']) && is_array($f['texts']) ? $f['texts'] : [];

        $placedTotal = count($placedArr);
        $linkMissing = 0;
        foreach ($placedArr as $p) {
            $status = isset($p['status']) ? $p['status'] : '';
            if ($status === '連結遺失') $linkMissing++;
        }

        // liveTextVisible：只計算「未隱藏」的文字框
        $liveTextVisible = 0;
        foreach ($textsArr as $t) {
            $hidden = isset($t['hidden']) ? (bool)$t['hidden'] : false;
            if (!$hidden) $liveTextVisible++;
        }

        $rows[] = [
            'docPath'=>$docPath, 'docName'=>$docName, 'abCount'=>$abCount,
            'placedTotal'=>$placedTotal, 'linkMissing'=>$linkMissing, 'liveTextVisible'=>$liveTextVisible
        ];

        $tot['files']++;
        $tot['placed']  += $placedTotal;
        $tot['missing'] += $linkMissing;
        $tot['liveText']+= $liveTextVisible;
    }
    return [$rows, $failed, $tot];
}

/***************************************
 * [PHP-2] 取得輸入資料並嘗試解析
 ***************************************/
$raw = '';
$data = null;
$err  = '';
if ($_SERVER['REQUEST_METHOD'] === 'POST') {
    $raw = parse_json_payload();
    $data = decode_report_json($raw, $err);
}

/***************************************
 * [PHP-3] 輸出 HTML
 ***************************************/
?><!doctype html>
<html lang="zh-Hant">
<head>
  <!-- [HTML-1] Meta 與 Bootstrap -->
  <meta charset="utf-8">
  <meta name="viewport" content="width=device-width, initial-scale=1">
  <title>Illustrator 檢查報告（網站版）</title>
  <link href="https://cdn.jsdelivr.net/npm/bootstrap@5.3.3/dist/css/bootstrap.min.css" rel="stylesheet">
  <style>
    /* [HTML-2] 自訂樣式 */
    .red { color: #d00; font-weight: 700; }
    .muted { color:#888; }
    .mono { font-family: Consolas, Menlo, Monaco, monospace; }
    .nowrap { white-space: nowrap; }
    .sticky-head { position: sticky; top: 0; background: #fff; z-index: 1; }
    .table-fixed { table-layout: fixed; }
    .small { font-size: 0.925rem; }
  </style>
</head>
<body>
<div class="container my-4">

  <!-- [HTML-3] 標題 -->
  <div class="row mb-3">
    <div class="col">
      <h1 class="h4 mb-1">Illustrator 未嵌入圖片 / 未外框文字 — 網站報告</h1>
      <div class="text-muted small">步驟：先用下方「掃描器.jsx」在本機產生 JSON → 上傳或貼上 JSON → 本頁生成報告。</div>
    </div>
  </div>

  <!-- [HTML-4] 上傳表單 -->
  <div class="row g-3">
    <div class="col-12 col-lg-6">
      <div class="card shadow-sm">
        <div class="card-header table-primary">1) 上傳 JSON 檔</div>
        <div class="card-body">
          <form method="post" enctype="multipart/form-data" id="uploadForm">
            <div class="mb-2">
              <input type="file" name="json_file" accept=".json,application/json" class="form-control">
            </div>
            <div class="mb-2">
              <button class="btn btn-primary w-100" type="submit">上傳並產生報告</button>
            </div>
          </form>
        </div>
      </div>
    </div>
    <div class="col-12 col-lg-6">
      <div class="card shadow-sm">
        <div class="card-header table-primary">2) 或貼上 JSON</div>
        <div class="card-body">
          <form method="post" id="pasteForm">
            <textarea name="json_text" rows="6" class="form-control mono" placeholder='貼上掃描器輸出的 JSON 內容'></textarea>
            <div class="mt-2 d-grid">
              <button class="btn btn-primary" type="submit">貼上並產生報告</button>
            </div>
          </form>
        </div>
      </div>
    </div>
  </div>

<?php if ($_SERVER['REQUEST_METHOD'] === 'POST'): ?>
  <!-- [HTML-5] 錯誤顯示 -->
  <?php if ($err): ?>
    <div class="alert alert-danger my-4">解析失敗：<?=h($err)?></div>
  <?php else: list($rows,$failed,$tot) = calc_summary($data); ?>

  <!-- [HTML-6] 快速工具列 -->
  <div class="row align-items-center my-3">
    <div class="col-auto">
      <span class="badge bg-secondary">檔案數：<?=$tot['files']?></span>
      <span class="badge bg-secondary">未嵌入總數：<?=$tot['placed']?></span>
      <span class="badge bg-secondary">連結遺失總數：<?=$tot['missing']?></span>
      <span class="badge bg-secondary">未外框文字（可見）總數：<?=$tot['liveText']?></span>
    </div>
    <div class="col-auto">
      <div class="form-check form-check-inline">
        <input class="form-check-input" type="checkbox" id="onlyProblems">
        <label class="form-check-label" for="onlyProblems">只顯示有問題的檔案</label>
      </div>
    </div>
    <div class="col-12 col-lg-4 ms-auto">
      <input id="kw" class="form-control" placeholder="關鍵字過濾（檔名/路徑）">
    </div>
  </div>

  <!-- [HTML-7] 匯總表格 -->
  <div class="table-responsive">
    <table class="table table-bordered table-fixed align-middle" id="summaryTable">
      <thead class="sticky-head">
        <tr class="table-primary">
          <th style="width:60px">#</th>
          <th style="width:30%">檔案路徑</th>
          <th style="width:20%">文件名稱</th>
          <th style="width:90px">畫板數</th>
          <th style="width:160px">未嵌入項目數（PlacedItem）</th>
          <th style="width:120px">連結遺失數</th>
          <th style="width:180px">未外框文字數（可見）</th>
        </tr>
      </thead>
      <tbody>
        <?php foreach ($rows as $i=>$r): 
          $hasIssue = ($r['placedTotal']>0 || $r['liveTextVisible']>0);
        ?>
        <tr data-idx="<?=$i?>" data-has-issue="<?=$hasIssue?1:0?>">
          <td class="nowrap">
            <?=($i+1)?> ｜ 
            <?php if ($hasIssue): ?>
              <a class="link-primary" href="#d<?=$i?>">詳情</a>
            <?php else: ?>
              <span class="muted">—</span>
            <?php endif; ?>
          </td>
          <td class="mono"><?=h($r['docPath'])?></td>
          <td><?=h($r['docName'])?></td>
          <td><?=h($r['abCount'])?></td>
          <td class="<?=$r['placedTotal']>0?'red':''?>"><?=h($r['placedTotal'])?></td>
          <td class="<?=$r['linkMissing']>0?'red':''?>"><?=h($r['linkMissing'])?></td>
          <td class="<?=$r['liveTextVisible']>0?'red':''?>"><?=h($r['liveTextVisible'])?></td>
        </tr>
        <?php endforeach; ?>
      </tbody>
    </table>
  </div>

  <!-- [HTML-8] 各別詳情（僅列出有問題的檔案） -->
  <div class="row mt-4">
    <div class="col">
      <h2 class="h5 mb-3">各別詳情（僅列出有未嵌入或未外框文字）</h2>

      <?php foreach ($rows as $i=>$r): 
        $hasIssue = ($r['placedTotal']>0 || $r['liveTextVisible']>0);
        if (!$hasIssue) continue;
        $file = $data['files'][$i];
        $placed = isset($file['placed']) && is_array($file['placed']) ? $file['placed'] : [];
        $texts  = isset($file['texts']) && is_array($file['texts']) ? $file['texts'] : [];
      ?>
      <div class="card mb-4" id="d<?=$i?>">
        <div class="card-header table-primary">
          <div class="d-flex flex-wrap justify-content-between">
            <div><strong>#<?=($i+1)?></strong>　<?=h($r['docName'])?></div>
            <div class="mono small"><?=h($r['docPath'])?></div>
          </div>
        </div>
        <div class="card-body">

          <?php if (count($placed)): ?>
          <h3 class="h6">未嵌入圖片（PlacedItem）</h3>
          <div class="table-responsive">
            <table class="table table-bordered align-middle">
              <thead>
                <tr class="table-light">
                  <th style="width:90px">序號</th>
                  <th style="width:20%">圖層</th>
                  <th style="width:20%">推測畫板</th>
                  <th style="width:120px">連結狀態</th>
                  <th>連結檔案路徑</th>
                </tr>
              </thead>
              <tbody>
                <?php foreach ($placed as $idx=>$p): ?>
                <tr>
                  <td><?=($idx+1)?></td>
                  <td><?=h($p['layer'])?></td>
                  <td><?=h($p['artboard'])?></td>
                  <td class="<?=($p['status']==='連結遺失'?'red':'')?>"><?=h($p['status'])?></td>
                  <td class="mono"><?=h($p['path'])?></td>
                </tr>
                <?php endforeach; ?>
              </tbody>
            </table>
          </div>
          <?php endif; ?>

          <?php if (count($texts)): ?>
          <h3 class="h6 mt-3">未外框文字（TextFrame）</h3>
          <div class="table-responsive">
            <table class="table table-bordered align-middle">
              <thead>
                <tr class="table-light">
                  <th style="width:90px">序號</th>
                  <th style="width:20%">圖層</th>
                  <th style="width:20%">推測畫板</th>
                  <th style="width:80px">鎖定</th>
                  <th style="width:80px">隱藏</th>
                  <th>文字內容（前 50 字）</th>
                </tr>
              </thead>
              <tbody>
                <?php foreach ($texts as $tidx=>$t): ?>
                <tr>
                  <td><?=($tidx+1)?></td>
                  <td><?=h($t['layer'])?></td>
                  <td><?=h($t['artboard'])?></td>
                  <td><?=(!empty($t['locked'])?'是':'否')?></td>
                  <td class="<?=(!empty($t['hidden'])?'muted':'')?>"><?=(!empty($t['hidden'])?'是':'否')?></td>
                  <td><?=h($t['preview'])?></td>
                </tr>
                <?php endforeach; ?>
              </tbody>
            </table>
          </div>
          <?php endif; ?>

          <?php if (!count($placed) && !count($texts)): ?>
            <div class="text-muted">（此檔案沒有未嵌入或未外框文字）</div>
          <?php endif; ?>

        </div>
      </div>
      <?php endforeach; ?>

      <?php if (empty(array_filter($rows, function($r){ return $r['placedTotal']>0 || $r['liveTextVisible']>0; }))): ?>
        <div class="alert alert-success">所有檔案均無未嵌入或未外框文字問題。</div>
      <?php endif; ?>

      <?php if (!empty($failed)): ?>
        <div class="card mt-4">
          <div class="card-header table-danger">開檔失敗清單</div>
          <div class="card-body">
            <div class="table-responsive">
              <table class="table table-bordered">
                <thead><tr class="table-light"><th style="width:60px">#</th><th>檔案路徑</th><th>錯誤訊息</th></tr></thead>
                <tbody>
                  <?php foreach ($failed as $k=>$fr): ?>
                  <tr><td><?=($k+1)?></td><td class="mono"><?=h($fr[0])?></td><td><?=h($fr[1])?></td></tr>
                  <?php endforeach;?>
                </tbody>
              </table>
            </div>
          </div>
        </div>
      <?php endif; ?>

    </div>
  </div>

  <?php endif; // end $err else ?>
<?php endif; // end POST ?>

  <!-- [HTML-9] 下載掃描器 -->
  <div class="row mt-5">
    <div class="col">
      <div class="card">
        <div class="card-header table-primary">3) 下載 Illustrator 掃描器（.jsx）→ 產出 JSON</div>
        <div class="card-body">
          <ol class="mb-3">
            <li>把下方完整程式碼存為 <code>ScanToJson.jsx</code></li>
            <li>Illustrator → <em>File &gt; Scripts &gt; Other Script…</em> 執行</li>
            <li>選擇要掃描的資料夾，執行後會產生 <strong>report.json</strong>（或你自選檔名）</li>
            <li>回到本頁，上傳該 JSON 產生網站報告</li>
          </ol>
          <pre class="bg-light p-3 small"><code>
// --- 下面是 ScanToJson.jsx（完整、不省略） ---
/************************************************************
 * ScanToJson.jsx
 * 說明：批次掃描 .ai/.eps/.pdf，輸出 JSON，供網站報告使用
 * 內容：PlacedItem（未嵌入圖片）＋ TextFrame（未外框文字）
 * 相容：不使用 ES5 API
 ************************************************************/
(function(){
    if (typeof app==='undefined'){ alert('請在 Illustrator 執行'); return; }

    // [J-1] 工具
    function decodePath(p){ try{return decodeURI(p);}catch(e){return p;} }
    function safeText(s){ return (s===undefined||s===null||s==='')?'(空)':String(s); }
    function toLower(s){ return (s||'').toLowerCase(); }
    function pad2(n){ return (n&lt;10?'0':'')+n; }
    function nowStamp(){ var d=new Date(); return d.getFullYear()+'-'+pad2(d.getMonth()+1)+'-'+pad2(d.getDate())+' '+pad2(d.getHours())+':'+pad2(d.getMinutes())+':'+pad2(d.getSeconds()); }
    function hasExt(file, allow){ var n=file.name; var i=n.lastIndexOf('.'); if(i&lt;0)return false; var e=n.substring(i+1).toLowerCase(); for(var k=0;k&lt;allow.length;k++){ if(e===allow[k]) return true; } return false; }
    function listFilesRecursive(folder, allow, includeSub){
        var out=[], items=folder.getFiles();
        for (var i=0;i&lt;items.length;i++){
            var it=items[i];
            if (it instanceof Folder){ if (includeSub){ var sub=listFilesRecursive(it,allow,includeSub); for (var j=0;j&lt;sub.length;j++) out.push(sub[j]); } }
            else if (it instanceof File){ if (hasExt(it,allow)) out.push(it); }
        }
        return out;
    }
    function rectFromArtboard(ab){ var r=ab.artboardRect; return {l:r[0],t:r[1],r:r[2],b:r[3]}; }
    function boundsFromItem(item){ var b=item.visibleBounds; return {l:b[0],t:b[1],r:b[2],b:b[3]}; }
    function centerOf(rc){ return {x:(rc.l+rc.r)/2,y:(rc.t+rc.b)/2}; }
    function pointInRect(pt,rc){ return (pt.x&gt;=rc.l &amp;&amp; pt.x&lt;=rc.r &amp;&amp; pt.y&lt;=rc.t &amp;&amp; pt.y&gt;=rc.b); }
    function guessArtboardIndex(doc,item){
        var ib=boundsFromItem(item), c=centerOf(ib), abs=doc.artboards;
        for (var i=0;i&lt;abs.length;i++){ var rc=rectFromArtboard(abs[i]); if (pointInRect(c,rc)) return i; }
        return -1;
    }

    // [J-2] 參數
    var w=new Window('dialog','掃描產生 JSON');
    w.orientation='column'; w.alignChildren=['fill','top'];
    var g1=w.add('group'); g1.add('statictext',undefined,'掃描資料夾：'); var tFolder=g1.add('edittext',undefined,''); tFolder.characters=50; var bBrowse=g1.add('button',undefined,'選擇…');
    var g2=w.add('group'); g2.orientation='row'; var cSub=g2.add('checkbox',undefined,'含子資料夾'); cSub.value=true; g2.add('statictext',undefined,'副檔名：'); var tExt=g2.add('edittext',undefined,'ai, eps, pdf'); tExt.characters=16;
    var g3=w.add('group'); g3.orientation='row'; g3.add('statictext',undefined,'輸出 JSON 檔名：'); var tOut=g3.add('edittext',undefined,'report.json'); tOut.characters=22;
    var g4=w.add('group'); var bOK=g4.add('button',undefined,'開始'), bCancel=g4.add('button',undefined,'取消',{name:'cancel'});
    bBrowse.onClick=function(){ var f=Folder.selectDialog('選取資料夾'); if(f) tFolder.text=f.fsName; };
    if (w.show()!=1) return;
    if (!tFolder.text){ alert('未選擇資料夾'); return; }
    var root=new Folder(tFolder.text); if (!root.exists){ alert('資料夾不存在'); return; }

    // 副檔名解析
    var s=(tExt.text||''), tmp=[], i, ch;
    for(i=0;i&lt;s.length;i++){ ch=s.charAt(i); if(ch===' '||ch==='　'||ch==='\\t'||ch==='\\r'||ch==='\\n')continue; tmp.push(ch); }
    var joined=tmp.join(''); var parts=[], seg='';
    for(i=0;i&lt;joined.length;i++){ ch=joined.charAt(i); if(ch===','){ if(seg!==''){ parts.push(seg); seg=''; } } else { seg+=ch; } }
    if (seg!=='') parts.push(seg);
    var exts=[], k; for(k=0;k&lt;parts.length;k++){ var e=toLower(parts[k]); if(e!=='') exts.push(e); }
    if (!exts.length) exts=['ai','eps','pdf'];

    // [J-3] 掃描
    var files=listFilesRecursive(root, exts, !!cSub.value);
    if (!files.length){ alert('找不到檔案'); return; }

    var pw=new Window('palette','掃描中…'); pw.orientation='column'; pw.alignChildren=['fill','top'];
    var tip=pw.add('statictext',undefined,'初始化…'); var bar=pw.add('progressbar',undefined,0,files.length); bar.preferredSize=[520,20];
    pw.show(); try{ app.activate(); }catch(e){}

    var oldUI=app.userInteractionLevel; app.userInteractionLevel=UserInteractionLevel.DONTDISPLAYALERTS;
    var report={ root: root.fsName, generated_at: nowStamp(), files: [], failed: [] };

    for (var fi=0;fi&lt;files.length;fi++){
        var f=files[fi]; tip.text='掃描 '+(fi+1)+'/'+files.length+'：'+f.fsName; bar.value=fi+1; pw.update(); $.sleep(10);
        var doc=null, opened=false;
        try{
            doc=app.open(f); opened=true;
            var placedArr=[], textsArr=[];
            // PlacedItem
            var placed=doc.placedItems;
            for (var j=0;j&lt;placed.length;j++){
                var p=placed[j], pPath='(無檔案屬性)', status='未知';
                try{ if (p.file){ pPath=decodePath(p.file.fsName); status=(File(p.file).exists ? '連結正常' : '連結遺失'); } }catch(e){}
                var abIdx= (function(it){ var ib=it.visibleBounds; var c={x:(ib[0]+ib[2])/2,y:(ib[1]+ib[3])/2}; for(var a=0;a&lt;doc.artboards.length;a++){ var r=doc.artboards[a].artboardRect; if (c.x&gt;=r[0] &amp;&amp; c.x&lt;=r[2] &amp;&amp; c.y&lt;=r[1] &amp;&amp; c.y&gt;=r[3]) return a; } return -1; })(p);
                var abName=(abIdx&gt;=0)?('#'+(abIdx+1)+' '+doc.artboards[abIdx].name):'(未對應)';
                placedArr.push({ layer: safeText(p.layer &amp;&amp; p.layer.name), artboard: abName, status: status, path: pPath });
            }
            // TextFrame
            var tfs=doc.textFrames;
            for (var t=0;t&lt;tfs.length;t++){
                var tf=tfs[t], isHidden=false, isLocked=false, layerName=safeText(tf.layer &amp;&amp; tf.layer.name);
                try{ isHidden=!!tf.hidden; }catch(e){}
                try{ isLocked=!!tf.locked; }catch(e){}
                var abIdx2=(function(it){ var ib=it.visibleBounds; var c={x:(ib[0]+ib[2])/2,y:(ib[1]+ib[3])/2}; for(var a=0;a&lt;doc.artboards.length;a++){ var r=doc.artboards[a].artboardRect; if (c.x&gt;=r[0] &amp;&amp; c.x&lt;=r[2] &amp;&amp; c.y&lt;=r[1] &amp;&amp; c.y&gt;=r[3]) return a; } return -1; })(tf);
                var abName2=(abIdx2&gt;=0)?('#'+(abIdx2+1)+' '+doc.artboards[abIdx2].name):'(未對應)';
                var preview=''; try{ preview=tf.contents||''; }catch(e){ preview=''; } if (preview.length&gt;50) preview=preview.substring(0,50)+'…';
                textsArr.push({ layer: layerName, artboard: abName2, locked: isLocked, hidden: isHidden, preview: preview });
            }
            report.files.push({
                docPath: (doc.fullName &amp;&amp; doc.fullName.fsName) ? decodePath(doc.fullName.fsName) : decodePath(f.fsName),
                docName: doc.name,
                artboards: doc.artboards.length,
                placed: placedArr,
                texts: textsArr
            });
        } catch(ex){
            report.failed.push([ decodePath(f.fsName), String(ex) ]);
        } finally {
            try{ if (opened &amp;&amp; doc) doc.close(SaveOptions.DONOTSAVECHANGES); }catch(e){}
        }
    }
    app.userInteractionLevel=oldUI; try{ pw.close(); }catch(e){}

    // 儲存 JSON
    var outFile = File.saveDialog('儲存 JSON 檔名', '*.json');
    if (!outFile) { alert('已取消輸出。'); return; }
    outFile.encoding='UTF-8'; outFile.open('w'); outFile.write(JSON.stringify(report)); outFile.close();
    alert('完成，已輸出：\\n'+outFile.fsName);
})();
          </code></pre>
        </div>
      </div>
    </div>
  </div>

</div>

<!-- [JS-1] jQuery 與互動 -->
<script src="https://code.jquery.com/jquery-3.7.1.min.js"></script>
<script>
/* [JS-1] 匯總表互動：只顯示有問題、關鍵字過濾 */
$(function(){
  function applyFilter(){
    var onlyProblems = $('#onlyProblems').prop('checked');
    var kw = ($('#kw').val() || '').toLowerCase();
    $('#summaryTable tbody tr').each(function(){
      var $tr = $(this);
      var show = true;
      if (onlyProblems && $tr.data('has-issue') != 1) show = false;
      if (kw) {
        var text = ($tr.find('td').eq(1).text() + ' ' + $tr.find('td').eq(2).text()).toLowerCase();
        if (text.indexOf(kw) === -1) show = false;
      }
      $tr.toggle(show);
    });
  }
  $('#onlyProblems').on('change', applyFilter);
  $('#kw').on('input', applyFilter);
});
</script>
</body>
</html>
