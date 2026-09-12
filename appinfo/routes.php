<?php

declare(strict_types=1);

return [
    'routes' => [
        ['name' => 'page#index', 'url' => '/', 'verb' => 'GET'],
        ['name' => 'page#app', 'url' => '/app', 'verb' => 'GET'],
        ['name' => 'page#settings', 'url' => '/settings', 'verb' => 'GET'],
        ['name' => 'page#documents', 'url' => '/documents', 'verb' => 'GET'],
		// The Metrics view is a first-class SPA route. Without this server-side
		// route, refreshing /apps/eva_ai/metrics bypasses Vue and returns 404.
        ['name' => 'page#metrics', 'url' => '/metrics', 'verb' => 'GET'],
        ['name' => 'page#standalone', 'url' => '/standalone', 'verb' => 'GET'],
    ],
    'ocs' => [
        ['name' => 'api#status', 'url' => '/api/status', 'verb' => 'GET'],
        ['name' => 'api#stats', 'url' => '/api/stats', 'verb' => 'GET'],
        ['name' => 'api#metrics', 'url' => '/api/metrics', 'verb' => 'GET'],
        ['name' => 'api#health', 'url' => '/api/health', 'verb' => 'GET'],
        ['name' => 'api#greeting', 'url' => '/api/greeting', 'verb' => 'GET'],
        ['name' => 'api#settings', 'url' => '/api/settings', 'verb' => 'GET'],
        ['name' => 'api#saveSettings', 'url' => '/api/settings', 'verb' => 'PUT'],
        ['name' => 'api#startIndex', 'url' => '/api/index', 'verb' => 'POST'],
        ['name' => 'api#startMailIndex', 'url' => '/api/mailIndex', 'verb' => 'POST'],
        // Index the user's Nextcloud Talk chat histories on demand (the button
        // in the settings/documents view), independent of the automatic pass.
        ['name' => 'api#startTalkIndex', 'url' => '/api/talkIndex', 'verb' => 'POST'],
        ['name' => 'api#stopIndex', 'url' => '/api/indexStop', 'verb' => 'POST'],
        ['name' => 'api#resetIndex', 'url' => '/api/indexReset', 'verb' => 'POST'],
        ['name' => 'api#documents', 'url' => '/api/documents', 'verb' => 'GET'],
        ['name' => 'api#documentChunks', 'url' => '/api/documentChunks', 'verb' => 'POST'],
        ['name' => 'api#chat', 'url' => '/api/chat', 'verb' => 'POST'],
        ['name' => 'api#backgroundChat', 'url' => '/api/backgroundChat', 'verb' => 'POST'],
        ['name' => 'api#backgroundChatStatus', 'url' => '/api/backgroundChat', 'verb' => 'GET'],
        ['name' => 'api#cancelBackgroundChat', 'url' => '/api/backgroundChat', 'verb' => 'DELETE'],
        ['name' => 'api#pauseBackgroundChat', 'url' => '/api/backgroundChat/pause', 'verb' => 'POST'],
        ['name' => 'api#resumeBackgroundChat', 'url' => '/api/backgroundChat/resume', 'verb' => 'POST'],
        ['name' => 'api#retryBackgroundChat', 'url' => '/api/backgroundChat/retry', 'verb' => 'POST'],
        ['name' => 'api#chats', 'url' => '/api/chats', 'verb' => 'GET'],
        ['name' => 'api#createChat', 'url' => '/api/chats', 'verb' => 'POST'],
        ['name' => 'api#deleteAllChats', 'url' => '/api/chats', 'verb' => 'DELETE'],
        ['name' => 'api#chatDetail', 'url' => '/api/chats/{id}', 'verb' => 'GET'],
        ['name' => 'api#chatDelete', 'url' => '/api/chats/{id}', 'verb' => 'DELETE'],
        ['name' => 'api#chatAppend', 'url' => '/api/chats/{id}/messages', 'verb' => 'POST'],
        ['name' => 'api#chatTitle', 'url' => '/api/chats/{id}/title', 'verb' => 'POST'],
        ['name' => 'api#chatMeta', 'url' => '/api/chats/{id}/meta', 'verb' => 'POST'],
        ['name' => 'api#chatRegenerate', 'url' => '/api/chats/{id}/regenerate', 'verb' => 'POST'],
        ['name' => 'api#folders', 'url' => '/api/folders', 'verb' => 'GET'],
        ['name' => 'api#createFolder', 'url' => '/api/folders', 'verb' => 'POST'],
        ['name' => 'api#renameFolder', 'url' => '/api/folders/rename', 'verb' => 'POST'],
        ['name' => 'api#deleteFolder', 'url' => '/api/folders/delete', 'verb' => 'POST'],
        ['name' => 'api#models', 'url' => '/api/models', 'verb' => 'GET'],
        ['name' => 'api#calendars', 'url' => '/api/calendars', 'verb' => 'GET'],
        ['name' => 'api#check', 'url' => '/api/check', 'verb' => 'POST'],
        ['name' => 'api#exportData', 'url' => '/api/export', 'verb' => 'GET'],
        ['name' => 'api#streamChat', 'url' => '/api/streamChat', 'verb' => 'POST'],
        ['name' => 'api#confirmTool', 'url' => '/api/confirmTool', 'verb' => 'POST'],
        ['name' => 'api#fileContextChat', 'url' => '/api/fileContextChat', 'verb' => 'POST'],
        ['name' => 'api#fileContextStatus', 'url' => '/api/fileContextStatus', 'verb' => 'POST'],
        ['name' => 'api#knowledge', 'url' => '/api/knowledge', 'verb' => 'GET'],
        ['name' => 'api#saveKnowledge', 'url' => '/api/knowledge', 'verb' => 'PUT'],
        // Admin-only endpoints (Issue #82): instance settings, overview and
        // per-user management. The settings routes were missing, so the admin
        // page's save buttons used to hit a 404 and silently changed nothing.
        ['name' => 'admin#getSettings', 'url' => '/api/admin/settings', 'verb' => 'GET'],
        ['name' => 'admin#saveSettings', 'url' => '/api/admin/settings', 'verb' => 'PUT'],
        ['name' => 'admin#overview', 'url' => '/api/admin/overview', 'verb' => 'GET'],
        ['name' => 'admin#reindex', 'url' => '/api/admin/users/{userId}/reindex', 'verb' => 'POST'],
        ['name' => 'admin#reset', 'url' => '/api/admin/users/{userId}/reset', 'verb' => 'POST'],
        ['name' => 'admin#setEnrollment', 'url' => '/api/admin/users/{userId}/enrollment', 'verb' => 'POST'],
        ['name' => 'admin#stopBackgroundIndex', 'url' => '/api/admin/stop', 'verb' => 'POST'],
        // Live check of the configured web search from the admin page, so a
        // broken provider is visible before it is used in a chat.
        ['name' => 'admin#testWebSearch', 'url' => '/api/admin/websearch/test', 'verb' => 'POST'],
    ],
];
