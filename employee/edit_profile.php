<?php
// PHP 功能 #1：驗證登入狀態
session_start();
if (!isset($_SESSION['user'])) {
    header('Location: ../login.php');
    exit;
}

// PHP 功能 #2：建立資料庫連線
$conn = new mysqli('localhost', 'root', '', 'mymakeart');
if ($conn->connect_error) {
    die('資料庫連線失敗：' . $conn->connect_error);
}
$conn->set_charset('utf8mb4');

$user_id = (int)($_SESSION['user']['id'] ?? 0);
$employee_number = $_SESSION['user']['employee_number'] ?? '';

// PHP 功能 #3：處理刪除圖片請求
if (isset($_GET['delete']) && in_array($_GET['delete'], ['profile_picture', 'id_card_front', 'id_card_back'], true)) {
    $field = $_GET['delete'];
    $select_sql = "SELECT $field FROM employees WHERE id = ?";
    if ($stmt_select = $conn->prepare($select_sql)) {
        $stmt_select->bind_param('i', $user_id);
        $stmt_select->execute();
        $stmt_select->bind_result($old_file);
        $stmt_select->fetch();
        $stmt_select->close();

        if (!empty($old_file) && file_exists($old_file)) {
            unlink($old_file);
        }

        $update_sql = "UPDATE employees SET $field = NULL WHERE id = ?";
        if ($stmt_update = $conn->prepare($update_sql)) {
            $stmt_update->bind_param('i', $user_id);
            $stmt_update->execute();
            $stmt_update->close();
        }
    }

    header('Location: edit_profile.php?status=deleted');
    exit;
}

// PHP 功能 #4：取得使用者資料
$user = [];
$user_sql = $conn->prepare('SELECT name, date_of_birth, gender, id_card, phone, address, profile_picture, id_card_front, id_card_back FROM employees WHERE id = ?');
$user_sql->bind_param('i', $user_id);
$user_sql->execute();
$user = $user_sql->get_result()->fetch_assoc() ?? [];
$user_sql->close();

// PHP 功能 #5：處理表單送出並更新資料
if ($_SERVER['REQUEST_METHOD'] === 'POST') {
    $name    = trim($_POST['name'] ?? '');
    $dob     = trim($_POST['date_of_birth'] ?? '');
    $gender  = $_POST['gender'] ?? 'Other';
    $id_card = trim($_POST['id_card'] ?? '');
    $phone   = trim($_POST['phone'] ?? '');
    $address = trim($_POST['address'] ?? '');

    $handleUpload = function (string $field, string $empNo, ?string $defaultPath) {
        if (empty($_FILES[$field]['name'])) {
            return $defaultPath;
        }

        $ext = strtolower(pathinfo($_FILES[$field]['name'], PATHINFO_EXTENSION));
        if (!in_array($ext, ['jpg', 'jpeg', 'png', 'gif'], true)) {
            return $defaultPath;
        }

        switch ($field) {
            case 'profile_picture':
                $filename = 'pic_' . $empNo . '.' . $ext;
                $folder = '../employee/uploads/profile_pictures/';
                break;
            case 'id_card_front':
                $filename = 'id_' . $empNo . 'A.' . $ext;
                $folder = '../employee/uploads/id_cards/';
                break;
            case 'id_card_back':
                $filename = 'id_' . $empNo . 'B.' . $ext;
                $folder = '../employee/uploads/id_cards/';
                break;
            default:
                $filename = $field . '_' . $empNo . '.' . $ext;
                $folder = '../employee/uploads/';
        }

        if (!is_dir($folder)) {
            mkdir($folder, 0777, true);
        }

        $path = $folder . $filename;

        if (!empty($defaultPath) && file_exists($defaultPath)) {
            unlink($defaultPath);
        }

        move_uploaded_file($_FILES[$field]['tmp_name'], $path);
        return $path;
    };

    $profile_picture = $handleUpload('profile_picture', $employee_number, $user['profile_picture'] ?? null);
    $id_card_front   = $handleUpload('id_card_front', $employee_number, $user['id_card_front'] ?? null);
    $id_card_back    = $handleUpload('id_card_back', $employee_number, $user['id_card_back'] ?? null);

    $update = $conn->prepare('UPDATE employees SET name=?, date_of_birth=?, gender=?, id_card=?, phone=?, address=?, profile_picture=?, id_card_front=?, id_card_back=? WHERE id=?');
    $update->bind_param('sssssssssi', $name, $dob, $gender, $id_card, $phone, $address, $profile_picture, $id_card_front, $id_card_back, $user_id);
    $update->execute();
    $update->close();

    header('Location: edit_profile.php?status=success');
    exit;
}

