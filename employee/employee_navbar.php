<?php
/** 【PHP-1】初始化登入資訊與導覽設定 */
if (session_status() === PHP_SESSION_NONE) {
    session_start();
}

$user = $_SESSION['user'] ?? [];
$employeeName = $user['name'] ?? '使用者';
$employeeNumber = $user['employee_number'] ?? '';
$isAdmin = isset($user['role']) && $user['role'] === 'admin';
$isManager = !empty($user['is_manager']);
$currentPage = basename($_SERVER['PHP_SELF'] ?? '');

$navItems = [
    [
        'id' => 'employee_home.php',
        'label' => '🏠 首頁總覽',
        'href' => '/mymakeart/employee/employee_home.php',
    ],
    [
        'id' => 'edit_profile.php',
        'label' => '✏️ 個人資料',
        'href' => '/mymakeart/employee/edit_profile.php',
    ],
    [
        'id' => 'request_leave.php',
        'label' => '🟡 申請請假/加班',
        'href' => '/mymakeart/employee/request_leave.php',
    ],
    [
        'id' => 'history_requests.php',
        'label' => '📄 我的申請',
        'href' => '/mymakeart/employee/history_requests.php',
    ],
    [
        'id' => 'tasks_list.php',
        'label' => '✅ 我的任務',
        'href' => '/mymakeart/employee/tasks_list.php',
    ],
    [
        'id' => 'tasks_assigned_by_me.php',
        'label' => '🧭 我指派的任務',
        'href' => '/mymakeart/employee/tasks_assigned_by_me.php',
    ],
    [
        'id' => 'notifications.php',
        'label' => '🔔 系統通知',
        'href' => '/mymakeart/employee/notifications.php',
    ],
    [
        'id' => 'employee_salary_summary.php',
        'label' => '💰 我的薪資',
        'href' => '/mymakeart/employee/employee_salary_summary.php',
    ],
];
?>

<div class="employee-navbar py-2 py-md-3">
    <div class="container">
        <div class="employee-navbar__surface">
            <div class="row g-3 align-items-center">
                <div class="col-xl-7 col-lg-8 col-md-7">
                    <div class="d-flex align-items-center gap-3">
                        <img src="/mymakeart/LOGO/LOGO-05.png" alt="企業識別LOGO" class="employee-navbar__logo">
                        <div>
                            <h1 class="employee-navbar__title mb-1">員工服務導覽中心</h1>
                            <p class="employee-navbar__subtitle mb-0">統一品牌色導覽，快速切換日常功能。</p>
                        </div>
                    </div>
                </div>
                <div class="col-xl-5 col-lg-4 col-md-5">
                    <div class="employee-navbar__user-card text-md-end">
                        <div class="employee-navbar__user-name mb-1">
                            <?= htmlspecialchars($employeeName) ?>
                            <?php if ($isManager): ?>
                                <span class="badge employee-navbar__badge-manager ms-2">主管</span>
                            <?php endif; ?>
                        </div>
                        <div class="employee-navbar__user-meta-wrap">
                            <?php if (!empty($employeeNumber)): ?>
                                <span class="employee-navbar__user-meta">工號：<?= htmlspecialchars($employeeNumber) ?></span>
                            <?php endif; ?>
                            <span class="employee-navbar__user-meta">角色：<?= $isManager ? '主管 / 員工' : ($isAdmin ? '管理員 / 員工' : '一般員工') ?></span>
                        </div>
                        <div class="employee-navbar__actions mt-2">
                            <?php if ($isAdmin): ?>
                                <a href="/mymakeart/admin/admin_home.php" class="btn btn-sm btn-brand-warning me-md-2 mb-2 mb-md-0">⚙️ 管理員模式</a>
                            <?php endif; ?>
                            <a href="/mymakeart/login.php" class="btn btn-sm btn-brand-danger">登出</a>
                        </div>
                    </div>
                </div>
            </div>

            <div class="row mt-3 align-items-center">
                <div class="col-12">
                    <div class="employee-navbar__link-outer position-relative">
                        <div class="employee-navbar__link-wrap" id="employeeNavLinks">
                            <?php foreach ($navItems as $item): ?>
                                <?php $isActive = $currentPage === $item['id']; ?>
                                <a href="<?= htmlspecialchars($item['href']) ?>"
                                   class="btn btn-sm <?= $isActive ? 'btn-brand-primary' : 'btn-brand-outline' ?> employee-navbar__link">
                                    <?= htmlspecialchars($item['label']) ?>
                                </a>
                            <?php endforeach; ?>
                        </div>
                        <div class="employee-navbar__scroll-hint small text-muted d-none" id="employeeNavHint">↔ 向左右滑動以瀏覽更多功能</div>
                    </div>
                </div>
            </div>
        </div>
    </div>
</div>

<script>
// 【JS-1】導覽列在手機尺寸下顯示滑動提示
(function() {
    const navWrap = document.getElementById('employeeNavLinks');
    const hint = document.getElementById('employeeNavHint');

    if (!navWrap || !hint) {
        return;
    }

    const toggleHint = () => {
        if (navWrap.scrollWidth > navWrap.clientWidth + 10) {
            hint.classList.remove('d-none');
        } else {
            hint.classList.add('d-none');
        }
    };

    toggleHint();
    window.addEventListener('resize', toggleHint);
})();
</script>
