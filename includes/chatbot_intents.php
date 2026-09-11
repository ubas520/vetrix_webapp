<?php
// Keep operational intent matching independent of database access.
function assistant_schedule_day($message) {
    if (preg_match('/\btomorrow\b/i', $message)) return 'tomorrow';
    if (preg_match('/\btoday\b/i', $message)) return 'today';
    return 'upcoming';
}

function assistant_operational_intent($message) {
    $text = mb_strtolower(trim($message));
    if (preg_match('/\b(inventory|stock)\b/', $text) && preg_match('/\blow\b|out of stock|need.*attention|reorder/', $text)) return 'inventory';
    if (preg_match('/\bappointments?\b/', $text) && preg_match('/\bpending\b|requests?.*(review|approval)/', $text)) return 'pending';
    if (preg_match('/\bappointments?\b/', $text) && preg_match('/need.*attention|overdue|past|completion|status update|resched/', $text)) return 'attention';
    if (preg_match('/\bschedule\b|\bappointments?\b/', $text) && preg_match('/\btoday\b|\btomorrow\b|\bnext\b|\bupcoming\b|assigned|scheduled|\bmy\b/', $text)) return 'schedule';
    return null;
}
