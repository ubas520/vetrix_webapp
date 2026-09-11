<?php
$sidebarHome = $sidebarHome ?? dashboard_for_role($_SESSION['role'] ?? 'staff');
$sidebarLabel = $sidebarLabel ?? 'Vetrix navigation';
$sidebarSections = $sidebarSections ?? [];
$currentScript = str_replace('\\', '/', $_SERVER['SCRIPT_NAME'] ?? '');
$currentUser = isset($conn) ? current_user_record($conn) : [
    'full_name' => $_SESSION['full_name'] ?? 'Vetrix User',
    'role' => $_SESSION['role'] ?? 'staff',
    'profile_photo' => null,
];
$profilePath = profile_path_for_role($currentUser['role'] ?? null);
$sidebarLinkIsActive = static function(array $link) use ($currentScript): bool {
    return strpos($currentScript, $link[3]) !== false
        && (empty($link[4]) || ($_GET['role'] ?? '') === $link[4]);
};
$sidebarCollapsibleSections = ['User Management' => 'user-cog', 'Management' => 'settings'];
?>
<aside class="sidebar app-sidebar vetrix-sidebar" id="appSidebar" aria-label="<?= e($sidebarLabel) ?>">
    <div class="vetrix-sidebar-head">
        <a class="vetrix-brand" href="<?= app_url($sidebarHome) ?>" aria-label="Vetrix home">
            <span class="vetrix-brand-logo" aria-hidden="true"><img src="<?= e(app_url(vetrix_brand_logo_path())) ?>" alt=""></span>
            <span class="vetrix-brand-copy"><b>Vetrix</b><small>Vet Care Central</small></span>
        </a>
        <button class="sidebar-collapse-control" type="button" onclick="toggleSidebarCollapse()" aria-label="Collapse navigation" title="Collapse navigation" aria-expanded="true">
            <?= ui_icon('panel') ?>
        </button>
        <button class="sidebar-mobile-close-control" type="button" onclick="toggleSidebar(false)" aria-label="Close navigation" title="Close navigation">
            <?= ui_icon('x') ?>
        </button>
    </div>

    <nav class="vetrix-nav" aria-label="<?= e($sidebarLabel) ?> menu">
        <?php foreach ($sidebarSections as $section => $links): ?>
            <?php
                $sectionCollapsible = isset($sidebarCollapsibleSections[$section]);
                $sectionActive = count(array_filter($links, $sidebarLinkIsActive)) > 0;
                $sectionTag = $sectionCollapsible ? 'details' : 'div';
            ?>
            <<?=$sectionTag?> class="vetrix-nav-section<?=$sectionCollapsible ? ' vetrix-nav-group' : ''?>"<?=$sectionCollapsible && $sectionActive ? ' open' : ''?>>
                <?php if($sectionCollapsible): ?>
                    <summary class="vetrix-nav-link vetrix-nav-group-toggle<?=$sectionActive ? ' section-is-active' : ''?>" title="<?=e($section)?>" aria-label="<?=e($section)?>">
                        <span class="vetrix-nav-icon"><?=ui_icon($sidebarCollapsibleSections[$section])?></span>
                        <span class="vetrix-nav-label vetrix-nav-section-title"><?=e($section)?></span>
                        <span class="vetrix-nav-group-caret" aria-hidden="true"><?=ui_icon('chevron-right')?></span>
                    </summary>
                    <div class="vetrix-nav-group-links">
                <?php else: ?><div class="vetrix-nav-section-title"><?= e($section) ?></div><?php endif; ?>
                <?php foreach ($links as $link):
                    $active = $sidebarLinkIsActive($link) ? 'active' : '';
                ?>
                    <a class="vetrix-nav-link <?= $active ?>" href="<?= app_url($link[2]) ?>" title="<?= e($link[1]) ?>" <?= $active ? 'aria-current="page"' : '' ?>>
                        <span class="vetrix-nav-icon"><?= ui_icon($link[0]) ?></span>
                        <span class="vetrix-nav-label"><?= e($link[1]) ?></span>
                    </a>
                <?php endforeach; ?>
                <?php if($sectionCollapsible): ?></div><?php endif; ?>
            </<?=$sectionTag?>>
        <?php endforeach; ?>
    </nav>

</aside>
