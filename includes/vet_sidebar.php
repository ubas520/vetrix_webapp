<?php
$sidebarHome = 'vet/dashboard.php';
$sidebarLabel = 'Veterinarian navigation';
$sidebarSections = [
    'Clinic' => [
        ['dashboard', 'Dashboard', 'vet/dashboard.php', '/vet/dashboard.php'],
        ['calendar', 'Appointments', 'vet/appointments.php', '/vet/appointments.php'],
        ['calendar-days', 'Calendar', 'vet/calendar.php', '/vet/calendar.php'],
        ['heart-pulse', 'Health Monitoring', 'vet/health_monitoring.php', '/vet/health_monitoring.php'],
    ],
    'Records' => [
        ['paw', 'Pets', 'vet/pets.php', '/vet/pets.php'],
        ['edit', 'Pet Change Reviews', 'vet/pet_change_reviews.php', '/vet/pet_change_reviews.php'],
        ['file', 'Medical Records', 'vet/medical_records.php', '/vet/medical_records.php'],
        ['receipt', 'Prescription', 'vet/prescription.php', '/vet/prescription.php'],
        ['syringe', 'Vaccinations', 'vet/vaccinations.php', '/vet/vaccinations.php'],
    ],
    'Help' => [
        ['help-circle', 'Vet FAQs', 'vet/faqs.php', '/vet/faqs.php'],
    ],
];
include __DIR__ . '/sidebar_template.php';