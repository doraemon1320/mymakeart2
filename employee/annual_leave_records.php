<?php
session_start();

if (!isset($_SESSION['user']) ) {
    http_response_code(403);
    echo json_encode(['error' => '未授權的存取']);
    exit;
}

header('Content-Type: application/json');
$conn = new mysqli('localhost', 'root', '', 'mymakeart');
if ($conn->connect_error) {
    echo json_encode(['error' => '資料庫連線失敗']);
    exit;
}

$employee_number = $_SESSION['user']['employee_number'];
$page = isset($_GET['page']) ? max(1, intval($_GET['page'])) : 1;
$perPage = 5;
$offset = ($page - 1) * $perPage;

// 總筆數
$countStmt = $conn->prepare("SELECT COUNT(*) FROM annual_leave_records WHERE employee_number = ?");
$countStmt->bind_param("s", $employee_number);
$countStmt->execute();
$countResult = $countStmt->get_result()->fetch_row();
$total = $countResult[0];
$totalPages = ceil($total / $perPage);

// 取資料
$stmt = $conn->prepare("SELECT * FROM annual_leave_records WHERE employee_number = ? ORDER BY year DESC, month DESC, day DESC LIMIT ? OFFSET ?");
$stmt->bind_param("sii", $employee_number, $perPage, $offset);
$stmt->execute();
$result = $stmt->get_result();
$records = [];
while ($row = $result->fetch_assoc()) {
    $records[] = $row;
}

// 回傳 JSON
echo json_encode([
    'records' => $records,
    'currentPage' => $page,
    'totalPages' => $totalPages
]);
