<?php
// Evitar cache del navegador para siempre cargar la versión más reciente
header('Cache-Control: no-cache, no-store, must-revalidate');
header('Pragma: no-cache');
header('Expires: 0');
?>
<!DOCTYPE html>
<html lang="es">
<head>
    <meta charset="UTF-8">
    <meta name="viewport" content="width=device-width, initial-scale=1.0, viewport-fit=cover, interactive-widget=resizes-content">
    <meta name="apple-mobile-web-app-capable" content="yes">
    <meta name="apple-mobile-web-app-status-bar-style" content="black-translucent">
    <meta name="mobile-web-app-capable" content="yes">
    <meta name="theme-color" content="#000000">
    <title>Grabadora Médica</title>
    
    <!-- Font Awesome -->
    <link href="https://cdnjs.cloudflare.com/ajax/libs/font-awesome/6.0.0/css/all.min.css" rel="stylesheet">
    
    <style>
        * { box-sizing: border-box; margin: 0; padding: 0; -webkit-tap-highlight-color: transparent; }

        :root {
            /* Relleno extra bajo el layout (barra URL/toolbar); lo actualiza JS con visualViewport */
            --vv-bottom-padding: 0px;
            --bg: #000000;
            --bg2: #1c1c1e;
            --bg3: #2c2c2e;
            --bg4: #3a3a3c;
            --blue:  #0a84ff;
            --green: #30d158;
            --red:   #ff375f;
            --orange:#ff9f0a;
            --yellow:#ffd60a;
            --t1: #ffffff;
            --t2: #8e8e93;
            --t3: #48484a;
            --sep: rgba(255,255,255,0.08);
            --sep2: rgba(255,255,255,0.14);
            /* Anillo de grabación: escala con viewport (iOS con barra de URL / Android) */
            --r-outer: clamp(150px, 52vw, 220px);
            --r-btn: clamp(64px, 22vw, 88px);
        }

        html {
            height: 100%;
            height: -webkit-fill-available;
        }

        body {
            min-height: 100%;
            min-height: 100vh;
            min-height: 100dvh;
            min-height: -webkit-fill-available;
            width: 100%;
            background: var(--bg);
            color: var(--t1);
            font-family: -apple-system, BlinkMacSystemFont, 'SF Pro Display', 'Segoe UI', sans-serif;
            overflow-x: hidden;
            overflow-y: hidden;
            -webkit-font-smoothing: antialiased;
        }

        /* ── App shell ── */
        #app {
            display: flex;
            flex-direction: column;
            height: 100%;
            min-height: 100vh;
            min-height: 100dvh;
            min-height: -webkit-fill-available;
            max-height: 100vh;
            max-height: 100dvh;
            max-height: -webkit-fill-available;
            padding-top: max(env(safe-area-inset-top), 10px);
            padding-right: env(safe-area-inset-right, 0);
            padding-left: env(safe-area-inset-left, 0);
            padding-bottom: calc(max(env(safe-area-inset-bottom, 0px), 8px) + var(--vv-bottom-padding, 0px));
        }

        /* ── Top bar ── */
        .top-bar {
            display: flex;
            align-items: center;
            justify-content: space-between;
            padding: 12px 20px 8px;
            flex-shrink: 0;
        }
        .top-bar .brand {
            display: flex;
            align-items: center;
            gap: 8px;
        }
        .top-bar .brand .cross {
            width: 26px; height: 26px;
            background: var(--red);
            border-radius: 6px;
            display: flex; align-items: center; justify-content: center;
            font-size: 14px; color: #fff; font-weight: 700;
        }
        .top-bar .brand span {
            font-size: 13px;
            font-weight: 600;
            letter-spacing: 0.3px;
            color: var(--t2);
            text-transform: uppercase;
        }
        #connection-status {
            display: flex; align-items: center; gap: 6px;
            font-size: 12px; color: var(--t2);
        }
        #connection-status .dot {
            width: 7px; height: 7px;
            border-radius: 50%;
            background: var(--green);
            box-shadow: 0 0 6px var(--green);
        }
        #connection-status.disconnected .dot { background: var(--red); box-shadow: 0 0 6px var(--red); }

        /* ── Wake Lock indicator ── */
        #wakelock-dot {
            width: 7px; height: 7px;
            border-radius: 50%;
            background: var(--sep2);
            flex-shrink: 0;
            transition: background 0.4s, box-shadow 0.4s;
            cursor: default;
        }

        /* ── Fullscreen button ── */
        #fullscreen-btn {
            position: relative;
            width: 32px; height: 32px;
            border-radius: 8px;
            border: none;
            background: rgba(255,255,255,0.08);
            color: var(--t2);
            font-size: 14px;
            display: flex; align-items: center; justify-content: center;
            cursor: pointer;
            margin-left: 10px;
            transition: background 0.15s, color 0.15s, transform 0.15s;
            touch-action: manipulation;
            -webkit-tap-highlight-color: transparent;
            -webkit-user-select: none; user-select: none;
            flex-shrink: 0;
        }
        #fullscreen-btn:active { background: rgba(255,255,255,0.16); transform: scale(0.88); }
        #fullscreen-btn.active { color: var(--blue); background: rgba(10,132,255,0.15); }
        /* Tooltip debajo del botón */
        #fullscreen-btn::after {
            content: attr(data-label);
            position: absolute;
            top: calc(100% + 6px); right: 0;
            background: var(--bg3);
            border: 1px solid var(--sep2);
            border-radius: 8px;
            padding: 4px 10px;
            font-size: 11px; white-space: nowrap; color: var(--t1);
            opacity: 0; pointer-events: none;
            transition: opacity 0.2s;
        }
        #fullscreen-btn:focus::after { opacity: 1; }

        /* Ajuste de la top-bar para acomodar el botón */
        .top-bar-right {
            display: flex; align-items: center; gap: 4px;
        }

        /* ── Patient card ── */
        #workspace-info {
            margin: 0 16px 4px;
            background: var(--bg2);
            border-radius: 14px;
            border: 1px solid var(--sep2);
            overflow: hidden;
            flex-shrink: 0;
        }
        #workspace-info .card-header {
            display: flex;
            align-items: center;
            gap: 10px;
            padding: 10px 14px;
            border-bottom: 1px solid var(--sep);
        }
        #workspace-info .card-header .avatar {
            width: 36px; height: 36px;
            background: linear-gradient(135deg, var(--blue), #5e5ce6);
            border-radius: 10px;
            display: flex; align-items: center; justify-content: center;
            font-size: 16px; color: #fff; flex-shrink: 0;
        }
        #workspace-info .card-header .name {
            font-size: 15px; font-weight: 600; color: var(--t1);
            white-space: nowrap; overflow: hidden; text-overflow: ellipsis;
        }
        #workspace-info .card-header .pid {
            font-size: 11px; color: var(--t2); margin-top: 1px;
        }
        #workspace-info .card-body {
            display: flex;
            padding: 10px 14px;
            gap: 0;
        }
        #workspace-info .meta-item {
            flex: 1;
            text-align: center;
        }
        #workspace-info .meta-item:not(:last-child) {
            border-right: 1px solid var(--sep);
        }
        #workspace-info .meta-label {
            font-size: 10px;
            color: var(--t2);
            text-transform: uppercase;
            letter-spacing: 0.5px;
            margin-bottom: 2px;
        }
        #workspace-info .meta-value {
            font-size: 13px;
            font-weight: 600;
            color: var(--t1);
        }
        #workspace-info.standby {
            border-color: var(--orange);
        }
        #workspace-info.standby .avatar {
            background: linear-gradient(135deg, var(--orange), #ff6b0a);
        }

        /* ── Recorder main ── */
        .recorder-main {
            flex: 1 1 auto;
            display: flex;
            flex-direction: column;
            align-items: center;
            justify-content: center;
            padding: 4px 16px 8px;
            min-height: 0;
            overflow-y: auto;
            overflow-x: hidden;
            -webkit-overflow-scrolling: touch;
            overscroll-behavior-y: contain;
        }

        /* ── Waveform ── */
        .waveform-wrap {
            width: 100%;
            max-width: 320px;
            height: 52px;
            display: flex;
            align-items: center;
            justify-content: center;
            gap: 3px;
            margin-bottom: 18px;
        }
        .wave-bar {
            width: 3px;
            border-radius: 3px;
            background: var(--t3);
            transition: background 0.3s;
        }
        .waveform-wrap.idle .wave-bar { height: 3px; }
        .waveform-wrap.recording .wave-bar {
            background: var(--red);
            animation: waveAnim 0.8s ease-in-out infinite alternate;
        }
        .waveform-wrap.recording .wave-bar:nth-child(1)  { animation-delay:0s;    --h:18px; }
        .waveform-wrap.recording .wave-bar:nth-child(2)  { animation-delay:0.05s; --h:30px; }
        .waveform-wrap.recording .wave-bar:nth-child(3)  { animation-delay:0.1s;  --h:44px; }
        .waveform-wrap.recording .wave-bar:nth-child(4)  { animation-delay:0.15s; --h:36px; }
        .waveform-wrap.recording .wave-bar:nth-child(5)  { animation-delay:0.2s;  --h:52px; }
        .waveform-wrap.recording .wave-bar:nth-child(6)  { animation-delay:0.25s; --h:38px; }
        .waveform-wrap.recording .wave-bar:nth-child(7)  { animation-delay:0.3s;  --h:26px; }
        .waveform-wrap.recording .wave-bar:nth-child(8)  { animation-delay:0.35s; --h:48px; }
        .waveform-wrap.recording .wave-bar:nth-child(9)  { animation-delay:0.4s;  --h:34px; }
        .waveform-wrap.recording .wave-bar:nth-child(10) { animation-delay:0.45s; --h:20px; }
        .waveform-wrap.recording .wave-bar:nth-child(11) { animation-delay:0.5s;  --h:50px; }
        .waveform-wrap.recording .wave-bar:nth-child(12) { animation-delay:0.55s; --h:40px; }
        .waveform-wrap.recording .wave-bar:nth-child(13) { animation-delay:0.6s;  --h:28px; }
        .waveform-wrap.recording .wave-bar:nth-child(14) { animation-delay:0.65s; --h:44px; }
        .waveform-wrap.recording .wave-bar:nth-child(15) { animation-delay:0.7s;  --h:22px; }
        .waveform-wrap.recording .wave-bar:nth-child(16) { animation-delay:0.75s; --h:38px; }
        .waveform-wrap.recording .wave-bar:nth-child(17) { animation-delay:0.8s;  --h:52px; }
        .waveform-wrap.recording .wave-bar:nth-child(18) { animation-delay:0.85s; --h:32px; }
        .waveform-wrap.recording .wave-bar:nth-child(19) { animation-delay:0.9s;  --h:16px; }
        .waveform-wrap.recording .wave-bar:nth-child(20) { animation-delay:0.95s; --h:40px; }
        .waveform-wrap.paused .wave-bar {
            background: var(--orange);
            height: 4px !important;
        }
        @keyframes waveAnim {
            from { height: 4px; }
            to   { height: var(--h, 20px); }
        }

        /* ── Timer ── */
        .timer-display {
            font-size: clamp(38px, 14vw, 62px);
            font-weight: 200;
            letter-spacing: -2px;
            color: var(--t1);
            font-variant-numeric: tabular-nums;
            line-height: 1;
            margin-bottom: 4px;
            font-feature-settings: "tnum";
        }
        .timer-label {
            font-size: clamp(10px, 3.2vw, 12px);
            color: var(--t2);
            text-transform: uppercase;
            letter-spacing: 1.5px;
            margin-bottom: clamp(12px, 4vh, 36px);
        }
        .timer-label.recording { color: var(--red); }
        .timer-label.paused    { color: var(--orange); }

        /* ── Record button (iOS-style) ── */
        .btn-record-outer {
            width: var(--r-outer);
            height: var(--r-outer);
            border-radius: 50%;
            border: 2px solid var(--sep2);
            display: flex; align-items: center; justify-content: center;
            position: relative;
            margin-bottom: clamp(12px, 3vh, 32px);
            margin-top: clamp(0px, 1vh, 8px);
            flex-shrink: 0;
        }
        /* Anillo de pulso usando un div hijo separado para no interferir con clicks */
        .pulse-ring {
            position: absolute;
            inset: -14px;
            border-radius: 50%;
            border: 2px solid rgba(255,55,95,0);
            pointer-events: none;
            transition: border-color 0.4s;
        }
        .btn-record-outer.recording .pulse-ring {
            border-color: rgba(255,55,95,0.35);
            animation: ringPulse 1.5s ease-in-out infinite;
        }
        @keyframes ringPulse {
            0%,100% { transform: scale(1);    opacity: 0.7; }
            50%      { transform: scale(1.06); opacity: 1;   }
        }
        #record-btn {
            width: var(--r-btn);
            height: var(--r-btn);
            border-radius: 50%;
            border: none;
            background: var(--red);
            color: #fff;
            font-size: clamp(20px, 7vw, 28px);
            display: flex; align-items: center; justify-content: center;
            cursor: pointer;
            box-shadow: 0 8px 28px rgba(255,55,95,0.45);
            transition: transform 0.15s, box-shadow 0.15s, background 0.3s;
            outline: none;
            -webkit-user-select: none; user-select: none;
            touch-action: manipulation;  /* Elimina delay de 300ms en móvil */
            -webkit-tap-highlight-color: transparent;
            position: relative;
            z-index: 10;
        }
        #record-btn:active { transform: scale(0.92); box-shadow: 0 4px 16px rgba(255,55,95,0.3); }
        #record-btn.recording {
            background: var(--bg3);
            box-shadow: 0 8px 28px rgba(255,55,95,0.2);
        }
        #record-btn.recording i { color: var(--red); }
        #record-btn:disabled { background: var(--bg3); box-shadow: none; opacity: 0.4; }

        /* progress ring around record button */
        .progress-ring {
            position: absolute;
            top: 50%; left: 50%;
            transform: translate(-50%,-50%) rotate(-90deg);
            width: calc(var(--r-btn) + 14px);
            height: calc(var(--r-btn) + 14px);
            pointer-events: none;
        }
        .progress-ring circle {
            fill: none;
            stroke: var(--red);
            stroke-width: 3;
            stroke-linecap: round;
            stroke-dasharray: 0 1000;
            transition: stroke-dasharray 0.1s linear;
            opacity: 0;
        }
        .btn-record-outer.recording .progress-ring circle { opacity: 1; }

        /* ── Secondary controls (iOS pill buttons) ── */
        .secondary-controls {
            display: flex;
            gap: clamp(14px, 6vw, 24px);
            align-items: center;
            justify-content: center;
            flex-shrink: 0;
            padding-bottom: 4px;
        }
        .ctrl-btn {
            width: clamp(48px, 14vw, 60px);
            height: clamp(48px, 14vw, 60px);
            border-radius: 50%;
            border: none;
            display: flex; align-items: center; justify-content: center;
            font-size: clamp(16px, 5vw, 20px);
            cursor: pointer;
            transition: transform 0.15s, opacity 0.15s;
            outline: none;
            -webkit-user-select: none; user-select: none;
            touch-action: manipulation;
            -webkit-tap-highlight-color: transparent;
        }
        .ctrl-btn:active { transform: scale(0.88); }
        .ctrl-btn:disabled { opacity: 0.25; pointer-events: none; }
        .ctrl-btn.pause { background: var(--bg3); color: var(--orange); }
        .ctrl-btn.stop  { background: var(--bg3); color: var(--t2); }

        /* ── Recordings panel (bottom sheet) ── */
        /* ── Recordings bottom sheet ── */
        .recordings-panel {
            flex: 0 1 auto;
            flex-shrink: 1;
            background: var(--bg2);
            border-radius: 20px 20px 0 0;
            border-top: 1px solid var(--sep2);
            display: flex;
            flex-direction: column;
            min-height: 0;
            /* Cap: no más del ~42% del alto visual + tope px (evita solaparse con chrome del navegador) */
            max-height: min(42vh, 300px);
            max-height: min(42dvh, 300px);
            transition: max-height 0.38s cubic-bezier(0.4, 0, 0.2, 1);
            overflow: hidden;
        }
        /* Estado colapsado: solo el handle visible */
        .recordings-panel.collapsed {
            max-height: 56px; /* altura del handle */
        }
        /* Pill drag-handle al estilo iOS (decorativo) */
        .recordings-pill {
            width: 36px; height: 4px;
            background: var(--sep2);
            border-radius: 2px;
            margin: 8px auto 0;
            flex-shrink: 0;
        }
        .recordings-handle {
            display: flex;
            align-items: center;
            justify-content: space-between;
            padding: 8px 18px 10px;
            flex-shrink: 0;
            cursor: pointer;
            touch-action: manipulation;
            -webkit-tap-highlight-color: transparent;
            user-select: none;
            -webkit-user-select: none;
        }
        .recordings-handle .title {
            display: flex; align-items: center; gap: 8px;
            font-size: 14px; font-weight: 600; color: var(--t1);
        }
        .recordings-handle .badge-count {
            background: var(--blue);
            color: #fff;
            border-radius: 10px;
            padding: 2px 8px;
            font-size: 11px;
            font-weight: 700;
        }
        .recordings-handle .badge-count.hidden { display: none; }
        /* Chevron giratorio */
        .recordings-chevron {
            color: var(--t3);
            font-size: 13px;
            transition: transform 0.35s cubic-bezier(0.4, 0, 0.2, 1), color 0.2s;
            flex-shrink: 0;
        }
        .recordings-panel.collapsed .recordings-chevron {
            transform: rotate(-180deg);
            color: var(--blue);
        }
        /* Fila inferior: botón Enviar + chevron */
        .recordings-handle-row {
            display: flex; align-items: center; gap: 10px;
        }
        #upload-btn {
            background: var(--blue);
            color: #fff;
            border: none;
            border-radius: 20px;
            padding: 8px 18px;
            font-size: 13px;
            font-weight: 600;
            cursor: pointer;
            display: flex; align-items: center; gap: 6px;
            transition: opacity 0.2s, transform 0.15s;
            white-space: nowrap;
            touch-action: manipulation;
            -webkit-tap-highlight-color: transparent;
        }
        #upload-btn:active { transform: scale(0.95); }
        #upload-btn:disabled { opacity: 0.3; pointer-events: none; }
        .recordings-list-inner {
            flex: 1 1 0%;
            min-height: 0;
            min-width: 0;
            overflow-x: hidden;
            overflow-y: auto;
            -webkit-overflow-scrolling: touch;
            overscroll-behavior-y: contain;
            touch-action: pan-y;
            padding: 0 12px max(12px, env(safe-area-inset-bottom, 0px));
        }
        .recordings-empty {
            text-align: center;
            color: var(--t3);
            font-size: 13px;
            padding: 16px 0;
        }
        .recording-item {
            background: var(--bg3);
            border-radius: 12px;
            padding: 12px 14px;
            margin-bottom: 8px;
            display: flex;
            align-items: center;
            gap: 10px;
        }
        .recording-item .rec-info {
            flex: 1;
            min-width: 0;
        }
        .recording-item .rec-name {
            font-size: 13px;
            font-weight: 600;
            color: var(--t1);
            white-space: nowrap;
            overflow: hidden;
            text-overflow: ellipsis;
        }
        .recording-item .rec-meta {
            font-size: 11px;
            color: var(--t2);
            margin-top: 2px;
        }
        .recording-item audio {
            width: 100%;
            height: 28px;
            margin-top: 6px;
            filter: invert(1) hue-rotate(180deg);
        }
        .recording-item .del-btn {
            width: 32px; height: 32px;
            border-radius: 50%;
            border: none;
            background: rgba(255,55,95,0.15);
            color: var(--red);
            font-size: 14px;
            display: flex; align-items: center; justify-content: center;
            cursor: pointer;
            flex-shrink: 0;
            transition: background 0.2s;
        }
        .recording-item .del-btn:active { background: rgba(255,55,95,0.3); }
        .recording-item .rec-actions {
            display: flex;
            gap: 8px;
            align-items: center;
            flex-shrink: 0;
        }
        .recording-item .share-btn {
            width: 32px; height: 32px;
            border-radius: 50%;
            border: none;
            background: rgba(10,132,255,0.16);
            color: var(--blue);
            font-size: 13px;
            display: flex; align-items: center; justify-content: center;
            cursor: pointer;
            flex-shrink: 0;
            transition: background 0.2s;
        }
        .recording-item .share-btn:active { background: rgba(10,132,255,0.3); }

        /* ── Recording item status badges ── */
        .recording-item .rec-status {
            display: flex; align-items: center; gap: 4px;
            font-size: 10px; font-weight: 600; margin-top: 4px;
            padding: 2px 8px; border-radius: 20px; width: fit-content;
        }
        .rec-status.status-uploading {
            background: rgba(10,132,255,0.15); color: var(--blue);
        }
        .rec-status.status-ok {
            background: rgba(48,209,88,0.15); color: var(--green);
        }
        .rec-status.status-backup {
            background: rgba(142,142,147,0.15); color: var(--t2);
        }
        .rec-status.status-pending {
            background: rgba(255,159,10,0.15); color: var(--orange);
        }
        .rec-status.status-error {
            background: rgba(255,55,95,0.15); color: var(--red);
        }
        .recording-item .retry-btn {
            background: none; border: none; color: var(--blue);
            font-size: 11px; font-weight: 600; cursor: pointer;
            padding: 0 0 0 6px; text-decoration: underline;
            touch-action: manipulation;
        }

        /* ── Network status banner ── */
        #network-banner {
            position: fixed;
            top: calc(env(safe-area-inset-top, 0px) + 44px);
            left: 12px; right: 12px;
            padding: 10px 14px;
            border-radius: 12px;
            font-size: 13px; font-weight: 600;
            display: flex; align-items: center; gap: 8px;
            z-index: 500;
            transform: translateY(-80px);
            opacity: 0;
            transition: transform 0.35s cubic-bezier(0.32,0.72,0,1), opacity 0.3s;
            pointer-events: none;
        }
        #network-banner.show {
            transform: translateY(0); opacity: 1;
        }
        #network-banner.offline {
            background: rgba(255,159,10,0.22);
            color: var(--orange);
            border: 1px solid rgba(255,159,10,0.35);
        }
        #network-banner.online {
            background: rgba(48,209,88,0.18);
            color: var(--green);
            border: 1px solid rgba(48,209,88,0.3);
        }

        /* ── Session Error / Standby screens ── */
        .overlay-screen {
            position: fixed;
            inset: 0;
            background: var(--bg);
            display: flex; flex-direction: column;
            align-items: center;
            justify-content: center;
            padding: max(20px, env(safe-area-inset-top)) max(20px, env(safe-area-inset-right)) max(20px, env(safe-area-inset-bottom)) max(20px, env(safe-area-inset-left));
            z-index: 100;
            text-align: center;
            overflow-y: auto;
            -webkit-overflow-scrolling: touch;
        }
        .overlay-screen .icon-wrap {
            width: 80px; height: 80px;
            border-radius: 24px;
            display: flex; align-items: center; justify-content: center;
            font-size: 36px;
            margin-bottom: 24px;
        }
        .overlay-screen .icon-wrap.error   { background: rgba(255,55,95,0.15);  color: var(--red); }
        .overlay-screen .icon-wrap.standby { background: rgba(255,159,10,0.15); color: var(--orange); }
        .overlay-screen h2 { font-size: 22px; font-weight: 700; margin-bottom: 10px; }
        .overlay-screen p  { font-size: 15px; color: var(--t2); line-height: 1.6; }
        .overlay-screen .overlay-badge {
            margin-top: 24px;
            background: var(--bg2);
            border-radius: 14px;
            padding: 14px 20px;
            font-size: 13px;
            color: var(--t2);
            border: 1px solid var(--sep2);
        }

        /* ── Upload success toast ── */
        #upload-success-toast {
            position: fixed;
            top: 50%; left: 50%;
            transform: translate(-50%,-50%) scale(0.8);
            background: var(--bg2);
            border: 1px solid var(--sep2);
            border-radius: 20px;
            padding: 32px 40px;
            text-align: center;
            z-index: 200;
            opacity: 0;
            transition: opacity 0.3s, transform 0.3s;
            pointer-events: none;
        }
        #upload-success-toast.show {
            opacity: 1; transform: translate(-50%,-50%) scale(1);
            pointer-events: all;
        }
        #upload-success-toast .checkmark {
            width: 60px; height: 60px;
            background: rgba(48,209,88,0.15);
            border-radius: 50%;
            display: flex; align-items: center; justify-content: center;
            font-size: 28px; color: var(--green);
            margin: 0 auto 16px;
        }
        #upload-success-toast h3 { font-size: 18px; font-weight: 700; margin-bottom: 8px; }
        #upload-success-toast p  { font-size: 14px; color: var(--t2); margin-bottom: 20px; }
        #upload-success-toast .btn-more {
            background: var(--bg3); color: var(--t1);
            border: none; border-radius: 12px;
            padding: 12px 24px; font-size: 15px; font-weight: 600;
            cursor: pointer; width: 100%;
        }

        /* ── Loading overlay ── */
        #loading-overlay {
            position: fixed; inset: 0;
            background: rgba(0,0,0,0.7);
            display: flex; align-items: center; justify-content: center;
            z-index: 300;
            opacity: 0;
            pointer-events: none;
            transition: opacity 0.2s;
        }
        #loading-overlay.show { opacity: 1; pointer-events: all; }
        .spinner {
            width: 44px; height: 44px;
            border: 3px solid var(--sep2);
            border-top-color: var(--blue);
            border-radius: 50%;
            animation: spin 0.7s linear infinite;
        }
        @keyframes spin { to { transform: rotate(360deg); } }

        /* ── Alert toast ── */
        .alert-toast {
            position: fixed;
            top: calc(env(safe-area-inset-top,16px) + 60px);
            left: 16px; right: 16px;
            background: var(--bg3);
            border-radius: 14px;
            padding: 14px 16px;
            display: flex; gap: 12px; align-items: flex-start;
            z-index: 400;
            transform: translateY(-20px);
            opacity: 0;
            transition: transform 0.3s, opacity 0.3s;
            border: 1px solid var(--sep2);
        }
        .alert-toast.show { transform: translateY(0); opacity: 1; }
        .alert-toast .toast-icon { font-size: 18px; flex-shrink: 0; margin-top: 1px; }
        .alert-toast .toast-body .toast-title { font-size: 14px; font-weight: 700; }
        .alert-toast .toast-body .toast-msg   { font-size: 13px; color: var(--t2); margin-top: 2px; }
        .alert-toast.danger  { border-color: rgba(255,55,95,0.3); }
        .alert-toast.success { border-color: rgba(48,209,88,0.3); }
        .alert-toast.info    { border-color: rgba(10,132,255,0.3); }
        .alert-toast .toast-icon.danger  { color: var(--red); }
        .alert-toast .toast-icon.success { color: var(--green); }
        .alert-toast .toast-icon.info    { color: var(--blue); }

        /* ── Modal personalizado estilo iOS ── */
        .ios-modal-overlay {
            position: fixed;
            top: 0; left: 0; right: 0; bottom: 0;
            background: rgba(0, 0, 0, 0.6);
            backdrop-filter: blur(20px);
            -webkit-backdrop-filter: blur(20px);
            z-index: 1000;
            display: flex;
            align-items: center;
            justify-content: center;
            padding: max(16px, env(safe-area-inset-top)) max(16px, env(safe-area-inset-right)) max(16px, env(safe-area-inset-bottom)) max(16px, env(safe-area-inset-left));
            opacity: 0;
            transition: opacity 0.3s ease;
            pointer-events: none;
        }
        .ios-modal-overlay.show {
            opacity: 1;
            pointer-events: all;
        }
        .ios-modal-overlay:not(.show) {
            pointer-events: none;
        }
        .ios-modal {
            background: var(--bg2);
            border-radius: 20px;
            border: 1px solid var(--sep2);
            width: 100%;
            max-width: 340px;
            overflow: hidden;
            transform: scale(0.9) translateY(20px);
            transition: transform 0.3s cubic-bezier(0.32, 0.72, 0, 1);
            box-shadow: 0 20px 60px rgba(0, 0, 0, 0.5);
        }
        .ios-modal-overlay.show .ios-modal {
            transform: scale(1) translateY(0);
        }
        .ios-modal-header {
            padding: 24px 20px 16px;
            text-align: center;
        }
        .ios-modal-icon {
            width: 64px;
            height: 64px;
            border-radius: 50%;
            background: rgba(255, 55, 95, 0.15);
            display: flex;
            align-items: center;
            justify-content: center;
            margin: 0 auto 16px;
            font-size: 32px;
            color: var(--red);
        }
        .ios-modal-title {
            font-size: 20px;
            font-weight: 700;
            color: var(--t1);
            margin-bottom: 8px;
            letter-spacing: -0.3px;
        }
        .ios-modal-message {
            font-size: 15px;
            color: var(--t2);
            line-height: 1.4;
        }
        .ios-modal-actions {
            padding: 8px 20px 20px;
            display: flex;
            flex-direction: column;
            gap: 8px;
        }
        .ios-modal-btn {
            width: 100%;
            height: 50px;
            border-radius: 12px;
            border: none;
            font-size: 17px;
            font-weight: 600;
            cursor: pointer;
            transition: all 0.15s;
            touch-action: manipulation;
            -webkit-tap-highlight-color: transparent;
            display: flex;
            align-items: center;
            justify-content: center;
            gap: 8px;
        }
        .ios-modal-btn-primary {
            background: var(--blue);
            color: #fff;
        }
        .ios-modal-btn-primary:active {
            background: #0071e3;
            transform: scale(0.98);
        }
        .ios-modal-btn-secondary {
            background: var(--bg3);
            color: var(--t1);
            border: 1px solid var(--sep2);
        }
        .ios-modal-btn-secondary:active {
            background: var(--bg4);
            transform: scale(0.98);
        }

        /* ── Debug strip (dentro de #app para no tapar grabaciones) ── */
        #debug-status {
            flex-shrink: 0;
            width: 100%;
            background: rgba(0,0,0,0.88);
            border-top: 1px solid var(--sep);
            padding: 6px 12px max(6px, env(safe-area-inset-bottom, 0px));
            font-size: 10px;
            color: var(--t2);
            font-family: 'SF Mono', 'Menlo', monospace;
            z-index: 50;
            display: block;
            word-break: break-word;
        }

        /* Pantallas bajas (iOS con barra de Safari, Android con chrome UI) */
        @media (max-height: 700px) {
            .recordings-panel {
                max-height: min(38vh, 260px);
                max-height: min(38dvh, 260px);
            }
            .waveform-wrap {
                margin-bottom: 10px;
                max-width: min(320px, 92vw);
            }
        }

        @media (max-height: 600px) {
            :root {
                --r-outer: clamp(130px, 42vw, 180px);
                --r-btn: clamp(56px, 18vw, 72px);
            }
            .recordings-panel {
                max-height: min(34vh, 200px);
                max-height: min(34dvh, 200px);
            }
            .recorder-main {
                justify-content: flex-start;
                padding-top: 8px;
            }
        }

        @media (max-width: 360px) {
            .top-bar { padding: 8px 12px 6px; }
            #workspace-info { margin-left: 12px; margin-right: 12px; }
            .top-bar .brand span { font-size: 11px; }
        }

        /*
         * Landscape en móvil: pantalla en dos columnas.
         * Izquierda: datos paciente/estudio arriba + lista de grabaciones abajo (scroll interno).
         * Derecha: waveform, timer, botón grabar y controles (columna centrada).
         */
        @media (orientation: landscape) and (max-height: 620px) {
            :root {
                --r-outer: clamp(100px, 28vh, 168px);
                --r-btn: clamp(50px, 12vh, 80px);
            }

            #app {
                display: grid;
                grid-template-columns: minmax(0, 1fr) minmax(240px, 44vw);
                grid-template-rows: auto auto minmax(0, 1fr) auto;
                column-gap: 10px;
                row-gap: 8px;
                align-content: stretch;
                align-items: stretch;
                min-height: 0;
            }

            #app > .top-bar {
                grid-column: 1 / -1;
                grid-row: 1;
                padding: 6px 12px 4px;
            }

            #app > #workspace-info {
                grid-column: 1;
                grid-row: 2;
                align-self: start;
                margin: 0 0 0 max(8px, env(safe-area-inset-left, 0px));
                margin-right: 0;
            }

            #app > #workspace-info .card-header {
                padding: 6px 10px;
            }
            #app > #workspace-info .card-body {
                padding: 6px 10px;
            }
            #app > #workspace-info .meta-value {
                font-size: 11px;
            }
            #app > #workspace-info .card-header .avatar {
                width: 32px;
                height: 32px;
                font-size: 14px;
            }
            #app > #workspace-info .card-header .name {
                font-size: 14px;
            }

            #app > #recordings-panel {
                grid-column: 1;
                grid-row: 3;
                align-self: stretch;
                max-height: none !important;
                height: 100%;
                min-height: 0;
                margin: 0 0 0 max(8px, env(safe-area-inset-left, 0px));
                margin-right: 0;
                margin-bottom: 0;
                border-radius: 12px;
                border: 1px solid var(--sep2);
            }

            #app > #recordings-panel.collapsed {
                max-height: 56px !important;
                height: auto;
                align-self: end;
            }

            #app > #recording-section {
                grid-column: 2;
                grid-row: 2 / 4;
                justify-self: stretch;
                align-self: stretch;
                display: flex;
                flex-direction: column;
                flex-wrap: nowrap;
                align-items: center;
                justify-content: center;
                gap: 0;
                padding: 8px 10px 10px;
                margin: 0 max(8px, env(safe-area-inset-right, 0px)) 0 0;
                min-height: 0;
                min-width: 0;
                overflow-x: hidden;
                overflow-y: auto;
                border-left: 1px solid var(--sep);
            }

            #app > #recording-section .waveform-wrap {
                order: unset;
                flex: 0 0 auto;
                width: 100%;
                max-width: min(280px, 100%);
                height: 40px;
                margin-bottom: 8px;
            }

            #app > #recording-section .timer-display {
                order: unset;
                flex: 0 0 auto;
                font-size: clamp(24px, 5.5vw, 40px);
                margin-bottom: 2px;
            }

            #app > #recording-section .timer-label {
                order: unset;
                flex: 0 0 auto;
                margin-bottom: 10px;
                margin-left: 0;
                max-width: min(260px, 100%);
                text-align: center;
                letter-spacing: 0.4px;
            }

            #app > #recording-section .btn-record-outer {
                order: unset;
                flex: 0 0 auto;
                margin-top: 0;
                margin-bottom: 10px;
            }

            #app > #recording-section .secondary-controls {
                order: unset;
                flex: 0 0 auto;
                gap: 12px;
            }

            #app > #debug-status {
                grid-column: 1 / -1;
                grid-row: 4 / 5;
                padding: 4px 10px max(4px, env(safe-area-inset-bottom, 0px));
                font-size: 9px;
            }

            .waveform-wrap.recording .wave-bar:nth-child(n) {
                animation-duration: 0.65s;
            }
        }

        /* Landscape muy bajo: compactar */
        @media (orientation: landscape) and (max-height: 400px) {
            :root {
                --r-outer: clamp(88px, 24vh, 130px);
                --r-btn: clamp(44px, 10vh, 64px);
            }
            #app > #recording-section .waveform-wrap {
                height: 30px;
                margin-bottom: 4px;
            }
            #app > #recording-section .timer-display {
                font-size: clamp(18px, 5vw, 30px);
            }
            #app > #recording-section .timer-label {
                margin-bottom: 6px;
            }
            #app > #recording-section .btn-record-outer {
                margin-bottom: 6px;
            }
        }
    </style>
