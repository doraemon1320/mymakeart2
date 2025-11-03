<?php
// PHP 功能 1：啟動工作階段並確認登入狀態
session_start();
if (!isset($_SESSION['user'])) {
    header('Location: ../login.php');
    exit;
}

// PHP 功能 2：載入資料庫連線
require_once '../db_connect.php';

$employeeNumber = $_SESSION['user']['employee_number'] ?? '';
// PHP 功能 3：撈取通知資料
$notifications = [];
if ($employeeNumber !== '') {
    $stmt = $conn->prepare(
        'SELECT id, message, created_at, is_read FROM notifications WHERE employee_number = ? ORDER BY created_at DESC'
    );
    $stmt->bind_param('s', $employeeNumber);
    $stmt->execute();
    $result = $stmt->get_result();
    $notifications = $result->fetch_all(MYSQLI_ASSOC);
    $stmt->close();

    // PHP 功能 4：更新已讀狀態
    if (!empty($notifications)) {
        $updateStmt = $conn->prepare('UPDATE notifications SET is_read = 1 WHERE employee_number = ? AND is_read = 0');
        $updateStmt->bind_param('s', $employeeNumber);
        $updateStmt->execute();
        $updateStmt->close();
    }
}

// PHP 功能 5：釋放資料庫連線
if (isset($conn) && $conn instanceof mysqli) {
    $conn->close();
}
?>
<!DOCTYPE html>
<html lang="zh-Hant">
<head>
    <meta charset="UTF-8">
    <meta name="viewport" content="width=device-width, initial-scale=1.0">
    <title>通知中心</title>
    <link href="https://cdn.jsdelivr.net/npm/bootstrap@5.3.3/dist/css/bootstrap.min.css" rel="stylesheet">
    <link href="https://cdn.jsdelivr.net/npm/bootstrap-icons@1.11.3/font/bootstrap-icons.css" rel="stylesheet">
    <link rel="stylesheet" href="employee_navbar.css">
    <script src="https://code.jquery.com/jquery-3.7.1.min.js"></script>
    <style>
        :root {
            --brand-gold: #ffcd00;
            --brand-rose: #e36386;
            --brand-blue: #345d9d;
        }

        body {
            background: #f7f7fb;
            font-family: "Noto Sans TC", "Segoe UI", sans-serif;
        }

        .page-header {
            background: #fff;
            border-radius: 14px;
            padding: 24px 30px;
            box-shadow: 0 12px 28px rgba(52, 93, 157, 0.15);
            border-top: 6px solid var(--brand-rose);
        }

        .page-title {
            font-weight: 700;
            color: var(--brand-blue);
        }

        .page-description {
            color: #495057;
        }

        .filter-panel {
            background: #fff;
            border-radius: 14px;
            padding: 18px 20px;
            box-shadow: 0 10px 24px rgba(0, 0, 0, 0.06);
            border: 1px solid rgba(52, 93, 157, 0.12);
        }

        .table thead.table-primary {
            background-color: var(--brand-blue);
            color: #fff;
        }

        .table tbody tr.unread-row {
            background-color: rgba(227, 99, 134, 0.12);
        }

        .table tbody tr:hover {
            background-color: rgba(52, 93, 157, 0.08);
        }

        .empty-state {
            background: #fff;
            border-radius: 16px;
            padding: 40px;
            text-align: center;
            box-shadow: 0 12px 28px rgba(0, 0, 0, 0.07);
        }

        .empty-state i {
            font-size: 48px;
            color: var(--brand-blue);
        }

        .empty-state p {
            margin-top: 12px;
            color: #6c757d;
        }

        .badge-status {
            font-size: 0.85rem;
        }
    </style>
</head>
<body>
<?php include 'employee_navbar.php'; ?>

