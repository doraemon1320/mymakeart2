<?php
/* [PHP-API-6] 新增或更新分類 */
require_once __DIR__ . '/../..//db_connect.php';
header('Content-Type: application/json; charset=utf-8');

$is_admin = isset($_SESSION['role']) && in_array($_SESSION['role'], ['admin','company_admin']);
if (!$is_admin){ echo json_encode(['success'=>false,'message'=>'權限不足']); exit; }

$id        = intval($_POST['id'] ?? 0);
$name      = trim($_POST['name'] ?? '');
$color_hex = trim($_POST['color_hex'] ?? '');
$is_active = isset($_POST['is_active']) ? (intval($_POST['is_active'])?1:0) : 1;

if ($name==='' || !preg_match('/^#[0-9A-Fa-f]{6}$/', $color_hex)){
  echo json_encode(['success'=>false,'message'=>'名稱或顏色格式錯誤']); exit;
}

if ($id>0){
  $stmt = $conn->prepare("UPDATE calendar_categories SET name=?, color_hex=?, is_active=? WHERE id=?");
  $stmt->bind_param('ssii', $name, $color_hex, $is_active, $id);
  $ok = $stmt->execute();
  $stmt->close();
} else {
  $stmt = $conn->prepare("INSERT INTO calendar_categories (name,color_hex,is_active,created_at) VALUES (?,?,?,NOW())");
  $stmt->bind_param('ssi', $name, $color_hex, $is_active);
  $ok = $stmt->execute();
  $stmt->close();
}
echo json_encode(['success'=>$ok], JSON_UNESCAPED_UNICODE);
