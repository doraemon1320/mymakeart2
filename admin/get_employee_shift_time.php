<?php
require_once "db_connect.php";

header('Content-Type: application/json');
$emp_no = $_GET['employee_number'] ?? '';

$stmt = $conn->prepare("SELECT s.start_time, s.end_time
    FROM employees e
    JOIN shifts s ON e.shift_id = s.id
    WHERE e.employee_number = ?");
$stmt->bind_param("s", $emp_no);
$stmt->execute();
$result = $stmt->get_result()->fetch_assoc();

if ($result) {
    echo json_encode([
        'success' => true,
        'start_time' => $result['start_time'],
        'end_time' => $result['end_time']
    ]);
} else {
    echo json_encode(['success' => false]);
}
