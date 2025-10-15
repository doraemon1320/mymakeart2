<?php
/* [PHP-API-5] 讀分類列表（可帶 active=1） */
require_once __DIR__ . '/../..//db_connect.php';
header('Content-Type: application/json; charset=utf-8');

$onlyActive = isset($_GET['active']) ? intval($_GET['active']) : null;

$sql = "SELECT c.id, c.name, c.color_hex, c.is_active,
        (SELECT COUNT(*) FROM calendar_items i WHERE i.category_id=c.id) AS use_count
        FROM calendar_categories c";
if ($onlyActive===1) $sql .= " WHERE c.is_active=1";
$sql .= " ORDER BY c.name ASC";

$rs = $conn->query($sql);
$data = [];
while($row=$rs->fetch_assoc()) $data[]=$row;

echo json_encode(['success'=>true,'data'=>$data], JSON_UNESCAPED_UNICODE);
