<?php
require_once __DIR__.'/../includes/chatbot_intents.php';
$cases = [
    ['What appointments are scheduled today?', 'schedule', 'today'],
    ['What appointments are assigned to me today?', 'schedule', 'today'],
    ['What is my schedule tomorrow?', 'schedule', 'tomorrow'],
    ['Which appointments are assigned to me next?', 'schedule', 'upcoming'],
    ['When is my next appointment?', 'schedule', 'upcoming'],
    ['Which appointments are pending?', 'pending', 'upcoming'],
    ['Which appointment requests need review right now?', 'pending', 'upcoming'],
    ['Which appointments need completion or rescheduling?', 'attention', 'upcoming'],
    ['Which appointments need attention?', 'attention', 'upcoming'],
    ['Which inventory items need attention?', 'inventory', 'upcoming'],
    ['Which inventory items are low in stock?', 'inventory', 'upcoming'],
    ['Show low stock', 'inventory', 'upcoming'],
    ['Which vaccinations are due soon?', null, 'upcoming'],
    ['My pet needs attention', null, 'upcoming'],
    ['How do I add unavailable time?', null, 'upcoming'],
];
foreach ($cases as [$message, $intent, $day]) {
    if (assistant_operational_intent($message) !== $intent || assistant_schedule_day($message) !== $day) {
        fwrite(STDERR, "FAIL: $message\n"); exit(1);
    }
}
echo count($cases)." chatbot intent checks passed.\n";
