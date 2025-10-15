<?php
/*************************************************
 * MyMakeArt API（員工登入版）
 * 路徑：/api/index.php
 * 需求：PHP ≥ 7.4、啟用 openssl、PDO MySQL
 *************************************************/

header('Content-Type: application/json; charset=utf-8');
header('Access-Control-Allow-Origin: *'); // 正式環境請收斂
header('Access-Control-Allow-Headers: Content-Type,Authorization');
header('Access-Control-Allow-Methods: GET,POST,PATCH,DELETE,OPTIONS');
if ($_SERVER['REQUEST_METHOD'] === 'OPTIONS') { http_response_code(204); exit; }

date_default_timezone_set('Asia/Taipei');

/* ===== 設定 ===== */
const JWT_SECRET = 'change-this-to-a-long-random-secret';
const JWT_ISS    = 'mymakeart-api';
const JWT_TTL    = 3600;           // Access Token 秒
const REFRESH_TTL_DAYS = 14;       // Refresh Token 天數

/* ===== DB 連線 ===== */
function db(): PDO {
    static $pdo = null;
    if ($pdo) return $pdo;
    $host = '192.168.2.54';
    $db   = 'mymakeart';
    $user = 'root';
    $pass = '';
    $dsn  = "mysql:host=$host;dbname=$db;charset=utf8mb4";
    $pdo = new PDO($dsn, $user, $pass, [
        PDO::ATTR_ERRMODE => PDO::ERRMODE_EXCEPTION,
        PDO::ATTR_DEFAULT_FETCH_MODE => PDO::FETCH_ASSOC,
    ]);
    return $pdo;
}

/* ===== 共用工具 ===== */
function json($data, int $code=200){ http_response_code($code); echo json_encode($data, JSON_UNESCAPED_UNICODE); exit; }
function b64url($s){ return rtrim(strtr(base64_encode($s), '+/', '-_'), '='); }
function b64url_decode($s){ return base64_decode(strtr($s, '-_', '+/')); }

/* ===== JWT ===== */
function jwt_sign(array $payload): string {
    $header = ['alg'=>'HS256','typ'=>'JWT'];
    $segments = [ b64url(json_encode($header)), b64url(json_encode($payload)) ];
    $signingInput = implode('.', $segments);
    $signature = hash_hmac('sha256', $signingInput, JWT_SECRET, true);
    $segments[] = b64url($signature);
    return implode('.', $segments);
}
function jwt_verify(string $jwt){
    $parts = explode('.', $jwt);
    if (count($parts) !== 3) return false;
    [$h64,$p64,$s64] = $parts;
    $sig = b64url_decode($s64);
    $check = hash_hmac('sha256', "$h64.$p64", JWT_SECRET, true);
    if (!hash_equals($check, $sig)) return false;
    $payload = json_decode(b64url_decode($p64), true);
    if (!$payload) return false;
    if (($payload['exp'] ?? 0) < time()) return false;
    return $payload;
}

/* ===== Auth 輔助：以 employee 為主體 ===== */
function issue_tokens(int $employee_id): array {
    $now = time();
    $access_payload = [
        'iss' => JWT_ISS,
        'sub' => $employee_id, // sub = employee id
        'iat' => $now,
        'exp' => $now + JWT_TTL,
    ];
    $access = jwt_sign($access_payload);

    $refresh_token = bin2hex(random_bytes(32));
    $expires_at = (new DateTime("+".REFRESH_TTL_DAYS." days"))->format('Y-m-d H:i:s');
    $stmt = db()->prepare("INSERT INTO employee_refresh_tokens (employee_id, token, expires_at) VALUES (?,?,?)");
    $stmt->execute([$employee_id, $refresh_token, $expires_at]);

    return ['accessToken'=>$access, 'refreshToken'=>$refresh_token, 'expiresIn'=>JWT_TTL];
}
function auth_employee_id_or_401(): int {
    $hdr = $_SERVER['HTTP_AUTHORIZATION'] ?? '';
    if (!preg_match('/Bearer\s+(.+)/i', $hdr, $m)) json(['error'=>'Unauthorized'],401);
    $payload = jwt_verify(trim($m[1]));
    if (!$payload) json(['error'=>'Unauthorized'],401);
    return (int)$payload['sub'];
}

/* ===== Router ===== */
$method = $_SERVER['REQUEST_METHOD'];
$uri    = parse_url($_SERVER['REQUEST_URI'], PHP_URL_PATH);

