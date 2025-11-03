<?php
// #1 PHP 功能：權限驗證與匯入流程控制
session_start();

if (!isset($_SESSION['user']) || $_SESSION['user']['role'] !== 'admin') {
    header('Location: login.php');
    exit;
}

require_once '../db_connect.php';
$conn->set_charset('utf8mb4');

$message = '';
$error = '';
$failed_records = [];
$summary = [
    'total' => 0,
    'success' => 0,
    'failure' => 0,
];

if ($_SERVER['REQUEST_METHOD'] === 'POST') {
    if (isset($_FILES['attendance_file']) && $_FILES['attendance_file']['error'] === UPLOAD_ERR_OK) {
        $file_tmp_path = $_FILES['attendance_file']['tmp_name'];
        $file_content = file_get_contents($file_tmp_path);

        if ($file_content === false || $file_content === '') {
            $error = '❌ 檔案內容為空，請確認 TXT 檔案內容。';
        } else {
            $file_content_utf8 = mb_convert_encoding($file_content, 'UTF-8', 'BIG5');
            $rows = preg_split('/\r\n|\r|\n/', trim($file_content_utf8));

            $valid_methods = ['指紋', '刷卡', '人臉', '掌靜脈'];

            foreach ($rows as $row) {
                $row = trim($row);
                if ($row === '') {
                    continue;
                }

                $summary['total']++;
                $fields = str_getcsv($row);

                if (count($fields) !== 5) {
                    $summary['failure']++;
                    $failed_records[] = '❌ 欄位數量錯誤：' . $row;
                    continue;
                }

                [$raw_date, $raw_time, $employee_number, $attendance_method, $device_code] = array_map('trim', $fields);

                $date = DateTime::createFromFormat('Ymd', $raw_date);
                if (!$date) {
                    $summary['failure']++;
                    $failed_records[] = '❌ 日期格式錯誤：' . $row;
                    continue;
                }
                $log_date = $date->format('Y-m-d');

                if (!preg_match('/^\d{4}$/', $raw_time)) {
                    $summary['failure']++;
                    $failed_records[] = '❌ 時間格式錯誤：' . $row;
                    continue;
                }
                $log_time = substr($raw_time, 0, 2) . ':' . substr($raw_time, 2, 2) . ':00';

                if ($employee_number === '') {
                    $summary['failure']++;
                    $failed_records[] = '❌ 員工編號為空：' . $row;
                    continue;
                }

                $stmt_check = $conn->prepare('SELECT id FROM employees WHERE employee_number = ?');
                $stmt_check->bind_param('s', $employee_number);
                $stmt_check->execute();
                $result = $stmt_check->get_result();

                if ($result->num_rows === 0) {
                    $summary['failure']++;
                    $failed_records[] = '❌ 員工編號不存在：' . $row;
                    continue;
                }

                if (!in_array($attendance_method, $valid_methods, true)) {
                    $summary['failure']++;
                    $failed_records[] = '❌ 考勤方式錯誤：' . $row;
                    continue;
                }

                $stmt_insert = $conn->prepare('INSERT INTO attendance_logs (log_date, log_time, employee_number, attendance_method, device_code) VALUES (?, ?, ?, ?, ?)');
                $stmt_insert->bind_param('sssss', $log_date, $log_time, $employee_number, $attendance_method, $device_code);

                if ($stmt_insert->execute()) {
                    $summary['success']++;
                } else {
                    $summary['failure']++;
                    $failed_records[] = '❌ 資料庫寫入失敗：' . $row;
                }
            }

            if ($summary['total'] > 0) {
                $message = "✅ 匯入完成！成功匯入：{$summary['success']} 筆，失敗：{$summary['failure']} 筆。";
            } else {
                $error = '❌ 檔案內沒有可處理的資料列。';
            }
        }
    } else {
        $error = '❌ 上傳失敗，請選擇正確的 TXT 檔案並重試。';
    }
}
?>

