<?php
$sidebarHome = 'staff/dashboard.php';
$sidebarLabel = 'Clinic staff navigation';
$sidebarSections = [
    'Staff Tools' => [
        ['dashboard', 'Dashboard', 'staff/dashboard.php', '/staff/dashboard.php'],
        ['calendar', 'Appointments', 'staff/appointments.php', '/staff/appointments.php'],
        ['calendar-days', 'Calendar', 'staff/calendar.php', '/staff/calendar.php'],
        ['users', 'Clients', 'staff/clients.php', '/staff/clients.php'],
    ],
    'Clinic Flow' => [
        ['paw', 'Pets', 'staff/pets.php', '/staff/pets.php'],
        ['package', 'Product Orders', 'staff/product_orders.php', '/staff/product_orders.php'],
        ['cart', 'Point of Sale', 'staff/pos.php', '/staff/pos.php'],
        ['coins', 'Payment Settings', 'staff/payment_settings.php', '/staff/payment_settings.php'],
        ['receipt', 'Receipts', 'staff/receipts.php', '/staff/receipts.php'],
        ['inventory', 'Inventory', 'staff/inventory.php', '/staff/inventory.php'],
        ['qr', 'QR Token', 'staff/qr.php', '/staff/qr.php'],
    ],
    'Help' => [
        ['help-circle', 'Frequently Asked Questions', 'staff/faqs.php', '/staff/faqs.php'],
    ],
];
include __DIR__ . '/sidebar_template.php';