<?php
// 【PHP 功能 #1】登入權限檢查
session_start();
if (!isset($_SESSION['user']) || $_SESSION['user']['role'] !== 'admin') {
    header('Location: login.php');
    exit;
}

// 【PHP 功能 #2】資料庫連線設定
$conn = new mysqli('localhost', 'root', '', 'mymakeart');
if ($conn->connect_error) {
    die('資料庫連線失敗：' . $conn->connect_error);
}
$conn->set_charset('utf8mb4');

// 【PHP 功能 #3】讀取篩選參數與狀態設定
$items_per_page = 10;
$current_page = isset($_GET['page']) && is_numeric($_GET['page']) && (int)$_GET['page'] > 0 ? (int)$_GET['page'] : 1;
$search_keyword = trim($_GET['search'] ?? '');
$selected_department = $_GET['department'] ?? '';
$status_filters = [
    'preboarding' => '尚未入職',
    'current' => '在職中',
    'resigned' => '已離職',
];
$selected_status = $_GET['status'] ?? 'current';
if ($selected_status !== '' && !array_key_exists($selected_status, $status_filters)) {
    $selected_status = 'current';
}

// 【PHP 功能 #4】組合在職狀態判斷式
$status_key_expression = "(CASE\n    WHEN (e.resignation_date IS NOT NULL AND e.resignation_date <> '') THEN 'resigned'\n    WHEN (e.hire_date IS NULL OR e.hire_date = '') THEN 'preboarding'\n    WHEN (CURDATE() < e.hire_date) THEN 'preboarding'\n    ELSE 'current'\nEND)";
$status_text_expression = "(CASE\n    WHEN (e.resignation_date IS NOT NULL AND e.resignation_date <> '') THEN '已離職'\n    WHEN (e.hire_date IS NULL OR e.hire_date = '') THEN '尚未入職'\n    WHEN (CURDATE() < e.hire_date) THEN '尚未入職'\n    ELSE '在職中'\nEND)";

// 【PHP 功能 #5】準備篩選條件
$conditions = [];
$params = [];
$types = '';

if ($search_keyword !== '') {
    $conditions[] = '(e.employee_number LIKE ? OR e.name LIKE ?)';
    $like = '%' . $search_keyword . '%';
    $params[] = $like;
    $params[] = $like;
    $types .= 'ss';
}

if ($selected_department !== '') {
    if ($selected_department === '__none') {
        $conditions[] = '(e.department IS NULL OR e.department = "")';
    } else {
        $conditions[] = 'e.department = ?';
        $params[] = $selected_department;
        $types .= 's';
    }
}

if ($selected_status !== '') {
    $conditions[] = "{$status_key_expression} = ?";
    $params[] = $selected_status;
    $types .= 's';
}

$where_sql = $conditions ? 'WHERE ' . implode(' AND ', $conditions) : '';

// 【PHP 功能 #6】統計總筆數與頁碼
$count_sql = "SELECT COUNT(*) AS total FROM employees e {$where_sql}";
$stmt_count = $conn->prepare($count_sql);
if ($types !== '') {
    $stmt_count->bind_param($types, ...$params);
}
$stmt_count->execute();
$total_items = (int)($stmt_count->get_result()->fetch_assoc()['total'] ?? 0);
$total_pages = max(1, (int)ceil($total_items / $items_per_page));
if ($current_page > $total_pages) {
    $current_page = $total_pages;
}
$offset = ($current_page - 1) * $items_per_page;

// 【PHP 功能 #7】取得員工清單
$data_sql = "SELECT
    e.id AS 員工ID,
    e.employee_number AS 工號,
    e.name AS 姓名,
    CASE
        WHEN e.gender = 'male' THEN '男'
        WHEN e.gender = 'female' THEN '女'
        WHEN e.gender = 'other' THEN '其他'
        ELSE '未知'
    END AS 性別,
    IFNULL(s.name, '未指定') AS 班別,
    IFNULL(NULLIF(e.department, ''), '未指定') AS 部門,
    IFNULL(NULLIF(e.position, ''), '未指定') AS 職位,
    {$status_text_expression} AS 在職狀況,
    {$status_key_expression} AS status_key,
    e.hire_date AS 到職日,
    e.resignation_date AS 離職日