</head>
<body>

<!-- ═══ Loading overlay ═══ -->
<div id="loading-overlay">
    <div class="spinner"></div>
</div>

<!-- ═══ Session Error screen ═══ -->
<div id="session-error" class="overlay-screen" style="display:none;">
    <div class="icon-wrap error"><i class="fas fa-qrcode"></i></div>
    <h2>Sesión Expirada</h2>
    <p>Esta sesión ya no es válida.<br>Por favor, escanea nuevamente el código QR desde el workspace.</p>
    <div class="overlay-badge">
        <i class="fas fa-shield-alt me-2"></i>Conexión segura requerida
    </div>
</div>

<!-- ═══ Standby screen (report finished) ═══ -->
<div id="standby-screen" class="overlay-screen" style="display:none;">
    <div class="icon-wrap standby"><i class="fas fa-clock"></i></div>
    <h2>En Espera</h2>
    <p>El informe fue finalizado.<br>Esperando que se abra un nuevo estudio en el workspace.</p>
    <div class="overlay-badge">
        <i class="fas fa-sync-alt me-2"></i>Se actualizará automáticamente
    </div>
</div>

<!-- ═══ Upload success toast ═══ -->
<div id="upload-success-toast">
    <div class="checkmark"><i class="fas fa-check"></i></div>
    <h3>¡Enviado!</h3>
    <p>Las grabaciones se subieron correctamente al workspace.</p>
    <button class="btn-more" onclick="mobileRecorder.dismissUploadSuccess()">
        <i class="fas fa-microphone me-2"></i>Grabar más
    </button>
