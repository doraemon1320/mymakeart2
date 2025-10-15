<?php
/* [PHP-API-1] 列表：依條件回傳事件 */
require_once __DIR__ . '/../..//db_connect.php';
header('Content-Type: application/json; charset=utf-8');
date_default_timezone_set('Asia/Taipei');

try{
  $start = $_GET['start'] ?? date('Y-m-01');
  $end   = $_GET['end']   ?? date('Y-m-t');
  $types = $_GET['types'] ?? 'custom';
  $q     = trim($_GET['q'] ?? '');
  $category_ids = array_filter(array_map('intval', explode(',', $_GET['category_ids'] ?? '')));

  // 僅支援 custom；但保留多型參數
  $typeList = array_intersect(explode(',', $types), ['custom','holiday','leave','construction']);
  if (empty($typeList)) $typeList = ['custom'];

  $sql = "
    SELECT ci.id, ci.type, ci.title, ci.start_datetime, ci.end_datetime, ci.all_day,
           ci.color_hex, ci.location, ci.note, ci.visibility,
           cc.name AS category_name, cc.color_hex AS category_color
    FROM calendar_items ci
    LEFT JOIN calendar_categories cc ON cc.id=ci.category_id
    WHERE ci.start_datetime <= ? AND ci.end_datetime >= ?
      AND ci.type IN (" . implode(',', array_fill(0, count($typeList), '?')) . ")
  ";

  $params = [$end, $start];
  $typestr = 'ss';
  foreach($typeList as $t){ $params[]=$t; $typestr.='s'; }

  if (!empty($category_ids)) {
    $sql .= " AND ci.category_id IN (" . implode(',', array_fill(0, count($category_ids), '?')) . ") ";
    foreach($category_ids as $cid){ $params[]=$cid; $typestr.='i'; }
  }
  if ($q!=='') {
    $sql .= " AND (ci.title LIKE ? OR ci.location LIKE ?) ";
    $like = "%{$q}%";
    $params[]=$like; $typestr.='s';
    $params[]=$like; $typestr.='s';
  }
  $sql .= " ORDER BY ci.start_datetime ASC, ci.id ASC";

  $stmt = $conn->prepare($sql);
  $stmt->bind_param($typestr, ...$params);
  $stmt->execute();
  $rs = $stmt->get_result();
  $data = [];
  while($row=$rs->fetch_assoc()){ $data[]=$row; }
  $stmt->close();

  echo json_encode(['success'=>true,'data'=>$data], JSON_UNESCAPED_UNICODE);
}catch(Throwable $e){
  echo json_encode(['success'=>false,'message'=>$e->getMessage()], JSON_UNESCAPED_UNICODE);
}
