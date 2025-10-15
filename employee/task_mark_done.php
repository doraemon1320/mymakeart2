<?php
require_once "../db_connect.php";
session_start();

if (!isset($_SESSION['user'])) {
    header("Location: ../login.php");
    exit;
}

$employee_id = $_SESSION['user']['id'];
$task_id = $_POST['task_id'] ?? 0;

if ($task_id > 0) {
    $stmt = $conn->prepare("INSERT INTO task_completions (task_id, employee_id, completed_at) VALUES (?, ?, NOW())");
    $stmt->bind_param("ii", $task_id, $employee_id);
    $stmt->execute();
}

header("Location: tasks_list.php");
exit;