$status_message = '';
if (isset($_GET['status'])) {
    $status = $_GET['status'];
    if ($status === 'success') {
        $status_message = '✅ 資料已成功更新。';
    } elseif ($status === 'deleted') {
        $status_message = '🗑️ 指定的圖片已刪除。';
    }
}
?>
<!DOCTYPE html>
<html lang="zh-Hant">
<head>
    <meta charset="UTF-8">
    <meta name="viewport" content="width=device-width, initial-scale=1.0">
    <title>員工個人資料維護</title>
    <link rel="stylesheet" href="https://cdn.jsdelivr.net/npm/bootstrap@5.3.3/dist/css/bootstrap.min.css">
    <link rel="stylesheet" href="employee_navbar.css">
    <style>
        :root {
            --brand-yellow: #ffcd00;
            --brand-pink: #e36386;
            --brand-blue: #345d9d;
            --brand-deep-blue: #225088;
        }
        body {
            background-color: #f5f7fb;
        }
        .profile-banner {
            background: linear-gradient(135deg, var(--brand-blue), var(--brand-pink));
            color: #fff;
            border-radius: 18px;
            padding: 32px 28px;
            box-shadow: 0 12px 24px rgba(34, 80, 136, 0.25);
        }
        .profile-banner h1 {
            font-size: 1.8rem;
            font-weight: 700;
        }
        .profile-banner p {
            margin-bottom: 0;
            font-size: 1rem;
        }
        .section-card {
            border: none;
            border-radius: 18px;
            box-shadow: 0 12px 24px rgba(52, 93, 157, 0.08);
        }
        .table thead.table-primary th {
            background-color: var(--brand-blue);
            color: #fff;
            border-color: var(--brand-blue);
        }
        .table tbody th {
            width: 22%;
            background-color: rgba(255, 205, 0, 0.12);
            color: var(--brand-deep-blue);
            font-weight: 600;
        }
        .table tbody td {
            background-color: #fff;
        }
        .table caption {
            caption-side: top;
            color: var(--brand-deep-blue);
            font-weight: 600;
            text-align: left;
            padding-bottom: 6px;
        }
        .upload-preview {
            background-color: #fff;
            border: 1px solid rgba(52, 93, 157, 0.2);
            border-radius: 16px;
            padding: 18px;
            box-shadow: 0 10px 20px rgba(0, 0, 0, 0.06);
            height: 100%;
        }
        .upload-preview .preview-image {
            border-radius: 12px;
            border: 1px dashed rgba(52, 93, 157, 0.35);
            background-color: rgba(255, 205, 0, 0.08);
            min-height: 160px;
            display: flex;
            align-items: center;
            justify-content: center;
            overflow: hidden;
        }
        .upload-preview .preview-image img {
            max-width: 100%;
            max-height: 200px;
            object-fit: contain;
        }
        .upload-preview .preview-placeholder {
            color: rgba(52, 93, 157, 0.65);
            font-size: 0.95rem;
        }
        .upload-preview .btn-delete {
            background-color: var(--brand-pink);
            color: #fff;
            border: none;
        }
        .upload-preview .btn-delete:hover {
            background-color: #c85171;
        }
        .required::after {
            content: '＊';
            color: var(--brand-pink);
            margin-left: 4px;
        }
    </style>
</head>
<body>
<?php include 'employee_navbar.php'; ?>