</div>

<!-- ═══ Network status banner ═══ -->
<div id="network-banner">
    <i class="fas fa-wifi-slash" id="network-banner-icon"></i>
    <span id="network-banner-text">Sin conexión — grabaciones guardadas localmente</span>
</div>

<!-- ═══ Alert toasts container ═══ -->
<div id="toast-container"></div>

<!-- ═══ Modal personalizado estilo iOS ── -->
<div id="ios-modal-overlay" class="ios-modal-overlay">
    <div class="ios-modal">
        <div class="ios-modal-header">
            <div class="ios-modal-icon" id="ios-modal-icon">
                <i class="fas fa-exclamation-triangle"></i>
            </div>
            <div class="ios-modal-title" id="ios-modal-title">Título</div>
            <div class="ios-modal-message" id="ios-modal-message">Mensaje</div>
        </div>
        <div class="ios-modal-actions" id="ios-modal-actions">
            <!-- Los botones se agregan dinámicamente -->
        </div>
    </div>
</div>

<!-- ═══ Main app ═══ -->
<div id="app">

    <!-- Top bar -->
    <div class="top-bar">
        <div class="brand">
            <div class="cross">✚</div>
            <span>Grabadora Médica</span>
        </div>
        <div class="top-bar-right">
            <div id="connection-status">
                <div class="dot"></div>
                <span id="conn-label">Conectado</span>
            </div>
            <!-- Indicador Wake Lock: amarillo = pantalla bloqueada, gris = inactivo -->
            <div id="wakelock-dot" title="Pantalla puede apagarse"></div>
            <button id="fullscreen-btn"
                    title="Pantalla completa"
                    data-label="Pantalla completa"
                    aria-label="Alternar pantalla completa"
                    ontouchend="void(0)">
                <i class="fas fa-expand" id="fullscreen-icon"></i>
            </button>
        </div>
    </div>

    <!-- Patient card -->
    <div id="workspace-info" style="display:none;">
        <div class="card-header">
            <div class="avatar"><i class="fas fa-user-injured"></i></div>
            <div style="min-width:0;flex:1;">
                <div class="name" id="patient-name-display">—</div>
                <div class="pid" id="patient-id-display">—</div>
            </div>
        </div>
        <div class="card-body" id="study-meta-row">
            <div class="meta-item">
                <div class="meta-label">Modalidad</div>
                <div class="meta-value" id="meta-modality">—</div>
            </div>
            <div class="meta-item">
                <div class="meta-label">Fecha</div>
                <div class="meta-value" id="meta-date">—</div>
            </div>
            <div class="meta-item">
                <div class="meta-label">Estudio</div>
                <div class="meta-value" id="meta-desc" style="font-size:11px;">—</div>
            </div>
        </div>
    </div>

    <!-- Recorder main -->
    <div id="recording-section" class="recorder-main" style="display:none;">

        <!-- Waveform -->
        <div class="waveform-wrap idle" id="waveform">
            <div class="wave-bar"></div><div class="wave-bar"></div>
            <div class="wave-bar"></div><div class="wave-bar"></div>
            <div class="wave-bar"></div><div class="wave-bar"></div>
            <div class="wave-bar"></div><div class="wave-bar"></div>
            <div class="wave-bar"></div><div class="wave-bar"></div>
            <div class="wave-bar"></div><div class="wave-bar"></div>
            <div class="wave-bar"></div><div class="wave-bar"></div>
            <div class="wave-bar"></div><div class="wave-bar"></div>
            <div class="wave-bar"></div><div class="wave-bar"></div>
            <div class="wave-bar"></div><div class="wave-bar"></div>
        </div>

        <!-- Timer -->
        <div class="timer-display" id="timer">00:00</div>
        <div class="timer-label" id="timer-label">Listo para grabar</div>

        <!-- Record button -->
        <div class="btn-record-outer" id="record-outer">
            <!-- Anillo de pulso (pointer-events:none, no interfiere con el tap) -->
            <div class="pulse-ring"></div>
            <!-- Anillo de progreso SVG (pointer-events:none) -->
            <svg class="progress-ring" id="progress-ring" viewBox="0 0 102 102">
                <circle id="progress-circle" cx="51" cy="51" r="48"
                    fill="none" stroke="var(--red)" stroke-width="3"
                    stroke-linecap="round" stroke-dasharray="0 302"
                    style="pointer-events:none;"/>
            </svg>
            <button id="record-btn" title="Grabar" ontouchend="void(0)">
                <i class="fas fa-circle" id="record-icon"></i>
            </button>
        </div>

        <!-- Secondary controls -->
        <div class="secondary-controls">
            <button class="ctrl-btn pause" id="pause-btn" disabled title="Pausar">
                <i class="fas fa-pause" id="pause-icon"></i>
            </button>
            <button class="ctrl-btn stop" id="stop-btn" disabled title="Detener">
                <i class="fas fa-stop"></i>
            </button>
        </div>

    </div>

    <!-- Recordings bottom sheet -->
    <div class="recordings-panel" id="recordings-panel" style="display:none;">
        <!-- Pill decorativo estilo iOS -->
        <div class="recordings-pill"></div>
        <!-- Handle: toca para colapsar/expandir -->
        <div class="recordings-handle" id="recordings-handle">
            <div class="title">
                <i class="fas fa-waveform-lines" style="color:var(--t2);font-size:13px;"></i>
                Grabaciones
                <span class="badge-count hidden" id="badge-count">0</span>
            </div>
            <div class="recordings-handle-row">
                <button id="upload-btn" disabled>
                    <i class="fas fa-share-from-square"></i>
                    Enviar a workspace
                </button>
                <!-- Chevron — apunta ↑ cuando expandido, ↓ cuando colapsado -->
                <i class="fas fa-chevron-up recordings-chevron" id="recordings-chevron"></i>
            </div>
        </div>
        <div class="recordings-list-inner" id="recordings-list">
            <div class="recordings-empty">
                <i class="fas fa-microphone-slash" style="font-size:22px;margin-bottom:8px;display:block;"></i>
                Sin grabaciones aún
            </div>
        </div>
    </div>

    <!-- Barra de diagnóstico: dentro del flex de #app para no tapar el panel de grabaciones -->
    <div id="debug-status">
        <span id="debug-status-text">...</span>
    </div>

</div><!-- /#app -->

