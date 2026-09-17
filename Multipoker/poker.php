<?php
require_once("backend/functions.php");

global $TTCache, $site_config, $CURUSER;

dbconn();

$site_config["LEFTNAV"] = $site_config["MIDDLENAV"] = $site_config["RIGHTNAV"] = false;

if ($site_config["MEMBERSONLY"]) {
    loggedinonly();
}

require_once __DIR__ . '/poker-lib.php';

poker_session_init();

global $CURUSER;
$db = poker_db();

$maintenanceState = poker_maintenance_state($db);

if (!poker_user_is_admin($CURUSER) && $maintenanceState === 2) {
    $title = 'Poker Maintenance';

    if (function_exists('stdhead')) {
        stdhead($title);
    }

    if (function_exists('begin_frame')) {
        begin_frame($title);
    }

    echo '<div style="max-width:760px;margin:35px auto;padding:24px;text-align:center;background:#171717;border:1px solid #444;border-radius:7px;color:#ddd;">';
    echo '<h2 style="margin-top:0;color:#fff;">Poker is temporarily offline</h2>';
    echo '<p style="margin-bottom:0;color:#aaa;">' . htmlspecialchars(poker_maintenance_message(), ENT_QUOTES, 'UTF-8') . '</p>';
    echo '</div>';

    if (function_exists('end_frame')) {
        end_frame();
    }

    if (function_exists('stdfoot')) {
        stdfoot();
    }

    exit;
}

$tableId = isset($_GET['table_id']) ? max(1, (int) $_GET['table_id']) : 1;
$table = poker_get_table($db, $tableId);
if (!$table) {
    die('Poker table not found. Run poker.sql first.');
}

$title = 'Multiplayer Poker';
if (function_exists('stdhead')) {
    stdhead($title);
}
if (function_exists('begin_frame')) {
    begin_frame($title);
}
$csrf = htmlspecialchars($_SESSION['poker_csrf'], ENT_QUOTES, 'UTF-8');
?>
<div class="poker-credit-bar">
    <span class="poker-credit-label">Your Upload Credit:</span>
    <span class="poker-credit-value" id="uploadCredit">
        <?php echo mksize((float)$CURUSER["uploaded"]); ?>
    </span>
