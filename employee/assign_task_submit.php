<?php
require_once "../db_connect.php";
session_start();

if (!isset($_SESSION['user']) || $_SESSION['user']['role'] !== 'admin') {
    header("Location: ../login.php");
    exit;
}

$title = $_POST['title'];
$description = $_POST['description'] ?? '';
$assigned_ids = $_POST['assigned_to'] ?? [];
$type = $_POST['type'];
$repeat_cycle = $_POST['repeat_cycle'] ?? null;
$start_date = $_POST['start_date'];
$due_date = $_POST['due_date'];
$assigner_id = $_SESSION['user']['id'];

if (!$title || empty($assigned_ids)) {
    die("資料不足");
}

foreach ($assigned_ids as $emp_id) {
    $stmt = $conn->prepare("
        INSERT INTO tasks (title, description, assigned_by, assigned_to, type, repeat_cycle, start_date, due_date)
        VALUES (?, ?, ?, ?, ?, ?, ?, ?)
    ");
    $stmt->bind_param("ssiissss", $title, $description, $assigner_id, $emp_id, $type, $repeat_cycle, $start_date, $due_date);
    $stmt->execute();
}

header("Location: tasks_list.php");
exit;
