<?php
$navRole = $_SESSION['role'] ?? 'guest';
$homeLink = dashboard_for_role($navRole);
$notifCount = isset($conn) ? get_nav_notification_count($conn, $navRole, current_user_id()) : 0;
$notifLabel = $notifCount > 99 ? '99+' : (string)$notifCount;
$pageLabel = preg_replace('/\s+-\s+Vetrix$/', '', $title ?? 'Dashboard');
$compactPageLabel = preg_replace('/^(?:Administrator|Admin|Veterinarian|Vet|Staff)\s+/i', '', $pageLabel) ?: $pageLabel;
$currentUser = isset($conn) ? current_user_record($conn) : [
    'full_name' => $_SESSION['full_name'] ?? 'Vetrix User',
    'role' => $navRole,
    'profile_photo' => null,
];
$profileLink = profile_path_for_role($navRole);
$portalLabel = match ($navRole) {
    'admin' => 'Admin Portal',
    'veterinarian' => 'Vet Portal',
    'staff' => 'Staff Portal',
    default => 'Vet Care Central',
};
$searchPrompt = match ($navRole) {
    'veterinarian' => 'Search pets, records, appointments...',
    'staff' => 'Search clients, pets, appointments...',
    default => 'Search anything in Vetrix...',
};
?>
<nav class="topbar app-topbar vetrix-topbar" aria-label="Top navigation">
    <div class="vetrix-topbar-brand-area">
        <button class="topbar-icon-button mobile-menu-control" type="button" onclick="toggleSidebar(true)" aria-label="Open navigation" title="Open navigation">
            <?= ui_icon('menu') ?>
        </button>
        <a class="vetrix-topbar-brand" href="<?= app_url($homeLink) ?>" aria-label="Vetrix home">
            <span class="vetrix-topbar-logo" aria-hidden="true"><img src="<?= e(app_url(vetrix_brand_logo_path())) ?>" alt=""></span>
            <span class="vetrix-topbar-brand-copy"><b>Vetrix</b><small><?= e($portalLabel) ?></small></span>
        </a>
        <button class="topbar-icon-button desktop-menu-control" type="button" onclick="toggleSidebarCollapse()" aria-label="Collapse navigation" title="Collapse navigation" aria-expanded="true">
            <?= ui_icon('chevron-left') ?>
        </button>
    </div>

    <div class="vetrix-topbar-center">
        <button class="vetrix-global-search" id="globalSearchButton" type="button" aria-haspopup="dialog" aria-controls="globalSearchDialog">
            <?= ui_icon('search') ?>
            <span><?= e($searchPrompt) ?></span>
            <kbd>Ctrl + K</kbd>
        </button>
        <div class="vetrix-mobile-page-context">
            <strong class="mobile-page-label-full"><?= e($pageLabel) ?></strong>
            <strong class="mobile-page-label-short"><?= e($compactPageLabel) ?></strong>
        </div>
    </div>

    <div class="topbar-right vetrix-topbar-actions">
        <button class="topbar-icon-button notification-control" id="notificationButton" type="button" aria-label="Open notifications<?= $notifCount > 0 ? ' (' . $notifLabel . ' new)' : '' ?>" title="Notifications" aria-expanded="false">
            <?= ui_icon('bell') ?>
            <?php if ($notifCount > 0): ?><span class="notif-count-badge" id="notificationBadge"><?= e($notifLabel) ?></span><?php endif; ?>
        </button>
        <button class="vetrix-account-chip" id="accountMenuButton" type="button" aria-haspopup="menu" aria-expanded="false" title="Open profile and account settings">
            <?= user_avatar_markup($currentUser, 'vetrix-account-avatar') ?>
            <span class="vetrix-account-copy"><b data-fit-name><?= e($currentUser['full_name'] ?? 'Vetrix User') ?></b><small><?= e(role_label($navRole)) ?></small></span>
            <span class="vetrix-account-caret"><?= ui_icon('chevron-down') ?></span>
        </button>
    </div>
