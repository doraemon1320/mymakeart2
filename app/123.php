<?php
/***************************************
 * [PHP-1] 基本設定
 ***************************************/
mb_internal_encoding('UTF-8');
header_remove('X-Powered-By');
date_default_timezone_set('Asia/Taipei');

/***************************************
 * [PHP-2] 來源步驟（依你的使用說明整理）
 * 來源出處：使用說明.docx（L1–L18）
 ***************************************/
$steps = [
  // [S1]
  [
    'title' => '使用 Adobe Illustrator 開啟程式',
    'desc'  => '請點擊程式，使用 ADOBE AI 開啟。',
    'note'  => '建議先關閉與此流程無關的檔案以縮短掃描時間。'
  ],
  // [S2]
  [
    'title' => '對話視窗：持續下一步',
    'desc'  => '看到對話視窗時，請依指示繼續。',
    'note'  => '若防火牆或權限提示，請允許執行以利檢查作業。'
  ],
  // [S3]
  [
    'title' => '選擇要檢查的資料夾',
    'desc'  => '選擇想要檢查「圖片遷入」與「文字是否有轉外框」的資料夾。',
    'note'  => '可包含子資料夾；建議先整理好資料結構。'
  ],
  // [S4]
  [
    'title' => '開始掃描',
    'desc'  => '點擊「開始掃描」。',
    'note'  => '依檔案數量不同，可能需要數十秒到數分鐘不等。'
  ],
  // [S5]
  [
    'title' => '掃描進行中',
    'desc'  => '掃描中，請稍候。',
    'note'  => '進度條若暫時停滯，多半是在處理大型檔案。'
  ],
  // [S6]
  [
    'title' => '查看結果',
    'desc'  => '點擊「確定」後，檢查日誌與彙總表會出現在你選擇的資料夾。',
    'note'  => '請開啟彙總報告先看整體，再深入各別檔案。'
  ],
];

/***************************************
 * [PHP-3] 生成表格資料（供 HTML 匯總區使用）
 ***************************************/
$summary = [];
foreach ($steps as $i => $s) {
  $summary[] = [
    'no'    => $i + 1,
    'title' => $s['title'],
    'desc'  => $s['desc'],
  ];
}
?>

