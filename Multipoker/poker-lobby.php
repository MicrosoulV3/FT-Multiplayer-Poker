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

$userId = (int) $CURUSER['id'];

/*
 * A seated player belongs at their active game, not in the lobby shopping
 * for a second table. Server-side join checks enforce the same rule even if
 * somebody bypasses this redirect.
 */
$activeGame = poker_active_game_for_user($db, $userId);
if ($activeGame) {
    header('Location: poker.php?table_id=' . (int) $activeGame['table_id']);
    exit;
}

$state = poker_lobby_data($db, $userId);

$title = 'Poker Lobby';

if (function_exists('stdhead')) {
    stdhead($title);
}

if (function_exists('begin_frame')) {
    begin_frame($title);
}
?>
<style>
    /* Same smoky poker-room ambiance used by the main poker table.
       Image path: images/poker/poker-room-background.webp */
    body {
        background:
            linear-gradient(rgba(4, 8, 11, .68), rgba(4, 8, 11, .84)),
            url("images/poker/poker-room-background.webp") center top / cover fixed no-repeat !important;
    }

    #poker-lobby {
        width: 1050px;
        margin: 16px auto;
        color: #ddd;
        font-family: Arial, Helvetica, sans-serif;
    }

    #poker-lobby * {
        box-sizing: border-box;
    }

    #poker-lobby .lobby-head {
        display: flex;
        justify-content: space-between;
        align-items: center;
        gap: 24px;
        margin-bottom: 16px;
        padding: 18px 20px;
        background: linear-gradient(180deg, rgba(29,29,29,.98), rgba(15,15,15,.98));
        border: 1px solid #3b3b3b;
        border-top-color: rgba(226,164,61,.62);
        border-radius: 10px;
        box-shadow: 0 14px 30px rgba(0,0,0,.28), inset 0 1px 0 rgba(255,255,255,.03);
    }

    #poker-lobby .lobby-title {
        margin: 0;
        color: #fff;
        font-size: 24px;
    }

    #poker-lobby .lobby-subtitle {
        margin-top: 4px;
        color: #8a8a8a;
        font-size: 12px;
    }

    #poker-lobby .credit {
        text-align: right;
        color: #aaa;
        font-size: 12px;
    }

    #poker-lobby .credit strong {
        display: block;
        margin-top: 3px;
        color: #7ed957;
        font-size: 18px;
    }

    #poker-lobby .lobby-audio-controls {
        display: flex;
        justify-content: flex-end;
        margin-top: 8px;
    }

    #poker-lobby .lobby-audio-toggle {
        padding: 6px 10px;
        border: 1px solid #555;
        border-radius: 5px;
        background: #242424;
        color: #ddd;
        font: inherit;
        font-size: 11px;
        font-weight: 700;
        cursor: pointer;
    }

    #poker-lobby .lobby-audio-toggle:hover {
        background: #333;
        color: #fff;
    }

    #poker-lobby .lobby-section {
        margin-top: 18px;
        border: 1px solid #343434;
        border-radius: 10px;
        background: linear-gradient(180deg, rgba(21,21,21,.97), rgba(11,11,11,.97));
        box-shadow: 0 12px 28px rgba(0,0,0,.24), inset 0 1px 0 rgba(255,255,255,.025);
        overflow: hidden;
    }

    #poker-lobby .lobby-section-head {
        display: flex;
        align-items: center;
        justify-content: space-between;
        gap: 18px;
        min-height: 64px;
        padding: 13px 16px;
        border-bottom: 1px solid #303030;
        background: rgba(255,255,255,.015);
    }

    #poker-lobby .lobby-section-heading {
        display: flex;
        align-items: center;
        gap: 11px;
    }

    #poker-lobby .section-icon {
        display: inline-flex;
        align-items: center;
        justify-content: center;
        width: 36px;
        height: 36px;
        border: 1px solid #565656;
        border-radius: 50%;
        background: #181818;
        color: #ddd;
        font-size: 16px;
        font-weight: 900;
    }

    #poker-lobby .lobby-section-title {
        margin: 0;
        color: #eee;
        font-size: 16px;
        font-weight: 900;
        letter-spacing: .45px;
        text-transform: uppercase;
    }

    #poker-lobby .lobby-section-note {
        margin-top: 3px;
        color: #777;
        font-size: 11px;
    }

    #poker-lobby .section-count {
        flex: 0 0 auto;
        min-width: 78px;
        padding: 7px 10px;
        border: 1px solid #3e3e3e;
        border-radius: 14px;
        background: #101010;
        color: #aaa;
        text-align: center;
        font-size: 10px;
        font-weight: 800;
        letter-spacing: .45px;
        text-transform: uppercase;
    }

    #poker-lobby .cash-section { border-top-color: rgba(86, 167, 104, .68); }
    #poker-lobby .cash-section .section-icon { border-color: #376747; color: #9de0ad; }
    #poker-lobby .cash-section .section-count { border-color: #2f573b; color: #9ed8aa; }

    #poker-lobby .tournament-section { border-top-color: rgba(205, 153, 67, .78); }
    #poker-lobby .tournament-section .section-icon { border-color: #71562a; color: #e9c472; }
    #poker-lobby .tournament-section .section-count { border-color: #5d4825; color: #ddbd79; }

    #poker-lobby .house-section { border-top-color: rgba(156, 103, 174, .72); }
    #poker-lobby .house-section .section-icon { border-color: #5f4169; color: #d3a4e1; }
    #poker-lobby .house-section .section-count { border-color: #4b3554; color: #caa1d5; }

    #poker-lobby .table-list {
        overflow: hidden;
        background: #111;
    }

    #poker-lobby table {
        width: 100%;
        border-collapse: collapse;
    }

    #poker-lobby th {
        padding: 10px 12px;
        background: #191919;
        border-bottom: 1px solid #333;
        color: #8f8f8f;
        font-size: 10px;
        text-transform: uppercase;
        letter-spacing: .5px;
        text-align: left;
    }

    #poker-lobby td {
        padding: 14px 12px;
        border-bottom: 1px solid #262626;
        vertical-align: middle;
        font-size: 13px;
    }

    #poker-lobby tbody tr:last-child td {
        border-bottom: 0;
    }

    #poker-lobby tbody tr {
        transition: background .15s ease;
    }

    #poker-lobby tbody tr:hover {
        background: #171717;
    }

    #poker-lobby tbody tr.occupied-table td {
        background: rgba(45, 103, 60, .13);
        border-bottom-color: #2d4633;
    }

    #poker-lobby tbody tr.occupied-table td:first-child {
        box-shadow: inset 3px 0 0 #58a86b;
    }

    #poker-lobby tbody tr.occupied-table:hover td {
        background: rgba(54, 124, 72, .2);
    }

    #poker-lobby .occupied-count {
        color: #9ee0ad;
        font-weight: 800;
        text-shadow: 0 0 10px rgba(88, 168, 107, .28);
    }

    #poker-lobby .vault-prize {
        color: #f1c56b;
        font-weight: 800;
        white-space: nowrap;
    }

    #poker-lobby .table-meta {
        display: block;
        margin-top: 3px;
        color: #666;
        font-size: 10px;
    }

    #poker-lobby .table-name {
        color: #fff;
        font-size: 15px;
        font-weight: 700;
    }

    #poker-lobby .my-table {
        display: inline-block;
        margin-left: 7px;
        padding: 2px 6px;
        border-radius: 10px;
        background: #263b29;
        color: #9ce8a8;
        font-size: 9px;
        font-weight: 700;
        text-transform: uppercase;
    }

    #poker-lobby .status {
        display: inline-block;
        min-width: 82px;
        padding: 4px 8px;
        border-radius: 12px;
        background: #252525;
        color: #bbb;
        text-align: center;
        font-size: 10px;
        font-weight: 700;
        text-transform: uppercase;
    }

    #poker-lobby .status.playing {
        background: #173220;
        color: #87d99c;
    }

    #poker-lobby .status.showdown {
        background: #332b17;
        color: #dfca82;
    }

    #poker-lobby .join {
        display: inline-block;
        min-width: 92px;
        padding: 7px 10px;
        border: 1px solid #495a36;
        border-radius: 4px;
        background: #26321d;
        color: #bfe39d;
        text-align: center;
        text-decoration: none;
        font-weight: 700;
    }

    #poker-lobby .join:hover {
        background: #324226;
    }

    #poker-lobby .empty {
        padding: 35px;
        color: #777;
        text-align: center;
    }

    #poker-lobby .lobby-foot {
        margin-top: 10px;
        color: #666;
        font-size: 11px;
        text-align: center;
    }

    #poker-lobby .admin-link {
        display: inline-block;
        margin-top: 8px;
        padding: 6px 10px;
        border: 1px solid #555;
        border-radius: 4px;
        background: #242424;
        color: #ddd;
        text-decoration: none;
        font-size: 11px;
        font-weight: 700;
    }

    #poker-lobby .admin-link:hover {
        background: #333;
        color: #fff;
    }

    #poker-lobby .maintenance-drain-notice {
        margin: 0 0 12px;
        padding: 11px 14px;
        border: 1px solid #725d2a;
        border-radius: 5px;
        background: #2b2518;
        color: #e6d18f;
        font-size: 12px;
        font-weight: 700;
        text-align: center;
    }


    #poker-lobby .starter-stake {
        display: none;
        margin: 0 0 12px;
        padding: 14px 16px;
        border: 1px solid #725d2a;
        border-radius: 6px;
        background: linear-gradient(180deg, #2d2719, #211d14);
        color: #ddd;
        text-align: center;
    }

    #poker-lobby .starter-stake.active {
        display: block;
    }

    #poker-lobby .starter-stake strong {
        color: #ffd98a;
    }

    #poker-lobby .starter-stake-copy {
        margin-bottom: 10px;
        line-height: 1.5;
    }

    #poker-lobby .starter-stake button {
        padding: 8px 14px;
        border: 1px solid #b77a20;
        border-radius: 4px;
        background: linear-gradient(180deg, #65451a, #3b280f);
        color: #ffd98a;
        font-weight: 700;
        cursor: pointer;
    }

    #poker-lobby .starter-stake button:hover:not(:disabled) {
        border-color: #e2a43d;
        background: linear-gradient(180deg, #805921, #4c3311);
        color: #fff0bd;
    }

    #poker-lobby .starter-stake button:disabled {
        opacity: .55;
        cursor: default;
    }

    #poker-lobby .starter-message {
        display: none;
        margin-top: 9px;
        color: #bfe39d;
        font-size: 12px;
        font-weight: 700;
    }
