<?php
/* [PHP-API-3] 新增/更新 */
require_once __DIR__ . '/../..//db_connect.php';
header('Content-Type: application/json; charset=utf-8');
date_default_timezone_set('Asia/Taipei');

/* [PHP-API-3-1] 權限（僅管理者） */
$is_admin = isset($_SESSION['role']) && in_array($_SESSION['role'], ['admin','company_admin']);
if (!$is_admin){ echo json_encode(['success'=>false,'message'=>'權限不足']); exit; }

/* [PHP-API-3-2] 取得表單 */
$id          = intval($_POST['id'] ?? 0);
$title       = trim($_POST['title'] ?? '');
$type        = 'custom'; // 本階段固定
$category_id = isset($_POST['category_id']) && $_POST['category_id']!=='' ? intval($_POST['category_id']) : null;
$color_hex   = trim($_POST['color_hex'] ?? '');
$visibility  = ($_POST['visibility'] ?? 'internal')==='public' ? 'public':'internal';
$location    = trim($_POST['location'] ?? '');
$note        = trim($_POST['note'] ?? '');
$all_day     = isset($_POST['all_day']) ? 1:0;

$sd = $_POST['start_date'] ?? '';
$st = $_POST['start_time'] ?? '00:00';
$ed = $_POST['end_date'] ?? '';
$et = $_POST['end_time'] ?? '23:59';

if ($title==='' || $sd==='' || $ed===''){
  echo json_encode(['success'=>false,'message'=>'缺少必填欄位']); exit;
}

if ($all_day){ $st='00:00'; $et='23:59'; }
$start_datetime = $sd . ' ' . $st . ':00';
$end_datetime   = $ed . ' ' . $et . ':00';
if (strtotime($start_datetime) > strtotime($end_datetime)){
  echo json_encode(['success'=>false,'message'=>'開始時間不可晚於結束時間']); exit;
}
if ($color_hex!==''){
  if (!preg_match('/^#[0-9A-Fa-f]{6}$/', $color_hex)){
    echo json_encode(['success'=>false,'message'=>'顏色格式需為 #RRGGBB']); exit;
  }
}

/* [PHP-API-3-3] 寫入 */
try{
  if ($id>0){
    $stmt = $conn->prepare("
      UPDATE calendar_items
         SET title=?, type=?, start_datetime=?, end_datetime=?, all_day=?,
             category_id=?, color_hex=?, location=?, note=?, visibility=?,
             updated_at=NOW()
       WHERE id=?");
    $stmt->bind_param('ssssiiisssi',
        $title, $type, $start_datetime, $end_datetime, $all_day,
        $category_id, $color_hex, $location, $note, $visibility,
        $id);
    if (!$stmt->execute()) throw new Exception('更新失敗：'.$stmt->error);
    $stmt->close();
  }else{
    $stmt = $conn->prepare("
      INSERT INTO calendar_items
        (title,type,start_datetime,end_datetime,all_day,category_id,color_hex,location,note,visibility,created_by,created_at,updated_at)
      VALUES (?,?,?,?,?,?,?,?,?,?,?,NOW(),NOW())");
    $created_by = $_SESSION['user_id'] ?? null;
    $stmt->bind_param('ssssiiisssi',
        $title, $type, $start_datetime, $end_datetime, $all_day,
        $category_id, $color_hex, $location, $note, $visibility, $created_by);
    if (!$stmt->execute()) throw new Exception('新增失敗：'.$stmt->error);
    $id = $stmt->insert_id;
    $stmt->close();
  }
  echo json_encode(['success'=>true,'id'=>$id], JSON_UNESCAPED_UNICODE);
}catch(Throwable $e){
  echo json_encode(['success'=>false,'message'=>$e->getMessage()], JSON_UNESCAPED_UNICODE);
}