<div class="container py-4">
    <div class="row g-4">
        <div class="col-12">
            <div class="profile-banner">
                <h1 class="mb-2">個人資料管理中心</h1>
                <p>請維持您的基本資料最新，確保公司能即時聯繫您並提供完善的員工服務。</p>
            </div>
        </div>

        <?php if (!empty($status_message)): ?>
            <div class="col-12">
                <div class="alert alert-info alert-dismissible fade show shadow-sm mb-0" role="alert" data-status-message="true">
                    <?= htmlspecialchars($status_message) ?>
                    <button type="button" class="btn-close" data-bs-dismiss="alert" aria-label="關閉"></button>
                </div>
            </div>
        <?php endif; ?>

        <div class="col-12">
            <form method="POST" enctype="multipart/form-data" class="section-card bg-white p-4">
                <table class="table table-bordered align-middle mb-4">
                    <caption>基本資料維護</caption>
                    <thead class="table-primary">
                        <tr>
                            <th colspan="2">員工個人資訊</th>
                        </tr>
                    </thead>
                    <tbody>
                        <tr>
                            <th scope="row" class="required">姓名</th>
                            <td>
                                <input type="text" name="name" class="form-control" value="<?= htmlspecialchars($user['name'] ?? '') ?>" required>
                            </td>
                        </tr>
                        <tr>
                            <th scope="row">出生年月日</th>
                            <td>
                                <input type="date" name="date_of_birth" class="form-control" value="<?= htmlspecialchars($user['date_of_birth'] ?? '') ?>">
                            </td>
                        </tr>
                        <tr>
                            <th scope="row">性別</th>
                            <td>
                                <div class="row g-2">
                                    <div class="col-sm-4">
                                        <select name="gender" class="form-select">
                                            <option value="Male" <?= (($user['gender'] ?? '') === 'Male') ? 'selected' : '' ?>>男</option>
                                            <option value="Female" <?= (($user['gender'] ?? '') === 'Female') ? 'selected' : '' ?>>女</option>
                                            <option value="Other" <?= (($user['gender'] ?? '') === 'Other') ? 'selected' : '' ?>>其他</option>
                                        </select>
                                    </div>
                                </div>
                            </td>
                        </tr>
                        <tr>
                            <th scope="row">身分證字號</th>
                            <td>
                                <input type="text" name="id_card" class="form-control" value="<?= htmlspecialchars($user['id_card'] ?? '') ?>" placeholder="請輸入身分證字號">
                            </td>
                        </tr>
                        <tr>
                            <th scope="row">聯絡電話</th>
                            <td>
                                <input type="text" name="phone" class="form-control" value="<?= htmlspecialchars($user['phone'] ?? '') ?>" placeholder="請輸入聯絡電話">
                            </td>
                        </tr>
                        <tr>
                            <th scope="row">通訊地址</th>
                            <td>
                                <textarea name="address" class="form-control" rows="3" placeholder="請填寫現居地址"><?= htmlspecialchars($user['address'] ?? '') ?></textarea>
                            </td>
                        </tr>
                    </tbody>
                </table>

                <table class="table table-bordered align-middle mb-4">
                    <caption>影像檔案管理</caption>
                    <thead class="table-primary">
                        <tr>
                            <th colspan="2">身分證明與個人照片</th>
                        </tr>
                    </thead>
                    <tbody>
                        <tr>
                            <th scope="row">大頭照</th>
                            <td>
                                <div class="row g-3 align-items-stretch">
                                    <div class="col-lg-6">
                                        <input type="file" name="profile_picture" accept=".jpg,.jpeg,.png,.gif" class="form-control" data-preview-target="#preview-profile">
                                        <small class="text-muted">建議使用 400x400 以上之清晰照片，支援 JPG、PNG、GIF。</small>
                                    </div>
                                    <div class="col-lg-6">
                                        <div class="upload-preview text-center h-100">
                                            <div class="preview-image mb-3" id="preview-profile">
                                                <?php if (!empty($user['profile_picture'])): ?>
                                                    <img src="<?= htmlspecialchars($user['profile_picture']) ?>" alt="目前大頭照">
                                                <?php else: ?>
                                                    <span class="preview-placeholder">尚未上傳大頭照</span>
                                                <?php endif; ?>
                                            </div>
                                            <?php if (!empty($user['profile_picture'])): ?>
                                                <a href="?delete=profile_picture" class="btn btn-sm btn-delete" onclick="return confirm('確定要刪除大頭照嗎？')">刪除目前圖片</a>
                                            <?php endif; ?>
                                        </div>
                                    </div>
                                </div>
                            </td>
                        </tr>
                        <tr>
                            <th scope="row">身分證正面</th>
                            <td>
                                <div class="row g-3 align-items-stretch">
                                    <div class="col-lg-6">
                                        <input type="file" name="id_card_front" accept=".jpg,.jpeg,.png,.gif" class="form-control" data-preview-target="#preview-id-front">
                                        <small class="text-muted">檔案須清晰可辨識，僅供人資內部審核。</small>
                                    </div>
                                    <div class="col-lg-6">
                                        <div class="upload-preview text-center h-100">
                                            <div class="preview-image mb-3" id="preview-id-front">
                                                <?php if (!empty($user['id_card_front'])): ?>
                                                    <img src="<?= htmlspecialchars($user['id_card_front']) ?>" alt="身分證正面">
                                                <?php else: ?>
                                                    <span class="preview-placeholder">尚未上傳身分證正面</span>
                                                <?php endif; ?>
                                            </div>
                                            <?php if (!empty($user['id_card_front'])): ?>
                                                <a href="?delete=id_card_front" class="btn btn-sm btn-delete" onclick="return confirm('確定要刪除身分證正面嗎？')">刪除目前圖片</a>
                                            <?php endif; ?>
                                        </div>
                                    </div>
                                </div>
                            </td>
                        </tr>
                        <tr>
                            <th scope="row">身分證反面</th>
                            <td>
                                <div class="row g-3 align-items-stretch">
                                    <div class="col-lg-6">
                                        <input type="file" name="id_card_back" accept=".jpg,.jpeg,.png,.gif" class="form-control" data-preview-target="#preview-id-back">
                                        <small class="text-muted">請確保影像完整、字體清楚，方便後續審核。</small>
                                    </div>
                                    <div class="col-lg-6">
                                        <div class="upload-preview text-center h-100">
                                            <div class="preview-image mb-3" id="preview-id-back">
                                                <?php if (!empty($user['id_card_back'])): ?>
                                                    <img src="<?= htmlspecialchars($user['id_card_back']) ?>" alt="身分證反面">
                                                <?php else: ?>
                                                    <span class="preview-placeholder">尚未上傳身分證反面</span>
                                                <?php endif; ?>
                                            </div>
                                            <?php if (!empty($user['id_card_back'])): ?>
                                                <a href="?delete=id_card_back" class="btn btn-sm btn-delete" onclick="return confirm('確定要刪除身分證反面嗎？')">刪除目前圖片</a>
                                            <?php endif; ?>
                                        </div>
                                    </div>
                                </div>
                            </td>
                        </tr>
                    </tbody>
                </table>

                <div class="text-center pt-2">
                    <button type="submit" class="btn btn-lg" style="background-color: var(--brand-blue); color: #fff; border-radius: 12px; padding: 10px 48px;">
                        儲存變更
                    </button>
                </div>
            </form>
        </div>
    </div>
