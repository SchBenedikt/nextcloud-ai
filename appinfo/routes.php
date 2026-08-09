<?php

declare(strict_types=1);

return [
    'routes' => [
        ['name' => 'page#index', 'url' => '/', 'verb' => 'GET'],
        ['name' => 'page#app', 'url' => '/app', 'verb' => 'GET'],
        ['name' => 'page#standalone', 'url' => '/standalone', 'verb' => 'GET'],
    ],
    'ocs' => [
        ['name' => 'api#status', 'url' => '/api/status', 'verb' => 'GET'],
        ['name' => 'api#settings', 'url' => '/api/settings', 'verb' => 'GET'],
        ['name' => 'api#saveSettings', 'url' => '/api/settings', 'verb' => 'PUT'],
        ['name' => 'api#startIndex', 'url' => '/api/index', 'verb' => 'POST'],
        ['name' => 'api#resetIndex', 'url' => '/api/indexReset', 'verb' => 'POST'],
        ['name' => 'api#documents', 'url' => '/api/documents', 'verb' => 'GET'],
        ['name' => 'api#documentChunks', 'url' => '/api/documentChunks', 'verb' => 'POST'],
        ['name' => 'api#chat', 'url' => '/api/chat', 'verb' => 'POST'],
        ['name' => 'api#chats', 'url' => '/api/chats', 'verb' => 'GET'],
        ['name' => 'api#createChat', 'url' => '/api/chats', 'verb' => 'POST'],
        ['name' => 'api#chatDetail', 'url' => '/api/chats/{id}', 'verb' => 'GET'],
        ['name' => 'api#chatDelete', 'url' => '/api/chats/{id}', 'verb' => 'DELETE'],
        ['name' => 'api#chatAppend', 'url' => '/api/chats/{id}/messages', 'verb' => 'POST'],
        ['name' => 'api#chatTitle', 'url' => '/api/chats/{id}/title', 'verb' => 'POST'],
        ['name' => 'api#models', 'url' => '/api/models', 'verb' => 'GET'],
        ['name' => 'api#check', 'url' => '/api/check', 'verb' => 'POST'],
        ['name' => 'api#streamChat', 'url' => '/api/streamChat', 'verb' => 'POST'],
        ['name' => 'api#fileContextChat', 'url' => '/api/fileContextChat', 'verb' => 'POST'],
        ['name' => 'api#fileContextStatus', 'url' => '/api/fileContextStatus', 'verb' => 'POST'],
    ],
];
