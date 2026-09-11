<?php
$sidebarHome = 'admin/dashboard.php';
$sidebarLabel = 'Administrator navigation';
$sidebarSections = [
    'Clinic' => [
        ['dashboard', 'Dashboard', 'admin/dashboard.php', '/admin/dashboard.php'],
        ['calendar', 'Appointments', 'admin/appointments.php', '/admin/appointments.php'],
        ['calendar-days', 'Calendar', 'admin/calendar.php', '/admin/calendar.php'],
        ['bell', 'Notifications', 'admin/notifications.php', '/admin/notifications.php'],
        ['inventory', 'Inventory', 'admin/inventory.php', '/admin/inventory.php'],
        ['cart', 'Point of Sale', 'admin/pos.php', '/admin/pos.php'],
    ],
    'Pet and Client Records' => [
        ['paw', 'Pets', 'admin/pets.php', '/admin/pets.php'],
        ['edit', 'Pet Edit Requests', 'admin/pet_edit_requests.php', '/admin/pet_edit_requests.php'],
        ['users', 'Clients', 'admin/clients.php', '/admin/clients.php'],
        ['syringe', 'Vaccinations', 'admin/vaccinations.php', '/admin/vaccinations.php'],
        ['qr', 'QR Token', 'admin/qr.php', '/admin/qr.php'],
    ],
    'User Management' => [
        ['user-cog', 'Staff Accounts', 'admin/users.php?role=staff', '/admin/users.php', 'staff'],
        ['stethoscope', 'Veterinarians', 'admin/users.php?role=veterinarian', '/admin/users.php', 'veterinarian'],
        ['shield', 'Administrators', 'admin/users.php?role=admin', '/admin/users.php', 'admin'],
    ],
    'Management' => [
        ['chart', 'Reports and Export', 'admin/reports.php', '/admin/reports.php'],
        ['message', 'Feedback', 'admin/feedback.php', '/admin/feedback.php'],
        ['activity', 'Activity Logs', 'admin/activity_logs.php', '/admin/activity_logs.php'],
        ['help-circle', 'FAQ and Access Guide', 'admin/faqs.php', '/admin/faqs.php'],
    ],
];
include __DIR__ . '/sidebar_template.php';
