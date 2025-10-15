<?php
session_start();
if (!isset($_SESSION['user'])) {
    header("Location: ../login.php");
    exit;
}

$conn = new mysqli('localhost', 'root', '', 'mymakeart');
if ($conn->connect_error) {
    die("Database connection failed: " . $conn->connect_error);
}

$user_id = $_SESSION['user']['id'];

// 處理刪除圖片請求
if (isset($_GET['delete']) && in_array($_GET['delete'], ['profile_picture', 'id_card_front', 'id_card_back'])) {
    $field = $_GET['delete'];
    $old_file = $conn->query("SELECT $field FROM employees WHERE id = $user_id")->fetch_assoc()[$field];
    if ($old_file && file_exists($old_file)) {
        unlink($old_file);
    }
    $conn->query("UPDATE employees SET $field = NULL WHERE id = $user_id");
    header("Location: edit_profile.php");
    exit;
}

// 取得資料
$query = $conn->prepare("SELECT name, date_of_birth, gender, id_card, phone, address, profile_picture, id_card_front, id_card_back FROM employees WHERE id = ?");
$query->bind_param("i", $user_id);
$query->execute();
$user = $query->get_result()->fetch_assoc();

if ($_SERVER['REQUEST_METHOD'] === 'POST') {
    $name = $_POST['name'];
    $dob = $_POST['date_of_birth'];
    $gender = $_POST['gender'];
    $id_card = $_POST['id_card'];
    $phone = $_POST['phone'];
    $address = $_POST['address'];

    $emp_no = $_SESSION['user']['employee_number'];

    // 上傳處理函數
    function handleUpload($field, $emp_no, $default) {
        if (!empty($_FILES[$field]['name'])) {
            $ext = strtolower(pathinfo($_FILES[$field]['name'], PATHINFO_EXTENSION));
            if (!in_array($ext, ['jpg', 'jpeg', 'png', 'gif'])) {
                return $default;
            }

            if ($field === 'profile_picture') {
                $filename = "pic_" . $emp_no . "." . $ext;
                $folder = "../employee/uploads/profile_pictures/";
            } elseif ($field === 'id_card_front') {
                $filename = "id_" . $emp_no . "A." . $ext;
                $folder = "../employee/uploads/id_cards/";
            } elseif ($field === 'id_card_back') {
                $filename = "id_" . $emp_no . "B." . $ext;
                $folder = "../employee/uploads/id_cards/";
            } else {
                $filename = $field . "_" . $emp_no . "." . $ext;
                $folder = "../employee/uploads/";
            }

            if (!is_dir($folder)) {
                mkdir($folder, 0777, true);
            }

            $path = $folder . $filename;

            // 刪除舊檔
            if ($default && file_exists($default)) {
                unlink($default);
            }

            move_uploaded_file($_FILES[$field]['tmp_name'], $path);
            return $path;
        }
        return $default;
    }

    $profile_picture = handleUpload('profile_picture', $emp_no, $user['profile_picture']);
    $id_card_front   = handleUpload('id_card_front', $emp_no, $user['id_card_front']);
    $id_card_back    = handleUpload('id_card_back', $emp_no, $user['id_card_back']);

    $stmt = $conn->prepare("UPDATE employees SET name=?, date_of_birth=?, gender=?, id_card=?, phone=?, address=?, profile_picture=?, id_card_front=?, id_card_back=? WHERE id=?");
    $stmt->bind_param("sssssssssi", $name, $dob, $gender, $id_card, $phone, $address, $profile_picture, $id_card_front, $id_card_back, $user_id);
    $stmt->execute();

    header("Location: edit_profile.php");
    exit;
}
?>
<!DOCTYPE html>
<html lang="zh-TW">
<head>
    <meta charset="UTF-8">
    <title>修改資料</title>
    <link rel="stylesheet" href="https://cdn.jsdelivr.net/npm/bootstrap@5.3.3/dist/css/bootstrap.min.css">
    <link rel="stylesheet" href="employee_navbar.css">
    <style>
        .thumb-preview { position: relative; display: inline-block; margin-top: 5px; }
        .thumb-preview img { width: 100px; height: auto; border: 1px solid #ccc; }
        .thumb-preview a {
            position: absolute;
            top: -10px;
            right: -10px;
            background: red;
            color: white;
            border-radius: 50%;
            padding: 2px 6px;
            text-decoration: none;
            font-weight: bold;
        }
    </style>
</head>
<body>
<?php include 'employee_navbar.php'; ?>
<div class="container mt-4">
    <div class="card shadow">
        <div class="card-header bg-primary text-white">基本資料</div>
        <div class="card-body">
            <form method="POST" enctype="multipart/form-data">
                <div class="mb-3">
                    <label class="form-label">姓名</label>
                    <input type="text" name="name" class="form-control" value="<?= htmlspecialchars($user['name']) ?>" required>
                </div>
                <div class="mb-3">
                    <label class="form-label">出生年月日</label>
                    <input type="date" name="date_of_birth" class="form-control" value="<?= htmlspecialchars($user['date_of_birth']) ?>">
                </div>
                <div class="mb-3">
                    <label class="form-label">性別</label>
                    <select name="gender" class="form-select">
                        <option value="Male" <?= $user['gender'] === 'Male' ? 'selected' : '' ?>>男</option>
                        <option value="Female" <?= $user['gender'] === 'Female' ? 'selected' : '' ?>>女</option>
                        <option value="Other" <?= $user['gender'] === 'Other' ? 'selected' : '' ?>>其他</option>
                    </select>
                </div>
                <div class="mb-3">
                    <label class="form-label">身分證字號</label>
                    <input type="text" name="id_card" class="form-control" value="<?= htmlspecialchars($user['id_card']) ?>">
                </div>
                <div class="mb-3">
                    <label class="form-label">電話</label>
                    <input type="text" name="phone" class="form-control" value="<?= htmlspecialchars($user['phone']) ?>">
                </div>
                <div class="mb-3">
                    <label class="form-label">地址</label>
                    <textarea name="address" class="form-control"><?= htmlspecialchars($user['address']) ?></textarea>
                </div>
                <div class="bg-info text-white p-2 mt-4">圖像與身分證</div>
                <div class="mb-3">
                    <label class="form-label">大頭照</label>
                    <input type="file" name="profile_picture" accept=".jpg,.jpeg,.png,.gif" class="form-control">
                    <?php if ($user['profile_picture']): ?>
                        <div class="thumb-preview">
                            <img src="<?= $user['profile_picture'] ?>">
                            <a href="?delete=profile_picture" onclick="return confirm('確定要刪除大頭照嗎？')">&times;</a>
                        </div>
                    <?php endif; ?>
                </div>
                <div class="mb-3">
                    <label class="form-label">身分證正面</label>
                    <input type="file" name="id_card_front" accept=".jpg,.jpeg,.png,.gif" class="form-control">
                    <?php if ($user['id_card_front']): ?>
                        <div class="thumb-preview">
                            <img src="<?= $user['id_card_front'] ?>">
                            <a href="?delete=id_card_front" onclick="return confirm('確定要刪除身分證正面嗎？')">&times;</a>
                        </div>
                    <?php endif; ?>
                </div>
                <div class="mb-3">
                    <label class="form-label">身分證反面</label>
                    <input type="file" name="id_card_back" accept=".jpg,.jpeg,.png,.gif" class="form-control">
                    <?php if ($user['id_card_back']): ?>
                        <div class="thumb-preview">
                            <img src="<?= $user['id_card_back'] ?>">
                            <a href="?delete=id_card_back" onclick="return confirm('確定要刪除身分證反面嗎？')">&times;</a>
                        </div>
                    <?php endif; ?>
                </div>
                <div class="text-center">
                    <button class="btn btn-primary">儲存變更</button>
                </div>
            </form>
        </div>
    </div>
</div>
</body>
</html>