</div>
<style>
    .poker-credit-bar {
        width: 771px;
        margin: 10px auto 12px auto;
        padding: 10px 16px;
        box-sizing: border-box;

        background: #181818;
        border: 1px solid #444;
        border-radius: 6px;

        text-align: center;
        font-size: 16px;
    }

    .poker-credit-label {
        color: #ccc;
        margin-right: 8px;
    }

    .poker-credit-value {
        color: #7ed957;
        font-weight: bold;
        font-size: 18px;
    }

    /* Poker-room atmosphere. The game table itself remains unchanged. */
    body {
        background:
            linear-gradient(rgba(3, 5, 7, .66), rgba(3, 5, 7, .82)),
            url('images/poker/poker-room-background.webp') center top / cover fixed no-repeat !important;
    }

    #modern-poker * {
        box-sizing: border-box;
    }

    #modern-poker {
        width: 1120px;
        margin: 12px auto;
        color: #ddd;
        font-family: Arial, Helvetica, sans-serif;
    }

    #modern-poker .poker-layout {
        display: grid;
        grid-template-columns: 771px 325px;
        gap: 18px;
        align-items: start;
    }

    #modern-poker .poker-play-column {
        width: 771px;
        min-width: 771px;
    }

    #modern-poker .table-action-strip {
        width: 771px;
        margin-top: 10px;
        padding: 9px 11px 8px;
        border: 1px solid #383838;
        border-top-color: rgba(242, 162, 58, .72);
        border-radius: 6px;
        background: linear-gradient(180deg, rgba(21,21,21,.98), rgba(10,10,10,.98));
        box-shadow: 0 8px 20px rgba(0,0,0,.28);
    }

    #modern-poker .table-action-status {
        display: flex;
        align-items: stretch;
        gap: 8px;
    }

    #modern-poker .action-pot-display {
        flex: 0 0 108px;
        min-height: 30px;
        padding: 4px 9px;
        display: flex;
        align-items: center;
        justify-content: center;
        gap: 7px;
        border: 1px solid rgba(242, 162, 58, .48);
        border-radius: 5px;
        background: rgba(12, 12, 12, .96);
        box-shadow: inset 0 1px 0 rgba(255,255,255,.03);
        pointer-events: none;
    }

    #modern-poker .action-pot-display span {
        color: #9b7b45;
        font-size: 9px;
        font-weight: 800;
        letter-spacing: .8px;
    }

    #modern-poker .action-pot-display strong {
        color: #e5b85c;
        font-size: 14px;
        line-height: 1;
        text-shadow: 0 1px 1px #000;
    }

    #modern-poker .table-action-status .notice {
        flex: 1 1 auto;
        min-height: 30px;
        padding: 6px 9px;
        display: flex;
        align-items: center;
    }

    #modern-poker .table-action-status .turn-countdown {
        flex: 0 0 150px;
        margin-top: 0;
        min-height: 30px;
        padding: 4px 8px;
        display: flex;
        align-items: center;
        justify-content: center;
    }

    #modern-poker .table-action-status .turn-countdown[hidden] {
        display: none;
    }

    #modern-poker .table-action-status .turn-countdown strong {
        font-size: 16px;
    }

    #modern-poker .table-action-controls {
        display: flex;
        align-items: center;
        justify-content: space-between;
        gap: 12px;
        margin-top: 8px;
    }

    #modern-poker .table-action-buttons,
    #modern-poker .table-raise-controls {
        display: flex;
        align-items: center;
        gap: 6px;
    }

    #modern-poker .table-action-buttons button {
        min-width: 70px;
    }

    #modern-poker .table-raise-controls {
        margin-left: auto;
    }

    #modern-poker .table-raise-controls label {
        margin-right: 2px;
        white-space: nowrap;
    }

    #modern-poker .table-raise-controls input {
        width: 90px;
        min-width: 90px;
        height: 34px;
        padding: 7px 8px;
        border: 1px solid #444;
        border-radius: 4px;
        background: #0c0c0c;
        color: #fff;
        font-size: 13px;
    }

    #modern-poker .table-raise-controls select {
        width: 64px;
        height: 34px;
        padding: 6px;
        border: 1px solid #444;
        border-radius: 4px;
        background: #0c0c0c;
        color: #fff;
        font-size: 12px;
    }

    #modern-poker .table-raise-controls input:focus,
    #modern-poker .table-raise-controls select:focus {
        outline: none;
        border-color: #5c9fd6;
    }

    #modern-poker .table-raise-limit {
        margin-top: 5px;
        text-align: right;
        font-size: 10px;
    }

    #modern-poker .table-status-strip {
        width: 771px;
        min-height: 32px;
        margin-top: 7px;
        padding: 5px 9px;
        display: flex;
        align-items: center;
        gap: 9px;
        border: 1px solid #303030;
        border-radius: 5px;
        background: rgba(10, 10, 10, .94);
        box-shadow: inset 0 1px 0 rgba(255,255,255,.025);
    }

    #modern-poker .table-status-label {
        flex: 0 0 auto;
        color: #777;
        font-size: 9px;
        font-weight: 800;
        letter-spacing: .8px;
    }

    #modern-poker .table-status-value {
        flex: 0 0 auto;
        color: #79d279;
        font-size: 11px;
        font-weight: 800;
        letter-spacing: .25px;
    }

    #modern-poker .table-status-value.error {
        color: #ff7f7f;
    }

    #modern-poker .table-status-strip .notice {
        flex: 1 1 auto;
        min-height: 0;
        padding: 0;
        border: 0;
        border-radius: 0;
        background: transparent;
        color: #aeb6bf;
        font-size: 11px;
        line-height: 1.3;
    }

    #modern-poker .table-status-strip .notice.error {
        color: #ff9c9c;
        border: 0;
    }

    #modern-poker .table-wrap {
        position: relative;
        width: 771px;
        height: 550px;
        background: #050505 url('images/poker/table.webp') no-repeat center center;
        background-size: 771px 550px;
        overflow: hidden;
        user-select: none;
    }

    #modern-poker .seat {
        position: absolute;
        width: 93px;
        height: 89px;
        text-align: center;
        color: #fff;
        font-size: 11px;
        overflow: hidden;
        border-radius: 10px;
    }

    #modern-poker .seat.empty {
        cursor: pointer;

        width: 82px;
        height: 32px;
        margin: 27px 0 0 6px;

        display: flex;
        align-items: center;
        justify-content: center;

        color: #d8d8d8;

        background:
            linear-gradient(180deg,
                rgba(45, 45, 45, .92) 0%,
                rgba(16, 16, 16, .95) 100%);

        border: 1px solid rgba(255, 255, 255, .30);
        border-radius: 18px;

        box-shadow:
            0 3px 7px rgba(0, 0, 0, .65),
            inset 0 1px 0 rgba(255, 255, 255, .12);

        font-size: 10px;
        font-weight: bold;
        letter-spacing: .6px;
        text-transform: uppercase;
        text-shadow: 0 1px 2px #000;

        transition:
            transform .15s ease,
            background .15s ease,
            border-color .15s ease,
            box-shadow .15s ease,
            color .15s ease;
    }

    #modern-poker .seat.empty:hover {
        color: #fff;

        background:
            linear-gradient(180deg,
                rgba(51, 118, 72, .98) 0%,
                rgba(24, 70, 40, .98) 100%);

        border-color: rgba(155, 235, 175, .75);

        box-shadow:
            0 4px 10px rgba(0, 0, 0, .75),
            0 0 10px rgba(100, 210, 130, .25),
            inset 0 1px 0 rgba(255, 255, 255, .20);

        transform: scale(1.07);
    }

    #modern-poker .seat.turn {
        outline: 2px solid #f2c94c;
        outline-offset: -2px;
        background: rgba(242, 201, 76, .08);
    }

    #modern-poker .seat.folded {
        opacity: .48;
    }

    #modern-poker .avatar {
        width: 42px;
        height: 42px;
        object-fit: cover;
        border-radius: 50%;
        border: 1px solid #999;
        display: block;
        margin: 3px auto 1px;
        background: #222;
    }

    #modern-poker .avatar-fallback {
        width: 42px;
        height: 42px;
        line-height: 42px;
        border-radius: 50%;
        border: 1px solid #999;
        margin: 3px auto 1px;
        background: #222;
        font-size: 19px;
        font-weight: bold;
    }

    #modern-poker .username {
        white-space: nowrap;
        overflow: hidden;
        text-overflow: ellipsis;
        padding: 0 2px;
        font-weight: bold;
        text-shadow: 1px 1px 2px #000;
    }

    #modern-poker .stack {
        color: #9ee493;
        font-size: 10px;
    }

    #modern-poker .seat-state {
        color: #ffcc66;
        font-size: 9px;
        text-transform: uppercase;
    }

    #modern-poker .hole {
        position: absolute;
        width: 94px;
        height: 106px;
        z-index: 5;
        display: flex;
        gap: 2px;
        align-items: center;
        justify-content: center;
        pointer-events: none;
    }

    #modern-poker .hole img,
    #modern-poker .community img {
        width: 46px;
        height: 61px;
        object-fit: fill;
        border-radius: 2px;
    }


    /* House-game dealing is staged visually instead of painting every card at once. */
    #modern-poker img.house-card-deal {
        opacity: 0;
        transform: translateY(-18px) rotate(-2deg) scale(.97);
        animation: houseCardDealIn .22s ease-out forwards;
        will-change: transform, opacity;
    }

    @keyframes houseCardDealIn {
        from {
            opacity: 0;
            transform: translateY(-18px) rotate(-2deg) scale(.97);
        }
        to {
            opacity: 1;
            transform: translateY(0) rotate(0deg) scale(1);
        }
    }

    #modern-poker .bet-chip {
        position: absolute;
        min-width: 52px;
        padding: 2px 5px;
        background: rgba(0, 0, 0, .72);
        border: 1px solid rgba(255, 255, 255, .18);
        border-radius: 10px;
        color: #fff;
        text-align: center;
        font-size: 10px;
        pointer-events: none;
    }

    #modern-poker .community {
        position: absolute;
        left: 259px;
        top: 240px;
        width: 253px;
        height: 63px;
        display: flex;
        justify-content: center;
        align-items: center;
        gap: 4px;
    }


    #modern-poker .dealer-button {
        position: absolute;
        width: 24px;
        height: 24px;
        border-radius: 50%;
        background: #eee;
        color: #111;
        border: 2px solid #777;
        font-weight: bold;
        line-height: 20px;
        text-align: center;
        font-size: 11px;
        pointer-events: none;
    }

    #modern-poker .table-status-panel {
        margin: 9px 0 3px;
        padding: 8px 10px;
        border: 1px solid rgba(242, 162, 58, .38);
        border-radius: 8px;
        background: linear-gradient(180deg, rgba(28, 22, 14, .92), rgba(8, 8, 8, .94));
        box-shadow: inset 0 1px 0 rgba(255,255,255,.04);
    }

    #modern-poker .table-status-panel span {
        display: block;
        margin-bottom: 3px;
        color: #d5a85d;
        font-size: 9px;
        font-weight: 800;
        letter-spacing: .8px;
        text-transform: uppercase;
    }

    #modern-poker .table-status-panel strong {
        display: block;
        color: #f1f1f1;
        font-size: 11px;
        line-height: 1.35;
        font-weight: 700;
    }

    /* Make the pre-game waiting state impossible to miss. */
    #modern-poker .table-status-panel.waiting {
        padding: 12px 12px;
        border-color: #e2a43d;
        background: linear-gradient(180deg, rgba(92, 61, 18, .96), rgba(24, 17, 8, .97));
        box-shadow:
            inset 0 1px 0 rgba(255,255,255,.08),
            0 0 14px rgba(226,164,61,.20);
        text-align: center;
    }

    #modern-poker .table-status-panel.waiting span {
        margin-bottom: 5px;
        color: #e7b75e;
        font-size: 10px;
    }

    #modern-poker .table-status-panel.waiting strong {
        color: #fff0bd;
        font-size: 17px;
        line-height: 1.25;
        letter-spacing: .2px;
        text-shadow: 0 1px 2px #000;
    }

    #modern-poker .panel {
        background: #151515;
        border: 1px solid #333;
        border-radius: 7px;
        padding: 14px;
        margin-bottom: 12px;
    }

    #modern-poker .panel h3 {
        margin: 0 0 10px;
        color: #fff;
        font-size: 16px;
    }

    #modern-poker .info-row {
        display: flex;
        justify-content: space-between;
        gap: 10px;
        padding: 5px 0;
        border-bottom: 1px solid #292929;
        font-size: 12px;
    }

    #modern-poker .info-row:last-child {
        border-bottom: 0;
    }

    #modern-poker input[type=number] {
        width: 100%;
        padding: 8px;
        border: 1px solid #444;
        border-radius: 4px;
        background: #0c0c0c;
        color: #fff;
        font-size: 14px;
    }

    #modern-poker button,
    #modern-poker .poker-link-button {
        border: 1px solid #555;
        border-radius: 4px;
        background: #292929;
        color: #fff;
        padding: 8px 11px;
        cursor: pointer;
        font-weight: bold;
    }

    #modern-poker button:hover:not(:disabled),
    #modern-poker .poker-link-button:hover {
        background: #3b3b3b;
    }

    #modern-poker button:disabled {
        opacity: .4;
        cursor: default;
    }

    #modern-poker .actions {
        display: flex;
        flex-wrap: wrap;
        gap: 7px;
        margin-top: 10px;
    }

    #modern-poker .actions .danger {
        background: #5b2222;
    }

    #modern-poker .actions .primary {
        background: #224d6c;
    }

    #modern-poker .actions .good {
        background: #27532f;
    }

    #modern-poker .notice {
        min-height: 34px;
        padding: 8px;
        background: #0d0d0d;
        border: 1px solid #303030;
        border-radius: 4px;
        color: #bbb;
        font-size: 12px;
    }

    #modern-poker .notice.error {
        color: #ff9c9c;
        border-color: #743232;
    }

    #modern-poker .turn-countdown {
        margin-top: 8px;
        padding: 7px 9px;
        border: 1px solid #3a3a3a;
        border-radius: 4px;
        background: #101010;
        color: #bbb;
        text-align: center;
        font-size: 12px;
    }

    #modern-poker .turn-countdown strong {
        color: #9ee493;
        font-size: 18px;
        margin-left: 5px;
    }

    #modern-poker .turn-countdown.warning {
        border-color: #8a5d24;
        color: #ffd28a;
    }

    #modern-poker .turn-countdown.warning strong {
        color: #ffb34d;
    }

    #modern-poker .turn-countdown.danger {
        border-color: #853535;
        color: #ffaaaa;
    }

    #modern-poker .turn-countdown.danger strong {
        color: #ff6b6b;
    }

    #modern-poker .turn-timer-badge {
        position: absolute;
        right: 3px;
        top: 3px;
        min-width: 24px;
        height: 20px;
        padding: 0 4px;
        border-radius: 10px;
        background: rgba(0, 0, 0, .82);
        border: 1px solid rgba(255, 255, 255, .28);
        color: #9ee493;
        line-height: 18px;
        font-size: 10px;
        font-weight: bold;
        z-index: 4;
        pointer-events: none;
    }

    #modern-poker .turn-timer-badge.warning {
        color: #ffb34d;
        border-color: rgba(255, 179, 77, .7);
    }

    #modern-poker .turn-timer-badge.danger {
        color: #ff6b6b;
        border-color: rgba(255, 107, 107, .75);
    }

    #modern-poker .buyin-box {
        display: none;
    }

    #modern-poker .buyin-box.active {
        display: block;
    }

    #modern-poker .seat.sitting-out {
        opacity: .72;
    }

    #modern-poker .sitout-badge {
        margin-top: 3px;
        color: #ffd36a;
        font-size: 10px;
        font-weight: 700;
        letter-spacing: .5px;
        text-transform: uppercase;
    }

    #modern-poker .waiting-hand-badge {
        margin-top: 3px;
        color: #8fd7ff;
        font-size: 10px;
        font-weight: 700;
        letter-spacing: .5px;
        text-transform: uppercase;
    }

    #modern-poker .reconnect-badge {
        margin-top: 3px;
        color: #ffb36b;
        font-size: 10px;
        font-weight: 700;
        letter-spacing: .4px;
        text-transform: uppercase;
    }

    #modern-poker .offline-badge {
        margin-top: 3px;
        color: #b8b8b8;
        font-size: 9px;
        font-weight: 700;
        letter-spacing: .4px;
        text-transform: uppercase;
    }

    #modern-poker .turn-countdown.reconnect {
        border-color: #d8792f;
        color: #ffd0a8;
    }

    #modern-poker .turn-countdown.reconnect strong {
        color: #ffad66;
    }

    #modern-poker .chat-panel {
        padding-bottom: 12px;
    }

    #modern-poker .chat-panel.prominent-chat {
        border-color: rgba(255, 140, 0, .62);
        background:
            linear-gradient(180deg, rgba(34, 25, 13, .96), rgba(14, 14, 14, .98));
        box-shadow:
            inset 0 1px 0 rgba(255,255,255,.035),
            0 0 18px rgba(255, 140, 0, .06);
    }

    #modern-poker .chat-panel-head {
        display: flex;
        align-items: center;
        justify-content: space-between;
        gap: 10px;
        margin-bottom: 10px;
    }

    #modern-poker .chat-panel-head h3 {
        margin: 0;
        color: #ffb347;
    }

    #modern-poker .chat-live-label {
        display: inline-flex;
        align-items: center;
        min-height: 20px;
        padding: 3px 7px;
        border: 1px solid rgba(255, 140, 0, .42);
        border-radius: 10px;
        background: rgba(255, 140, 0, .08);
        color: #d9a15c;
        font-size: 8px;
        font-weight: 800;
        letter-spacing: .8px;
    }

    #modern-poker .chat-log {
        height: 215px;
        overflow-y: auto;
        padding: 8px;
        border: 1px solid #292929;
        background: #0e0e0e;
        border-radius: 4px;
        font-size: 12px;
        line-height: 1.35;
    }

    #modern-poker .chat-line {
        margin: 0 0 6px;
        overflow-wrap: anywhere;
    }

    #modern-poker .chat-line:last-child {
        margin-bottom: 0;
    }

    #modern-poker .chat-line.dealer-message {
        padding: 5px 7px;
        border-left: 2px solid #6f8f67;
        background: #151915;
        border-radius: 3px;
    }

    #modern-poker .chat-line.dealer-message .chat-name {
        color: #9fca92;
    }

    #modern-poker .chat-time {
        color: #686868;
        margin-right: 5px;
        font-size: 10px;
    }

    #modern-poker .chat-name {
        color: #e0bc63;
        font-weight: 700;
        margin-right: 5px;
    }

    #modern-poker .chat-empty {
        color: #666;
        text-align: center;
        padding-top: 65px;
    }

    #modern-poker .chat-compose {
        display: flex;
        gap: 6px;
        margin-top: 8px;
    }

    #modern-poker .chat-compose input {
        flex: 1;
        min-width: 0;
    }

    #modern-poker .chat-compose button {
        flex: 0 0 auto;
    }

    #modern-poker .chat-counter {
        margin-top: 4px;
        text-align: right;
        color: #666;
        font-size: 10px;
    }

    #modern-poker .poker-link-button {
        display: inline-flex;
        align-items: center;
        justify-content: center;
        border-radius: 4px;
        background: #292929;
        color: #fff;
        padding: 8px 11px;
        cursor: pointer;
        font-weight: bold;
        text-decoration: none;
    }

    #modern-poker .history-open {
        width: 100%;
        margin-top: 8px;
    }

    .poker-history-overlay {
        position: fixed;
        inset: 0;
        z-index: 10000;
        display: none;
        align-items: center;
        justify-content: center;
        padding: 30px;
        background: rgba(0, 0, 0, .78);
    }

    .poker-history-overlay.open {
        display: flex;
    }

    .poker-history-window {
        width: min(980px, calc(100vw - 60px));
        height: min(700px, calc(100vh - 60px));
        display: grid;
        grid-template-columns: 260px 1fr;
        overflow: hidden;
        border: 1px solid #3a3a3a;
        border-radius: 8px;
        background: #151515;
        box-shadow: 0 18px 70px rgba(0, 0, 0, .65);
        color: #ddd;
    }

    .poker-history-list {
        overflow-y: auto;
        border-right: 1px solid #303030;
        background: #101010;
    }

    .poker-history-list-title {
        position: sticky;
        top: 0;
        z-index: 1;
        padding: 14px;
        border-bottom: 1px solid #303030;
        background: #171717;
        color: #fff;
        font-weight: 700;
    }

    .poker-history-item {
        display: block;
        width: 100%;
        padding: 11px 13px;
        border: 0;
        border-bottom: 1px solid #252525;
        border-radius: 0;
        background: transparent;
        color: #bbb;
        text-align: left;
        cursor: pointer;
    }

    .poker-history-item:hover,
    .poker-history-item.active {
        background: #242424;
    }

    .poker-history-item strong {
        display: block;
        color: #e7c46e;
        font-size: 13px;
    }

    .poker-history-item span {
        display: block;
        margin-top: 3px;
        color: #777;
        font-size: 10px;
    }

    .poker-history-detail {
        overflow-y: auto;
        padding: 18px;
    }

    .poker-history-head {
        display: flex;
        justify-content: space-between;
        gap: 16px;
        align-items: flex-start;
        margin-bottom: 16px;
    }

    .poker-history-head h2 {
        margin: 0 0 4px;
        color: #fff;
        font-size: 21px;
    }

    .poker-history-result {
        margin: 12px 0;
        padding: 10px;
        border: 1px solid #34583a;
        border-radius: 5px;
        background: #142018;
        color: #bce9c3;
        font-size: 13px;
    }

    .poker-history-board,
    .poker-history-hole {
        display: flex;
        gap: 5px;
        align-items: center;
    }

    .poker-history-board img {
        width: 56px;
        height: auto;
    }

    .poker-history-hole img {
        width: 38px;
        height: auto;
    }

    .poker-history-section {
        margin-top: 18px;
    }

    .poker-history-section h3 {
        margin: 0 0 9px;
        color: #fff;
        font-size: 15px;
    }

    .poker-history-player {
        display: grid;
        grid-template-columns: 52px 1fr auto auto;
        gap: 9px;
        align-items: center;
        padding: 8px 0;
        border-bottom: 1px solid #292929;
        font-size: 12px;
    }

    .poker-history-player .dealer {
        color: #e7c46e;
        font-weight: 700;
    }

    .poker-history-actions {
        border: 1px solid #2b2b2b;
        border-radius: 5px;
        overflow: hidden;
    }

    .poker-history-action {
        display: grid;
        grid-template-columns: 65px 95px 1fr;
        gap: 8px;
        padding: 7px 9px;
        border-bottom: 1px solid #252525;
        background: #111;
        font-size: 12px;
    }

    .poker-history-action:last-child {
        border-bottom: 0;
    }

    .poker-history-action .time {
        color: #666;
        font-size: 10px;
    }

    .poker-history-action .name {
        color: #e0bc63;
        font-weight: 700;
    }

    .poker-history-empty {
        padding: 35px 20px;
        color: #777;
        text-align: center;
    }

    #modern-poker .buyin-entry {
    display: grid;
    grid-template-columns: 1fr 72px;
    gap: 7px;
    margin: 10px 0;
}
#modern-poker .buyin-entry input,
#modern-poker .buyin-entry select {
    width: 100%;
    min-width: 0;
    padding: 8px;
    border: 1px solid #444;
    border-radius: 4px;
    background: #0c0c0c;
    color: #fff;
    font-size: 14px;
}
#modern-poker .buyin-entry input:focus,
#modern-poker .buyin-entry select:focus {
    outline: none;
    border-color: #5c9fd6;
}
#modern-poker .spectator-summary {
    margin-top: 9px;
}
#modern-poker .spectator-summary-button {
    width: 100%;
    display: flex;
    align-items: center;
    justify-content: space-between;
    gap: 10px;
    padding: 9px 11px;
    border: 1px solid #333;
    border-radius: 4px;
    background: #101010;
    color: #aaa;
    font-size: 11px;
    font-weight: 700;
    text-transform: uppercase;
    cursor: pointer;
}
#modern-poker .spectator-summary-button:hover {
    border-color: #4a4a4a;
    background: #151515;
}
#modern-poker .spectator-summary-button strong {
    color: #fff;
    font-size: 12px;
}
.poker-spectator-overlay {
    position: fixed;
    inset: 0;
    z-index: 10020;
    display: none;
    align-items: center;
    justify-content: center;
    padding: 30px;
    background: rgba(0, 0, 0, .68);
}
.poker-spectator-overlay.open {
    display: flex;
}

