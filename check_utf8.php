<?php
$targetFolder = __DIR__; // 從當前資料夾掃描
$extensions = ['php', 'css', 'js', 'html']; // 要檢查的檔案類型
$results = [];

function is_utf8($filename) {
    $content = file_get_contents($filename);
    return mb_check_encoding($content, 'UTF-8');
}

function scanFolder($dir) {
    global $results, $extensions;

    foreach (scandir($dir) as $file) {
        if ($file === '.' || $file === '..') continue;

        $fullPath = $dir . DIRECTORY_SEPARATOR . $file;

        if (is_dir($fullPath)) {
            scanFolder($fullPath); // 遞迴掃子資料夾
        } else {
            $ext = pathinfo($file, PATHINFO_EXTENSION);
            if (in_array($ext, $extensions)) {
                $results[] = [
                    'path' => $fullPath,
                    'is_utf8' => is_utf8($fullPath)
                ];
            }
        }
    }
}

scanFolder($targetFolder);
?>

<!DOCTYPE html>
<html lang="zh-Hant">
<head>
    <meta charset="UTF-8">
    <title>UTF-8 編碼檢查工具</title>
    <style>
        body { font-family: "Microsoft JhengHei", sans-serif; padding: 30px; }
        h1 { font-size: 22px; margin-bottom: 20px; }
        .ok { color: green; }
        .fail { color: red; }
        ul { line-height: 1.6; }
    </style>
</head>
<body>
    <h1>📋 UTF-8 編碼檢查結果</h1>
    <ul>
        <?php foreach ($results as $file): ?>
            <li class="<?= $file['is_utf8'] ? 'ok' : 'fail' ?>">
                <?= $file['is_utf8'] ? '✅' : '❌' ?>
                <?= str_replace(__DIR__, '.', $file['path']) ?>
            </li>
        <?php endforeach; ?>
    </ul>
</body>
</html>
<?php
$targetFolder = __DIR__; // 從當前資料夾掃描
$extensions = ['php', 'css', 'js', 'html']; // 要檢查的檔案類型
$results = [];

function is_utf8($filename) {
    $content = file_get_contents($filename);
    return mb_check_encoding($content, 'UTF-8');
}

function scanFolder($dir) {
    global $results, $extensions;

    foreach (scandir($dir) as $file) {
        if ($file === '.' || $file === '..') continue;

        $fullPath = $dir . DIRECTORY_SEPARATOR . $file;

        if (is_dir($fullPath)) {
            scanFolder($fullPath); // 遞迴掃子資料夾
        } else {
            $ext = pathinfo($file, PATHINFO_EXTENSION);
            if (in_array($ext, $extensions)) {
                $results[] = [
                    'path' => $fullPath,
                    'is_utf8' => is_utf8($fullPath)
                ];
            }
        }
    }
}

scanFolder($targetFolder);
?>

<!DOCTYPE html>
<html lang="zh-Hant">
<head>
    <meta charset="UTF-8">
    <title>UTF-8 編碼檢查工具</title>
    <style>
        body { font-family: "Microsoft JhengHei", sans-serif; padding: 30px; }
        h1 { font-size: 22px; margin-bottom: 20px; }
        .ok { color: green; }
        .fail { color: red; }
        ul { line-height: 1.6; }
    </style>
</head>
<body>
    <h1>📋 UTF-8 編碼檢查結果</h1>
    <ul>
        <?php foreach ($results as $file): ?>
            <li class="<?= $file['is_utf8'] ? 'ok' : 'fail' ?>">
                <?= $file['is_utf8'] ? '✅' : '❌' ?>
                <?= str_replace(__DIR__, '.', $file['path']) ?>
            </li>
        <?php endforeach; ?>
    </ul>
</body>
</html>
