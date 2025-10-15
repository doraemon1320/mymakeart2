<?php
session_start();

// ✅ 登入驗證
if (!isset($_SESSION['user']) ) {
    header('Location: ../login.php');
    exit;
}

$conn = new mysqli('localhost', 'root', '', 'mymakeart');
if ($conn->connect_error) {
    die("資料庫連接失敗：" . $conn->connect_error);
}

$employee_number = $_SESSION['user']['employee_number'] ?? '';
$perPage = 20;
$page = isset($_GET['page']) ? max(1, intval($_GET['page'])) : 1;
$offset = ($page - 1) * $perPage;

// 查總數
$stmt = $conn->prepare("SELECT COUNT(*) FROM requests WHERE employee_number = ?");
$stmt->bind_param("s", $employee_number);
$stmt->execute();
$stmt->bind_result($totalRows);
$stmt->fetch();
$stmt->close();
$totalPages = ceil($totalRows / $perPage);

// 查資料
$stmt = $conn->prepare("
    SELECT type, subtype, reason, status, start_date, end_date, created_at
    FROM requests
    WHERE employee_number = ?
    ORDER BY created_at DESC
    LIMIT ?, ?
");
$stmt->bind_param("sii", $employee_number, $offset, $perPage);
$stmt->execute();
$result = $stmt->get_result();
$requests = $result->fetch_all(MYSQLI_ASSOC);
?>
<!DOCTYPE html>
<html lang="zh-TW">
<head>
    <meta charset="UTF-8">
    <title>請假/加班申請記錄</title>
    <meta name="viewport" content="width=device-width, initial-scale=1.0">
    <!-- Bootstrap + 自訂樣式 -->
    <link href="https://cdn.jsdelivr.net/npm/bootstrap@5.3.3/dist/css/bootstrap.min.css" rel="stylesheet">
    <link rel="stylesheet" href="employee_navbar.css">

</head>
<body class="bg-light">
<?php include 'employee_navbar.php'; ?>
<div class="container my-4">
    <h4 class="mb-3"><i class="bi bi-journal-check me-2"></i>請假/加班申請紀錄</h4>

    <?php if (empty($requests)): ?>
        <div class="alert alert-info">目前尚無申請紀錄。</div>
    <?php else: ?>
        <div class="table-responsive">
        <table class="table table-bordered align-middle">
            <thead class="table-secondary">
                <tr>
                    <th>類型</th>
                    <th>假別</th>
                    <th>理由</th>
                    <th>狀態</th>
                    <th>起始</th>
                    <th>結束</th>
                    <th>申請時間</th>
                </tr>
            </thead>
            <tbody>
                <?php foreach ($requests as $r): ?>
                    <tr>
                        <td><?= htmlspecialchars($r['type']) ?></td>
                        <td><?= $r['subtype'] === '特休假' ? '特休假' : htmlspecialchars($r['subtype']) ?></td>
                        <td><?= htmlspecialchars($r['reason']) ?></td>
                        <td>
                            <?php
                                echo match($r['status']) {
                                    'Approved' => '<span class="text-success">🟢 已通過</span>',
                                    'Pending' => '<span class="text-warning">🟡 審查中</span>',
                                    default => '<span class="text-danger">🔴 不通過</span>'
                                };
                            ?>
                        </td>
                        <td><?= htmlspecialchars($r['start_date']) ?></td>
                        <td><?= htmlspecialchars($r['end_date']) ?></td>
                        <td><?= htmlspecialchars($r['created_at']) ?></td>
                    </tr>
                <?php endforeach; ?>
            </tbody>
        </table>
        </div>

        <!-- 分頁 -->
        <nav class="text-center mt-3">
            <div class="pagination justify-content-center">
                <?php if ($page > 1): ?>
                    <a class="btn btn-outline-secondary btn-sm me-2" href="?page=<?= $page - 1 ?>">← 上一頁</a>
                <?php endif; ?>
                <?php for ($i = 1; $i <= $totalPages; $i++): ?>
                    <a class="btn btn-sm <?= ($i == $page) ? 'btn-primary' : 'btn-outline-primary' ?> me-1" href="?page=<?= $i ?>"><?= $i ?></a>
                <?php endfor; ?>
                <?php if ($page < $totalPages): ?>
                    <a class="btn btn-outline-secondary btn-sm ms-2" href="?page=<?= $page + 1 ?>">下一頁 →</a>
                <?php endif; ?>
            </div>
        </nav>
    <?php endif; ?>
</div>
</body>
</html>
