<?php
/* [PHP-API-2] 讀單筆 */
require_once __DIR__ . '/../..//db_connect.php';
header('Content-Type: application/json; charset=utf-8');

$id = intval($_GET['id'] ?? 0);
if ($id<=0){ echo json_encode(['success'=>false,'message'=>'缺少 id']); exit; }

$stmt = $conn->prepare("
  SELECT ci.*, cc.name AS category_name, cc.color_hex AS category_color
  FROM calendar_items ci
  LEFT JOIN calendar_categories cc ON cc.id=ci.category_id
  WHERE ci.id=?");
$stmt->bind_param('i', $id);
$stmt->execute();
$row = $stmt->get_result()->fetch_assoc();
$stmt->close();

if(!$row){ echo json_encode(['success'=>false,'message'=>'資料不存在']); exit; }
echo json_encode(['success'=>true,'data'=>$row], JSON_UNESCAPED_UNICODE);