</div>

<script src="https://code.jquery.com/jquery-3.7.1.min.js"></script>
<script src="https://cdn.jsdelivr.net/npm/bootstrap@5.3.3/dist/js/bootstrap.bundle.min.js"></script>
<script>
// JS 功能 #1：即時預覽上傳圖片
$(function () {
    $('input[type="file"][data-preview-target]').on('change', function (event) {
        const target = $(this).data('preview-target');
        const previewContainer = $(target);
        const files = event.target.files;

        if (!previewContainer.length) {
            return;
        }

        if (!files || !files[0]) {
            previewContainer.html('<span class="preview-placeholder">尚未選擇檔案</span>');
            return;
        }

        const reader = new FileReader();
        reader.onload = function (e) {
            previewContainer.html('<img src="' + e.target.result + '" alt="預覽圖片">');
        };
        reader.readAsDataURL(files[0]);
    });

    // JS 功能 #2：關閉提示訊息後移除網址參數
    const statusAlert = $('[data-status-message="true"]');
    if (statusAlert.length) {
        statusAlert.on('closed.bs.alert', function () {
            const url = new URL(window.location.href);
            url.searchParams.delete('status');
            const params = url.searchParams.toString();
            const newUrl = url.pathname + (params ? '?' + params : '') + url.hash;
            window.history.replaceState({}, document.title, newUrl);
        });
    }
});
</script>
</body>
</html>