FROM employees e
LEFT JOIN shifts s ON e.shift_id = s.id
{$where_sql}
ORDER BY e.hire_date DESC, e.id DESC
LIMIT ? OFFSET ?";
$data_stmt = $conn->prepare($data_sql);
$data_params = $params;
$data_types = $types . 'ii';
$data_params[] = $items_per_page;
$data_params[] = $offset;
$data_stmt->bind_param($data_types, ...$data_params);
$data_stmt->execute();
$employees = $data_stmt->get_result();

// 【PHP 功能 #8】部門清單與總覽資訊
$departments = [];
$department_result = $conn->query("SELECT DISTINCT department FROM employees WHERE department IS NOT NULL AND department <> '' ORDER BY department");
while ($department_row = $department_result->fetch_assoc()) {
    $departments[] = $department_row['department'];
}
$has_department_none = (int)($conn->query("SELECT COUNT(*) AS qty FROM employees WHERE department IS NULL OR department = ''")->fetch_assoc()['qty'] ?? 0) > 0;

$overview_sql = "SELECT
    COUNT(*) AS total,
    SUM(CASE WHEN {$status_key_expression} = 'current' THEN 1 ELSE 0 END) AS current_total,
    SUM(CASE WHEN {$status_key_expression} = 'preboarding' THEN 1 ELSE 0 END) AS preboarding_total,
    SUM(CASE WHEN {$status_key_expression} = 'resigned' THEN 1 ELSE 0 END) AS resigned_total,
    SUM(CASE WHEN e.department IS NULL OR e.department = '' THEN 1 ELSE 0 END) AS without_department
FROM employees e";
$overview_query = $conn->query($overview_sql);
$overview_raw = $overview_query->fetch_assoc() ?: [];
$overview = [
    'total' => (int)($overview_raw['total'] ?? 0),
    'current_total' => (int)($overview_raw['current_total'] ?? 0),
    'preboarding_total' => (int)($overview_raw['preboarding_total'] ?? 0),
    'resigned_total' => (int)($overview_raw['resigned_total'] ?? 0),
    'without_department' => (int)($overview_raw['without_department'] ?? 0),
    'filtered_total' => $total_items,
];

