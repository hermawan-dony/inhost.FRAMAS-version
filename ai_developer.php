<?php
/**
 * Asisten AI (Adek / Dd)
 * Asisten Pribadi Mas Wawan untuk Pemeliharaan & Pengembangan CEISA 4.0 / Inhost ERP
 * Eksklusif untuk Super Administrator ('dp')
 */
session_start();
header("Cache-Control: no-store, no-cache, must-revalidate, max-age=0");
header("Cache-Control: post-check=0, pre-check=0", false);
header("Pragma: no-cache");
require_once __DIR__ . '/koneksi.php';

// Proteksi Keamanan Akses Eksklusif User 'dp'
$isDpUser = (!empty($_SESSION['user_id']) && strtolower(trim($_SESSION['user_id'])) === 'dp');

if (!$isDpUser) {
    http_response_code(403);
    die('<div style="font-family:sans-serif;padding:50px;text-align:center;color:#e53e3e;">
        <h2>403 - Akses Ditolak</h2>
        <p>Halaman ini dikhususkan secara eksklusif untuk akun dp.</p>
    </div>');
}

$userDisplayName = $_SESSION['user_name'] ?? 'Super Administrator';
?>
<!DOCTYPE html>
<html lang="id">
<head>
    <meta charset="UTF-8">
    <meta name="viewport" content="width=device-width, initial-scale=1.0">
    <title>Asisten AI</title>
    <link rel="stylesheet" href="https://cdnjs.cloudflare.com/ajax/libs/font-awesome/6.4.0/css/all.min.css">
    <style>
        :root {
            --bg-canvas: #0f172a;
            --bg-card: #1e293b;
            --bg-chat-user: #1e3a8a;
            --bg-chat-ai: #1e293b;
            --border-color: #334155;
            --text-primary: #f8fafc;
            --text-muted: #94a3b8;
            --accent-green: #10b981;
            --accent-blue: #3b82f6;
            --accent-cyan: #06b6d4;
        }

        body {
            background-color: var(--bg-canvas);
            color: var(--text-primary);
            font-family: -apple-system, BlinkMacSystemFont, "Segoe UI", Roboto, Helvetica, Arial, sans-serif;
            margin: 0;
            padding: 0;
            height: 100vh;
            display: flex;
            flex-direction: column;
            overflow: hidden;
        }

        /* Top Bar Header */
        .asisten-header {
            background: #090d16;
            border-bottom: 1px solid var(--border-color);
            padding: 8px 18px;
            display: flex;
            align-items: center;
            justify-content: space-between;
            flex-wrap: wrap;
            gap: 10px;
        }

        .asisten-profile {
            display: flex;
            align-items: center;
            gap: 12px;
        }

        .asisten-avatar-head {
            width: 32px;
            height: 32px;
            border-radius: 50%;
            object-fit: cover;
            border: 2px solid #38bdf8;
            box-shadow: 0 0 8px rgba(56,189,248,0.4);
            cursor: pointer;
            transition: transform 0.2s;
        }
        .asisten-avatar-head:hover {
            transform: scale(1.06);
        }

        .asisten-title-wrap {
            display: flex;
            flex-direction: column;
        }

        .asisten-name {
            font-weight: 700;
            font-size: 14px;
            letter-spacing: 0.3px;
            color: #f8fafc;
            display: flex;
            align-items: center;
            gap: 8px;
        }

        .asisten-status-pill {
            display: inline-flex;
            align-items: center;
            gap: 5px;
            background: rgba(16, 185, 129, 0.15);
            color: var(--accent-green);
            padding: 2px 7px;
            border-radius: 12px;
            font-size: 9.5px;
            font-weight: 600;
            border: 1px solid rgba(16, 185, 129, 0.3);
        }

        .asisten-status-dot {
            width: 6px;
            height: 6px;
            background-color: var(--accent-green);
            border-radius: 50%;
            animation: pulse 2s infinite;
        }

        @keyframes pulse {
            0% { opacity: 0.4; }
            50% { opacity: 1; }
            100% { opacity: 0.4; }
        }

        .asisten-controls {
            display: flex;
            align-items: center;
            gap: 6px;
            flex-wrap: wrap;
        }

        .asisten-select {
            background: var(--bg-card);
            border: 1px solid var(--border-color);
            color: #cbd5e1;
            padding: 4px 8px;
            border-radius: 6px;
            font-size: 11px;
            font-weight: 500;
            outline: none;
            cursor: pointer;
        }
        .asisten-select:focus {
            border-color: var(--accent-blue);
        }

        .asisten-btn {
            background: var(--bg-card);
            border: 1px solid var(--border-color);
            color: #e2e8f0;
            padding: 4px 10px;
            border-radius: 6px;
            font-size: 11px;
            font-weight: 500;
            cursor: pointer;
            display: inline-flex;
            align-items: center;
            gap: 5px;
            text-decoration: none;
            transition: all 0.2s;
        }
        .asisten-btn:hover {
            background: #334155;
            color: #fff;
        }

        .asisten-btn-primary {
            background: #2563eb;
            border-color: #1d4ed8;
            color: #fff;
        }
        .asisten-btn-primary:hover {
            background: #1d4ed8;
        }

        .asisten-btn-danger {
            background: rgba(239, 68, 68, 0.15);
            border-color: rgba(239, 68, 68, 0.4);
            color: #f87171;
        }
        .asisten-btn-danger:hover {
            background: #dc2626;
            color: #fff;
        }

        /* Workspace Grid */
        .asisten-main-container {
            display: flex;
            flex: 1;
            overflow: hidden;
        }

        /* Chat Panel */
        .asisten-chat-pane {
            flex: 1;
            display: flex;
            flex-direction: column;
            border-right: 1px solid var(--border-color);
            background: var(--bg-canvas);
        }

        .asisten-messages {
            flex: 1;
            overflow-y: auto;
            padding: 14px 18px;
            display: flex;
            flex-direction: column;
            gap: 12px;
        }

        .asisten-msg {
            display: flex;
            gap: 10px;
            max-width: 90%;
        }

        .asisten-msg.user {
            align-self: flex-end;
            flex-direction: row-reverse;
        }

        .asisten-msg-avatar {
            width: 32px;
            height: 32px;
            border-radius: 50%;
            display: flex;
            align-items: center;
            justify-content: center;
            font-size: 11.5px;
            font-weight: 700;
            flex-shrink: 0;
        }

        .asisten-msg.user .asisten-msg-avatar {
            background: #3b82f6;
            color: #fff;
            border: 1.5px solid rgba(255, 255, 255, 0.2);
        }

        .asisten-msg.ai .asisten-msg-avatar img {
            width: 32px;
            height: 32px;
            border-radius: 50%;
            object-fit: cover;
            border: 2px solid #38bdf8;
            box-shadow: 0 0 6px rgba(56, 189, 248, 0.3);
        }

        .asisten-msg-bubble {
            background: var(--bg-chat-ai);
            border: 1px solid var(--border-color);
            padding: 9px 13px;
            border-radius: 10px;
            font-size: 12.5px;
            line-height: 1.55;
            color: #e2e8f0;
            position: relative;
        }

        .asisten-msg.user .asisten-msg-bubble {
            background: var(--bg-chat-user);
            border-color: #2563eb;
            color: #fff;
        }

        .user-attached-thumb {
            display: block;
            max-height: 130px;
            border-radius: 6px;
            cursor: pointer;
            margin-bottom: 6px;
            border: 1px solid rgba(255, 255, 255, 0.2);
            transition: transform 0.2s;
        }
        .user-attached-thumb:hover {
            transform: scale(1.02);
            border-color: #60a5fa;
        }

        .asisten-step-log {
            background: #090d16;
            border: 1px solid #1e293b;
            border-radius: 6px;
            padding: 5px 10px;
            margin: 4px 0;
            font-family: "JetBrains Mono", Consolas, monospace;
            font-size: 10.5px;
            color: #38bdf8;
            display: flex;
            align-items: center;
            gap: 6px;
        }

        .asisten-step-log.success {
            color: #4ade80;
        }

        .asisten-step-log.error {
            color: #f87171;
            background: rgba(239, 68, 68, 0.1);
        }

        /* Live Single Dynamic Loader (Pengganti Log Berulang) */
        .asisten-live-loader {
            display: flex;
            flex-direction: column;
            gap: 7px;
            padding: 2px 0;
        }

        .asisten-loader-heart {
            color: #f472b6;
            font-style: italic;
            font-size: 12.5px;
            display: flex;
            align-items: center;
        }

        .asisten-loader-active-step {
            display: inline-flex;
            align-items: center;
            gap: 8px;
            background: rgba(15, 23, 42, 0.75);
            border: 1px solid rgba(56, 189, 248, 0.3);
            border-radius: 6px;
            padding: 5px 10px;
            font-family: "JetBrains Mono", Consolas, monospace;
            font-size: 11px;
            color: #38bdf8;
            box-shadow: 0 2px 8px rgba(0, 0, 0, 0.25);
            width: fit-content;
            max-width: 100%;
            animation: fadeInStep 0.2s ease-in-out;
        }

        @keyframes fadeInStep {
            from { opacity: 0.5; transform: translateY(-2px); }
            to { opacity: 1; transform: translateY(0); }
        }

        .asisten-spinner {
            color: #06b6d4;
            font-size: 12px;
        }

        .asisten-loader-target {
            color: #fef08a;
            font-weight: 600;
            background: rgba(254, 240, 138, 0.12);
            padding: 1px 6px;
            border-radius: 4px;
            border: 1px solid rgba(254, 240, 138, 0.2);
            display: inline-block;
        }

        .asisten-msg-content {
            font-size: 12.5px;
            line-height: 1.55;
            color: #e2e8f0;
        }

        /* Textbox Terminal Panel Khusus (Log Aktivitas Adek Tanpa Mengotori Chat) */
        .asisten-terminal-panel {
            background: #050811;
            border-top: 1px solid var(--border-color);
            display: flex;
            flex-direction: column;
            transition: height 0.25s cubic-bezier(0.4, 0, 0.2, 1);
            height: 145px;
            flex-shrink: 0;
            position: relative;
            z-index: 5;
        }

        .asisten-terminal-panel.collapsed {
            height: 32px;
        }

        .asisten-terminal-header {
            height: 32px;
            background: #090d16;
            padding: 0 12px;
            display: flex;
            align-items: center;
            justify-content: space-between;
            border-bottom: 1px solid var(--border-color);
            font-size: 11px;
            user-select: none;
            cursor: pointer;
        }

        .asisten-terminal-title {
            display: flex;
            align-items: center;
            gap: 8px;
            color: #94a3b8;
            font-weight: 600;
        }

        .asisten-terminal-title i {
            color: #38bdf8;
        }

        .terminal-badge-status {
            font-size: 9px;
            font-weight: 700;
            padding: 1px 6px;
            border-radius: 10px;
            background: rgba(100, 116, 139, 0.2);
            color: #94a3b8;
            border: 1px solid rgba(100, 116, 139, 0.3);
            letter-spacing: 0.5px;
        }

        .terminal-badge-status.running {
            background: rgba(14, 165, 233, 0.2);
            color: #38bdf8;
            border-color: rgba(14, 165, 233, 0.4);
            animation: pulse 1.5s infinite;
        }

        .terminal-badge-status.done {
            background: rgba(34, 197, 94, 0.2);
            color: #4ade80;
            border-color: rgba(34, 197, 94, 0.4);
        }

        .terminal-badge-status.error {
            background: rgba(239, 68, 68, 0.2);
            color: #f87171;
            border-color: rgba(239, 68, 68, 0.4);
        }

        .asisten-terminal-actions {
            display: flex;
            align-items: center;
            gap: 6px;
        }

        .asisten-btn-tiny {
            background: rgba(255, 255, 255, 0.05);
            border: 1px solid rgba(255, 255, 255, 0.1);
            color: #94a3b8;
            padding: 2px 7px;
            border-radius: 4px;
            font-size: 10px;
            cursor: pointer;
            display: inline-flex;
            align-items: center;
            gap: 4px;
            transition: all 0.15s;
        }

        .asisten-btn-tiny:hover {
            background: rgba(255, 255, 255, 0.15);
            color: #fff;
        }

        .asisten-terminal-body {
            flex: 1;
            overflow-y: auto;
            padding: 8px 12px;
            font-family: "JetBrains Mono", Consolas, "Courier New", monospace;
            font-size: 11px;
            line-height: 1.55;
            background: #050811;
            color: #cbd5e1;
        }

        .terminal-line-placeholder {
            color: #475569;
            font-style: italic;
            padding: 4px 0;
        }

        .terminal-log-row {
            display: flex;
            align-items: baseline;
            gap: 8px;
            margin-bottom: 2px;
            word-break: break-all;
        }

        .terminal-log-time {
            color: #64748b;
            font-size: 10px;
            flex-shrink: 0;
            font-family: inherit;
        }

        .terminal-log-tag {
            padding: 1px 5px;
            border-radius: 3px;
            font-size: 9.5px;
            font-weight: 700;
            flex-shrink: 0;
            font-family: inherit;
        }

        .terminal-log-tag.read {
            background: rgba(56, 189, 248, 0.15);
            color: #38bdf8;
            border: 1px solid rgba(56, 189, 248, 0.3);
        }

        .terminal-log-tag.write {
            background: rgba(245, 158, 11, 0.15);
            color: #fbbf24;
            border: 1px solid rgba(245, 158, 11, 0.3);
        }

        .terminal-log-tag.cmd {
            background: rgba(168, 85, 247, 0.15);
            color: #c084fc;
            border: 1px solid rgba(168, 85, 247, 0.3);
        }

        .terminal-log-tag.search {
            background: rgba(20, 184, 166, 0.15);
            color: #2dd4bf;
            border: 1px solid rgba(20, 184, 166, 0.3);
        }

        .terminal-log-tag.info {
            background: rgba(100, 116, 139, 0.2);
            color: #94a3b8;
        }

        .terminal-log-tag.error {
            background: rgba(239, 68, 68, 0.2);
            color: #f87171;
            border: 1px solid rgba(239, 68, 68, 0.4);
        }

        .terminal-log-text {
            color: #e2e8f0;
        }

        .terminal-log-target {
            color: #fef08a;
            font-weight: 600;
        }

        pre.code-block {
            background: #090d16;
            border: 1px solid #334155;
            padding: 8px 12px;
            border-radius: 6px;
            overflow-x: auto;
            font-family: "JetBrains Mono", Consolas, monospace;
            font-size: 11px;
            color: #a5f3fc;
        }

        /* Image Preview Bar */
        .asisten-image-preview-bar {
            display: none;
            align-items: center;
            gap: 10px;
            background: rgba(30, 41, 59, 0.95);
            border: 1px dashed var(--accent-blue);
            border-radius: 8px;
            padding: 6px 10px;
            margin-bottom: 6px;
        }

        .asisten-preview-thumb {
            width: 44px;
            height: 44px;
            object-fit: cover;
            border-radius: 6px;
            border: 1px solid var(--border-color);
            background: #090d16;
            cursor: pointer;
        }

        .asisten-preview-filebox {
            width: 44px;
            height: 44px;
            border-radius: 6px;
            background: rgba(59, 130, 246, 0.15);
            border: 1px solid rgba(59, 130, 246, 0.3);
            display: inline-flex;
            align-items: center;
            justify-content: center;
            font-size: 20px;
            color: #60a5fa;
            flex-shrink: 0;
        }

        .asisten-preview-info {
            flex: 1;
            font-size: 11.5px;
            color: #e2e8f0;
        }

        .asisten-preview-size {
            font-size: 10px;
            color: var(--text-muted);
            margin-top: 2px;
        }

        .asisten-preview-remove {
            background: rgba(239, 68, 68, 0.2);
            border: 1px solid rgba(239, 68, 68, 0.5);
            color: #f87171;
            width: 24px;
            height: 24px;
            border-radius: 50%;
            display: flex;
            align-items: center;
            justify-content: center;
            cursor: pointer;
            font-size: 11px;
            transition: all 0.2s;
        }
        .asisten-preview-remove:hover {
            background: #dc2626;
            color: #fff;
        }

        /* Input Bar */
        .asisten-input-bar {
            background: #090d16;
            border-top: 1px solid var(--border-color);
            padding: 10px 16px;
            display: flex;
            flex-direction: column;
            gap: 6px;
        }

        .asisten-textarea-wrapper {
            display: flex;
            gap: 8px;
            align-items: flex-end;
            background: var(--bg-card);
            border: 1px solid var(--border-color);
            border-radius: 8px;
            padding: 6px 10px;
        }

        .asisten-textarea-wrapper:focus-within {
            border-color: var(--accent-blue);
        }

        .asisten-textarea {
            flex: 1;
            background: transparent;
            border: none;
            color: #fff;
            font-size: 12.5px;
            outline: none;
            resize: none;
            height: 40px;
            max-height: 140px;
            font-family: inherit;
        }

        .asisten-shortcuts-hint {
            display: flex;
            justify-content: space-between;
            align-items: center;
            font-size: 10px;
            color: var(--text-muted);
        }

        .asisten-quick-pills {
            display: flex;
            gap: 5px;
            flex-wrap: wrap;
        }

        .asisten-pill {
            background: rgba(255, 255, 255, 0.05);
            border: 1px solid rgba(255, 255, 255, 0.1);
            color: #cbd5e1;
            padding: 2px 7px;
            border-radius: 4px;
            font-size: 10px;
            cursor: pointer;
            transition: all 0.15s;
        }
        .asisten-pill:hover {
            background: rgba(59, 130, 246, 0.2);
            color: #60a5fa;
            border-color: rgba(59, 130, 246, 0.4);
        }

        /* Modal Styles */
        .asisten-modal {
            display: none;
            position: fixed;
            top: 0; left: 0; right: 0; bottom: 0;
            background: rgba(0,0,0,0.75);
            z-index: 9999;
            align-items: center;
            justify-content: center;
            backdrop-filter: blur(4px);
        }
        .asisten-modal.show {
            display: flex;
        }
        .asisten-modal-content {
            background: #1e293b;
            border: 1px solid var(--border-color);
            border-radius: 10px;
            width: 90%;
            max-width: 500px;
            box-shadow: 0 20px 25px -5px rgba(0, 0, 0, 0.5);
            overflow: hidden;
        }
        .asisten-modal-header {
            padding: 14px 20px;
            border-bottom: 1px solid var(--border-color);
            display: flex;
            justify-content: space-between;
            align-items: center;
        }

        /* Quota & Limit Modal Styles */
        .quota-modal-content {
            background: #111827;
            border: 1px solid #374151;
            border-radius: 12px;
            width: 94%;
            max-width: 640px;
            box-shadow: 0 25px 50px -12px rgba(0, 0, 0, 0.75);
            overflow: hidden;
            color: #f3f4f6;
            animation: modalFadeIn 0.2s ease-out;
        }
        @keyframes modalFadeIn {
            from { opacity: 0; transform: scale(0.96); }
            to { opacity: 1; transform: scale(1); }
        }
        .quota-modal-body {
            padding: 18px 20px;
            max-height: 80vh;
            overflow-y: auto;
        }
        .quota-account-pill {
            display: inline-flex;
            align-items: center;
            gap: 7px;
            background: rgba(59, 130, 246, 0.12);
            border: 1px solid rgba(59, 130, 246, 0.3);
            color: #93c5fd;
            padding: 5px 12px;
            border-radius: 20px;
            font-size: 11.5px;
            font-weight: 600;
            margin-bottom: 16px;
        }
        .quota-group {
            margin-bottom: 20px;
        }
        .quota-group:last-child {
            margin-bottom: 0;
        }
        /* Gemini — tampil lebih besar & prominent */
        .quota-group-gemini {
            margin-bottom: 22px;
            background: linear-gradient(135deg, rgba(16,185,129,0.06) 0%, rgba(56,189,248,0.06) 100%);
            border: 1px solid rgba(16,185,129,0.18);
            border-radius: 12px;
            padding: 14px 16px;
        }
        .quota-card-gemini {
            background: linear-gradient(135deg, #1a2e26 0%, #1a2535 100%);
            border: 1px solid rgba(16,185,129,0.28);
        }
        .quota-card-gemini:hover {
            border-color: #10b981;
        }
        .quota-ring-wrap-lg {
            width: 82px;
            height: 82px;
            flex-shrink: 0;
            position: relative;
            display: flex;
            align-items: center;
            justify-content: center;
        }
        .quota-ring-svg-lg {
            width: 82px;
            height: 82px;
            transform: rotate(-90deg);
        }
        .quota-ring-text-lg {
            position: absolute;
            font-size: 15px;
            font-weight: 800;
            color: #f9fafb;
            font-family: inherit;
        }
        .quota-group-title {
            font-size: 13px;
            font-weight: 700;
            color: #f3f4f6;
            margin-bottom: 10px;
            display: flex;
            align-items: center;
            gap: 8px;
            letter-spacing: 0.3px;
        }
        .quota-cards-grid {
            display: grid;
            grid-template-columns: repeat(auto-fit, minmax(260px, 1fr));
            gap: 12px;
        }
        .quota-card {
            background: #1f2937;
            border: 1px solid #374151;
            border-radius: 10px;
            padding: 14px;
            display: flex;
            align-items: center;
            gap: 14px;
            transition: all 0.2s;
        }
        .quota-card:hover {
            border-color: #4b5563;
            background: #232d3f;
        }
        .quota-ring-wrap {
            position: relative;
            width: 58px;
            height: 58px;
            flex-shrink: 0;
            display: flex;
            align-items: center;
            justify-content: center;
        }
        .quota-ring-svg {
            width: 58px;
            height: 58px;
            transform: rotate(-90deg);
        }
        .quota-ring-bg {
            fill: none;
            stroke: #374151;
            stroke-width: 4.5;
        }
        .quota-ring-bar {
            fill: none;
            stroke-width: 4.5;
            stroke-linecap: round;
            transition: stroke-dashoffset 0.6s ease;
        }
        .quota-ring-text {
            position: absolute;
            font-size: 12px;
            font-weight: 700;
            color: #f9fafb;
            font-family: inherit;
        }
        .quota-info {
            flex: 1;
            min-width: 0;
        }
        .quota-name {
            font-size: 12.5px;
            font-weight: 700;
            color: #f9fafb;
            margin-bottom: 3px;
            line-height: 1.25;
        }
        .quota-desc {
            font-size: 11px;
            color: #9ca3af;
            line-height: 1.35;
        }
        .quota-reset-tag {
            display: inline-block;
            margin-top: 4px;
            font-size: 10px;
            color: #38bdf8;
            font-weight: 500;
        }
        .quota-models-detail {
            margin-top: 16px;
            border-top: 1px dashed #374151;
            padding-top: 14px;
        }
        .quota-model-item {
            display: flex;
            align-items: center;
            justify-content: space-between;
            padding: 6px 10px;
            background: rgba(31, 41, 55, 0.5);
            border-radius: 6px;
            margin-bottom: 6px;
            font-size: 11.5px;
        }
        .quota-model-name {
            font-weight: 600;
            color: #e5e7eb;
        }
        .quota-model-bar-wrap {
            width: 90px;
            height: 7px;
            background: #374151;
            border-radius: 4px;
            overflow: hidden;
            margin-right: 8px;
        }
        .quota-model-bar-inner {
            height: 100%;
            border-radius: 4px;
            transition: width 0.4s ease;
        }
    </style>
</head>
<body>

    <!-- Header / Top Bar -->
    <header class="asisten-header">
        <div class="asisten-profile">
            <img src="/assets/asisten_adek.jpg" alt="Adek" class="asisten-avatar-head" onclick="openLightbox(this.src)" title="Adek - Asisten AI Pribadi Mas Wawan">
            <div class="asisten-title-wrap">
                <div class="asisten-name">
                    Asisten AI
                    <div class="asisten-status-pill">
                        <span class="asisten-status-dot"></span>
                        ONLINE
                    </div>
                </div>
            </div>
        </div>

        <div class="asisten-controls">
            <!-- Model Selector -->
            <div style="display:flex;align-items:center;gap:6px;font-size:11px;color:var(--text-muted);">
                <i class="fa-solid fa-brain"></i> Model:
                <select id="selModel" class="asisten-select" onchange="onModelChange()">
                    <option value="gemini-3.8-flash-high" selected>🔍 Gemini 3.8 Flash (Analisis Mendalam) - Default</option>
                    <option value="gemini-3.8-flash-medium">⚖️ Gemini 3.8 Flash (Standar)</option>
                    <option value="gemini-3.8-flash-low">⚡ Gemini 3.8 Flash (Respon Kilat)</option>
                    <option value="claude-sonnet-4-6">🧠 Claude 3.7 Sonnet (Reasoning)</option>
                    <option value="claude-opus-4-6-thinking">🏆 Claude Opus 4.6 (Deep Think)</option>
                </select>
            </div>

            <!-- Single Google Account Badge & Action -->
            <div style="display:flex;align-items:center;gap:6px;">
                <span id="accountBadge" style="font-size:11px;padding:3px 8px;border-radius:6px;display:inline-flex;align-items:center;gap:5px;background:rgba(59,130,246,0.15);color:#60a5fa;border:1px solid rgba(59,130,246,0.3);">
                    <i class="fa-solid fa-spinner fa-spin"></i> Memeriksa Akun...
                </span>
                <button id="btnAccountAction" class="asisten-btn" style="display:none;font-size:11px;padding:3px 8px;">
                </button>
            </div>

            <!-- Tombol Antrian Tiket Pengguna (Bugs & Requests) -->
            <button class="asisten-btn" onclick="openTicketQueueModal()" id="btnHeaderTickets" title="Lihat antrian tiket bug dan request yang diajukan pengguna" style="background:rgba(14,165,233,0.15); color:#38bdf8; border:1px solid rgba(14,165,233,0.3); font-size:11px; padding:3px 9px;">
                <i class="fa-solid fa-ticket"></i> Tiket Masuk
                <span id="aiTicketBadge" style="background:#ef4444; color:#fff; border-radius:10px; padding:1px 6px; font-size:9.5px; font-weight:700; display:none; margin-left:3px;">0</span>
            </button>

            <!-- Tombol Buka di Window Baru (Bebas Refresh Portal) -->
            <button class="asisten-btn" onclick="openInNewWindow()" title="Buka Asisten AI di Jendela / Tab Baru agar bebas refresh web portal">
                <i class="fa-solid fa-arrow-up-right-from-square" style="color:#60a5fa;"></i> Jendela Baru
            </button>

            <!-- Tombol Kuota & Limit Akun Google (Gemini, Claude, GPT) -->
            <button class="asisten-btn" onclick="openQuotaModal()" title="Cek Kuota & Limit Akun Google (Gemini, Claude, GPT)">
                <i class="fa-solid fa-chart-pie" style="color:#10b981;"></i> Kuota &amp; Limit
            </button>

            <!-- Tombol Stop Eksekusi (Aktif Saat Adek Sedang Berpikir/Bekerja) -->
            <button id="btnHeaderStop" class="asisten-btn asisten-btn-danger" style="display:none;font-weight:700;" onclick="stopExecution()" title="Hentikan proses Adek yang sedang berjalan">
                <i class="fa-solid fa-hand"></i> Stop
            </button>

            <!-- Tombol Toggle Textbox Terminal -->
            <button class="asisten-btn" onclick="toggleTerminalBox()" title="Buka / Sembunyikan Textbox Terminal Log">
                <i class="fa-solid fa-terminal" style="color:#38bdf8;"></i> Terminal (<span id="headerTerminalCount">0</span>)
            </button>

            <!-- Tombol Restart Engine (Kill Zombie & Reset Engine AI) -->
            <button id="btnRestartEngine" class="asisten-btn" onclick="restartEngine()" title="Kill proses yang ngadat/macet dan nyalakan ulang Engine AI">
                <i class="fa-solid fa-arrows-rotate" style="color:#f59e0b;"></i> Restart Engine
            </button>

            <!-- Bersihkan Riwayat Chat & File Gambar Sementara -->
            <button class="asisten-btn" onclick="clearSessionChat()" title="Bersihkan riwayat percakapan dan berkas sementara">
                <i class="fa-solid fa-broom"></i> Bersihkan Sesi
            </button>

            <!-- Rollback Button -->
            <button class="asisten-btn asisten-btn-danger" onclick="doRollback()" title="Revert perubahan terakhir via Git Stash">
                <i class="fa-solid fa-rotate-left"></i> Rollback
            </button>
        </div>
    </header>

    <!-- Main Workspace -->
    <div class="asisten-main-container">
        <div class="asisten-chat-pane">
            <!-- Messages Area (Memuat Riwayat Otomatis) -->
            <div class="asisten-messages" id="chatMessages">
                <!-- Welcome AI Message -->
                <div class="asisten-msg ai">
                    <div class="asisten-msg-avatar">
                        <img src="/assets/asisten_adek.jpg" alt="Adek">
                    </div>
                    <div class="asisten-msg-bubble">
                        <strong>Halo Mas Wawan!</strong> 👋<br>
                        Adek siap membantu Mas Wawan untuk koding, query database, perbaikan bug, atau analisis tangkapan layar langsung di sini.<br>
                        <div style="margin-top:6px;font-size:11.5px;color:var(--text-muted);">
                            • Model Aktif: <span id="lblActiveModel" style="color:#60a5fa;font-weight:600;">Gemini 3.8 Flash (Analisis Mendalam)</span><br>
                            • Otentikasi: <span id="lblAuthStatusText" style="color:#4ade80;font-weight:600;">Memeriksa otentikasi...</span>
                        </div>
                    </div>
                </div>
            </div>

            <!-- Textbox Terminal Panel Khusus (Aktivitas Adek Tanpa Mengotori Chat) -->
            <div id="asistenTerminalPanel" class="asisten-terminal-panel collapsed">
                <div class="asisten-terminal-header" onclick="toggleTerminalBox()">
                    <div class="asisten-terminal-title">
                        <i class="fa-solid fa-terminal"></i>
                        <span>Terminal &amp; Log Aktivitas Adek</span>
                        <span class="terminal-badge-status" id="terminalStatusBadge">IDLE</span>
                        <span style="font-size:10px;color:var(--text-muted);font-weight:normal;" id="terminalSummaryText">• 0 aktivitas</span>
                    </div>
                    <div class="asisten-terminal-actions" onclick="event.stopPropagation()">
                        <button type="button" class="asisten-btn-tiny" onclick="clearTerminalLogs()" title="Bersihkan log terminal">
                            <i class="fa-solid fa-trash-can"></i> Bersihkan
                        </button>
                        <button type="button" class="asisten-btn-tiny" onclick="toggleTerminalBox()" title="Buka / Tutup panel terminal">
                            <i class="fa-solid fa-chevron-up" id="terminalChevron"></i>
                        </button>
                    </div>
                </div>
                <div class="asisten-terminal-body" id="terminalLogBody">
                    <div class="terminal-line-placeholder">Terminal siap. Setiap proses pembacaan file, modifikasi kode, dan perintah terminal Adek akan dicatat di sini tanpa mengotori ruang obrolan... ✨</div>
                </div>
            </div>

            <!-- Input Bar -->
            <div class="asisten-input-bar">
                <div class="asisten-quick-pills">
                    <span style="font-size:10px;color:var(--text-muted);margin-right:4px;">Instruksi Cepat:</span>
                    <span class="asisten-pill" onclick="setPrompt('git status dan periksa apakah ada berkas yang dimodifikasi')">Git Status</span>
                    <span class="asisten-pill" onclick="setPrompt('git pull origin main dan sinkronkan kode terbaru dari GitHub')"><i class="fa-brands fa-github"></i> Git Pull</span>
                    <span class="asisten-pill" onclick="setPrompt('git add . && git commit -m &quot;Update via Asisten AI&quot; && git push origin main')"><i class="fa-brands fa-github"></i> Git Push</span>
                    <span class="asisten-pill" onclick="setPrompt('Tampilkan daftar tabel utama kepabeanan di database bc2faktur_inhost')">Daftar Tabel DB</span>
                    <span class="asisten-pill" onclick="setPrompt('Cek php -v dan status service nginx & php8.1-fpm')">Cek Service</span>
                    <span class="asisten-pill" onclick="setPrompt('/model gemini-3.8-flash-high')">🔍 Flash Analisis</span>
                    <span class="asisten-pill" onclick="setPrompt('/model claude-sonnet-4-6')">🧠 Sonnet</span>
                </div>

                <!-- Pratinjau Berkas / Gambar yang di-Paste / di-Unggah -->
                <div id="imagePreviewBar" class="asisten-image-preview-bar">
                    <img id="imgThumbPreview" class="asisten-preview-thumb" src="" alt="Thumbnail" onclick="openLightbox(this.src)" title="Klik untuk memperbesar gambar" style="display:none;">
                    <div id="boxFilePreview" class="asisten-preview-filebox" style="display:none;">
                        <i id="iconFilePreview" class="fa-solid fa-file"></i>
                    </div>
                    <div class="asisten-preview-info">
                        <div id="lblImgName" style="font-weight:600;white-space:nowrap;overflow:hidden;text-overflow:ellipsis;max-width:280px;">Berkas Terlampir</div>
                        <div id="lblImgSize" class="asisten-preview-size">0 KB</div>
                    </div>
                    <div class="asisten-preview-remove" onclick="removePendingImage()" title="Batalkan lampiran berkas">
                        <i class="fa-solid fa-xmark"></i>
                    </div>
                </div>

                <div class="asisten-textarea-wrapper">
                    <!-- Tombol Upload Berkas / Lampiran -->
                    <label for="fileUploadInput" class="asisten-btn" style="height:32px;padding:0 11px;cursor:pointer;display:inline-flex;align-items:center;justify-content:center;margin-bottom:0;" title="Lampirkan berkas (SQL, TXT, JSON, CSV, PDF, kode sumber, dll) atau gambar (bisa juga tekan Ctrl+V)">
                        <i class="fa-solid fa-paperclip" style="color:#60a5fa;font-size:14px;"></i>
                    </label>
                    <input type="file" id="fileUploadInput" style="display:none;" onchange="handleFileSelect(event)">

                    <textarea id="txtPrompt" class="asisten-textarea" placeholder="Ketik instruksi, lampirkan berkas, atau paste gambar (Ctrl+V) untuk Adek... (Tekan Enter untuk kirim)" onkeydown="handleKeyDown(event)"></textarea>

                    <button id="btnSend" class="asisten-btn asisten-btn-primary" style="height:32px;padding:0 14px;" onclick="sendPrompt()">
                        <i class="fa-solid fa-paper-plane"></i> Kirim
                    </button>
                </div>
            </div>
        </div>
    </div>

    <!-- Modal Login Google Auth -->
    <div id="modalDeviceCode" class="asisten-modal">
        <div class="asisten-modal-content" style="max-width: 520px; text-align: left;">
            <div class="asisten-modal-header">
                <h4 style="margin:0;font-size:15px;color:#fff;"><i class="fa-brands fa-google" style="color:#4285F4;"></i> Hubungkan Akun Google</h4>
                <button class="asisten-btn" onclick="cancelDeviceCode()"><i class="fa-solid fa-xmark"></i></button>
            </div>
            <div class="asisten-modal-body" style="padding: 20px;">
                <div style="font-size: 13px; color: #94a3b8; margin-bottom: 14px;">
                    Sistem menghubungkan akun Google resmi Mas Wawan langsung ke Asisten AI.
                </div>

                <div style="margin-bottom: 16px;">
                    <label style="font-size: 12px; color: #cbd5e1; font-weight: 600; display: block; margin-bottom: 6px;">
                        Langkah 1: Buka tautan otentikasi Google di bawah ini:
                    </label>
                    <a id="btnAuthUrl" href="#" target="_blank" class="asisten-btn asisten-btn-primary" style="width: 100%; justify-content: center; padding: 10px; font-weight: 600;">
                        <i class="fa-solid fa-arrow-up-right-from-square"></i> Buka Halaman Login Akun Google
                    </a>
                    <button type="button" class="asisten-btn" style="margin-top:6px;width:100%;justify-content:center;font-size:11px;padding:5px 8px;background:rgba(255,255,255,0.05);border:1px dashed #475569;" onclick="refreshAuthUrl()" title="Klik ini jika tautan sebelumnya sudah kedaluwarsa atau ingin mengambil tautan baru">
                        <i class="fa-solid fa-arrows-rotate" style="color:#f59e0b;"></i> Ambil Tautan Baru (Jika Tautan Sebelumnya Kedaluwarsa)
                    </button>
                </div>

                <div style="margin-bottom: 16px;">
                    <label style="font-size: 12px; color: #cbd5e1; font-weight: 600; display: block; margin-bottom: 6px;">
                        Langkah 2: Pilih Akun Google Mas Wawan, klik "Continue / Izinkan", lalu salin Kode Otorisasi yang muncul:
                    </label>
                    <input type="text" id="txtAuthCode" class="asisten-select" placeholder="Paste Authorization Code di sini (contoh: 4/0AbCdEf...)" style="width: 100%; font-family: monospace; font-size: 12px;">
                </div>

                <div id="authStatusText" style="font-size: 12px; color: #94a3b8; display: none; align-items: center; gap: 8px;">
                    <i class="fa-solid fa-spinner fa-spin"></i>
                    <span>Sedang memproses verifikasi kode ke Asisten AI...</span>
                </div>
            </div>
            <div style="padding: 14px 20px; border-top: 1px solid var(--border-color); display: flex; justify-content: space-between; align-items: center;">
                <button class="asisten-btn" onclick="cancelDeviceCode()">Batal</button>
                <button id="btnSubmitAuthCode" class="asisten-btn asisten-btn-primary" onclick="submitAuthCode()">
                    <i class="fa-solid fa-check"></i> Verifikasi & Hubungkan
                </button>
            </div>
        </div>
    </div>

    <!-- Modal Kuota & Limit Akun Google (Gemini, Claude, GPT) -->
    <div id="modalQuotaUsage" class="asisten-modal" onclick="closeQuotaModal()">
        <div class="quota-modal-content" onclick="event.stopPropagation()">
            <div class="asisten-modal-header" style="background:#1e293b; border-bottom:1px solid #374151;">
                <div style="display:flex;align-items:center;gap:8px;">
                    <i class="fa-solid fa-chart-pie" style="color:#10b981;font-size:16px;"></i>
                    <h4 style="margin:0;font-size:15px;color:#fff;font-weight:700;">Kuota &amp; Limit Model AI</h4>
                </div>
                <div style="display:flex;align-items:center;gap:8px;">
                    <button class="asisten-btn" onclick="loadQuotaUsage(true)" title="Segarkan data kuota">
                        <i class="fa-solid fa-arrows-rotate" id="iconRefreshQuota"></i> Segarkan
                    </button>
                    <button class="asisten-btn" onclick="closeQuotaModal()" title="Tutup">
                        <i class="fa-solid fa-xmark"></i>
                    </button>
                </div>
            </div>
            <div class="quota-modal-body" id="quotaModalBody">
                <div style="text-align:center;padding:36px;color:#94a3b8;">
                    <i class="fa-solid fa-spinner fa-spin" style="font-size:26px;color:#38bdf8;margin-bottom:12px;"></i>
                    <div style="font-size:13px;font-weight:600;color:#e2e8f0;">Menghubungi Google Cloud Code API...</div>
                    <div style="font-size:11px;color:#64748b;margin-top:4px;">Mengambil kuota realtime Gemini, Claude, dan GPT</div>
                </div>
            </div>
        </div>
    </div>

    <!-- Modal Antrian Tiket Masuk (Bug & Request Pengguna) -->
    <div id="modalTicketQueue" class="asisten-modal" onclick="closeTicketQueueModal()">
        <div class="quota-modal-content" onclick="event.stopPropagation()" style="max-width:750px;">
            <div class="asisten-modal-header" style="background:#0f172a; border-bottom:1px solid #334155; padding:10px 16px;">
                <div style="display:flex;align-items:center;gap:8px;">
                    <i class="fa-solid fa-ticket" style="color:#38bdf8;font-size:16px;"></i>
                    <h4 style="margin:0;font-size:14px;color:#fff;font-weight:700;">Antrian Tiket Masuk (Bugs &amp; Requests)</h4>
                    <span id="aiModalTicketCount" style="background:#0284c7; color:#fff; font-size:10px; padding:2px 8px; border-radius:10px; font-weight:700;">0 Tiket</span>
                </div>
                <div style="display:flex;align-items:center;gap:8px;">
                    <button class="asisten-btn" onclick="loadAiTicketQueue()" title="Segarkan Antrian">
                        <i class="fa-solid fa-rotate" id="iconRefreshTickets"></i> Segarkan
                    </button>
                    <button class="asisten-btn" onclick="closeTicketQueueModal()" title="Tutup">
                        <i class="fa-solid fa-xmark"></i>
                    </button>
                </div>
            </div>
            <div class="asisten-modal-body" style="padding:14px; max-height:75vh; overflow-y:auto;" id="ticketQueueListContainer">
                <div style="text-align:center; padding:20px; color:var(--text-muted);">Memuat antrian tiket...</div>
            </div>
        </div>
    </div>

    <!-- Modal Lightbox Pratinjau Gambar Penuh -->
    <div id="modalImageLightbox" class="asisten-modal" onclick="closeLightbox()">
        <div style="max-width:92vw;max-height:92vh;display:flex;flex-direction:column;align-items:center;" onclick="event.stopPropagation()">
            <img id="lightboxImg" src="" style="max-width:90vw;max-height:85vh;border-radius:8px;box-shadow:0 10px 40px rgba(0,0,0,0.9);object-fit:contain;border:1px solid #334155;">
            <div style="margin-top:10px;">
                <button class="asisten-btn" onclick="closeLightbox()"><i class="fa-solid fa-xmark"></i> Tutup Pratinjau</button>
            </div>
        </div>
    </div>

    <script>
        const API_URL = 'ai_agent/api.php';
        let currentModel = 'gemini-3.8-flash-high';
        let isExecuting = false;
        let currentEvtSource = null;
        let activeAiUi = null;
        const currentSessionId = 'main_session_dp';

        // Pending Attachment (Berkas File & Gambar)
        let pendingAttachment = null;
        let pendingImageBase64 = null;

        document.addEventListener('DOMContentLoaded', () => {
            // Muat model tersimpan dari localStorage atau default ke gemini-3.8-flash-high
            const savedModel = localStorage.getItem('asisten_ai_model') || 'gemini-3.8-flash-high';
            const selModel = document.getElementById('selModel');
            if (selModel) {
                selModel.value = savedModel;
                if (!selModel.value) {
                    selModel.value = 'gemini-3.8-flash-high';
                }
                currentModel = selModel.value;
                const lbl = document.getElementById('lblActiveModel');
                if (lbl && selModel.selectedOptions[0]) {
                    lbl.innerText = selModel.selectedOptions[0].text;
                }
            }
            loadGitStatus();
            checkAuthStatus();
            loadChatHistory();
            loadAiTicketBadge();
            setInterval(loadAiTicketBadge, 30000);

            // Intercept Clipboard Paste di mana saja di halaman
            window.addEventListener('paste', handlePasteEvent);
        });

        function openInNewWindow() {
            window.open('ai_developer.php', '_blank');
        }

        // =====================================================================
        // PERSISTENT CHAT HISTORY (MEMORI OBROLAN)
        // =====================================================================
        function loadChatHistory() {
            fetch(`${API_URL}?action=get_chat_history&session_id=${encodeURIComponent(currentSessionId)}`)
                .then(r => r.json())
                .then(d => {
                    if (d.status === 'OK' && Array.isArray(d.history) && d.history.length > 0) {
                        const container = document.getElementById('chatMessages');
                        // Bersihkan gelembung selamat datang default jika riwayat ada
                        container.innerHTML = '';

                        d.history.forEach(item => {
                            if (item.role === 'user') {
                                appendUserMessage(item.message, item.image_url);
                            } else {
                                appendAiMessage(item.message);
                            }
                        });

                        container.scrollTop = container.scrollHeight;
                    }
                })
                .catch(err => console.error('Gagal memuat riwayat obrolan:', err));
        }

        // =====================================================================
        // ATTACHMENT SYSTEM (FILE DOKUMEN & GAMBAR / PASTE)
        // =====================================================================
        function handlePasteEvent(e) {
            if (!e.clipboardData || !e.clipboardData.items) return;

            for (let i = 0; i < e.clipboardData.items.length; i++) {
                const item = e.clipboardData.items[i];
                if (item.type.indexOf('image') !== -1) {
                    const file = item.getAsFile();
                    if (file) {
                        e.preventDefault();
                        loadAttachmentFile(file, 'Clipboard_' + new Date().toISOString().replace(/[:.]/g, '-').slice(11, 19) + '.png');
                        break;
                    }
                }
            }
        }

        function handleFileSelect(e) {
            const files = e.target.files;
            if (files && files.length > 0) {
                loadAttachmentFile(files[0], files[0].name);
            }
            e.target.value = '';
        }

        function loadAttachmentFile(file, nameHint) {
            if (file.size > 25 * 1024 * 1024) {
                alert('Ukuran berkas melebihi batas maksimal 25 MB.');
                return;
            }

            const fileName = nameHint || file.name || 'Berkas Terlampir';
            const ext = (fileName.split('.').pop() || '').toLowerCase();
            const isImage = ['png', 'jpg', 'jpeg', 'webp', 'gif'].includes(ext) || (file.type && file.type.startsWith('image/'));

            const imgPreview = document.getElementById('imgThumbPreview');
            const boxPreview = document.getElementById('boxFilePreview');
            const iconPreview = document.getElementById('iconFilePreview');
            const lblName = document.getElementById('lblImgName');
            const lblSize = document.getElementById('lblImgSize');
            const previewBar = document.getElementById('imagePreviewBar');

            lblName.innerText = fileName;
            lblSize.innerText = (file.size / 1024).toFixed(1) + ' KB';

            if (isImage) {
                const reader = new FileReader();
                reader.onload = function(evt) {
                    pendingAttachment = {
                        file: file,
                        base64: evt.target.result,
                        isImage: true,
                        name: fileName,
                        size: file.size,
                        ext: ext
                    };
                    imgPreview.src = evt.target.result;
                    imgPreview.style.display = 'block';
                    boxPreview.style.display = 'none';
                    previewBar.style.display = 'flex';
                    document.getElementById('txtPrompt').focus();
                };
                reader.readAsDataURL(file);
            } else {
                pendingAttachment = {
                    file: file,
                    base64: null,
                    isImage: false,
                    name: fileName,
                    size: file.size,
                    ext: ext
                };
                imgPreview.style.display = 'none';
                boxPreview.style.display = 'inline-flex';

                let iconClass = 'fa-solid fa-file-lines';
                if (['sql', 'db'].includes(ext)) iconClass = 'fa-solid fa-database';
                else if (['php', 'js', 'py', 'html', 'css', 'json', 'xml', 'srw', 'srd'].includes(ext)) iconClass = 'fa-solid fa-file-code';
                else if (['pdf'].includes(ext)) iconClass = 'fa-solid fa-file-pdf';
                else if (['xls', 'xlsx', 'csv'].includes(ext)) iconClass = 'fa-solid fa-file-excel';
                else if (['zip', 'rar', 'gz', 'tar', '7z'].includes(ext)) iconClass = 'fa-solid fa-file-zipper';

                iconPreview.className = iconClass;
                previewBar.style.display = 'flex';
                document.getElementById('txtPrompt').focus();
            }
        }

        function removePendingImage() {
            pendingAttachment = null;
            const imgPreview = document.getElementById('imgThumbPreview');
            if (imgPreview) imgPreview.src = '';
            const previewBar = document.getElementById('imagePreviewBar');
            if (previewBar) previewBar.style.display = 'none';
            const finput = document.getElementById('fileUploadInput');
            if (finput) finput.value = '';
        }

        function openLightbox(src) {
            if (!src) return;
            document.getElementById('lightboxImg').src = src;
            document.getElementById('modalImageLightbox').classList.add('show');
        }

        function closeLightbox() {
            document.getElementById('modalImageLightbox').classList.remove('show');
        }

        // =====================================================================
        // VERIFIKASI PIN KEAMANAN UNTUK TINDAKAN KRITIS
        // =====================================================================
        function promptSecurityPin(actionDescription) {
            const pin = prompt(`🔒 Konfirmasi Keamanan:\n\nSilakan masukkan PIN Keamanan untuk ${actionDescription}:`);
            if (pin === null) return null; // Pengguna menekan tombol Batal / Cancel
            if (pin.trim() !== '1782') {
                alert('⛔ PIN Keamanan tidak valid! Tindakan dibatalkan.');
                return null;
            }
            return pin.trim();
        }

        // =====================================================================
        // BERSIHKAN SESI CHAT & FILE GAMBAR
        // =====================================================================
        function clearSessionChat() {
            const pin = promptSecurityPin('membersihkan riwayat sesi percakapan');
            if (!pin) return;

            const container = document.getElementById('chatMessages');
            container.innerHTML = `
                <div class="asisten-msg ai">
                    <div class="asisten-msg-avatar">
                        <img src="/assets/asisten_adek.jpg" alt="Adek">
                    </div>
                    <div class="asisten-msg-bubble">
                        <strong>Riwayat Percakapan Telah Dibersihkan.</strong> ✨<br>
                        Adek siap menerima instruksi koding, query database, atau tangkapan layar baru dari Mas Wawan.
                    </div>
                </div>
            `;

            removePendingImage();
            clearTerminalLogs();
            setTerminalStatus('IDLE');

            fetch(`${API_URL}?action=clear_chat_history&session_id=${encodeURIComponent(currentSessionId)}&pin=${encodeURIComponent(pin)}`)
                .then(r => r.json())
                .then(d => {
                    if (d.status === 'OK') {
                        appendTerminalLog(d.message || 'Riwayat percakapan berhasil dibersihkan.', 'INFO');
                    } else {
                        alert(d.message || 'Gagal membersihkan riwayat.');
                    }
                })
                .catch(() => {});
        }

        // =====================================================================
        // PROMPT INPUT & EXECUTION
        // =====================================================================
        function handleKeyDown(e) {
            if (e.key === 'Enter' && !e.shiftKey) {
                e.preventDefault();
                sendPrompt();
            }
        }

        function setPrompt(text) {
            const ta = document.getElementById('txtPrompt');
            ta.value = text;
            ta.focus();
        }

        function onModelChange() {
            const sel = document.getElementById('selModel');
            currentModel = sel.value;
            localStorage.setItem('asisten_ai_model', currentModel);
            const lbl = document.getElementById('lblActiveModel');
            if (lbl && sel.selectedOptions[0]) {
                lbl.innerText = sel.selectedOptions[0].text;
            }
            appendLog(`Model disimpan & dialihkan ke: ${currentModel}`);
        }

        function loadGitStatus() {
            fetch(`${API_URL}?action=git_status`)
                .then(r => r.json())
                .then(d => {
                    if (d.status === 'OK') {
                        const el = document.getElementById('gitCommitBadge');
                        if (el) el.innerText = d.last_commit || 'Git Ready';
                    }
                })
                .catch(() => {});
        }

        function checkAuthStatus() {
            const badge = document.getElementById('accountBadge');
            const btn = document.getElementById('btnAccountAction');
            const lblAuth = document.getElementById('lblAuthStatusText');

            fetch(`${API_URL}?action=check_auth_status`)
                .then(r => r.json())
                .then(d => {
                    if (d.authenticated) {
                        const email = d.email || 'tpberp@gmail.com';
                        badge.style.background = 'rgba(34,197,94,0.15)';
                        badge.style.color = '#4ade80';
                        badge.style.border = '1px solid rgba(34,197,94,0.4)';
                        badge.innerHTML = `<i class="fa-solid fa-circle-check"></i> Terhubung: <strong style="color:#fff;margin-left:3px;">${escapeHtml(email)}</strong>`;

                        btn.style.display = 'inline-flex';
                        btn.className = 'asisten-btn asisten-btn-danger';
                        btn.innerHTML = '<i class="fa-solid fa-right-from-bracket"></i> Logout Akun';
                        btn.title = 'Logout dari Akun Google untuk ganti akun';
                        btn.onclick = doLogout;

                        if (lblAuth) {
                            lblAuth.innerHTML = `Terhubung (<strong>${escapeHtml(email)}</strong>)`;
                            lblAuth.style.color = '#4ade80';
                        }
                    } else {
                        badge.style.background = 'rgba(239,68,68,0.15)';
                        badge.style.color = '#f87171';
                        badge.style.border = '1px solid rgba(239,68,68,0.4)';
                        badge.innerHTML = '<i class="fa-solid fa-circle-xmark"></i> Belum Terhubung';

                        btn.style.display = 'inline-flex';
                        btn.className = 'asisten-btn asisten-btn-primary';
                        btn.innerHTML = '<i class="fa-brands fa-google"></i> Login Akun Google';
                        btn.title = 'Hubungkan akun Google resmi ke Asisten AI';
                        btn.onclick = startDeviceLogin;

                        if (lblAuth) {
                            lblAuth.innerHTML = 'Belum Terhubung';
                            lblAuth.style.color = '#f87171';
                        }
                    }
                })
                .catch(err => {
                    console.error('Check auth error:', err);
                });
        }

        function doLogout() {
            if (!confirm('Apakah Mas Wawan ingin logout dari akun Google?\n\nSetelah logout, seluruh sesi dan berkas gambar sementara akan dibersihkan, dan Anda dapat login kembali kapan saja.')) return;
            appendLog('Memproses logout dari Asisten AI...', 'info');
            fetch(`${API_URL}?action=logout`)
                .then(r => r.json())
                .then(d => {
                    alert(d.message || 'Logout berhasil.');
                    appendLog('Akun Google berhasil di-logout.', 'success');
                    checkAuthStatus();
                })
                .catch(err => alert('Gagal logout: ' + err));
        }

        function doRollback() {
            const pin = promptSecurityPin('me-rollback (git stash) perubahan berkas terakhir');
            if (!pin) return;

            const fd = new FormData();
            fd.append('pin', pin);

            fetch(`${API_URL}?action=rollback`, { method: 'POST', body: fd })
                .then(r => r.json())
                .then(d => {
                    if (d.status === 'OK') {
                        alert(d.message || 'Rollback selesai.');
                        loadGitStatus();
                        appendLog('Rollback berhasil dieksekusi.');
                    } else {
                        alert(d.message || 'Rollback ditolak.');
                    }
                })
                .catch(err => alert('Gagal rollback: ' + err));
        }

        function appendUserMessage(text, imageSrc = null, attachmentName = null) {
            const container = document.getElementById('chatMessages');
            const msg = document.createElement('div');
            msg.className = 'asisten-msg user';

            let imgHtml = '';
            if (imageSrc) {
                imgHtml = `<img src="${imageSrc}" class="user-attached-thumb" onclick="openLightbox(this.src)" title="Klik untuk memperbesar gambar">`;
            } else if (attachmentName) {
                imgHtml = `<div style="display:inline-flex;align-items:center;gap:6px;background:rgba(255,255,255,0.08);border:1px solid rgba(255,255,255,0.15);padding:4px 9px;border-radius:6px;margin-bottom:6px;font-size:11.5px;font-family:monospace;color:#93c5fd;"><i class="fa-solid fa-paperclip"></i> ${escapeHtml(attachmentName)}</div>`;
            }

            msg.innerHTML = `
                <div class="asisten-msg-avatar">DP</div>
                <div class="asisten-msg-bubble">
                    ${imgHtml}
                    <div>${escapeHtml(text)}</div>
                </div>
            `;
            container.appendChild(msg);
            container.scrollTop = container.scrollHeight;
        }

        function appendAiMessage(markdownText) {
            const container = document.getElementById('chatMessages');
            const msg = document.createElement('div');
            msg.className = 'asisten-msg ai';
            msg.innerHTML = `
                <div class="asisten-msg-avatar">
                    <img src="/assets/asisten_adek.jpg" alt="Adek">
                </div>
                <div class="asisten-msg-bubble">${formatMarkdown(markdownText)}</div>
            `;
            container.appendChild(msg);
            container.scrollTop = container.scrollHeight;
        }

        // =====================================================================
        // DEDICATED TEXTBOX TERMINAL CONSOLE LOGGING
        // =====================================================================
        let terminalLogCount = 0;
        let isTerminalExpanded = false;

        function toggleTerminalBox(forceState) {
            const panel = document.getElementById('asistenTerminalPanel');
            const chev = document.getElementById('terminalChevron');
            if (!panel) return;

            if (typeof forceState === 'boolean') {
                isTerminalExpanded = forceState;
            } else {
                isTerminalExpanded = !isTerminalExpanded;
            }

            if (isTerminalExpanded) {
                panel.classList.remove('collapsed');
                if (chev) {
                    chev.classList.remove('fa-chevron-up');
                    chev.classList.add('fa-chevron-down');
                }
            } else {
                panel.classList.add('collapsed');
                if (chev) {
                    chev.classList.remove('fa-chevron-down');
                    chev.classList.add('fa-chevron-up');
                }
            }
        }

        function clearTerminalLogs() {
            const body = document.getElementById('terminalLogBody');
            if (!body) return;
            body.innerHTML = '<div class="terminal-line-placeholder">Terminal bersih. Log baru akan dicatat di sini tanpa mengotori chat... ✨</div>';
            terminalLogCount = 0;
            updateTerminalCounter();
        }

        function updateTerminalCounter() {
            const summary = document.getElementById('terminalSummaryText');
            const headerCount = document.getElementById('headerTerminalCount');
            if (summary) summary.textContent = `• ${terminalLogCount} aktivitas`;
            if (headerCount) headerCount.textContent = terminalLogCount;
        }

        function setTerminalStatus(status) {
            const badge = document.getElementById('terminalStatusBadge');
            if (!badge) return;
            badge.className = 'terminal-badge-status ' + status.toLowerCase();
            badge.textContent = status.toUpperCase();
        }

        function appendTerminalLog(text, category = 'info', target = '') {
            const body = document.getElementById('terminalLogBody');
            if (!body) return;

            const ph = body.querySelector('.terminal-line-placeholder');
            if (ph) ph.remove();

            const row = document.createElement('div');
            row.className = 'terminal-log-row';

            const now = new Date();
            const timeStr = now.toTimeString().split(' ')[0];

            let tagClass = 'info';
            let catUpper = (category || 'INFO').toUpperCase();
            if (['READ', 'VIEW'].includes(catUpper)) tagClass = 'read';
            else if (['WRITE', 'EDIT', 'MODIF'].includes(catUpper)) tagClass = 'write';
            else if (['CMD', 'EXEC'].includes(catUpper)) tagClass = 'cmd';
            else if (['SEARCH', 'FIND', 'GREP'].includes(catUpper)) tagClass = 'search';
            else if (['ERROR', 'ERR'].includes(catUpper)) tagClass = 'error';

            let contentHtml = escapeHtml(text);
            if (target) {
                const escTarget = escapeHtml(target);
                if (contentHtml.includes(escTarget)) {
                    contentHtml = contentHtml.replace(escTarget, `<span class="terminal-log-target">${escTarget}</span>`);
                } else {
                    contentHtml += ` <span class="terminal-log-target">${escTarget}</span>`;
                }
            }

            row.innerHTML = `
                <span class="terminal-log-time">${timeStr}</span>
                <span class="terminal-log-tag ${tagClass}">${catUpper}</span>
                <span class="terminal-log-text">${contentHtml}</span>
            `;

            body.appendChild(row);
            body.scrollTop = body.scrollHeight;

            terminalLogCount++;
            updateTerminalCounter();
        }

        // Seluruh pemanggilan log sistem/perkakas diarahkan ke Textbox Terminal Mandiri (Bebas Polusi Chat)
        function appendLog(text, type = 'info') {
            appendTerminalLog(text, type);
        }

        function getWaitingText(promptText) {
            const p = (promptText || '').toLowerCase();

            // 1. Konsultasi / Saran / Ide / Diskusi
            if (p.includes('saran') || p.includes('improve') || p.includes('ide') || p.includes('pendapat') || p.includes('bagaimana') || p.includes('menurut') || p.includes('rekomendasi') || p.includes('review') || p.includes('kira-kira') || p.includes('kenapa')) {
                const list = [
                    "Bentar ya Mas Wawan.. Adek analisa & carikan saran terbaik ya.. 💡",
                    "Adek lagi kumpulkan ide & pertimbangan terbaik buat Mas.. ✨",
                    "Tunggu sebentar ya Mas.. Adek pelajari dulu untuk rekomendasinya.. 🌸",
                    "Siap Mas Wawan.. Adek telaah dulu ya poin-poin pentingnya.. ☕"
                ];
                return list[Math.floor(Math.random() * list.length)];
            }

            // 2. Koding / Buat Fitur / Modif / Bugfix / Query
            if (p.includes('buat') || p.includes('bikin') || p.includes('ubah') || p.includes('edit') || p.includes('ganti') || p.includes('modif') || p.includes('perbaiki') || p.includes('fix') || p.includes('query') || p.includes('tambah') || p.includes('hapus') || p.includes('koding')) {
                const list = [
                    "Siap Mas Wawan.. Adek teliti kodenya & siapkan solusinya ya.. ⚡",
                    "Bentar ya Mas.. Adek periksa dulu berkas & kodenya ya.. 🛠️",
                    "Adek lagi siapkan kodenya yang rapi buat Mas Wawan.. 💻",
                    "Iya Mas.. Adek kerjakan sekarang ya, tunggu sebentar.. 🌸"
                ];
                return list[Math.floor(Math.random() * list.length)];
            }

            // 3. Analisis Berkas / Gambar Lampiran
            if (pendingAttachment) {
                return "Bentar ya Mas Wawan.. Adek periksa & pelajari berkas lampirannya ya.. 📎";
            }

            // 4. Default Universal (Sopan, Santun, Hangat & Nyambung untuk Segala Pertanyaan)
            const defaultList = [
                "Bentar ya Mas Wawan.. Adek analisa dulu ya.. 💡",
                "Tunggu sebentar ya Mas.. Adek siapkan jawabannya.. 🌸",
                "Adek sedang berpikir ya Mas, sebentar ya.. ✨",
                "Siap Mas Wawan.. Adek pelajari dulu ya.. ☕",
                "Sedang Adek carikan jawaban terbaiknya ya Mas.. 💖"
            ];
            return defaultList[Math.floor(Math.random() * defaultList.length)];
        }

        let currentWaitingText = '';

        function createAiBubble(userPrompt = '') {
            const container = document.getElementById('chatMessages');
            const msg = document.createElement('div');
            msg.className = 'asisten-msg ai';
            currentWaitingText = getWaitingText(userPrompt);
            msg.innerHTML = `
                <div class="asisten-msg-avatar">
                    <img src="/assets/asisten_adek.jpg" alt="Adek">
                </div>
                <div class="asisten-msg-bubble">
                    <div class="asisten-live-loader" id="activeAiLoader">
                        <div class="asisten-loader-heart">
                            <i class="fa-solid fa-heart fa-beat" style="color:#ec4899;margin-right:6px;"></i>
                            <span class="asisten-loader-heart-text">${escapeHtml(currentWaitingText)}</span>
                        </div>
                        <div class="asisten-loader-active-step" id="activeAiStep" style="display:none;">
                            <i class="fa-solid fa-circle-notch fa-spin asisten-spinner"></i>
                            <span class="asisten-step-text" id="activeAiStepText"></span>
                        </div>
                    </div>
                    <div class="asisten-msg-content" id="activeAiContent" style="display:none;"></div>
                </div>
            `;
            container.appendChild(msg);
            container.scrollTop = container.scrollHeight;

            return {
                bubble: msg.querySelector('.asisten-msg-bubble'),
                loader: msg.querySelector('#activeAiLoader'),
                heartText: msg.querySelector('.asisten-loader-heart-text'),
                stepBox: msg.querySelector('#activeAiStep'),
                stepText: msg.querySelector('#activeAiStepText'),
                content: msg.querySelector('#activeAiContent')
            };
        }

        async function sendPrompt() {
            const ta = document.getElementById('txtPrompt');
            const prompt = ta.value.trim();
            const hasAttachment = !!pendingAttachment;

            if ((!prompt && !hasAttachment) || isExecuting) return;

            // Handle inline model switch command
            if (prompt.startsWith('/model ')) {
                const targetM = prompt.replace('/model ', '').trim();
                const sel = document.getElementById('selModel');
                for (let opt of sel.options) {
                    if (opt.value.includes(targetM) || targetM.includes(opt.value)) {
                        sel.value = opt.value;
                        onModelChange();
                        ta.value = '';
                        return;
                    }
                }
            }

            isExecuting = true;
            setTerminalStatus('RUNNING');
            toggleTerminalBox(true);

            // Ubah tombol Kirim menjadi tombol STOP merah menyala
            const btnSend = document.getElementById('btnSend');
            if (btnSend) {
                btnSend.className = 'asisten-btn asisten-btn-danger';
                btnSend.innerHTML = '<i class="fa-solid fa-hand"></i> Stop';
                btnSend.disabled = false;
                btnSend.title = 'Hentikan proses Adek yang sedang berjalan';
                btnSend.onclick = stopExecution;
            }

            // Tampilkan juga tombol Stop di header atas
            const btnHStop = document.getElementById('btnHeaderStop');
            if (btnHStop) btnHStop.style.display = 'inline-flex';

            let uploadedFilePath = '';
            let uploadedFileName = '';
            let isAttachedImage = false;
            let attachedImgSrc = null;

            // 1. Unggah Berkas / Gambar Sementara jika ada
            if (hasAttachment) {
                appendTerminalLog(`Mengunggah berkas lampiran (${pendingAttachment.name})...`, 'INFO');
                try {
                    let uploadResp;
                    if (pendingAttachment.isImage && pendingAttachment.base64) {
                        uploadResp = await fetch(`${API_URL}?action=upload_temp_file`, {
                            method: 'POST',
                            headers: { 'Content-Type': 'application/json' },
                            body: JSON.stringify({
                                image_base64: pendingAttachment.base64,
                                file_name: pendingAttachment.name
                            })
                        });
                    } else {
                        const fd = new FormData();
                        fd.append('file', pendingAttachment.file, pendingAttachment.name);
                        uploadResp = await fetch(`${API_URL}?action=upload_temp_file`, {
                            method: 'POST',
                            body: fd
                        });
                    }
                    const uploadData = await uploadResp.json();
                    if (uploadData.status === 'OK') {
                        uploadedFilePath = uploadData.file_path;
                        uploadedFileName = uploadData.original_name || uploadData.file_name;
                        isAttachedImage = !!uploadData.is_image;
                        if (isAttachedImage) {
                            attachedImgSrc = uploadData.view_url || pendingAttachment.base64;
                        }
                        appendTerminalLog(`Berkas lampiran tersimpan sementara (${uploadedFileName}). Akan otomatis dibersihkan saat sesi dibersihkan.`, 'INFO');
                    } else {
                        alert('Gagal mengunggah berkas: ' + (uploadData.message || 'Error'));
                        finishExecutionUI('IDLE');
                        return;
                    }
                } catch (upErr) {
                    alert('Gagal mengunggah berkas ke server: ' + upErr);
                    finishExecutionUI('ERROR');
                    return;
                }
            }

            appendUserMessage(prompt || (isAttachedImage ? '[Lampiran Gambar]' : `[Lampiran Berkas: ${uploadedFileName}]`), attachedImgSrc, !isAttachedImage ? uploadedFileName : null);
            ta.value = '';
            removePendingImage();

            const aiUi = createAiBubble(prompt);
            activeAiUi = aiUi;
            let accumulatedReply = '';

            const sseUrl = `${API_URL}?action=chat_stream&prompt=${encodeURIComponent(prompt)}&model=${encodeURIComponent(currentModel)}&file_path=${encodeURIComponent(uploadedFilePath)}&file_name=${encodeURIComponent(uploadedFileName)}&session_id=${encodeURIComponent(currentSessionId)}`;
            const evtSource = new EventSource(sseUrl);
            currentEvtSource = evtSource;

            evtSource.onmessage = function(e) {
                try {
                    const data = JSON.parse(e.data);
                    const type = data.type;
                    const p = data.payload || {};

                    if (type === 'init') {
                        if (aiUi.heartText) {
                            aiUi.heartText.textContent = currentWaitingText;
                        }
                    } else if (type === 'thinking') {
                        // 1. Catat ke Textbox Terminal Mandiri
                        let category = 'INFO';
                        const tool = (p.tool || '').toLowerCase();
                        if (tool.includes('read') || tool.includes('view')) category = 'READ';
                        else if (tool.includes('write') || tool.includes('replace') || tool.includes('edit')) category = 'WRITE';
                        else if (tool.includes('run_command') || tool.includes('cmd')) category = 'CMD';
                        else if (tool.includes('grep') || tool.includes('find') || tool.includes('search')) category = 'SEARCH';

                        appendTerminalLog(p.action || p.thought || 'Memproses instruksi...', category, p.target || '');

                        // 2. Perbarui status live loader tunggal di dalam gelembung obrolan (In-Place)
                        if (aiUi.stepBox && aiUi.stepText) {
                            if (aiUi.loader) {
                                aiUi.loader.style.display = 'flex';
                                if (accumulatedReply.trim() && aiUi.heartText) {
                                    aiUi.heartText.parentElement.style.display = 'none';
                                }
                            }
                            aiUi.stepBox.style.display = 'inline-flex';
                            let html = '';
                            if (p.action && p.target) {
                                const escapedAction = escapeHtml(p.action);
                                const escapedTarget = escapeHtml(p.target);
                                if (escapedAction.includes(escapedTarget)) {
                                    html = escapedAction.replace(escapedTarget, `<span class="asisten-loader-target">${escapedTarget}</span>`);
                                } else {
                                    html = `${escapedAction} <span class="asisten-loader-target">${escapedTarget}</span>`;
                                }
                            } else if (p.thought) {
                                html = escapeHtml(p.thought);
                            }
                            aiUi.stepText.innerHTML = html;
                            document.getElementById('chatMessages').scrollTop = document.getElementById('chatMessages').scrollHeight;
                        }
                    } else if (type === 'model_switched') {
                        document.getElementById('selModel').value = p.model;
                        onModelChange();
                    } else if (type === 'rate_limit') {
                        appendTerminalLog(`⚠️ ${p.message}`, 'ERROR');
                        finishExecutionUI('RATE_LIMIT');
                    } else if (type === 'token_needed') {
                        if (aiUi.loader) aiUi.loader.style.display = 'none';
                        if (aiUi.content) {
                            aiUi.content.style.display = 'block';
                            aiUi.content.innerHTML = `<span style="color:#f87171;">⚠️ ${escapeHtml(p.message)}</span><br><br><button class="asisten-btn asisten-btn-primary" onclick="startDeviceLogin()"><i class="fa-brands fa-google"></i> Hubungkan Akun Google Sekarang</button>`;
                        }
                        finishExecutionUI('ERROR');
                    } else if (type === 'message') {
                        accumulatedReply += p.content;
                        if (aiUi.loader) {
                            aiUi.loader.style.display = 'none';
                        }
                        if (aiUi.content) {
                            aiUi.content.style.display = 'block';
                            aiUi.content.innerHTML = formatMarkdown(accumulatedReply);
                        }
                        document.getElementById('chatMessages').scrollTop = document.getElementById('chatMessages').scrollHeight;
                    } else if (type === 'error') {
                        if (aiUi.loader) aiUi.loader.style.display = 'none';
                        if (aiUi.content) {
                            aiUi.content.style.display = 'block';
                            aiUi.content.innerHTML = `<span style="color:#f87171;">Terjadi kendala pada eksekusi Asisten AI. Silakan periksa log terminal di bawah.</span>`;
                        }
                        appendTerminalLog(`Error: ${p.error}`, 'ERROR');
                        finishExecutionUI('ERROR');
                    } else if (type === 'done') {
                        if (aiUi.loader) aiUi.loader.style.display = 'none';
                        if (aiUi.content && !accumulatedReply.trim()) {
                            aiUi.content.style.display = 'block';
                            aiUi.content.innerHTML = `<span style="color:#4ade80;"><i class="fa-solid fa-circle-check" style="margin-right:6px;"></i> ${escapeHtml(p.message || 'Instruksi berhasil diselesaikan.')}</span>`;
                        }
                        finishExecutionUI('DONE');
                    }
                } catch(err) {
                    console.error(err);
                }
            };

            evtSource.onerror = function() {
                if (aiUi.loader) aiUi.loader.style.display = 'none';
                finishExecutionUI('IDLE');
            };
        }

        // =====================================================================
        // PENGHENTIAN EKSEKUSI (STOP BUTTON)
        // =====================================================================
        function stopExecution() {
            if (!isExecuting) return;

            appendTerminalLog('🛑 Permintaan STOP diterima. Menghentikan proses Adek...', 'CMD');
            setTerminalStatus('STOPPING');

            // 1. Putus koneksi streaming SSE di browser
            if (currentEvtSource) {
                currentEvtSource.close();
                currentEvtSource = null;
            }

            // 2. Beritahu backend untuk mematikan proses di server
            fetch(`${API_URL}?action=stop_execution&session_id=${encodeURIComponent(currentSessionId)}`, { method: 'POST' })
                .then(r => r.json())
                .then(d => {
                    appendTerminalLog(`✅ ${d.message || 'Proses di server berhasil dihentikan.'}`, 'CMD');
                })
                .catch(err => {
                    console.error('Stop request error:', err);
                });

            // 3. Matikan loader aktif dan tampilkan notifikasi ramah di chat
            if (activeAiUi) {
                if (activeAiUi.loader) activeAiUi.loader.style.display = 'none';
                if (activeAiUi.content) {
                    activeAiUi.content.style.display = 'block';
                    activeAiUi.content.innerHTML += `
                        <div style="margin-top:8px;padding:8px 12px;background:rgba(239,68,68,0.12);border:1px solid rgba(239,68,68,0.3);border-radius:6px;color:#fca5a5;font-size:12px;">
                            <i class="fa-solid fa-circle-stop" style="margin-right:6px;color:#f87171;"></i>
                            <strong>Proses dihentikan oleh Mas Wawan.</strong> Adek siap menerima instruksi berikutnya ya mas... ✨
                        </div>
                    `;
                }
            }

            // 4. Kembalikan kontrol UI ke kondisi normal
            finishExecutionUI('STOPPED');
        }

        function finishExecutionUI(finalStatus = 'IDLE') {
            if (currentEvtSource) {
                currentEvtSource.close();
                currentEvtSource = null;
            }
            isExecuting = false;
            activeAiUi = null;

            const btnSend = document.getElementById('btnSend');
            if (btnSend) {
                btnSend.className = 'asisten-btn asisten-btn-primary';
                btnSend.innerHTML = '<i class="fa-solid fa-paper-plane"></i> Kirim';
                btnSend.disabled = false;
                btnSend.title = 'Kirim instruksi ke Adek (Enter)';
                btnSend.onclick = sendPrompt;
            }

            const btnHStop = document.getElementById('btnHeaderStop');
            if (btnHStop) btnHStop.style.display = 'none';

            setTerminalStatus(finalStatus);
            loadGitStatus();
        }

        function formatMarkdown(text) {
            return text
                .replace(/```([\s\S]*?)```/g, '<pre class="code-block"><code>$1</code></pre>')
                .replace(/`([^`]+)`/g, '<code style="background:#090d16;padding:2px 5px;border-radius:4px;color:#38bdf8;">$1</code>')
                .replace(/\*\*([^*]+)\*\*/g, '<strong>$1</strong>')
                .replace(/\n/g, '<br>');
        }

        function escapeHtml(str) {
            return (str || '').replace(/&/g, '&amp;').replace(/</g, '&lt;').replace(/>/g, '&gt;').replace(/"/g, '&quot;');
        }

        function startDeviceLogin() {
            const btnAuth = document.getElementById('btnAuthUrl');
            btnAuth.innerText = 'Memeriksa & mengambil tautan otentikasi Google...';
            btnAuth.removeAttribute('href');
            document.getElementById('txtAuthCode').value = '';
            document.getElementById('authStatusText').style.display = 'none';
            document.getElementById('btnSubmitAuthCode').disabled = false;
            document.getElementById('modalDeviceCode').classList.add('show');

            fetch(`${API_URL}?action=start_auth`)
                .then(r => r.json())
                .then(d => {
                    if (d.status === 'ALREADY_CONNECTED') {
                        document.getElementById('modalDeviceCode').classList.remove('show');
                        alert('🎉 Asisten AI sudah aktif dan terhubung!\n\nAkun Google Mas Wawan siap digunakan.');
                        appendLog('Asisten AI aktif dan siap digunakan!', 'success');
                        checkAuthStatus();
                        return;
                    }
                    if (d.status === 'OK' && d.auth_url) {
                        btnAuth.href = d.auth_url;
                        btnAuth.innerHTML = '<i class="fa-solid fa-arrow-up-right-from-square"></i> Buka Halaman Login Akun Google';
                    } else {
                        alert('Gagal mengambil tautan otentikasi: ' + (d.message || 'Error') + (d.log ? '\n\nLog: ' + d.log : ''));
                        document.getElementById('modalDeviceCode').classList.remove('show');
                    }
                })
                .catch(err => {
                    alert('Gagal menghubungi bridge: ' + err);
                    document.getElementById('modalDeviceCode').classList.remove('show');
                });
        }

        function submitAuthCode() {
            let code = document.getElementById('txtAuthCode').value.trim();
            if (code.includes('code=')) {
                const match = code.match(/code=([^&]+)/);
                if (match) {
                    code = decodeURIComponent(match[1]);
                }
            }
            if (!code) {
                alert('Silakan masukkan authorization code yang didapat dari halaman Google.');
                return;
            }

            document.getElementById('authStatusText').style.display = 'flex';
            document.getElementById('btnSubmitAuthCode').disabled = true;

            const fd = new FormData();
            fd.append('code', code);

            fetch(`${API_URL}?action=submit_code`, { method: 'POST', body: fd })
                .then(r => r.json())
                .then(d => {
                    document.getElementById('authStatusText').style.display = 'none';
                    document.getElementById('btnSubmitAuthCode').disabled = false;

                    if (d.status === 'OK') {
                        document.getElementById('modalDeviceCode').classList.remove('show');
                        alert('🎉 Berhasil! Asisten AI telah terhubung dengan Akun Google Mas Wawan!');
                        appendLog('Asisten AI berhasil terhubung dengan akun Google!', 'success');
                        checkAuthStatus();
                    } else {
                        alert('Gagal: ' + (d.message || 'Verifikasi kode gagal.'));
                    }
                })
                .catch(err => {
                    document.getElementById('authStatusText').style.display = 'none';
                    document.getElementById('btnSubmitAuthCode').disabled = false;
                    alert('Error saat mengirim kode: ' + err);
                });
        }

        function refreshAuthUrl() {
            const btnAuth = document.getElementById('btnAuthUrl');
            btnAuth.innerText = 'Mengambil tautan otentikasi baru...';
            btnAuth.removeAttribute('href');
            document.getElementById('txtAuthCode').value = '';
            document.getElementById('authStatusText').style.display = 'none';

            fetch(`${API_URL}?action=start_auth`)
                .then(r => r.json())
                .then(d => {
                    if (d.status === 'OK' && d.auth_url) {
                        btnAuth.href = d.auth_url;
                        btnAuth.innerHTML = '<i class="fa-solid fa-arrow-up-right-from-square"></i> Buka Halaman Login Akun Google';
                    } else if (d.status === 'ALREADY_CONNECTED') {
                        document.getElementById('modalDeviceCode').classList.remove('show');
                        alert('🎉 Asisten AI sudah aktif dan terhubung!');
                        checkAuthStatus();
                    } else {
                        alert('Gagal mengambil tautan baru: ' + (d.message || 'Error'));
                    }
                })
                .catch(err => {
                    alert('Gagal menghubungi backend: ' + err);
                });
        }

        function restartEngine() {
            if (!confirm('Apakah Mas Wawan ingin me-restart Engine AI?\n\nPerintah ini akan membunuh proses yang ngadat/menggantung (kill -9) dan mengembalikan Engine ke status siap pakai.')) return;

            const btn = document.getElementById('btnRestartEngine');
            const originalHtml = btn ? btn.innerHTML : '';
            if (btn) {
                btn.disabled = true;
                btn.innerHTML = '<i class="fa-solid fa-spinner fa-spin" style="color:#f59e0b;"></i> Me-restart...';
            }

            appendTerminalLog('🔄 Permintaan RESTART ENGINE diterima. Membunuh proses zombie dan mereset service...', 'CMD');

            fetch(`${API_URL}?action=restart_engine`, { method: 'POST' })
                .then(r => r.json())
                .then(d => {
                    if (btn) {
                        btn.disabled = false;
                        btn.innerHTML = originalHtml;
                    }
                    if (d.status === 'OK') {
                        appendTerminalLog(`✅ ${d.message}`, 'INFO');
                        alert('✅ ' + d.message);
                        checkAuthStatus();
                    } else {
                        appendTerminalLog(`⚠️ Gagal restart: ${d.message || 'Error'}`, 'ERROR');
                        alert('Gagal restart: ' + (d.message || 'Error'));
                    }
                })
                .catch(err => {
                    if (btn) {
                        btn.disabled = false;
                        btn.innerHTML = originalHtml;
                    }
                    appendTerminalLog(`❌ Error restart: ${err}`, 'ERROR');
                    alert('Error koneksi saat me-restart: ' + err);
                });
        }

        // =====================================================================
        // KUOTA & LIMIT USAGE MODAL (GEMINI, CLAUDE, GPT)
        // =====================================================================
                // =====================================================================
        // ANTRIAN TIKET MASUK DARI PENGGUNA (BUGS & REQUESTS)
        // =====================================================================
        function openTicketQueueModal() {
            const modal = document.getElementById('modalTicketQueue');
            if (modal) modal.classList.add('show');
            loadAiTicketQueue();
        }

        function closeTicketQueueModal() {
            const modal = document.getElementById('modalTicketQueue');
            if (modal) modal.classList.remove('show');
        }

        async function loadAiTicketBadge() {
            try {
                const res = await fetch('ticket.php?action=kpi');
                const json = await res.json();
                if (json.status === 'OK' && json.kpi) {
                    const openCount = Number(json.kpi.open_tickets || 0);
                    const badge = document.getElementById('aiTicketBadge');
                    if (badge) {
                        badge.textContent = openCount;
                        badge.style.display = openCount > 0 ? 'inline-block' : 'none';
                    }
                }
            } catch(e) {}
        }

        async function loadAiTicketQueue() {
            const container = document.getElementById('ticketQueueListContainer');
            const icon = document.getElementById('iconRefreshTickets');
            if (icon) icon.classList.add('fa-spin');

            try {
                const res = await fetch('ticket.php?action=list&status=open');
                const json = await res.json();
                if (icon) icon.classList.remove('fa-spin');

                if (json.status === 'OK') {
                    const tickets = json.data || [];
                    const badgeCount = document.getElementById('aiModalTicketCount');
                    if (badgeCount) badgeCount.textContent = tickets.length + ' Tiket Menunggu';

                    if (tickets.length === 0) {
                        container.innerHTML = '<div style="text-align:center; padding:35px 20px; color:var(--text-muted);"><i class=\"fa-solid fa-circle-check\" style=\"font-size:32px; color:#10b981; margin-bottom:10px; display:block;\"></i><div style=\"font-size:13px; font-weight:600; color:#fff;\">Tidak ada antrian tiket!</div><div style=\"font-size:11px; margin-top:4px;\">Semua tiket bug dan permintaan dari pengguna telah selesai dikerjakan.</div></div>';
                        return;
                    }

                    let html = '';
                    tickets.forEach(t => {
                        const isBug = t.category === 'bug';
                        const catBadge = isBug ? '<span style=\"background:#ef4444; color:#fff; font-size:9.5px; padding:2px 6px; border-radius:3px; font-weight:700;\">ðŸ› BUG</span>' : (t.category === 'improve' ? '<span style=\"background:#f59e0b; color:#fff; font-size:9.5px; padding:2px 6px; border-radius:3px; font-weight:700;\">âš¡ IMPROVE</span>' : '<span style=\"background:#10b981; color:#fff; font-size:9.5px; padding:2px 6px; border-radius:3px; font-weight:700;\">âœ¨ FITUR BARU</span>');

                        html += '<div style=\"background:#1e293b; border:1px solid #334155; border-radius:6px; padding:12px; margin-bottom:10px;\">' +
                            '<div style=\"display:flex; justify-content:space-between; align-items:center; margin-bottom:6px;\">' +
                                '<div style=\"display:flex; align-items:center; gap:8px;\">' +
                                    '<strong style=\"color:#38bdf8; font-size:12px;\">#' + escapeHtml(t.ticket_no) + '</strong> ' +
                                    catBadge + ' ' +
                                    '<span style=\"color:#94a3b8; font-size:11px;\">Menu: <strong style=\"color:#e2e8f0;\">' + escapeHtml(t.menu_name || t.menu_target || '-') + '</strong></span>' +
                                '</div>' +
                                '<span style=\"font-size:10px; color:#64748b;\">' + escapeHtml(t.created_at) + '</span>' +
                            '</div>' +
                            '<div style=\"font-weight:700; font-size:12.5px; color:#fff; margin-bottom:4px;\">' + escapeHtml(t.title) + '</div>' +
                            '<div style=\"font-size:10.5px; color:#94a3b8; margin-bottom:8px;\">Pemohon: <strong>' + escapeHtml(t.created_by_name || t.created_by_id) + '</strong> (' + escapeHtml(t.created_by_id) + ') &bull; <span style=\"color:#38bdf8;\">' + escapeHtml(t.subdomain || t.cmp_id) + '.inhost.id</span> (' + escapeHtml(t.cmp_name || t.cmp_id) + ')</div>' +
                            '<div style=\"display:flex; justify-content:flex-end; gap:8px; margin-top:8px; border-top:1px solid #334155; padding-top:8px;\">' +
                                '<button type=\"button\" class=\"asisten-btn\" style=\"background:#0284c7; color:#fff; font-size:11px;\" onclick=\"takeTicketToAi(' + t.id + ')\">' +
                                    '<i class=\"fa-solid fa-robot\"></i> Ambil &amp; Selesaikan dengan AI' +
                                '</button>' +
                                '<button type=\"button\" class=\"asisten-btn\" style=\"background:#059669; color:#fff; font-size:11px;\" onclick=\"solveTicketPrompt(' + t.id + ', \'' + escapeHtml(t.ticket_no) + '\')\">' +
                                    '<i class=\"fa-solid fa-check\"></i> Tandai Solved' +
                                '</button>' +
                            '</div>' +
                        '</div>';
                    });

                    container.innerHTML = html;
                }
            } catch(e) {
                if (icon) icon.classList.remove('fa-spin');
                container.innerHTML = '<div style=\"color:#ef4444; padding:15px;\">Gagal memuat antrian: ' + escapeHtml(e.message) + '</div>';
            }
        }

        async function takeTicketToAi(ticketId) {
            closeTicketQueueModal();
            try {
                const res = await fetch('ticket.php?action=detail&id=' + ticketId);
                const json = await res.json();
                if (json.status === 'OK' && json.ticket) {
                    const t = json.ticket;
                    const promptText = 'Halo Adek, tolong bantu selesaikan Tiket #' + t.ticket_no + ':\n' +
                        '- Kategori: ' + t.category.toUpperCase() + '\n' +
                        '- Menu: ' + (t.menu_name || t.menu_target) + '\n' +
                        '- Judul: ' + t.title + '\n' +
                        '- Subdomain: ' + (t.subdomain || t.cmp_id) + '.inhost.id\n' +
                        '- Perusahaan: ' + (t.cmp_name || t.cmp_id) + ' [' + t.cmp_id + ']\n' +
                        '- Pemohon: ' + (t.created_by_name || t.created_by_id) + ' (' + t.created_by_id + ')\n' +
                        '- Penjelasan Kendala: ' + t.description + '\n\n' +
                        'Mohon dianalisis dan diperbaiki berkas kode terkait ya dek.';

                    const promptInput = document.getElementById('promptInput');
                    if (promptInput) {
                        promptInput.value = promptText;
                        promptInput.focus();
                        promptInput.style.height = 'auto';
                        promptInput.style.height = Math.min(promptInput.scrollHeight, 180) + 'px';
                    }
                    if (typeof appendTerminalLog === 'function') {
                        appendTerminalLog('[TIKET #' + t.ticket_no + '] Diambil ke jendela kerja Asisten AI.', 'INFO');
                    }
                }
            } catch(e) {}
        }

        async function solveTicketPrompt(ticketId, ticketNo) {
            const notes = prompt('Tuliskan catatan perbaikan / solusi untuk Tiket #' + ticketNo + ':', 'Telah diperbaiki dan diselesaikan oleh Asisten AI Adek.');
            if (notes === null || !notes.trim()) return;

            const fd = new FormData();
            fd.append('action', 'solve_ticket');
            fd.append('ticket_id', ticketId);
            fd.append('solution_notes', notes.trim());
            fd.append('solved_by', 'Asisten AI (Adek)');

            try {
                const res = await fetch('ticket.php', { method: 'POST', body: fd });
                const json = await res.json();
                if (json.status === 'OK') {
                    alert('âœ… Tiket #' + ticketNo + ' berhasil ditandai SOLVED!\nPengguna akan menerima notifikasi otomatis.');
                    loadAiTicketQueue();
                    loadAiTicketBadge();
                } else {
                    alert('Gagal: ' + json.message);
                }
            } catch(e) {
                alert('Kesalahan sistem: ' + e.message);
            }
        }

        function openQuotaModal() {
            const modal = document.getElementById('modalQuotaUsage');
            if (modal) modal.classList.add('show');
            loadQuotaUsage(false);
        }

        function closeQuotaModal() {
            const modal = document.getElementById('modalQuotaUsage');
            if (modal) modal.classList.remove('show');
        }

        function renderQuotaRingSvg(fraction) {
            const safeFraction = (fraction !== undefined && fraction !== null && !isNaN(fraction)) ? Number(fraction) : 1.0;
            const pct = Math.max(0, Math.min(100, Math.round(safeFraction * 100)));
            const circumference = 150.8;
            const offset = (circumference * (1 - (pct / 100))).toFixed(1);
            let color = '#10b981'; // Green
            if (pct < 20) {
                color = '#ef4444'; // Red
            } else if (pct < 50) {
                color = '#f59e0b'; // Amber
            }
            return `
                <div class="quota-ring-wrap">
                    <svg class="quota-ring-svg" viewBox="0 0 58 58">
                        <circle class="quota-ring-bg" cx="29" cy="29" r="24"></circle>
                        <circle class="quota-ring-bar" cx="29" cy="29" r="24"
                                stroke="${color}"
                                stroke-dasharray="150.8"
                                stroke-dashoffset="${offset}"></circle>
                    </svg>
                    <div class="quota-ring-text">${pct}%</div>
                </div>
            `;
        }

        function loadQuotaUsage(isManualRefresh = false) {
            const body = document.getElementById('quotaModalBody');
            const iconRefresh = document.getElementById('iconRefreshQuota');
            if (iconRefresh) iconRefresh.classList.add('fa-spin');

            if (isManualRefresh) {
                body.innerHTML = `
                    <div style="text-align:center;padding:36px;color:#94a3b8;">
                        <i class="fa-solid fa-spinner fa-spin" style="font-size:26px;color:#38bdf8;margin-bottom:12px;"></i>
                        <div style="font-size:13px;font-weight:600;color:#e2e8f0;">Menghubungi Google Cloud Code API...</div>
                        <div style="font-size:11px;color:#64748b;margin-top:4px;">Memperbarui kuota realtime...</div>
                    </div>
                `;
            }

            fetch(`${API_URL}?action=get_quota_usage`)
                .then(r => r.json())
                .then(d => {
                    if (iconRefresh) iconRefresh.classList.remove('fa-spin');
                    if (d.status !== 'OK') {
                        body.innerHTML = `
                            <div style="text-align:center;padding:30px;color:#f87171;">
                                <i class="fa-solid fa-triangle-exclamation" style="font-size:28px;margin-bottom:10px;"></i>
                                <div style="font-size:14px;font-weight:700;">Gagal Mengambil Kuota</div>
                                <div style="font-size:12px;color:#94a3b8;margin-top:6px;max-width:400px;margin-left:auto;margin-right:auto;">
                                    ${escapeHtml(d.message || 'Terjadi kesalahan sistem saat menghubungi Google API.')}
                                </div>
                                <button class="asisten-btn asisten-btn-primary" style="margin-top:14px;" onclick="loadQuotaUsage(true)">
                                    <i class="fa-solid fa-arrows-rotate"></i> Coba Lagi
                                </button>
                            </div>
                        `;
                        return;
                    }
                    renderQuotaContent(d);
                })
                .catch(err => {
                    if (iconRefresh) iconRefresh.classList.remove('fa-spin');
                    body.innerHTML = `
                        <div style="text-align:center;padding:30px;color:#f87171;">
                            <i class="fa-solid fa-triangle-exclamation" style="font-size:28px;margin-bottom:10px;"></i>
                            <div style="font-size:14px;font-weight:700;">Koneksi API Gagal</div>
                            <div style="font-size:12px;color:#94a3b8;margin-top:6px;">${escapeHtml(err.message)}</div>
                            <button class="asisten-btn asisten-btn-primary" style="margin-top:14px;" onclick="loadQuotaUsage(true)">
                                <i class="fa-solid fa-arrows-rotate"></i> Coba Lagi
                            </button>
                        </div>
                    `;
                });
        }

        function renderQuotaRingSvgLg(fraction) {
            const r = 34, cx = 41, cy = 41;
            const circ = 2 * Math.PI * r;
            const pct = Math.max(0, Math.min(100, Math.round((fraction || 0) * 100)));
            const offset = circ * (1 - (fraction || 0));
            let color = '#10b981';
            if (pct < 20) color = '#ef4444';
            else if (pct < 50) color = '#f59e0b';
            return `
                <div class="quota-ring-wrap-lg">
                    <svg class="quota-ring-svg-lg" viewBox="0 0 82 82">
                        <circle class="quota-ring-bg" cx="${cx}" cy="${cy}" r="${r}"></circle>
                        <circle class="quota-ring-bar" cx="${cx}" cy="${cy}" r="${r}"
                            stroke="${color}"
                            stroke-dasharray="${circ}"
                            stroke-dashoffset="${offset}">
                        </circle>
                    </svg>
                    <div class="quota-ring-text-lg">${pct}%</div>
                </div>`;
        }

        function renderQuotaContent(data) {
            const body = document.getElementById('quotaModalBody');
            const email = data.email || 'tpberp@gmail.com';
            const summary = data.summary || {};
            const quota = data.quota || {};

            const summaryGroups = summary.groups || [];

            // ── Bangun group Gemini dari quota.buckets ──────────────────────────
            const geminiModels = [];
            const nonGeminiBuckets = [];
            if (Array.isArray(quota.buckets)) {
                quota.buckets.forEach(b => {
                    const id = (b.modelId || b.displayName || '').toLowerCase();
                    if (id.includes('gemini')) geminiModels.push(b);
                    else nonGeminiBuckets.push(b);
                });
            }

            let html = `
                <div style="display:flex;justify-content:space-between;align-items:center;flex-wrap:wrap;gap:10px;margin-bottom:14px;">
                    <div class="quota-account-pill" style="margin-bottom:0;">
                        <i class="fa-brands fa-google" style="color:#4285F4;"></i>
                        <span>Akun Google: <strong>${escapeHtml(email)}</strong></span>
                        <span style="font-size:10px;color:#10b981;font-weight:normal;">• Terhubung &amp; Aktif</span>
                    </div>
                    <div style="font-size:11px;color:#64748b;">
                        Sinkronisasi Terakhir: <strong style="color:#94a3b8;">${new Date().toLocaleTimeString('id-ID')}</strong>
                    </div>
                </div>
            `;

            // ── 1. GEMINI — Utama, lebih besar ─────────────────────────────────
            if (geminiModels.length > 0) {
                html += `
                    <div class="quota-group quota-group-gemini">
                        <div class="quota-group-title">
                            <i class="fa-solid fa-bolt" style="color:#10b981;font-size:15px;"></i>
                            <span style="font-size:14px;">Gemini Models</span>
                            <span style="font-size:10px;font-weight:500;color:#6ee7b7;background:rgba(16,185,129,0.12);padding:2px 8px;border-radius:20px;">Favorit Mas Wawan</span>
                        </div>
                        <div class="quota-cards-grid" style="grid-template-columns:repeat(auto-fit,minmax(280px,1fr));gap:14px;">
                `;
                geminiModels.forEach(b => {
                    const name = b.displayName || b.modelId || 'Gemini';
                    const fraction = b.remainingFraction !== undefined ? b.remainingFraction : 1.0;
                    const desc = b.description || (b.resetTime ? `Reset: ${new Date(b.resetTime).toLocaleString('id-ID')}` : 'Kuota pemakaian aktif');
                    html += `
                        <div class="quota-card quota-card-gemini" style="padding:16px;gap:16px;">
                            ${renderQuotaRingSvgLg(fraction)}
                            <div class="quota-info">
                                <div class="quota-name" style="font-size:13.5px;margin-bottom:4px;">${escapeHtml(name)}</div>
                                <div class="quota-desc">${escapeHtml(desc)}</div>
                            </div>
                        </div>
                    `;
                });
                html += `</div></div>`;
            }

            // ── 2. Claude / GPT dari summary.groups ────────────────────────────
            if (summaryGroups.length > 0) {
                summaryGroups.forEach(g => {
                    const groupTitle = g.displayName || 'Model Kuota';
                    let iconHtml = '<i class="fa-solid fa-brain" style="color:#a855f7;"></i>';
                    html += `
                        <div class="quota-group">
                            <div class="quota-group-title">
                                ${iconHtml}
                                <span>${escapeHtml(groupTitle)}</span>
                            </div>
                            <div class="quota-cards-grid">
                    `;
                    const buckets = g.buckets || [];
                    buckets.forEach(b => {
                        const name = b.displayName || b.modelId || 'Batas Kuota';
                        const fraction = b.remainingFraction !== undefined ? b.remainingFraction : 1.0;
                        const desc = b.description || (b.resetTime ? `Reset: ${new Date(b.resetTime).toLocaleString('id-ID')}` : 'Batas pemakaian aktif');
                        html += `
                            <div class="quota-card">
                                ${renderQuotaRingSvg(fraction)}
                                <div class="quota-info">
                                    <div class="quota-name">${escapeHtml(name)}</div>
                                    <div class="quota-desc">${escapeHtml(desc)}</div>
                                </div>
                            </div>
                        `;
                    });
                    html += `</div></div>`;
                });
            }

            // ── 3. Model non-Gemini dari quota.buckets (bar kecil, jika ada) ──
            if (nonGeminiBuckets.length > 0) {
                html += `
                    <div class="quota-models-detail">
                        <div style="font-size:12px;font-weight:700;color:#cbd5e1;margin-bottom:8px;display:flex;align-items:center;gap:6px;">
                            <i class="fa-solid fa-list-check" style="color:#94a3b8;"></i>
                            <span>Model Lainnya</span>
                        </div>
                `;
                nonGeminiBuckets.forEach(qb => {
                    const modelName = qb.displayName || qb.modelId || 'Model';
                    const fraction = qb.remainingFraction !== undefined ? qb.remainingFraction : 1.0;
                    const pct = Math.max(0, Math.min(100, Math.round(fraction * 100)));
                    let color = '#10b981';
                    if (pct < 20) color = '#ef4444';
                    else if (pct < 50) color = '#f59e0b';
                    html += `
                        <div class="quota-model-item">
                            <span class="quota-model-name">${escapeHtml(modelName)}</span>
                            <div style="display:flex;align-items:center;">
                                <div class="quota-model-bar-wrap">
                                    <div class="quota-model-bar-inner" style="width:${pct}%;background:${color};"></div>
                                </div>
                                <span style="font-weight:700;color:${color};font-size:11px;width:34px;text-align:right;">${pct}%</span>
                            </div>
                        </div>
                    `;
                });
                html += `</div>`;
            }

            // ── Fallback jika benar-benar kosong ───────────────────────────────
            if (geminiModels.length === 0 && summaryGroups.length === 0 && nonGeminiBuckets.length === 0) {
                html += `
                    <div style="text-align:center;padding:20px;color:#94a3b8;font-size:12px;">
                        Data kuota tidak tersedia dari Google Cloud Code API saat ini.
                    </div>
                `;
            }

            body.innerHTML = html;
        }



        function cancelDeviceCode() {

            document.getElementById('modalDeviceCode').classList.remove('show');
        }
    </script>
</body>
</html>
