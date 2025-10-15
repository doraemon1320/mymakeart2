<?php
/* [PHP-API-4] 刪除 */
require_once __DIR__ . '/../..//db_connect.php';
header('Content-Type: application/json; charset=utf-8');
$is_admin = isset($_SESSION['role']) && in_array($_SESSION['role'], ['admin','company_admin']);
if (!$is_admin){ echo json_encode(['success'=>false,'message'=>'權限不足']); exit; }

$id = intval($_POST['id'] ?? 0);
if ($id<=0){ echo json_encode(['success'=>false,'message'=>'缺少 id']); exit; }

$stmt = $conn->prepare("DELETE FROM calendar_items WHERE id=?");
$stmt->bind_param('i', $id);
$ok = $stmt->execute();
$stmt->close();

echo json_encode(['success'=>$ok], JSON_UNESCAPED_UNICODE);