<!doctype html>
<html lang="zh-Hant">
<head>
  <!-- [HTML-1] Meta 與 Bootstrap 5 -->
  <meta charset="utf-8">
  <meta name="viewport" content="width=device-width, initial-scale=1">
  <title>使用說明｜圖片嵌入與文字外框檢查</title>
  <link href="https://cdn.jsdelivr.net/npm/bootstrap@5.3.3/dist/css/bootstrap.min.css" rel="stylesheet">
  <style>
    /* [HTML-2] 自訂樣式 */
    .mono{ font-family: Consolas, Menlo, Monaco, monospace; }
    .step-card{ border-left: 6px solid #0d6efd; }
    .badge-step{ width: 2.3rem; }
  </style>
</head>
<body>
<div class="container my-4">
  <!-- [HTML-3] 首屏區塊 -->
  <div class="row mb-3">
    <div class="col">
      <h1 class="h4 mb-1">圖片嵌入與文字外框檢查｜使用說明</h1>
      <div class="text-muted">
        本頁依你的操作手冊整理為網頁版教學，流程與步驟文字同原文件。
      </div>
      <div class="small text-muted mt-1">
        來源：使用說明.docx（步驟 L1–L18）
      </div>
    </div>
  </div>

  <!-- [HTML-4] 工具列 -->
  <div class="row align-items-center g-2 mb-3">
    <div class="col-auto">
      <button id="btnExpand" class="btn btn-primary btn-sm">展開全部</button>
      <button id="btnCollapse" class="btn btn-outline-secondary btn-sm">收合全部</button>
    </div>
    <div class="col-auto">
      <button id="btnCopy" class="btn btn-outline-primary btn-sm">複製步驟文字</button>
    </div>
  </div>

  <!-- [HTML-5] 匯總表（table-bordered + table-primary） -->
  <div class="table-responsive mb-4">
    <table class="table table-bordered align-middle">
      <thead class="table-primary">
        <tr>
          <th style="width:80px">步驟</th>
          <th style="width:25%">標題</th>
          <th>說明</th>
          <th style="width:110px">快速導覽</th>
        </tr>
      </thead>
      <tbody>
      <?php foreach ($summary as $row): ?>
        <tr>
          <td><span class="badge bg-primary badge-step d-inline-flex justify-content-center"><?php echo $row['no']; ?></span></td>
          <td><?php echo htmlspecialchars($row['title'], ENT_QUOTES, 'UTF-8'); ?></td>
          <td><?php echo htmlspecialchars($row['desc'], ENT_QUOTES, 'UTF-8'); ?></td>
          <td><a class="btn btn-sm btn-outline-primary" href="#step-<?php echo $row['no']; ?>">前往</a></td>
        </tr>
      <?php endforeach; ?>
      </tbody>
    </table>
  </div>

  <!-- [HTML-6] 逐步說明（卡片樣式） -->
  <div class="row gy-3">
    <?php foreach ($steps as $i => $s): $n = $i + 1; ?>
      <div class="col-12" id="step-<?php echo $n; ?>">
        <div class="card step-card shadow-sm">
          <div class="card-body">
            <div class="d-flex align-items-start">
              <div class="me-3">
                <span class="badge bg-primary badge-step d-inline-flex justify-content-center"><?php echo $n; ?></span>
              </div>
              <div class="flex-grow-1">
                <h2 class="h5 mb-2"><?php echo htmlspecialchars($s['title'], ENT_QUOTES, 'UTF-8'); ?></h2>
                <p class="mb-2"><?php echo htmlspecialchars($s['desc'], ENT_QUOTES, 'UTF-8'); ?></p>
                <div class="collapse" id="step-body-<?php echo $n; ?>">
                  <div class="alert alert-secondary py-2">
                    <div class="small text-muted">小提醒</div>
                    <div><?php echo htmlspecialchars($s['note'], ENT_QUOTES, 'UTF-8'); ?></div>
                  </div>
                </div>
                <button class="btn btn-sm btn-outline-secondary toggle-step" data-target="#step-body-<?php echo $n; ?>">更多說明</button>
              </div>
            </div>
          </div>
        </div>
      </div>
    <?php endforeach; ?>
  </div>

  <!-- [HTML-7] 版尾 -->
  <div class="row mt-4">
    <div class="col">
      <div class="text-muted small">最後更新：<?php echo date('Y-m-d H:i'); ?></div>
    </div>
  </div>
</div>

<!-- [HTML-8] jQuery 與互動腳本 -->
<script src="https://code.jquery.com/jquery-3.7.1.min.js"></script>
<script>
/* [JS-1] 展開/收合所有步驟 */
$(document).on('click', '#btnExpand', function(){
  $('[id^="step-body-"]').each(function(){ $(this).addClass('show'); });
});
$(document).on('click', '#btnCollapse', function(){
  $('[id^="step-body-"]').each(function(){ $(this).removeClass('show'); });
});

/* [JS-2] 個別步驟的「更多說明」切換 */
$(document).on('click', '.toggle-step', function(){
  var sel = $(this).data('target');
  $(sel).toggleClass('show');
});

/* [JS-3] 一鍵複製所有步驟（標題 + 說明） */
$(document).on('click', '#btnCopy', function(){
  var lines = [];
  $('.step-card').each(function(i){
    var $card = $(this);
    var stepNo = i + 1;
    var title = $card.find('h2').text().trim();
    var desc  = $card.find('p').first().text().trim();
    lines.push('[' + stepNo + '] ' + title + '：' + desc);
  });
  var txt = lines.join('\n');
  navigator.clipboard.writeText(txt).then(function(){
    alert('步驟已複製到剪貼簿！');
  }, function(){
    alert('瀏覽器阻擋了複製；請手動選取文字。');
  });
});
</script>
</body>
</html>