.poker-house-broke-overlay {
    position: fixed;
    inset: 0;
    z-index: 10040;
    display: none;
    align-items: center;
    justify-content: center;
    padding: 30px;
    background: rgba(0, 0, 0, .72);
}
.poker-house-broke-overlay.open {
    display: flex;
}
.poker-user-notice-overlay {
    position: fixed;
    inset: 0;
    z-index: 10050;
    display: none;
    align-items: center;
    justify-content: center;
    padding: 30px;
    background: rgba(0, 0, 0, .74);
}
.poker-user-notice-overlay.open {
    display: flex;
}
.poker-user-notice-popout {
    width: min(560px, calc(100vw - 60px));
    padding: 24px 26px 22px;
    border: 1px solid #8a4a32;
    border-radius: 9px;
    background: linear-gradient(180deg, #1d1310, #0f0f0f);
    box-shadow: 0 20px 80px rgba(0, 0, 0, .76);
    color: #ddd;
    text-align: center;
}
.poker-user-notice-popout strong {
    display: block;
    margin-bottom: 10px;
    color: #ffad89;
    font-size: 12px;
    font-weight: 900;
    letter-spacing: 1.2px;
    text-transform: uppercase;
}
.poker-user-notice-message {
    margin: 0 0 18px;
    color: #f2f2f2;
    font-size: 18px;
    font-weight: 700;
    line-height: 1.5;
    white-space: pre-wrap;
}
.poker-user-notice-close {
    min-width: 110px;
    padding: 8px 16px;
    border: 1px solid #684040;
    border-radius: 5px;
    background: #3b2020;
    color: #efaaaa;
    font-weight: 800;
    cursor: pointer;
}
.poker-user-notice-close:hover {
    border-color: #8a4a32;
    background: #4a2519;
}
.poker-house-broke-popout {
    width: min(500px, calc(100vw - 60px));
    padding: 24px 26px 22px;
    border: 1px solid #9b742f;
    border-radius: 9px;
    background: linear-gradient(180deg, #1b1710, #0f0f0f);
    box-shadow: 0 20px 80px rgba(0, 0, 0, .72);
    color: #ddd;
    text-align: center;
}
.poker-house-broke-popout strong {
    display: block;
    margin-bottom: 10px;
    color: #d8a84e;
    font-size: 12px;
    font-weight: 900;
    letter-spacing: 1.2px;
    text-transform: uppercase;
}
.poker-house-broke-message {
    margin: 0 0 18px;
    color: #f2f2f2;
    font-size: 21px;
    font-weight: 800;
    line-height: 1.35;
    text-shadow: 0 1px 2px #000;
}
.poker-house-broke-close {
    min-width: 110px;
    padding: 8px 16px;
    border: 1px solid #4a4a4a;
    border-radius: 5px;
    background: #181818;
    color: #ddd;
    font-weight: 800;
    cursor: pointer;
}
.poker-house-broke-close:hover {
    border-color: #6a6a6a;
    background: #202020;
}
.poker-spectator-popout {
    width: min(380px, calc(100vw - 60px));
    max-height: min(520px, calc(100vh - 60px));
    overflow: hidden;
    border: 1px solid #3a3a3a;
    border-radius: 8px;
    background: #151515;
    box-shadow: 0 18px 70px rgba(0, 0, 0, .65);
    color: #ddd;
}
.poker-spectator-popout-head {
    display: flex;
    align-items: center;
    justify-content: space-between;
    gap: 12px;
    padding: 13px 15px;
    border-bottom: 1px solid #303030;
    background: #101010;
}
.poker-spectator-popout-head strong {
    color: #fff;
    font-size: 15px;
}
.poker-spectator-popout-head span {
    color: #888;
    font-size: 12px;
}
.poker-spectator-list {
    max-height: 430px;
    overflow-y: auto;
    padding: 7px 0;
}
.poker-spectator-person,
.poker-spectator-empty {
    padding: 9px 15px;
    border-bottom: 1px solid #242424;
    color: #ddd;
    font-size: 13px;
}
.poker-spectator-person:last-child {
    border-bottom: 0;
}
.poker-spectator-empty {
    border-bottom: 0;
    color: #777;
    text-align: center;
}
#modern-poker .spectator-mode {
    color: #d9b96e;
}
#modern-poker .player-mode {
    color: #8ed399;
}
#modern-poker .muted {
        color: #888;
        font-size: 11px;
        line-height: 1.4;
    }

    /* ===== Poker visual polish - Stage 1 ===== */
    #modern-poker .table-wrap {
        border-radius: 16px;
        box-shadow: 0 22px 45px rgba(0,0,0,.58), 0 0 0 1px rgba(255,166,55,.12);
    }
    #modern-poker .seat:not(.empty) {
        width:104px; height:96px; padding:5px 5px 4px; overflow:visible;
        background:linear-gradient(180deg,rgba(24,24,24,.97),rgba(5,5,5,.96));
        border:1px solid rgba(255,255,255,.22); border-radius:12px;
        box-shadow:0 7px 14px rgba(0,0,0,.72),inset 0 1px 0 rgba(255,255,255,.08);
        z-index:3; transition:border-color .18s ease,box-shadow .18s ease,transform .18s ease,opacity .18s ease;
    }
    #modern-poker .seat.turn {
        outline:none; border-color:#f2a23a;
        background:linear-gradient(180deg,rgba(44,31,15,.98),rgba(10,8,5,.97));
        box-shadow:0 0 0 2px rgba(242,162,58,.22),0 0 18px rgba(242,162,58,.48),0 8px 16px rgba(0,0,0,.72);
        transform:translateY(-2px);
    }
    #modern-poker .avatar,#modern-poker .avatar-fallback {
        width:44px;height:44px;margin:0 auto 2px;border:2px solid rgba(225,184,98,.72);
        box-shadow:0 2px 7px rgba(0,0,0,.75);
    }
    #modern-poker .avatar-fallback { line-height:40px; }

    /* The Collector's emblem is his seat box -- no normal player HUD behind it. */
    #modern-poker .seat.collector-seat {
        width: 122px;
        height: 122px;
        padding: 0;
        background: transparent;
        border: 0;
        border-radius: 0;
        box-shadow: none;
        overflow: visible;
    }
    #modern-poker .seat.collector-seat.turn {
        background: transparent;
        border: 0;
        box-shadow: none;
        transform: translateY(-2px);
    }
    #modern-poker .seat.collector-seat .avatar {
        width: 122px;
        height: 122px;
        margin: 0;
        object-fit: contain;
        border: 0;
        border-radius: 0;
        background: transparent;
        box-shadow: none;
        filter: drop-shadow(0 7px 9px rgba(0,0,0,.78));
        transform: translateX(-18px);
    }
    #modern-poker .seat.collector-seat .username {
        display: none;
    }
    #modern-poker .seat.collector-seat .stack {
        position: absolute;
        left: 50%;
        bottom: -15px;
        transform: translateX(-50%);
        min-width: 74px;
        padding: 2px 7px;
        border: 1px solid rgba(185,52,42,.72);
        border-radius: 10px;
        background: rgba(7,7,7,.9);
        color: #f0d1a5;
        text-align: center;
        white-space: nowrap;
        box-shadow: 0 3px 8px rgba(0,0,0,.7);
        transform: translateX(-55px);
    }
    #modern-poker .seat.collector-seat .seat-state {
        bottom: -34px;
    }

    #modern-poker .username { font-size:11px;line-height:14px;color:#f4f4f4; }
    #modern-poker .stack { color:#8fe879;font-size:11px;font-weight:700;line-height:14px;text-shadow:0 1px 2px #000; }
    #modern-poker .seat-state {
        position:absolute;left:50%;bottom:-18px;transform:translateX(-50%);
        min-width:64px;padding:2px 7px;border-radius:10px;background:rgba(0,0,0,.82);
        border:1px solid rgba(255,255,255,.16);color:#ffcc66;font-size:8px;line-height:12px;white-space:nowrap;
    }
    #modern-poker .hole img,#modern-poker .community img {
        border-radius:5px;border:1px solid rgba(255,255,255,.72);box-shadow:0 5px 10px rgba(0,0,0,.55);
    }
    #modern-poker .bet-chip {
        min-width:58px;padding:4px 8px 4px 23px;border:1px solid rgba(255,180,70,.48);
        background:radial-gradient(circle at 12px 50%,#f2a23a 0 5px,#8c4c0d 6px 8px,transparent 9px),
                   linear-gradient(180deg,rgba(24,24,24,.94),rgba(3,3,3,.94));
        border-radius:13px;box-shadow:0 4px 9px rgba(0,0,0,.55);font-weight:700;
    }
    #modern-poker .dealer-button,#modern-poker .blind-button {
        position:absolute;width:26px;height:26px;border-radius:50%;font-weight:800;line-height:22px;
        text-align:center;font-size:10px;pointer-events:none;z-index:6;box-shadow:0 3px 7px rgba(0,0,0,.72);
    }
    #modern-poker .dealer-button { background:linear-gradient(#fff,#cfcfcf);color:#111;border:2px solid #777; }
    #modern-poker .blind-button.sb { background:linear-gradient(#bc55d7,#6e2186);color:#fff;border:2px solid #e59bf5; }
    #modern-poker .blind-button.bb { background:linear-gradient(#efb52f,#a76508);color:#161008;border:2px solid #ffd86c; }
    #modern-poker .winner-banner {
        display:none;width:420px;margin:10px auto 0;padding:10px 16px;
        border:1px solid rgba(255,177,52,.72);border-radius:10px;
        background:linear-gradient(180deg,rgba(45,27,8,.96),rgba(12,8,4,.96));color:#ffbd55;
        box-shadow:0 0 22px rgba(255,155,25,.24),0 8px 18px rgba(0,0,0,.55);
        text-align:center;font-size:15px;font-weight:800;pointer-events:none;
    }
    #modern-poker .winner-banner.show { display:block;animation:poker-result-in .2s ease-out; }

    @keyframes poker-result-in {
        from { opacity:0;transform:translateY(-5px) scale(.98); }
        to { opacity:1;transform:translateY(0) scale(1); }
    }


#modern-poker .table-status-strip {
    position: relative;
}

#modern-poker .status-deal-control {
    margin-left: auto;
    display: flex;
    align-items: center;
    justify-content: flex-end;
}

#modern-poker .status-deal-control #startHand {
    margin: 0;
    white-space: nowrap;
}

/* Primary play-area controls: make the buttons you actually play with stand out. */
#modern-poker .table-action-buttons .move,
#modern-poker .table-raise-controls .move,
#modern-poker #startHand {
    border-color: #b77a20;
    background: linear-gradient(180deg, #65451a, #3b280f);
    color: #ffd98a;
    box-shadow:
        inset 0 1px 0 rgba(255,255,255,.10),
        0 0 0 1px rgba(218,145,34,.10),
        0 0 10px rgba(218,145,34,.16);
    text-shadow: 0 1px 1px #000;
}

#modern-poker .table-action-buttons .move:hover:not(:disabled),
#modern-poker .table-raise-controls .move:hover:not(:disabled),
#modern-poker #startHand:hover:not(:disabled) {
    border-color: #e2a43d;
    background: linear-gradient(180deg, #805921, #4c3311);
    color: #fff0bd;
    box-shadow:
        inset 0 1px 0 rgba(255,255,255,.12),
        0 0 13px rgba(226,164,61,.32);
}

#modern-poker .table-action-buttons .move:disabled,
#modern-poker .table-raise-controls .move:disabled,
#modern-poker #startHand:disabled {
    border-color: #6b542f;
    background: linear-gradient(180deg, #3b301e, #262015);
    color: #a9946d;
    box-shadow: none;
    opacity: .55;
}

/* Keep the raise entry itself visually tied to the play controls. */
#modern-poker .table-raise-controls input,
#modern-poker .table-raise-controls select {
    border-color: #755522;
}

#modern-poker .table-raise-controls input:focus,
#modern-poker .table-raise-controls select:focus {
    border-color: #d89a34;
    box-shadow: 0 0 0 2px rgba(216,154,52,.14);
}

</style>

<div id="modern-poker" data-table-id="<?php echo (int) $tableId; ?>" data-csrf="<?php echo $csrf; ?>" data-user-id="<?php echo (int) $CURUSER['id']; ?>">
    <div class="poker-layout">
        <div class="poker-play-column">
            <div class="table-wrap" id="pokerTable">
            <div class="community" id="community"></div>
            </div>

            <div class="winner-banner" id="winnerBanner" role="status" aria-live="polite"></div>

            <div class="table-action-strip">
                <div class="table-action-status">
                    <div class="action-pot-display" id="potDisplay">
                        <span>POT</span>
                        <strong id="potAmount">0</strong>
                    </div>
                    <div class="notice" id="turnNotice">Waiting for the table...</div>
                    <div class="turn-countdown" id="turnCountdown" hidden>
                        <span id="turnCountdownLabel">Turn timer</span>
                        <strong id="turnSeconds">--</strong>
                    </div>
                </div>

                <div class="table-action-controls">
                    <div class="table-action-buttons">
                        <button type="button" class="danger move" data-move="fold" disabled>Fold</button>
                        <button type="button" class="primary move" data-move="check" disabled>Check</button>
                        <button type="button" class="primary move" data-move="call" disabled id="callButton">Call</button>
                        <button type="button" class="danger move" data-move="allin" disabled>All In</button>
                    </div>

                    <div class="table-raise-controls">
                        <label for="raiseToAmount" class="muted">Raise to</label>
                        <input type="number" id="raiseToAmount" min="0" step="1" value="" aria-label="Raise-to amount" autocomplete="off">
                        <select id="raiseToUnit" aria-label="Raise-to unit">
                            <option value="MB">MB</option>
                            <option value="GB">GB</option>
                        </select>
                        <button type="button" class="good move" data-move="raise" disabled>Raise</button>
                    </div>
                </div>

                <div class="muted table-raise-limit" id="raiseLimit">Table limit applies.</div>
            </div>

            <div class="table-status-strip">
                <span class="table-status-label">STATUS</span>
                <strong class="table-status-value" id="statusValue">OK</strong>
                <div class="notice" id="statusNotice"></div>
            
    <div class="status-deal-control">
        <button type="button" class="good" id="startHand" disabled>Deal / Next Hand</button>
    </div>
