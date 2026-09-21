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
        #sidebar .nav-ico { display: block; flex: none; width: 20px; height: 20px; }
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
        .empty .ico { display: grid; place-items: center; color: var(--color-text-maxcontrast, #666); }
        .empty .ico svg { display: block; width: 36px; height: 36px; }
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
        #chatlist-error { margin: 8px 4px; padding: 10px; border: 1px solid color-mix(in srgb, var(--color-error, #c00) 35%, transparent); border-radius: 8px; color: var(--color-error, #c00); font-size: 12px; line-height: 1.45; overflow-wrap: anywhere; }
        #chatlist-error[hidden] { display: none !important; }
        #chatlist-error button { display: block; margin-top: 8px; padding: 4px 8px; border: 1px solid var(--color-border, #ccc); border-radius: 5px; background: var(--color-main-background, #fff); color: var(--color-main-text, #222); cursor: pointer; font: inherit; }
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
        .chat-entry .x:focus-visible { outline: 2px solid var(--color-primary-element, #00679c); outline-offset: 2px; }
        .chat-entry .x svg { display: block; width: 16px; height: 16px; }
        .sidebar-spacer { flex: 1; }
        .chat-empty { font-size: 12px; color: var(--color-text-maxcontrast, #666); padding: 8px 12px; }
        .rth { margin-bottom: 8px; font-size: 12px; }
        .rth summary { cursor: pointer; color: #555; font-weight: 600; user-select: none; }
        .rth-c { margin-top: 6px; padding: 8px 10px; background: #eef1f4; border-radius: 6px; white-space: pre-wrap; word-break: break-word; color: #555; font-size: 12px; line-height: 1.5; max-height: 220px; overflow-y: auto; }
        .rs { margin-top: 6px; font-size: 12px; color: var(--color-main-text, #333); }
        .rs-sum { cursor: pointer; font-weight: 600; user-select: none; }
        .rs-list { margin-top: 4px; padding: 6px 10px; background: var(--color-background-dark, #f3f3f3); border-radius: 6px; }
        .rs-item { margin-bottom: 6px; }
        .rs-item a { color: var(--color-primary-element, #00679c); text-decoration: none; }
        /* A source with nothing to open is named, not linked, so it must not look
           like a link. */
        .rs-item .rs-plain { color: var(--color-main-text, #222); }
        .rs-excerpt { margin-top: 2px; padding: 4px 8px; background: var(--color-main-background, #fff); border-radius: 4px; font-size: 11px; line-height: 1.4; white-space: pre-wrap; word-break: break-word; color: var(--color-text-maxcontrast, #555); }
        .rs-badge { display: inline-block; margin-right: 6px; padding: 1px 6px; border-radius: 8px; background: var(--color-primary-element-light, #e5f0f7); color: var(--color-primary-element, #00679c); font-size: 10px; font-weight: 700; text-transform: uppercase; letter-spacing: .04em; vertical-align: 1px; }
        .rs-host { display: block; color: var(--color-text-maxcontrast, #555); font-size: 11px; }
        .rtools { margin-top: 6px; display: flex; flex-direction: column; gap: 4px; max-width: 86%; }
        .rtools .tool { font-size: 12px; padding: 4px 10px; border-radius: 6px; background: var(--color-background-dark, #eef1f4); color: var(--color-text-maxcontrast, #555); font-family: var(--font-family-monospace, monospace); }
        .rtools .tool.running { color: #8a6d1a; }
        .rtools .tool.ok { color: #2f8f3f; }
        .rtools .tool.bad { color: var(--color-error, #e9322d); }
        .rtools details { display: inline-block; margin-left: 6px; font-family: var(--font-family-sans-serif, sans-serif); }
        .rtools summary { cursor: pointer; color: var(--color-primary-element, #00679c); font-size: 11px; }
        .rtools pre { margin: 4px 0 0; padding: 6px 8px; max-width: min(720px, 80vw); max-height: 180px; overflow: auto; white-space: pre-wrap; word-break: break-word; border: 1px solid var(--color-border, #ddd); border-radius: 4px; background: var(--color-main-background, #fff); color: var(--color-main-text, #222); font: 11px/1.4 var(--font-family-monospace, monospace); }
        .rtools .tool-error { margin: 3px 0 0 18px; color: var(--color-error, #e9322d); font-family: var(--font-family-sans-serif, sans-serif); font-size: 11px; white-space: pre-wrap; word-break: break-word; }
        .rfu { display: flex; flex-wrap: wrap; gap: 6px; margin-top: 8px; }
        .rfu-btn { padding: 4px 10px; border: 1px solid var(--color-border, #ccc); border-radius: 14px; background: var(--color-background-hover, #f6f7f8); color: var(--color-main-text, #222); font: inherit; font-size: 12px; cursor: pointer; transition: background .15s; }
        .rfu-btn:hover { background: var(--color-primary-element, #00679c); color: #fff; border-color: var(--color-primary-element, #00679c); }
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
            display: inline-flex; align-items: center; gap: 6px;
            border: 1px solid var(--color-border, #ccc);
            background: var(--color-main-background, #fff);
            color: var(--color-main-text, #111);
            border-radius: 6px;
            padding: 5px 10px;
            font-size: 12px;
            cursor: pointer;
        }
        .export-icon { display: block; width: 15px; height: 15px; }
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
        .form button.stop { background: var(--color-error, #e9322d); }
        .err { color: var(--color-error, #e9322d); font-size: 13px; margin: 8px 4px 0; white-space: pre-wrap; }
        .chat-dialog-backdrop { position: fixed; inset: 0; z-index: 100; display: flex; align-items: center; justify-content: center; padding: 20px; background: rgba(0, 0, 0, .48); }
        .chat-dialog-backdrop[hidden] { display: none !important; }
        .chat-dialog { width: min(100%, 440px); padding: 22px; border: 1px solid var(--color-border, #ddd); border-radius: 12px; background: var(--color-main-background, #fff); color: var(--color-main-text, #222); box-shadow: 0 12px 40px rgba(0, 0, 0, .22); }
        .chat-dialog h2 { margin: 0 0 10px; font-size: 18px; }
        .chat-dialog p { margin: 0; line-height: 1.5; overflow-wrap: anywhere; }
        .chat-dialog-actions { display: flex; justify-content: flex-end; gap: 8px; margin-top: 22px; }
        .chat-dialog-actions button { min-height: 38px; padding: 7px 14px; border: 1px solid var(--color-border, #ccd0d4); border-radius: 7px; background: var(--color-main-background, #fff); color: var(--color-main-text, #222); font: inherit; cursor: pointer; }
        .chat-dialog-actions .danger { border-color: var(--color-error, #c00); background: var(--color-error, #c00); color: var(--color-primary-element-text, #fff); font-weight: 600; }
        .chat-dialog-actions button:focus-visible { outline: 2px solid var(--color-primary-element, #00679c); outline-offset: 2px; }
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
            <div id="chatlist-error" role="alert" hidden><span id="chatlist-error-message"></span><button id="chatlist-retry" type="button">Try again</button></div>
            <div class="sidebar-spacer"></div>
            <a class="nav-item" href="<?php echo htmlspecialchars(\OC::$WEBROOT . '/apps/eva_ai/documents', ENT_QUOTES); ?>">
                <svg class="nav-ico" viewBox="0 0 24 24" aria-hidden="true"><path d="M6 2h8l6 6v14H6a2 2 0 0 1-2-2V4a2 2 0 0 1 2-2zm7 2v5h5l-5-5zM7 13h10v2H7v-2zm0 4h10v2H7v-2z" fill="currentColor"/></svg> Documents
            </a>
            <a class="nav-item" href="<?php echo htmlspecialchars(\OC::$WEBROOT . '/apps/eva_ai/settings', ENT_QUOTES); ?>">
                <svg class="nav-ico" viewBox="0 0 24 24" aria-hidden="true"><path d="M19.4 13a7.8 7.8 0 0 0 0-2l2-1.5-2-3.5-2.4 1a7.5 7.5 0 0 0-1.7-1L15 3h-6l-.4 3a7.5 7.5 0 0 0-1.7 1l-2.4-1-2 3.5 2 1.5a7.8 7.8 0 0 0 0 2l-2 1.5 2 3.5 2.4-1a7.5 7.5 0 0 0 1.7 1L9 21h6l.4-3a7.5 7.5 0 0 0 1.7-1l2.4 1 2-3.5-2.1-1.5zM12 15.5a3.5 3.5 0 1 1 0-7 3.5 3.5 0 0 1 0 7z" fill="currentColor"/></svg> Settings
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
                    <button id="export" class="export-btn" title="Download this chat as Markdown" disabled><svg class="export-icon" viewBox="0 0 24 24" aria-hidden="true"><path d="M5 20h14v-2H5v2zM11 2v11.17l-4.59-4.58L5 10l7 7 7-7-1.41-1.41L13 13.17V2h-2z" fill="currentColor"/></svg><span id="export-label">Export</span></button>
                    <span class="badge">eva_ai</span>
                </div>
            </div>

            <div id="msgs">
                <div class="empty" id="empty">
                    <div class="ico" aria-hidden="true"><svg viewBox="0 0 24 24" fill="none" stroke="currentColor" stroke-width="1.6" stroke-linecap="round" stroke-linejoin="round"><path d="M21 11.5a8.4 8.4 0 0 1-.9 3.8 8.5 8.5 0 0 1-7.6 4.7 8.4 8.4 0 0 1-3.8-.9L3 21l1.9-5.7a8.4 8.4 0 0 1-.9-3.8A8.5 8.5 0 0 1 8.7 3.9a8.4 8.4 0 0 1 3.8-.9h.5a8.5 8.5 0 0 1 8 8z"/></svg></div>
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
    <div id="chat-confirm" class="chat-dialog-backdrop" hidden>
        <section class="chat-dialog" role="alertdialog" aria-modal="true" aria-labelledby="chat-confirm-title" aria-describedby="chat-confirm-message">
            <h2 id="chat-confirm-title">Delete chat</h2>
            <p id="chat-confirm-message"></p>
            <div class="chat-dialog-actions">
                <button id="chat-confirm-cancel" type="button">Cancel</button>
                <button id="chat-confirm-submit" class="danger" type="button">Delete chat</button>
            </div>
        </section>
    </div>
</body>
</html>