<!DOCTYPE html>
<html lang="zh-TW">
<head>
    <meta charset="UTF-8">
    <meta name="viewport" content="width=device-width, initial-scale=1.0">
    <title>匯入考勤資料</title>
    <link href="https://cdn.jsdelivr.net/npm/bootstrap@5.3.3/dist/css/bootstrap.min.css" rel="stylesheet">
    <link href="https://cdn.jsdelivr.net/npm/bootstrap-icons@1.11.3/font/bootstrap-icons.min.css" rel="stylesheet">
    <link rel="stylesheet" href="admin_navbar.css">
    <style>
        :root {
            --brand-yellow: #ffcd00;
            --brand-pink: #e36386;
            --brand-blue: #345d9d;
        }

        body {
            background: radial-gradient(circle at top, rgba(255, 205, 0, 0.18), transparent 55%),
                        linear-gradient(135deg, rgba(52, 93, 157, 0.12) 0%, rgba(227, 99, 134, 0.1) 100%);
            font-family: 'Noto Sans TC', 'Microsoft JhengHei', sans-serif;
            color: #2c2c2c;
        }

        .page-header {
            background: #fff;
            border-radius: 18px;
            box-shadow: 0 18px 40px rgba(52, 93, 157, 0.08);
            padding: 24px 28px;
            margin-bottom: 24px;
            border-left: 6px solid var(--brand-blue);
        }

        .upload-card {
            border: none;
            border-radius: 20px;
            box-shadow: 0 20px 50px rgba(0, 0, 0, 0.08);
        }

        .upload-card .card-header {
            background: var(--brand-blue);
            color: #fff;
            border-top-left-radius: 20px;
            border-top-right-radius: 20px;
        }

        .upload-area {
            border: 2px dashed rgba(52, 93, 157, 0.35);
            border-radius: 16px;
            padding: 32px 24px;
            text-align: center;
            transition: all 0.3s ease;
            background: rgba(255, 255, 255, 0.9);
        }

        .upload-area.dragover {
            background: rgba(255, 205, 0, 0.2);
            border-color: var(--brand-pink);
        }

        .btn-brand {
            background: var(--brand-pink);
            border: none;
            color: #fff;
        }

        .btn-brand:hover {
            background: #cc5575;
            color: #fff;
        }

        .instruction-list li::marker {
            color: var(--brand-pink);
        }

        .summary-table th {
            background: var(--brand-blue);
            color: #fff;
        }

        .summary-table td {
            font-weight: 600;
        }

        .failed-table thead th {
            background: var(--brand-pink);
            color: #fff;
        }

        .badge-success-count {
            background: var(--brand-blue);
        }

        .badge-failure-count {
            background: var(--brand-pink);
        }

        .logo-wrapper img {
            height: 48px;
        }
    </style>