/* ---- 登入（/api/auth/login）---- */
if ($method==='POST' && $uri==='/api/auth/login') {
    $body = json_decode(file_get_contents('php://input'), true) ?: [];
    $username = trim($body['username'] ?? '');
    $password = trim($body['password'] ?? '');
    if ($username==='' || $password==='') json(['error'=>'帳密必填'],422);

    // 你的 schema：登入資訊在 employees 表（含 username/password/role）
    $stmt = db()->prepare("SELECT id, username, password, role, employee_number, name, department, email, phone
                           FROM employees WHERE username=? LIMIT 1");
    $stmt->execute([$username]);
    $e = $stmt->fetch();
    if (!$e) json(['error'=>'帳號或密碼錯誤'],401);

    // 兼容舊站若為明碼（建議逐步改用 password_hash）
    $ok = password_verify($password, $e['password']) || ($password === $e['password']);
    if (!$ok) json(['error'=>'帳號或密碼錯誤'],401);

    $tokens = issue_tokens((int)$e['id']);
    json([
        'token'=>$tokens['accessToken'],
        'refreshToken'=>$tokens['refreshToken'],
        'expiresIn'=>$tokens['expiresIn'],
        'user'=>[
          'id'=>(int)$e['id'],
          'username'=>$e['username'],
          'role'=>$e['role'],
          'employee'=>[
            'id'=>(int)$e['id'],
            'employee_number'=>$e['employee_number'],
            'name'=>$e['name'],
            'department'=>$e['department'],
            'email'=>$e['email'],
            'phone'=>$e['phone'],
          ]
        ]
    ]);
}

/* ---- Refresh（/api/auth/refresh）---- */
if ($method==='POST' && $uri==='/api/auth/refresh') {
    $body = json_decode(file_get_contents('php://input'), true) ?: [];
    $refresh = trim($body['refreshToken'] ?? '');
    if ($refresh==='') json(['error'=>'refreshToken 必填'],422);

    $stmt = db()->prepare("SELECT id, employee_id, revoked, expires_at FROM employee_refresh_tokens WHERE token=? LIMIT 1");
    $stmt->execute([$refresh]);
    $row = $stmt->fetch();
    if (!$row || (int)$row['revoked']===1 || strtotime($row['expires_at'])<time()) {
        json(['error'=>'Refresh Token 無效'],401);
    }
    $tokens = issue_tokens((int)$row['employee_id']);
    json(['token'=>$tokens['accessToken'],'refreshToken'=>$tokens['refreshToken'],'expiresIn'=>$tokens['expiresIn']]);
}

/* ---- 目前登入者（/api/me）---- */
if ($method==='GET' && $uri==='/api/me') {
    $employee_id = auth_employee_id_or_401();
    $e = db()->prepare("SELECT id, employee_number, username, name, department, email, phone FROM employees WHERE id=?");
    $e->execute([$employee_id]);
    $emp = $e->fetch();
    if (!$emp) json(['error'=>'員工不存在'],404);
    json(['id'=>(int)$emp['id'],'username'=>$emp['username'],'employee'=>$emp]);
}

/* ---- 員工首頁儀表板（/api/dashboard/employee）---- */
if ($method==='GET' && $uri==='/api/dashboard/employee') {
    $employee_id = auth_employee_id_or_401();

    $todayStart = (new DateTime('today'))->format('Y-m-d 00:00:00');
    $todayEnd   = (new DateTime('tomorrow'))->format('Y-m-d 00:00:00');

    // 今日行程：員工個人或全公司事件
    $cal = db()->prepare("
      SELECT id,title,type,start_datetime,end_datetime,all_day,color_hex,location
      FROM calendar_items
      WHERE (employee_id = :eid OR employee_id IS NULL)
        AND start_datetime < :tEnd AND end_datetime >= :tStart
      ORDER BY start_datetime ASC
      LIMIT 20
    ");
    $cal->execute([':eid'=>$employee_id, ':tEnd'=>$todayEnd, ':tStart'=>$todayStart]);
    $todayEvents = $cal->fetchAll();

    // 近 5 筆請假/加班
    $req = db()->prepare("
      SELECT id, type, subtype, start_date, end_date, status
      FROM requests
      WHERE employee_id = ?
      ORDER BY id DESC
      LIMIT 5
    ");
    $req->execute([$employee_id]);
    $recentRequests = $req->fetchAll();

    json(['todayEvents'=>$todayEvents,'recentRequests'=>$recentRequests,'tasks'=>[]]);
}

/* ---- 404 ---- */
json(['error'=>'Not Found','method'=>$method,'path'=>$uri],404);