</div>
        </div>

        <div>
            <div class="panel chat-panel prominent-chat" id="chatPanel">
                <div class="chat-panel-head">
                    <h3>Table Chat</h3>
                    <span class="chat-live-label">LIVE TABLE</span>
                </div>
                <div class="chat-log" id="chatLog">
                    <div class="chat-empty">No messages yet.</div>
                </div>
                <div class="chat-compose">
                    <input type="text" id="chatMessage" maxlength="300" autocomplete="off" placeholder="Sit down to chat..." disabled>
                    <button type="button" class="primary" id="chatSend" disabled>Send</button>
                </div>
                <div class="chat-counter"><span id="chatCount">0</span>/300</div>
            </div>

            <div class="panel">
                <h3 id="tableName">Poker Table</h3>
                <div class="info-row"><span>Blinds</span><strong id="blinds">-</strong></div>
                <div class="info-row"><span>Hand</span><strong id="handNo">-</strong></div>
                <div class="table-status-panel" id="tableStatusPanel">
                    <span>Table Status</span>
                    <strong id="tableMessage">Loading table...</strong>
                </div>
                <div id="tournamentTableInfo" hidden>
                    <div class="info-row"><span>Tournament</span><strong id="tournamentStatus">-</strong></div>
                    <div class="info-row"><span>Registered</span><strong id="tournamentRegistered">-</strong></div>
                    <div class="info-row"><span>Prize Pool</span><strong id="tournamentPrize">-</strong></div>
                </div>
                <div class="info-row"><span>Mode</span><strong id="viewMode" class="spectator-mode">Spectating</strong></div>
                <div class="info-row"><span>Your seat</span><strong id="mySeat">Not seated</strong></div>
                <div class="info-row"><span>Your stack</span><strong id="myStack">-</strong></div>
                <div class="spectator-summary" id="spectatorSummary">
                    <button type="button" class="spectator-summary-button" id="spectatorOpen" aria-haspopup="dialog" aria-controls="spectatorOverlay">
                        <span>Spectators</span>
                        <strong><span id="spectatorCount">0</span> Watching</strong>
                    </button>
                </div>
                <div class="actions">
                    <button type="button" class="good" id="adminStartTournament" hidden disabled>Start Tournament</button>
                    
                    <button type="button" id="sitOutToggle" disabled>Sit Out</button>
                    <button type="button" class="danger" id="leaveTable" disabled>Leave Table</button>
                    <button type="button" id="soundToggle" aria-pressed="true">Game Sounds: On</button>
                    <button type="button" id="ambienceToggle" aria-pressed="true">Room Ambience: On</button>
                    <a class="poker-link-button" href="poker-lobby.php">Poker Lobby</a>
                    <a class="poker-link-button" href="poker-leaderboard.php">Leaderboard</a>
                    <a class="poker-link-button" href="poker-profile.php">My Profile</a>
                    <button type="button" class="history-open" id="historyOpen">Hand History</button>
                </div>
            </div>

            <div class="panel buyin-box" id="buyinBox">
                <h3>Take Seat <span id="selectedSeat"></span></h3>
                <div class="muted" id="buyinLimits"></div>
                <div class="buyin-entry">
                    <input type="number" id="buyinAmount" min="1" step="1" value="" aria-label="Buy-in amount">
                    <select id="buyinUnit" aria-label="Buy-in unit">
                        <option value="MB">MB</option>
                        <option value="GB">GB</option>
                    </select>
                </div>
                <div class="actions">
                    <button type="button" class="good" id="confirmSeat">Buy In</button>
                    <button type="button" id="cancelSeat">Cancel</button>
                </div>
            </div>

        </div>
    </div>
</div>


<div class="poker-user-notice-overlay" id="userNoticeOverlay" aria-hidden="true">
    <div class="poker-user-notice-popout" role="dialog" aria-modal="true" aria-labelledby="userNoticeTitle">
        <strong id="userNoticeTitle">Poker Notice</strong>
        <p class="poker-user-notice-message" id="userNoticeMessage"></p>
        <button type="button" class="poker-user-notice-close" id="userNoticeClose">Close</button>
    </div>
</div>

<div class="poker-house-broke-overlay" id="houseBrokeOverlay" aria-hidden="true">
    <div class="poker-house-broke-popout" role="dialog" aria-modal="true" aria-labelledby="houseBrokeTitle">
        <strong id="houseBrokeTitle">A Notice from The Collector</strong>
        <p class="poker-house-broke-message">You are now poor. All of your upload are belong to us.</p>
        <button type="button" class="poker-house-broke-close" id="houseBrokeClose">Close</button>
    </div>
</div>

<div class="poker-spectator-overlay" id="spectatorOverlay" aria-hidden="true">
    <div class="poker-spectator-popout" role="dialog" aria-modal="true" aria-labelledby="spectatorPopoutTitle">
        <div class="poker-spectator-popout-head">
            <strong id="spectatorPopoutTitle">Spectators</strong>
            <span id="spectatorPopoutCount">0 watching</span>
        </div>
        <div class="poker-spectator-list" id="spectatorList">
            <div class="poker-spectator-empty">Nobody watching.</div>
        </div>
    </div>
</div>

<div class="poker-history-overlay" id="historyOverlay" aria-hidden="true">
    <div class="poker-history-window">
        <div class="poker-history-list" id="historyList">
            <div class="poker-history-list-title">Recent Hands</div>
        </div>
        <div class="poker-history-detail" id="historyDetail">
            <div class="poker-history-empty">Select a hand to view its history.</div>
        </div>
    </div>
</div>