</head>
<body>
    <?php include 'admin_navbar.php'; ?>
    <main class="py-4 py-lg-5">
        <div class="container">
            <div class="page-header d-flex flex-column flex-lg-row align-items-lg-center justify-content-between gap-3">
                <div class="d-flex align-items-center gap-3">
                    <div class="logo-wrapper p-2 bg-white rounded-3 shadow-sm">
                        <img src="../LOGO/LOGO-05.png" alt="公司標誌">
                    </div>
                    <div>
                        <h2 class="mb-1 fw-bold text-dark">匯入考勤資料</h2>
                        <p class="mb-0 text-muted">上傳 TXT 檔後，系統會自動轉換為標準格式並入庫。</p>
                    </div>
                </div>
                <div class="text-lg-end">
                    <span class="badge rounded-pill badge-success-count me-2 px-3 py-2">成功：<?= number_format($summary['success']) ?> 筆</span>
                    <span class="badge rounded-pill badge-failure-count px-3 py-2">失敗：<?= number_format($summary['failure']) ?> 筆</span>
                </div>
            </div>

            <div class="row g-4">
                <div class="col-lg-7">
                    <div class="card upload-card h-100">
                        <div class="card-header d-flex align-items-center justify-content-between">
                            <span class="fw-semibold">TXT 檔上傳</span>
                            <span class="small">支援 BIG5 文字編碼</span>
                        </div>
                        <div class="card-body">
                            <p class="text-muted">請確認檔案欄位依序為：日期、時間、員工編號、考勤方式、設備代碼。</p>
                            <form method="POST" enctype="multipart/form-data" class="needs-validation" novalidate>
                                <div class="upload-area" id="uploadArea">
                                    <div class="mb-3">
                                        <i class="bi bi-cloud-arrow-up-fill display-6 text-primary"></i>
                                    </div>
                                    <p class="fw-semibold mb-1">拖曳或點擊以下按鈕選擇檔案</p>
                                    <p class="text-muted mb-3">僅接受副檔名為 .txt 的檔案</p>
                                    <input type="file" class="form-control" id="attendance_file" name="attendance_file" accept=".txt" required>
                                </div>
                                <div class="d-flex flex-column flex-sm-row align-items-sm-center gap-3 mt-4">
                                    <button type="submit" class="btn btn-brand px-4" id="uploadBtn">
                                        <span class="spinner-border spinner-border-sm me-2 d-none" id="uploadSpinner"></span>
                                        上傳並匯入
                                    </button>
                                    <small class="text-muted" id="selectedFile">尚未選擇檔案</small>
                                </div>
                            </form>
                            <?php if (!empty($message)): ?>
                                <div class="alert alert-success mt-4 mb-0"><?= htmlspecialchars($message) ?></div>
                            <?php elseif (!empty($error)): ?>
                                <div class="alert alert-danger mt-4 mb-0"><?= htmlspecialchars($error) ?></div>
                            <?php endif; ?>
                        </div>
                    </div>
                </div>
                <div class="col-lg-5">
                    <div class="card h-100 shadow-sm border-0">
                        <div class="card-body">
                            <h5 class="fw-semibold text-dark mb-3">匯入注意事項</h5>
                            <ol class="instruction-list ps-3 text-muted">
                                <li>TXT 檔案須使用 BIG5 編碼，欄位以逗號分隔。</li>
                                <li>日期格式請使用 <code>YYYYMMDD</code>，時間為 24 小時制 <code>HHMM</code>。</li>
                                <li>考勤方式限定：「指紋、刷卡、人臉、掌靜脈」。</li>
                                <li>匯入失敗的資料會列在下方表格，可檢視原因後修正再上傳。</li>
                                <li>匯入成功的資料會立即寫入系統，請確認檔案內容無誤。</li>
                            </ol>

                            <div class="table-responsive">
                                <table class="table table-bordered table-primary summary-table mt-4 mb-0">
                                    <thead>
                                        <tr>
                                            <th scope="col">項目</th>
                                            <th scope="col" class="text-center">筆數</th>
                                        </tr>
                                    </thead>
                                    <tbody>
                                        <tr>
                                            <td>檔案總列數</td>
                                            <td class="text-center"><?= number_format($summary['total']) ?></td>
                                        </tr>
                                        <tr>
                                            <td>成功匯入</td>
                                            <td class="text-center text-success"><?= number_format($summary['success']) ?></td>
                                        </tr>
                                        <tr>
                                            <td>匯入失敗</td>
                                            <td class="text-center text-danger"><?= number_format($summary['failure']) ?></td>
                                        </tr>
                                    </tbody>
                                </table>
                            </div>
                        </div>
                    </div>
                </div>
            </div>

            <?php if (!empty($failed_records)): ?>
                <div class="card shadow-sm border-0 mt-4">
                    <div class="card-body">
                        <h5 class="fw-semibold text-danger mb-3">⚠️ 無法匯入的記錄</h5>
                        <div class="table-responsive">
                            <table class="table table-bordered failed-table">
                                <thead>
                                    <tr>
                                        <th scope="col" style="width: 80px;">序號</th>
                                        <th scope="col">錯誤資訊</th>
                                    </tr>
                                </thead>
                                <tbody>
                                    <?php foreach ($failed_records as $index => $failed_record): ?>
                                        <tr>
                                            <td class="text-center align-middle">#<?= $index + 1 ?></td>
                                            <td><?= htmlspecialchars($failed_record) ?></td>
                                        </tr>
                                    <?php endforeach; ?>
                                </tbody>
                            </table>
                        </div>
                    </div>
                </div>
            <?php endif; ?>
        </div>
    </main>

    <script src="https://code.jquery.com/jquery-3.7.1.min.js"></script>
    <script src="https://cdn.jsdelivr.net/npm/bootstrap@5.3.3/dist/js/bootstrap.bundle.min.js"></script>
    <script>
        // #2 JS 功能：上傳互動與表單檢核
        $(function () {
            const $fileInput = $('#attendance_file');
            const $uploadArea = $('#uploadArea');
            const $selectedFile = $('#selectedFile');
            const $uploadBtn = $('#uploadBtn');
            const $uploadSpinner = $('#uploadSpinner');

            $fileInput.on('change', function () {
                const fileName = this.files.length ? this.files[0].name : '尚未選擇檔案';
                $selectedFile.text(fileName);
            });

            $uploadArea.on('dragover', function (event) {
                event.preventDefault();
                event.stopPropagation();
                $(this).addClass('dragover');
            });

            $uploadArea.on('dragleave drop', function (event) {
                event.preventDefault();
                event.stopPropagation();
                $(this).removeClass('dragover');
            });

            $('form').on('submit', function () {
                if (!$fileInput.val()) {
                    $selectedFile.text('請先選擇 TXT 檔案');
                    return false;
                }
                $uploadBtn.prop('disabled', true);
                $uploadSpinner.removeClass('d-none');
            });
        });
    </script>
</body>
</html>