</style>

<div id="poker-lobby">
    <div class="lobby-head">
        <div>
            <h2 class="lobby-title">FastTracker Poker Lobby</h2>
            <div class="lobby-subtitle">Choose a table, watch a game, or take an open seat.</div>
        </div>
        <div class="credit">
            Your Total Upload Credit
            <strong id="lobbyCredit"><?php echo htmlspecialchars($state['me']['total_credit_text'], ENT_QUOTES, 'UTF-8'); ?></strong>
            <div class="lobby-audio-controls">
                <button type="button"
                        id="lobbyAmbienceToggle"
                        class="lobby-audio-toggle"
                        aria-pressed="true">Room Ambience: On</button>
            </div>
        </div>
    </div>

    <div class="maintenance-drain-notice" id="maintenanceDrainNotice" style="display:none;">
        Poker maintenance is pending. Active hands may finish, but no new players or new hands can begin.
    </div>

    <div class="starter-stake" id="starterStakeBox">
        <div class="starter-stake-copy">
            <strong>New to Poker?</strong> The Collector will spot you a one-time <strong id="starterStakeAmount">1 GB</strong> starter stake.
            Win with it and the winnings are yours. Lose it and there is no refill.
        </div>
        <button type="button" id="starterStakeClaim">Claim Starter Stake</button>
        <div class="starter-message" id="starterStakeMessage"></div>
    </div>

    <div class="lobby-section cash-section">
        <div class="lobby-section-head">
            <div class="lobby-section-heading">
                <span class="section-icon">$</span>
                <div>
                    <h3 class="lobby-section-title">Multiplayer Tables</h3>
                    <div class="lobby-section-note">Open tables for live player-versus-player poker.</div>
                </div>
            </div>
            <div class="section-count" id="cashCount">0 Tables</div>
        </div>
        <div class="table-list">
            <table>
                <thead>
                    <tr>
                        <th>Table</th>
                        <th>Players</th>
                        <th>Watching</th>
                        <th>Blinds</th>
                        <th>Buy-In</th>
                        <th>Status</th>
                        <th>Hand</th>
                        <th></th>
                    </tr>
                </thead>
                <tbody id="cashRows"></tbody>
            </table>
        </div>
    </div>

    <div class="lobby-section tournament-section">
        <div class="lobby-section-head">
            <div class="lobby-section-heading">
                <span class="section-icon">T</span>
                <div>
                    <h3 class="lobby-section-title">Tournament Tables</h3>
                    <div class="lobby-section-note">Compete for the prize pool and a random Champion's Vault reward.</div>
                </div>
            </div>
            <div class="section-count" id="tournamentCount">0 Events</div>
        </div>
        <div class="table-list">
            <table>
                <thead>
                    <tr>
                        <th>Tournament</th>
                        <th>Registered</th>
                        <th>Watching</th>
                        <th>Entry</th>
                        <th>Prize Pool</th>
                        <th>Champion's Vault</th>
                        <th>Blinds</th>
                        <th>Status</th>
                        <th></th>
                    </tr>
                </thead>
                <tbody id="tournamentRows"></tbody>
            </table>
        </div>
    </div>

    <div class="lobby-section house-section">
        <div class="lobby-section-head">
            <div class="lobby-section-heading">
                <span class="section-icon">H</span>
                <div>
                    <h3 class="lobby-section-title">Single-Player House Tables</h3>
                    <div class="lobby-section-note">Private one-on-one games against The Collector.</div>
                </div>
            </div>
            <div class="section-count" id="houseCount">0 Games</div>
        </div>
        <div class="table-list">
            <table>
                <thead>
                    <tr>
                        <th>Table</th>
                        <th>Players</th>
                        <th>Blinds</th>
                        <th>Buy-In</th>
                        <th>Status</th>
                        <th></th>
                    </tr>
                </thead>
                <tbody id="houseRows"></tbody>
            </table>
        </div>
    </div>

    <div class="lobby-foot">Lobby updates automatically every 5 seconds.<br>
        <a class="admin-link" href="poker-leaderboard.php">Poker Leaderboard</a>
        <a class="admin-link" href="poker-profile.php">My Poker Profile</a>
        <?php if (!empty($CURUSER['class']) && (int) $CURUSER['class'] >= 7) { ?>
            <a class="admin-link" href="poker-admin.php">Poker Admin Manager</a>
        <?php } ?>
    </div>
