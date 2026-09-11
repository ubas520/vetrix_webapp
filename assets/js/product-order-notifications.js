(() => {
    if (document.body.dataset.userRole !== 'staff') return;
    let latest = null;
    let pending = false;
    async function refreshOrders() {
        if (pending || document.hidden) return;
        pending = true;
        try {
            const base = (window.VETRIX_BASE || '/vetrix/').replace(/\/$/, '');
            const response = await fetch(base + '/staff/order_updates.php', { headers: { Accept: 'application/json' }, cache: 'no-store' });
            if (!response.ok) return;
            const data = await response.json();
            if (!Number.isFinite(data.count)) return;
            updateNotificationBadge(data.count);
            const next = Number(data.latest_order_notification) || 0;
            if (latest !== null && next > latest) {
                showToast('New product order update. Open the notification bell to review it.', 'info');
                if (document.getElementById('notificationPopover')?.classList.contains('open')) await loadNotificationPreview();
            }
            latest = next;
        } catch (_) { /* Retry when the connection returns. */ }
        finally { pending = false; }
    }
    refreshOrders();
    setInterval(refreshOrders, 15000);
    document.addEventListener('visibilitychange', refreshOrders);
})();