<main class="container my-4 my-md-5">
    <div class="row g-4 align-items-stretch">
        <div class="col-12">
            <section class="page-header h-100">
                <div class="d-flex flex-column flex-md-row justify-content-between align-items-start align-items-md-center gap-3">
                    <div>
                        <h1 class="page-title mb-2"><i class="bi bi-bell-fill me-2 text-warning"></i>通知中心</h1>
                        <p class="page-description mb-0">集中管理您在系統中的最新通知，快速掌握任務、流程與公告狀況。</p>
                    </div>
                    <div class="text-md-end w-100 w-md-auto">
                        <button class="btn btn-warning text-dark fw-semibold" id="refreshButton"><i class="bi bi-arrow-clockwise me-1"></i>重新整理</button>
                    </div>
                </div>
            </section>
        </div>

        <div class="col-12">
            <section class="filter-panel">
                <div class="row g-3 align-items-center">
                    <div class="col-md-4">
                        <label for="keyword" class="form-label mb-1">關鍵字搜尋</label>
                        <div class="input-group">
                            <span class="input-group-text bg-white"><i class="bi bi-search"></i></span>
                            <input type="text" id="keyword" class="form-control" placeholder="輸入通知內容或關鍵字">
                        </div>
                    </div>
                    <div class="col-md-4">
                        <label for="statusFilter" class="form-label mb-1">狀態篩選</label>
                        <select id="statusFilter" class="form-select">
                            <option value="all">全部通知</option>
                            <option value="unread">僅顯示未讀</option>
                            <option value="read">僅顯示已讀</option>
                        </select>
                    </div>
                    <div class="col-md-4">
                        <label for="dateFilter" class="form-label mb-1">依日期排序</label>
                        <select id="dateFilter" class="form-select">
                            <option value="desc" selected>最新時間在前</option>
                            <option value="asc">最早時間在前</option>
                        </select>
                    </div>
                </div>
            </section>
        </div>

        <div class="col-12">
            <?php if (!empty($notifications)): ?>
                <div class="table-responsive shadow-sm rounded-4 overflow-hidden">
                    <table class="table table-bordered table-hover align-middle mb-0" id="notificationTable">
                        <thead class="table-primary">
                            <tr>
                                <th scope="col" class="text-center" style="width: 80px;">序號</th>
                                <th scope="col">通知內容</th>
                                <th scope="col" style="width: 200px;">收到時間</th>
                                <th scope="col" style="width: 120px;">目前狀態</th>
                            </tr>
                        </thead>
                        <tbody>
                            <?php foreach ($notifications as $index => $notification): ?>
                                <?php
                                    $isUnread = (int)$notification['is_read'] === 0;
                                    $rowClass = $isUnread ? 'unread-row' : '';
                                    $statusBadge = $isUnread
                                        ? '<span class="badge bg-danger badge-status"><i class="bi bi-dot"></i>未讀</span>'
                                        : '<span class="badge bg-success badge-status"><i class="bi bi-check-circle"></i>已讀</span>';
                                ?>
                                <tr class="<?= $rowClass ?>" data-status="<?= $isUnread ? 'unread' : 'read' ?>" data-created-at="<?= htmlspecialchars($notification['created_at']) ?>">
                                    <td class="text-center fw-semibold index-cell">#<?= $index + 1 ?></td>
                                    <td><?= nl2br(htmlspecialchars($notification['message'])) ?></td>
                                    <td><?= htmlspecialchars($notification['created_at']) ?></td>
                                    <td><?= $statusBadge ?></td>
                                </tr>
                            <?php endforeach; ?>
                        </tbody>
                    </table>
                </div>
            <?php else: ?>
                <div class="empty-state">
                    <i class="bi bi-emoji-smile"></i>
                    <h5 class="mt-3 mb-1">目前沒有新的通知</h5>
                    <p class="mb-0">系統一有最新訊息就會立即推播，您也可以稍後再次查看。</p>
                </div>
            <?php endif; ?>
        </div>
    </div>
</main>

<script src="https://cdn.jsdelivr.net/npm/bootstrap@5.3.3/dist/js/bootstrap.bundle.min.js"></script>
<script>
$(function () {
    // JS 功能 1：重新整理頁面
    $('#refreshButton').on('click', function () {
        location.reload();
    });

    // JS 功能 2：依條件動態篩選通知
    function applyFilters() {
        const keyword = $('#keyword').val().toLowerCase();
        const status = $('#statusFilter').val();
        const rows = $('#notificationTable tbody tr');

        rows.each(function () {
            const row = $(this);
            const text = row.text().toLowerCase();
            const rowStatus = row.data('status');

            const matchesKeyword = text.indexOf(keyword) !== -1;
            const matchesStatus = status === 'all' || rowStatus === status;

            if (matchesKeyword && matchesStatus) {
                row.show();
            } else {
                row.hide();
            }
        });

        updateRowIndex();
    }

    $('#keyword').on('input', applyFilters);
    $('#statusFilter').on('change', applyFilters);

    // JS 功能 3：依日期排序
    $('#dateFilter').on('change', function () {
        const order = $(this).val();
        const tbody = $('#notificationTable tbody');
        const rows = tbody.find('tr').get();

        rows.sort(function (a, b) {
            const dateA = new Date($(a).data('created-at'));
            const dateB = new Date($(b).data('created-at'));
            return order === 'asc' ? dateA - dateB : dateB - dateA;
        });

        $.each(rows, function (index, row) {
            tbody.append(row);
        });

        updateRowIndex();
    });

    // JS 功能 4：依目前排序重新編號序列
    function updateRowIndex() {
        let visibleIndex = 1;
        $('#notificationTable tbody tr').each(function () {
            const row = $(this);
            const indexCell = row.find('.index-cell');

            if (row.is(':visible')) {
                indexCell.text('#' + visibleIndex);
                visibleIndex += 1;
            }
        });
    }

    updateRowIndex();
});
</script>
</body>
</html>
