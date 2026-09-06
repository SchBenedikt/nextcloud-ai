<?php
// EvaAi – eigenständige Seite (ohne Vue/Framework), jetzt im
// Nextcloud-Look: Core-Design-Variablen, Topbar und Seitenleiste.
// requesttoken/apiBase/version kommen per util::addHeader/addScript.
?>
<!DOCTYPE html>
<html lang="en">
<head>
    <meta charset="utf-8">
    <title>EVA – Chat with your files</title>
    <meta name="viewport" content="width=device-width, initial-scale=1">
    <link rel="stylesheet" href="<?php echo htmlspecialchars(\OC::$WEBROOT . '/core/css/server.css', ENT_QUOTES); ?>">
    <style>
        * { box-sizing: border-box; margin: 0; padding: 0; }
        body {
            font-family: -apple-system, BlinkMacSystemFont, "Segoe UI", Roboto, "Helvetica Neue", Arial, sans-serif;
            background: var(--color-main-background, #f7f7f7);
            color: var(--color-main-text, #111);
            height: 100vh;
            display: flex;
            flex-direction: column;
        }

        /* ============ Topbar ============ */
        #topbar {
            display: flex;
            align-items: center;
            gap: 14px;
            padding: 0 16px;
            height: 52px;
            background: var(--color-primary-background, #0082c9);
            color: var(--color-primary-element-text, #fff);
            flex-shrink: 0;
            position: relative;
            z-index: 5;
        }
        #topbar .brand {
            display: flex;
            align-items: center;
            gap: 10px;
            font-size: 18px;
            font-weight: 700;
            color: inherit;
            text-decoration: none;
        }
        #topbar .logo {
            width: 28px;
            height: 28px;
            border-radius: 7px;
        }
        #topbar .spacer { flex: 1; }
        #topbar .toplink {
            color: var(--color-primary-element-text, #fff);
            font-size: 13px;
            text-decoration: none;
            background: rgba(255, 255, 255, .15);
            padding: 6px 12px;
            border-radius: 20px;
        }
        #topbar .toplink:hover { background: rgba(255, 255, 255, .25); }

        /* ============ Layout ============ */
        #layout {
            flex: 1;
            display: flex;
            overflow: hidden;
        }

        /* ============ Sidebar ============ */
        #sidebar {
            width: 240px;
            flex-shrink: 0;
            background: var(--color-background-dark, #ededed);
            border-right: 1px solid var(--color-border, #ddd);
            padding: 14px 10px;
            overflow-y: auto;
        }
        #sidebar .nav-item {
            display: flex;
            align-items: center;
            gap: 12px;
            padding: 9px 12px;
            margin: 2px 0;
            border-radius: 8px;
            color: var(--color-main-text, #111);
            text-decoration: none;
            font-size: 14px;
            font-weight: 600;
        }
        #sidebar .nav-item:hover { background: var(--color-background-hover, #e5e5e5); }
        #sidebar .nav-item.active {
            background: var(--color-primary-light, #e8f0f7);
            color: var(--color-primary-element, #00679c);
        }
        #sidebar .nav-ico { font-size: 16px; width: 20px; text-align: center; }
        #sidebar .sidebar-sep { height: 1px; background: var(--color-border, #ddd); margin: 12px 8px; }

        /* ============ Inhalt ============ */
        #content {
            flex: 1;
            display: flex;
            flex-direction: column;
            overflow: hidden;
            padding: 24px clamp(16px, 3vw, 36px) 28px;
            background: var(--color-main-background, #f7f7f7);
        }
        .head { display: flex; align-items: center; justify-content: space-between; gap: 16px; flex-wrap: wrap; margin-bottom: 18px; padding-bottom: 16px; border-bottom: 1px solid var(--color-border, #ddd); }
        .head h1 { font-size: clamp(22px, 3vw, 28px); font-weight: 700; letter-spacing: -.02em; color: var(--color-main-text, #111); }
        .head-left { display: flex; align-items: center; gap: 10px; }
        .badge {
            font-size: 11px; color: var(--color-text-maxcontrast, #666); background: var(--color-background-hover, #e9e9e9);
            border: 1px solid var(--color-border, #d5d5d5); border-radius: 12px; padding: 3px 10px;
        }
        .pill { padding: 3px 12px; border-radius: 20px; font-size: 12px; font-weight: 600; background: var(--color-background-hover, #e5e5e5); color: var(--color-main-text, #333); }
        .pill-ok { background: var(--color-success, #2fb344); color: var(--color-primary-element-text, #fff); }
        .pill-bad { background: var(--color-error, #e9322d); color: var(--color-primary-element-text, #fff); }
        .pill-warn { background: var(--color-warning, #f0a64a); color: #111; }
        .refresh { border: 1px solid var(--color-border, #ddd); background: var(--color-main-background, #fff); border-radius: 6px; padding: 4px 9px; cursor: pointer; font-size: 14px; line-height: 1; color: var(--color-main-text, #111); }
        #msgs {
            flex: 1;
            min-height: 320px;
            background: var(--color-background-dark, var(--color-main-background, #fff));
            border: 1px solid var(--color-border, #ddd);
            border-radius: 14px;
            padding: clamp(14px, 2vw, 22px);
            overflow-y: auto;
            display: flex;
            flex-direction: column;
            gap: 12px;
            margin-bottom: 12px;
        }
        .empty { flex: 1; display: flex; flex-direction: column; align-items: center; justify-content: center; text-align: center; gap: 6px; padding: 24px; }
        .empty .ico { font-size: 42px; }
        .empty .t { font-size: 16px; font-weight: 600; color: var(--color-main-text, #222); }
        .empty .d { font-size: 13px; color: var(--color-text-maxcontrast, #444); max-width: 480px; }
        .rm { display: flex; flex-direction: column; align-items: flex-start; width: 100%; }
        .rm.user { align-items: flex-end; }
        .rb { max-width: min(86%, 820px); padding: 12px 16px; border: 1px solid var(--color-border, #ddd); border-radius: 14px; line-height: 1.5; font-size: 14px; word-break: break-word; background: var(--color-main-background, #fff); color: var(--color-main-text, #111); }
        .rm.user .rb { border-color: color-mix(in srgb, var(--color-primary-element) 45%, transparent); }
        .rm.assistant .rb { background: var(--color-main-background, #fff) !important; }
        .rm.user .rb { background: var(--color-primary-element, #00679c); color: var(--color-primary-element-text, #fff); border-bottom-right-radius: 4px; }
        .rm.assistant .rb { border-bottom-left-radius: 4px; }
        .rt { white-space: normal; font-size: 14px; line-height: 1.55; color: inherit; text-align: left; }
        .rt p { margin: 0 0 8px; }
        .rt p:last-child { margin-bottom: 0; }
        .rt ul, .rt ol { margin: 0 0 8px 22px; padding: 0; }
        .rt ul { list-style: disc; }
        .rt ol { list-style: decimal; }
        .rt li { margin: 2px 0; }
        .rt h1, .rt h2, .rt h3, .rt h4, .rt h5, .rt h6 { margin: 10px 0 6px; font-weight: 600; line-height: 1.3; }
        .rt h1 { font-size: 17px; }
        .rt h2 { font-size: 16px; }
        .rt h3 { font-size: 15px; }
        .rt h4, .rt h5, .rt h6 { font-size: 14px; }
        .rt p code, .rt li code { font-family: var(--font-family-monospace, monospace); font-size: 85%; background: var(--color-background-dark, #eee); padding: 1px 5px; border-radius: 4px; }
        .rt pre { background: var(--color-background-dark, #f0f0f0); padding: 10px 12px; border-radius: 8px; overflow-x: auto; margin: 0 0 8px; }
        .rt pre code { font-family: var(--font-family-monospace, monospace); font-size: 13px; background: transparent; padding: 0; white-space: pre-wrap; }
        .rt blockquote { margin: 6px 0; padding: 4px 12px; border-left: 3px solid var(--color-border, #ccc); color: var(--color-text-maxcontrast, #555); }
        .rt a { color: var(--color-primary-element, #00679c); text-decoration: underline; }
        .rt hr { border: none; border-top: 1px solid var(--color-border, #ddd); margin: 10px 0; }
        .head-right { display: flex; align-items: center; gap: 8px; }
        #sidebar { display: flex; flex-direction: column; }
        .nav-new {
            display: flex; align-items: center; justify-content: center; gap: 8px;
            width: 100%; padding: 9px 12px; margin-bottom: 8px;
            border: 0; border-radius: 8px; cursor: pointer;
            background: var(--color-primary-element, #00679c);
            color: var(--color-primary-element-text, #fff);
            font-size: 14px; font-weight: 600; font-family: inherit;
        }
        .nav-new:hover { filter: brightness(1.08); }
        .nav-new:disabled { opacity: .6; cursor: default; }
        #chatlist { flex: 1; overflow-y: auto; }
        .chat-entry {
            display: flex; align-items: center; gap: 8px;
            padding: 7px 10px; margin: 2px 0; border-radius: 8px;
            color: var(--color-main-text, #111); font-size: 13px;
        }
        .chat-entry .t { flex: 1; overflow: hidden; text-overflow: ellipsis; white-space: nowrap; cursor: pointer; }
        .chat-entry:hover { background: var(--color-background-hover, #e5e5e5); }
        .chat-entry.active { background: var(--color-primary-light, #e8f0f7); color: var(--color-primary-element, #00679c); }
        .chat-entry .x {
            border: 0; background: none; cursor: pointer; color: var(--color-text-maxcontrast, #888);
            font-size: 13px; line-height: 1; padding: 2px 4px; border-radius: 4px; font-family: inherit;
        }
        .chat-entry .x:hover { background: var(--color-error, #e9322d); color: #fff; }
        .sidebar-spacer { flex: 1; }
        .chat-empty { font-size: 12px; color: var(--color-text-maxcontrast, #666); padding: 8px 12px; }
        .rth { margin-bottom: 8px; font-size: 12px; }
        .rth summary { cursor: pointer; color: #555; font-weight: 600; user-select: none; }
        .rth-c { margin-top: 6px; padding: 8px 10px; background: #eef1f4; border-radius: 6px; white-space: pre-wrap; word-break: break-word; color: #555; font-size: 12px; line-height: 1.5; max-height: 220px; overflow-y: auto; }
        .rs { margin-top: 6px; padding: 6px 10px; background: var(--color-background-dark, #f3f3f3); border-radius: 6px; font-size: 12px; color: var(--color-main-text, #333); }
        .rtools { margin-top: 6px; display: flex; flex-direction: column; gap: 4px; max-width: 86%; }
        .rtools .tool { font-size: 12px; padding: 4px 10px; border-radius: 6px; background: var(--color-background-dark, #eef1f4); color: var(--color-text-maxcontrast, #555); font-family: var(--font-family-monospace, monospace); }
        .rtools .tool.running { color: #8a6d1a; }
        .rtools .tool.ok { color: #2f8f3f; }
        .rtools .tool.bad { color: var(--color-error, #e9322d); }
        .rs .lab { color: var(--color-text-maxcontrast, #555); margin-bottom: 2px; }
        .rs a { display: block; color: var(--color-primary-element, #00679c); text-decoration: none; margin: 2px 0; }
        .rconfirm { margin-top: 10px; max-width: min(100%, 560px); padding: 12px; border: 1px solid var(--color-border, #ccd0d4); border-left: 3px solid var(--color-warning, #eab308); border-radius: 8px; background: var(--color-background-hover, #f6f7f8); }
        .rconfirm-label { font-size: 13px; font-weight: 650; }
        .rconfirm-args { max-height: 150px; margin: 8px 0; padding: 8px; overflow: auto; white-space: pre-wrap; word-break: break-word; font: 12px/1.45 var(--font-family-monospace, monospace); color: var(--color-text-maxcontrast, #555); background: var(--color-main-background, #fff); border: 1px solid var(--color-border, #ddd); border-radius: 5px; }
        .rconfirm-share-form { display: grid; grid-template-columns: repeat(2, minmax(0, 1fr)); gap: 10px; margin: 10px 0 12px; }
        .rconfirm-field { display: flex; flex-direction: column; gap: 4px; min-width: 0; }
        .rconfirm-field:first-child { grid-column: 1 / -1; }
        .rconfirm-field span, .rconfirm-check span { color: var(--color-text-maxcontrast, #555); font-size: 12px; font-weight: 600; }
        .rconfirm-field input, .rconfirm-field select, .rconfirm-field textarea { width: 100%; min-height: 34px; padding: 6px 8px; border: 1px solid var(--color-border, #bbb); border-radius: 6px; background: var(--color-main-background, #fff); color: var(--color-main-text, #222); font: inherit; font-size: 13px; }
        .rconfirm-field textarea { min-height: 58px; resize: vertical; }
        .rconfirm-check { display: flex; align-items: center; gap: 7px; min-width: 0; }
        .rconfirm-check input { margin: 0; }
        .rconfirm-actions { display: flex; gap: 8px; flex-wrap: wrap; }
        .rconfirm-actions button { min-height: 34px; padding: 6px 12px; border: 1px solid var(--color-border, #ccd0d4); border-radius: 6px; cursor: pointer; font: inherit; font-size: 13px; }
        .rconfirm-approve { color: var(--color-primary-element-text, #fff); background: var(--color-primary-element, #00679c); border-color: var(--color-primary-element, #00679c) !important; }
        .rconfirm-reject { color: var(--color-main-text, #222); background: var(--color-main-background, #fff); }
        .rconfirm-actions button:disabled { opacity: .6; cursor: default; }
        .export-btn {
            border: 1px solid var(--color-border, #ccc);
            background: var(--color-main-background, #fff);
            color: var(--color-main-text, #111);
            border-radius: 6px;
            padding: 5px 10px;
            font-size: 12px;
            cursor: pointer;
        }
        .export-btn:disabled { opacity: .5; cursor: default; }
        @media (max-width: 600px) {
            .rconfirm-share-form { grid-template-columns: 1fr; }
            .rconfirm-field:first-child { grid-column: auto; }
        }
        .rm { position: relative; }
        .rcopy {
            position: absolute;
            top: 8px;
            right: 8px;
            width: 24px;
            height: 24px;
            line-height: 1;
            border: none;
            background: transparent;
            color: var(--color-text-maxcontrast, #888);
            border-radius: 6px;
            font-size: 13px;
            cursor: pointer;
            opacity: 0;
            transition: opacity .12s;
        }
        .rm:hover .rcopy { opacity: 1; }
        .rcopy:hover { background: var(--color-background-hover, #e5e5e5); }
        .form { display: flex; gap: 8px; align-items: center; padding: 8px; border: 1px solid var(--color-border, #ddd); border-radius: 12px; background: var(--color-main-background, #fff); }
        .form input {
            flex: 1; min-width: 0; padding: 10px 12px; border: 1px solid transparent; border-radius: 8px;
            font-size: 14px; color: var(--color-main-text, #111); background: transparent;
        }
        .form input:focus { border-color: var(--color-primary-element, #00679c); outline: none; background: var(--color-background-hover, #f1f2f4); }
        .form button { padding: 10px 18px; border: 0; border-radius: 8px; background: var(--color-primary-element, #00679c); color: var(--color-primary-element-text, #fff); font-size: 14px; font-weight: 600; cursor: pointer; }
        .form button:disabled { opacity: .6; cursor: default; }
        .err { color: var(--color-error, #e9322d); font-size: 13px; margin: 8px 4px 0; white-space: pre-wrap; }
        @media (max-width: 600px) {
            #content { padding: 18px 12px 20px; }
            .head { align-items: flex-start; flex-direction: column; }
            .head-right { width: 100%; justify-content: flex-end; }
            .rb { max-width: 94%; }
        }
    </style>
</head>
<body>
    <div id="topbar">
        <a class="brand" href="<?php echo htmlspecialchars(\OC::$WEBROOT . '/apps/eva_ai/', ENT_QUOTES); ?>">
            <img class="logo" src="<?php echo htmlspecialchars(\OC::$WEBROOT . '/apps/eva_ai/img/eva-icon.svg', ENT_QUOTES); ?>" alt="EVA">
            <span>EVA</span>
        </a>
        <div class="spacer"></div>
        <a class="toplink" href="<?php echo htmlspecialchars(\OC::$WEBROOT . '/', ENT_QUOTES); ?>">Back to overview</a>
    </div>

    <div id="layout">
        <nav id="sidebar">
            <button id="newchat" class="nav-new">+ New chat</button>
            <div id="chatlist"></div>
            <div class="sidebar-spacer"></div>
            <a class="nav-item" href="<?php echo htmlspecialchars(\OC::$WEBROOT . '/apps/eva_ai/documents', ENT_QUOTES); ?>">
                <span class="nav-ico">📄</span> Documents
            </a>
            <a class="nav-item" href="<?php echo htmlspecialchars(\OC::$WEBROOT . '/apps/eva_ai/settings', ENT_QUOTES); ?>">
                <span class="nav-ico">⚙️</span> Settings
            </a>
            <div class="sidebar-sep"></div>
            <div style="font-size:12px;color:var(--color-text-maxcontrast,#666);padding:4px 12px;">
                EVA · <span id="badge-version">standalone</span>
            </div>
        </nav>

        <div id="content">
            <div class="head">
                <div class="head-left">
                    <h1>Chat with your files</h1>
                </div>
                <div class="head-right">
                    <button id="export" class="export-btn" title="Download this chat as Markdown" disabled>&#11015; Export</button>
                    <span class="badge">eva_ai</span>
                </div>
            </div>

            <div id="msgs">
                <div class="empty" id="empty">
                    <div class="ico">💬</div>
                    <div class="t">Ask a question about your files</div>
                    <div class="d">Ask about notes, plans or files — I can even create files, write notes and remember personal facts in a KNOWLEDGE.md.</div>
                </div>
            </div>

            <form class="form" id="form">
                <input id="q" type="text" autocomplete="off" placeholder="What does my note about X say?">
                <button type="submit" id="send">Send</button>
            </form>
            <div class="err" id="err" style="display:none;"></div>
        </div>
    </div>
</body>
</html>