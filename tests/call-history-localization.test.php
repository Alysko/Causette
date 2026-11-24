<?php

declare(strict_types=1);

require_once __DIR__ . '/../public_html/app/core/ChatResponse.php';
require_once __DIR__ . '/../public_html/app/core/Locale.php';

use Causette\Http\ChatResponse;
use Causette\Localization\Locale;

Locale::initializeForAccount('fr', 'en');

$messages = ChatResponse::messages([
    (object) ['is_system_event' => true, 'event_type' => 'call_completed', 'content' => 'Alice called Bob (12:04)'],
    (object) ['is_system_event' => true, 'event_type' => 'call_joined', 'content' => 'Bob joined call'],
    (object) ['is_system_event' => true, 'event_type' => 'call_left', 'content' => 'Bob left call'],
    (object) ['is_system_event' => true, 'event_type' => 'call_declined', 'content' => 'Alice declined a call'],
    (object) ['id' => 2, 'content' => 'Bob left call'],
    (object) ['is_system_event' => true, 'event_type' => 'call_joined', 'content' => 'Archived call label'],
]);
$expected = [
    'Alice a appelé Bob (12:04)',
    'Bob a rejoint l’appel',
    'Bob a quitté l’appel',
    'Alice a refusé l’appel',
    'Bob left call',
    'Archived call label',
];
if (array_column($messages, 'content') !== $expected) {
    throw new RuntimeException('Call history should translate generated event text while preserving ordinary messages and unknown history.');
}

echo "Call history localization checks passed.\n";