</div>

<script>
(function () {
    'use strict';

    var initialState = <?php echo json_encode($state, JSON_UNESCAPED_SLASHES); ?>;
    var pokerCsrf = <?php echo json_encode((string)$_SESSION['poker_csrf']); ?>;

    // Disable the browser context menu throughout the poker lobby as well.
    document.addEventListener('contextmenu', function (event) {
        event.preventDefault();
    });

    /*
     * Use the exact same per-user ambience preference as the poker table.
     * Turning ambience off here also keeps it off when entering a table,
     * and vice versa.
     */
    var pokerUserId = <?php echo json_encode((string)$userId); ?>;
    var ambienceStorageKey = 'pokerAmbienceEnabled_' + pokerUserId;
    var ambienceStored = localStorage.getItem(ambienceStorageKey);
    var ambienceEnabled = ambienceStored === null ? true : ambienceStored === '1';
    var ambienceStarted = false;
    var ambienceAudio = new Audio('sounds/poker/poker-room-ambience.mp3');
    ambienceAudio.loop = true;
    ambienceAudio.preload = 'none';
    ambienceAudio.volume = 1.00;

    function updateAmbienceButton() {
        var button = document.getElementById('lobbyAmbienceToggle');
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
                promise.then(function () {
                    ambienceStarted = true;
                }).catch(function () {
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

    var ambienceToggle = document.getElementById('lobbyAmbienceToggle');
    updateAmbienceButton();

    if (ambienceToggle) {
        ambienceToggle.addEventListener('click', function () {
            ambienceEnabled = !ambienceEnabled;
            localStorage.setItem(ambienceStorageKey, ambienceEnabled ? '1' : '0');
            updateAmbienceButton();

            if (ambienceEnabled) {
                startAmbience();
            } else {
                stopAmbience();
            }
        });
    }

    if (ambienceEnabled) {
        startAmbience();
        document.addEventListener('pointerdown', startAmbienceFromInteraction, { once: true });
        document.addEventListener('keydown', startAmbienceFromInteraction, { once: true });
    }

    function statusClass(status) {
        if (status === 'playing') return 'status playing';
        if (status === 'showdown') return 'status showdown';
        return 'status';
    }

    function renderStarterStake(state) {
        var box = document.getElementById('starterStakeBox');
        var button = document.getElementById('starterStakeClaim');
        var amount = document.getElementById('starterStakeAmount');
        var message = document.getElementById('starterStakeMessage');
        var starter = state.me && state.me.starter_stake ? state.me.starter_stake : null;

        if (!box || !button || !amount || !message || !starter) return;

        amount.textContent = starter.amount_text || '1 GB';
        box.classList.toggle('active', !!starter.eligible);

        if (!starter.eligible) {
            button.disabled = false;
        }
    }

    function appendEmptyRow(body, colspan, message) {
        var emptyRow = document.createElement('tr');
        var emptyCell = document.createElement('td');
        emptyCell.colSpan = colspan;
        emptyCell.className = 'empty';
        emptyCell.textContent = message;
        emptyRow.appendChild(emptyCell);
        body.appendChild(emptyRow);
    }

    function renderCashTable(body, table, draining) {
        var row = document.createElement('tr');
        if (parseInt(table.player_count || 0, 10) > 0) row.classList.add('occupied-table');

        var nameCell = document.createElement('td');
        var name = document.createElement('span');
        name.className = 'table-name';
        name.textContent = table.name;
        nameCell.appendChild(name);

        if (table.my_seat) {
            var mine = document.createElement('span');
            mine.className = 'my-table';
            mine.textContent = 'Your seat #' + table.my_seat;
            nameCell.appendChild(mine);
        }

        var players = document.createElement('td');
        players.textContent = table.player_count + ' / ' + table.max_seats;
        if (parseInt(table.player_count || 0, 10) > 0) players.classList.add('occupied-count');

        var watching = document.createElement('td');
        watching.textContent = String(table.spectator_count || 0);

        var blinds = document.createElement('td');
        blinds.textContent = table.small_blind_text + ' / ' + table.big_blind_text;
        blinds.title = 'Increases every ' + table.blind_hands_per_level + ' hands';

        var buyin = document.createElement('td');
        buyin.textContent = table.min_buyin_text + ' – ' + table.max_buyin_text;

        var statusCell = document.createElement('td');
        var status = document.createElement('span');
        status.className = statusClass(table.status);
        status.textContent = table.status_text;
        statusCell.appendChild(status);

        var hand = document.createElement('td');
        hand.textContent = table.hand_no ? '#' + table.hand_no : '-';

        var actionCell = document.createElement('td');
        var link = document.createElement('a');
        link.className = 'join';
        link.href = 'poker.php?table_id=' + encodeURIComponent(table.id);
        link.textContent = table.my_seat
            ? 'Return'
            : (draining ? 'Watch' : (table.player_count >= table.max_seats ? 'Spectate' : 'Join / Watch'));
        actionCell.appendChild(link);

        row.appendChild(nameCell);
        row.appendChild(players);
        row.appendChild(watching);
        row.appendChild(blinds);
        row.appendChild(buyin);
        row.appendChild(statusCell);
        row.appendChild(hand);
        row.appendChild(actionCell);
        body.appendChild(row);
    }

    function renderTournamentTable(body, table, draining) {
        var row = document.createElement('tr');
        if (parseInt(table.player_count || 0, 10) > 0) row.classList.add('occupied-table');

        var nameCell = document.createElement('td');
        var name = document.createElement('span');
        name.className = 'table-name';
        name.textContent = table.name;
        nameCell.appendChild(name);

        var meta = document.createElement('span');
        meta.className = 'table-meta';
        meta.textContent = table.hand_no ? 'Hand #' + table.hand_no : 'Waiting to begin';
        nameCell.appendChild(meta);

        if (table.my_seat) {
            var mine = document.createElement('span');
            mine.className = 'my-table';
            mine.textContent = 'Registered';
            nameCell.appendChild(mine);
        }

        var registered = document.createElement('td');
        registered.textContent = table.player_count + ' / ' + table.max_seats;
        if (parseInt(table.player_count || 0, 10) > 0) registered.classList.add('occupied-count');

        var watching = document.createElement('td');
        watching.textContent = String(table.spectator_count || 0);

        var entry = document.createElement('td');
        entry.textContent = table.tournament_entry_fee_text;

        var prize = document.createElement('td');
        prize.textContent = table.tournament_prize_pool_text;

        var vault = document.createElement('td');
        vault.className = 'vault-prize';
        vault.textContent = table.tournament_vault_range_text;
        vault.title = 'Random winner bonus · Requires ' + table.tournament_vault_min_players + ' players';

        var blinds = document.createElement('td');
        blinds.textContent = table.small_blind_text + ' / ' + table.big_blind_text;

        var statusCell = document.createElement('td');
        var status = document.createElement('span');
        status.className = statusClass(table.status);
        if (table.tournament_status === 'registration') {
            status.textContent = 'Registration';
        } else if (table.tournament_status === 'running') {
            status.textContent = table.status_text;
        } else {
            status.textContent = 'Finished';
        }
        statusCell.appendChild(status);

        var actionCell = document.createElement('td');
        var link = document.createElement('a');
        link.className = 'join';
        link.href = 'poker.php?table_id=' + encodeURIComponent(table.id);
        link.textContent = table.my_seat
            ? 'Return'
            : (draining
                ? 'Watch'
                : (table.tournament_status === 'registration' && table.player_count < table.max_seats ? 'Register / Watch' : 'Spectate'));
        actionCell.appendChild(link);

        row.appendChild(nameCell);
        row.appendChild(registered);
        row.appendChild(watching);
        row.appendChild(entry);
        row.appendChild(prize);
        row.appendChild(vault);
        row.appendChild(blinds);
        row.appendChild(statusCell);
        row.appendChild(actionCell);
        body.appendChild(row);
    }

    function renderHouseTable(body, table, draining) {
        var row = document.createElement('tr');
        if (parseInt(table.player_count || 0, 10) > 0) row.classList.add('occupied-table');

        var nameCell = document.createElement('td');
        var name = document.createElement('span');
        name.className = 'table-name';
        name.textContent = table.name;
        nameCell.appendChild(name);

        if (table.my_seat) {
            var mine = document.createElement('span');
            mine.className = 'my-table';
            mine.textContent = 'Your game';
            nameCell.appendChild(mine);
        }

        var players = document.createElement('td');
        players.textContent = table.player_count + ' Playing';
        if (parseInt(table.player_count || 0, 10) > 0) players.classList.add('occupied-count');

        var blinds = document.createElement('td');
        blinds.textContent = table.small_blind_text + ' / ' + table.big_blind_text;

        var buyin = document.createElement('td');
        buyin.textContent = table.min_buyin_text + ' – ' + table.max_buyin_text;

        var statusCell = document.createElement('td');
        var status = document.createElement('span');
        status.className = statusClass(table.status);
        status.textContent = 'The Collector · Open';
        statusCell.appendChild(status);

        var actionCell = document.createElement('td');
        var link = document.createElement('a');
        link.className = 'join';
        link.href = 'poker.php?table_id=' + encodeURIComponent(table.id);
        link.textContent = table.my_seat ? 'Return to The Collector' : (draining ? 'Unavailable' : 'Play The Collector');
        if (draining && !table.my_seat) {
            link.style.pointerEvents = 'none';
            link.style.opacity = '.45';
        }
        actionCell.appendChild(link);

        row.appendChild(nameCell);
        row.appendChild(players);
        row.appendChild(blinds);
        row.appendChild(buyin);
        row.appendChild(statusCell);
        row.appendChild(actionCell);
        body.appendChild(row);
    }

    function renderLobby(state) {
        var cashBody = document.getElementById('cashRows');
        var tournamentBody = document.getElementById('tournamentRows');
        var houseBody = document.getElementById('houseRows');
        cashBody.innerHTML = '';
        tournamentBody.innerHTML = '';
        houseBody.innerHTML = '';

        document.getElementById('lobbyCredit').textContent = state.me.total_credit_text;
        renderStarterStake(state);

        var draining = !!(state.maintenance && state.maintenance.draining);
        document.getElementById('maintenanceDrainNotice').style.display = draining ? 'block' : 'none';

        var tables = state.tables || [];
        var cashTables = tables.filter(function(table) { return table.game_type === 'cash'; });
        var tournamentTables = tables.filter(function(table) { return table.game_type === 'tournament'; });
        var houseTables = tables.filter(function(table) { return table.game_type === 'house'; });

        document.getElementById('cashCount').textContent = cashTables.length + (cashTables.length === 1 ? ' Table' : ' Tables');
        document.getElementById('tournamentCount').textContent = tournamentTables.length + (tournamentTables.length === 1 ? ' Event' : ' Events');
        document.getElementById('houseCount').textContent = houseTables.length + (houseTables.length === 1 ? ' Game' : ' Games');

        if (!cashTables.length) {
            appendEmptyRow(cashBody, 8, 'No multiplayer games are configured.');
        } else {
            cashTables.forEach(function(table) {
                renderCashTable(cashBody, table, draining);
            });
        }

        if (!tournamentTables.length) {
            appendEmptyRow(tournamentBody, 8, 'No tournaments are currently configured.');
        } else {
            tournamentTables.forEach(function(table) {
                renderTournamentTable(tournamentBody, table, draining);
            });
        }

        if (!houseTables.length) {
            appendEmptyRow(houseBody, 6, 'No single-player House games are configured.');
        } else {
            houseTables.forEach(function(table) {
                renderHouseTable(houseBody, table, draining);
            });
        }
    }

    function refreshLobby() {
        fetch('poker-api.php?action=lobby&_=' + Date.now(), {
            credentials: 'same-origin'
        })
        .then(function (response) {
            return response.json();
        })
        .then(function (state) {
            if (!state.ok) {
                throw new Error(state.error || 'Unable to refresh poker lobby.');
            }

            renderLobby(state);
        })
        .catch(function () {
            /* Keep the last good state visible if one refresh fails. */
        });
    }

    var starterStakeClaim = document.getElementById('starterStakeClaim');
    if (starterStakeClaim) {
        starterStakeClaim.addEventListener('click', function () {
            var message = document.getElementById('starterStakeMessage');
            starterStakeClaim.disabled = true;
            message.style.display = 'none';
            message.textContent = '';

            var body = new URLSearchParams();
            body.set('csrf', pokerCsrf);

            fetch('poker-api.php?action=starter_claim', {
                method: 'POST',
                credentials: 'same-origin',
                headers: {
                    'Content-Type': 'application/x-www-form-urlencoded;charset=UTF-8'
                },
                body: body.toString()
            })
            .then(function (response) {
                return response.json();
            })
            .then(function (state) {
                if (!state.ok) {
                    throw new Error(state.error || 'Unable to claim the Poker Starter Stake.');
                }

                document.getElementById('lobbyCredit').textContent = state.me.total_credit_text;
                message.textContent = 'The Collector spotted you ' + state.me.starter_stake.amount_text + '. Try not to lose it too fast.';
                message.style.display = 'block';

                window.setTimeout(function () {
                    renderLobby(state);
                }, 2600);
            })
            .catch(function (error) {
                message.textContent = error.message || 'Unable to claim the Poker Starter Stake.';
                message.style.display = 'block';
                starterStakeClaim.disabled = false;
            });
        });
    }

    renderLobby(initialState);
    window.setInterval(refreshLobby, 5000);
})();
</script>
<?php
if (function_exists('end_frame')) {
    end_frame();
}

if (function_exists('stdfoot')) {
    stdfoot();
}
?>