<script>
    /**
     * Relleno inferior = hueco entre el viewport visual y el layout (barras URL/toolbar).
     * Así #app no queda tapada por el chrome del navegador cuando no hay pantalla completa.
     */
    (function initViewportBottomPad() {
        const root = document.documentElement;
        function sync() {
            if (!window.visualViewport) {
                root.style.setProperty('--vv-bottom-padding', '0px');
                return;
            }
            const vv = window.visualViewport;
            const gap = Math.max(0, window.innerHeight - vv.height - vv.offsetTop);
            root.style.setProperty('--vv-bottom-padding', gap + 'px');
        }
        if (window.visualViewport) {
            window.visualViewport.addEventListener('resize', sync, { passive: true });
            window.visualViewport.addEventListener('scroll', sync, { passive: true });
        }
        window.addEventListener('resize', sync, { passive: true });
        document.addEventListener('fullscreenchange', sync);
        document.addEventListener('webkitfullscreenchange', sync);
        if (document.readyState === 'loading') {
            document.addEventListener('DOMContentLoaded', sync);
        } else {
            sync();
        }
    })();

    // ── Fullscreen on first interaction ──
    function requestFullscreen() {
        const el = document.documentElement;
        const rfs = el.requestFullscreen || el.webkitRequestFullscreen || el.mozRequestFullScreen || el.msRequestFullscreen;
        if (rfs) rfs.call(el).catch(() => {});
    }
    document.addEventListener('click', function onFirstClick() {
        requestFullscreen();
        document.removeEventListener('click', onFirstClick);
    }, { once: true });

    // ── Lock screen orientation to portrait ──
    if (screen.orientation && screen.orientation.lock) {
        screen.orientation.lock('portrait').catch(() => {});
    }

    class MobileAudioRecorder {
        constructor() {
            this.sessionId = this.getSessionId();
            this.workspaceId = null;
            this.studyId = null;
            this.stream = null;
            this.mediaRecorder = null;
            this.audioChunks = [];
            this.recordings = [];
            this.isRecording = false;
            this.isPaused = false;
            this.timerInterval = null;
            this.startTime = null;
            this.elapsedTime = 0;
            this.recordingSeconds = 0;
            // IndexedDB para persistencia de audios ante pérdida de conexión
            this.idb = null;
            this.IDB_NAME = 'mobile_audio_recorder_v1';
            this.IDB_STORE = 'pending_uploads';
            // Estado de red
            this.isOnline = navigator.onLine;
            
            // Variables para rastrear cambios en datos del estudio
            this.currentPatientName = null;
            this.currentPatientId = null;
            this.currentModality = null;
            this.currentStudyDate = null;
            this.currentStudyDescription = null;
            this.currentReportFinished = false;
            
            // Timestamp de la última actualización del estudio que vimos
            // El workspace actualiza este timestamp cada vez que cambia el estudio
            // Si este valor cambia, forzamos una actualización completa
            // Persistir en localStorage para que sobreviva recargas
            const stored = localStorage.getItem(`mobile_last_study_updated_${this.sessionId}`);
            this.lastStudyUpdatedAt = stored || null;
            if (stored) {
                console.log('📌 Restaurado lastStudyUpdatedAt desde localStorage:', stored);
            }

            // Contador de polls consecutivos con workspace_has_session=false
            // Se usa para implementar un período de gracia antes de declarar desconexión real
            // (evita desconexión falsa al cambiar de estudio, cuando el iframe se recarga ~2-3s)
            this.disconnectionCount = 0;

            // Wake Lock — mantiene la pantalla encendida
            this.wakeLock = null;
            
            // AudioContext compartido para sonidos (se inicializa en el primer evento de usuario)
            this.audioContext = null;
            this.audioContextInitialized = false;

            this.init();
        }
        
        getSessionId() {
            const urlParams = new URLSearchParams(window.location.search);
            return urlParams.get('session');
        }
        
        getBaseUrl() {
            return window.location.origin + window.location.pathname.replace('/mobile-audio-recorder.php', '');
        }
        
        /**
         * Inicializa el AudioContext si no está inicializado
         * El AudioContext requiere interacción del usuario en algunos navegadores
         */
        initAudioContext() {
            if (this.audioContextInitialized && this.audioContext) {
                return this.audioContext;
            }
            
            try {
                // Si el contexto está suspendido, intentar reanudarlo
                if (this.audioContext && this.audioContext.state === 'suspended') {
                    this.audioContext.resume();
                    this.audioContextInitialized = true;
                    return this.audioContext;
                }
                
                // Crear nuevo contexto
                this.audioContext = new (window.AudioContext || window.webkitAudioContext)();
                this.audioContextInitialized = true;
                return this.audioContext;
            } catch (error) {
                console.warn('No se pudo inicializar AudioContext:', error);
                return null;
            }
        }
        
        /**
         * Reproduce un sonido usando Web Audio API
         * @param {string} type - Tipo de sonido: 'start', 'stop', 'pause', 'resume', 'disconnect', 'connected', 'study_changed'
         */
        playSound(type) {
            try {
                const audioContext = this.initAudioContext();
                if (!audioContext) {
                    return; // No se pudo inicializar el contexto
                }
                
                let frequency = 440; // Frecuencia base (A4)
                let duration = 0.1; // Duración en segundos
                let volume = 0.3; // Volumen (0-1)
                
                switch(type) {
                    case 'start':
                        // Beep ascendente (inicio de grabación)
                        this.playToneSequence(audioContext, [
                            { freq: 440, dur: 0.08, vol: 0.6 },
                            { freq: 550, dur: 0.08, vol: 0.6 }
                        ]);
                        break;
                    case 'stop':
                        // Beep doble (fin de grabación)
                        this.playToneSequence(audioContext, [
                            { freq: 440, dur: 0.1, vol: 0.6 },
                            { freq: 0, dur: 0.05, vol: 0 }, // pausa
                            { freq: 440, dur: 0.1, vol: 0.6 }
                        ]);
                        break;
                    case 'pause':
                        // Beep corto y bajo (pausa)
                        this.playTone(audioContext, 330, 0.1, 0.5);
                        break;
                    case 'resume':
                        // Beep corto medio (reanudar)
                        this.playTone(audioContext, 440, 0.1, 0.5);
                        break;
                    case 'disconnect':
                        // Beep descendente (desconexión/error)
                        this.playToneSequence(audioContext, [
                            { freq: 550, dur: 0.1, vol: 0.6 },
                            { freq: 330, dur: 0.15, vol: 0.6 }
                        ]);
                        break;
                    case 'connected':
                        // Beep de conexión exitosa - dos tonos ascendentes
                        this.playToneSequence(audioContext, [
                            { freq: 550, dur: 0.1, vol: 0.6 },
                            { freq: 660, dur: 0.12, vol: 0.6 }
                        ]);
                        break;
                    case 'study_changed':
                        // Beep de notificación (cambio de estudio) - tres tonos ascendentes
                        this.playToneSequence(audioContext, [
                            { freq: 440, dur: 0.08, vol: 0.6 },
                            { freq: 0, dur: 0.05, vol: 0 }, // pausa
                            { freq: 550, dur: 0.08, vol: 0.6 },
                            { freq: 0, dur: 0.05, vol: 0 }, // pausa
                            { freq: 660, dur: 0.1, vol: 0.6 }
                        ]);
                        break;
                    default:
                        this.playTone(audioContext, frequency, duration, volume);
                }
            } catch (error) {
                console.warn('No se pudo reproducir sonido:', error);
            }
        }
        
        /**
         * Reproduce un tono simple
         */
        playTone(audioContext, frequency, duration, volume) {
            const oscillator = audioContext.createOscillator();
            const gainNode = audioContext.createGain();
            
            oscillator.connect(gainNode);
            gainNode.connect(audioContext.destination);
            
            oscillator.frequency.value = frequency;
            oscillator.type = 'sine';
            
            gainNode.gain.setValueAtTime(0, audioContext.currentTime);
            gainNode.gain.linearRampToValueAtTime(volume, audioContext.currentTime + 0.01);
            gainNode.gain.exponentialRampToValueAtTime(0.01, audioContext.currentTime + duration);
            
            oscillator.start(audioContext.currentTime);
            oscillator.stop(audioContext.currentTime + duration);
        }
        
        /**
         * Reproduce una secuencia de tonos
         */
        playToneSequence(audioContext, tones) {
            let currentTime = audioContext.currentTime;
            
            tones.forEach(tone => {
                if (tone.freq > 0) {
                    const oscillator = audioContext.createOscillator();
                    const gainNode = audioContext.createGain();
                    
                    oscillator.connect(gainNode);
                    gainNode.connect(audioContext.destination);
                    
                    oscillator.frequency.value = tone.freq;
                    oscillator.type = 'sine';
                    
                    gainNode.gain.setValueAtTime(0, currentTime);
                    gainNode.gain.linearRampToValueAtTime(tone.vol, currentTime + 0.01);
                    gainNode.gain.exponentialRampToValueAtTime(0.01, currentTime + tone.dur);
                    
                    oscillator.start(currentTime);
                    oscillator.stop(currentTime + tone.dur);
                }
                
                currentTime += tone.dur;
            });
        }
        
        debugLog(msg, type = 'info') {
            console.log(msg);
            const panel = document.getElementById('debug-status');
            const text  = document.getElementById('debug-status-text');
            if (panel && text) {
                panel.style.display = 'block';
                text.textContent = msg;
            }
        }

        async updateSessionStatus(status) {
            try {
                this.debugLog(`Enviando estado: ${status}...`);
                const baseUrl = this.getBaseUrl();
                const apiUrl  = `${baseUrl}/api/mobile_session.php`;
                console.log('updateSessionStatus URL:', apiUrl, 'sessionId:', this.sessionId, 'status:', status);
                
                const body = JSON.stringify({
                    action: 'update_status',
                    session_id: this.sessionId,
                    status: status
                });
                
                const response = await fetch(apiUrl, {
                    method: 'POST',
                    headers: { 'Content-Type': 'application/json' },
                    body: body
                });
                
                console.log('updateSessionStatus response status:', response.status);
                
                if (!response.ok) {
                    const text = await response.text();
                    this.debugLog(`❌ Error HTTP ${response.status}: ${text.substring(0, 100)}`, 'error');
                    return;
                }
                
                const result = await response.json();
                console.log('updateSessionStatus result:', result);
                
                if (result.success) {
                    this.debugLog(`✅ Estado "${status}" enviado OK`, 'success');
                } else {
                    this.debugLog(`❌ Error del servidor: ${result.error || result.message}`, 'error');
                }
            } catch (error) {
                this.debugLog(`❌ Error de red: ${error.message}`, 'error');
                console.error('Error actualizando estado de sesión:', error);
            }
        }

        async validateSession() {
            try {
                this.showLoading(true);
                this.debugLog('Contactando servidor...');
                
                if (!this.sessionId) {
                    this.debugLog('ERROR: No hay session ID', 'error');
                    return false;
                }
                
                const baseUrl  = this.getBaseUrl();
                const validUrl = `${baseUrl}/api/mobile_session.php?session_id=${this.sessionId}`;
                console.log('URL de validación:', validUrl);
                
                const response = await fetch(validUrl);
                const result   = await response.json();
                console.log('Resultado de validación:', result);
                
                if (result.success) {
                    this.debugLog('Sesión OK, cargando datos...', 'success');
                    
                    this.workspaceId             = result.data.workspace_id;
                    this.studyId                 = result.data.study_id;
                    this.currentPatientName      = result.data.patient_name;
                    this.currentPatientId        = result.data.patient_id;
                    this.currentModality         = result.data.modality;
                    this.currentStudyDate        = result.data.study_date;
                    this.currentStudyDescription = result.data.study_description;
                    this.currentReportFinished   = result.data.report_finished || false;
                    
                    this.displayWorkspaceInfo(result.data);
                    this.showRecordingSection();
                    console.log('✅ Info del workspace mostrada:', result.data);
                    return true;
                } else {
                    this.debugLog(`❌ Sesión inválida: ${result.error}`, 'error');
                    this.showSessionError();
                    return false;
                }
            } catch (error) {
                this.debugLog(`❌ Error de red: ${error.message}`, 'error');
                console.error('Error en validateSession:', error);
                this.showSessionError();
                return false;
            } finally {
                this.showLoading(false);
            }
        }

        async init() {
            this.debugLog('Iniciando... Session: ' + this.sessionId);
            
            if (!this.sessionId) {
                this.debugLog('ERROR: No hay session ID en la URL', 'error');
                this.showSessionError();
                return;
            }

            // Inicializar IndexedDB para persistencia de audios
            await this.initIndexedDB();

            // Monitorear estado de la red
            window.addEventListener('online',  () => this.onNetworkRestored());
            window.addEventListener('offline', () => this.onNetworkLost());
            
            this.debugLog('Validando sesión con el servidor...');
            const validationSuccess = await this.validateSession();
            
            if (validationSuccess) {
                this.debugLog('Sesión válida, notificando conexión...', 'success');
                await this.updateSessionStatus('connected');
                this.playSound('connected');
                this.debugLog('✅ Conectado', 'success');

                // Verificar si hay grabaciones pendientes de enviar (de sesiones anteriores)
                await this.recoverPendingUploads();
            } else {
                this.debugLog('❌ Sesión inválida o expirada', 'error');
                // Aunque la sesión no valide, informar si quedaron blobs en IndexedDB
                // (el usuario puede volver a escanear el QR; recoverPendingUploads requiere sesión OK para subir)
                try {
                    const stuck = await this.getAllPendingFromIndexedDB();
                    if (stuck.length > 0) {
                        this.showAlert(
                            'Grabaciones en este dispositivo',
                            `Hay ${stuck.length} grabación(es) guardada(s) localmente. Cuando recuperes una sesión válida (código QR), el sistema te ofrecerá resguardarlas en el servidor.`,
                            'info',
                            8000
                        );
                    }
                } catch (_e) { /* ignorar */ }
            }
            
            this.setupEventListeners();
            this.startTokenMonitoring();

            // Mantener pantalla encendida mientras la grabadora está activa
            await this.acquireWakeLock();
        }
        
        /* ─── Screen Wake Lock ─────────────────────────────────────── */
        async acquireWakeLock() {
            if (!('wakeLock' in navigator)) {
                this.debugLog('⚠️ Wake Lock no soportado en este navegador', 'info');
                return;
            }
            try {
                this.wakeLock = await navigator.wakeLock.request('screen');
                this.debugLog('🔆 Pantalla bloqueada (no se apagará)', 'success');
                this.syncWakeLockIcon(true);

                // Se libera automáticamente al minimizar/bloquear; re-adquirir al volver
                this.wakeLock.addEventListener('release', () => {
                    this.debugLog('🔅 Wake Lock liberado por el sistema', 'info');
                    this.syncWakeLockIcon(false);
                    this.wakeLock = null;
                });
            } catch (err) {
                this.debugLog(`⚠️ Wake Lock: ${err.message}`, 'info');
            }
        }

        async releaseWakeLock() {
            if (this.wakeLock) {
                await this.wakeLock.release();
                this.wakeLock = null;
                this.syncWakeLockIcon(false);
            }
        }

        syncWakeLockIcon(active) {
            const dot = document.getElementById('wakelock-dot');
            if (dot) {
                dot.title = active ? 'Pantalla bloqueada — no se apagará' : 'Pantalla puede apagarse';
                dot.style.background = active ? 'var(--yellow, #ffd60a)' : 'var(--sep2)';
                dot.style.boxShadow  = active ? '0 0 5px var(--yellow, #ffd60a)' : 'none';
            }
        }
        /* ──────────────────────────────────────────────────────────── */

        startTokenMonitoring() {
            this.tokenMonitorInterval = setInterval(async () => {
                await this.checkTokenValidity();
            }, 5000);
            console.log('Monitoreo de token iniciado (cada 5 segundos)');

            // Visibilidad: manejar suspensión de Safari/iOS cuando la app pasa a segundo plano
            document.addEventListener('visibilitychange', async () => {
                if (document.visibilityState === 'visible') {
                    this.debugLog('🔆 Pantalla visible de nuevo — reanudando polling...', 'info');

                    // Re-adquirir Wake Lock si se perdió
                    if (!this.wakeLock) {
                        await this.acquireWakeLock();
                    }

                    // iOS pausa los setInterval mientras la pestaña está en segundo plano.
                    // Al volver a visible puede haber pasado tiempo sin polling, lo que hace
                    // que el workspace NO reciba check_workspace=1 y baje mobile_last_seen,
                    // disparando el timeout de 50s en workspace. Solucionamos:
                    // 1. Hacer un poll inmediato para actualizar mobile_last_seen en el servidor.
                    // 2. Resetear el contador de desconexiones para que el período de gracia
                    //    empiece desde cero (evita falsa desconexión por tiempo en background).
                    // 3. Reiniciar el intervalo de polling para asegurar cadencia correcta.
                    this.disconnectionCount = 0;
                    await this.checkTokenValidity(); // poll inmediato

                    // Reiniciar el intervalo de polling (puede haberse desacelerado en iOS)
                    if (this.tokenMonitorInterval) {
                        clearInterval(this.tokenMonitorInterval);
                        this.tokenMonitorInterval = null;
                    }
                    this.tokenMonitorInterval = setInterval(async () => {
                        await this.checkTokenValidity();
                    }, 5000);
                    this.debugLog('✅ Polling reiniciado tras volver a primer plano', 'info');
                }
            });

            // Función para cerrar sesión cuando el usuario cierra/navega fuera de la grabadora
            const handleCloseSession = () => {
                if (!this.sessionId) return;
                const baseUrl = this.getBaseUrl();
                const url     = `${baseUrl}/api/mobile_session.php`;
                const payload = JSON.stringify({ action: 'close', session_id: this.sessionId });
                // sendBeacon es la única forma fiable de enviar datos al cerrar
                if (navigator.sendBeacon) {
                    const sent = navigator.sendBeacon(url, new Blob([payload], { type: 'application/json' }));
                    if (sent) {
                        this.debugLog('👋 Sesión cerrada al salir (beacon enviado)', 'info');
                    } else {
                        console.warn('⚠️ No se pudo enviar beacon para cerrar sesión');
                    }
                } else {
                    // Fallback: intentar fetch con keepalive
                    fetch(url, {
                        method: 'POST',
                        headers: { 'Content-Type': 'application/json' },
                        body: payload,
                        keepalive: true
                    }).catch(() => {
                        // Ignorar errores durante beforeunload
                    });
                }
            };
            
            // Usar beforeunload (se ejecuta antes de cerrar)
            window.addEventListener('beforeunload', handleCloseSession);
            
            // También usar pagehide (más confiable en móviles y cuando se cierra la ventana directamente)
            window.addEventListener('pagehide', (event) => {
                // Solo cerrar sesión si la página se está descargando permanentemente
                if (event.persisted === false) {
                    handleCloseSession();
                }
            });
        }
        
        stopTokenMonitoring() {
            if (this.tokenMonitorInterval) {
                clearInterval(this.tokenMonitorInterval);
                this.tokenMonitorInterval = null;
            }
        }
        
        async checkTokenValidity() {
            try {
                const baseUrl = this.getBaseUrl();
                // Agregar check_workspace=1 para verificar si el workspace tiene la sesión activa
                // y un timestamp (_=) para evitar cualquier caché intermedio del navegador/proxy
                const apiUrl  = `${baseUrl}/api/mobile_session.php?session_id=${this.sessionId}&check_workspace=1&_=${Date.now()}`;
                const now     = new Date().toLocaleTimeString();
                this.debugLog(`🔍 Sondeando sesión (${now})...`, 'info');
                
                // Forzar que el navegador no use caché en absoluto
                const response = await fetch(apiUrl, {
                    method: 'GET',
                    cache: 'no-store',
                    headers: {
                        'Cache-Control': 'no-cache, no-store, must-revalidate',
                        'Pragma': 'no-cache'
                    }
                });
                const result   = await response.json();
                
                if (!result.success) {
                    console.warn('⚠️ Token inválido o expirado');
                    this.debugLog('❌ Sesión inválida o expirada', 'error');
                    this.handleTokenInvalidation();
                    return;
                }

                // Verificar si el workspace tiene la sesión activa.
                // workspace_has_session=true  → last_activity fue actualizado por el workspace hace < 2 min.
                // workspace_has_session=false → el workspace lleva más de 2 minutos sin actividad (cerró).
                // NOTA: el polling del móvil (GET) YA NO actualiza last_activity (fix en servidor),
                //       así que last_activity solo lo actualiza el workspace via PUT update_study.
                // IMPORTANTE: NO usar study_updated_at como indicador de actividad porque puede ser antiguo
                //            y aún así el workspace puede estar desconectado. Usar solo workspace_has_session
                //            que está basado en last_activity (el indicador correcto).
                
                if (result.data.workspace_has_session === false) {
                    // ── Período de gracia para cambio de estudio / pestaña oculta ─────
                    // El workspace puede tener last_activity viejo si:
                    //   a) La pestaña estuvo oculta (otra app en primer plano en escritorio)
                    //   b) El iframe se recargó al abrir un nuevo estudio (~2-3s)
                    //   c) Hubo pérdida de red momentánea en el workspace
                    // Con polls de 5s, GRACE_POLLS=6 da 30s de gracia antes de declarar
                    // desconexión real, suficiente para cubrir esos casos sin falsos positivos.
                    this.disconnectionCount = (this.disconnectionCount || 0) + 1;
                    const GRACE_POLLS = 6; // 6 × 5s = 30 segundos de gracia

                    console.warn(`⚠️ Workspace sin actividad (poll ${this.disconnectionCount}/${GRACE_POLLS}):`, {
                        workspace_has_session: result.data.workspace_has_session,
                        last_activity: result.data.last_activity,
                        study_updated_at: result.data.study_updated_at
                    });

                    if (this.disconnectionCount < GRACE_POLLS) {
                        // Aún dentro del período de gracia: mostrar estado de espera pero no desconectar
                        this.debugLog(`⏳ Workspace sin respuesta (${this.disconnectionCount}/${GRACE_POLLS})... reconectando`, 'warning');
                        return; // Seguir monitoreando — no desconectar todavía
                    }

                    // Desconexión confirmada: pasaron 30s sin actividad del workspace
                    this.debugLog('⚠️ Workspace desconectado (tiempo de gracia agotado)', 'warning');
                    this.handleWorkspaceDisconnected();
                    return;
                }

                // Workspace activo → resetear contador de desconexiones
                if (this.disconnectionCount > 0) {
                    console.log(`✅ Workspace reconectado después de ${this.disconnectionCount} poll(s) sin actividad`);
                    this.disconnectionCount = 0;
                }

                // ── Comparar study_updated_at para detectar cambios del workspace ──
                // El workspace actualiza study_updated_at cada vez que cambia el estudio
                // Si este timestamp cambia, significa que el workspace actualizó los datos
                const serverStudyUpdatedAt = result.data.study_updated_at || null;
                
                // Log detallado de la comparación de timestamps
                console.log('🔍 Comparando study_updated_at:', {
                    local: this.lastStudyUpdatedAt,
                    servidor: serverStudyUpdatedAt,
                    sonIguales: this.lastStudyUpdatedAt === serverStudyUpdatedAt,
                    localEsNull: this.lastStudyUpdatedAt === null,
                    servidorEsNull: serverStudyUpdatedAt === null,
                    tipoLocal: typeof this.lastStudyUpdatedAt,
                    tipoServidor: typeof serverStudyUpdatedAt
                });
                
                // Verificar también si study_id o workspace_id cambiaron (backup check)
                const studyIdChanged = this.studyId !== null && 
                                      result.data.study_id !== null && 
                                      this.studyId !== result.data.study_id;
                const workspaceIdChanged = this.workspaceId !== null && 
                                          result.data.workspace_id !== null && 
                                          this.workspaceId !== result.data.workspace_id;
                
                // Determinar si hubo actualización:
                // 1. Si tenemos un valor local Y un valor del servidor Y son diferentes → cambio detectado
                // 2. O si study_id o workspace_id cambiaron (verificación adicional)
                const studyWasUpdated = (this.lastStudyUpdatedAt !== null && 
                                       serverStudyUpdatedAt !== null && 
                                       serverStudyUpdatedAt !== '' &&
                                       this.lastStudyUpdatedAt !== serverStudyUpdatedAt) ||
                                       studyIdChanged ||
                                       workspaceIdChanged;
                
                // Log adicional para debugging
                if (studyWasUpdated) {
                    if (this.lastStudyUpdatedAt !== serverStudyUpdatedAt) {
                        console.log('✅ CAMBIO DETECTADO - study_updated_at cambió');
                    }
                    if (studyIdChanged) {
                        console.log('✅ CAMBIO DETECTADO - study_id cambió:', {
                            anterior: this.studyId,
                            nuevo: result.data.study_id
                        });
                    }
                    if (workspaceIdChanged) {
                        console.log('✅ CAMBIO DETECTADO - workspace_id cambió:', {
                            anterior: this.workspaceId,
                            nuevo: result.data.workspace_id
                        });
                    }
                } else if (this.lastStudyUpdatedAt === null && serverStudyUpdatedAt) {
                    console.log('ℹ️ Primera vez recibiendo study_updated_at, guardando...');
                } else if (this.lastStudyUpdatedAt === serverStudyUpdatedAt) {
                    console.log('✓ study_updated_at sin cambios');
                } else {
                    console.log('⚠️ Estado inesperado en comparación de timestamps');
                }
                
                if (studyWasUpdated) {
                    // Determinar si hay un cambio real de estudio (IDs diferentes)
                    // Solo usar forceUpdate si realmente cambió el estudio, no solo por el timestamp
                    const hasRealStudyChange = studyIdChanged || workspaceIdChanged;
                    
                    if (hasRealStudyChange) {
                        console.log('🔔 CAMBIO REAL DE ESTUDIO DETECTADO:', {
                            study_id_changed: studyIdChanged,
                            workspace_id_changed: workspaceIdChanged,
                            anterior_study_id: this.studyId,
                            nuevo_study_id: result.data.study_id
                        });
                        this.debugLog('🔔 Workspace cambió el estudio - forzando actualización...', 'info');
                        // Forzar actualización cuando hay cambio real de estudio
                        await this.checkStudyDataChanges(result.data, true); // true = forzar actualización
                    } else {
                        // Solo cambió el timestamp pero no los IDs → actualización periódica sin cambio de estudio
                        console.log('🔄 Actualización periódica del workspace (sin cambio de estudio)');
                        this.debugLog('🔄 Workspace actualizó datos (sin cambio de estudio)...', 'info');
                        // Actualizar datos normalmente sin forzar (la comparación normal detectará cambios reales)
                        await this.checkStudyDataChanges(result.data, false);
                    }
                    
                    // Actualizar lastStudyUpdatedAt después de procesar el cambio
                    this.lastStudyUpdatedAt = serverStudyUpdatedAt;
                    localStorage.setItem(`mobile_last_study_updated_${this.sessionId}`, serverStudyUpdatedAt);
                    console.log('✅ lastStudyUpdatedAt actualizado y guardado:', serverStudyUpdatedAt);
                } else {
                    // Si es la primera vez o no hay cambio en el timestamp, usar comparación normal
                    if (this.lastStudyUpdatedAt === null && serverStudyUpdatedAt) {
                        // Primera vez: guardar el timestamp
                        this.lastStudyUpdatedAt = serverStudyUpdatedAt;
                        localStorage.setItem(`mobile_last_study_updated_${this.sessionId}`, serverStudyUpdatedAt);
                        console.log('📌 Guardando study_updated_at inicial:', serverStudyUpdatedAt);
                    } else if (this.lastStudyUpdatedAt === serverStudyUpdatedAt) {
                        console.log('✓ study_updated_at sin cambios, usando comparación normal de datos');
                    }
                    
                    // Log detallado de los datos recibidos antes de comparar
                    console.log('📥 Datos recibidos del servidor:', {
                        study_id: result.data.study_id,
                        workspace_id: result.data.workspace_id,
                        patient_name: result.data.patient_name,
                        patient_id: result.data.patient_id,
                        modality: result.data.modality,
                        study_description: result.data.study_description,
                        study_updated_at: serverStudyUpdatedAt
                    });
                    
                    // Log del estado actual antes de comparar
                    console.log('📊 Estado actual en móvil:', {
                        studyId: this.studyId,
                        workspaceId: this.workspaceId,
                        currentPatientName: this.currentPatientName,
                        currentPatientId: this.currentPatientId,
                        currentModality: this.currentModality,
                        currentStudyDescription: this.currentStudyDescription,
                        lastStudyUpdatedAt: this.lastStudyUpdatedAt
                    });

                    // checkStudyDataChanges ya detecta y maneja todos los cambios (study_id, workspace_id, patient data, etc.)
                    this.debugLog(`📋 Sesión: ${result.data.patient_name || 'Sin paciente'} | Estudio: ${result.data.study_id || 'N/A'}`, 'info');
                    await this.checkStudyDataChanges(result.data, false);
                }
            } catch (error) {
                console.error('Error verificando validez del token:', error);
                this.debugLog(`❌ Error verificando sesión: ${error.message}`, 'error');
            }
        }
        
        async checkStudyDataChanges(sessionData, forceUpdate = false) {
            const currentData = {
                study_id:          this.studyId,
                workspace_id:      this.workspaceId,
                patient_name:      this.currentPatientName,
                patient_id:        this.currentPatientId,
                modality:          this.currentModality,
                study_date:        this.currentStudyDate,
                study_description: this.currentStudyDescription,
                report_finished:   this.currentReportFinished
            };
            
            const newData = {
                study_id:          sessionData.study_id          || null,
                workspace_id:      sessionData.workspace_id      || null,
                patient_name:      sessionData.patient_name      || null,
                patient_id:        sessionData.patient_id        || null,
                modality:          sessionData.modality          || null,
                study_date:        sessionData.study_date        || null,
                study_description: sessionData.study_description || null,
                report_finished:   sessionData.report_finished   || false
            };
            
            const normalize = (val) => {
                if (val === null || val === undefined || val === '') return null;
                return String(val).trim();
            };
            
            const normalizedCurrent = {
                study_id:          normalize(currentData.study_id),
                workspace_id:      normalize(currentData.workspace_id),
                patient_name:      normalize(currentData.patient_name),
                patient_id:        normalize(currentData.patient_id),
                modality:          normalize(currentData.modality),
                study_date:        normalize(currentData.study_date),
                study_description: normalize(currentData.study_description)
            };
            
            const normalizedNew = {
                study_id:          normalize(newData.study_id),
                workspace_id:      normalize(newData.workspace_id),
                patient_name:      normalize(newData.patient_name),
                patient_id:        normalize(newData.patient_id),
                modality:          normalize(newData.modality),
                study_date:        normalize(newData.study_date),
                study_description: normalize(newData.study_description)
            };
            
            // Log detallado para debugging - siempre mostrar comparación completa
            const comparisonLog = {
                current_study_id: normalizedCurrent.study_id,
                new_study_id: normalizedNew.study_id,
                study_id_match: normalizedCurrent.study_id === normalizedNew.study_id,
                current_workspace_id: normalizedCurrent.workspace_id,
                new_workspace_id: normalizedNew.workspace_id,
                workspace_id_match: normalizedCurrent.workspace_id === normalizedNew.workspace_id,
                current_patient_name: normalizedCurrent.patient_name,
                new_patient_name: normalizedNew.patient_name,
                patient_name_match: normalizedCurrent.patient_name === normalizedNew.patient_name,
                current_patient_id: normalizedCurrent.patient_id,
                new_patient_id: normalizedNew.patient_id,
                patient_id_match: normalizedCurrent.patient_id === normalizedNew.patient_id
            };
            console.log('🔍 Comparando datos (normalizados):', comparisonLog);
            
            // Comparación robusta: cualquier diferencia en study_id o workspace_id indica cambio de estudio
            const studyChanged = normalizedCurrent.study_id     !== normalizedNew.study_id ||
                                 normalizedCurrent.workspace_id !== normalizedNew.workspace_id;
            
            // Log específico si hay cambio de estudio
            if (studyChanged) {
                console.log('🔄 CAMBIO DE ESTUDIO DETECTADO:', {
                    study_id_changed: normalizedCurrent.study_id !== normalizedNew.study_id,
                    workspace_id_changed: normalizedCurrent.workspace_id !== normalizedNew.workspace_id,
                    from_study: normalizedCurrent.study_id,
                    to_study: normalizedNew.study_id,
                    from_workspace: normalizedCurrent.workspace_id,
                    to_workspace: normalizedNew.workspace_id
                });
            }
            
            const dataChanged  = normalizedCurrent.patient_name      !== normalizedNew.patient_name ||
                                 normalizedCurrent.patient_id        !== normalizedNew.patient_id   ||
                                 normalizedCurrent.modality          !== normalizedNew.modality     ||
                                 normalizedCurrent.study_date        !== normalizedNew.study_date   ||
                                 normalizedCurrent.study_description !== normalizedNew.study_description;
            
            const comparisonDetails = [];
            if (normalizedCurrent.study_id   !== normalizedNew.study_id)   comparisonDetails.push(`study_id: "${normalizedCurrent.study_id}" → "${normalizedNew.study_id}"`);
            if (normalizedCurrent.patient_name !== normalizedNew.patient_name) comparisonDetails.push(`patient_name: "${normalizedCurrent.patient_name}" → "${normalizedNew.patient_name}"`);
            if (normalizedCurrent.patient_id !== normalizedNew.patient_id)  comparisonDetails.push(`patient_id: "${normalizedCurrent.patient_id}" → "${normalizedNew.patient_id}"`);
            
            // Si forceUpdate es true, forzar actualización incluso si los datos parecen iguales
            // Esto es útil cuando el workspace notifica un cambio mediante study_updated_at
            const shouldUpdate = forceUpdate || studyChanged || dataChanged;
            
            if (!shouldUpdate) {
                this.debugLog('✓ Sin cambios detectados', 'info');
            } else {
                if (forceUpdate) {
                    this.debugLog('🔔 ACTUALIZACIÓN FORZADA por notificación del workspace', 'info');
                } else {
                    this.debugLog(`🔍 Cambios detectados: estudio=${studyChanged}, datos=${dataChanged}`, 'info');
                    if (comparisonDetails.length > 0) this.debugLog(`📝 Detalles: ${comparisonDetails.join(' | ')}`, 'info');
                }
            }
            
            if (shouldUpdate) {
                this.debugLog('🔄 CAMBIO DETECTADO - Actualizando datos del estudio...', 'info');
                
                // Guardar valores anteriores para logging
                const oldValues = {
                    studyId: this.studyId,
                    workspaceId: this.workspaceId,
                    patientName: this.currentPatientName
                };
                
                // Actualizar todas las variables internas
                this.studyId                 = newData.study_id;
                this.workspaceId             = newData.workspace_id;
                this.currentPatientName      = newData.patient_name;
                this.currentPatientId        = newData.patient_id;
                this.currentModality         = newData.modality;
                this.currentStudyDate        = newData.study_date;
                this.currentStudyDescription = newData.study_description;
                
                // Log detallado de la actualización
                console.log('✅ Variables actualizadas:', {
                    study_id: { from: oldValues.studyId, to: this.studyId },
                    workspace_id: { from: oldValues.workspaceId, to: this.workspaceId },
                    patient_name: { from: oldValues.patientName, to: this.currentPatientName }
                });
                
                this.debugLog(`✅ Variables actualizadas: paciente="${newData.patient_name}", estudio="${newData.study_id}"`, 'success');
                
                this.displayWorkspaceInfo(sessionData);
                this.debugLog('✅ UI actualizada', 'success');
                
                // Actualizar lastStudyUpdatedAt si viene en los datos (para mantener sincronización)
                // Esto es importante para mantener el estado sincronizado después de una actualización
                if (sessionData.study_updated_at) {
                    const previousTimestamp = this.lastStudyUpdatedAt;
                    this.lastStudyUpdatedAt = sessionData.study_updated_at;
                    localStorage.setItem(`mobile_last_study_updated_${this.sessionId}`, sessionData.study_updated_at);
                    if (previousTimestamp !== sessionData.study_updated_at) {
                        console.log('📌 lastStudyUpdatedAt actualizado después de procesar cambio:', {
                            anterior: previousTimestamp,
                            nuevo: sessionData.study_updated_at
                        });
                    }
                }
                
                // Limpiar grabaciones SOLO si hay un cambio real de estudio
                // forceUpdate solo fuerza la actualización de datos/UI, pero NO indica un cambio de estudio
                // El aviso y la limpieza solo deben ocurrir cuando realmente cambia el estudio
                if (studyChanged) {
                    console.log('🔄 Cambio de estudio detectado — verificando grabaciones pendientes...');
                    this._handleStudyChangeWithPendingRecordings();
                } else if (forceUpdate) {
                    // Si es forceUpdate pero NO hay cambio de estudio, solo actualizar datos/UI
                    // Esto puede pasar cuando el workspace actualiza periódicamente sin cambiar el estudio
                    console.log('🔄 Actualización forzada sin cambio de estudio - solo actualizando datos/UI');
                }
            } else {
                // Log cuando NO hay cambios para debugging
                console.log('✓ Sin cambios detectados - valores coinciden');
            }
            
            if (!currentData.report_finished && newData.report_finished) {
                this.debugLog('✅ Informe finalizado, volviendo a estado standby...', 'success');
                this.handleReportFinished();
            } else if (currentData.report_finished && !newData.report_finished) {
                this.debugLog('🔄 Nuevo estudio detectado (informe desfinalizado), limpiando estado...', 'info');
                this.currentReportFinished = false;
                const standby = document.getElementById('standby-screen');
                if (standby) standby.style.display = 'none';
            }
            
            this.currentReportFinished = newData.report_finished;
        }

        handleReportFinished() {
            if (this.isRecording) this.stopRecording();
            this.recordings = [];
            this.updateRecordingsList();
            const standby = document.getElementById('standby-screen');
            if (standby) standby.style.display = 'flex';
        }
        
        handleTokenInvalidation() {
            this.stopTokenMonitoring();
            this.showSessionError();
        }
        
        handleTokenChange() {
            // El cambio de estudio se maneja en checkStudyDataChanges
        }

        /**
         * Intercepta el cambio de estudio cuando hay grabaciones aún no enviadas al workspace.
         * Si todas están en workspace_ready, limpia directamente.
         * Si hay pendientes (backup / pending / error), ofrece opciones al usuario.
         */
        _handleStudyChangeWithPendingRecordings() {
            const doSwitch = () => {
                this.recordings = [];
                this.updateRecordingsList();
                this.playSound('study_changed');
                this.showAlert('Nuevo estudio', 'Se ha cambiado el estudio en el workspace.', 'info');
            };

            // Detenemos grabación activa si la hay
            if (this.isRecording) {
                this.stopRecording().catch(() => {});
            }

            const backupRecs  = this.recordings.filter(r => r.status === 'backup');
            const unsavedRecs = this.recordings.filter(r => r.status === 'pending' || r.status === 'error');
            const hasPending  = backupRecs.length > 0 || unsavedRecs.length > 0;

            if (!hasPending) {
                // Todo ya estaba en workspace — cambio directo sin aviso
                doSwitch();
                return;
            }

            // Construir mensaje informativo
            let msg = '';
            if (backupRecs.length > 0 && unsavedRecs.length > 0) {
                msg = `Tenés ${backupRecs.length} grabación(es) resguardada(s) en el servidor y ${unsavedRecs.length} aún sin subir al servidor. ¿Qué hacemos antes de cambiar de estudio?`;
            } else if (backupRecs.length > 0) {
                msg = `Tenés ${backupRecs.length} grabación(es) resguardada(s) en el servidor (no publicadas en workspace). ¿Qué hacemos antes de cambiar de estudio?`;
            } else {
                msg = `Tenés ${unsavedRecs.length} grabación(es) que aún no llegaron al servidor. ¿Qué hacemos antes de cambiar de estudio?`;
            }

            const buttons = [];

            // Opción 1: Enviar al workspace y luego cambiar (si hay algo enviable)
            const hasSendable = this.recordings.some(r =>
                r.status === 'backup' || r.status === 'pending' || r.status === 'error'
            );
            if (hasSendable) {
                buttons.push({
                    text: 'Enviar al workspace y cambiar',
                    primary: true,
                    icon: 'fa-paper-plane',
                    onClick: async () => {
                        try {
                            await this.uploadRecordings();
                        } catch (e) {
                            console.warn('⚠️ Error enviando grabaciones al cambiar estudio:', e);
                        }
                        doSwitch();
                    }
                });
            }

            // Opción 2: si hay backup (seguras en servidor), ofrecer continuar sin enviar a workspace
            if (backupRecs.length > 0) {
                buttons.push({
                    text: `Cambiar estudio (quedan en papelera del servidor)`,
                    icon: 'fa-exchange-alt',
                    onClick: doSwitch
                });
            }

            // Opción 3: si solo hay en dispositivo y no backup, dejarlas ir
            if (unsavedRecs.length > 0 && backupRecs.length === 0) {
                buttons.push({
                    text: 'Descartar y cambiar estudio',
                    icon: 'fa-trash-alt',
                    onClick: doSwitch
                });
            }

            this.showIOSModal(
                '¡Grabaciones pendientes!',
                msg,
                'fa-exclamation-circle',
                'var(--orange)',
                buttons
            );
        }

        handleWorkspaceDisconnected() {
            this.stopTokenMonitoring();
            this.releaseWakeLock();
            
            // Detener cualquier grabación en curso
            if (this.isRecording) {
                this.stopRecording();
            }
            
            // Sonido de desconexión
            this.playSound('disconnect');
            
            // Guardar snapshot de datos del estudio para poder restaurarlos si reconecta
            const snapshotStudyData = {
                patient_name:      this.currentPatientName,
                patient_id:        this.currentPatientId,
                modality:          this.currentModality,
                study_date:        this.currentStudyDate,
                study_description: this.currentStudyDescription,
                workspace_id:      this.workspaceId,
                study_id:          this.studyId
            };

            // Limpiar datos del paciente/estudio
            this.currentPatientName      = null;
            this.currentPatientId        = null;
            this.currentModality         = null;
            this.currentStudyDate        = null;
            this.currentStudyDescription = null;
            this.workspaceId             = null;
            this.studyId                 = null;
            
            // Actualizar UI para mostrar estado de desconexión
            this.displayWorkspaceInfo({
                patient_name: null,
                patient_id: null,
                modality: null,
                study_date: null,
                study_description: null
            });
            
            // Ocultar controles de grabación
            const recordBtn = document.getElementById('recordButton');
            const pauseBtn  = document.getElementById('pauseButton');
            const stopBtn   = document.getElementById('stopButton');
            const uploadBtn = document.getElementById('uploadButton');
            
            if (recordBtn) recordBtn.style.display = 'none';
            if (pauseBtn)  pauseBtn.style.display  = 'none';
            if (stopBtn)   stopBtn.style.display   = 'none';
            if (uploadBtn) uploadBtn.style.display = 'none';
            
            this.debugLog('🔌 Workspace desconectado - sesión cerrada', 'warning');
            
            // Función para cerrar la ventana
            const closeWindow = () => {
                try {
                    window.close();
                    setTimeout(() => {
                        if (!document.hidden) {
                            window.location.href = 'about:blank';
                        }
                    }, 500);
                } catch (e) {
                    window.location.href = 'about:blank';
                }
            };

            // Intento de reconexión: verifica si el workspace volvió a estar activo
            const tryReconnect = async () => {
                this.debugLog('🔄 Intentando reconectar con el workspace...', 'info');

                // Mostrar estado de reconexión mientras se verifica
                this.showIOSModal(
                    'Reconectando...',
                    'Verificando conexión con el workspace. Por favor espera.',
                    'fa-sync-alt',
                    'var(--blue)',
                    [] // sin botones mientras espera
                );

                try {
                    const baseUrl = this.getBaseUrl();
                    const apiUrl  = `${baseUrl}/api/mobile_session.php?session_id=${this.sessionId}&check_workspace=1&_=${Date.now()}`;
                    const response = await fetch(apiUrl, { method: 'GET', cache: 'no-store' });
                    const result   = await response.json();

                    if (result.success && result.data.workspace_has_session === true) {
                        // ✅ Workspace volvió a estar activo
                        this.hideIOSModal();
                        this.debugLog('✅ Reconexión exitosa con el workspace', 'success');

                        // Restaurar datos del estudio desde el snapshot
                        this.currentPatientName      = result.data.patient_name      || snapshotStudyData.patient_name;
                        this.currentPatientId        = result.data.patient_id        || snapshotStudyData.patient_id;
                        this.currentModality         = result.data.modality          || snapshotStudyData.modality;
                        this.currentStudyDate        = result.data.study_date        || snapshotStudyData.study_date;
                        this.currentStudyDescription = result.data.study_description || snapshotStudyData.study_description;
                        this.workspaceId             = result.data.workspace_id      || snapshotStudyData.workspace_id;
                        this.studyId                 = result.data.study_id          || snapshotStudyData.study_id;

                        // Restaurar UI del paciente/estudio
                        this.displayWorkspaceInfo(result.data);

                        // Volver a mostrar controles de grabación
                        if (recordBtn) recordBtn.style.display = '';
                        if (pauseBtn)  pauseBtn.style.display  = '';
                        if (stopBtn)   stopBtn.style.display   = '';
                        if (uploadBtn) uploadBtn.style.display = '';

                        // Resetear contador de desconexiones y reiniciar polling
                        this.disconnectionCount = 0;
                        await this.acquireWakeLock();
                        this.tokenMonitorInterval = setInterval(async () => {
                            await this.checkTokenValidity();
                        }, 5000);

                        this.showAlert('Reconectado', 'Conexión con el workspace reestablecida.', 'success', 3000);
                        this.playSound('connect');
                    } else {
                        // ❌ El workspace sigue sin estar activo → volver al modal original
                        this.debugLog('❌ El workspace sigue desconectado', 'warning');
                        showDisconnectedModal();
                    }
                } catch (err) {
                    this.debugLog(`❌ Error al reconectar: ${err.message}`, 'error');
                    showDisconnectedModal();
                }
            };

            // Función que muestra el modal de desconexión (reutilizable para el caso de fallo de reconexión)
            const showDisconnectedModal = () => {
                const pendingCount = this.recordings.filter(r => r.status !== 'ok').length;
                const reconnectBtn = {
                    text: 'Intentar reconectar',
                    icon: 'fa-sync-alt',
                    onClick: tryReconnect
                };

                if (pendingCount > 0) {
                    this.showIOSModal(
                        'Sesión Cerrada',
                        `El workspace se desconectó y hay ${pendingCount} grabación(es) sin enviar. Podés intentar reconectar o descargarlas al dispositivo.`,
                        'fa-exclamation-triangle',
                        'var(--orange)',
                        [
                            reconnectBtn,
                            {
                                text: 'Descargar grabaciones',
                                primary: true,
                                icon: 'fa-download',
                                onClick: () => {
                                    this.downloadAllPendingAudios();
                                    setTimeout(closeWindow, 2500);
                                }
                            },
                            {
                                text: 'Cerrar sin descargar',
                                icon: 'fa-times',
                                onClick: closeWindow
                            }
                        ]
                    );
                } else {
                    this.showIOSModal(
                        'Sesión Cerrada',
                        'El workspace cerró sesión o se desconectó. Todas las grabaciones fueron enviadas correctamente.',
                        'fa-sign-out-alt',
                        'var(--red)',
                        [
                            reconnectBtn,
                            {
                                text: 'Entendido',
                                primary: true,
                                icon: 'fa-check',
                                onClick: closeWindow
                            }
                        ]
                    );
                }
            };

            showDisconnectedModal();
        }

        // ── Mostrar info del workspace con diseño mejorado ──
        displayWorkspaceInfo(sessionData) {
            try {
                const infoEl = document.getElementById('workspace-info');
                if (!infoEl) return;
                
                // Nombre del paciente
                const nameEl = document.getElementById('patient-name-display');
                const pidEl  = document.getElementById('patient-id-display');
                if (nameEl) nameEl.textContent = this.escapeHtml(sessionData.patient_name || 'Paciente desconocido');
                if (pidEl)  pidEl.textContent  = sessionData.patient_id ? 'ID: ' + this.escapeHtml(sessionData.patient_id) : '';
                
                // Meta datos
                const modalityEl = document.getElementById('meta-modality');
                const dateEl     = document.getElementById('meta-date');
                const descEl     = document.getElementById('meta-desc');
                if (modalityEl) modalityEl.textContent = this.escapeHtml(sessionData.modality    || '—');
                if (dateEl)     dateEl.textContent     = this.escapeHtml(sessionData.study_date   || '—');
                if (descEl)     descEl.textContent     = this.escapeHtml(sessionData.study_description
                    ? sessionData.study_description.substring(0, 20) : '—');
                
                infoEl.style.display = 'block';
                console.log('✅ Info del workspace mostrada:', sessionData);
            } catch (e) {
                console.error('Error mostrando info workspace:', e);
            }
        }
        
        escapeHtml(text) {
            if (!text) return '';
            const div = document.createElement('div');
            div.textContent = text;
            return div.innerHTML;
        }
        
        setupEventListeners() {
            console.log('=== CONFIGURANDO EVENT LISTENERS ===');
            
            // Inicializar AudioContext en el primer evento de usuario (requerido por algunos navegadores)
            const initAudioOnInteraction = () => {
                if (!this.audioContextInitialized) {
                    this.initAudioContext();
                }
            };
            
            // Inicializar AudioContext en cualquier interacción del usuario
            document.addEventListener('touchstart', initAudioOnInteraction, { once: true });
            document.addEventListener('click', initAudioOnInteraction, { once: true });
            document.addEventListener('keydown', initAudioOnInteraction, { once: true });
            
            document.getElementById('record-btn').onclick     = () => { initAudioOnInteraction(); this.toggleRecording(); };
            document.getElementById('pause-btn').onclick      = () => { initAudioOnInteraction(); this.togglePause(); };
            document.getElementById('stop-btn').onclick       = () => { initAudioOnInteraction(); this.stopRecording(); };
            document.getElementById('fullscreen-btn').onclick = () => { initAudioOnInteraction(); this.toggleFullscreen(); };

            // Botón Enviar — stopPropagation para que no active el toggle del panel
            document.getElementById('upload-btn').onclick = (e) => {
                e.stopPropagation();
                this.uploadRecordings();
            };

            // Toggle colapso del panel de grabaciones (toca el handle)
            document.getElementById('recordings-handle').onclick = () => this.toggleRecordingsPanel();

            // Actualizar ícono si el usuario sale de pantalla completa con gesto del sistema
            document.addEventListener('fullscreenchange',       () => this.syncFullscreenIcon());
            document.addEventListener('webkitfullscreenchange', () => this.syncFullscreenIcon());
            document.addEventListener('mozfullscreenchange',    () => this.syncFullscreenIcon());

            console.log('=== EVENT LISTENERS CONFIGURADOS ===');
        }

        /* ─── Toggle recordings panel ─── */
        toggleRecordingsPanel() {
            const panel = document.getElementById('recordings-panel');
            if (!panel) return;
            const isCollapsed = panel.classList.toggle('collapsed');
            // Haptic sutil
            if (navigator.vibrate) navigator.vibrate(6);
            this.debugLog(isCollapsed ? '📂 Grabaciones ocultas' : '📂 Grabaciones visibles', 'info');
        }

        /* ─── Fullscreen ─── */
        toggleFullscreen() {
            const isFs = !!(
                document.fullscreenElement ||
                document.webkitFullscreenElement ||
                document.mozFullScreenElement
            );

            if (isFs) {
                // Salir
                const exit = document.exitFullscreen ||
                             document.webkitExitFullscreen ||
                             document.mozCancelFullScreen;
                if (exit) exit.call(document);
            } else {
                // Entrar — en iOS Safari la API Fullscreen no está disponible,
                // pero sí webkitRequestFullscreen en el elemento
                const el  = document.documentElement;
                const req = el.requestFullscreen ||
                            el.webkitRequestFullscreen ||
                            el.mozRequestFullScreen;
                if (req) {
                    req.call(el).catch(() => {
                        // iOS Safari (no soporta la API): fallback informativo
                        this.debugLog('💡 Agrega al inicio de pantalla para pantalla completa nativa', 'info');
                        this.showAlert(
                            'Pantalla completa',
                            'En iPhone Safari, toca Compartir → "Agregar a inicio de pantalla" para una experiencia de pantalla completa.',
                            'info'
                        );
                    });
                } else {
                    this.showAlert(
                        'Pantalla completa',
                        'Tu navegador no soporta esta función. En iPhone, agrega la app al inicio de pantalla.',
                        'info'
                    );
                }
            }
            this.syncFullscreenIcon();
            // Haptic feedback si disponible
            if (navigator.vibrate) navigator.vibrate(8);
        }

        syncFullscreenIcon() {
            const isFs = !!(
                document.fullscreenElement ||
                document.webkitFullscreenElement ||
                document.mozFullScreenElement
            );
            const icon = document.getElementById('fullscreen-icon');
            const btn  = document.getElementById('fullscreen-btn');
            if (!icon || !btn) return;
            icon.className = isFs ? 'fas fa-compress' : 'fas fa-expand';
            btn.classList.toggle('active', isFs);
            btn.setAttribute('data-label', isFs ? 'Salir de pantalla completa' : 'Pantalla completa');
            btn.title = isFs ? 'Salir de pantalla completa' : 'Pantalla completa';
        }

        toggleRecording() {
            if (this.isRecording) {
                this.stopRecording();
            } else {
                this.startRecording();
            }
        }

        togglePause() {
            if (!this.isRecording) return;
            if (this.isPaused) {
                this.resumeRecording();
            } else {
                this.pauseRecording();
            }
        }
        
        async startRecording() {
            try {
                console.log('=== INICIO startRecording ===');
                this.debugLog('⏺ Iniciando grabación...', 'info');
                
                // Feedback visual inmediato mientras se pide permiso
                const btn = document.getElementById('record-btn');
                if (btn) { btn.style.opacity = '0.6'; }
                
                if (!navigator.mediaDevices || !navigator.mediaDevices.getUserMedia) {
                    throw new Error('Tu navegador no soporta grabación de audio. Usa Chrome o Safari.');
                }
                
                this.stream = await navigator.mediaDevices.getUserMedia({
                    audio: {
                        echoCancellation: true,
                        noiseSuppression: true,
                        autoGainControl: true
                    }
                });
                
                const options = { mimeType: 'audio/webm;codecs=opus', audioBitsPerSecond: 128000 };
                if (!MediaRecorder.isTypeSupported(options.mimeType)) {
                    options.mimeType = 'audio/webm';
                    if (!MediaRecorder.isTypeSupported(options.mimeType)) {
                        options.mimeType = 'audio/mp4';
                        if (!MediaRecorder.isTypeSupported(options.mimeType)) {
                            options.mimeType = '';
                        }
                    }
                }
                
                this.mediaRecorder = options.mimeType
                    ? new MediaRecorder(this.stream, options)
                    : new MediaRecorder(this.stream);
                
                this.audioChunks = [];
                
                this.mediaRecorder.ondataavailable = (event) => {
                    if (event.data.size > 0) this.audioChunks.push(event.data);
                };
                
                this.mediaRecorder.onstop = async () => {
                    const audioBlob = new Blob(this.audioChunks, { type: this.mediaRecorder.mimeType || 'audio/webm' });
                    const audioUrl  = URL.createObjectURL(audioBlob);
                    const finalDuration = this.lastRecordedDuration ?? this.elapsedTime ?? 0;
                    const recording = {
                        id:           Date.now(),
                        url:          audioUrl,
                        blob:         audioBlob,
                        timestamp:    new Date().toLocaleString(),
                        duration:     finalDuration,
                        status:       'uploading', // uploading | ok | pending | error
                        sessionId:    this.sessionId,
                        studyId:      this.studyId
                    };
                    this.recordings.push(recording);
                    this.updateRecordingsList();
                    // Auto-subida inmediata al servidor + respaldo en IndexedDB
                    await this.autoUploadRecording(recording);
                };
                
                this.mediaRecorder.onerror = (event) => {
                    console.error('Error en MediaRecorder:', event.error);
                    this.showAlert('Error de grabación', event.error.message, 'danger');
                };
                
                this.mediaRecorder.start(100);
                this.isRecording = true;
                this.isPaused    = false;
                this.startTime   = Date.now() - this.elapsedTime;
                this.recordingSeconds = 0;
                this.startTimer();
                
                // Restaurar opacidad y actualizar UI
                const btn2 = document.getElementById('record-btn');
                if (btn2) btn2.style.opacity = '';
                this.setRecordingUI(true);
                
                // Notificar al workspace que el móvil está grabando
                this._pushMobileStatus('recording');
                
                // Vibración y sonido de inicio
                if (navigator.vibrate) navigator.vibrate(50);
                this.playSound('start');
                
                console.log('✅ Grabación iniciada');
                
            } catch (error) {
                console.error('Error iniciando grabación:', error);
                this.debugLog(`❌ Error: ${error.message}`, 'error');
                // Restaurar opacidad del botón si falló
                const btn = document.getElementById('record-btn');
                if (btn) btn.style.opacity = '';
                this.handleRecordingError(error);
            }
        }
        
        pauseRecording() {
            if (!this.isRecording || this.isPaused) return;
            this.mediaRecorder.pause();
            this.isPaused = true;
            this.stopTimer();
            if (navigator.vibrate) navigator.vibrate(30);
            this.playSound('pause');
            // UI
            document.getElementById('pause-icon').className = 'fas fa-play';
            document.getElementById('pause-btn').title = 'Reanudar';
            document.getElementById('waveform').className = 'waveform-wrap paused';
            document.getElementById('record-outer').classList.remove('recording');
            document.getElementById('timer-label').textContent = 'Pausado';
            document.getElementById('timer-label').className   = 'timer-label paused';
        }
        
        resumeRecording() {
            if (!this.isRecording || !this.isPaused) return;
            this.mediaRecorder.resume();
            this.isPaused = false;
            this.startTime = Date.now() - this.elapsedTime;
            this.startTimer();
            if (navigator.vibrate) navigator.vibrate(30);
            this.playSound('resume');
            // UI
            document.getElementById('pause-icon').className = 'fas fa-pause';
            document.getElementById('pause-btn').title = 'Pausar';
            document.getElementById('waveform').className = 'waveform-wrap recording';
            document.getElementById('record-outer').classList.add('recording');
            document.getElementById('timer-label').textContent = 'Grabando';
            document.getElementById('timer-label').className   = 'timer-label recording';
        }
        
        stopRecording() {
            if (!this.isRecording) return;
            
            if (this.mediaRecorder && this.mediaRecorder.state !== 'inactive') {
                this.mediaRecorder.stop();
            }
            if (this.stream) {
                this.stream.getTracks().forEach(track => track.stop());
                this.stream = null;
            }
            
            this.isRecording = false;
            this.isPaused    = false;
            this.stopTimer();
            this.lastRecordedDuration = this.elapsedTime;
            this.elapsedTime = 0;
            
            // Sincronizar estado con workspace justo al detener la grabación
            this._syncMobileStatus();
            
            if (navigator.vibrate) navigator.vibrate([30, 30, 80]);
            this.playSound('stop');
            this.setRecordingUI(false);
            console.log('✅ Grabación detenida');
        }
        
        setRecordingUI(recording) {
            const recordBtn   = document.getElementById('record-btn');
            const recordOuter = document.getElementById('record-outer');
            const recordIcon  = document.getElementById('record-icon');
            const pauseBtn    = document.getElementById('pause-btn');
            const stopBtn     = document.getElementById('stop-btn');
            const waveform    = document.getElementById('waveform');
            const timerLabel  = document.getElementById('timer-label');
            
            if (recording) {
                recordIcon.className   = 'fas fa-square'; // square = stop look on main btn
                recordOuter.classList.add('recording');
                waveform.className     = 'waveform-wrap recording';
                timerLabel.textContent = 'Grabando';
                timerLabel.className   = 'timer-label recording';
                pauseBtn.disabled      = false;
                stopBtn.disabled       = false;
            } else {
                recordIcon.className   = 'fas fa-circle';
                recordOuter.classList.remove('recording');
                waveform.className     = 'waveform-wrap idle';
                timerLabel.textContent = 'Listo para grabar';
                timerLabel.className   = 'timer-label';
                document.getElementById('timer').textContent = '00:00';
                pauseBtn.disabled      = true;
                stopBtn.disabled       = true;
                document.getElementById('pause-icon').className = 'fas fa-pause';
                document.getElementById('pause-btn').title      = 'Pausar';
            }
        }
        
        startTimer() {
            this.timerInterval = setInterval(() => {
                this.elapsedTime = Date.now() - this.startTime;
                const minutes = Math.floor(this.elapsedTime / 60000);
                const seconds = Math.floor((this.elapsedTime % 60000) / 1000);
                document.getElementById('timer').textContent =
                    `${String(minutes).padStart(2,'0')}:${String(seconds).padStart(2,'0')}`;
                
                // Update progress ring (max 5 min = 300s)
                this.recordingSeconds = this.elapsedTime / 1000;
                this.updateProgressRing(this.recordingSeconds / 300);
            }, 100);
        }
        
        stopTimer() {
            if (this.timerInterval) {
                clearInterval(this.timerInterval);
                this.timerInterval = null;
            }
            this.updateProgressRing(0);
        }
        
        updateProgressRing(progress) {
            const circle = document.getElementById('progress-circle');
            if (!circle) return;
            const circumference = 2 * Math.PI * 48; // r=48 fijo en el SVG (viewBox 102x102)
            const dasharray = Math.min(progress, 1) * circumference;
            circle.setAttribute('stroke-dasharray', `${dasharray.toFixed(1)} ${circumference.toFixed(1)}`);
        }
        
        updateRecordingsList() {
            const listContainer = document.getElementById('recordings-list');
            const uploadBtn     = document.getElementById('upload-btn');
            const badgeCount    = document.getElementById('badge-count');
            const panel         = document.getElementById('recordings-panel');
            
            if (this.recordings.length === 0) {
                listContainer.innerHTML = `
                    <div class="recordings-empty">
                        <i class="fas fa-microphone-slash" style="font-size:22px;margin-bottom:8px;display:block;"></i>
                        Sin grabaciones aún
                    </div>`;
                uploadBtn.disabled = true;
                badgeCount.classList.add('hidden');
                return;
            }
            
            badgeCount.textContent = this.recordings.length;
            badgeCount.classList.remove('hidden');
            panel.style.display = 'flex';
            
            listContainer.innerHTML = '';
            this.recordings.forEach((recording, index) => {
                // ── Badge de estado visual ──
                let statusHtml = '';
                const st = recording.status || 'backup';

                if (st === 'uploading') {
                    // Subiendo respaldo al servidor
                    statusHtml = `<div class="rec-status status-uploading">
                        <i class="fas fa-spinner fa-spin" style="font-size:9px;"></i> Guardando respaldo...
                    </div>`;
                } else if (st === 'backup') {
                    // En papelera como respaldo, aún no en workspace
                    statusHtml = `<div class="rec-status status-backup">
                        <i class="fas fa-cloud" style="font-size:9px;"></i> Guardado como respaldo
                    </div>`;
                } else if (st === 'sending_workspace') {
                    // Enviando al workspace
                    statusHtml = `<div class="rec-status status-uploading">
                        <i class="fas fa-spinner fa-spin" style="font-size:9px;"></i> Enviando al workspace...
                    </div>`;
                } else if (st === 'workspace_ready') {
                    // Publicado en workspace
                    statusHtml = `<div class="rec-status status-ok">
                        <i class="fas fa-check" style="font-size:9px;"></i> En workspace
                    </div>`;
                } else if (st === 'pending') {
                    // Sin conexión, en IDB local
                    statusHtml = `<div class="rec-status status-pending">
                        <i class="fas fa-clock" style="font-size:9px;"></i> Sin conexión — en dispositivo
                        <button class="retry-btn" onclick="mobileRecorder.autoUploadRecording(mobileRecorder.recordings.find(r=>r.id===${recording.id}))">Reintentar</button>
                    </div>`;
                } else if (st === 'error') {
                    // Error al subir respaldo
                    statusHtml = `<div class="rec-status status-error">
                        <i class="fas fa-exclamation-triangle" style="font-size:9px;"></i> Error al resguardar
                        <button class="retry-btn" onclick="mobileRecorder.autoUploadRecording(mobileRecorder.recordings.find(r=>r.id===${recording.id}))">Reintentar respaldo</button>
                    </div>`;
                }

                // Botón eliminar: no disponible mientras está subiendo o enviando
                const canDelete = st !== 'uploading' && st !== 'sending_workspace';

                const item = document.createElement('div');
                item.className = 'recording-item';
                item.setAttribute('data-id', recording.id);
                item.innerHTML = `
                    <div class="rec-info">
                        <div class="rec-name">Grabación ${index + 1}${recording.fromRecovery ? ' <span style="font-size:10px;color:var(--orange);">(recuperada)</span>' : ''}</div>
                        <div class="rec-meta">${recording.timestamp} · ${this.formatDuration(recording.duration)}</div>
                        ${statusHtml}
                        <audio controls src="${recording.url}" style="margin-top:6px;"></audio>
                    </div>
                    <div class="rec-actions">
                        <button class="share-btn" onclick="mobileRecorder.shareRecording(${recording.id})" title="Compartir audio por WhatsApp">
                            <i class="fas fa-share-alt"></i>
                        </button>
                        ${canDelete ? `<button class="del-btn" onclick="mobileRecorder.deleteRecording(${recording.id})" title="Eliminar">
                            <i class="fas fa-trash-alt"></i>
                        </button>` : ``}
                    </div>
                `;
                listContainer.appendChild(item);
            });
            
            // Habilitar botón "Enviar" si hay grabaciones listas para enviar al workspace
            // (backup = en servidor como respaldo, pending/error = en dispositivo)
            const hasSendable = this.recordings.some(r =>
                r.status === 'backup' || r.status === 'pending' || r.status === 'error'
            );
            uploadBtn.disabled = !hasSendable;
        }
        
        async deleteRecording(id) {
            const rec = this.recordings.find(r => r.id === id);
            if (!rec) return;

            if (navigator.vibrate) navigator.vibrate(30);

            // Si ya fue subido al servidor, marcar como eliminado en la BD
            if (rec.serverAudioId) {
                await this.markAsDeletedOnServer(rec.serverAudioId);
            }

            // Limpiar también de IndexedDB si aún estuviera
            await this.removeFromIndexedDB(id);

            // Quitar de la lista local
            this.recordings = this.recordings.filter(r => r.id !== id);
            this.updateRecordingsList();
            this._syncMobileStatus(); // notificar workspace del nuevo estado
        }
        
        formatDuration(ms) {
            const minutes = Math.floor(ms / 60000);
            const seconds = Math.floor((ms % 60000) / 1000);
            return `${String(minutes).padStart(2,'0')}:${String(seconds).padStart(2,'0')}`;
        }

        buildShareText(recording) {
            const lines = [
                'Audio de estudio médico',
                `Paciente: ${this.currentPatientName || '—'}`,
                `ID paciente: ${this.currentPatientId || '—'}`,
                `Modalidad: ${this.currentModality || '—'}`,
                `Fecha estudio: ${this.currentStudyDate || '—'}`,
                `Descripción: ${this.currentStudyDescription || '—'}`,
                `Fecha grabación: ${recording?.timestamp || new Date().toLocaleString()}`
            ];
            return lines.join('\n');
        }

        getFileExtensionFromMime(mimeType) {
            const mime = (mimeType || '').toLowerCase();
            if (mime.includes('webm')) return 'webm';
            if (mime.includes('mp4')) return 'mp4';
            if (mime.includes('ogg')) return 'ogg';
            if (mime.includes('mpeg') || mime.includes('mp3')) return 'mp3';
            if (mime.includes('wav')) return 'wav';
            if (mime.includes('aac')) return 'aac';
            return 'webm';
        }

        sanitizeFilePart(value, fallback = 'NA') {
            const normalized = (value || '')
                .toString()
                .trim()
                .normalize('NFD')
                .replace(/[\u0300-\u036f]/g, '')
                .replace(/[^a-zA-Z0-9]+/g, '_')
                .replace(/^_+|_+$/g, '');
            return normalized || fallback;
        }

        buildShareFileName(recording, ext) {
            const patientName = this.sanitizeFilePart(this.currentPatientName || 'paciente');
            const patientId = this.sanitizeFilePart(this.currentPatientId || 'sinid');
            let recordingDate = recording?.timestamp ? new Date(recording.timestamp) : new Date();
            if (Number.isNaN(recordingDate.getTime())) {
                recordingDate = new Date();
            }
            const yyyy = recordingDate.getFullYear();
            const mm = String(recordingDate.getMonth() + 1).padStart(2, '0');
            const dd = String(recordingDate.getDate()).padStart(2, '0');
            const datePart = `${yyyy}${mm}${dd}`;
            return `${patientName}_${patientId}_${datePart}.${ext}`;
        }

        async shareRecording(id) {
            const recording = this.recordings.find(r => r.id === id);
            if (!recording) {
                this.showAlert('Información', 'No se encontró la grabación para compartir.', 'warning', 3000);
                return;
            }

            const shareText = this.buildShareText(recording);
            const mimeType = recording.blob?.type || 'audio/webm';
            const ext = this.getFileExtensionFromMime(mimeType);
            const fileName = this.buildShareFileName(recording, ext);

            try {
                if (!navigator.share) {
                    throw new Error('SHARE_NOT_SUPPORTED');
                }

                let shared = false;

                if (recording.blob) {
                    const file = new File([recording.blob], fileName, { type: mimeType });
                    try {
                        await navigator.share({
                            title: 'Audio de estudio',
                            text: shareText,
                            files: [file]
                        });
                        shared = true;
                    } catch (errFileOnly) {
                        // Reintento 2: mismo archivo como binario genérico (documento)
                        try {
                            const genericFile = new File(
                                [recording.blob],
                                fileName,
                                { type: 'application/octet-stream' }
                            );
                            await navigator.share({
                                title: 'Audio de estudio',
                                text: shareText,
                                files: [genericFile]
                            });
                            shared = true;
                        } catch (errGenericFile) {
                            // Reintento 3: declarar tipo audio/mpeg y extensión .mp3 (algunas apps lo aceptan mejor)
                            try {
                                const mp3LikeFile = new File(
                                    [recording.blob],
                                    fileName.replace(/\.[^.]+$/, '.mp3'),
                                    { type: 'audio/mpeg' }
                                );
                                await navigator.share({
                                    title: 'Audio de estudio',
                                    text: shareText,
                                    files: [mp3LikeFile]
                                });
                                shared = true;
                            } catch (errMp3LikeFile) {}
                        }
                    }
                }

                // Si ningún intento de share funcionó, forzar fallback visible.
                if (!shared) {
                    throw new Error('FILE_SHARE_FAILED');
                }
            } catch (error) {
                if (error && (error.name === 'AbortError' || error.message === 'The user aborted a request.')) {
                    return;
                }
                await this.shareRecordingFallback(recording, fileName, shareText);
            }
        }

        async shareRecordingFallback(recording, fileName, shareText) {
            try {
                const a = document.createElement('a');
                a.href = recording.url;
                a.download = fileName;
                document.body.appendChild(a);
                a.click();
                document.body.removeChild(a);
            } catch (e) {}

            try {
                const waAppUrl = `whatsapp://send?text=${encodeURIComponent(shareText)}`;
                window.location.href = waAppUrl;
            } catch (e) {}

            this.showAlert(
                'Audio descargado',
                `No se pudo adjuntar audio directo. Se abrió WhatsApp con el texto y el audio se descargó como "${fileName}" para adjuntarlo manualmente.`,
                'warning',
                6500
            );
        }
        
        async uploadRecordings() {
            // Solo procesar grabaciones que aún no están en workspace ni en proceso
            const candidates = this.recordings.filter(r =>
                r.status !== 'workspace_ready' && r.status !== 'sending_workspace'
            );
            if (candidates.length === 0) {
                this.showAlert('Información', 'Todas las grabaciones ya fueron enviadas al workspace.', 'info', 3000);
                return;
            }

            try {
                console.log('=== INICIO uploadRecordings (Enviar al workspace) ===');
                this.showLoading(true);

                let successCount = 0;
                let errorCount   = 0;

                for (const recording of candidates) {
                    recording.status = 'sending_workspace';
                    this.updateRecordingsList();

                    try {
                        if (recording.serverAudioId) {
                            // Ya está en servidor como respaldo → solo cambiar estado a listo_workspace
                            await this.publishToWorkspace(recording.serverAudioId);
                        } else {
                            // Todavía en IDB o pendiente → subir y luego publicar
                            const result = await this.uploadSingleRecording(recording);
                            recording.serverAudioId = result?.data?.audio_id || null;
                            await this.removeFromIndexedDB(recording.id);
                            if (recording.serverAudioId) {
                                await this.publishToWorkspace(recording.serverAudioId);
                            }
                        }
                        recording.status = 'workspace_ready';
                        successCount++;
                        console.log(`✅ Audio ${recording.id} enviado al workspace (serverAudioId: ${recording.serverAudioId})`);
                    } catch (err) {
                        recording.status = recording.serverAudioId ? 'backup' : 'error';
                        errorCount++;
                        console.warn(`⚠️ Error enviando audio ${recording.id} al workspace:`, err.message);
                    }
                    this.updateRecordingsList();
                }

                if (errorCount === 0) {
                    this.showUploadSuccess();
                    if (navigator.vibrate) navigator.vibrate([50, 30, 100]);
                } else if (successCount > 0) {
                    this.showAlert('Parcialmente enviado', `${successCount} enviada(s) al workspace, ${errorCount} con error.`, 'warning', 5000);
                } else {
                    this.showAlert('Error', 'No se pudieron enviar al workspace. Verifica tu conexión.', 'danger', 5000);
                }

            } catch (error) {
                console.error('=== ERROR en uploadRecordings ===', error);
                this.showAlert('Error', 'Error inesperado: ' + error.message, 'danger');
            } finally {
                this.showLoading(false);
                this._syncMobileStatus(); // notificar workspace con estado real post-envío
            }
        }
        
        handleRecordingError(error) {
            let msg = 'Error desconocido al iniciar la grabación.';
            if (error.name === 'NotAllowedError'  || error.name === 'PermissionDeniedError') msg = 'Permisos de micrófono denegados. Permite el acceso al micrófono en tu navegador.';
            else if (error.name === 'NotFoundError')    msg = 'No se encontró ningún micrófono en este dispositivo.';
            else if (error.name === 'NotReadableError') msg = 'El micrófono está siendo usado por otra aplicación.';
            this.showAlert('Micrófono no disponible', msg, 'danger');
        }
        
        showRecordingSection() {
            document.getElementById('recording-section').style.display = 'flex';
            document.getElementById('recordings-panel').style.display  = 'flex';
        }
        
        showSessionError() {
            const el = document.getElementById('session-error');
            if (el) el.style.display = 'flex';
            const rs = document.getElementById('recording-section');
            if (rs) rs.style.display = 'none';
            
            const cs = document.getElementById('connection-status');
            if (cs) cs.className = 'disconnected';
            const cl = document.getElementById('conn-label');
            if (cl) cl.textContent = 'Sin sesión';
        }
        
        showUploadSuccess() {
            const toast = document.getElementById('upload-success-toast');
            if (toast) toast.classList.add('show');
        }

        dismissUploadSuccess() {
            const toast = document.getElementById('upload-success-toast');
            if (toast) toast.classList.remove('show');
        }
        
        showLoading(show) {
            const overlay = document.getElementById('loading-overlay');
            if (overlay) overlay.classList.toggle('show', show);
        }
        
        showAlert(title, message, type = 'info', autoCloseMs = 4000, onClose = null) {
            const iconMap  = { danger: 'fa-circle-exclamation', success: 'fa-circle-check', info: 'fa-circle-info', warning: 'fa-triangle-exclamation' };
            const typeN    = type === 'warning' ? 'info' : type;
            const icon     = iconMap[type] || iconMap.info;
            
            const toast = document.createElement('div');
            toast.className = `alert-toast ${typeN}`;
            toast.innerHTML = `
                <i class="fas ${icon} toast-icon ${typeN}"></i>
                <div class="toast-body">
                    <div class="toast-title">${title}</div>
                    <div class="toast-msg">${message}</div>
                </div>`;
            
            document.getElementById('toast-container').appendChild(toast);
            requestAnimationFrame(() => toast.classList.add('show'));
            
            // Si autoCloseMs es 0, no auto-cerrar
            if (autoCloseMs > 0) {
                setTimeout(() => {
                    toast.classList.remove('show');
                    setTimeout(() => {
                        toast.remove();
                        if (onClose) onClose();
                    }, 350);
                }, autoCloseMs);
            } else {
                // Si no hay auto-cierre, agregar botón de cerrar y ejecutar callback al hacer click
                const closeBtn = document.createElement('button');
                closeBtn.className = 'toast-close';
                closeBtn.innerHTML = '<i class="fas fa-times"></i>';
                closeBtn.onclick = () => {
                    toast.classList.remove('show');
                    setTimeout(() => {
                        toast.remove();
                        if (onClose) onClose();
                    }, 350);
                };
                toast.appendChild(closeBtn);
            }
        }

        showIOSModal(title, message, icon = 'fa-exclamation-triangle', iconColor = 'var(--red)', buttons = []) {
            const overlay = document.getElementById('ios-modal-overlay');
            const modalIcon = document.getElementById('ios-modal-icon');
            const modalTitle = document.getElementById('ios-modal-title');
            const modalMessage = document.getElementById('ios-modal-message');
            const modalActions = document.getElementById('ios-modal-actions');
            
            if (!overlay) return;
            
            // Configurar contenido
            modalIcon.innerHTML = `<i class="fas ${icon}"></i>`;
            modalIcon.style.background = iconColor.replace('var(--red)', 'rgba(255, 55, 95, 0.15)')
                                                 .replace('var(--blue)', 'rgba(10, 132, 255, 0.15)')
                                                 .replace('var(--green)', 'rgba(48, 209, 88, 0.15)')
                                                 .replace('var(--orange)', 'rgba(255, 159, 10, 0.15)');
            modalIcon.style.color = iconColor;
            modalTitle.textContent = title;
            modalMessage.textContent = message;
            
            // Limpiar botones anteriores
            modalActions.innerHTML = '';
            
            // Agregar botones
            buttons.forEach((btn, index) => {
                const button = document.createElement('button');
                button.className = `ios-modal-btn ${btn.primary ? 'ios-modal-btn-primary' : 'ios-modal-btn-secondary'}`;
                button.textContent = btn.text;
                if (btn.icon) {
                    button.innerHTML = `<i class="fas ${btn.icon}"></i> ${btn.text}`;
                }
                button.onclick = () => {
                    this.hideIOSModal();
                    if (btn.onClick) {
                        setTimeout(() => btn.onClick(), 300); // Esperar a que el modal se cierre
                    }
                };
                modalActions.appendChild(button);
            });
            
            // Mostrar modal
            requestAnimationFrame(() => {
                overlay.classList.add('show');
            });
            
            // Haptic feedback
            if (navigator.vibrate) navigator.vibrate(30);
        }

        hideIOSModal() {
            const overlay = document.getElementById('ios-modal-overlay');
            if (overlay) {
                overlay.classList.remove('show');
            }
        }

        /* ══════════════════════════════════════════════════════════
         *  IndexedDB — persistencia de audios ante pérdida de red
         * ══════════════════════════════════════════════════════════ */

        async initIndexedDB() {
            return new Promise((resolve) => {
                if (!window.indexedDB) {
                    console.warn('⚠️ IndexedDB no disponible en este navegador');
                    resolve();
                    return;
                }
                const req = indexedDB.open(this.IDB_NAME, 1);
                req.onupgradeneeded = (e) => {
                    const db = e.target.result;
                    if (!db.objectStoreNames.contains(this.IDB_STORE)) {
                        db.createObjectStore(this.IDB_STORE, { keyPath: 'id' });
                    }
                };
                req.onsuccess = (e) => {
                    this.idb = e.target.result;
                    console.log('✅ IndexedDB inicializado');
                    resolve();
                };
                req.onerror = (e) => {
                    console.warn('⚠️ Error inicializando IndexedDB:', e.target.error);
                    resolve(); // No bloquear el flujo
                };
            });
        }

        async saveToIndexedDB(recording) {
            if (!this.idb) return;
            return new Promise((resolve) => {
                try {
                    const tx = this.idb.transaction(this.IDB_STORE, 'readwrite');
                    tx.objectStore(this.IDB_STORE).put({
                        id:        recording.id,
                        blob:      recording.blob,
                        sessionId: recording.sessionId || this.sessionId,
                        studyId:   recording.studyId   || this.studyId,
                        timestamp: recording.timestamp,
                        duration:  recording.duration,
                        serverAudioId: recording.serverAudioId || null,
                        savedAt:   new Date().toISOString()
                    });
                    tx.oncomplete = () => resolve(true);
                    tx.onerror    = () => resolve(false);
                } catch (e) {
                    console.warn('⚠️ Error guardando en IndexedDB:', e);
                    resolve(false);
                }
            });
        }

        async removeFromIndexedDB(recordingId) {
            if (!this.idb) return;
            return new Promise((resolve) => {
                try {
                    const tx = this.idb.transaction(this.IDB_STORE, 'readwrite');
                    tx.objectStore(this.IDB_STORE).delete(recordingId);
                    tx.oncomplete = () => resolve(true);
                    tx.onerror    = () => resolve(false);
                } catch (e) {
                    resolve(false);
                }
            });
        }

        async getAllPendingFromIndexedDB() {
            if (!this.idb) return [];
            return new Promise((resolve) => {
                try {
                    const tx = this.idb.transaction(this.IDB_STORE, 'readonly');
                    const req = tx.objectStore(this.IDB_STORE).getAll();
                    req.onsuccess = () => resolve(req.result || []);
                    req.onerror   = () => resolve([]);
                } catch (e) {
                    resolve([]);
                }
            });
        }

        /* ══════════════════════════════════════════════════════════
         *  Auto-subida al detener grabación
         * ══════════════════════════════════════════════════════════ */

        /**
         * Calcula el estado real del móvil a partir de la lista de grabaciones y lo envía al servidor.
         * Llamar cada vez que cambia la lista o el estado de grabación.
         *
         * Prioridad de estados:
         *   recording   → grabando actualmente
         *   uploading   → algún audio se está subiendo/enviando
         *   has_unsent  → hay audios en la lista que NO llegaron al workspace (backup/pending/error)
         *   idle        → sin actividad pendiente
         */
        _syncMobileStatus() {
            if (!this.sessionId) return;
            let status = 'idle';
            if (this.isRecording) {
                status = 'recording';
            } else if (this.recordings.some(r => r.status === 'uploading' || r.status === 'sending_workspace')) {
                status = 'uploading';
            } else if (this.recordings.some(r => r.status === 'backup' || r.status === 'pending' || r.status === 'error')) {
                status = 'has_unsent';
            }
            this._pushMobileStatus(status);
        }

        /**
         * Push de estado de grabación al servidor (fire-and-forget).
         * El workspace lee este campo en su polling periódico para actualizar el badge.
         * @param {'idle'|'recording'|'uploading'|'has_unsent'} status
         */
        _pushMobileStatus(status) {
            if (!this.sessionId) return;
            const baseUrl = this.getBaseUrl();
            const payload = JSON.stringify({
                session_id:              this.sessionId,
                action:                  'update_mobile_status',
                mobile_recording_status: status
            });
            // Usamos keepalive para que se envíe incluso si el usuario cierra la pantalla
            fetch(`${baseUrl}/api/mobile_session.php`, {
                method: 'PUT',
                headers: { 'Content-Type': 'application/json' },
                body: payload,
                keepalive: true
            }).catch(() => { /* ignorar — es un ping de estado */ });
            console.log(`📡 Mobile status push: ${status}`);
        }

        async autoUploadRecording(recording) {
            // 1. Guardar inmediatamente en IndexedDB (respaldo local del dispositivo)
            await this.saveToIndexedDB(recording);
            console.log(`💾 Audio ${recording.id} guardado en IndexedDB`);

            // 2. Si no hay conexión, marcar como pendiente y esperar
            if (!this.isOnline) {
                recording.status = 'pending';
                this.updateRecordingsList();
                this.showAlert('Sin conexión', 'La grabación se guardó en el dispositivo. Se enviará al servidor cuando vuelva la conexión.', 'warning', 4000);
                return;
            }

            recording.status = 'uploading';
            this.updateRecordingsList();
            this._pushMobileStatus('uploading'); // ← notificar workspace

            try {
                // Subir al servidor como RESPALDO (estado = en_papelera, no aparece en workspace aún)
                const result = await this.uploadSingleRecording(recording);
                recording.status       = 'backup'; // guardado en papelera, aún no en workspace
                recording.serverAudioId = result?.data?.audio_id || null; // ID en audios_informe
                // Persistir serverAudioId en IDB antes de borrar: si falla el delete, al reabrir
                // podemos publicar sin volver a subir el blob.
                await this.saveToIndexedDB(recording);
                await this.removeFromIndexedDB(recording.id); // respaldo en servidor OK → limpiar IDB local
                this.updateRecordingsList();
                console.log(`✅ Audio ${recording.id} guardado como respaldo (serverAudioId: ${recording.serverAudioId})`);
            } catch (err) {
                recording.status = 'error';
                this.updateRecordingsList();
                console.warn(`⚠️ Error guardando respaldo del audio ${recording.id}:`, err.message);
                this.showAlert('Error al resguardar', 'La grabación quedó guardada en el dispositivo. Usa el botón Enviar para reenviarla.', 'danger', 4000);
            } finally {
                this._syncMobileStatus(); // ← notificar workspace con estado real (puede tener has_unsent)
            }
        }

        async uploadSingleRecording(recording) {
            const baseUrl = this.getBaseUrl();
            const formData = new FormData();
            formData.append('audio',          recording.blob, `audio_${recording.id}.webm`);
            formData.append('session_id',     recording.sessionId || this.sessionId);
            formData.append('tipo_grabacion', 'mobile');
            if (recording.studyId || this.studyId) {
                formData.append('study_id', recording.studyId || this.studyId);
            }
            if (this.workspaceId) {
                formData.append('workspace_id', this.workspaceId);
            }
            // Enviar duración al servidor como fallback cuando ffprobe no puede leer
            // la cabecera de un WebM grabado con MediaRecorder (sin duración embebida).
            if (recording.duration && recording.duration > 0) {
                formData.append('client_duration', (recording.duration / 1000).toFixed(2));
            }
            const sessionToken = await this.getSessionToken();
            if (sessionToken) formData.append('session_token', sessionToken);

            const response = await fetch(`${baseUrl}/api/audios/upload.php`, {
                method: 'POST',
                headers: sessionToken ? { 'Authorization': `Bearer ${sessionToken}` } : {},
                body: formData
            });

            if (!response.ok) {
                const txt = await response.text();
                throw new Error(`HTTP ${response.status}: ${txt.substring(0, 120)}`);
            }
            const result = await response.json();
            if (!result.success) throw new Error(result.message || 'Error en el servidor');
            // Devolver resultado completo para que el caller pueda leer result.data.audio_id
            return result;
        }

        /* Llama a mobile-publish.php para cambiar el estado de un audio ya subido */
        async publishToWorkspace(serverAudioId) {
            const baseUrl = this.getBaseUrl();
            const response = await fetch(`${baseUrl}/api/audios/mobile-publish.php`, {
                method: 'POST',
                headers: { 'Content-Type': 'application/json' },
                body: JSON.stringify({
                    session_id: this.sessionId,
                    audio_id:   serverAudioId,
                    action:     'publish'
                })
            });
            if (!response.ok) {
                const txt = await response.text();
                throw new Error(`HTTP ${response.status}: ${txt.substring(0, 120)}`);
            }
            const result = await response.json();
            if (!result.success) throw new Error(result.message || 'Error publicando al workspace');
            return result;
        }

        /* Llama a mobile-publish.php para marcar un audio como eliminado */
        async markAsDeletedOnServer(serverAudioId) {
            const baseUrl = this.getBaseUrl();
            try {
                const response = await fetch(`${baseUrl}/api/audios/mobile-publish.php`, {
                    method: 'POST',
                    headers: { 'Content-Type': 'application/json' },
                    body: JSON.stringify({
                        session_id: this.sessionId,
                        audio_id:   serverAudioId,
                        action:     'delete'
                    })
                });
                const result = await response.json();
                if (result.success) {
                    console.log(`🗑️ Audio ${serverAudioId} marcado como eliminado en servidor`);
                }
            } catch (err) {
                // No bloquear el flujo si falla (el audio queda en papelera)
                console.warn('⚠️ No se pudo marcar como eliminado en servidor:', err.message);
            }
        }

        /* ══════════════════════════════════════════════════════════
         *  Recuperación de grabaciones pendientes al reabrir
         * ══════════════════════════════════════════════════════════ */

        async recoverPendingUploads() {
            const pending = await this.getAllPendingFromIndexedDB();
            if (pending.length === 0) return;

            console.log(`📂 ${pending.length} grabación(es) pendiente(s) encontradas en IndexedDB`);

            this.showIOSModal(
                `${pending.length} grabación${pending.length > 1 ? 'es' : ''} sin resguardar`,
                `Se encontraron grabaciones guardadas en este dispositivo que no llegaron al servidor. ¿Deseas resguardarlas ahora?`,
                'fa-cloud-upload-alt',
                'var(--orange)',
                [
                    {
                        text: 'Resguardar ahora',
                        primary: true,
                        icon: 'fa-shield',
                        onClick: async () => {
                            for (const item of pending) {
                                // Reconstruir recording object desde IDB
                                const url = URL.createObjectURL(item.blob);
                                const rec = {
                                    id:           item.id,
                                    url:          url,
                                    blob:         item.blob,
                                    timestamp:    item.timestamp,
                                    duration:     item.duration || 0,
                                    // Subida ya hecha en servidor pero aún no publicada al workspace
                                    status:       item.serverAudioId ? 'backup' : 'pending',
                                    sessionId:    item.sessionId || this.sessionId,
                                    studyId:      item.studyId   || this.studyId,
                                    serverAudioId: item.serverAudioId || null,
                                    fromRecovery: true
                                };
                                this.recordings.push(rec);
                            }
                            this.updateRecordingsList();
                            await this.retryPendingUploads();
                        }
                    },
                    {
                        text: 'Descartar',
                        icon: 'fa-trash',
                        onClick: async () => {
                            for (const item of pending) {
                                await this.removeFromIndexedDB(item.id);
                            }
                            this.showAlert('Descartadas', 'Las grabaciones pendientes fueron eliminadas.', 'info', 3000);
                        }
                    }
                ]
            );
        }

        async retryPendingUploads() {
            // Reintentar: pendientes / error sin audio en servidor, o recuperados desde IDB
            // que quedaron en 'uploading' si un reintento anterior se interrumpió.
            const toRetry = this.recordings.filter(r => {
                if (r.fromRecovery && r.serverAudioId && r.status === 'backup') {
                    return true; // subida OK antes del crash; falta publicar al workspace
                }
                if (r.serverAudioId) return false;
                return r.status === 'pending' ||
                    r.status === 'error' ||
                    (r.fromRecovery && r.status === 'uploading');
            });
            if (toRetry.length === 0) return;
            console.log(`🔄 Reintentando respaldo de ${toRetry.length} grabación(es)...`);
            for (const rec of toRetry) {
                if (rec.fromRecovery && rec.serverAudioId && rec.status === 'backup') {
                    try {
                        rec.status = 'sending_workspace';
                        this.updateRecordingsList();
                        await this.publishToWorkspace(rec.serverAudioId);
                        await this.removeFromIndexedDB(rec.id);
                        rec.status = 'workspace_ready';
                    } catch (err) {
                        rec.status = 'backup';
                        console.warn(`⚠️ Error publicando audio recuperado ${rec.id}:`, err.message);
                    }
                    this.updateRecordingsList();
                    continue;
                }
                await this.autoUploadRecording(rec);
            }
            this._syncMobileStatus();
        }

        /* ══════════════════════════════════════════════════════════
         *  Monitoreo de conectividad de red
         * ══════════════════════════════════════════════════════════ */

        onNetworkLost() {
            this.isOnline = false;
            this.showNetworkBanner('Sin conexión — grabaciones se guardarán localmente', 'offline', 0);
            this.debugLog('📵 Sin conexión a internet', 'warning');
        }

        onNetworkRestored() {
            this.isOnline = true;
            this.showNetworkBanner('Conexión restaurada', 'online', 3000);
            this.debugLog('📶 Conexión restaurada', 'success');
            // Reintentar subidas pendientes automáticamente
            setTimeout(() => this.retryPendingUploads(), 1000);
        }

        showNetworkBanner(text, type = 'offline', autohideMs = 3000) {
            const banner   = document.getElementById('network-banner');
            const iconEl   = document.getElementById('network-banner-icon');
            const textEl   = document.getElementById('network-banner-text');
            if (!banner) return;

            banner.className = `network-banner ${type} show`;
            textEl.textContent = text;
            iconEl.className = type === 'offline'
                ? 'fas fa-wifi-slash'
                : 'fas fa-wifi';

            if (this._networkBannerTimer) clearTimeout(this._networkBannerTimer);
            if (autohideMs > 0) {
                this._networkBannerTimer = setTimeout(() => {
                    banner.classList.remove('show');
                }, autohideMs);
            }
        }

        /* ══════════════════════════════════════════════════════════
         *  Descarga de emergencia cuando workspace se desconecta
         * ══════════════════════════════════════════════════════════ */

        downloadAllPendingAudios() {
            const pending = this.recordings.filter(r => r.status !== 'ok');
            if (pending.length === 0) return;
            pending.forEach((rec, i) => {
                setTimeout(() => {
                    const a = document.createElement('a');
                    a.href     = rec.url;
                    a.download = `grabacion_${i + 1}_${rec.timestamp.replace(/[/:, ]/g, '_')}.webm`;
                    document.body.appendChild(a);
                    a.click();
                    document.body.removeChild(a);
                }, i * 800); // pequeño delay entre descargas para no saturar
            });
            this.showAlert('Descargando', `Descargando ${pending.length} grabación(es) al dispositivo.`, 'info', 4000);
        }

        async getSessionToken() {
            return null;
        }
    }
    
    let mobileRecorder;
    document.addEventListener('DOMContentLoaded', () => {
        mobileRecorder = new MobileAudioRecorder();
    });
</script>
</body>
</html>