</nav>
<section class="vetrix-search-dialog" id="globalSearchDialog" aria-hidden="true" role="dialog" aria-modal="true" aria-labelledby="globalSearchTitle">
    <div class="vetrix-search-scrim" data-global-search-close></div>
    <div class="vetrix-search-card">
        <header>
            <span class="vetrix-search-leading"><?= ui_icon('search') ?></span>
            <label class="visually-hidden" for="globalSearchInput" id="globalSearchTitle">Search Vetrix navigation</label>
            <input id="globalSearchInput" type="search" autocomplete="off" placeholder="Search pages and clinic tools...">
            <button class="icon-button" type="button" data-global-search-close aria-label="Close search"><?= ui_icon('x') ?></button>
        </header>
        <div class="vetrix-search-results" id="globalSearchResults" role="listbox" aria-label="Search results"></div>
        <footer><span>Navigate with your keyboard</span><span><kbd>Enter</kbd> open&nbsp;&nbsp;<kbd>Esc</kbd> close</span></footer>
    </div>
</section>
<div class="sidebar-backdrop" onclick="toggleSidebar(false)" aria-hidden="true"></div>
<div class="ui-scrim" id="uiScrim" hidden></div>
<section class="notification-popover" id="notificationPopover" aria-label="Notifications" aria-hidden="true">
    <header>
        <div><h2>Notifications</h2></div>
        <button type="button" class="icon-button" data-close-notifications aria-label="Close notifications"><?= ui_icon('x') ?></button>
    </header>
    <div class="notification-popover-body" id="notificationPreviewList">
        <div class="notification-loading"><span class="loading-ring"></span><p>Loading notifications</p></div>
    </div>
    <footer class="notification-popover-footer">
        <button class="button-secondary" type="button" id="markAllNotificationsRead"<?= $notifCount <= 0 ? ' disabled aria-disabled="true"' : '' ?>>Mark all read</button>
        <button class="button-primary" type="button" id="openAllNotifications">All notifications</button>
    </footer>
</section>

<section class="app-dialog notification-archive-dialog" id="allNotificationsDialog" aria-hidden="true" role="dialog" aria-modal="true" aria-labelledby="allNotificationsTitle">
    <div class="app-dialog-scrim" data-close-all-notifications></div>
    <div class="app-dialog-card notification-archive-card">
        <header><div><h2 id="allNotificationsTitle">All notifications</h2></div><button class="icon-button" type="button" data-close-all-notifications aria-label="Close all notifications"><?= ui_icon('x') ?></button></header>
        <div class="notification-archive-summary"><button class="button-secondary" type="button" id="markAllNotificationsReadArchive"<?= $notifCount <= 0 ? ' disabled aria-disabled="true"' : '' ?>>Mark all read</button></div>
        <div class="notification-archive-body" id="allNotificationList"><div class="notification-loading"><span class="loading-ring"></span><p>Loading notifications</p></div></div>
    </div>
</section>
<div class="account-menu" id="accountMenu" role="menu" aria-hidden="true">
    <div class="account-menu-summary">
        <?= user_avatar_markup($currentUser, 'account-menu-avatar') ?>
        <div><b><?= e($currentUser['full_name'] ?? 'Vetrix User') ?></b><small><?= e($currentUser['email'] ?? ($_SESSION['email'] ?? '')) ?></small></div>
    </div>
    <a role="menuitem" href="<?= app_url($profileLink) ?>"><?= ui_icon('user') ?><span>Profile</span></a>
    <a role="menuitem" href="<?= app_url('account/change_password.php') ?>"><?= ui_icon('lock') ?><span>Change password</span></a>
    <?php if ($navRole === 'admin'): ?><a role="menuitem" href="<?= app_url('account/logo.php') ?>"><?= ui_icon('image') ?><span>Logo</span></a><?php endif; ?>
    <form method="POST" action="<?= app_url('logout.php') ?>" class="account-menu-signout" data-confirm-skip="true"><button role="menuitem" type="submit"><?= ui_icon('logout') ?><span>Sign out</span></button></form>
</div>
