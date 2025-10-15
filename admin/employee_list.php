<?php
session_start();
if (!isset($_SESSION['user']) || $_SESSION['user']['role'] !== 'admin') {
    header('Location: login.php');
    exit;
}

$conn = new mysqli('localhost', 'root', '', 'mymakeart');
if ($conn->connect_error) {
    die("資料庫連線失敗：" . $conn->connect_error);
}

$items_per_page = 10;
$current_page = isset($_GET['page']) && is_numeric($_GET['page']) ? (int)$_GET['page'] : 1;
$offset = ($current_page - 1) * $items_per_page;

$total_items_result = $conn->query("SELECT COUNT(*) AS total FROM employees");
$total_items = $total_items_result->fetch_assoc()['total'];
$total_pages = ceil($total_items / $items_per_page);

$stmt = $conn->prepare("SELECT 
    e.id AS 員工ID,
    e.employee_number AS 工號,
    e.name AS 姓名,
    CASE 
        WHEN e.gender = 'male' THEN '男'
        WHEN e.gender = 'female' THEN '女'
        WHEN e.gender = 'other' THEN '其他'
        ELSE '未知'
    END AS 性別,
    IFNULL(s.name, '未指定') AS 班別,
    IFNULL(e.department, '無') AS 部門,
    IFNULL(e.position, '無') AS 職位,
    CASE 
        WHEN e.employment_status = 'active' THEN '在職'
        WHEN e.employment_status = 'inactive' THEN '離職'
        ELSE '未知'
    END AS 在職狀況,
    e.hire_date AS 到職日
FROM employees e
LEFT JOIN shifts s ON e.shift_id = s.id
ORDER BY e.hire_date DESC
LIMIT ? OFFSET ?");
$stmt->bind_param("ii", $items_per_page, $offset);
$stmt->execute();
$employees = $stmt->get_result();
?>

<!DOCTYPE html>
<html lang="zh-Hant">
<head>
    <meta charset="UTF-8">
    <title>員工資料表</title>
    <link href="https://cdn.jsdelivr.net/npm/bootstrap@5.3.3/dist/css/bootstrap.min.css" rel="stylesheet">
    <style>
        .table th, .table td { vertical-
			align: middle; }
    </style>
	
	
</head>
<body>
    <?php include "admin_navbar.php"; ?>

    <div class="container mt-4">
        <div class="d-flex justify-content-between align-items-center mb-3">
            <h2>員工資料表</h2>
            <a href="add_employee.php" class="btn btn-primary">➕ 新增員工</a>
        </div>

        <p class="text-muted">以下顯示所有員工的基本資料：</p>

        <div class="table-responsive">
            <table class="table table-bordered table-hover text-center align-middle">
                <thead class="table-light">
                    <tr>
                        <th>工號</th>
                        <th>姓名</th>
                        <th>性別</th>
                        <th>班別</th>
                        <th>部門</th>
                        <th>職位</th>
                        <th>在職狀況</th>
                        <th>到職日</th>
                    </tr>
                </thead>
                <tbody>
                    <?php while ($employee = $employees->fetch_assoc()): ?>
                        <tr>
                            <td><?= htmlspecialchars($employee['工號']) ?></td>
                            <td>
                                <a href="employee_detail.php?id=<?= htmlspecialchars($employee['員工ID']) ?>">
                                    <?= htmlspecialchars($employee['姓名']) ?>
                                </a>
                            </td>
                            <td><?= htmlspecialchars($employee['性別']) ?></td>
                            <td><?= htmlspecialchars($employee['班別']) ?></td>
                            <td><?= htmlspecialchars($employee['部門']) ?></td>
                            <td><?= htmlspecialchars($employee['職位']) ?></td>
                            <td><?= htmlspecialchars($employee['在職狀況']) ?></td>
                            <td><?= htmlspecialchars($employee['到職日']) ?></td>
                        </tr>
                    <?php endwhile; ?>
                </tbody>
            </table>
        </div>

        <nav>
            <ul class="pagination justify-content-center mt-4">
                <?php if ($current_page > 1): ?>
                    <li class="page-item">
                        <a class="page-link" href="?page=<?= $current_page - 1 ?>">上一頁</a>
                    </li>
                <?php endif; ?>

                <?php for ($i = 1; $i <= $total_pages; $i++): ?>
                    <li class="page-item <?= $i === $current_page ? 'active' : '' ?>">
                        <a class="page-link" href="?page=<?= $i ?>"><?= $i ?></a>
                    </li>
                <?php endfor; ?>

                <?php if ($current_page < $total_pages): ?>
                    <li class="page-item">
                        <a class="page-link" href="?page=<?= $current_page + 1 ?>">下一頁</a>
                    </li>
                <?php endif; ?>
            </ul>
        </nav>
    </div>
</body>
</html>