<script>
    (function() {
        'use strict';

        var root = document.getElementById('modern-poker');
        var tableEl = document.getElementById('pokerTable');
        var MB = <?php echo MB; ?>;
        var GB = <?php echo GB; ?>;

        // Keep the poker interface clean by disabling the browser context menu.
        document.addEventListener('contextmenu', function(event) {
            event.preventDefault();
        });
        var tableId = parseInt(root.getAttribute('data-table-id'), 10);
        var csrf = root.getAttribute('data-csrf');
        var selectedSeat = 0;
        var busy = false;
        var stateEpoch = 0;
        var statePollInFlight = false;
        var lastState = null;
        var lastRaiseContextKey = '';
        var statusErrorTimer = null;
        var houseThinkTimer = null;
        var houseBrokeShown = false;
        var housePresentationHand = 0;
        var houseHoleRevealAt = {};
        var houseBoardRevealAt = [];
        var houseOutcomeRevealAt = 0;
        var soundEnabled = localStorage.getItem('pokerSoundEnabled') !== '0';
        var pokerUserId = root.getAttribute('data-user-id') || '0';
        var ambienceStorageKey = 'pokerAmbienceEnabled_' + pokerUserId;
        var ambienceStored = localStorage.getItem(ambienceStorageKey);
        var ambienceEnabled = ambienceStored === null ? true : ambienceStored === '1';
        var ambienceStarted = false;
        var ambienceAudio = new Audio('sounds/poker/poker-room-ambience.mp3');
        ambienceAudio.loop = true;
        ambienceAudio.preload = 'none';
        ambienceAudio.volume = 1.00;
        var turnDeadlineMs = 0;
        var turnTimerKey = '';
        var turnWarningPlayed = false;
        var turnExpiryRequested = false;
        var chatBusy = false;
        var lastChatId = 0;
        var historySelectedHand = 0;

        var pokerSounds = {
            card: new Audio('sounds/poker/placing-playing-card.mp3'),
            check: new Audio('sounds/poker/check.mp3'),
            blind: new Audio('sounds/poker/blind.mp3'),
            call: new Audio('sounds/poker/call.mp3'),
            raise: new Audio('sounds/poker/raise.mp3'),
            allin: new Audio('sounds/poker/allin.mp3'),
            pot: new Audio('sounds/poker/pot.mp3'),
            deal2: new Audio('sounds/poker/deal2.mp3'),
            deal3: new Audio('sounds/poker/deal3.mp3'),
            deal4: new Audio('sounds/poker/deal4.mp3'),
            deal5: new Audio('sounds/poker/deal5.mp3'),
            deal6: new Audio('sounds/poker/deal6.mp3'),
            flop: new Audio('sounds/poker/flop.mp3'),
            fold: new Audio('sounds/poker/fold.mp3'),
            shuffle: new Audio('sounds/poker/shuffle.mp3'),
            yourTurn: new Audio('sounds/poker/your-turn.mp3')
        };

        Object.keys(pokerSounds).forEach(function(key) {
            pokerSounds[key].preload = 'auto';
            pokerSounds[key].volume = 0.75;
        });

        function playSound(name) {
            if (!soundEnabled || !pokerSounds[name]) return;

            try {
                pokerSounds[name].currentTime = 0;
                var promise = pokerSounds[name].play();
                if (promise && typeof promise.catch === 'function') {
                    promise.catch(function() {});
                }
            } catch (e) {}
        }

        function playCardPlacement(count, spacing) {
            if (!soundEnabled || !pokerSounds.card) return;

            count = Math.max(1, parseInt(count || 1, 10));
            spacing = Math.max(70, parseInt(spacing || 120, 10));

            for (var i = 0; i < count; i++) {
                (function(delay) {
                    window.setTimeout(function() {
                        try {
                            var sound = pokerSounds.card.cloneNode(true);
                            sound.volume = pokerSounds.card.volume;
                            var promise = sound.play();
                            if (promise && typeof promise.catch === 'function') {
                                promise.catch(function() {});
                            }
                        } catch (e) {}
                    }, delay);
                }(i * spacing));
            }
        }

        function updateAmbienceButton() {
            var button = document.getElementById('ambienceToggle');
            if (!button) return;

            button.textContent = ambienceEnabled ? 'Room Ambience: On' : 'Room Ambience: Muted';
            button.setAttribute('aria-pressed', ambienceEnabled ? 'true' : 'false');
        }

        function stopAmbience() {
            try {
                ambienceAudio.pause();
            } catch (e) {}
            ambienceStarted = false;
        }

        function startAmbience() {
            if (!ambienceEnabled || ambienceStarted) return;

            try {
                var promise = ambienceAudio.play();

                if (promise && typeof promise.then === 'function') {
                    promise.then(function() {
                        ambienceStarted = true;
                    }).catch(function() {
                        ambienceStarted = false;
                    });
                } else {
                    ambienceStarted = true;
                }
            } catch (e) {
                ambienceStarted = false;
            }
        }

        function startAmbienceFromInteraction() {
            if (!ambienceEnabled || ambienceStarted) return;
            startAmbience();
        }

        function occupiedCount(state) {
            var count = 0;

            for (var i = 1; i <= 10; i++) {
                if (state.seats[i] && state.seats[i].state !== 'waiting') {
                    count++;
                }
            }

            return count;
        }

        function dealSoundForPlayers(count) {
            if (count <= 2) return 'deal2';
            if (count === 3) return 'deal3';
            if (count === 4) return 'deal4';
            if (count === 5) return 'deal5';
            return 'deal6';
        }

        function soundForStateChange(previous, state) {
            if (!previous) return;

            var oldHand = parseInt(previous.table.hand_no || 0, 10);
            var newHand = parseInt(state.table.hand_no || 0, 10);

            if (newHand > oldHand) {
                playSound('shuffle');
                window.setTimeout(function() {
                    // Two hole cards are physically laid down for every active player.
                    playCardPlacement(Math.max(1, occupiedCount(state) * 2), 325);
                }, 300);
                return;
            }

            var oldStreet = String(previous.table.street || '').toLowerCase();
            var newStreet = String(state.table.street || '').toLowerCase();
            var isHouse = state.table.game_type === 'house';
            var oldBoardCount = previous.table.community && previous.table.community.length
                ? previous.table.community.length
                : 0;
            var newBoardCount = state.table.community && state.table.community.length
                ? state.table.community.length
                : 0;
            var houseBoardSound = false;

            if (isHouse && newBoardCount > oldBoardCount) {
                houseBoardSound = true;

                if (oldBoardCount === 0 && newBoardCount >= 5) {
                    window.setTimeout(function() { playCardPlacement(3, 275); }, 250);
                    window.setTimeout(function() { playCardPlacement(1, 325); }, 1450);
                    window.setTimeout(function() { playCardPlacement(1, 325); }, 2100);
                } else {
                    window.setTimeout(function() {
                        playCardPlacement(newBoardCount - oldBoardCount, 275);
                    }, 250);
                }
            }

            if (!houseBoardSound && newStreet !== oldStreet) {
                if (newStreet === 'flop') {
                    playCardPlacement(3, 275);
                } else if (newStreet === 'turn' || newStreet === 'river') {
                    playCardPlacement(1, 325);
                }
            }

            var oldMsg = String(previous.table.message || '').toLowerCase();
            var newMsg = String(state.table.message || '').toLowerCase();

            if (newMsg && newMsg !== oldMsg) {
                if (newMsg.indexOf('fold') !== -1) {
                    playSound('fold');
                } else if (newMsg.indexOf('check') !== -1) {
                    playSound('check');
                } else if (
                    newMsg.indexOf('all-in') !== -1 ||
                    newMsg.indexOf('all in') !== -1
                ) {
                    playSound('allin');
                } else if (
                    newMsg.indexOf('raise') !== -1 ||
                    newMsg.indexOf('bet ') !== -1 ||
                    newMsg.indexOf(' bets') !== -1
                ) {
                    playSound('raise');
                } else if (newMsg.indexOf('blind') !== -1) {
                    playSound('blind');
                } else if (newMsg.indexOf('call') !== -1) {
                    playSound('call');
                } else if (
                    newMsg.indexOf('wins') !== -1 ||
                    newMsg.indexOf('won') !== -1
                ) {
                    playSound('pot');
                }
            }

            if (!previous.me.is_turn && state.me.is_turn) {
                window.setTimeout(function() {
                    playSound('yourTurn');
                }, 140);
            }
        }

        function updateTurnCountdown() {
            var box = document.getElementById('turnCountdown');
            var label = document.getElementById('turnCountdownLabel');
            var secondsEl = document.getElementById('turnSeconds');

            if (!lastState || lastState.table.status !== 'playing' || !lastState.table.current_turn || !turnDeadlineMs) {
                box.hidden = true;
                return;
            }

            var currentSeat = lastState.seats[lastState.table.current_turn] || null;
            var reconnecting = !!(currentSeat && currentSeat.reconnecting);
            var remaining = Math.max(0, Math.ceil((turnDeadlineMs - Date.now()) / 1000));

            label.textContent = reconnecting ? 'Reconnect grace' : 'Turn timer';
            secondsEl.textContent = remaining + 's';
            box.hidden = false;

            if (reconnecting) {
                box.className = 'turn-countdown reconnect' + (remaining <= 5 ? ' danger' : '');
            } else {
                box.className = 'turn-countdown' + (remaining <= 5 ? ' danger' : (remaining <= 10 ? ' warning' : ''));
            }

            var badge = tableEl.querySelector('.seat[data-seat="' + lastState.table.current_turn + '"] .turn-timer-badge');
            if (badge) {
                badge.textContent = remaining;
                badge.className = 'turn-timer-badge' + (remaining <= 5 ? ' danger' : (remaining <= 10 ? ' warning' : ''));
            }

            if (!reconnecting && lastState.me.is_turn && remaining > 0 && remaining <= 5 && !turnWarningPlayed) {
                turnWarningPlayed = true;
                playSound('yourTurn');
            }

            if (remaining === 0 && !turnExpiryRequested) {
                turnExpiryRequested = true;
                document.querySelectorAll('#modern-poker .move').forEach(function(button) {
                    button.disabled = true;
                });
                fetchState();
            }
        }

        function syncTurnCountdown(state) {
            if (state.table.status !== 'playing' || !state.table.current_turn || !state.table.turn_expires_at) {
                turnDeadlineMs = 0;
                turnTimerKey = '';
                turnWarningPlayed = false;
                turnExpiryRequested = false;
                updateTurnCountdown();
                return;
            }

            var key = String(state.table.hand_no) + ':' + String(state.table.current_turn) + ':' + String(state.table.turn_expires_at);
            if (key !== turnTimerKey) {
                turnTimerKey = key;
                turnWarningPlayed = false;
                turnExpiryRequested = false;
            }

            var secondsLeft = Math.max(0, parseInt(state.table.turn_seconds_left || 0, 10));
            turnDeadlineMs = Date.now() + (secondsLeft * 1000);
            updateTurnCountdown();
        }

        /*
         * Visual layout: player HUDs live outside the card area while hole
         * cards sit toward the felt.  This keeps avatars / D / SB / BB from
         * covering the cards, especially at the bottom-center seat.
         */
        var seatPositions = {
            1: [14, 118],
            2: [160, 2],
            3: [339, 2],
            4: [514, 2],
            5: [653, 118],

            6: [653, 306],
            7: [519, 436],
            8: [338, 436],
            9: [149, 436],
            10: [14, 306]
        };

        var handPositions = {
            1: [105, 158],
            2: [191, 116],
            3: [335, 104],
            4: [477, 116],
            5: [572, 158],

            6: [566, 302],
            7: [477, 336],
            8: [335, 326],
            9: [191, 336],
            10: [105, 302]
        };

        var betPositions = {
            1: [137, 225],
            2: [225, 198],
            3: [355, 186],
            4: [484, 198],
            5: [581, 225],

            6: [581, 288],
            7: [491, 332],
            8: [355, 318],
            9: [213, 332],
            10: [134, 288]
        };

        /* Marker positions are beside the cards rather than on top of them. */
        var dealerPositions = {
            1: [113, 184],
            2: [222, 142],
            3: [431, 129],
            4: [524, 142],
            5: [630, 184],

            6: [628, 344],
            7: [572, 379],
            8: [435, 366],
            9: [170, 379],
            10: [113, 344]
        };

        function activeSeatNumbers(state) {
            var numbers = [];
            Object.keys(state.seats || {}).forEach(function(key) {
                var seatNo = parseInt(key, 10);
                var seat = state.seats[key];
                if (!seat || !seat.user_id || seat.state === 'waiting' || seat.sitting_out) return;
                numbers.push(seatNo);
            });
            numbers.sort(function(a,b){ return a-b; });
            return numbers;
        }

        function nextSeatNumber(numbers, current) {
            if (!numbers.length) return null;
            for (var i=0;i<numbers.length;i++) if (numbers[i] > current) return numbers[i];
            return numbers[0];
        }

        function blindSeatsForState(state) {
            var players = activeSeatNumbers(state);
            var dealer = parseInt(state.table.dealer_seat || 0,10);
            if (!dealer || players.length < 2) return {sb:null,bb:null};
            if (players.length === 2) return {sb:dealer,bb:nextSeatNumber(players,dealer)};
            var sb = nextSeatNumber(players,dealer);
            return {sb:sb,bb:nextSeatNumber(players,sb)};
        }

        function addBlindButton(kind, seatNo) {
            if (!seatNo || !dealerPositions[seatNo]) return;

            var base = dealerPositions[seatNo];
            var offsetX = 30;
            var offsetY = 0;

            /* Keep blind badges clear of both cards and the player HUD. */
            if (seatNo === 3 || seatNo === 8) {
                offsetX = kind === 'SB' ? -30 : 30;
            } else if (seatNo === 4 || seatNo === 5 || seatNo === 6 || seatNo === 7) {
                offsetX = kind === 'SB' ? 30 : 58;
            } else {
                offsetX = kind === 'SB' ? -30 : -58;
            }

            var badge = document.createElement('div');
            badge.className = 'blind-button ' + kind.toLowerCase();
            badge.textContent = kind;
            badge.style.left = (base[0] + offsetX) + 'px';
            badge.style.top = (base[1] + offsetY) + 'px';
            tableEl.appendChild(badge);
        }


        function escCard(card) {
            return String(card || '').replace(/[^0-9JQKACDHS]/g, '');
        }

        function cardUrl(card) {
            if (card === 'BACK') return 'images/poker/cards/facedown.webp';
            return 'images/poker/cards/' + escCard(card) + '.webp';
        }

        function setNotice(text, error) {
            var el = document.getElementById('statusNotice');
            var value = document.getElementById('statusValue');

            if (statusErrorTimer) {
                window.clearTimeout(statusErrorTimer);
                statusErrorTimer = null;
            }

            if (error) {
                value.textContent = 'ERROR';
                value.className = 'table-status-value error';
                el.textContent = text || 'Poker action failed.';
                el.className = 'notice error';

                statusErrorTimer = window.setTimeout(function() {
                    value.textContent = 'OK';
                    value.className = 'table-status-value';
                    el.textContent = '';
                    el.className = 'notice';
                    statusErrorTimer = null;
                }, 5000);
            } else {
                value.textContent = 'OK';
                value.className = 'table-status-value';
                el.textContent = '';
                el.className = 'notice';
            }
        }

        function openUserNotice(notice) {
            if (!notice) return;

            var overlay = document.getElementById('userNoticeOverlay');
            if (!overlay) return;

            document.getElementById('userNoticeTitle').textContent = notice.title || 'Poker Notice';
            document.getElementById('userNoticeMessage').textContent = notice.message || '';
            overlay.classList.add('open');
            overlay.setAttribute('aria-hidden', 'false');
        }

        function closeUserNotice() {
            var overlay = document.getElementById('userNoticeOverlay');
            if (!overlay) return;
            overlay.classList.remove('open');
            overlay.setAttribute('aria-hidden', 'true');
        }

        function openHouseBrokePopup() {
            var overlay = document.getElementById('houseBrokeOverlay');
            if (!overlay) return;
            overlay.classList.add('open');
            overlay.setAttribute('aria-hidden', 'false');
        }

        function closeHouseBrokePopup() {
            var overlay = document.getElementById('houseBrokeOverlay');
            if (!overlay) return;
            overlay.classList.remove('open');
            overlay.setAttribute('aria-hidden', 'true');
        }

        function post(action, data) {
            if (busy) return Promise.reject(new Error('Please wait for the previous action.'));
            busy = true;

            /*
             * Invalidate any state request that started before this action.
             * Without this, an older polling response can arrive after a House
             * hand finishes and repaint the UI with stale "playing" state,
             * leaving Deal / Next Hand disabled even though the server is ready.
             */
            stateEpoch++;

            var body = new URLSearchParams();
            body.set('action', action);
            body.set('table_id', tableId);
            body.set('csrf', csrf);
            Object.keys(data || {}).forEach(function(key) {
                body.set(key, data[key]);
            });
            return fetch('poker-api.php', {
                method: 'POST',
                headers: {
                    'Content-Type': 'application/x-www-form-urlencoded; charset=UTF-8'
                },
                credentials: 'same-origin',
                body: body.toString()
            }).then(function(r) {
                return r.json();
            }).then(function(json) {
                busy = false;
                if (!json.ok) throw new Error(json.error || 'Poker action failed.');
                render(json);

                /*
                 * Reconfirm the authoritative server state after an action.
                 * This is especially useful for House hands where the bot may
                 * immediately become responsible for the next transition.
                 */
                window.setTimeout(fetchState, 100);

                return json;
            }).catch(function(err) {
                busy = false;
                setNotice(err.message, true);

                var isHouseGame = !!(lastState && lastState.table && lastState.table.game_type === 'house');
                if (isHouseGame && /not enough upload credit|stack is below the big blind/i.test(String(err.message || ''))) {
                    houseBrokeShown = true;
                    openHouseBrokePopup();
                }

                throw err;
            });
        }

        function openSpectators() {
            var overlay = document.getElementById('spectatorOverlay');
            overlay.classList.add('open');
            overlay.setAttribute('aria-hidden', 'false');
        }

        function closeSpectators() {
            var overlay = document.getElementById('spectatorOverlay');
            overlay.classList.remove('open');
            overlay.setAttribute('aria-hidden', 'true');
        }

        function historyFetch(handNo) {
            var params = new URLSearchParams();
            params.set('action', 'history');
            params.set('table_id', tableId);
            if (handNo) params.set('hand_no', handNo);

            return fetch('poker-api.php?' + params.toString(), {
                credentials: 'same-origin'
            }).then(function(response) {
                return response.json();
            }).then(function(json) {
                if (!json.ok) {
                    throw new Error(json.error || 'Could not load hand history.');
                }
                return json;
            });
        }

        function historyActionText(action) {
            var name = String(action.action || '').replace(/^auto_/, '').replace(/_/g, ' ');
            name = name.charAt(0).toUpperCase() + name.slice(1);

            if (action.amount_text) {
                return name + ' ' + action.amount_text;
            }

            return name;
        }

        function historyCardRow(cards, className) {
            var row = document.createElement('div');
            row.className = className;

            (cards || []).forEach(function(card) {
                var img = document.createElement('img');
                img.src = cardUrl(card);
                img.alt = card === 'BACK' ? 'Hidden card' : card;
                row.appendChild(img);
            });

            return row;
        }

        function renderHistoryList(hands) {
            var list = document.getElementById('historyList');
            list.innerHTML = '';

            var title = document.createElement('div');
            title.className = 'poker-history-list-title';
            title.textContent = 'Recent Hands';
            list.appendChild(title);

            if (!hands.length) {
                var empty = document.createElement('div');
                empty.className = 'poker-history-empty';
                empty.textContent = 'No recorded hands yet.';
                list.appendChild(empty);
                return;
            }

            hands.forEach(function(hand) {
                var button = document.createElement('button');
                button.type = 'button';
                button.className = 'poker-history-item' + (hand.hand_no === historySelectedHand ? ' active' : '');

                var strong = document.createElement('strong');
                strong.textContent = 'Hand #' + hand.hand_no;

                var time = document.createElement('span');
                time.textContent = hand.started + (hand.status === 'playing' ? ' · In progress' : '');

                button.appendChild(strong);
                button.appendChild(time);

                button.addEventListener('click', function() {
                    historySelectedHand = hand.hand_no;
                    loadHistoryDetail(hand.hand_no);
                });

                list.appendChild(button);
            });
        }

        function renderHistoryDetail(hand) {
            var detail = document.getElementById('historyDetail');
            detail.innerHTML = '';

            var head = document.createElement('div');
            head.className = 'poker-history-head';

            var headText = document.createElement('div');
            var title = document.createElement('h2');
            title.textContent = 'Hand #' + hand.hand_no;
            var meta = document.createElement('div');
            meta.className = 'muted';
            meta.textContent = hand.started + ' · Blinds ' + hand.small_blind_text + ' / ' + hand.big_blind_text;

            headText.appendChild(title);
            headText.appendChild(meta);
            head.appendChild(headText);
            detail.appendChild(head);

            if (hand.result) {
                var result = document.createElement('div');
                result.className = 'poker-history-result';
                result.textContent = hand.result;
                detail.appendChild(result);
            }

            var boardSection = document.createElement('div');
            boardSection.className = 'poker-history-section';
            var boardTitle = document.createElement('h3');
            boardTitle.textContent = 'Board';
            boardSection.appendChild(boardTitle);

            if (hand.board && hand.board.length) {
                boardSection.appendChild(historyCardRow(hand.board, 'poker-history-board'));
            } else {
                var noBoard = document.createElement('div');
                noBoard.className = 'muted';
                noBoard.textContent = 'No community cards were dealt.';
                boardSection.appendChild(noBoard);
            }
            detail.appendChild(boardSection);

            var playersSection = document.createElement('div');
            playersSection.className = 'poker-history-section';
            var playersTitle = document.createElement('h3');
            playersTitle.textContent = 'Players';
            playersSection.appendChild(playersTitle);

            hand.players.forEach(function(player) {
                var row = document.createElement('div');
                row.className = 'poker-history-player';

                var seat = document.createElement('div');
                seat.className = player.seat_no === hand.dealer_seat ? 'dealer' : '';
                seat.textContent = 'Seat ' + player.seat_no + (player.seat_no === hand.dealer_seat ? ' D' : '');

                var name = document.createElement('div');
                name.textContent = player.username;

                var stack = document.createElement('div');
                stack.className = 'muted';
                stack.textContent = player.starting_stack_text;

                row.appendChild(seat);
                row.appendChild(name);
                row.appendChild(stack);
                row.appendChild(historyCardRow(player.cards, 'poker-history-hole'));
                playersSection.appendChild(row);
            });

            detail.appendChild(playersSection);

            var actionSection = document.createElement('div');
            actionSection.className = 'poker-history-section';
            var actionTitle = document.createElement('h3');
            actionTitle.textContent = 'Action';
            actionSection.appendChild(actionTitle);

            var actionList = document.createElement('div');
            actionList.className = 'poker-history-actions';

            if (!hand.actions.length) {
                var noActions = document.createElement('div');
                noActions.className = 'poker-history-empty';
                noActions.textContent = 'No actions recorded.';
                actionList.appendChild(noActions);
            } else {
                hand.actions.forEach(function(action) {
                    var row = document.createElement('div');
                    row.className = 'poker-history-action';

                    var time = document.createElement('div');
                    time.className = 'time';
                    time.textContent = action.time;

                    var name = document.createElement('div');
                    name.className = 'name';
                    name.textContent = action.username;

                    var text = document.createElement('div');
                    text.textContent = historyActionText(action);

                    row.appendChild(time);
                    row.appendChild(name);
                    row.appendChild(text);
                    actionList.appendChild(row);
                });
            }

            actionSection.appendChild(actionList);
            detail.appendChild(actionSection);
        }

        function loadHistoryList() {
            return historyFetch(0).then(function(json) {
                renderHistoryList(json.hands || []);

                if (!historySelectedHand && json.hands && json.hands.length) {
                    historySelectedHand = json.hands[0].hand_no;
                    return loadHistoryDetail(historySelectedHand);
                }
            }).catch(function(err) {
                setNotice(err.message, true);
            });
        }

        function loadHistoryDetail(handNo) {
            return historyFetch(handNo).then(function(json) {
                renderHistoryDetail(json.hand);
                return historyFetch(0);
            }).then(function(json) {
                renderHistoryList(json.hands || []);
            }).catch(function(err) {
                setNotice(err.message, true);
            });
        }

        function openHistory() {
            var overlay = document.getElementById('historyOverlay');
            overlay.classList.add('open');
            overlay.setAttribute('aria-hidden', 'false');
            loadHistoryList();
        }

        function closeHistory() {
            var overlay = document.getElementById('historyOverlay');
            overlay.classList.remove('open');
            overlay.setAttribute('aria-hidden', 'true');
        }

        function sendChat() {
            if (chatBusy || !lastState || !lastState.me.seat) return;

            var input = document.getElementById('chatMessage');
            var message = input.value.trim();

            if (!message) return;

            chatBusy = true;
            document.getElementById('chatSend').disabled = true;

            var body = new URLSearchParams();
            body.set('action', 'chat');
            body.set('table_id', tableId);
            body.set('csrf', csrf);
            body.set('message', message);

            fetch('poker-api.php', {
                method: 'POST',
                headers: {
                    'Content-Type': 'application/x-www-form-urlencoded; charset=UTF-8'
                },
                credentials: 'same-origin',
                body: body.toString()
            }).then(function(r) {
                return r.json();
            }).then(function(json) {
                chatBusy = false;

                if (!json.ok) {
                    throw new Error(json.error || 'Could not send chat message.');
                }

                input.value = '';
                document.getElementById('chatCount').textContent = '0';
                render(json);
            }).catch(function(err) {
                chatBusy = false;
                document.getElementById('chatSend').disabled = !(lastState && lastState.me.seat);
                setNotice(err.message, true);
            });
        }

        function renderChat(messages) {
            var log = document.getElementById('chatLog');
            var shouldStickToBottom = (log.scrollHeight - log.scrollTop - log.clientHeight) < 30;
            var newestId = messages.length ? messages[messages.length - 1].id : 0;
            var hasNewMessage = newestId > lastChatId;

            log.innerHTML = '';

            if (!messages.length) {
                var empty = document.createElement('div');
                empty.className = 'chat-empty';
                empty.textContent = 'No messages yet.';
                log.appendChild(empty);
            } else {
                messages.forEach(function(message) {
                    var line = document.createElement('div');
                    line.className = 'chat-line';
                    if (parseInt(message.user_id || 0, 10) === 0 && message.username === 'Dealer') {
                        line.classList.add('dealer-message');
                    }

                    var time = document.createElement('span');
                    time.className = 'chat-time';
                    time.textContent = message.time;

                    var name = document.createElement('span');
                    name.className = 'chat-name';
                    name.textContent = message.username + ':';

                    var body = document.createElement('span');
                    body.className = 'chat-text';
                    body.textContent = message.message;

                    line.appendChild(time);
                    line.appendChild(name);
                    line.appendChild(body);
                    log.appendChild(line);
                });
            }

            if (shouldStickToBottom || hasNewMessage) {
                log.scrollTop = log.scrollHeight;
            }

            lastChatId = Math.max(lastChatId, newestId);
        }

        function fetchState() {
            /*
             * Never allow state GETs to overlap. Starting a new poll while an
             * older one is still running is exactly how out-of-order table
             * state gets painted into the UI. Skipping one interval is safer
             * than having two competing responses.
             */
            if (statePollInFlight) return;

            statePollInFlight = true;
            var requestEpoch = stateEpoch;

            fetch('poker-api.php?action=state&table_id=' + encodeURIComponent(tableId) + '&_=' + Date.now(), {
                credentials: 'same-origin',
                cache: 'no-store'
            }).then(function(r) {
                return r.json();
            }).then(function(json) {
                if (!json.ok) throw new Error(json.error || 'Could not load poker table.');

                /*
                 * A POST action that began after this GET makes this response
                 * obsolete. Do not repaint the table with pre-action state.
                 */
                if (requestEpoch !== stateEpoch) return;

                render(json);
            }).catch(function(err) {
                if (requestEpoch !== stateEpoch) return;
                setNotice(err.message, true);
            }).finally(function() {
                statePollInFlight = false;
            });
        }

        function makeSeat(seatNo, data) {
            var el = document.createElement('div');
            el.className = 'seat';
            el.style.left = seatPositions[seatNo][0] + 'px';
            el.style.top = seatPositions[seatNo][1] + 'px';
            el.dataset.seat = seatNo;

            if (!data) {
                el.classList.add('empty');

                if (lastState && lastState.table && lastState.table.game_type === 'house') {
                    if (seatNo !== 8) {
                        el.style.display = 'none';
                        return el;
                    }
                }

                if (lastState && lastState.me.seat) {
                    el.style.display = 'none';
                    return el;
                }

                if (lastState && lastState.maintenance && lastState.maintenance.draining) {
                    el.textContent = 'MAINTENANCE';
                    el.style.cursor = 'default';
                } else {
                    el.textContent = '+ TAKE SEAT';
                    el.addEventListener('click', function() {
                        chooseSeat(seatNo);
                    });
                }

                return el;
            }

            if (data.is_turn) el.classList.add('turn');
            if (data.state === 'folded') el.classList.add('folded');
            if (data.sitting_out) el.classList.add('sitting-out');
            if (lastState && lastState.table && lastState.table.game_type === 'house' && parseInt(data.user_id || 0, 10) === 0) {
                el.classList.add('collector-seat');
            }

            if (data.avatar) {
                var img = document.createElement('img');
                img.className = 'avatar';
                img.src = data.avatar;
                img.alt = '';
                img.addEventListener('error', function() {
                    var fallback = document.createElement('div');
                    fallback.className = 'avatar-fallback';
                    fallback.textContent = data.username.charAt(0).toUpperCase();
                    img.replaceWith(fallback);
                });
                el.appendChild(img);
            } else {
                var fallback = document.createElement('div');
                fallback.className = 'avatar-fallback';
                fallback.textContent = data.username.charAt(0).toUpperCase();
                el.appendChild(fallback);
            }

            var name = document.createElement('div');
            name.className = 'username';
            name.textContent = data.username;
            el.appendChild(name);
            var stack = document.createElement('div');
            stack.className = 'stack';
            stack.textContent = data.stack_text;
            el.appendChild(stack);
            if (data.state !== 'waiting') {
                var state = document.createElement('div');
                state.className = 'seat-state';
                state.textContent = data.state;
                el.appendChild(state);
            }

            if (
                data.state === 'waiting' &&
                !data.sitting_out &&
                lastState &&
                lastState.table &&
                lastState.table.status === 'playing'
            ) {
                var waitingHand = document.createElement('div');
                waitingHand.className = 'waiting-hand-badge';
                waitingHand.textContent = 'NEXT HAND';
                el.appendChild(waitingHand);
            }

            if (data.sitting_out) {
                var sitout = document.createElement('div');
                sitout.className = 'sitout-badge';
                sitout.textContent = (data.state === 'active' || data.state === 'allin')
                    ? 'OUT NEXT HAND'
                    : 'SITTING OUT';
                el.appendChild(sitout);
            }

            if (data.reconnecting) {
                var reconnect = document.createElement('div');
                reconnect.className = 'reconnect-badge';
                reconnect.textContent = 'RECONNECT ' + data.reconnect_seconds_left + 's';
                el.appendChild(reconnect);
            } else if (!data.connected) {
                var offline = document.createElement('div');
                offline.className = 'offline-badge';
                offline.textContent = 'OFFLINE';
                el.appendChild(offline);
            }

            if (data.is_turn) {
                var timerBadge = document.createElement('div');
                timerBadge.className = 'turn-timer-badge';
                timerBadge.textContent = '--';
                el.appendChild(timerBadge);
            }
            return el;
        }

        function houseDealCard(img, revealAt) {
            var remaining = Math.max(0, parseInt(revealAt || 0, 10) - Date.now());
            if (remaining <= 0) return;
            img.classList.add('house-card-deal');
            img.style.animationDelay = remaining + 'ms';
        }

        function prepareHousePresentation(previous, state) {
            if (!state || !state.table || state.table.game_type !== 'house') return;

            var handNo = parseInt(state.table.hand_no || 0, 10);
            var oldHandNo = previous && previous.table ? parseInt(previous.table.hand_no || 0, 10) : 0;
            var now = Date.now();

            if (handNo > 0 && handNo !== housePresentationHand) {
                housePresentationHand = handNo;
                houseHoleRevealAt = {};
                houseBoardRevealAt = [];
                houseOutcomeRevealAt = 0;

                if (handNo > oldHandNo) {
                    var dealer = parseInt(state.table.dealer_seat || 0, 10);
                    var firstSeat = dealer === 8 ? 3 : 8;
                    var secondSeat = firstSeat === 3 ? 8 : 3;
                    var first = now + 300;
                    var gap = 325;

                    houseHoleRevealAt[firstSeat + ':0'] = first;
                    houseHoleRevealAt[secondSeat + ':0'] = first + gap;
                    houseHoleRevealAt[firstSeat + ':1'] = first + (gap * 2);
                    houseHoleRevealAt[secondSeat + ':1'] = first + (gap * 3);
                }
            }

            var oldBoard = previous && previous.table && Array.isArray(previous.table.community)
                ? previous.table.community.length
                : 0;
            var newBoard = Array.isArray(state.table.community)
                ? state.table.community.length
                : 0;

            if (newBoard > oldBoard) {
                var start = now + 250;

                /*
                 * A House all-in can resolve the whole board on the server in one
                 * response.  Keep that authoritative result, but reveal it like a
                 * real deal: flop, pause, turn, pause, river.
                 */
                if (oldBoard === 0 && newBoard >= 5) {
                    houseBoardRevealAt[0] = start;
                    houseBoardRevealAt[1] = start + 275;
                    houseBoardRevealAt[2] = start + 550;
                    houseBoardRevealAt[3] = start + 1200;
                    houseBoardRevealAt[4] = start + 1850;
                    houseOutcomeRevealAt = start + 2250;
                    window.setTimeout(fetchState, Math.max(0, houseOutcomeRevealAt - now) + 50);
                } else {
                    for (var i = oldBoard; i < newBoard; i++) {
                        houseBoardRevealAt[i] = start + ((i - oldBoard) * 275);
                    }
                    if (state.table.status === 'showdown') {
                        houseOutcomeRevealAt = houseBoardRevealAt[newBoard - 1] + 400;
                        window.setTimeout(fetchState, Math.max(0, houseOutcomeRevealAt - now) + 50);
                    }
                }
            }

            if (state.table.status === 'showdown' && houseOutcomeRevealAt === 0) {
                houseOutcomeRevealAt = now + 450;
                window.setTimeout(fetchState, 500);
            }
        }

        function makeHole(seatNo, cards, isHouse) {
            if (!cards || !cards.length) return null;
            var el = document.createElement('div');
            el.className = 'hole';
            el.style.left = handPositions[seatNo][0] + 'px';
            el.style.top = handPositions[seatNo][1] + 'px';
            cards.forEach(function(card, cardIndex) {
                var img = document.createElement('img');
                img.src = cardUrl(card);
                img.alt = card === 'BACK' ? 'Face-down card' : card;
                if (isHouse) {
                    houseDealCard(img, houseHoleRevealAt[seatNo + ':' + cardIndex] || 0);
                }
                el.appendChild(img);
            });
            return el;
        }

        function buyinValueForUnit(bytes, unit) {
            if (unit === 'MB') {
                return bytes / MB;
            }

            return bytes / GB;
        }

        function trimBuyinNumber(value, decimals) {
            var factor = Math.pow(10, decimals);
            var rounded = Math.round(value * factor) / factor;
            return String(rounded);
        }

        function configureBuyinInput(resetValue) {
            if (!lastState || !lastState.table) return;

            var minBytes = parseInt(lastState.table.min_buyin || 0, 10);
            var maxBytes = parseInt(lastState.table.max_buyin || 0, 10);
            var input = document.getElementById('buyinAmount');
            var unitSelect = document.getElementById('buyinUnit');

            if (!minBytes || !maxBytes) return;

            var allowMB = minBytes < GB;
            var allowGB = maxBytes >= GB;
            var mbOption = unitSelect.querySelector('option[value="MB"]');
            var gbOption = unitSelect.querySelector('option[value="GB"]');

            mbOption.hidden = !allowMB;
            mbOption.disabled = !allowMB;
            gbOption.hidden = !allowGB;
            gbOption.disabled = !allowGB;

            if (resetValue || (unitSelect.value === 'MB' && !allowMB) || (unitSelect.value === 'GB' && !allowGB)) {
                unitSelect.value = allowMB ? 'MB' : 'GB';
            }

            var unit = unitSelect.value;
            var divisor = unit === 'MB' ? MB : GB;

            /*
             * Buy-ins are whole MB/GB values only.  Clamp the displayed range to
             * whole values that still fall inside the table's byte limits.
             */
            var minValue = Math.ceil(minBytes / divisor);
            var maxValue = Math.floor(maxBytes / divisor);

            input.step = '1';
            input.min = String(minValue);
            input.max = String(maxValue);

            if (resetValue) {
                input.value = String(minValue);
            } else {
                var currentValue = parseInt(input.value || 0, 10);
                if (!currentValue || currentValue < minValue || currentValue > maxValue) {
                    input.value = String(minValue);
                }
            }
        }

        function formatRaiseAmount(bytes) {
            bytes = Math.max(0, parseInt(bytes || 0, 10));

            if (bytes >= GB) {
                return trimBuyinNumber(bytes / GB, 4) + ' GB';
            }

            return trimBuyinNumber(bytes / MB, 2) + ' MB';
        }

        function configureRaiseInput(resetValue) {
            if (!lastState || !lastState.me) return;

            var maxBytes = parseInt(lastState.me.max_raise_to || 0, 10);
            var currentBet = parseInt(lastState.table.current_bet || 0, 10);
            var minRaise = Math.max(1, parseInt(lastState.table.min_raise || 0, 10));
            var minRaiseTo = currentBet + minRaise;

            var input = document.getElementById('raiseToAmount');
            var unitSelect = document.getElementById('raiseToUnit');
            var limit = document.getElementById('raiseLimit');
            var raiseButton = document.querySelector('[data-move="raise"]');

            var canFullRaise = maxBytes >= minRaiseTo && maxBytes > currentBet;

            /*
             * Reset stale raise values when the actual betting context changes
             * (new hand/street/bet/limit), but do NOT fight the player every
             * polling refresh if they manually switch MB/GB while deciding.
             */
            var raiseContextKey = [
                lastState.table.hand_no || 0,
                lastState.table.status || '',
                lastState.table.street || '',
                currentBet,
                minRaise,
                maxBytes
            ].join('|');

            var contextChanged = raiseContextKey !== lastRaiseContextKey;
            if (contextChanged) {
                lastRaiseContextKey = raiseContextKey;
            }

            var shouldReset = resetValue || contextChanged;

            if (lastState.table.game_type === 'tournament') {
                unitSelect.style.display = 'none';
                input.step = '1';

                if (canFullRaise) {
                    input.min = String(minRaiseTo);
                    input.max = String(maxBytes);

                    if (shouldReset) {
                        input.value = '';
                    }

                    limit.textContent =
                        'Minimum raise-to: ' + minRaiseTo.toLocaleString() +
                        ' chips · Maximum: ' + maxBytes.toLocaleString() + ' chips';
                } else {
                    input.min = '0';
                    input.max = maxBytes > 0 ? String(maxBytes) : '';
                    if (shouldReset) input.value = '';

                    limit.textContent = maxBytes > currentBet
                        ? 'No full raise available — use All In.'
                        : 'No raise available.';
                }

                if (raiseButton) {
                    raiseButton.disabled = !lastState.me.is_turn || !canFullRaise;
                }
                return;
            }

            unitSelect.style.display = '';

            if (!canFullRaise) {
                input.min = '0';
                input.max = '';
                if (shouldReset) input.value = '';

                limit.textContent = maxBytes > currentBet
                    ? 'No full raise available — use All In.'
                    : 'No raise available.';

                if (raiseButton) {
                    raiseButton.disabled = true;
                }
                return;
            }

            if (shouldReset) {
                unitSelect.value = minRaiseTo < GB ? 'MB' : 'GB';
                unitSelect.dataset.previousUnit = unitSelect.value;
            }

            var unit = unitSelect.value;
            var divisor = unit === 'MB' ? MB : GB;
            var decimals = unit === 'MB' ? 2 : 4;

            input.step = unit === 'MB' ? '1' : '0.1';

            /*
             * Keep the browser's number-spinner sane in GB mode. If we use the
             * exact byte-based minimum here, a small legal raise (for example
             * 4 MB) becomes 0.0039 GB and the spinner starts there. The actual
             * poker minimum is still shown below and enforced by the server.
             */
            input.min = unit === 'MB'
                ? trimBuyinNumber(minRaiseTo / divisor, decimals)
                : '0';
            input.max = trimBuyinNumber(maxBytes / divisor, decimals);

            if (shouldReset) {
                input.value = '';
            }

            limit.textContent =
                'Minimum raise-to: ' + formatRaiseAmount(minRaiseTo) +
                ' · Maximum: ' + formatRaiseAmount(maxBytes);

            if (raiseButton) {
                raiseButton.disabled = !lastState.me.is_turn;
            }
        }

        function chooseSeat(seatNo) {
            if (lastState && lastState.table && lastState.table.game_type === 'house') {
                seatNo = 8;
            }
            selectedSeat = seatNo;
            if (lastState && lastState.table && lastState.table.game_type === 'tournament') {
                if (lastState.table.tournament_status !== 'registration') { alert('Tournament registration is closed.'); return; }
                if (!confirm('Register for ' + lastState.table.tournament_entry_fee_text + ' and receive ' + lastState.table.tournament_starting_stack_text + '?')) return;
                post('join', { seat: selectedSeat });
                return;
            }
            document.getElementById('selectedSeat').textContent = '#' + seatNo;
            configureBuyinInput(true);
            document.getElementById('buyinBox').classList.add('active');
            document.getElementById('buyinAmount').focus();
        }

        function render(state) {
            var previousState = lastState;
            var isHouse = state.table.game_type === 'house';
            prepareHousePresentation(previousState, state);
            soundForStateChange(previousState, state);
            lastState = state;

            if (state.user_notice) {
                openUserNotice(state.user_notice);
            }
            tableEl.querySelectorAll('.seat,.hole,.bet-chip,.dealer-button,.blind-button').forEach(function(el) {
                el.remove();
            });
            document.getElementById('community').innerHTML = '';

            document.getElementById('historyOpen').hidden = isHouse;
            var seatLimit = Math.max(2, Math.min(10, parseInt(state.table.max_seats || 10, 10)));
            var seatsToRender = isHouse ? [3, 8] : [];
            if (!isHouse) {
                for (var fillSeat = 1; fillSeat <= seatLimit; fillSeat++) seatsToRender.push(fillSeat);
            }

            seatsToRender.forEach(function(i) {
                var s = state.seats[i];
                tableEl.appendChild(makeSeat(i, s));
                if (s) {
                    var displayedCards = s.cards;
                    if (
                        isHouse &&
                        i === 3 &&
                        state.table.status === 'showdown' &&
                        houseOutcomeRevealAt > Date.now() &&
                        displayedCards && displayedCards.length
                    ) {
                        displayedCards = ['BACK', 'BACK'];
                    }
                    var hole = makeHole(i, displayedCards, isHouse);
                    if (hole) tableEl.appendChild(hole);
                    if (s.round_bet > 0) {
                        var bet = document.createElement('div');
                        bet.className = 'bet-chip';
                        bet.textContent = s.round_bet_text;
                        bet.style.left = betPositions[i][0] + 'px';
                        bet.style.top = betPositions[i][1] + 'px';
                        tableEl.appendChild(bet);
                    }
                }
            });

            if (state.table.dealer_seat) {
                var d = document.createElement('div');
                d.className = 'dealer-button';
                d.textContent = 'D';
                d.style.left = dealerPositions[state.table.dealer_seat][0] + 'px';
                d.style.top = dealerPositions[state.table.dealer_seat][1] + 'px';
                tableEl.appendChild(d);
            }

            if (state.table.status === 'playing' && state.table.dealer_seat) {
                var blindSeats = blindSeatsForState(state);
                addBlindButton('SB', blindSeats.sb);
                addBlindButton('BB', blindSeats.bb);
            }

            state.table.community.forEach(function(card, cardIndex) {
                var img = document.createElement('img');
                img.src = cardUrl(card);
                img.alt = card;
                if (isHouse) {
                    houseDealCard(img, houseBoardRevealAt[cardIndex] || 0);
                }
                document.getElementById('community').appendChild(img);
            });
            document.getElementById('uploadCredit').textContent = state.me.total_credit_text;
            document.getElementById('tableName').textContent = state.table.name;
            document.getElementById('blinds').textContent = state.table.small_blind_text + ' / ' + state.table.big_blind_text + (state.table.game_type === 'tournament' ? ' · TOURNAMENT' : (isHouse ? ' · HOUSE' : ''));
            document.getElementById('handNo').textContent = state.table.hand_no ? '#' + state.table.hand_no : '-';

            var isTournament = state.table.game_type === 'tournament';
            var tournamentInfo = document.getElementById('tournamentTableInfo');
            tournamentInfo.hidden = !isTournament;

            if (isTournament) {
                var tournamentStatus = String(state.table.tournament_status || 'registration');
                document.getElementById('tournamentStatus').textContent =
                    tournamentStatus === 'registration' ? 'Registration Open' :
                    (tournamentStatus === 'running' ? 'Running' : 'Finished');
                document.getElementById('tournamentRegistered').textContent =
                    String(state.table.tournament_entries || 0) + ' / ' + String(state.table.max_seats || 10);
                document.getElementById('tournamentPrize').textContent = state.table.tournament_prize_pool_text;
            }

            var seatedPlayers = 0;

            Object.keys(state.seats || {}).forEach(function(seatNo) {
                var seat = state.seats[seatNo];

                if (seat && seat.user_id) {
                    seatedPlayers++;
                }
            });

            var tableMessage = state.table.message || '';

            if (
                isHouse &&
                state.table.status === 'showdown' &&
                houseOutcomeRevealAt > Date.now()
            ) {
                tableMessage = state.table.community && state.table.community.length
                    ? 'Dealing the board...'
                    : 'The Collector is settling the hand...';
            }

            var playersNeeded = isHouse ? 1 : 2;
            if (seatedPlayers >= playersNeeded && (tableMessage === 'Waiting for players.' || tableMessage === 'Waiting for a player to challenge The Collector.')) {
                tableMessage = isHouse ? 'Heads-Up vs The Collector — ready to deal.' : '';
            }

            if (!tableMessage && seatedPlayers < playersNeeded) {
                tableMessage = isHouse ? 'Waiting for a player to challenge The Collector.' : 'Waiting for players.';
            }

            document.getElementById('potAmount').textContent = state.table.pot_text || '0';

            var winnerBanner = document.getElementById('winnerBanner');
            var tableMessageEl = document.getElementById('tableMessage');
            var isWinnerMessage = /\bwin(?:s)?\b|split pot|tie with/i.test(tableMessage);

            winnerBanner.textContent = isWinnerMessage ? tableMessage : '';
            winnerBanner.classList.toggle('show', isWinnerMessage);

            /*
             * Normal hand/status text lives in the right-side Table Status panel.
             * Winner text may also briefly appear over the felt as the dedicated
             * winner presentation.
             */
            tableMessageEl.textContent = tableMessage || 'Table ready.';

            var tableStatusPanel = document.getElementById('tableStatusPanel');
            var isWaitingForPlayers = !isHouse && seatedPlayers < playersNeeded && tableMessage === 'Waiting for players.';
            tableStatusPanel.classList.toggle('waiting', isWaitingForPlayers);
            document.getElementById('buyinLimits').textContent = state.table.game_type === 'tournament'
                ? ('Tournament: ' + state.table.tournament_entry_fee_text + ' entry · ' + state.table.tournament_starting_stack_text + ' starting stack · Prize ' + state.table.tournament_prize_pool_text)
                : (isHouse ? ('The Collector · Buy-in: ' + state.table.min_buyin_text + ' to ' + state.table.max_buyin_text) : ('Buy-in: ' + state.table.min_buyin_text + ' to ' + state.table.max_buyin_text));

            var me = state.me.seat ? state.seats[state.me.seat] : null;

            if (isHouse && me && state.table.status !== 'playing') {
                var housePlayerStack = parseInt(me.stack || 0, 10);
                var houseBigBlind = parseInt(state.table.big_blind || 0, 10);

                if (houseBigBlind > 0 && housePlayerStack < houseBigBlind) {
                    if (!houseBrokeShown) {
                        houseBrokeShown = true;
                        openHouseBrokePopup();
                    }
                } else {
                    houseBrokeShown = false;
                }
            } else if (!isHouse || !me) {
                houseBrokeShown = false;
            }

            document.getElementById('mySeat').textContent = state.me.seat ? '#' + state.me.seat : 'Not seated';
            document.getElementById('myStack').textContent = me ? me.stack_text : '-';

            var viewMode = document.getElementById('viewMode');
            var waitingForNextHand = !!(
                me &&
                me.state === 'waiting' &&
                !state.me.sitting_out &&
                state.table.status === 'playing'
            );

            if (waitingForNextHand) {
                viewMode.textContent = 'Waiting Next Hand';
                viewMode.className = 'player-mode';
            } else {
                viewMode.textContent = isHouse ? (state.me.seat ? 'Playing The Collector' : 'The Collector') : (state.me.seat ? 'Playing' : 'Spectating');
                viewMode.className = state.me.seat ? 'player-mode' : 'spectator-mode';
            }

            document.getElementById('spectatorSummary').style.display = isHouse ? 'none' : '';
            document.getElementById('chatPanel').style.display = isHouse ? 'none' : '';

            var spectators = state.spectators || [];
            var spectatorCount = state.spectator_count || spectators.length || 0;
            document.getElementById('spectatorCount').textContent = String(spectatorCount);
            document.getElementById('spectatorPopoutCount').textContent = spectatorCount === 1
                ? '1 watching'
                : String(spectatorCount) + ' watching';

            var spectatorList = document.getElementById('spectatorList');
            spectatorList.textContent = '';

            if (!spectators.length) {
                var spectatorEmpty = document.createElement('div');
                spectatorEmpty.className = 'poker-spectator-empty';
                spectatorEmpty.textContent = 'Nobody watching.';
                spectatorList.appendChild(spectatorEmpty);
            } else {
                spectators.forEach(function(spectator) {
                    var spectatorRow = document.createElement('div');
                    spectatorRow.className = 'poker-spectator-person';
                    spectatorRow.textContent = spectator.username;
                    spectatorList.appendChild(spectatorRow);
                });
            }

            document.getElementById('leaveTable').disabled = !state.me.seat || state.table.status === 'playing';

            var sitOutToggle = document.getElementById('sitOutToggle');
            sitOutToggle.disabled = !state.me.seat || isHouse;
            sitOutToggle.textContent = isHouse ? 'Sit Out N/A' : (state.me.sitting_out ? 'Sit Back In' : 'Sit Out');

            var adminStartTournament = document.getElementById('adminStartTournament');
            var canAdminStartTournament = !!(
                state.me.is_admin &&
                isTournament &&
                state.table.tournament_status === 'registration'
            );
            adminStartTournament.hidden = !canAdminStartTournament;
            adminStartTournament.disabled = !canAdminStartTournament ||
                parseInt(state.table.tournament_entries || 0, 10) < 2 ||
                (state.maintenance && state.maintenance.draining);
            if (canAdminStartTournament) {
                adminStartTournament.textContent = 'Start Tournament (' +
                    String(state.table.tournament_entries || 0) + '/' +
                    String(state.table.max_seats || 10) + ')';
            }

            document.getElementById('startHand').disabled =
                !state.me.seat ||
                state.me.sitting_out ||
                state.table.status === 'playing' ||
                (isTournament && state.table.tournament_status !== 'running') ||
                (state.maintenance && state.maintenance.draining);

            var currentTurnSeat = state.table.current_turn ? state.seats[state.table.current_turn] : null;
            var reconnectGraceActive = !!(currentTurnSeat && currentTurnSeat.reconnecting);
            var houseTurn = !!(isHouse && currentTurnSeat && parseInt(currentTurnSeat.user_id || 0, 10) === 0 && state.table.status === 'playing');

            if (houseThinkTimer) {
                window.clearTimeout(houseThinkTimer);
                houseThinkTimer = null;
            }
            if (houseTurn && !busy) {
                houseThinkTimer = window.setTimeout(function() {
                    if (!busy && lastState && lastState.table.game_type === 'house') {
                        post('house_tick', {});
                    }
                }, 1200 + Math.floor(Math.random() * 900));
            }

            var moveButtons = document.querySelectorAll('#modern-poker .move');
            moveButtons.forEach(function(b) {
                b.disabled = !state.me.is_turn || reconnectGraceActive;
            });
            var checkBtn = document.querySelector('[data-move="check"]');
            var callBtn = document.getElementById('callButton');

            configureRaiseInput(false);

            if (houseTurn) {
                checkBtn.disabled = true;
                callBtn.disabled = true;
                callBtn.textContent = 'Call';
                document.getElementById('turnNotice').textContent = 'The Collector is thinking...';
            } else if (state.me.is_turn && !reconnectGraceActive) {
                checkBtn.disabled = !state.me.can_check;
                callBtn.disabled = state.me.can_check;
                callBtn.textContent = state.me.can_check ? 'Call' : 'Call ' + state.me.call_text;
                document.getElementById('turnNotice').textContent = state.me.can_check ? 'Your turn — you may check or bet.' : 'Your turn — ' + state.me.call_text + ' to call.';
            } else if (reconnectGraceActive && currentTurnSeat) {
                checkBtn.disabled = true;
                callBtn.disabled = true;
                callBtn.textContent = 'Call';
                document.getElementById('turnNotice').textContent = 'Waiting for ' + currentTurnSeat.username + ' to reconnect...';
            } else {
                if (!state.me.seat) {
                    document.getElementById('turnNotice').textContent = state.table.status === 'playing'
                        ? 'Spectating — watch the hand or take an open seat.'
                        : 'Spectating — take an open seat when you are ready to play.';
                } else {
                    if (waitingForNextHand) {
                        document.getElementById('turnNotice').textContent = 'Seat reserved — you will be dealt in when the next hand starts.';
                    } else {
                        document.getElementById('turnNotice').textContent = isHouse
                            ? (state.table.status === 'playing' ? 'The Collector is thinking...' : 'Ready for the next hand against The Collector.')
                            : (state.table.status === 'playing' ? 'Waiting for another player...' : 'No hand in progress.');
                    }
                }
                callBtn.textContent = 'Call';
            }

            if (state.maintenance && state.maintenance.draining && state.me.seat && state.table.status !== 'playing') {
                document.getElementById('turnNotice').textContent = 'Maintenance pending — leave the table to return your stack to upload credit.';
            }

            if (state.maintenance && state.maintenance.draining) {
                selectedSeat = 0;
            }
            document.getElementById('buyinBox').classList.toggle(
                'active',
                selectedSeat > 0 && !state.me.seat && !(state.maintenance && state.maintenance.draining)
            );

            renderChat(state.chat || []);

            var chatInput = document.getElementById('chatMessage');
            var chatSend = document.getElementById('chatSend');
            if (isHouse) {
                chatInput.disabled = true;
                chatSend.disabled = true;
                chatInput.value = '';
                chatInput.placeholder = 'Private heads-up game — table chat is not used.';
            } else {
                chatInput.disabled = !state.me.seat;
                chatSend.disabled = !state.me.seat || chatBusy;
                chatInput.placeholder = state.me.seat ? 'Message the table...' : 'Take a seat to talk.';
            }

            syncTurnCountdown(state);
            setNotice('Table updated.', false);
        }

        document.getElementById('historyOpen').addEventListener('click', function() {
            openHistory();
        });

        document.getElementById('historyOverlay').addEventListener('click', function(event) {
            if (event.target === this) {
                closeHistory();
            }
        });

        document.getElementById('spectatorOpen').addEventListener('click', function() {
            openSpectators();
        });

        document.getElementById('spectatorOverlay').addEventListener('click', function(event) {
            if (event.target === this) {
                closeSpectators();
            }
        });

        document.getElementById('userNoticeClose').addEventListener('click', function() {
            closeUserNotice();
        });

        document.getElementById('userNoticeOverlay').addEventListener('click', function(event) {
            if (event.target === this) {
                closeUserNotice();
            }
        });

        document.getElementById('houseBrokeClose').addEventListener('click', function() {
            closeHouseBrokePopup();
        });

        document.getElementById('houseBrokeOverlay').addEventListener('click', function(event) {
            if (event.target === this) {
                closeHouseBrokePopup();
            }
        });

        document.addEventListener('keydown', function(event) {
            if (event.key === 'Escape') {
                closeHistory();
                closeSpectators();
                closeHouseBrokePopup();
                closeUserNotice();
            }
        });

        document.getElementById('chatSend').addEventListener('click', function() {
            sendChat();
        });

        document.getElementById('chatMessage').addEventListener('keydown', function(event) {
            if (event.key === 'Enter' && !event.shiftKey) {
                event.preventDefault();
                sendChat();
            }
        });

        document.getElementById('chatMessage').addEventListener('input', function() {
            document.getElementById('chatCount').textContent = String(this.value.length);
        });

        window.addEventListener('pagehide', function() {
            if (!lastState || lastState.me.seat || !navigator.sendBeacon) {
                return;
            }

            var body = new FormData();
            body.append('action', 'spectator_leave');
            body.append('table_id', String(tableId));
            body.append('csrf', csrf);
            navigator.sendBeacon('poker-api.php', body);
        });

        document.getElementById('buyinUnit').addEventListener('change', function() {
            configureBuyinInput(false);
        });

        document.getElementById('confirmSeat').addEventListener('click', function() {
            if (!selectedSeat) return;

            post('join', {
                seat: selectedSeat,
                buyin_amount: document.getElementById('buyinAmount').value,
                buyin_unit: document.getElementById('buyinUnit').value
            }).then(function() {
                selectedSeat = 0;
                document.getElementById('buyinBox').classList.remove('active');
            });
        });
        document.getElementById('cancelSeat').addEventListener('click', function() {
            selectedSeat = 0;
            document.getElementById('buyinBox').classList.remove('active');
        });
        document.getElementById('sitOutToggle').addEventListener('click', function() {
            if (!lastState || !lastState.me.seat) return;

            if (lastState.me.sitting_out) {
                post('sit_in', {});
            } else {
                post('sit_out', {});
            }
        });

        document.getElementById('leaveTable').addEventListener('click', function() {
            if (confirm('Leave the poker table and return your remaining stack to upload credit?')) post('leave', {});
        });
        document.getElementById('adminStartTournament').addEventListener('click', function() {
            if (!lastState || !lastState.me.is_admin || lastState.table.game_type !== 'tournament') return;

            var registered = parseInt(lastState.table.tournament_entries || 0, 10);
            if (registered < 2) return;

            if (confirm('Start this tournament with ' + registered + ' registered players? Registration will close immediately.')) {
                post('start_tournament', {});
            }
        });

        document.getElementById('startHand').addEventListener('click', function() {
            post('start', {});
        });
        var soundToggle = document.getElementById('soundToggle');
        soundToggle.textContent = soundEnabled ? 'Game Sounds: On' : 'Game Sounds: Muted';
        soundToggle.setAttribute('aria-pressed', soundEnabled ? 'true' : 'false');

        soundToggle.addEventListener('click', function() {
            soundEnabled = !soundEnabled;
            localStorage.setItem('pokerSoundEnabled', soundEnabled ? '1' : '0');
            this.textContent = soundEnabled ? 'Game Sounds: On' : 'Game Sounds: Muted';
            this.setAttribute('aria-pressed', soundEnabled ? 'true' : 'false');

            if (soundEnabled) {
                playSound('yourTurn');
            }
        });

        var ambienceToggle = document.getElementById('ambienceToggle');
        updateAmbienceButton();

        ambienceToggle.addEventListener('click', function() {
            ambienceEnabled = !ambienceEnabled;
            localStorage.setItem(ambienceStorageKey, ambienceEnabled ? '1' : '0');
            updateAmbienceButton();

            if (ambienceEnabled) {
                startAmbience();
            } else {
                stopAmbience();
            }
        });

        /*
         * Browsers may block audible autoplay. If ambience is enabled for this
         * user, try immediately and then again on the user's first interaction.
         * A saved muted preference never starts the track.
         */
        if (ambienceEnabled) {
            startAmbience();
            document.addEventListener('pointerdown', startAmbienceFromInteraction, { once: true });
            document.addEventListener('keydown', startAmbienceFromInteraction, { once: true });
        }
        var raiseUnitSelect = document.getElementById('raiseToUnit');
        raiseUnitSelect.dataset.previousUnit = raiseUnitSelect.value;

        raiseUnitSelect.addEventListener('change', function() {
            var input = document.getElementById('raiseToAmount');
            var oldUnit = this.dataset.previousUnit || 'MB';
            var newUnit = this.value;

            /*
             * Changing the raise unit is a fresh entry choice. Do not convert a
             * previous MB value into a tiny GB decimal such as 0.0039. Clear the
             * amount and let the player type the raise-to value in the new unit.
             */
            if (oldUnit !== newUnit) {
                input.value = '';
            }

            this.dataset.previousUnit = newUnit;
            configureRaiseInput(false);
        });

        document.querySelectorAll('#modern-poker .move').forEach(function(btn) {
            btn.addEventListener('click', function() {
                var move = this.dataset.move;
                var data = {
                    move: move
                };
                if (move === 'raise') {
                    var raiseInput = document.getElementById('raiseToAmount');
                    var raiseUnit = document.getElementById('raiseToUnit');
                    var raiseValue = parseFloat(raiseInput.value);

                    if (!isFinite(raiseValue) || raiseValue <= 0) {
                        setNotice('Enter a valid raise-to amount.', true);
                        raiseInput.focus();
                        return;
                    }

                    data.raise_to_amount = raiseInput.value;
                    data.raise_to_unit = raiseUnit.value;
                }
                post('move', data);
            });
        });

        fetchState();
        window.setInterval(function() {
            if (!busy) fetchState();
        }, 1500);

        window.setInterval(function() {
            updateTurnCountdown();
        }, 250);
    }());
</script>
<?php
if (function_exists('end_frame')) {
    end_frame();
}
if (function_exists('stdfoot')) {
    stdfoot();
}
?>