$display_start = $total_items === 0 ? 0 : $offset + 1;
$display_end = $total_items === 0 ? 0 : min($offset + $items_per_page, $total_items);
$display_range_text = $total_items === 0 ? '無符合資料' : '顯示第 ' . $display_start . ' - ' . $display_end . ' 筆';
?>
<!DOCTYPE html>
<html lang="zh-Hant">
<head>
    <meta charset="UTF-8">
    <title>員工資料總覽</title>
    <link href="https://cdn.jsdelivr.net/npm/bootstrap@5.3.3/dist/css/bootstrap.min.css" rel="stylesheet">
    <script src="https://code.jquery.com/jquery-3.7.1.min.js"></script>
    <style>
        :root {
            --brand-gold: #ffcd00;
            --brand-rose: #e36386;
            --brand-blue: #345d9d;
            --brand-ink: #1f2a44;
        }

        body {
            background: linear-gradient(180deg, rgba(227, 99, 134, 0.08) 0%, rgba(52, 93, 157, 0.08) 35%, rgba(255, 205, 0, 0.05) 100%);
            font-family: 'Noto Sans TC', 'Microsoft JhengHei', sans-serif;
        }

        .hero-banner {
            background: linear-gradient(135deg, rgba(52, 93, 157, 0.92), rgba(227, 99, 134, 0.85));
      
			border-radius: 1rem;
            box-shadow: 0 25px 50px rgba(33, 45, 78, 0.28);
            color: #fff;
            position: relative;
            overflow: hidden;
			
			
   
        }

        .hero-banner::after {
            content: '';
            position: absolute;
            inset: 0;
            pointer-events: none;
        }

        .hero-title {
            font-weight: 800;
            letter-spacing: 1px;
			
        }

        .hero-subtitle {
            color: rgba(255, 255, 255, 0.88);
            max-width: 38rem;
        }

        .hero-action {
            background: var(--brand-gold);
            color: #2f2a01;
            font-weight: 700;
            border-radius: 30px;
            padding: 0.85rem 1.9rem;
            box-shadow: 0 16px 35px rgba(255, 205, 0, 0.45);
            border: none;
        }

        .hero-action:hover {
            background: #ffd838;
            color: #2f2a01;
        }

        .summary-card {
            border-radius: 22px;
            background: #ffffff;
            border: 1px solid rgba(52, 93, 157, 0.08);
            padding: 1.5rem;
            position: relative;
            overflow: hidden;
            box-shadow: 0 18px 35px rgba(17, 38, 77, 0.08);
        }

        .summary-card::before {
            content: '';
            position: absolute;
            inset: 0;
            opacity: 0.18;
        }

        .summary-card.accent-blue::before {
            background: linear-gradient(135deg, var(--brand-blue), #6f8fcd);
        }

        .summary-card.accent-rose::before {
            background: linear-gradient(135deg, var(--brand-rose), #f29ab4);
        }

        .summary-card.accent-gold::before {
            background: linear-gradient(135deg, var(--brand-gold), #ffe783);
        }

        .summary-card.accent-indigo::before {
            background: linear-gradient(135deg, #1f2a44, #4a5d8a);
        }

        .summary-card > * {
            position: relative;
            z-index: 1;
        }

        .summary-title {
            letter-spacing: 0.18rem;
            font-weight: 700;
            color: rgba(31, 42, 68, 0.68);
        }

        .summary-number {
            font-size: 2.35rem;
            font-weight: 900;
            color: var(--brand-ink);
        }

        .summary-subtext {
            color: rgba(31, 42, 68, 0.7);
            font-size: 0.95rem;
        }

        .filter-card {
            border-radius: 22px;
            overflow: hidden;
            box-shadow: 0 18px 36px rgba(17, 38, 77, 0.12);
            border: none;
        }

        .filter-card .card-header {
            background: linear-gradient(135deg, var(--brand-rose), var(--brand-blue));
            color: #fff;
            letter-spacing: 0.08rem;
        }

        .filter-card .form-control,
        .filter-card .form-select {
            border-radius: 14px;
            border: 1px solid rgba(52, 93, 157, 0.25);
            box-shadow: none;
        }

        .filter-card .btn-primary {
            background: var(--brand-blue);
            border-color: var(--brand-blue);
            border-radius: 14px;
            font-weight: 600;
        }

        .filter-card .btn-outline-secondary {
            border-radius: 14px;
        }

        .table-card {
            border-radius: 12px;
            box-shadow: 0 18px 42px rgba(31, 42, 68, 0.1);
            overflow: hidden;
            background: #ffffff;
        }

        .table thead.table-primary th {
            border-color: transparent;
            letter-spacing: 0.12rem;
            font-weight: 700;
        }

        .table tbody tr {
            transition: transform 0.2s ease, box-shadow 0.2s ease;
        }

        .table tbody tr:hover {
            transform: translateY(-2px);
            box-shadow: 0 12px 24px rgba(52, 93, 157, 0.12);
        }

        .status-badge {
            font-size: 0.85rem;
            padding: 0.42rem 0.85rem;
            border-radius: 30px;
            font-weight: 600;
            display: inline-flex;
            align-items: center;
            gap: 0.35rem;
        }

        .status-preboarding {
            background-color: rgba(255, 205, 0, 0.2);
            color: #9b6d00;
        }

        .status-current {
            background-color: rgba(72, 187, 120, 0.22);
            color: #2f855a;
        }

        .status-resigned {
            background-color: rgba(227, 99, 134, 0.18);
            color: #d6336c;
        }

        .status-unknown {
            background-color: rgba(108, 117, 125, 0.2);
            color: #495057;
        }

        mark {
            background: rgba(255, 205, 0, 0.55);
            padding: 0;
            border-radius: 4px;
        }

        .pagination .page-link {
            color: var(--brand-blue);
            font-weight: 600;
        }

        .pagination .page-item.active .page-link {
            background-color: var(--brand-blue);
            border-color: var(--brand-blue);
        }

        .empty-state {
            border: 2px dashed rgba(227, 99, 134, 0.35);
            border-radius: 18px;
            padding: 2.75rem;
            background: rgba(255, 255, 255, 0.9);
        }

        @media (max-width: 767.98px) {
            .hero-banner {
                border-radius: 24px;
            }

            .hero-action {
                width: 100%;
            }

            .summary-number {
                font-size: 2rem;
            }
        }
    </style>
</head>
<body>
<?php include 'admin_navbar.php'; ?>

<div class="container py-5">
    <div class="hero-banner p-4 p-md-5 mb-4">
        <div class="row align-items-center gy-3">
            <div class="col-lg-8">
                <h1 class="hero-title mb-2">員工資料總覽</h1>
                <p class="hero-subtitle mb-0">快速掌握全體員工的到職狀態、組織配置與帳號使用情形，並支援主管即時查詢與維護。</p>
            </div>
            <div class="col-lg-4 text-lg-end">
                <a href="add_employee.php" class="btn hero-action"><span class="me-2">➕</span>新增員工</a>
            </div>
        </div>
    </div>

    <div class="row g-3 mb-4">
        <div class="col-12 col-md-3">
            <div class="summary-card accent-blue h-100">
                <div class="summary-title text-uppercase small">員工總數</div>
                <div class="summary-number"><?= number_format($overview['total']) ?></div>
                <div class="summary-subtext">包含所有在職與離職紀錄</div>
            </div>
        </div>
        <div class="col-12 col-md-3">
            <div class="summary-card accent-rose h-100">
                <div class="summary-title text-uppercase small">篩選符合</div>
                <div class="summary-number"><span id="filterTotal" data-total="<?= $overview['filtered_total'] ?>"><?= number_format($overview['filtered_total']) ?></span></div>
                <div class="summary-subtext"><?= htmlspecialchars($display_range_text) ?></div>
            </div>
        </div>
        <div class="col-12 col-md-3">
            <div class="summary-card accent-gold h-100">
                <div class="summary-title text-uppercase small">在職中</div>
                <div class="summary-number"><?= number_format($overview['current_total']) ?></div>
                <div class="summary-subtext">尚未入職：<?= number_format($overview['preboarding_total']) ?> 人</div>
            </div>
        </div>
        <div class="col-12 col-md-3">
            <div class="summary-card accent-indigo h-100">
                <div class="summary-title text-uppercase small">已離職</div>
                <div class="summary-number"><?= number_format($overview['resigned_total']) ?></div>
                <div class="summary-subtext">未分配部門：<?= number_format($overview['without_department']) ?> 人</div>
            </div>
        </div>
    </div>

    <div class="filter-card card mb-4">
        <div class="card-header py-3">
            <h5 class="mb-0">查詢條件</h5>
        </div>
        <div class="card-body">
            <form id="filterForm" class="row g-3 align-items-end" method="get">
                <div class="col-12 col-md-4">
                    <label for="searchInput" class="form-label">姓名 / 工號關鍵字</label>
                    <input type="text" name="search" id="searchInput" class="form-control" placeholder="輸入姓名或工號" value="<?= htmlspecialchars($search_keyword) ?>">
                </div>
                <div class="col-12 col-md-4">
                    <label for="departmentSelect" class="form-label">部門篩選</label>
                    <select name="department" id="departmentSelect" class="form-select">
                        <option value="">全部部門</option>
                        <?php foreach ($departments as $department_option): ?>
                            <option value="<?= htmlspecialchars($department_option) ?>" <?= $selected_department === $department_option ? 'selected' : '' ?>><?= htmlspecialchars($department_option) ?></option>
                        <?php endforeach; ?>
                        <?php if ($has_department_none): ?>
                            <option value="__none" <?= $selected_department === '__none' ? 'selected' : '' ?>>未指定部門</option>
                        <?php endif; ?>
                    </select>
                </div>
                <div class="col-12 col-md-2">
                    <label for="statusSelect" class="form-label">在職狀態</label>
                    <select name="status" id="statusSelect" class="form-select">
                        <option value="">全部狀態</option>
                        <?php foreach ($status_filters as $status_value => $status_label): ?>
                            <option value="<?= $status_value ?>" <?= $selected_status === $status_value ? 'selected' : '' ?>><?= $status_label ?></option>
                        <?php endforeach; ?>
                    </select>
                </div>
                <div class="col-12 col-md-2 d-flex gap-2">
                    <button type="submit" class="btn btn-primary flex-grow-1">套用篩選</button>
                    <a href="employee_list.php" class="btn btn-outline-secondary">重置</a>
                </div>
            </form>
        </div>
    </div>

    <div class="table-card mb-4">
        <?php if ($employees->num_rows === 0): ?>
            <div class="empty-state text-center">
                <h4 class="fw-bold text-muted mb-2">目前沒有符合條件的員工資料</h4>
                <p class="text-secondary mb-0">調整搜尋條件或新增員工，即可快速建立完整的人事資料庫。</p>
            </div>
        <?php else: ?>
            <div class="table-responsive">
                <table class="table table-bordered table-hover table-striped align-middle mb-0" id="employeeTable">
                    <thead class="table-primary">
                        <tr>
                            <th scope="col">工號</th>
                            <th scope="col">姓名</th>
                            <th scope="col">性別</th>
                            <th scope="col">班別</th>
                            <th scope="col">部門</th>
                            <th scope="col">職位</th>
                            <th scope="col">在職狀況</th>
                            <th scope="col">到職日</th>
                            <th scope="col">離職日</th>
                            <th scope="col">操作</th>
                        </tr>
                    </thead>
                    <tbody>
                        <?php while ($employee = $employees->fetch_assoc()): ?>
                            <?php
                                $status_class_map = [
                                    'preboarding' => 'status-preboarding',
                                    'current' => 'status-current',
                                    'resigned' => 'status-resigned',
                                ];
                                $status_key = $employee['status_key'] ?? '';
                                $status_class = $status_class_map[$status_key] ?? 'status-unknown';
                                $hire_date_value = $employee['到職日'] ?? '';
                                $resign_date_value = $employee['離職日'] ?? '';
                                $hire_date_display = ($hire_date_value && $hire_date_value !== '0000-00-00') ? date('Y-m-d', strtotime($hire_date_value)) : '—';
                                $resign_date_display = ($resign_date_value && $resign_date_value !== '0000-00-00') ? date('Y-m-d', strtotime($resign_date_value)) : '—';
                                $status_hint = '';
                                if ($status_key === 'preboarding' && $hire_date_display !== '—') {
                                    $status_hint = '預計到職日：' . $hire_date_display;
                                } elseif ($status_key === 'resigned' && $resign_date_display !== '—') {
                                    $status_hint = '離職日：' . $resign_date_display;
                                }
                                $status_attribute = '';
                                if ($status_hint !== '') {
                                    $status_attribute = ' title="' . htmlspecialchars($status_hint, ENT_QUOTES) . '"';
                                }
                            ?>
                            <tr>
                                <td data-field="number" class="fw-semibold text-nowrap"><?= htmlspecialchars($employee['工號']) ?></td>
                                <td data-field="name">
                                    <a href="employee_detail.php?id=<?= htmlspecialchars($employee['員工ID']) ?>" class="link-dark text-decoration-none fw-semibold">
                                        <?= htmlspecialchars($employee['姓名']) ?>
                                    </a>
                                </td>
                                <td><?= htmlspecialchars($employee['性別']) ?></td>
                                <td><?= htmlspecialchars($employee['班別']) ?></td>
                                <td><?= htmlspecialchars($employee['部門']) ?></td>
                                <td><?= htmlspecialchars($employee['職位']) ?></td>
                                <td>
                                    <span class="status-badge <?= $status_class ?>"<?= $status_attribute ?>><?= htmlspecialchars($employee['在職狀況']) ?></span>
                                </td>
                                <td><?= htmlspecialchars($hire_date_display) ?></td>
                                <td><?= htmlspecialchars($resign_date_display) ?></td>
                                <td>
                                    <a href="employee_detail.php?id=<?= htmlspecialchars($employee['員工ID']) ?>" class="btn btn-outline-primary btn-sm">檢視與編輯</a>
                                </td>
                            </tr>
                        <?php endwhile; ?>
                    </tbody>
                </table>
            </div>
        <?php endif; ?>
    </div>

    <?php if ($total_pages > 1): ?>
    <nav aria-label="分頁導覽" class="mt-4">
        <ul class="pagination justify-content-center">
            <li class="page-item <?= $current_page <= 1 ? 'disabled' : '' ?>">
                <a class="page-link" href="?<?= http_build_query(array_merge($_GET, ['page' => max(1, $current_page - 1)])) ?>" aria-label="上一頁">
                    <span aria-hidden="true">&laquo;</span>
                </a>
            </li>
            <?php for ($i = 1; $i <= $total_pages; $i++): ?>
                <li class="page-item <?= $i === $current_page ? 'active' : '' ?>">
                    <a class="page-link" href="?<?= http_build_query(array_merge($_GET, ['page' => $i])) ?>"><?= $i ?></a>
                </li>
            <?php endfor; ?>
            <li class="page-item <?= $current_page >= $total_pages ? 'disabled' : '' ?>">
                <a class="page-link" href="?<?= http_build_query(array_merge($_GET, ['page' => min($total_pages, $current_page + 1)])) ?>" aria-label="下一頁">
                    <span aria-hidden="true">&raquo;</span>
                </a>
            </li>
        </ul>
    </nav>
    <?php endif; ?>
</div>

<script src="https://cdn.jsdelivr.net/npm/bootstrap@5.3.3/dist/js/bootstrap.bundle.min.js"></script>
<script>
    // 【JS 功能 #1】即時套用下拉篩選
    $(function () {
        $('#departmentSelect, #statusSelect').on('change', function () {
            $('#filterForm').submit();
        });
    
        // 【JS 功能 #2】關鍵字高亮顯示
        const keyword = $('#searchInput').val().trim();
        if (keyword !== '') {
            const safeKeyword = keyword.replace(/[.*+?^${}()|[\]\\]/g, '\\$&');
            const regex = new RegExp('(' + safeKeyword + ')', 'gi');
            $('#employeeTable tbody tr').each(function () {
                $(this).find('[data-field="number"], [data-field="name"]').each(function () {
                    const originalText = $(this).text();
                    $(this).html(originalText.replace(regex, '<mark>$1</mark>'));
                });
            });
        }

        // 【JS 功能 #3】更新當頁顯示筆數
        const visibleCount = $('#employeeTable tbody tr').length;
        if (visibleCount > 0) {
            $('#filterTotal').attr('data-current', visibleCount);
        }
    });
</script>
</body>
</html>
