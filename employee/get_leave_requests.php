<?php
session_start();
header('Content-Type: application/json');

if (!isset($_SESSION['user']) || $_SESSION['user']['role'] !== 'employee') {
    echo json_encode(["error" => "Unauthorized"]);
    exit;
}

$conn = new mysqli('localhost', 'root', '', 'mymakeart');
if ($conn->connect_error) {
    echo json_encode(["error" => "DB connect failed"]);
    exit;
}

$employee_number = $_SESSION['user']['employee_number'];
$page = intval($_GET['page'] ?? 1);
$page = max($page, 1);
$perPage = 5;
$offset = ($page - 1) * $perPage;

// 查總筆數
$count_stmt = $conn->prepare("SELECT COUNT(*) FROM requests WHERE employee_number = ?");
$count_stmt->bind_param("s", $employee_number);
$count_stmt->execute();
$count_stmt->bind_result($totalRows);
$count_stmt->fetch();
$count_stmt->close();

$totalPages = max(ceil($totalRows / $perPage), 1);

// 查請假資料
$sql = "SELECT type, subtype, reason, status, start_date, end_date
        FROM requests
        WHERE employee_number = ?
        ORDER BY created_at DESC
        LIMIT $offset, $perPage";

$stmt = $conn->prepare($sql);
$stmt->bind_param("s", $employee_number);
$stmt->execute();
$result = $stmt->get_result();

$records = [];
while ($row = $result->fetch_assoc()) {
    $records[] = $row;
}

echo json_encode([
    "records" => $records,
    "currentPage" => $page,
    "totalPages" => $totalPages
]);
