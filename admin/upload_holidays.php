<?php
session_start();

// 【PHP-1】登入權限檢查：僅允許管理者進入頁面
if (!isset($_SESSION['user']) || $_SESSION['user']['role'] !== 'admin') {
    header('Location: login.php');
    exit;
}

// 【PHP-2】建立資料庫連線
$conn = new mysqli('localhost', 'root', '', 'mymakeart');
if ($conn->connect_error) {
    die("資料庫連接失敗：" . $conn->connect_error);
}

// 【PHP-3】預設回應參數
$message = '';
$insert_count = 0;
$skip_count = 0;
$is_success = false;
$has_result = false;

// 【PHP-4】處理假日 JSON 匯入
if ($_SERVER['REQUEST_METHOD'] === 'POST' && isset($_FILES['holidays_file'])) {
    $has_result = true;
    $file_tmp = $_FILES['holidays_file']['tmp_name'];
    $file_name = $_FILES['holidays_file']['name'] ?? '';
    $file_type = $_FILES['holidays_file']['type'] ?? '';

    if ($file_type === 'application/json' || preg_match('/\.json$/i', $file_name)) {
        $json_data = file_get_contents($file_tmp);
        $data = json_decode($json_data, true);

        if ($data !== null && isset($data['holidays']) && isset($data['workdays'])) {
            $stmt_check = $conn->prepare("SELECT 1 FROM holidays WHERE holiday_date = ?");
            $stmt_insert = $conn->prepare("
                INSERT INTO holidays (holiday_date, description, is_working_day)
                VALUES (?, ?, ?)
            ");

            foreach ($data['holidays'] as $date => $desc) {
                $stmt_check->bind_param('s', $date);
                $stmt_check->execute();
                $stmt_check->store_result();

                if ($stmt_check->num_rows === 0) {
                    $is_working_day = 0;
                    $stmt_insert->bind_param('ssi', $date, $desc, $is_working_day);
                    $stmt_insert->execute();
                    $insert_count++;
                } else {
                    $skip_count++;
                }
            }

            foreach ($data['workdays'] as $date => $desc) {
                $stmt_check->bind_param('s', $date);
                $stmt_check->execute();
                $stmt_check->store_result();

                if ($stmt_check->num_rows === 0) {
                    $is_working_day = 1;
                    $stmt_insert->bind_param('ssi', $date, $desc, $is_working_day);
                    $stmt_insert->execute();
                    $insert_count++;
                } else {
                    $skip_count++;
                }
            }

            $is_success = true;
            $message = "匯入完成，新增 {$insert_count} 筆，略過 {$skip_count} 筆已存在日期。";
        } else {
            $message = "JSON 格式錯誤或缺少 holidays、workdays 節點。";
        }
    } else {
        $message = "請確認上傳檔案為 .json 格式。";
    }
}
?>

<!DOCTYPE html>
<html lang="zh-TW">
<head>
    <meta charset="UTF-8">
    <meta name="viewport" content="width=device-width, initial-scale=1.0">
    <title>匯入台灣假日資料</title>
    <link href="https://cdn.jsdelivr.net/npm/bootstrap@5.3.3/dist/css/bootstrap.min.css" rel="stylesheet">
    <link rel="stylesheet" href="admin_navbar.css">
    <style>
        body {
            background: #f6f7fb;
        }

        .page-banner {
            background: linear-gradient(135deg, #ffcd00 0%, #e36386 50%, #345d9d 100%);
            color: #fff;
        }

        .info-badge {
            display: inline-flex;
            align-items: center;
            gap: 0.5rem;
            padding: 0.5rem 0.75rem;
            border-radius: 999px;
            background: rgba(255, 255, 255, 0.25);
            font-weight: 600;
        }

        .json-sample {
            background: #fff8e1;
            border: 1px solid #ffe082;
            padding: 1rem;
            border-radius: 0.75rem;
            font-family: "Courier New", monospace;
            font-size: 0.95rem;
            color: #5f4b00;
            white-space: pre-wrap;
        }

        .card-shadow {
            box-shadow: 0 15px 35px rgba(52, 93, 157, 0.08);
            border: none;
        }

        .btn-brand {
            background-color: #ffcd00;
            color: #212529;
            border: none;
            font-weight: 700;
        }

        .btn-brand:hover {
            background-color: #f1bc00;
            color: #212529;
        }

        .result-table td,
        .result-table th {
            vertical-align: middle;
        }
    </style>
</head>
<body>
<?php include 'admin_navbar.php'; ?>

<div class="container my-4 my-lg-5">
    <div class="page-banner rounded-4 p-4 p-lg-5 mb-4">
        <div class="row align-items-center g-4">
            <div class="col-lg-8">
                <div class="info-badge mb-3">
                    <span class="fs-5">📅 台灣假日資料匯入</span>
                </div>
                <h1 class="fw-bold mb-3">匯入官方假日與補班日</h1>
                <p class="mb-0 fs-5">請上傳依照政府公告整理的 JSON 檔案，系統會自動建立假日與補班日，並略過重複日期。</p>
            </div>
            <div class="col-lg-4 text-lg-end">
                <div class="bg-white bg-opacity-25 rounded-4 p-3">
                    <p class="mb-1">匯入前請確認：</p>
                    <ul class="mb-0 ps-3 small">
                        <li>日期格式為 <strong>YYYY-MM-DD</strong></li>
                        <li>holidays、workdays 兩個節點皆存在</li>
                        <li>內容採 UTF-8 編碼儲存</li>
                    </ul>
                </div>
            </div>
        </div>
    </div>

    <?php if ($has_result): ?>
        <div class="alert <?= $is_success ? 'alert-success' : 'alert-danger' ?> rounded-4 shadow-sm" role="alert">
            <?= htmlspecialchars($message) ?>
        </div>
    <?php endif; ?>

    <?php if ($has_result): ?>
        <div class="card card-shadow mb-4">
            <div class="card-header bg-primary bg-gradient text-white fw-bold">匯入結果摘要</div>
            <div class="card-body p-0">
                <table class="table table-bordered table-hover m-0 result-table">
                    <thead class="table-primary">
                        <tr>
                            <th scope="col">新增筆數</th>
                            <th scope="col">略過筆數</th>
                            <th scope="col">提示說明</th>
                        </tr>
                    </thead>
                    <tbody>
                        <tr>
                            <td class="fw-bold text-success"><?= number_format($insert_count) ?></td>
                            <td class="fw-bold text-warning"><?= number_format($skip_count) ?></td>
                            <td><?= $is_success ? '資料已寫入假日設定中，如需調整請前往假期設定頁面。' : '請檢查檔案內容是否符合規範後重新上傳。' ?></td>
                        </tr>
                    </tbody>
                </table>
            </div>
        </div>
    <?php endif; ?>

    <div class="row g-4 align-items-stretch mb-4">
        <div class="col-lg-6">
            <div class="card card-shadow h-100">
                <div class="card-header bg-primary bg-gradient text-white fw-bold">JSON 檔案結構</div>
                <div class="card-body">
                    <p class="text-muted">以下為建議格式，holidays 為放假日，workdays 為補班日：</p>
                    <pre class="json-sample mb-0">{
  "holidays": {
    "2025-01-01": "元旦",
    "2025-02-28": "和平紀念日"
  },
  "workdays": {
    "2025-02-17": "補班日"
  }
}</pre>
                </div>
            </div>
        </div>
        <div class="col-lg-6">
            <div class="card card-shadow h-100">
                <div class="card-header bg-primary bg-gradient text-white fw-bold">匯入流程重點</div>
                <div class="card-body p-0">
                    <table class="table table-bordered mb-0">
                        <thead class="table-primary">
                            <tr>
                                <th scope="col" class="w-25">步驟</th>
                                <th scope="col">內容說明</th>
                            </tr>
                        </thead>
                        <tbody>
                            <tr>
                                <td>1. 整理資料</td>
                                <td>依照政府公布之行事曆，分別整理假日與補班日兩個節點。</td>
                            </tr>
                            <tr>
                                <td>2. 檢查格式</td>
                                <td>確認 JSON 無語法錯誤、採 UTF-8 編碼並使用 YYYY-MM-DD 日期格式。</td>
                            </tr>
                            <tr>
                                <td>3. 上傳匯入</td>
                                <td>系統會檢查日期是否已存在，若重複將自動略過，避免重複寫入。</td>
                            </tr>
                        </tbody>
                    </table>
                </div>
            </div>
        </div>
    </div>

    <div class="card card-shadow">
        <div class="card-header bg-primary bg-gradient text-white fw-bold">上傳 JSON 檔案</div>
        <div class="card-body">
            <form method="POST" enctype="multipart/form-data" class="row g-3" id="holidayForm">
                <div class="col-md-8">
                    <label for="holidays_file" class="form-label">選擇假日檔案（.json）</label>
                    <input type="file" name="holidays_file" id="holidays_file" accept=".json" required class="form-control">
                    <div class="form-text">僅接受 JSON 檔案，建議檔名格式：<span class="fw-semibold">TW-holidays-2025.json</span></div>
                </div>
                <div class="col-md-4 d-flex align-items-end">
                    <button type="submit" class="btn btn-brand btn-lg w-100" id="submitBtn">
                        <span class="me-2">⬆️</span>上傳並匯入
                    </button>
                </div>
            </form>
        </div>
    </div>
</div>

<script src="https://code.jquery.com/jquery-3.7.1.min.js"></script>
<script src="https://cdn.jsdelivr.net/npm/bootstrap@5.3.3/dist/js/bootstrap.bundle.min.js"></script>
<script>
    // 【JS-1】上傳前基本檢核：確認檔案副檔名
    $(document).ready(function () {
        $('#holidayForm').on('submit', function (event) {
            const fileInput = $('#holidays_file')[0];
            if (!fileInput.files.length) {
                alert('請先選擇要匯入的 JSON 檔案。');
                event.preventDefault();
                return;
            }

            const fileName = fileInput.files[0].name.toLowerCase();
            if (!fileName.endsWith('.json')) {
                alert('檔案格式錯誤，僅能上傳 .json 檔案。');
                event.preventDefault();
            }
        });
    });
</script>
</body>
</html>