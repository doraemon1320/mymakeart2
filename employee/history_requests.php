<?php
session_start();

// 【PHP-1】登入驗證與權限檢查
if (!isset($_SESSION['user'])) {
    header('Location: ../login.php');
    exit;
}

// 【PHP-2】建立資料庫連線
$conn = new mysqli('localhost', 'root', '', 'mymakeart');
if ($conn->connect_error) {
    die("資料庫連接失敗：" . $conn->connect_error);
}

$employee_number = $_SESSION['user']['employee_number'] ?? '';

// 【PHP-3】設定分頁參數
$perPage = 20;
$page = isset($_GET['page']) ? max(1, intval($_GET['page'])) : 1;
$offset = ($page - 1) * $perPage;

// 【PHP-4】計算總筆數與總頁數
$stmt = $conn->prepare("SELECT COUNT(*) FROM requests WHERE employee_number = ?");
$stmt->bind_param('s', $employee_number);
$stmt->execute();
$stmt->bind_result($totalRows);
$stmt->fetch();
$stmt->close();
$totalPages = max(1, ceil($totalRows / $perPage));

// 【PHP-5】取得申請紀錄資料
$stmt = $conn->prepare("
    SELECT type, subtype, reason, status, start_date, end_date, created_at
    FROM requests
    WHERE employee_number = ?
    ORDER BY created_at DESC
    LIMIT ?, ?
");
$stmt->bind_param('sii', $employee_number, $offset, $perPage);
$stmt->execute();
$result = $stmt->get_result();
$requests = $result->fetch_all(MYSQLI_ASSOC);
$stmt->close();

// 【PHP-6】彙整狀態統計資訊
$statusSummary = [
    'approved' => 0,
    'pending' => 0,
    'rejected' => 0,
    'other' => 0,
];

foreach ($requests as $row) {
    $statusKey = strtolower($row['status']);
    if (!array_key_exists($statusKey, $statusSummary)) {
        $statusKey = 'other';
    }
    $statusSummary[$statusKey]++;
}

$statusLabels = [
    'approved' => ['label' => '已通過', 'badge' => 'badge-approved', 'icon' => 'bi-check-circle-fill'],
    'pending' => ['label' => '審查中', 'badge' => 'badge-pending', 'icon' => 'bi-hourglass-split'],
    'rejected' => ['label' => '未通過', 'badge' => 'badge-rejected', 'icon' => 'bi-x-circle-fill'],
    'other' => ['label' => '其他', 'badge' => 'badge-other', 'icon' => 'bi-question-circle-fill'],
];

// 【PHP-7】釋放資料庫資源
$conn->close();
?>
<!DOCTYPE html>
<html lang="zh-TW">
<head>
    <meta charset="UTF-8">
    <title>請假/加班申請紀錄</title>
    <meta name="viewport" content="width=device-width, initial-scale=1.0">
    <!-- 核心樣式 -->
    <link href="https://cdn.jsdelivr.net/npm/bootstrap@5.3.3/dist/css/bootstrap.min.css" rel="stylesheet">
    <link href="https://cdn.jsdelivr.net/npm/bootstrap-icons@1.11.3/font/bootstrap-icons.css" rel="stylesheet">
    <link rel="stylesheet" href="employee_navbar.css">
    <style>
        body {
            background-color: #f6f7fb;
        }

        .page-hero {
            background: linear-gradient(135deg, rgba(52, 93, 157, 0.95), rgba(227, 99, 134, 0.92));
            color: #fff;
            border-radius: 18px;
            padding: 28px;
            box-shadow: 0 15px 35px rgba(52, 93, 157, 0.25);
        }

        .page-hero h1 {
            font-size: 26px;
            font-weight: 700;
        }

        .page-hero p {
            margin: 0;
            font-size: 15px;
            opacity: 0.92;
        }

        .summary-card {
            border-radius: 16px;
            padding: 16px;
            background-color: #ffffff;
            box-shadow: 0 8px 20px rgba(0, 0, 0, 0.07);
            border: 1px solid rgba(52, 93, 157, 0.12);
        }

        .summary-card .icon-box {
            width: 48px;
            height: 48px;
            border-radius: 50%;
            display: flex;
            align-items: center;
            justify-content: center;
            background-color: rgba(255, 205, 0, 0.18);
            color: #345d9d;
            font-size: 22px;
        }

        .summary-card span {
            font-size: 13px;
            color: #6c757d;
        }

        .summary-card strong {
            font-size: 24px;
            color: #345d9d;
        }

        .card-layout {
            border-radius: 18px;
            box-shadow: 0 12px 28px rgba(0, 0, 0, 0.08);
            border: none;
        }

        .card-layout .card-header {
            background-color: #ffffff;
            border-bottom: 1px solid rgba(52, 93, 157, 0.1);
        }

        .filter-label {
            font-size: 13px;
            color: #345d9d;
        }

        table thead th {
            background-color: #ffec8a !important;
            color: #345d9d;
            font-weight: 600;
            border-color: rgba(52, 93, 157, 0.25) !important;
        }

        table tbody td {
            vertical-align: middle;
            border-color: rgba(52, 93, 157, 0.12) !important;
        }

        .badge-approved {
            background-color: rgba(52, 93, 157, 0.12);
            color: #345d9d;
        }

        .badge-pending {
            background-color: rgba(255, 205, 0, 0.22);
            color: #b8860b;
        }

        .badge-rejected {
            background-color: rgba(227, 99, 134, 0.15);
            color: #c03d63;
        }

        .badge-other {
            background-color: rgba(108, 117, 125, 0.15);
            color: #6c757d;
        }

        .empty-state {
            border-radius: 18px;
            background: linear-gradient(135deg, rgba(255, 205, 0, 0.18), rgba(227, 99, 134, 0.15));
            border: 1px dashed rgba(52, 93, 157, 0.35);
            padding: 36px;
            text-align: center;
            color: #345d9d;
        }

        .pagination .page-link {
            color: #345d9d;
        }

        .pagination .page-item.active .page-link {
            background-color: #345d9d;
            border-color: #345d9d;
        }

        .pagination .page-link:hover {
            color: #e36386;
        }
    </style>
</head>
<body>
<?php include 'employee_navbar.php'; ?>

<div class="container py-4">
    <div class="row g-4 align-items-stretch mb-4">
        <div class="col-lg-8">
            <div class="page-hero h-100">
                <div class="d-flex align-items-center mb-3">
                    <div class="me-3 display-6"><i class="bi bi-journal-text"></i></div>
                    <div>
                        <h1 class="mb-1">請假/加班申請紀錄</h1>
                        <p>快速掌握個人歷史申請狀態，方便與主管溝通與後續追蹤。</p>
                    </div>
                </div>
                <div class="d-flex flex-wrap gap-3">
                    <div class="d-flex align-items-center gap-2">
                        <span class="badge bg-light text-primary">每頁 <?= $perPage ?> 筆</span>
                        <span class="badge bg-light text-primary">共 <?= $totalRows ?> 筆資料</span>
                    </div>
                </div>
            </div>
        </div>
        <div class="col-lg-4">
            <div class="summary-card h-100 d-flex flex-column justify-content-center">
                <div class="d-flex align-items-center gap-3 mb-3">
                    <div class="icon-box">
                        <i class="bi bi-clipboard-data"></i>
                    </div>
                    <div>
                        <span>目前頁面資料統計</span>
                        <div class="fw-bold text-dark">狀態分佈概覽</div>
                    </div>
                </div>
                <div class="row g-3">
                    <?php foreach ($statusSummary as $key => $count): ?>
                        <div class="col-6">
                            <div class="small text-muted mb-1"><i class="bi <?= $statusLabels[$key]['icon'] ?> me-1"></i><?= $statusLabels[$key]['label'] ?></div>
                            <strong><?= $count ?></strong>
                        </div>
                    <?php endforeach; ?>
                </div>
            </div>
        </div>
    </div>

    <?php if (empty($requests)): ?>
        <div class="empty-state">
            <div class="display-6 mb-3">📄</div>
            <h4 class="fw-bold mb-2">尚未產生申請紀錄</h4>
            <p class="mb-0">請前往「申請請假/加班」頁面提交第一筆申請，系統將自動彙整所有紀錄供您查詢。</p>
        </div>
    <?php else: ?>
        <div class="card card-layout">
            <div class="card-header py-3">
                <div class="row g-3 align-items-center">
                    <div class="col-md-6">
                        <h5 class="mb-0 text-primary"><i class="bi bi-list-check me-2"></i>申請紀錄列表</h5>
                    </div>
                    <div class="col-md-6 text-md-end">
                        <div class="d-inline-flex align-items-center gap-2">
                            <span class="filter-label">依狀態篩選：</span>
                            <select id="statusFilter" class="form-select form-select-sm w-auto">
                                <option value="">全部</option>
                                <?php foreach ($statusLabels as $key => $info): ?>
                                    <option value="<?= $key ?>"><?= $info['label'] ?></option>
                                <?php endforeach; ?>
                            </select>
                        </div>
                    </div>
                </div>
            </div>
            <div class="table-responsive">
                <table class="table table-bordered table-hover align-middle mb-0">
                    <thead class="table-primary">
                        <tr>
                            <th scope="col">申請類型</th>
                            <th scope="col">假別/加班別</th>
                            <th scope="col" style="min-width: 180px;">申請理由</th>
                            <th scope="col">審核狀態</th>
                            <th scope="col">起始時間</th>
                            <th scope="col">結束時間</th>
                            <th scope="col">申請時間</th>
                        </tr>
                    </thead>
                    <tbody id="requestTableBody">
                        <?php foreach ($requests as $r): ?>
                            <?php $statusKey = strtolower($r['status']); ?>
                            <?php if (!array_key_exists($statusKey, $statusLabels)) { $statusKey = 'other'; } ?>
                            <tr data-status="<?= $statusKey ?>">
                                <td class="text-nowrap"><i class="bi bi-pin-angle text-primary me-2"></i><?= htmlspecialchars($r['type']) ?></td>
                                <td><?= $r['subtype'] === '特休假' ? '特休假' : htmlspecialchars($r['subtype'] ?? '—') ?></td>
                                <td><?= nl2br(htmlspecialchars($r['reason'] ?? '')) ?></td>
                                <td>
                                    <span class="badge rounded-pill <?= $statusLabels[$statusKey]['badge'] ?>">
                                        <i class="bi <?= $statusLabels[$statusKey]['icon'] ?> me-1"></i><?= $statusLabels[$statusKey]['label'] ?>
                                    </span>
                                </td>
                                <td><?= htmlspecialchars($r['start_date']) ?></td>
                                <td><?= htmlspecialchars($r['end_date']) ?></td>
                                <td><?= htmlspecialchars($r['created_at']) ?></td>
                            </tr>
                        <?php endforeach; ?>
                    </tbody>
                </table>
            </div>
            <?php if ($totalPages > 1): ?>
                <div class="card-footer bg-white py-3">
                    <nav>
                        <ul class="pagination pagination-sm justify-content-center mb-0">
                            <li class="page-item <?= $page === 1 ? 'disabled' : '' ?>">
                                <a class="page-link" href="?page=<?= max(1, $page - 1) ?>" aria-label="上一頁">
                                    <span aria-hidden="true">&laquo;</span>
                                </a>
                            </li>
                            <?php for ($i = 1; $i <= $totalPages; $i++): ?>
                                <li class="page-item <?= ($i === $page) ? 'active' : '' ?>">
                                    <a class="page-link" href="?page=<?= $i ?>"><?= $i ?></a>
                                </li>
                            <?php endfor; ?>
                            <li class="page-item <?= $page === $totalPages ? 'disabled' : '' ?>">
                                <a class="page-link" href="?page=<?= min($totalPages, $page + 1) ?>" aria-label="下一頁">
                                    <span aria-hidden="true">&raquo;</span>
                                </a>
                            </li>
                        </ul>
                    </nav>
                </div>
            <?php endif; ?>
        </div>
    <?php endif; ?>
</div>

<script src="https://code.jquery.com/jquery-3.7.1.min.js"></script>
<script src="https://cdn.jsdelivr.net/npm/bootstrap@5.3.3/dist/js/bootstrap.bundle.min.js"></script>
<script>
    // 【JS-1】依狀態即時篩選列表
    $(function () {
        $('#statusFilter').on('change', function () {
            const filterValue = $(this).val();
            if (!filterValue) {
                $('#requestTableBody tr').show();
                return;
            }
            $('#requestTableBody tr').each(function () {
                const rowStatus = $(this).data('status');
                $(this).toggle(rowStatus === filterValue);
            });
        });
    });
</script>
</body>
</html>