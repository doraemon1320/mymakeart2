<?php
// ? 除錯用：顯示錯誤
ini_set('display_errors', 1);
error_reporting(E_ALL);

session_start();
header('Content-Type: application/json');

$response = [
    "records" => [],
    "currentPage" => 1,
    "totalPages" => 1
];

// ? 登入驗證
if (!isset($_SESSION['user']) || $_SESSION['user']['role'] !== 'employee') {
    echo json_encode(["error" => "Unauthorized"]);
    exit;
}

$employee_id = $_SESSION['user']['id'];
$conn = new mysqli('localhost', 'root', '', 'mymakeart');
if ($conn->connect_error) {
    echo json_encode(["error" => "DB connect failed"]);
    exit;
}

$page = intval($_GET['page'] ?? 1);
$page = max($page, 1);
$perPage = 5;
$offset = ($page - 1) * $perPage;

// ? 查總筆數
$count_stmt = $conn->prepare("SELECT COUNT(*) FROM annual_leave_records WHERE employee_id = ?");
if (!$count_stmt) {
    echo json_encode(["error" => "Count prepare failed"]);
    exit;
}
$count_stmt->bind_param("i", $employee_id);
$count_stmt->execute();
$count_stmt->bind_result($totalRows);
$count_stmt->fetch();
$count_stmt->close();

$totalPages = max(ceil($totalRows / $perPage), 1);
$response["totalPages"] = $totalPages;
$response["currentPage"] = $page;

// ? 主查詢（將 status 當作 type 傳回，note 存在資料庫中）
$sql = "SELECT year, month, day, status AS type, days, hours, note
        FROM annual_leave_records
        WHERE employee_id = ?
        ORDER BY year DESC, month DESC, day DESC
        LIMIT " . intval($offset) . ", " . intval($perPage);

$stmt = $conn->prepare($sql);
if (!$stmt) {
    echo json_encode(["error" => "Main query prepare failed"]);
    exit;
}
$stmt->bind_param("i", $employee_id);
$stmt->execute();
$result = $stmt->get_result();

$records = [];
while ($row = $result->fetch_assoc()) {
    $records[] = $row;
}

$response["records"] = $records;
echo json_encode($response);
