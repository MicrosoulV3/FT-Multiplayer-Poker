<?php
/*
 * Multiplayer Poker rewrite - PHP 7.4+
 */

if (!defined('KB')) {
    define('KB', 1024);
}

if (!defined('MB')) {
    define('MB', 1024 * KB);
}

if (!defined('GB')) {
    define('GB', 1024 * MB);
}

/* Backward-compatible alias for older poker code. */
if (!defined('POKER_BYTES_GB')) {
    define('POKER_BYTES_GB', GB);
}

if (!defined('POKER_TURN_SECONDS')) {
    define('POKER_TURN_SECONDS', 30);
}

if (!defined('POKER_RECONNECT_GRACE_SECONDS')) {
    define('POKER_RECONNECT_GRACE_SECONDS', 15);
}

if (!defined('POKER_PRESENCE_HEARTBEAT_SECONDS')) {
    define('POKER_PRESENCE_HEARTBEAT_SECONDS', 10);
}

if (!defined('POKER_PRESENCE_STALE_SECONDS')) {
    define('POKER_PRESENCE_STALE_SECONDS', 15);
}

if (!defined('POKER_SPECTATOR_STALE_SECONDS')) {
    define('POKER_SPECTATOR_STALE_SECONDS', 12);
}

if (!defined('POKER_STARTER_STAKE')) {
    define('POKER_STARTER_STAKE', 1 * GB);
}

if (!defined('POKER_STARTER_ACTIVITY_LIMIT')) {
    define('POKER_STARTER_ACTIVITY_LIMIT', 1 * MB);
}

function poker_session_init()
{
    global $CURUSER;

    if (empty($CURUSER['id'])) {
        header('Location: account-login.php?returnto=' . rawurlencode($_SERVER['REQUEST_URI']));
        exit;
    }

    if (session_status() !== PHP_SESSION_ACTIVE) {
        session_start();
    }

    if (empty($_SESSION['poker_csrf'])) {
        $_SESSION['poker_csrf'] = bin2hex(random_bytes(24));
    }
}

function poker_db()
{
    if (function_exists('getDatabaseConnection')) {
        return getDatabaseConnection();
    }

    if (isset($GLOBALS['DBconnector']) && $GLOBALS['DBconnector'] instanceof mysqli) {
        return $GLOBALS['DBconnector'];
    }

    throw new RuntimeException('Poker could not locate the tracker mysqli connection.');
}

function poker_user_is_admin($user)
{
    return !empty($user['id'])
        && isset($user['class'])
        && (int) $user['class'] >= 7;
}

function poker_lock_settings($db)
{
    $stmt = $db->prepare('SELECT maintenance_mode FROM poker_settings WHERE id=1 FOR UPDATE');
    $stmt->execute();
    $row = $stmt->get_result()->fetch_assoc();
    $stmt->close();

    if (!$row) {
        throw new RuntimeException('Poker settings row is missing. Run poker-maintenance.sql.');
    }

    return $row;
}

function poker_maintenance_state($db)
{
    $stmt = $db->prepare('SELECT maintenance_mode FROM poker_settings WHERE id=1 LIMIT 1');
    $stmt->execute();
    $row = $stmt->get_result()->fetch_assoc();
    $stmt->close();

    if (!$row) {
        throw new RuntimeException('Poker settings row is missing. Run poker-maintenance.sql.');
    }

    return max(0, min(2, (int) $row['maintenance_mode']));
}

function poker_maintenance_enabled($db)
{
    return poker_maintenance_state($db) !== 0;
}

function poker_maintenance_draining($db)
{
    return poker_maintenance_state($db) === 1;
}

function poker_maintenance_offline($db)
{
    return poker_maintenance_state($db) === 2;
}

function poker_maintenance_message()
{
    return 'Poker is temporarily unavailable while maintenance is being performed.';
}

function poker_is_tournament_table($table)
{
    return isset($table['game_type']) && (string) $table['game_type'] === 'tournament';
}

function poker_is_house_table($table)
{
    return isset($table['game_type']) && (string) $table['game_type'] === 'house';
}

function poker_house_bot_seat()
{
    return 3;
}

function poker_house_player_seat()
{
    return 8;
}

function poker_format_chips($amount)
{
    return number_format((int) $amount) . ' chips';
}

function poker_format_table_amount($table, $amount)
{
    return poker_is_tournament_table($table) ? poker_format_chips($amount) : poker_format_bytes($amount);
}

function poker_start_tournament($db, $tableId, $user)
{
    if (!poker_user_is_admin($user)) {
        throw new RuntimeException('Administrator access required.');
    }

    $db->begin_transaction();

    try {
        $table = poker_table_for_update($db, $tableId);

        if (!$table || !poker_is_tournament_table($table)) {
            throw new RuntimeException('Tournament not found.');
        }

        if ((string) $table['tournament_status'] !== 'registration') {
            throw new RuntimeException('Tournament registration is not open.');
        }

        $stmt = $db->prepare('SELECT COUNT(*) AS player_count FROM poker_seats WHERE table_id=?');
        $stmt->bind_param('i', $tableId);
        $stmt->execute();
        $row = $stmt->get_result()->fetch_assoc();
        $stmt->close();

        $playerCount = $row ? (int) $row['player_count'] : 0;

        if ($playerCount < 2) {
            throw new RuntimeException('At least two registered players are required.');
        }

        $message = 'Tournament started. Registration is closed. Deal the first hand!';
        $stmt = $db->prepare("UPDATE poker_tables SET tournament_status='running',tournament_started_at=NOW(),last_message=? WHERE id=?");
        $stmt->bind_param('si', $message, $tableId);
        $stmt->execute();
        $stmt->close();

        poker_dealer_message($db, $tableId, $message);
        $db->commit();

        return true;
    } catch (Throwable $e) {
        $db->rollback();
        throw $e;
    }
}

function poker_tournament_finalize_if_winner($db, $tableId)
{
    $table = poker_table_for_update($db, $tableId);
    if (!$table || !poker_is_tournament_table($table) || (string)$table['tournament_status'] !== 'running') return false;

    $stmt=$db->prepare('SELECT seat_no,user_id,username,stack FROM poker_seats WHERE table_id=? AND stack>0 ORDER BY stack DESC FOR UPDATE');
    $stmt->bind_param('i',$tableId); $stmt->execute(); $result=$stmt->get_result(); $alive=array();
    while($row=$result->fetch_assoc()) $alive[]=$row;
    $stmt->close();
    if(count($alive)!==1) return false;

    $winner=$alive[0]; $prize=(int)$table['tournament_prize_pool'];
    if($prize>0){
        $stmt=$db->prepare('UPDATE users SET uploaded=uploaded+? WHERE id=?');
        $uid=(int)$winner['user_id']; $stmt->bind_param('ii',$prize,$uid); $stmt->execute(); $stmt->close();
    }
    $msg=(string)$winner['username'].' wins the tournament and '.poker_format_bytes($prize).' in upload credit!';
    $uid=(int)$winner['user_id'];
    $stmt=$db->prepare("UPDATE poker_tables SET tournament_status='finished',tournament_winner_user_id=?,tournament_ended_at=NOW(),last_message=? WHERE id=?");
    $stmt->bind_param('isi',$uid,$msg,$tableId); $stmt->execute(); $stmt->close();
    poker_dealer_message($db,$tableId,$msg);
    return true;
}

function poker_maintenance_drain_message($playing)
{
    if ($playing) {
        return 'Poker maintenance is starting. This is the final hand. The table will close when it finishes.';
    }

    return 'Poker maintenance is starting. No new hands will begin. Please leave the table to return your poker stack to upload credit.';
}

function poker_finalize_maintenance_if_empty($db)
{
    $db->begin_transaction();
    try {
        $settings = poker_lock_settings($db);
        if ((int) $settings['maintenance_mode'] !== 1) {
            $db->commit();
            return false;
        }

        $stmt = $db->prepare('SELECT COUNT(*) AS player_count FROM poker_seats');
        $stmt->execute();
        $row = $stmt->get_result()->fetch_assoc();
        $stmt->close();

        if ($row && (int) $row['player_count'] === 0) {
            $offline = 2;
            $stmt = $db->prepare('UPDATE poker_settings SET maintenance_mode=?,updated_at=NOW() WHERE id=1');
            $stmt->bind_param('i', $offline);
            $stmt->execute();
            $stmt->close();
            $db->commit();
            return true;
        }

        $db->commit();
        return false;
    } catch (Throwable $e) {
        $db->rollback();
        throw $e;
    }
}

function poker_json($data, $status = 200)
{
    http_response_code($status);
    header('Content-Type: application/json; charset=UTF-8');
    echo json_encode($data, JSON_UNESCAPED_SLASHES);
    exit;
}

function poker_csrf_check()
{
    $token = isset($_POST['csrf']) ? (string) $_POST['csrf'] : '';
    if ($token === '' || empty($_SESSION['poker_csrf']) || !hash_equals($_SESSION['poker_csrf'], $token)) {
        poker_json(array('ok' => false, 'error' => 'Invalid request token.'), 403);
    }
}

function poker_u64($value)
{
    if (is_int($value)) {
        return max(0, $value);
    }
    if (!is_numeric($value)) {
        return 0;
    }
    $n = (int) floor((float) $value);
    return max(0, $n);
}

function poker_format_bytes($bytes)
{
    $bytes = (float) $bytes;
    $units = array('B', 'KB', 'MB', 'GB', 'TB');
    $i = 0;
    while ($bytes >= 1024 && $i < count($units) - 1) {
        $bytes /= 1024;
        $i++;
    }
    $precision = $i >= 3 ? 2 : 0;
    $formatted = number_format($bytes, $precision, '.', '');

    if ($precision > 0) {
        $formatted = rtrim(rtrim($formatted, '0'), '.');
    }

    return $formatted . ' ' . $units[$i];
}

function poker_gb_to_bytes($gb)
{
    $gb = (float) $gb;
    if ($gb <= 0) {
        return 0;
    }
    return (int) round($gb * GB);
}

function poker_mb_to_bytes($mb)
{
    $mb = (float) $mb;
    if ($mb <= 0) {
        return 0;
    }
    return (int) round($mb * MB);
}

function poker_blinds_for_hand($handNo, $startingSmallBlind, $startingBigBlind, $handsPerLevel)
{
    $handNo = max(1, (int) $handNo);
    $startingSmallBlind = max(1, (int) $startingSmallBlind);
    $startingBigBlind = max($startingSmallBlind, (int) $startingBigBlind);
    $handsPerLevel = max(1, (int) $handsPerLevel);

    $level = (int) floor(($handNo - 1) / $handsPerLevel);

    /*
     * Same escalation curve as the original 100 MB / 200 MB schedule,
     * now scaled from each table's configured starting blinds.
     */
    $multipliers = array(
        1.0,
        1.5,
        2.0,
        3.0,
        4.0,
        5.0,
        7.5,
        10.0,
        15.0,
        20.0
    );

    if ($level >= count($multipliers)) {
        $level = count($multipliers) - 1;
    }

    $multiplier = $multipliers[$level];

    return array(
        'level' => $level + 1,
        'small_blind' => (int) round($startingSmallBlind * $multiplier),
        'big_blind' => (int) round($startingBigBlind * $multiplier)
    );
}



function poker_remove_spectator($db, $tableId, $userId)
{
    $stmt = $db->prepare('DELETE FROM poker_spectators WHERE table_id=? AND user_id=?');
    $stmt->bind_param('ii', $tableId, $userId);
    $stmt->execute();
    $stmt->close();
}

function poker_touch_spectator($db, $tableId, $userId, $isSeated)
{
    $tableId = (int) $tableId;
    $userId = (int) $userId;

    if ($tableId <= 0 || $userId <= 0) {
        return;
    }

    if ($isSeated) {
        poker_remove_spectator($db, $tableId, $userId);
        return;
    }

    $stmt = $db->prepare('SELECT username FROM users WHERE id=? LIMIT 1');
    $stmt->bind_param('i', $userId);
    $stmt->execute();
    $row = $stmt->get_result()->fetch_assoc();
    $stmt->close();

    if (!$row) {
        return;
    }

    $username = (string) $row['username'];

    $stmt = $db->prepare('INSERT INTO poker_spectators
        (table_id,user_id,username,last_seen_at)
        VALUES (?,?,?,NOW())
        ON DUPLICATE KEY UPDATE username=VALUES(username),last_seen_at=NOW()');
    $stmt->bind_param('iis', $tableId, $userId, $username);
    $stmt->execute();
    $stmt->close();
}

function poker_spectators($db, $tableId)
{
    $staleSeconds = (int) POKER_SPECTATOR_STALE_SECONDS;

    $stmt = $db->prepare("SELECT user_id,username
        FROM poker_spectators
        WHERE table_id=?
          AND last_seen_at >= DATE_SUB(NOW(), INTERVAL {$staleSeconds} SECOND)
        ORDER BY username ASC");
    $stmt->bind_param('i', $tableId);
    $stmt->execute();
    $result = $stmt->get_result();

    $spectators = array();
    while ($row = $result->fetch_assoc()) {
        $spectators[] = array(
            'user_id' => (int) $row['user_id'],
            'username' => (string) $row['username']
        );
    }
    $stmt->close();

    return $spectators;
}


function poker_active_game_for_user($db, $userId)
{
    $userId = (int) $userId;

    /*
     * Normal multiplayer and tournament seats live in poker_seats.
     * A player may have only one active poker game at a time.
     */
    $stmt = $db->prepare("\n        SELECT\n            s.table_id,\n            t.name,\n            t.game_type\n        FROM poker_seats s\n        INNER JOIN poker_tables t ON t.id = s.table_id\n        WHERE s.user_id = ?\n        ORDER BY s.table_id ASC\n        LIMIT 1\n    ");
    $stmt->bind_param('i', $userId);
    $stmt->execute();
    $row = $stmt->get_result()->fetch_assoc();
    $stmt->close();

    if ($row) {
        return array(
            'table_id' => (int) $row['table_id'],
            'name' => (string) $row['name'],
            'game_type' => (string) $row['game_type'],
            'is_house' => false
        );
    }

    /* House games are private sessions and are stored separately. */
    $stmt = $db->prepare("\n        SELECT\n            hs.template_id AS table_id,\n            t.name,\n            t.game_type\n        FROM poker_house_sessions hs\n        INNER JOIN poker_tables t ON t.id = hs.template_id\n        WHERE hs.user_id = ?\n          AND hs.active = 1\n        ORDER BY hs.id ASC\n        LIMIT 1\n    ");
    $stmt->bind_param('i', $userId);
    $stmt->execute();
    $row = $stmt->get_result()->fetch_assoc();
    $stmt->close();

    if ($row) {
        return array(
            'table_id' => (int) $row['table_id'],
            'name' => (string) $row['name'],
            'game_type' => 'house',
            'is_house' => true
        );
    }

    return null;
}

function poker_active_game_join_error($activeGame)
{
    if (!$activeGame) {
        return 'You are already seated in another poker game.';
    }

    $name = isset($activeGame['name']) ? trim((string) $activeGame['name']) : '';
    if ($name === '') {
        $name = 'another poker game';
    }

    return 'You are already seated at ' . $name . '. Leave that game before joining another table.';
}

function poker_starter_stake_status($db, $userId)
{
    $userId = (int) $userId;
    $amount = (int) POKER_STARTER_STAKE;
    $activityLimit = (int) POKER_STARTER_ACTIVITY_LIMIT;

    $stmt = $db->prepare('SELECT uploaded,downloaded FROM users WHERE id=? LIMIT 1');
    $stmt->bind_param('i', $userId);
    $stmt->execute();
    $account = $stmt->get_result()->fetch_assoc();
    $stmt->close();

    if (!$account) {
        return array(
            'eligible' => false,
            'claimed' => false,
            'amount' => $amount,
            'amount_text' => poker_format_bytes($amount)
        );
    }

    $stmt = $db->prepare('SELECT id FROM poker_starter_grants WHERE user_id=? LIMIT 1');
    $stmt->bind_param('i', $userId);
    $stmt->execute();
    $claimed = (bool) $stmt->get_result()->fetch_assoc();
    $stmt->close();

    $activeGame = poker_active_game_for_user($db, $userId);

    $stmt = $db->prepare("SELECT COALESCE(SUM(s.stack),0) AS poker_stack
                          FROM poker_seats s
                          INNER JOIN poker_tables t ON t.id=s.table_id AND t.game_type='cash'
                          WHERE s.user_id=?");
    $stmt->bind_param('i', $userId);
    $stmt->execute();
    $stackRow = $stmt->get_result()->fetch_assoc();
    $stmt->close();
    $pokerStack = $stackRow ? (int) $stackRow['poker_stack'] : 0;

    $stmt = $db->prepare('SELECT COALESCE(SUM(player_stack),0) AS house_stack FROM poker_house_sessions WHERE user_id=? AND active=1');
    $stmt->bind_param('i', $userId);
    $stmt->execute();
    $houseRow = $stmt->get_result()->fetch_assoc();
    $stmt->close();
    $pokerStack += $houseRow ? (int) $houseRow['house_stack'] : 0;

    $uploaded = (int) $account['uploaded'];
    $downloaded = (int) $account['downloaded'];
    $eligible = (
        !$claimed &&
        !$activeGame &&
        $uploaded < $activityLimit &&
        $downloaded < $activityLimit &&
        ($uploaded + $pokerStack) < $activityLimit
    );

    return array(
        'eligible' => $eligible,
        'claimed' => $claimed,
        'amount' => $amount,
        'amount_text' => poker_format_bytes($amount)
    );
}

function poker_claim_starter_stake($db, $userId)
{
    $userId = (int) $userId;
    $amount = (int) POKER_STARTER_STAKE;
    $activityLimit = (int) POKER_STARTER_ACTIVITY_LIMIT;

    $db->begin_transaction();

    try {
        $stmt = $db->prepare('SELECT uploaded,downloaded FROM users WHERE id=? FOR UPDATE');
        $stmt->bind_param('i', $userId);
        $stmt->execute();
        $account = $stmt->get_result()->fetch_assoc();
        $stmt->close();

        if (!$account) {
            throw new RuntimeException('Poker account not found.');
        }

        $stmt = $db->prepare('SELECT id FROM poker_starter_grants WHERE user_id=? LIMIT 1 FOR UPDATE');
        $stmt->bind_param('i', $userId);
        $stmt->execute();
        $existing = $stmt->get_result()->fetch_assoc();
        $stmt->close();

        if ($existing) {
            throw new RuntimeException('You have already claimed the Poker Starter Stake.');
        }

        $activeGame = poker_active_game_for_user($db, $userId);
        if ($activeGame) {
            throw new RuntimeException('Leave your current poker game before claiming the Poker Starter Stake.');
        }

        $stmt = $db->prepare("SELECT COALESCE(SUM(s.stack),0) AS poker_stack
                              FROM poker_seats s
                              INNER JOIN poker_tables t ON t.id=s.table_id AND t.game_type='cash'
                              WHERE s.user_id=?");
        $stmt->bind_param('i', $userId);
        $stmt->execute();
        $stackRow = $stmt->get_result()->fetch_assoc();
        $stmt->close();
        $pokerStack = $stackRow ? (int) $stackRow['poker_stack'] : 0;

        $stmt = $db->prepare('SELECT COALESCE(SUM(player_stack),0) AS house_stack FROM poker_house_sessions WHERE user_id=? AND active=1');
        $stmt->bind_param('i', $userId);
        $stmt->execute();
        $houseRow = $stmt->get_result()->fetch_assoc();
        $stmt->close();
        $pokerStack += $houseRow ? (int) $houseRow['house_stack'] : 0;

        $creditBefore = (int) $account['uploaded'];
        $downloaded = (int) $account['downloaded'];

        if (
            $creditBefore >= $activityLimit ||
            $downloaded >= $activityLimit ||
            ($creditBefore + $pokerStack) >= $activityLimit
        ) {
            throw new RuntimeException('The Poker Starter Stake is only available to brand-new poker players with no meaningful tracker activity.');
        }

        $stmt = $db->prepare('UPDATE users SET uploaded=uploaded+? WHERE id=?');
        $stmt->bind_param('ii', $amount, $userId);
        $stmt->execute();
        $stmt->close();

        $stmt = $db->prepare('INSERT INTO poker_starter_grants (user_id,amount,credit_before,claimed_at) VALUES (?,?,?,NOW())');
        $stmt->bind_param('iii', $userId, $amount, $creditBefore);
        $stmt->execute();
        $stmt->close();

        $db->commit();
        return true;
    } catch (Throwable $e) {
        $db->rollback();
        throw $e;
    }
}

function poker_lobby_data($db, $userId)
{
    $stmt = $db->prepare("
        SELECT
            t.id,
            t.name,
            t.small_blind,
            t.big_blind,
            t.starting_small_blind,
            t.starting_big_blind,
            t.blind_hands_per_level,
            t.min_buyin,
            t.max_buyin,
            t.max_seats,
            t.game_type,
            t.tournament_status,
            t.tournament_entry_fee,
            t.tournament_starting_stack,
            t.tournament_prize_pool,
            t.status,
            t.hand_no,
            SUM(CASE WHEN s.user_id > 0 THEN 1 ELSE 0 END) AS player_count,
            MAX(CASE WHEN s.user_id = ? THEN s.seat_no ELSE 0 END) AS my_seat,
            (SELECT COUNT(*)
             FROM poker_spectators ps
             WHERE ps.table_id=t.id
               AND ps.last_seen_at >= DATE_SUB(NOW(), INTERVAL 12 SECOND)) AS spectator_count
        FROM poker_tables t
        LEFT JOIN poker_seats s ON s.table_id = t.id
        GROUP BY
            t.id,
            t.name,
            t.small_blind,
            t.big_blind,
            t.starting_small_blind,
            t.starting_big_blind,
            t.blind_hands_per_level,
            t.min_buyin,
            t.max_buyin,
            t.max_seats,
            t.game_type,
            t.tournament_status,
            t.tournament_entry_fee,
            t.tournament_starting_stack,
            t.tournament_prize_pool,
            t.status,
            t.hand_no
        ORDER BY t.id ASC
    ");
    $stmt->bind_param('i', $userId);
    $stmt->execute();
    $result = $stmt->get_result();

    $tables = array();

    while ($row = $result->fetch_assoc()) {
        if ((string)$row['game_type'] === 'house') {
            $houseStmt = $db->prepare('SELECT SUM(CASE WHEN last_seen_at >= DATE_SUB(NOW(), INTERVAL 60 SECOND) THEN 1 ELSE 0 END) AS active_count, MAX(CASE WHEN user_id=? THEN 1 ELSE 0 END) AS mine FROM poker_house_sessions WHERE template_id=? AND active=1');
            $templateIdForCount = (int)$row['id'];
            $houseStmt->bind_param('ii', $userId, $templateIdForCount);
            $houseStmt->execute();
            $houseRow = $houseStmt->get_result()->fetch_assoc();
            $houseStmt->close();
            $row['player_count'] = $houseRow ? (int)$houseRow['active_count'] : 0;
            $row['my_seat'] = ($houseRow && !empty($houseRow['mine'])) ? poker_house_player_seat() : 0;
            $row['status'] = !empty($row['my_seat']) ? 'playing' : 'waiting';
        }

        $status = (string) $row['status'];

        if ($status === 'playing') {
            $statusText = 'Playing';
        } elseif ($status === 'showdown') {
            $statusText = 'Between Hands';
        } else {
            $statusText = 'Waiting';
        }

        $tables[] = array(
            'id' => (int) $row['id'],
            'name' => (string) $row['name'],
            'player_count' => (int) $row['player_count'],
            'max_seats' => ((string) $row['game_type'] === 'house' ? 1 : (int) $row['max_seats']),
            'status' => $status,
            'status_text' => $statusText,
            'hand_no' => (int) $row['hand_no'],
            'small_blind_text' => ((string)$row['game_type']==='tournament' ? poker_format_chips((int)$row['small_blind']) : poker_format_bytes((int)$row['small_blind'])),
            'big_blind_text' => ((string)$row['game_type']==='tournament' ? poker_format_chips((int)$row['big_blind']) : poker_format_bytes((int)$row['big_blind'])),
            'starting_small_blind' => (int) $row['starting_small_blind'],
            'starting_small_blind_text' => ((string)$row['game_type']==='tournament' ? poker_format_chips((int)$row['starting_small_blind']) : poker_format_bytes((int)$row['starting_small_blind'])),
            'starting_big_blind' => (int) $row['starting_big_blind'],
            'starting_big_blind_text' => ((string)$row['game_type']==='tournament' ? poker_format_chips((int)$row['starting_big_blind']) : poker_format_bytes((int)$row['starting_big_blind'])),
            'blind_hands_per_level' => (int) $row['blind_hands_per_level'],
            'min_buyin_text' => poker_format_bytes((int) $row['min_buyin']),
            'max_buyin_text' => poker_format_bytes((int) $row['max_buyin']),
            'game_type' => (string) $row['game_type'],
            'tournament_status' => (string) $row['tournament_status'],
            'tournament_entry_fee_text' => poker_format_bytes((int) $row['tournament_entry_fee']),
            'tournament_starting_stack_text' => poker_format_chips((int) $row['tournament_starting_stack']),
            'tournament_prize_pool_text' => poker_format_bytes((int) $row['tournament_prize_pool']),
            'my_seat' => (int) $row['my_seat'],
            'spectator_count' => (int) $row['spectator_count']
        );
    }

    $stmt->close();

    $stmt = $db->prepare('SELECT uploaded FROM users WHERE id=? LIMIT 1');
    $stmt->bind_param('i', $userId);
    $stmt->execute();
    $account = $stmt->get_result()->fetch_assoc();
    $stmt->close();

    $uploaded = $account ? (int) $account['uploaded'] : 0;

    $stmt = $db->prepare("SELECT COALESCE(SUM(s.stack),0) AS poker_stack
                          FROM poker_seats s
                          INNER JOIN poker_tables t ON t.id=s.table_id AND t.game_type='cash'
                          WHERE s.user_id=?");
    $stmt->bind_param('i', $userId);
    $stmt->execute();
    $stackRow = $stmt->get_result()->fetch_assoc();
    $stmt->close();

    $allPokerStack = $stackRow ? (int) $stackRow['poker_stack'] : 0;

    $stmt = $db->prepare('SELECT COALESCE(SUM(player_stack),0) AS house_stack FROM poker_house_sessions WHERE user_id=? AND active=1');
    $stmt->bind_param('i', $userId);
    $stmt->execute();
    $houseStackRow = $stmt->get_result()->fetch_assoc();
    $stmt->close();
    $allPokerStack += $houseStackRow ? (int)$houseStackRow['house_stack'] : 0;

    $maintenanceState = poker_maintenance_state($db);
    $starterStake = poker_starter_stake_status($db, $userId);

    return array(
        'ok' => true,
        'maintenance' => array(
            'state' => $maintenanceState,
            'draining' => $maintenanceState === 1,
            'offline' => $maintenanceState === 2
        ),
        'tables' => $tables,
        'me' => array(
            'uploaded_credit' => $uploaded,
            'all_poker_stack' => $allPokerStack,
            'total_credit' => $uploaded + $allPokerStack,
            'total_credit_text' => poker_format_bytes($uploaded + $allPokerStack),
            'starter_stake' => $starterStake
        )
    );
}

function poker_table_for_update($db, $tableId)
{
    $stmt = $db->prepare('SELECT *, CASE WHEN turn_expires_at IS NOT NULL AND turn_expires_at <= NOW() THEN 1 ELSE 0 END AS turn_expired FROM poker_tables WHERE id = ? FOR UPDATE');
    $stmt->bind_param('i', $tableId);
    $stmt->execute();
    $row = $stmt->get_result()->fetch_assoc();
    $stmt->close();
    return $row ?: null;
}

function poker_get_table($db, $tableId)
{
    $stmt = $db->prepare('SELECT *, CASE WHEN turn_expires_at IS NOT NULL THEN GREATEST(0, CEIL(TIMESTAMPDIFF(MICROSECOND, NOW(), turn_expires_at) / 1000000)) ELSE 0 END AS turn_seconds_left FROM poker_tables WHERE id = ? LIMIT 1');
    $stmt->bind_param('i', $tableId);
    $stmt->execute();
    $row = $stmt->get_result()->fetch_assoc();
    $stmt->close();
    return $row ?: null;
}

function poker_seats($db, $tableId, $forUpdate = false)
{
    $sql = "SELECT *,
                   CASE
                       WHEN last_seen_at IS NULL THEN 999999
                       ELSE GREATEST(0, TIMESTAMPDIFF(SECOND, last_seen_at, NOW()))
                   END AS presence_age_seconds,
                   CASE
                       WHEN reconnect_grace_until IS NULL THEN 0
                       ELSE GREATEST(0, CEIL(TIMESTAMPDIFF(MICROSECOND, NOW(), reconnect_grace_until) / 1000000))
                   END AS reconnect_seconds_left
            FROM poker_seats
            WHERE table_id = ?
            ORDER BY seat_no";
    if ($forUpdate) {
        $sql .= ' FOR UPDATE';
    }
    $stmt = $db->prepare($sql);
    $stmt->bind_param('i', $tableId);
    $stmt->execute();
    $result = $stmt->get_result();
    $rows = array();
    while ($row = $result->fetch_assoc()) {
        $rows[(int) $row['seat_no']] = $row;
    }
    $stmt->close();
    return $rows;
}

function poker_find_user_seat($seats, $userId)
{
    foreach ($seats as $seatNo => $seat) {
        if ((int) $seat['user_id'] === (int) $userId) {
            return (int) $seatNo;
        }
    }
    return null;
}

function poker_seat_numbers_clockwise($startExclusive)
{
    $out = array();
    for ($i = 1; $i <= 10; $i++) {
        $seat = (($startExclusive - 1 + $i) % 10) + 1;
        $out[] = $seat;
    }
    return $out;
}

function poker_next_matching_seat($seats, $startExclusive, $callback)
{
    foreach (poker_seat_numbers_clockwise($startExclusive) as $seatNo) {
        if (isset($seats[$seatNo]) && call_user_func($callback, $seats[$seatNo])) {
            return $seatNo;
        }
    }
    return null;
}

function poker_active_for_hand($seat)
{
    return in_array($seat['hand_state'], array('active', 'allin'), true);
}

function poker_can_act($seat)
{
    return $seat['hand_state'] === 'active' && (int) $seat['stack'] > 0;
}

function poker_build_deck()
{
    $ranks = array('2','3','4','5','6','7','8','9','10','J','Q','K','A');
    $suits = array('C','D','H','S');
    $deck = array();
    foreach ($ranks as $rank) {
        foreach ($suits as $suit) {
            $deck[] = $rank . $suit;
        }
    }
    shuffle($deck);
    return $deck;
}

function poker_take_card(&$deck)
{
    if (!$deck) {
        throw new RuntimeException('Deck is empty.');
    }
    return array_pop($deck);
}

function poker_set_seat_hand($db, $tableId, $seatNo, $stack, $roundBet, $contribution, $state, $acted, $h1, $h2)
{
    $stmt = $db->prepare('UPDATE poker_seats SET stack=?, round_bet=?, hand_contribution=?, hand_state=?, acted=?, hole1=?, hole2=? WHERE table_id=? AND seat_no=?');
    $stmt->bind_param('iiisissii', $stack, $roundBet, $contribution, $state, $acted, $h1, $h2, $tableId, $seatNo);
    // mysqli integer binding is signed but 64-bit PHP handles tracker-sized byte values on 64-bit servers.
    $stmt->execute();
    $stmt->close();
}

function poker_update_money_state($db, $tableId, $seatNo, $stack, $roundBet, $contribution, $state, $acted)
{
    $stmt = $db->prepare('UPDATE poker_seats SET stack=?, round_bet=?, hand_contribution=?, hand_state=?, acted=? WHERE table_id=? AND seat_no=?');
    $stmt->bind_param('iiisiii', $stack, $roundBet, $contribution, $state, $acted, $tableId, $seatNo);
    $stmt->execute();
    $stmt->close();
}

function poker_log_action($db, $tableId, $handNo, $userId, $seatNo, $action, $amount)
{
    $stmt = $db->prepare('INSERT INTO poker_actions (table_id,hand_no,user_id,seat_no,action_name,amount) VALUES (?,?,?,?,?,?)');
    $stmt->bind_param('iiiisi', $tableId, $handNo, $userId, $seatNo, $action, $amount);
    $stmt->execute();
    $stmt->close();
}

function poker_history_start($db, $tableId, $handNo, $dealerSeat, $smallBlind, $bigBlind, $seats)
{
    $players = array();

    foreach ($seats as $seatNo => $seat) {
        if (!in_array($seat['hand_state'], array('active', 'allin'), true)) {
            continue;
        }

        $players[] = array(
            'seat_no' => (int) $seatNo,
            'user_id' => (int) $seat['user_id'],
            'username' => (string) $seat['username'],
            'starting_stack' => (int) $seat['stack'] + (int) $seat['hand_contribution'],
            'hole1' => $seat['hole1'],
            'hole2' => $seat['hole2'],
            'final_state' => null
        );
    }

    $playersJson = json_encode($players);
    $boardJson = json_encode(array());

    $stmt = $db->prepare("INSERT INTO poker_hand_history
        (table_id,hand_no,dealer_seat,small_blind,big_blind,players_json,board_json,status,showdown_reached,result_text,started_at)
        VALUES (?,?,?,?,?,?,?,'playing',0,'',NOW())
        ON DUPLICATE KEY UPDATE
            dealer_seat=VALUES(dealer_seat),
            small_blind=VALUES(small_blind),
            big_blind=VALUES(big_blind),
            players_json=VALUES(players_json),
            board_json=VALUES(board_json),
            status='playing',
            showdown_reached=0,
            result_text='',
            started_at=NOW(),
            ended_at=NULL");
    $stmt->bind_param('iiiiiss', $tableId, $handNo, $dealerSeat, $smallBlind, $bigBlind, $playersJson, $boardJson);
    $stmt->execute();
    $stmt->close();
}

function poker_history_update_board($db, $tableId, $handNo, $community)
{
    $boardJson = json_encode(array_values($community));

    $stmt = $db->prepare('UPDATE poker_hand_history
        SET board_json=?
        WHERE table_id=? AND hand_no=?');
    $stmt->bind_param('sii', $boardJson, $tableId, $handNo);
    $stmt->execute();
    $stmt->close();
}

function poker_history_finish($db, $tableId, $handNo, $community, $message, $seats, $showdownReached)
{
    $players = array();

    $stmt = $db->prepare('SELECT players_json
        FROM poker_hand_history
        WHERE table_id=? AND hand_no=?
        LIMIT 1');
    $stmt->bind_param('ii', $tableId, $handNo);
    $stmt->execute();
    $row = $stmt->get_result()->fetch_assoc();
    $stmt->close();

    if ($row) {
        $decoded = json_decode((string) $row['players_json'], true);
        if (is_array($decoded)) {
            $players = $decoded;
        }
    }

    foreach ($players as &$player) {
        $seatNo = isset($player['seat_no']) ? (int) $player['seat_no'] : 0;
        if ($seatNo > 0 && isset($seats[$seatNo])) {
            $player['final_state'] = (string) $seats[$seatNo]['hand_state'];
        }
    }
    unset($player);

    $playersJson = json_encode($players);
    $boardJson = json_encode(array_values($community));
    $showdown = $showdownReached ? 1 : 0;

    $stmt = $db->prepare("UPDATE poker_hand_history
        SET players_json=?,
            board_json=?,
            status='finished',
            showdown_reached=?,
            result_text=?,
            ended_at=NOW()
        WHERE table_id=? AND hand_no=?");
    $stmt->bind_param('ssisii', $playersJson, $boardJson, $showdown, $message, $tableId, $handNo);
    $stmt->execute();
    $stmt->close();
}

function poker_history_data($db, $tableId, $viewerUserId, $handNo = 0)
{
    if ($handNo <= 0) {
        $stmt = $db->prepare("SELECT hand_no,status,result_text,
                                     DATE_FORMAT(started_at, '%b %e %H:%i') AS started_text
                              FROM poker_hand_history
                              WHERE table_id=?
                              ORDER BY hand_no DESC
                              LIMIT 20");
        $stmt->bind_param('i', $tableId);
        $stmt->execute();
        $result = $stmt->get_result();

        $hands = array();
        while ($row = $result->fetch_assoc()) {
            $hands[] = array(
                'hand_no' => (int) $row['hand_no'],
                'status' => (string) $row['status'],
                'result' => (string) $row['result_text'],
                'started' => (string) $row['started_text']
            );
        }
        $stmt->close();

        return array('ok' => true, 'hands' => $hands);
    }

    $stmt = $db->prepare("SELECT hand_no,dealer_seat,small_blind,big_blind,
                                 players_json,board_json,status,showdown_reached,result_text,
                                 DATE_FORMAT(started_at, '%b %e, %Y %H:%i:%s') AS started_text,
                                 DATE_FORMAT(ended_at, '%b %e, %Y %H:%i:%s') AS ended_text
                          FROM poker_hand_history
                          WHERE table_id=? AND hand_no=?
                          LIMIT 1");
    $stmt->bind_param('ii', $tableId, $handNo);
    $stmt->execute();
    $hand = $stmt->get_result()->fetch_assoc();
    $stmt->close();

    if (!$hand) {
        throw new RuntimeException('Hand history not found.');
    }

    $historyTable = poker_get_table($db,$tableId);
    $players = json_decode((string) $hand['players_json'], true);
    $board = json_decode((string) $hand['board_json'], true);
    if (!is_array($players)) $players = array();
    if (!is_array($board)) $board = array();

    $showdownReached = !empty($hand['showdown_reached']);
    $playerBySeat = array();

    foreach ($players as &$player) {
        $seatNo = (int) $player['seat_no'];
        $playerBySeat[$seatNo] = array(
            'username' => (string) $player['username'],
            'user_id' => (int) $player['user_id']
        );

        $maySeeCards = (
            (int) $player['user_id'] === (int) $viewerUserId ||
            (
                $showdownReached &&
                in_array((string) $player['final_state'], array('active', 'allin'), true)
            )
        );

        $player['starting_stack_text'] = poker_format_table_amount($historyTable,(int) $player['starting_stack']);
        $player['cards'] = $maySeeCards
            ? array($player['hole1'], $player['hole2'])
            : array('BACK', 'BACK');

        unset($player['hole1'], $player['hole2']);
    }
    unset($player);

    $stmt = $db->prepare('SELECT id,user_id,seat_no,action_name,amount,
                                 DATE_FORMAT(created_at, "%H:%i:%s") AS time_text
                          FROM poker_actions
                          WHERE table_id=? AND hand_no=?
                          ORDER BY id ASC');
    $stmt->bind_param('ii', $tableId, $handNo);
    $stmt->execute();
    $result = $stmt->get_result();

    $actions = array();
    while ($row = $result->fetch_assoc()) {
        $seatNo = (int) $row['seat_no'];
        $username = isset($playerBySeat[$seatNo])
            ? $playerBySeat[$seatNo]['username']
            : ('Seat ' . $seatNo);

        $amount = (int) $row['amount'];

        $actions[] = array(
            'id' => (int) $row['id'],
            'seat_no' => $seatNo,
            'username' => $username,
            'action' => (string) $row['action_name'],
            'amount' => $amount,
            'amount_text' => $amount > 0 ? poker_format_table_amount($historyTable,$amount) : '',
            'time' => (string) $row['time_text']
        );
    }
    $stmt->close();

    return array(
        'ok' => true,
        'hand' => array(
            'hand_no' => (int) $hand['hand_no'],
            'dealer_seat' => (int) $hand['dealer_seat'],
            'small_blind_text' => poker_format_table_amount($historyTable,(int) $hand['small_blind']),
            'big_blind_text' => poker_format_table_amount($historyTable,(int) $hand['big_blind']),
            'players' => $players,
            'board' => $board,
            'status' => (string) $hand['status'],
            'showdown_reached' => $showdownReached,
            'result' => (string) $hand['result_text'],
            'started' => (string) $hand['started_text'],
            'ended' => (string) $hand['ended_text'],
            'actions' => $actions
        )
    );
}

function poker_join($db, $tableId, $seatNo, $buyin, $user)
{
    $userId = (int) $user['id'];
    $username = (string) $user['username'];
    $avatar = isset($user['avatar']) ? (string) $user['avatar'] : '';

    $db->begin_transaction();
    try {
        /*
         * Lock the global poker settings row before seating a player.
         * The admin maintenance toggle locks this same row, preventing
         * a join from racing the maintenance switch.
         */
        $settings = poker_lock_settings($db);

        if ((int) $settings['maintenance_mode'] !== 0) {
            throw new RuntimeException('This table is closed to new players while poker maintenance is pending.');
        }

        $table = poker_table_for_update($db, $tableId);
        if (!$table) {
            throw new RuntimeException('Poker table not found.');
        }
        $isHouse = poker_is_house_table($table);
        if ($isHouse) {
            $seatNo = poker_house_player_seat();
        }
        if ($seatNo < 1 || $seatNo > (int) $table['max_seats']) {
            throw new RuntimeException('Invalid seat.');
        }
        $isTournament = poker_is_tournament_table($table);
        if ($isTournament) {
            if ((string)$table['tournament_status'] !== 'registration') {
                throw new RuntimeException('Tournament registration is closed.');
            }
            $buyin = (int)$table['tournament_entry_fee'];
        } elseif ($buyin < (int) $table['min_buyin'] || $buyin > (int) $table['max_buyin']) {
            throw new RuntimeException('Buy-in must be between ' . poker_format_bytes($table['min_buyin']) . ' and ' . poker_format_bytes($table['max_buyin']) . '.');
        }

        $seats = poker_seats($db, $tableId, true);

        if ($isHouse) {
            foreach ($seats as $existingSeat) {
                if ((int) $existingSeat['user_id'] > 0) {
                    throw new RuntimeException('The House table already has a player.');
                }
            }
        }

        /*
         * If this is the first player joining an empty table, wipe any
         * leftover state from the previous poker session before seating them.
         * This prevents old community cards, winner text, dealer position,
         * from reappearing the instant Buy In is clicked.
         * Hand numbers intentionally remain monotonic for persistent history.
         */
        if (count($seats) === 0) {
            $emptyDeck = '[]';
            $emptyCommunity = '[]';
            $waitingMessage = 'Waiting for players.';

            $stmt = $db->prepare("UPDATE poker_tables
                SET status='waiting',
                    street='preflop',
                    dealer_seat=NULL,
                    current_turn=NULL,
                    turn_expires_at=NULL,
                    current_bet=0,
                    min_raise=big_blind,
                    deck_json=?,
                    community_json=?,
                    last_message=?
                WHERE id=?");
            $stmt->bind_param('sssi', $emptyDeck, $emptyCommunity, $waitingMessage, $tableId);
            $stmt->execute();
            $stmt->close();

            /* Keep the locked table row in sync for this request. */
            $table['status'] = 'waiting';
            $table['street'] = 'preflop';
            $table['dealer_seat'] = null;
            $table['current_turn'] = null;
            $table['current_bet'] = 0;
            $table['min_raise'] = $table['big_blind'];
            $table['deck_json'] = $emptyDeck;
            $table['community_json'] = $emptyCommunity;
            $table['last_message'] = $waitingMessage;
        }

        if (isset($seats[$seatNo])) {
            throw new RuntimeException('That seat is already occupied.');
        }
        if (poker_find_user_seat($seats, $userId) !== null) {
            throw new RuntimeException('You are already sitting at this table.');
        }

        $stmt = $db->prepare('SELECT uploaded FROM users WHERE id=? FOR UPDATE');
        $stmt->bind_param('i', $userId);
        $stmt->execute();
        $account = $stmt->get_result()->fetch_assoc();
        $stmt->close();

        /*
         * The users row is now locked, so concurrent attempts to join two
         * different games are serialized for this account. Check every poker
         * game type before reserving another buy-in.
         */
        $activeGame = poker_active_game_for_user($db, $userId);
        if ($activeGame) {
            throw new RuntimeException(poker_active_game_join_error($activeGame));
        }

        if (!$account || (int) $account['uploaded'] < $buyin) {
            throw new RuntimeException('You do not have enough upload credit for that buy-in.');
        }

        $stmt = $db->prepare('UPDATE users SET uploaded = uploaded - ? WHERE id=? AND uploaded >= ?');
        $stmt->bind_param('iii', $buyin, $userId, $buyin);
        $stmt->execute();
        if ($stmt->affected_rows !== 1) {
            $stmt->close();
            throw new RuntimeException('Unable to reserve the buy-in from your upload credit.');
        }
        $stmt->close();

        /*
         * A newly seated player is not part of the previous hand. Set every
         * hand-specific field explicitly instead of relying on table defaults.
         */
        $stmt = $db->prepare("INSERT INTO poker_seats
            (table_id,seat_no,user_id,username,avatar,stack,round_bet,hand_contribution,hand_state,acted,hole1,hole2,sitting_out,last_seen_at,reconnect_grace_until,reconnect_grace_used)
            VALUES (?,?,?,?,?,?,0,0,'waiting',0,NULL,NULL,0,NOW(),NULL,0)");
        $seatStack = $isTournament ? (int)$table['tournament_starting_stack'] : $buyin;
        $stmt->bind_param('iiissi', $tableId, $seatNo, $userId, $username, $avatar, $seatStack);
        $stmt->execute();
        $stmt->close();

        if ($isHouse) {
            $botSeat = poker_house_bot_seat();
            $botUserId = 0;
            $botName = 'House Bot';
            $botAvatar = '';
            $botStack = max((int) $table['max_buyin'], $buyin);
            $stmt = $db->prepare("INSERT INTO poker_seats
                (table_id,seat_no,user_id,username,avatar,stack,round_bet,hand_contribution,hand_state,acted,hole1,hole2,sitting_out,last_seen_at,reconnect_grace_until,reconnect_grace_used)
                VALUES (?,?,?,?,?,?,0,0,'waiting',0,NULL,NULL,0,NOW(),NULL,0)");
            $stmt->bind_param('iiissi', $tableId, $botSeat, $botUserId, $botName, $botAvatar, $botStack);
            $stmt->execute();
            $stmt->close();
        }

        if ($isTournament) {
            $stmt=$db->prepare('UPDATE poker_tables SET tournament_prize_pool=tournament_prize_pool+?, tournament_entries=tournament_entries+1 WHERE id=?');
            $stmt->bind_param('ii',$buyin,$tableId); $stmt->execute(); $stmt->close();
        }

        $joinSuffix = $table['status'] === 'playing'
            ? ' and will join the next hand.'
            : '.';
        poker_dealer_message(
            $db,
            $tableId,
            $isTournament
                ? ($username . ' registers in Seat ' . $seatNo . ' for ' . poker_format_bytes($buyin) . ' and receives ' . poker_format_chips($seatStack) . '.')
                : ($isHouse
                    ? ($username . ' sits down against the House with ' . poker_format_bytes($buyin) . '.')
                    : ($username . ' takes Seat ' . $seatNo . ' with ' . poker_format_bytes($buyin) . $joinSuffix))
        );

        if (
            ($isHouse || count($seats) + 1 >= 2) &&
            $table['status'] === 'waiting' &&
            (string) $table['last_message'] === 'Waiting for players.'
        ) {
            $emptyMessage = '';
            $stmt = $db->prepare('UPDATE poker_tables SET last_message=? WHERE id=?');
            $stmt->bind_param('si', $emptyMessage, $tableId);
            $stmt->execute();
            $stmt->close();
        }

        $db->commit();
    } catch (Throwable $e) {
        $db->rollback();
        throw $e;
    }
}

function poker_touch_presence($db, $tableId, $userId)
{
    /*
     * Presence is a heartbeat, not game state. Most state polls should not
     * need to lock the shared poker_tables row or every seat at the table.
     *
     * Normal path:
     *   - read only this user's seat
     *   - refresh last_seen_at at most once per heartbeat interval
     *
     * Reconnect-grace path:
     *   - use the original transactional locking behavior, but lock only the
     *     table row and this player's seat before restoring the turn timer
     */
    $stmt = $db->prepare("SELECT seat_no,
                                reconnect_grace_until,
                                CASE
                                    WHEN reconnect_grace_until IS NULL THEN 0
                                    ELSE GREATEST(0, CEIL(TIMESTAMPDIFF(MICROSECOND, NOW(), reconnect_grace_until) / 1000000))
                                END AS reconnect_seconds_left
                         FROM poker_seats
                         WHERE table_id=? AND user_id=?
                         LIMIT 1");
    $stmt->bind_param('ii', $tableId, $userId);
    $stmt->execute();
    $seat = $stmt->get_result()->fetch_assoc();
    $stmt->close();

    if (!$seat) {
        return;
    }

    $seatNo = (int) $seat['seat_no'];
    $returningDuringGrace = (
        !empty($seat['reconnect_grace_until']) &&
        (int) $seat['reconnect_seconds_left'] > 0
    );

    if (!$returningDuringGrace) {
        $heartbeatSeconds = max(1, (int) POKER_PRESENCE_HEARTBEAT_SECONDS);
        $sql = 'UPDATE poker_seats
                SET last_seen_at=NOW()
                WHERE table_id=?
                  AND user_id=?
                  AND (last_seen_at IS NULL OR last_seen_at < DATE_SUB(NOW(), INTERVAL ' . $heartbeatSeconds . ' SECOND))';
        $stmt = $db->prepare($sql);
        $stmt->bind_param('ii', $tableId, $userId);
        $stmt->execute();
        $stmt->close();
        return;
    }

    $db->begin_transaction();

    try {
        $table = poker_table_for_update($db, $tableId);
        if (!$table) {
            $db->commit();
            return;
        }

        $stmt = $db->prepare("SELECT seat_no,
                                    reconnect_grace_until,
                                    CASE
                                        WHEN reconnect_grace_until IS NULL THEN 0
                                        ELSE GREATEST(0, CEIL(TIMESTAMPDIFF(MICROSECOND, NOW(), reconnect_grace_until) / 1000000))
                                    END AS reconnect_seconds_left
                             FROM poker_seats
                             WHERE table_id=? AND user_id=?
                             LIMIT 1
                             FOR UPDATE");
        $stmt->bind_param('ii', $tableId, $userId);
        $stmt->execute();
        $lockedSeat = $stmt->get_result()->fetch_assoc();
        $stmt->close();

        if (!$lockedSeat) {
            $db->commit();
            return;
        }

        $seatNo = (int) $lockedSeat['seat_no'];
        $stillReturningDuringGrace = (
            $table['status'] === 'playing' &&
            $table['current_turn'] !== null &&
            (int) $table['current_turn'] === $seatNo &&
            !empty($lockedSeat['reconnect_grace_until']) &&
            (int) $lockedSeat['reconnect_seconds_left'] > 0
        );

        if ($stillReturningDuringGrace) {
            $stmt = $db->prepare('UPDATE poker_seats
                SET last_seen_at=NOW(), reconnect_grace_until=NULL
                WHERE table_id=? AND seat_no=?');
            $stmt->bind_param('ii', $tableId, $seatNo);
            $stmt->execute();
            $stmt->close();

            $stmt = $db->prepare('UPDATE poker_tables
                SET turn_expires_at=DATE_ADD(NOW(), INTERVAL 30 SECOND)
                WHERE id=? AND current_turn=?');
            $stmt->bind_param('ii', $tableId, $seatNo);
            $stmt->execute();
            $stmt->close();
        } else {
            /* Grace changed while we were acquiring locks; record presence only. */
            $stmt = $db->prepare('UPDATE poker_seats
                SET last_seen_at=NOW()
                WHERE table_id=? AND seat_no=?');
            $stmt->bind_param('ii', $tableId, $seatNo);
            $stmt->execute();
            $stmt->close();
        }

        $db->commit();
    } catch (Throwable $e) {
        $db->rollback();
        throw $e;
    }
}

function poker_maybe_start_reconnect_grace($db, $tableId)
{
    $db->begin_transaction();

    try {
        $table = poker_table_for_update($db, $tableId);

        if (
            !$table ||
            $table['status'] !== 'playing' ||
            $table['current_turn'] === null ||
            empty($table['turn_expires_at']) ||
            empty($table['turn_expired'])
        ) {
            $db->commit();
            return false;
        }

        $seats = poker_seats($db, $tableId, true);
        $turnSeat = (int) $table['current_turn'];

        if (!isset($seats[$turnSeat])) {
            $db->commit();
            return false;
        }

        $seat = $seats[$turnSeat];

        if (
            !empty($seat['reconnect_grace_used']) ||
            !empty($seat['reconnect_grace_until']) ||
            (int) $seat['presence_age_seconds'] <= (int) POKER_PRESENCE_STALE_SECONDS
        ) {
            $db->commit();
            return false;
        }

        $stmt = $db->prepare('UPDATE poker_seats
            SET reconnect_grace_until=DATE_ADD(NOW(), INTERVAL 15 SECOND),
                reconnect_grace_used=1
            WHERE table_id=? AND seat_no=?');
        $stmt->bind_param('ii', $tableId, $turnSeat);
        $stmt->execute();
        $stmt->close();

        $stmt = $db->prepare('UPDATE poker_tables
            SET turn_expires_at=DATE_ADD(NOW(), INTERVAL 15 SECOND)
            WHERE id=? AND current_turn=?');
        $stmt->bind_param('ii', $tableId, $turnSeat);
        $stmt->execute();
        $stmt->close();

        $db->commit();
        return true;
    } catch (Throwable $e) {
        $db->rollback();
        throw $e;
    }
}

function poker_set_sitting_out($db, $tableId, $userId, $sittingOut)
{
    $sittingOut = $sittingOut ? 1 : 0;

    $db->begin_transaction();
    try {
        $table = poker_table_for_update($db, $tableId);
        if (!$table) {
            throw new RuntimeException('Poker table not found.');
        }
        if (poker_is_tournament_table($table) || poker_is_house_table($table)) {
            throw new RuntimeException(poker_is_house_table($table) ? 'Sit Out is disabled at House tables.' : 'Sit Out is disabled for tournament tables.');
        }

        $seats = poker_seats($db, $tableId, true);
        $seatNo = poker_find_user_seat($seats, $userId);

        if ($seatNo === null) {
            throw new RuntimeException('You are not seated at this table.');
        }

        $stmt = $db->prepare('UPDATE poker_seats SET sitting_out=? WHERE table_id=? AND seat_no=?');
        $stmt->bind_param('iii', $sittingOut, $tableId, $seatNo);
        $stmt->execute();
        $stmt->close();

        $seatName = (string) $seats[$seatNo]['username'];
        if ($sittingOut) {
            poker_dealer_message(
                $db,
                $tableId,
                $seatName . ($table['status'] === 'playing' ? ' will sit out after this hand.' : ' is sitting out.')
            );
        } else {
            poker_dealer_message($db, $tableId, $seatName . ' is back in and ready for the next hand.');
        }

        $db->commit();
    } catch (Throwable $e) {
        $db->rollback();
        throw $e;
    }
}

function poker_leave($db, $tableId, $userId)
{
    $db->begin_transaction();
    try {
        $table = poker_table_for_update($db, $tableId);
        $seats = poker_seats($db, $tableId, true);
        $seatNo = poker_find_user_seat($seats, $userId);
        if ($seatNo === null) {
            throw new RuntimeException('You are not seated at this table.');
        }
        if ($table['status'] === 'playing' && poker_active_for_hand($seats[$seatNo])) {
            throw new RuntimeException('You cannot leave during an active hand. Fold first, then leave after the hand.');
        }
        $stack = (int) $seats[$seatNo]['stack'];
        $leavingUsername = (string) $seats[$seatNo]['username'];
        $isTournament = poker_is_tournament_table($table);
        $isHouse = poker_is_house_table($table);

        if ($isTournament && (string)$table['tournament_status'] === 'running' && $stack > 0) {
            throw new RuntimeException('You cannot leave an active tournament unless you have been eliminated.');
        }

        if ($isTournament && (string)$table['tournament_status'] === 'registration') {
            $refund=(int)$table['tournament_entry_fee'];
            $stmt=$db->prepare('UPDATE users SET uploaded=uploaded+? WHERE id=?');
            $stmt->bind_param('ii',$refund,$userId); $stmt->execute(); $stmt->close();
            $stmt=$db->prepare('UPDATE poker_tables SET tournament_prize_pool=GREATEST(0,tournament_prize_pool-?), tournament_entries=GREATEST(0,tournament_entries-1) WHERE id=?');
            $stmt->bind_param('ii',$refund,$tableId); $stmt->execute(); $stmt->close();
        } elseif (!$isTournament) {
            $stmt = $db->prepare('UPDATE users SET uploaded = uploaded + ? WHERE id=?');
            $stmt->bind_param('ii', $stack, $userId);
            $stmt->execute();
            $stmt->close();
        }

        $stmt = $db->prepare('DELETE FROM poker_seats WHERE table_id=? AND seat_no=?');
        $stmt->bind_param('ii', $tableId, $seatNo);
        $stmt->execute();
        $stmt->close();

        if ($isHouse) {
            $botUserId = 0;
            $stmt = $db->prepare('DELETE FROM poker_seats WHERE table_id=? AND user_id=?');
            $stmt->bind_param('ii', $tableId, $botUserId);
            $stmt->execute();
            $stmt->close();
        }

        poker_dealer_message(
            $db,
            $tableId,
            $isTournament ? ($leavingUsername . ' leaves the tournament table.') : ($leavingUsername . ' leaves the table with ' . poker_format_bytes($stack) . '.')
        );

        /*
         * Leaving is only allowed outside an active hand. Once somebody
         * leaves, retire the completed hand so a later join starts from a
         * genuinely clean table instead of inheriting showdown state.
         */
        $emptyDeck = json_encode(array());
        $emptyCommunity = json_encode(array());
        $waitingMessage = 'Waiting for players.';

        $stmt = $db->prepare("UPDATE poker_tables
            SET status='waiting', street='preflop', dealer_seat=NULL,
                current_turn=NULL, turn_expires_at=NULL, current_bet=0, min_raise=big_blind,
                deck_json=?, community_json=?, last_message=?
            WHERE id=?");
        $stmt->bind_param('sssi', $emptyDeck, $emptyCommunity, $waitingMessage, $tableId);
        $stmt->execute();
        $stmt->close();

        $stmt = $db->prepare("UPDATE poker_seats
            SET round_bet=0, hand_contribution=0, hand_state='waiting',
                acted=0, hole1=NULL, hole2=NULL
            WHERE table_id=?");
        $stmt->bind_param('i', $tableId);
        $stmt->execute();
        $stmt->close();

        /*
         * If the last player has left, reset the table completely so the
         * next group starts with a clean table. The hand counter is preserved
         * so persistent hand-history identifiers are never reused.
         */
        $stmt = $db->prepare('SELECT COUNT(*) AS players FROM poker_seats WHERE table_id=?');
        $stmt->bind_param('i', $tableId);
        $stmt->execute();
        $remaining = $stmt->get_result()->fetch_assoc();
        $stmt->close();

        if ((int) $remaining['players'] === 0) {
            $stmt = $db->prepare("UPDATE poker_tables
                SET status='waiting', street='preflop', dealer_seat=NULL,
                    current_turn=NULL, turn_expires_at=NULL, current_bet=0, min_raise=big_blind,
                    deck_json=?, community_json=?, last_message=?
                WHERE id=?");
            $stmt->bind_param('sssi', $emptyDeck, $emptyCommunity, $waitingMessage, $tableId);
            $stmt->execute();
            $stmt->close();
        }

        $db->commit();
        poker_finalize_maintenance_if_empty($db);
    } catch (Throwable $e) {
        if ($db->errno === 0) {
            // Transaction may already be committed; rollback is harmless only while active.
        }
        try { $db->rollback(); } catch (Throwable $ignored) {}
        throw $e;
    }
}

function poker_pay_from_stack($db, $tableId, $seatNo, $amount, $markActed)
{
    $stmt = $db->prepare('SELECT stack,round_bet,hand_contribution,hand_state FROM poker_seats WHERE table_id=? AND seat_no=? FOR UPDATE');
    $stmt->bind_param('ii', $tableId, $seatNo);
    $stmt->execute();
    $seat = $stmt->get_result()->fetch_assoc();
    $stmt->close();
    if (!$seat) {
        throw new RuntimeException('Seat disappeared.');
    }
    $amount = min((int) $seat['stack'], max(0, (int) $amount));
    $stack = (int) $seat['stack'] - $amount;
    $roundBet = (int) $seat['round_bet'] + $amount;
    $contrib = (int) $seat['hand_contribution'] + $amount;
    $state = $stack === 0 ? 'allin' : $seat['hand_state'];
    $acted = $markActed ? 1 : 0;
    poker_update_money_state($db, $tableId, $seatNo, $stack, $roundBet, $contrib, $state, $acted);
    return array('paid' => $amount, 'stack' => $stack, 'round_bet' => $roundBet, 'contribution' => $contrib, 'state' => $state);
}

function poker_start_hand($db, $tableId, $userId)
{
    $db->begin_transaction();
    try {
        $settings = poker_lock_settings($db);
        if ((int) $settings['maintenance_mode'] !== 0) {
            throw new RuntimeException('Poker maintenance is pending. No new hands can be started.');
        }

        $table = poker_table_for_update($db, $tableId);
        if (!$table) {
            throw new RuntimeException('Poker table not found.');
        }
        if (poker_is_tournament_table($table) && (string)$table['tournament_status'] !== 'running') {
            throw new RuntimeException((string)$table['tournament_status'] === 'registration' ? 'The tournament has not been started by an administrator yet.' : 'This tournament has finished.');
        }
        if ($table['status'] === 'playing') {
            throw new RuntimeException('A hand is already in progress.');
        }

        if (poker_is_house_table($table)) {
            $botUserId = 0;
            $botStack = (int) $table['max_buyin'];
            $stmt = $db->prepare("UPDATE poker_seats SET stack=?, sitting_out=0 WHERE table_id=? AND user_id=?");
            $stmt->bind_param('iii', $botStack, $tableId, $botUserId);
            $stmt->execute();
            $stmt->close();
        }

        $seats = poker_seats($db, $tableId, true);
        if (poker_find_user_seat($seats, $userId) === null) {
            throw new RuntimeException('Sit at the table before starting a hand.');
        }

        $stmt = $db->prepare('UPDATE poker_seats
            SET reconnect_grace_used=0, reconnect_grace_until=NULL
            WHERE table_id=?');
        $stmt->bind_param('i', $tableId);
        $stmt->execute();
        $stmt->close();

        $seats = poker_seats($db, $tableId, true);

        $eligible = array();
        foreach ($seats as $seatNo => $seat) {
            if ((int) $seat['stack'] > 0 && empty($seat['sitting_out'])) {
                $eligible[$seatNo] = $seat;
            }
        }
        if (count($eligible) < 2) {
            throw new RuntimeException('At least two players who are not sitting out are needed.');
        }

        $oldDealer = $table['dealer_seat'] === null ? 0 : (int) $table['dealer_seat'];
        $dealer = poker_next_matching_seat($eligible, $oldDealer, function ($s) { return (int) $s['stack'] > 0; });
        if ($dealer === null) {
            $dealer = (int) array_key_first($eligible);
        }

        $deck = poker_build_deck();
        foreach ($seats as $seatNo => $seat) {
            if (isset($eligible[$seatNo])) {
                $h1 = poker_take_card($deck);
                $h2 = poker_take_card($deck);
                poker_set_seat_hand($db, $tableId, $seatNo, (int) $seat['stack'], 0, 0, 'active', 0, $h1, $h2);
            } else {
                poker_set_seat_hand($db, $tableId, $seatNo, (int) $seat['stack'], 0, 0, 'waiting', 0, null, null);
            }
        }

        $count = count($eligible);
        if ($count === 2) {
            $sb = $dealer;
            $bb = poker_next_matching_seat($eligible, $sb, function ($s) { return (int) $s['stack'] > 0; });
            $turnStart = $sb;
        } else {
            $sb = poker_next_matching_seat($eligible, $dealer, function ($s) { return (int) $s['stack'] > 0; });
            $bb = poker_next_matching_seat($eligible, $sb, function ($s) { return (int) $s['stack'] > 0; });
            $turnStart = $bb;
        }

        $handNo = (int) $table['hand_no'] + 1;
        $blindLevel = poker_blinds_for_hand(
            $handNo,
            (int) $table['starting_small_blind'],
            (int) $table['starting_big_blind'],
            (int) $table['blind_hands_per_level']
        );
        $smallBlind = (int) $blindLevel['small_blind'];
        $bigBlind = (int) $blindLevel['big_blind'];

        $sbPaid = poker_pay_from_stack($db, $tableId, $sb, $smallBlind, false);
        $bbPaid = poker_pay_from_stack($db, $tableId, $bb, $bigBlind, false);
        $seats = poker_seats($db, $tableId, true);
        $turn = poker_next_matching_seat($seats, $turnStart, 'poker_can_act');

        $currentBet = max($sbPaid['round_bet'], $bbPaid['round_bet']);
        $minRaise = $bigBlind;
        $deckJson = json_encode(array_values($deck));
        $communityJson = json_encode(array());
        $msg = 'Hand #' . $handNo . ' started. Blinds ' . poker_format_table_amount($table,$smallBlind) . ' / ' . poker_format_table_amount($table,$bigBlind) . '.';

        $stmt = $db->prepare("UPDATE poker_tables SET status='playing',dealer_seat=?,current_turn=?,turn_expires_at=DATE_ADD(NOW(), INTERVAL 30 SECOND),street='preflop',current_bet=?,min_raise=?,small_blind=?,big_blind=?,deck_json=?,community_json=?,hand_no=?,last_message=? WHERE id=?");
        $stmt->bind_param('iiiiiissisi', $dealer, $turn, $currentBet, $minRaise, $smallBlind, $bigBlind, $deckJson, $communityJson, $handNo, $msg, $tableId);
        $stmt->execute();
        $stmt->close();

        poker_log_action($db, $tableId, $handNo, (int) $seats[$sb]['user_id'], $sb, 'small_blind', $sbPaid['paid']);
        poker_log_action($db, $tableId, $handNo, (int) $seats[$bb]['user_id'], $bb, 'big_blind', $bbPaid['paid']);

        poker_history_start(
            $db,
            $tableId,
            $handNo,
            $dealer,
            $smallBlind,
            $bigBlind,
            $seats
        );

        poker_dealer_message(
            $db,
            $tableId,
            'Hand #' . $handNo . ' begins. Blinds are ' . poker_format_table_amount($table,$smallBlind) . ' / ' . poker_format_table_amount($table,$bigBlind) . '.'
        );

        if ($turn === null) {
            $table['deck_json'] = $deckJson;
            $table['community_json'] = $communityJson;
            $table['street'] = 'preflop';
            $table['dealer_seat'] = $dealer;
            $table['hand_no'] = $handNo;
            $seats = poker_seats($db, $tableId, true);
            poker_advance_street_or_showdown($db, $tableId, $table, $seats);
        }

        $db->commit();
    } catch (Throwable $e) {
        $db->rollback();
        throw $e;
    }
}

function poker_round_complete($seats, $currentBet)
{
    $canAct = 0;
    foreach ($seats as $seat) {
        if ($seat['hand_state'] === 'active') {
            $canAct++;
            if (!(int) $seat['acted'] || (int) $seat['round_bet'] !== (int) $currentBet) {
                return false;
            }
        }
    }
    return $canAct === 0 || true;
}

function poker_nonfolded_count($seats)
{
    $n = 0;
    foreach ($seats as $seat) {
        if (in_array($seat['hand_state'], array('active','allin'), true)) {
            $n++;
        }
    }
    return $n;
}

function poker_first_nonfolded($seats)
{
    foreach ($seats as $seatNo => $seat) {
        if (in_array($seat['hand_state'], array('active','allin'), true)) {
            return $seatNo;
        }
    }
    return null;
}

function poker_all_remaining_allin($seats)
{
    $remaining = 0;
    foreach ($seats as $seat) {
        if (in_array($seat['hand_state'], array('active','allin'), true)) {
            $remaining++;
            if ($seat['hand_state'] === 'active' && (int) $seat['stack'] > 0) {
                return false;
            }
        }
    }
    return $remaining >= 2;
}

function poker_record_player_stats($db, $seats, $awards, $showdownReached)
{
    foreach ($seats as $seatNo => $seat) {
        if (!isset($seat['user_id']) || empty($seat['user_id'])) {
            continue;
        }

        $handState = isset($seat['hand_state']) ? (string) $seat['hand_state'] : 'waiting';

        /*
         * Sitting-out / waiting seats were not dealt into this hand and
         * therefore do not count as hands played.
         */
        if ($handState === 'waiting') {
            continue;
        }

        $userId = (int) $seat['user_id'];
        $contribution = isset($seat['hand_contribution']) ? (int) $seat['hand_contribution'] : 0;
        $award = isset($awards[$seatNo]) ? (int) $awards[$seatNo] : 0;

        $handsPlayed = 1;
        $handsWon = $award > 0 ? 1 : 0;
        $folds = $handState === 'folded' ? 1 : 0;
        $allins = $handState === 'allin' ? 1 : 0;

        $reachedShowdown = $showdownReached
            && in_array($handState, array('active', 'allin'), true);

        $showdownsSeen = $reachedShowdown ? 1 : 0;
        $showdownsWon = ($reachedShowdown && $award > 0) ? 1 : 0;
        $totalWon = max(0, $award);
        $netProfit = $award - $contribution;
        $biggestPot = max(0, $award);

        $stmt = $db->prepare("
            INSERT INTO poker_player_stats
                (
                    user_id,
                    hands_played,
                    hands_won,
                    folds,
                    allins,
                    showdowns_seen,
                    showdowns_won,
                    total_won,
                    net_profit,
                    biggest_pot,
                    updated_at
                )
            VALUES
                (?,?,?,?,?,?,?,?,?,?,NOW())
            ON DUPLICATE KEY UPDATE
                hands_played = hands_played + VALUES(hands_played),
                hands_won = hands_won + VALUES(hands_won),
                folds = folds + VALUES(folds),
                allins = allins + VALUES(allins),
                showdowns_seen = showdowns_seen + VALUES(showdowns_seen),
                showdowns_won = showdowns_won + VALUES(showdowns_won),
                total_won = total_won + VALUES(total_won),
                net_profit = net_profit + VALUES(net_profit),
                biggest_pot = GREATEST(biggest_pot, VALUES(biggest_pot)),
                updated_at = NOW()
        ");

        $stmt->bind_param(
            'iiiiiiiiii',
            $userId,
            $handsPlayed,
            $handsWon,
            $folds,
            $allins,
            $showdownsSeen,
            $showdownsWon,
            $totalWon,
            $netProfit,
            $biggestPot
        );
        $stmt->execute();
        $stmt->close();
    }
}

function poker_player_stats_row($db, $userId)
{
    $stmt = $db->prepare("
        SELECT
            ps.*,
            u.username
        FROM poker_player_stats ps
        INNER JOIN users u ON u.id = ps.user_id
        WHERE ps.user_id=?
        LIMIT 1
    ");
    $stmt->bind_param('i', $userId);
    $stmt->execute();
    $row = $stmt->get_result()->fetch_assoc();
    $stmt->close();

    return $row ?: null;
}

function poker_player_profile_row($db, $userId)
{
    $stmt = $db->prepare("\n        SELECT\n            u.id AS user_id,\n            u.username,\n            u.avatar,\n            COALESCE(ps.hands_played, 0) AS hands_played,\n            COALESCE(ps.hands_won, 0) AS hands_won,\n            COALESCE(ps.folds, 0) AS folds,\n            COALESCE(ps.allins, 0) AS allins,\n            COALESCE(ps.showdowns_seen, 0) AS showdowns_seen,\n            COALESCE(ps.showdowns_won, 0) AS showdowns_won,\n            COALESCE(ps.total_won, 0) AS total_won,\n            COALESCE(ps.net_profit, 0) AS net_profit,\n            COALESCE(ps.biggest_pot, 0) AS biggest_pot,\n            ps.updated_at\n        FROM users u\n        LEFT JOIN poker_player_stats ps ON ps.user_id = u.id\n        WHERE u.id=?\n        LIMIT 1\n    ");
    $stmt->bind_param('i', $userId);
    $stmt->execute();
    $row = $stmt->get_result()->fetch_assoc();
    $stmt->close();

    return $row ?: null;
}

function poker_achievement_definitions()
{
    return array(
        array('id' => 'first_hand', 'name' => 'Ante Up', 'description' => 'Complete your first poker hand.', 'field' => 'hands_played', 'target' => 1, 'kind' => 'count'),
        array('id' => 'table_regular', 'name' => 'Table Regular', 'description' => 'Complete 50 poker hands.', 'field' => 'hands_played', 'target' => 50, 'kind' => 'count'),
        array('id' => 'poker_veteran', 'name' => 'Poker Veteran', 'description' => 'Complete 250 poker hands.', 'field' => 'hands_played', 'target' => 250, 'kind' => 'count'),
        array('id' => 'first_win', 'name' => 'First Blood', 'description' => 'Win your first poker hand.', 'field' => 'hands_won', 'target' => 1, 'kind' => 'count'),
        array('id' => 'winning_habit', 'name' => 'Winning Habit', 'description' => 'Win 25 poker hands.', 'field' => 'hands_won', 'target' => 25, 'kind' => 'count'),
        array('id' => 'century_club', 'name' => 'Century Club', 'description' => 'Win 100 poker hands.', 'field' => 'hands_won', 'target' => 100, 'kind' => 'count'),
        array('id' => 'showdown_bound', 'name' => 'Showdown Bound', 'description' => 'Reach 25 showdowns.', 'field' => 'showdowns_seen', 'target' => 25, 'kind' => 'count'),
        array('id' => 'closer', 'name' => 'The Closer', 'description' => 'Win 25 showdowns.', 'field' => 'showdowns_won', 'target' => 25, 'kind' => 'count'),
        array('id' => 'all_in_artist', 'name' => 'All-In Artist', 'description' => 'Go all-in 10 times.', 'field' => 'allins', 'target' => 10, 'kind' => 'count'),
        array('id' => 'pot_hunter', 'name' => 'Pot Hunter', 'description' => 'Win a pot worth at least 1 GB.', 'field' => 'biggest_pot', 'target' => GB, 'kind' => 'bytes'),
        array('id' => 'high_roller', 'name' => 'High Roller', 'description' => 'Win a pot worth at least 10 GB.', 'field' => 'biggest_pot', 'target' => 10 * GB, 'kind' => 'bytes'),
        array('id' => 'in_the_black', 'name' => 'In the Black', 'description' => 'Reach a positive career net profit.', 'field' => 'net_profit', 'target' => 1, 'kind' => 'signed_bytes')
    );
}

function poker_player_achievements($stats)
{
    $stats = is_array($stats) ? $stats : array();
    $items = array();

    foreach (poker_achievement_definitions() as $definition) {
        $field = $definition['field'];
        $value = isset($stats[$field]) ? (int) $stats[$field] : 0;
        $target = (int) $definition['target'];
        $unlocked = $value >= $target;
        $progress = $target > 0 ? min(100, max(0, ($value / $target) * 100)) : 0;

        if ($definition['kind'] === 'bytes') {
            $progressText = poker_format_bytes(max(0, $value)) . ' / ' . poker_format_bytes($target);
        } elseif ($definition['kind'] === 'signed_bytes') {
            $progressText = $unlocked ? poker_format_signed_bytes($value) : 'Reach positive net profit';
        } else {
            $progressText = number_format(max(0, $value)) . ' / ' . number_format($target);
        }

        $definition['value'] = $value;
        $definition['unlocked'] = $unlocked;
        $definition['progress'] = $progress;
        $definition['progress_text'] = $progressText;
        $items[] = $definition;
    }

    return $items;
}

function poker_achievement_count($stats)
{
    $count = 0;
    foreach (poker_player_achievements($stats) as $achievement) {
        if (!empty($achievement['unlocked'])) {
            $count++;
        }
    }
    return $count;
}

function poker_stats_win_rate($handsWon, $handsPlayed)
{
    $handsPlayed = (int) $handsPlayed;

    if ($handsPlayed <= 0) {
        return '0.0%';
    }

    return number_format(((int) $handsWon / $handsPlayed) * 100, 1) . '%';
}

function poker_stats_showdown_rate($showdownsWon, $showdownsSeen)
{
    $showdownsSeen = (int) $showdownsSeen;

    if ($showdownsSeen <= 0) {
        return '0.0%';
    }

    return number_format(((int) $showdownsWon / $showdownsSeen) * 100, 1) . '%';
}

function poker_format_signed_bytes($bytes)
{
    $bytes = (int) $bytes;

    if ($bytes > 0) {
        return '+' . poker_format_bytes($bytes);
    }

    if ($bytes < 0) {
        return '-' . poker_format_bytes(abs($bytes));
    }

    return poker_format_bytes(0);
}

function poker_leaderboard_rows($db, $orderBy, $limit = 25)
{
    $allowed = array(
        'net_profit' => 'ps.net_profit DESC, ps.hands_won DESC, ps.hands_played DESC',
        'hands_won' => 'ps.hands_won DESC, ps.net_profit DESC, ps.hands_played DESC',
        'biggest_pot' => 'ps.biggest_pot DESC, ps.net_profit DESC, ps.hands_won DESC',
        'hands_played' => 'ps.hands_played DESC, ps.hands_won DESC, ps.net_profit DESC'
    );

    if (!isset($allowed[$orderBy])) {
        $orderBy = 'net_profit';
    }

    $limit = max(1, min(100, (int) $limit));
    $sql = "
        SELECT
            ps.user_id,
            u.username,
            ps.hands_played,
            ps.hands_won,
            ps.folds,
            ps.allins,
            ps.showdowns_seen,
            ps.showdowns_won,
            ps.total_won,
            ps.net_profit,
            ps.biggest_pot,
            ps.updated_at
        FROM poker_player_stats ps
        INNER JOIN users u ON u.id = ps.user_id
        ORDER BY " . $allowed[$orderBy] . "
        LIMIT " . $limit;

    $result = $db->query($sql);
    $rows = array();

    while ($row = $result->fetch_assoc()) {
        $rows[] = $row;
    }

    $result->free();

    return $rows;
}

function poker_award_uncontested($db, $tableId, $table, $seats)
{
    $winnerSeat = poker_first_nonfolded($seats);
    if ($winnerSeat === null) {
        throw new RuntimeException('No winner found.');
    }
    $pot = 0;
    foreach ($seats as $seat) {
        $pot += (int) $seat['hand_contribution'];
    }
    $stmt = $db->prepare('UPDATE poker_seats SET stack=stack+? WHERE table_id=? AND seat_no=?');
    $stmt->bind_param('iii', $pot, $tableId, $winnerSeat);
    $stmt->execute();
    $stmt->close();
    $community = json_decode((string) $table['community_json'], true);
    if (!is_array($community)) {
        $community = array();
    }

    if (!poker_is_tournament_table($table) && !poker_is_house_table($table)) {
        poker_record_player_stats(
            $db,
            $seats,
            array($winnerSeat => $pot),
            false
        );
    }

    poker_finish_hand(
        $db,
        $tableId,
        (int) $table['hand_no'],
        $community,
        $seats[$winnerSeat]['username'] . ' wins ' . poker_format_table_amount($table,$pot) . ' (everyone else folded).',
        $seats
    );
    poker_tournament_finalize_if_winner($db,$tableId);
}

function poker_finish_hand($db, $tableId, $handNo, $community, $message, $seats)
{
    $stmt = $db->prepare("UPDATE poker_tables SET status='showdown',current_turn=NULL,turn_expires_at=NULL,current_bet=0,min_raise=big_blind,last_message=? WHERE id=?");
    $stmt->bind_param('si', $message, $tableId);
    $stmt->execute();
    $stmt->close();

    $stmt = $db->prepare("UPDATE poker_seats SET round_bet=0,acted=0,reconnect_grace_until=NULL WHERE table_id=?");
    $stmt->bind_param('i', $tableId);
    $stmt->execute();
    $stmt->close();

    poker_history_finish($db, $tableId, $handNo, $community, $message, $seats, false);
    poker_dealer_message($db, $tableId, $message);
}

function poker_advance_street_or_showdown($db, $tableId, $table, $seats)
{
    if (poker_nonfolded_count($seats) <= 1) {
        poker_award_uncontested($db, $tableId, $table, $seats);
        return;
    }

    $deck = json_decode((string) $table['deck_json'], true);
    $community = json_decode((string) $table['community_json'], true);
    if (!is_array($deck)) $deck = array();
    if (!is_array($community)) $community = array();

    $street = $table['street'];
    if ($street === 'preflop') {
        $community[] = poker_take_card($deck);
        $community[] = poker_take_card($deck);
        $community[] = poker_take_card($deck);
        $nextStreet = 'flop';
    } elseif ($street === 'flop') {
        $community[] = poker_take_card($deck);
        $nextStreet = 'turn';
    } elseif ($street === 'turn') {
        $community[] = poker_take_card($deck);
        $nextStreet = 'river';
    } else {
        poker_showdown($db, $tableId, $table, $seats, $community);
        return;
    }

    $stmt = $db->prepare("UPDATE poker_seats SET round_bet=0,acted=0,reconnect_grace_until=NULL WHERE table_id=? AND hand_state='active'");
    $stmt->bind_param('i', $tableId);
    $stmt->execute();
    $stmt->close();

    $seats = poker_seats($db, $tableId, true);
    if (poker_all_remaining_allin($seats)) {
        while (count($community) < 5) {
            $community[] = poker_take_card($deck);
        }
        $table['deck_json'] = json_encode($deck);
        $table['community_json'] = json_encode($community);
        poker_showdown($db, $tableId, $table, $seats, $community);
        return;
    }

    $turn = poker_next_matching_seat($seats, (int) $table['dealer_seat'], 'poker_can_act');
    $deckJson = json_encode(array_values($deck));
    $communityJson = json_encode(array_values($community));
    $msg = ucfirst($nextStreet) . '.';
    $stmt = $db->prepare('UPDATE poker_tables SET street=?,current_bet=0,min_raise=big_blind,current_turn=?,turn_expires_at=DATE_ADD(NOW(), INTERVAL 30 SECOND),deck_json=?,community_json=?,last_message=? WHERE id=?');
    $stmt->bind_param('sisssi', $nextStreet, $turn, $deckJson, $communityJson, $msg, $tableId);
    $stmt->execute();
    $stmt->close();

    poker_history_update_board($db, $tableId, (int) $table['hand_no'], $community);
}

function poker_action($db, $tableId, $userId, $action, $raiseTo, $automatic = false)
{
    $allowed = array('fold','check','call','raise','allin');
    if (!$automatic && !in_array($action, $allowed, true)) {
        throw new RuntimeException('Unknown poker action.');
    }

    $db->begin_transaction();
    try {
        $table = poker_table_for_update($db, $tableId);
        if (!$table || $table['status'] !== 'playing') {
            throw new RuntimeException('There is no active hand.');
        }
        $seats = poker_seats($db, $tableId, true);
        $seatNo = poker_find_user_seat($seats, $userId);
        if ($seatNo === null || (int) $table['current_turn'] !== $seatNo) {
            throw new RuntimeException('It is not your turn.');
        }
        $seat = $seats[$seatNo];
        if (!poker_can_act($seat)) {
            throw new RuntimeException('You cannot act right now.');
        }

        $currentBet = (int) $table['current_bet'];
        $roundBet = (int) $seat['round_bet'];
        $stack = (int) $seat['stack'];
        $call = max(0, $currentBet - $roundBet);
        $paid = 0;
        $message = '';
        $newCurrentBet = $currentBet;
        $newMinRaise = (int) $table['min_raise'];
        $fullRaise = false;

        if ($automatic) {
            $action = ($call === 0) ? 'check' : 'fold';
        }

        if ($action === 'fold') {
            $stmt = $db->prepare("UPDATE poker_seats SET hand_state='folded',acted=1 WHERE table_id=? AND seat_no=?");
            $stmt->bind_param('ii', $tableId, $seatNo);
            $stmt->execute();
            $stmt->close();
            $message = $seat['username'] . ($automatic ? ' folds (time expired).' : ' folds.');
        } elseif ($action === 'check') {
            if ($call !== 0) {
                throw new RuntimeException('You cannot check. There is ' . poker_format_table_amount($table,$call) . ' to call.');
            }
            $stmt = $db->prepare('UPDATE poker_seats SET acted=1 WHERE table_id=? AND seat_no=?');
            $stmt->bind_param('ii', $tableId, $seatNo);
            $stmt->execute();
            $stmt->close();
            $message = $seat['username'] . ($automatic ? ' checks (time expired).' : ' checks.');
        } elseif ($action === 'call') {
            if ($call === 0) {
                throw new RuntimeException('There is nothing to call.');
            }
            $r = poker_pay_from_stack($db, $tableId, $seatNo, $call, true);
            $paid = $r['paid'];
            $message = $seat['username'] . (($r['state'] === 'allin') ? ' calls all-in for ' : ' calls ') . poker_format_table_amount($table,$paid) . '.';
        } elseif ($action === 'allin') {
            $oldBet = $roundBet;
            $tableWagerRoom = poker_is_tournament_table($table) ? $stack : max(0, (int) $table['max_buyin'] - (int) $seat['hand_contribution']);
            $allInAmount = min($stack, $tableWagerRoom);
            if ($allInAmount <= 0) {
                throw new RuntimeException('You have reached this table\'s maximum wager for the hand.');
            }
            $r = poker_pay_from_stack($db, $tableId, $seatNo, $allInAmount, true);
            $paid = $r['paid'];
            $resultingBet = $oldBet + $paid;
            if ($resultingBet > $currentBet) {
                $raiseSize = $resultingBet - $currentBet;
                if ($raiseSize >= $newMinRaise) {
                    $fullRaise = true;
                    $newMinRaise = $raiseSize;
                }
                $newCurrentBet = $resultingBet;
            }
            $message = $seat['username'] . ' is all-in for ' . poker_format_table_amount($table,$resultingBet) . '.';
        } else {
            $raiseTo = (int) $raiseTo;
            $tableWagerRoom = poker_is_tournament_table($table) ? $stack : max(0, (int) $table['max_buyin'] - (int) $seat['hand_contribution']);
            $maxTo = $roundBet + min($stack, $tableWagerRoom);
            if ($raiseTo <= $currentBet) {
                throw new RuntimeException('Raise-to amount must be above the current bet.');
            }
            if ($raiseTo > $maxTo) {
                throw new RuntimeException('You do not have enough chips for that raise.');
            }
            $raiseSize = $raiseTo - $currentBet;
            if ($raiseSize < $newMinRaise && $raiseTo !== $maxTo) {
                throw new RuntimeException('Minimum raise is ' . poker_format_table_amount($table,$newMinRaise) . '.');
            }
            $needed = $raiseTo - $roundBet;
            $r = poker_pay_from_stack($db, $tableId, $seatNo, $needed, true);
            $paid = $r['paid'];
            $newCurrentBet = $raiseTo;
            if ($raiseSize >= $newMinRaise) {
                $fullRaise = true;
                $newMinRaise = $raiseSize;
            }
            $message = $seat['username'] . ' raises to ' . poker_format_table_amount($table,$raiseTo) . '.';
        }

        if ($fullRaise) {
            $stmt = $db->prepare("UPDATE poker_seats SET acted=0 WHERE table_id=? AND seat_no<>? AND hand_state='active'");
            $stmt->bind_param('ii', $tableId, $seatNo);
            $stmt->execute();
            $stmt->close();
        }

        $loggedAction = $automatic ? ('auto_' . $action) : $action;
        poker_log_action($db, $tableId, (int) $table['hand_no'], $userId, $seatNo, $loggedAction, $paid);
        $stmt = $db->prepare('UPDATE poker_tables SET current_bet=?,min_raise=?,last_message=? WHERE id=?');
        $stmt->bind_param('iisi', $newCurrentBet, $newMinRaise, $message, $tableId);
        $stmt->execute();
        $stmt->close();

        $table['current_bet'] = $newCurrentBet;
        $table['min_raise'] = $newMinRaise;
        $table['last_message'] = $message;
        $seats = poker_seats($db, $tableId, true);

        if (poker_nonfolded_count($seats) <= 1) {
            poker_award_uncontested($db, $tableId, $table, $seats);
            $db->commit();
            return;
        }

        if (poker_round_complete($seats, $newCurrentBet)) {
            poker_advance_street_or_showdown($db, $tableId, $table, $seats);
            $db->commit();
            return;
        }

        $turn = poker_next_matching_seat($seats, $seatNo, function ($s) use ($newCurrentBet) {
            return poker_can_act($s) && (!(int) $s['acted'] || (int) $s['round_bet'] < $newCurrentBet);
        });
        if ($turn === null) {
            poker_advance_street_or_showdown($db, $tableId, $table, $seats);
        } else {
            $stmt = $db->prepare('UPDATE poker_tables SET current_turn=?,turn_expires_at=DATE_ADD(NOW(), INTERVAL 30 SECOND) WHERE id=?');
            $stmt->bind_param('ii', $turn, $tableId);
            $stmt->execute();
            $stmt->close();
        }
        $db->commit();
    } catch (Throwable $e) {
        $db->rollback();
        throw $e;
    }
}

function poker_house_preflop_strength($hole1, $hole2)
{
    list($r1, $s1) = poker_parse_card($hole1);
    list($r2, $s2) = poker_parse_card($hole2);
    $high = max($r1, $r2);
    $low = min($r1, $r2);
    $pair = $r1 === $r2;
    $suited = $s1 === $s2;
    $gap = abs($r1 - $r2);

    if ($pair) {
        return min(0.98, 0.54 + (($high - 2) / 12) * 0.42);
    }

    $strength = 0.18 + (($high - 2) / 12) * 0.35 + (($low - 2) / 12) * 0.18;
    if ($suited) $strength += 0.07;
    if ($gap === 1) $strength += 0.06;
    elseif ($gap === 2) $strength += 0.03;
    elseif ($gap >= 5) $strength -= 0.05;
    if ($high >= 12 && $low >= 10) $strength += 0.10;
    if ($high === 14) $strength += 0.05;

    return max(0.05, min(0.95, $strength));
}

function poker_house_strength($seat, $community)
{
    if (empty($seat['hole1']) || empty($seat['hole2'])) {
        return 0.0;
    }

    if (count($community) < 3) {
        return poker_house_preflop_strength($seat['hole1'], $seat['hole2']);
    }

    $cards = array_merge(array($seat['hole1'], $seat['hole2']), $community);
    $score = poker_best_score($cards);
    $category = isset($score[0]) ? (int) $score[0] : 0;
    $kicker = isset($score[1]) ? (int) $score[1] : 2;
    $strength = 0.12 + ($category / 8) * 0.76 + (($kicker - 2) / 12) * 0.10;

    return max(0.05, min(0.99, $strength));
}

/*
 * Estimate the House hand's equity against an unknown random opponent hand.
 *
 * IMPORTANT:
 * - This never reads the player's hole cards.
 * - It removes only cards the House is legitimately allowed to know:
 *   its own hole cards and the public board.
 * - It then samples possible opponent cards and remaining board cards.
 *
 * This makes large-bet/all-in decisions behave like poker decisions instead
 * of a crude "strength number + random roll".
 */
function poker_house_estimated_equity($hole1, $hole2, $community, $trials = 180)
{
    $community = is_array($community) ? array_values($community) : array();
    $known = array($hole1, $hole2);
    foreach ($community as $card) {
        if ($card !== null && $card !== '') {
            $known[] = $card;
        }
    }

    $deck = poker_build_deck();
    $deck = array_values(array_diff($deck, $known));

    $boardNeeded = max(0, 5 - count($community));
    $cardsNeeded = 2 + $boardNeeded;

    if (count($deck) < $cardsNeeded) {
        return 0.50;
    }

    $trials = max(40, min(400, (int)$trials));
    $wins = 0;
    $ties = 0;
    $completed = 0;

    for ($i = 0; $i < $trials; $i++) {
        $sample = $deck;
        shuffle($sample);

        $opp1 = array_pop($sample);
        $opp2 = array_pop($sample);
        $board = $community;

        while (count($board) < 5) {
            $board[] = array_pop($sample);
        }

        $houseScore = poker_best_score(array_merge(array($hole1, $hole2), $board));
        $oppScore = poker_best_score(array_merge(array($opp1, $opp2), $board));
        $cmp = poker_compare_score($houseScore, $oppScore);

        if ($cmp > 0) {
            $wins++;
        } elseif ($cmp === 0) {
            $ties++;
        }

        $completed++;
    }

    if ($completed <= 0) {
        return 0.50;
    }

    return ($wins + ($ties * 0.5)) / $completed;
}

function poker_house_hand_pot($state)
{
    return
        (int)$state['player']['hand_contribution'] +
        (int)$state['bot']['hand_contribution'];
}

function poker_house_is_shove($state, $call, $botStack)
{
    if ((string)$state['player']['hand_state'] === 'allin') {
        return true;
    }

    if ($botStack <= 0) {
        return false;
    }

    return ((int)$call / max(1, (int)$botStack)) >= 0.60;
}

function poker_house_play_turn($db, $tableId)
{
    $table = poker_get_table($db, $tableId);
    if (!$table || !poker_is_house_table($table) || $table['status'] !== 'playing' || $table['current_turn'] === null) {
        return false;
    }

    $seats = poker_seats($db, $tableId, false);
    $seatNo = (int) $table['current_turn'];
    if (!isset($seats[$seatNo]) || (int) $seats[$seatNo]['user_id'] !== 0 || !poker_can_act($seats[$seatNo])) {
        return false;
    }

    $seat = $seats[$seatNo];
    $community = json_decode((string) $table['community_json'], true);
    if (!is_array($community)) $community = array();

    $strength = poker_house_strength($seat, $community);
    $currentBet = (int) $table['current_bet'];
    $roundBet = (int) $seat['round_bet'];
    $call = max(0, $currentBet - $roundBet);
    $stack = (int) $seat['stack'];
    $pot = 0;
    foreach ($seats as $s) $pot += (int) $s['hand_contribution'];

    $roll = random_int(1, 1000) / 1000;
    $bluff = $roll < 0.07;
    $action = 'check';
    $raiseTo = 0;

    $room = max(0, (int) $table['max_buyin'] - (int) $seat['hand_contribution']);
    $maxTo = $roundBet + min($stack, $room);
    $minimumRaiseTo = $currentBet + max(1, (int) $table['min_raise']);

    if ($call === 0) {
        if (($strength >= 0.72 || $bluff) && $maxTo > $currentBet) {
            $target = $currentBet + max((int) $table['min_raise'], (int) $table['big_blind']);
            if ($strength >= 0.90) $target += (int) $table['big_blind'];
            $raiseTo = min($maxTo, max($minimumRaiseTo, $target));
            $action = $raiseTo > $currentBet ? 'raise' : 'check';
        }
    } else {
        $potOdds = $call / max(1, $pot + $call);
        if (($strength >= 0.80 || ($bluff && $strength >= 0.30)) && $maxTo >= $minimumRaiseTo && $stack > $call) {
            $target = $minimumRaiseTo + ($strength >= 0.92 ? (int) $table['big_blind'] : 0);
            $raiseTo = min($maxTo, $target);
            $action = $raiseTo > $currentBet ? 'raise' : 'call';
        } elseif ($strength + ($roll * 0.12) >= $potOdds + 0.16 || $call <= (int) $table['big_blind']) {
            $action = 'call';
        } else {
            $action = 'fold';
        }
    }

    poker_action($db, $tableId, 0, $action, $raiseTo, false);
    return true;
}

function poker_rank_value($rank)
{
    $map = array('2'=>2,'3'=>3,'4'=>4,'5'=>5,'6'=>6,'7'=>7,'8'=>8,'9'=>9,'10'=>10,'J'=>11,'Q'=>12,'K'=>13,'A'=>14);
    return isset($map[$rank]) ? $map[$rank] : 0;
}

function poker_parse_card($card)
{
    $suit = substr($card, -1);
    $rank = substr($card, 0, -1);
    return array(poker_rank_value($rank), $suit);
}

function poker_score_five($cards)
{
    $ranks = array(); $suits = array();
    foreach ($cards as $card) {
        list($r, $s) = poker_parse_card($card);
        $ranks[] = $r; $suits[] = $s;
    }
    rsort($ranks, SORT_NUMERIC);
    $counts = array_count_values($ranks);
    arsort($counts, SORT_NUMERIC);
    $unique = array_values(array_unique($ranks));
    rsort($unique, SORT_NUMERIC);
    if (in_array(14, $unique, true)) $unique[] = 1;
    $straightHigh = 0;
    for ($i=0; $i<=count($unique)-5; $i++) {
        if ($unique[$i]-$unique[$i+4] === 4) { $straightHigh=$unique[$i]; break; }
    }
    $flush = count(array_unique($suits)) === 1;
    if ($flush && $straightHigh) return array(8,$straightHigh);

    $groups = array();
    foreach ($counts as $rank=>$cnt) $groups[] = array((int)$cnt,(int)$rank);
    usort($groups, function($a,$b){ return $a[0] === $b[0] ? $b[1]-$a[1] : $b[0]-$a[0]; });

    if ($groups[0][0] === 4) {
        $kick=0; foreach($ranks as $r){ if($r!==$groups[0][1]){$kick=$r;break;} }
        return array(7,$groups[0][1],$kick);
    }
    if ($groups[0][0] === 3 && isset($groups[1]) && $groups[1][0] >= 2) return array(6,$groups[0][1],$groups[1][1]);
    if ($flush) return array_merge(array(5),$ranks);
    if ($straightHigh) return array(4,$straightHigh);
    if ($groups[0][0] === 3) {
        $k=array(); foreach($ranks as $r){if($r!==$groups[0][1])$k[]=$r;} return array(3,$groups[0][1],$k[0],$k[1]);
    }
    if ($groups[0][0] === 2 && isset($groups[1]) && $groups[1][0] === 2) {
        $hi=max($groups[0][1],$groups[1][1]); $lo=min($groups[0][1],$groups[1][1]); $kick=0;
        foreach($ranks as $r){if($r!==$hi && $r!==$lo){$kick=$r;break;}}
        return array(2,$hi,$lo,$kick);
    }
    if ($groups[0][0] === 2) {
        $k=array(); foreach($ranks as $r){if($r!==$groups[0][1])$k[]=$r;} return array(1,$groups[0][1],$k[0],$k[1],$k[2]);
    }
    return array_merge(array(0),$ranks);
}

function poker_compare_score($a, $b)
{
    $n = max(count($a), count($b));
    for ($i=0; $i<$n; $i++) {
        $av = isset($a[$i]) ? $a[$i] : 0;
        $bv = isset($b[$i]) ? $b[$i] : 0;
        if ($av > $bv) return 1;
        if ($av < $bv) return -1;
    }
    return 0;
}

function poker_best_score($cards)
{
    if (count($cards) < 5) throw new RuntimeException('Not enough cards to score hand.');
    $best = null; $n=count($cards);
    for($a=0;$a<$n-4;$a++) for($b=$a+1;$b<$n-3;$b++) for($c=$b+1;$c<$n-2;$c++) for($d=$c+1;$d<$n-1;$d++) for($e=$d+1;$e<$n;$e++) {
        $score=poker_score_five(array($cards[$a],$cards[$b],$cards[$c],$cards[$d],$cards[$e]));
        if($best===null || poker_compare_score($score,$best)>0) $best=$score;
    }
    return $best;
}

function poker_hand_name($score)
{
    $names=array('High Card','Pair','Two Pair','Three of a Kind','Straight','Flush','Full House','Four of a Kind','Straight Flush');
    return isset($names[$score[0]]) ? $names[$score[0]] : 'Hand';
}

function poker_showdown($db, $tableId, $table, $seats, $community)
{
    while (count($community) < 5) {
        $deck = json_decode((string)$table['deck_json'], true);
        $community[] = poker_take_card($deck);
        $table['deck_json'] = json_encode($deck);
    }

    $eligibleScores = array();
    foreach ($seats as $seatNo => $seat) {
        if (in_array($seat['hand_state'], array('active','allin'), true)) {
            $eligibleScores[$seatNo] = poker_best_score(array_merge(array($seat['hole1'],$seat['hole2']), $community));
        }
    }

    $levels = array();
    foreach ($seats as $seat) {
        $c=(int)$seat['hand_contribution']; if($c>0)$levels[$c]=$c;
    }
    sort($levels, SORT_NUMERIC);
    $prev=0; $awards=array();
    foreach($levels as $level){
        $contributors=array();
        $eligible=array();
        foreach($seats as $seatNo=>$seat){
            if((int)$seat['hand_contribution'] >= $level){
                $contributors[]=$seatNo;
                if(isset($eligibleScores[$seatNo]))$eligible[]=$seatNo;
            }
        }
        $pot=($level-$prev)*count($contributors); $prev=$level;
        if($pot<=0 || !$eligible) continue;
        $best=null; $winners=array();
        foreach($eligible as $seatNo){
            $score=$eligibleScores[$seatNo];
            if($best===null || poker_compare_score($score,$best)>0){$best=$score;$winners=array($seatNo);} elseif(poker_compare_score($score,$best)===0){$winners[]=$seatNo;}
        }
        $share=intdiv($pot,count($winners)); $rem=$pot-($share*count($winners));
        foreach($winners as $idx=>$seatNo){$awards[$seatNo]=isset($awards[$seatNo])?$awards[$seatNo]+$share:$share; if($idx===0)$awards[$seatNo]+=$rem;}
    }

    foreach($awards as $seatNo=>$amount){
        $stmt=$db->prepare('UPDATE poker_seats SET stack=stack+? WHERE table_id=? AND seat_no=?');
        $stmt->bind_param('iii',$amount,$tableId,$seatNo); $stmt->execute(); $stmt->close();
    }
    $names=array();
    foreach($awards as $seatNo=>$amount){$names[]=$seats[$seatNo]['username'].' wins '.poker_format_table_amount($table,$amount).' with '.poker_hand_name($eligibleScores[$seatNo]);}
    $communityJson=json_encode(array_values($community));
    $stmt=$db->prepare("UPDATE poker_tables SET community_json=?,deck_json=?,status='showdown',current_turn=NULL,turn_expires_at=NULL,current_bet=0,last_message=? WHERE id=?");
    $deckJson=(string)$table['deck_json']; $message=implode(' / ',$names); $stmt->bind_param('sssi',$communityJson,$deckJson,$message,$tableId); $stmt->execute(); $stmt->close();

    if (!poker_is_tournament_table($table) && !poker_is_house_table($table)) {
        poker_record_player_stats(
            $db,
            $seats,
            $awards,
            true
        );
    }

    poker_history_finish(
        $db,
        $tableId,
        (int) $table['hand_no'],
        $community,
        $message,
        $seats,
        true
    );

    poker_dealer_message($db, $tableId, $message);
    poker_tournament_finalize_if_winner($db,$tableId);

    $stmt=$db->prepare('UPDATE poker_seats SET round_bet=0,acted=0 WHERE table_id=?'); $stmt->bind_param('i',$tableId); $stmt->execute(); $stmt->close();
}

function poker_process_timeout($db, $tableId)
{
    $table = poker_get_table($db, $tableId);

    if (
        !$table ||
        $table['status'] !== 'playing' ||
        $table['current_turn'] === null ||
        empty($table['turn_expires_at'])
    ) {
        return false;
    }

    if ((int) $table['turn_seconds_left'] > 0) {
        return false;
    }

    if (poker_maybe_start_reconnect_grace($db, $tableId)) {
        return false;
    }

    $table = poker_get_table($db, $tableId);
    if ($table && (int) $table['turn_seconds_left'] > 0) {
        return false;
    }

    $seats = poker_seats($db, $tableId, false);
    $turnSeat = (int) $table['current_turn'];

    if (!isset($seats[$turnSeat])) {
        return false;
    }

    $turnUserId = (int) $seats[$turnSeat]['user_id'];

    try {
        return poker_action($db, $tableId, $turnUserId, '', 0, true);
    } catch (RuntimeException $e) {
        /* Another request may have completed the turn after our first read. */
        return false;
    }
}

function poker_dealer_message($db, $tableId, $message)
{
    $message = trim((string) $message);

    if ($message === '') {
        return;
    }

    $userId = 0;
    $username = 'Dealer';

    $stmt = $db->prepare('INSERT INTO poker_chat
        (table_id,user_id,username,message,created_at)
        VALUES (?,?,?,?,NOW())');
    $stmt->bind_param('iiss', $tableId, $userId, $username, $message);
    $stmt->execute();
    $stmt->close();
}

function poker_chat_send($db, $tableId, $userId, $message)
{
    $message = trim((string) $message);
    $message = preg_replace('/\s+/u', ' ', $message);

    if ($message === '') {
        throw new RuntimeException('Type a chat message first.');
    }

    $length = function_exists('mb_strlen') ? mb_strlen($message, 'UTF-8') : strlen($message);
    if ($length > 300) {
        throw new RuntimeException('Chat messages are limited to 300 characters.');
    }

    $table = poker_get_table($db, $tableId);
    if (!$table) {
        throw new RuntimeException('Poker table not found.');
    }

    $seats = poker_seats($db, $tableId, false);
    $seatNo = poker_find_user_seat($seats, $userId);

    if ($seatNo === null || !isset($seats[$seatNo])) {
        throw new RuntimeException('Sit at the table before using poker chat.');
    }

    $stmt = $db->prepare('SELECT id
        FROM poker_chat
        WHERE table_id=? AND user_id=? AND created_at > DATE_SUB(NOW(), INTERVAL 2 SECOND)
        ORDER BY id DESC
        LIMIT 1');
    $stmt->bind_param('ii', $tableId, $userId);
    $stmt->execute();
    $recent = $stmt->get_result()->fetch_assoc();
    $stmt->close();

    if ($recent) {
        throw new RuntimeException('Please wait a moment before sending another message.');
    }

    $username = (string) $seats[$seatNo]['username'];

    $stmt = $db->prepare('INSERT INTO poker_chat
        (table_id,user_id,username,message,created_at)
        VALUES (?,?,?,?,NOW())');
    $stmt->bind_param('iiss', $tableId, $userId, $username, $message);
    $stmt->execute();
    $stmt->close();
}

function poker_chat_messages($db, $tableId, $limit = 40)
{
    $limit = max(1, min(100, (int) $limit));

    $stmt = $db->prepare("SELECT id,user_id,username,message,
                                 DATE_FORMAT(created_at, '%H:%i') AS time_text
                          FROM poker_chat
                          WHERE table_id=?
                          ORDER BY id DESC
                          LIMIT ?");
    $stmt->bind_param('ii', $tableId, $limit);
    $stmt->execute();
    $result = $stmt->get_result();

    $messages = array();
    while ($row = $result->fetch_assoc()) {
        $messages[] = array(
            'id' => (int) $row['id'],
            'user_id' => (int) $row['user_id'],
            'username' => (string) $row['username'],
            'message' => (string) $row['message'],
            'time' => (string) $row['time_text']
        );
    }
    $stmt->close();

    return array_reverse($messages);
}

/*
 * Persistent one-time poker notices.
 * Used by admin actions such as removing a player from a table.
 * Schema is managed separately in poker_user_notices; PHP never creates it.
 */
function poker_create_user_notice($db, $userId, $tableId, $noticeType, $title, $message, $createdBy = null)
{
    $userId = (int) $userId;
    $tableId = $tableId === null ? null : (int) $tableId;
    $createdBy = $createdBy === null ? null : (int) $createdBy;
    $noticeType = trim((string) $noticeType);
    $title = trim((string) $title);
    $message = trim((string) $message);

    if ($userId <= 0) {
        throw new RuntimeException('Invalid poker notice user.');
    }

    if ($noticeType === '') {
        $noticeType = 'info';
    }

    if (strlen($noticeType) > 32) {
        $noticeType = substr($noticeType, 0, 32);
    }
    if (strlen($title) > 100) {
        $title = substr($title, 0, 100);
    }
    if (strlen($message) > 500) {
        $message = substr($message, 0, 500);
    }

    $stmt = $db->prepare('INSERT INTO poker_user_notices
        (user_id, table_id, notice_type, title, message, created_by, created_at)
        VALUES (?, ?, ?, ?, ?, ?, NOW())');
    $stmt->bind_param('iisssi', $userId, $tableId, $noticeType, $title, $message, $createdBy);
    $stmt->execute();
    $noticeId = (int) $db->insert_id;
    $stmt->close();

    return $noticeId;
}

function poker_take_user_notice($db, $userId)
{
    $userId = (int) $userId;
    if ($userId <= 0) {
        return null;
    }

    $db->begin_transaction();

    try {
        $stmt = $db->prepare('SELECT id, table_id, notice_type, title, message, created_by, created_at
            FROM poker_user_notices
            WHERE user_id=?
            ORDER BY id ASC
            LIMIT 1
            FOR UPDATE');
        $stmt->bind_param('i', $userId);
        $stmt->execute();
        $notice = $stmt->get_result()->fetch_assoc();
        $stmt->close();

        if (!$notice) {
            $db->commit();
            return null;
        }

        $noticeId = (int) $notice['id'];
        $stmt = $db->prepare('DELETE FROM poker_user_notices WHERE id=? AND user_id=?');
        $stmt->bind_param('ii', $noticeId, $userId);
        $stmt->execute();
        $stmt->close();

        $db->commit();

        return array(
            'id' => $noticeId,
            'table_id' => $notice['table_id'] === null ? null : (int) $notice['table_id'],
            'type' => (string) $notice['notice_type'],
            'title' => (string) $notice['title'],
            'message' => (string) $notice['message'],
            'created_by' => $notice['created_by'] === null ? null : (int) $notice['created_by'],
            'created_at' => (string) $notice['created_at']
        );
    } catch (Throwable $e) {
        $db->rollback();
        throw $e;
    }
}

function poker_public_state($db, $tableId, $userId)
{
    poker_process_timeout($db, $tableId);

    $table = poker_get_table($db, $tableId);

    if (!$table) {
        throw new RuntimeException('Poker table not found.');
    }

    $seats = poker_seats($db, $tableId, false);

    /*
     * An empty table must always be completely clean.
     * This also repairs stale showdown/community-card data left
     * behind by an older session.
     */
    if (count($seats) === 0) {
        $emptyDeck = '[]';
        $emptyCommunity = '[]';
        $waitingMessage = 'Waiting for players.';

        $stmt = $db->prepare("UPDATE poker_tables
            SET status='waiting',
                street='preflop',
                dealer_seat=NULL,
                current_turn=NULL,
                current_bet=0,
                min_raise=big_blind,
                deck_json=?,
                community_json=?,
                last_message=?
            WHERE id=?");
        $stmt->bind_param('sssi', $emptyDeck, $emptyCommunity, $waitingMessage, $tableId);
        $stmt->execute();
        $stmt->close();

        /* Keep this request's copy synchronized. */
        $table['status'] = 'waiting';
        $table['street'] = 'preflop';
        $table['dealer_seat'] = null;
        $table['current_turn'] = null;
        $table['current_bet'] = 0;
        $table['min_raise'] = $table['big_blind'];
        $table['deck_json'] = $emptyDeck;
        $table['community_json'] = $emptyCommunity;
        $table['last_message'] = $waitingMessage;
    }

    $mySeat = poker_find_user_seat($seats, $userId);
    poker_touch_spectator($db, $tableId, $userId, $mySeat !== null);
    $spectators = poker_spectators($db, $tableId);
    $showdown = $table['status'] === 'showdown';

    $pot = 0;
    foreach ($seats as $s) {
        $pot += (int) $s['hand_contribution'];
    }

    $outSeats = array();

    for ($i = 1; $i <= 10; $i++) {
        if (!isset($seats[$i])) {
            $outSeats[$i] = null;
            continue;
        }

        $s = $seats[$i];
        $cards = array();
        $hasHoleCards = !empty($s['hole1']) && !empty($s['hole2']);

        if (
            $showdown &&
            $hasHoleCards &&
            in_array($s['hand_state'], array('active', 'allin'), true)
        ) {
            $cards = array($s['hole1'], $s['hole2']);
        } elseif (
            $mySeat === $i &&
            $table['status'] === 'playing' &&
            $hasHoleCards &&
            in_array($s['hand_state'], array('active', 'allin', 'folded'), true)
        ) {
            $cards = array($s['hole1'], $s['hole2']);
        } elseif (
            $table['status'] === 'playing' &&
            poker_active_for_hand($s)
        ) {
            $cards = array('BACK', 'BACK');
        }

        $outSeats[$i] = array(
            'seat' => $i,
            'user_id' => (int) $s['user_id'],
            'username' => $s['username'],
            'avatar' => $s['avatar'],
            'stack' => (int) $s['stack'],
            'stack_text' => poker_format_table_amount($table,$s['stack']),
            'round_bet' => (int) $s['round_bet'],
            'round_bet_text' => poker_format_table_amount($table,$s['round_bet']),
            'state' => (poker_is_tournament_table($table) && (string)$table['tournament_status'] !== 'registration' && (int)$s['stack'] <= 0) ? 'eliminated' : $s['hand_state'],
            'sitting_out' => !empty($s['sitting_out']),
            'connected' => ((int) $s['user_id'] === 0) ? true : ((int) $s['presence_age_seconds'] <= (int) POKER_PRESENCE_STALE_SECONDS),
            'reconnecting' => (
                !empty($s['reconnect_grace_until']) &&
                (int) $s['reconnect_seconds_left'] > 0 &&
                (int) $table['current_turn'] === $i &&
                $table['status'] === 'playing'
            ),
            'reconnect_seconds_left' => (int) $s['reconnect_seconds_left'],
            'cards' => $cards,
            'is_turn' => (
                (int) $table['current_turn'] === $i &&
                $table['status'] === 'playing'
            )
        );
    }

    $call = 0;
    $canCheck = false;
    $maxTo = 0;

    if ($mySeat !== null && isset($seats[$mySeat])) {
        $call = max(
            0,
            (int) $table['current_bet'] - (int) $seats[$mySeat]['round_bet']
        );
        $canCheck = $call === 0;
        $tableWagerRoom = poker_is_tournament_table($table) ? (int)$seats[$mySeat]['stack'] : max(
            0,
            (int) $table['max_buyin'] - (int) $seats[$mySeat]['hand_contribution']
        );
        $maxTo = (int) $seats[$mySeat]['round_bet'] + min(
            (int) $seats[$mySeat]['stack'],
            $tableWagerRoom
        );
    }

    /*
     * Read the user's current tracker upload credit on every API refresh.
     */
    $stmt = $db->prepare('SELECT uploaded FROM users WHERE id=? LIMIT 1');
    $stmt->bind_param('i', $userId);
    $stmt->execute();
    $userAccount = $stmt->get_result()->fetch_assoc();
    $stmt->close();

    $uploadedCredit = $userAccount ? (int) $userAccount['uploaded'] : 0;

    /*
     * Credit currently sitting on the poker table is still part of the
     * user's total credit. Wins and losses therefore update this value
     * immediately without waiting for the player to leave the table.
     */
    $pokerStack = 0;
    if ($mySeat !== null && isset($seats[$mySeat])) {
        $pokerStack = poker_is_tournament_table($table) ? 0 : (int) $seats[$mySeat]['stack'];
    }

    $stmt = $db->prepare("SELECT COALESCE(SUM(s.stack),0) AS all_poker_stack
                          FROM poker_seats s
                          INNER JOIN poker_tables t ON t.id=s.table_id AND t.game_type IN ('cash','house')
                          WHERE s.user_id=?");
    $stmt->bind_param('i', $userId);
    $stmt->execute();
    $allStackRow = $stmt->get_result()->fetch_assoc();
    $stmt->close();

    $allPokerStack = $allStackRow ? (int) $allStackRow['all_poker_stack'] : 0;
    $totalCredit = $uploadedCredit + $allPokerStack;

    $community = json_decode((string) $table['community_json'], true);
    if (!is_array($community)) {
        $community = array();
    }

    $chatMessages = poker_chat_messages($db, $tableId, 40);

    $userNotice = poker_take_user_notice($db, $userId);

    $maintenanceState = poker_maintenance_state($db);
    $tableMessage = (string) $table['last_message'];
    if ($maintenanceState === 1) {
        $tableMessage = poker_maintenance_drain_message($table['status'] === 'playing');
    } elseif (count($seats) >= 2 && $tableMessage === 'Waiting for players.') {
        $tableMessage = '';
    }

    return array(
        'ok' => true,
        'user_notice' => $userNotice,
        'maintenance' => array(
            'state' => $maintenanceState,
            'draining' => $maintenanceState === 1,
            'offline' => $maintenanceState === 2
        ),
        'table' => array(
            'id' => (int) $table['id'],
            'name' => $table['name'],
            'status' => $table['status'],
            'street' => $table['street'],
            'dealer_seat' => $table['dealer_seat'] === null ? null : (int) $table['dealer_seat'],
            'current_turn' => $table['current_turn'] === null ? null : (int) $table['current_turn'],
            'turn_seconds' => (int) POKER_TURN_SECONDS,
            'turn_seconds_left' => isset($table['turn_seconds_left']) ? (int) $table['turn_seconds_left'] : 0,
            'turn_expires_at' => $table['turn_expires_at'],
            'reconnect_grace_seconds' => (int) POKER_RECONNECT_GRACE_SECONDS,
            'pot' => $pot,
            'pot_text' => poker_format_table_amount($table,$pot),
            'current_bet' => (int) $table['current_bet'],
            'current_bet_text' => poker_format_table_amount($table,$table['current_bet']),
            'min_raise' => (int) $table['min_raise'],
            'min_raise_text' => poker_format_table_amount($table,$table['min_raise']),
            'small_blind' => (int) $table['small_blind'],
            'small_blind_text' => poker_format_table_amount($table,$table['small_blind']),
            'big_blind' => (int) $table['big_blind'],
            'big_blind_text' => poker_format_table_amount($table,$table['big_blind']),
            'starting_small_blind' => (int) $table['starting_small_blind'],
            'starting_small_blind_text' => poker_format_table_amount($table,$table['starting_small_blind']),
            'starting_big_blind' => (int) $table['starting_big_blind'],
            'starting_big_blind_text' => poker_format_table_amount($table,$table['starting_big_blind']),
            'blind_hands_per_level' => (int) $table['blind_hands_per_level'],
            'min_buyin' => (int) $table['min_buyin'],
            'min_buyin_text' => poker_format_bytes($table['min_buyin']),
            'max_buyin' => (int) $table['max_buyin'],
            'max_buyin_text' => poker_format_bytes($table['max_buyin']),
            'game_type' => isset($table['game_type']) ? (string)$table['game_type'] : 'cash',
            'tournament_status' => isset($table['tournament_status']) ? (string)$table['tournament_status'] : 'registration',
            'tournament_entry_fee_text' => poker_format_bytes((int)$table['tournament_entry_fee']),
            'tournament_starting_stack_text' => poker_format_chips((int)$table['tournament_starting_stack']),
            'tournament_prize_pool_text' => poker_format_bytes((int)$table['tournament_prize_pool']),
            'tournament_entries' => (int)$table['tournament_entries'],
            'max_seats' => (poker_is_house_table($table) ? 1 : (int) $table['max_seats']),
            'community' => $community,
            'hand_no' => (int) $table['hand_no'],
            'message' => $tableMessage
        ),
        'seats' => $outSeats,
        'spectators' => $spectators,
        'spectator_count' => count($spectators),
        'chat' => $chatMessages,
        'me' => array(
            'seat' => $mySeat,
            'is_admin' => poker_user_is_admin($GLOBALS['CURUSER']),
            'is_spectating' => $mySeat === null,
            'sitting_out' => (
                $mySeat !== null &&
                isset($seats[$mySeat]) &&
                !empty($seats[$mySeat]['sitting_out'])
            ),
            'call' => $call,
            'call_text' => poker_format_table_amount($table,$call),
            'can_check' => $canCheck,
            'max_raise_to' => $maxTo,
            'uploaded_credit' => $uploadedCredit,
            'poker_stack' => $pokerStack,
            'all_poker_stack' => $allPokerStack,
            'total_credit' => $totalCredit,
            'total_credit_text' => poker_format_bytes($totalCredit),
            'is_turn' => (
                $mySeat !== null &&
                (int) $table['current_turn'] === $mySeat &&
                $table['status'] === 'playing'
            )
        )
    );
}

/*
 * Instanced House Hold'em
 * -----------------------
 * House tables in poker_tables are templates/configuration only. Each user gets
 * an independent persistent session in poker_house_sessions, so any number of
 * users can play the same House table at the same time without sharing cards,
 * pots, turns, or stacks.
 */
function poker_house_session_row($db, $templateId, $userId, $forUpdate = false)
{
    $sql = 'SELECT * FROM poker_house_sessions WHERE template_id=? AND user_id=? AND active=1 LIMIT 1';
    if ($forUpdate) {
        $sql .= ' FOR UPDATE';
    }
    $stmt = $db->prepare($sql);
    $stmt->bind_param('ii', $templateId, $userId);
    $stmt->execute();
    $row = $stmt->get_result()->fetch_assoc();
    $stmt->close();
    return $row ?: null;
}

function poker_house_default_state($table, $playerStack)
{
    return array(
        'status' => 'waiting',
        'street' => 'preflop',
        'dealer_seat' => poker_house_bot_seat(),
        'current_turn' => null,
        'turn_expires_at' => null,
        'current_bet' => 0,
        'min_raise' => (int)$table['big_blind'],
        'deck' => array(),
        'community' => array(),
        'hand_no' => 0,
        'message' => 'Ready for a heads-up hand against the House.',
        'session_buyin' => (int)$playerStack,
        'player' => array(
            'stack' => (int)$playerStack,
            'round_bet' => 0,
            'hand_contribution' => 0,
            'hand_state' => 'waiting',
            'acted' => 0,
            'hole1' => null,
            'hole2' => null
        ),
        'bot' => array(
            'stack' => (int)$playerStack,
            'round_bet' => 0,
            'hand_contribution' => 0,
            'hand_state' => 'waiting',
            'acted' => 0,
            'hole1' => null,
            'hole2' => null
        ),
        'actions' => array(),
        'history' => array()
    );
}

function poker_house_decode_state($row, $table)
{
    if (!$row) {
        return null;
    }
    $state = json_decode((string)$row['state_json'], true);
    if (!is_array($state)) {
        $state = poker_house_default_state($table, (int)$row['player_stack']);
    }
    if (!isset($state['session_buyin']) || (int)$state['session_buyin'] <= 0) {
        if (isset($state['player_starting_stack']) && (int)$state['player_starting_stack'] > 0) {
            $state['session_buyin'] = (int)$state['player_starting_stack'];
        } else {
            $state['session_buyin'] = (int)$row['player_stack'];
        }
    }
    return $state;
}

function poker_house_save_session($db, $sessionId, $state)
{
    $json = json_encode($state, JSON_UNESCAPED_SLASHES);
    if ($json === false) {
        throw new RuntimeException('Could not encode House session state.');
    }
    $playerStack = isset($state['player']['stack']) ? (int)$state['player']['stack'] : 0;
    $status = isset($state['status']) ? (string)$state['status'] : 'waiting';
    $stmt = $db->prepare('UPDATE poker_house_sessions SET player_stack=?, session_status=?, state_json=?, last_seen_at=NOW(), updated_at=NOW() WHERE id=?');
    $stmt->bind_param('issi', $playerStack, $status, $json, $sessionId);
    $stmt->execute();
    $stmt->close();
}

function poker_house_join_session($db, $templateId, $buyin, $user)
{
    $db->begin_transaction();
    try {
        $table = poker_table_for_update($db, $templateId);
        if (!$table || !poker_is_house_table($table)) {
            throw new RuntimeException('House table not found.');
        }
        if ($buyin < (int)$table['min_buyin'] || $buyin > (int)$table['max_buyin']) {
            throw new RuntimeException('Buy-in must be between ' . poker_format_bytes($table['min_buyin']) . ' and ' . poker_format_bytes($table['max_buyin']) . '.');
        }
        $userId = (int)$user['id'];
        if (poker_house_session_row($db, $templateId, $userId, true)) {
            throw new RuntimeException('You already have an active House game.');
        }
        $stmt = $db->prepare('SELECT uploaded FROM users WHERE id=? FOR UPDATE');
        $stmt->bind_param('i', $userId);
        $stmt->execute();
        $account = $stmt->get_result()->fetch_assoc();
        $stmt->close();

        /* Same one-player/one-game rule applies to private House sessions. */
        $activeGame = poker_active_game_for_user($db, $userId);
        if ($activeGame) {
            throw new RuntimeException(poker_active_game_join_error($activeGame));
        }

        if (!$account || (int)$account['uploaded'] < $buyin) {
            throw new RuntimeException('You do not have enough upload credit for that buy-in.');
        }
        $stmt = $db->prepare('UPDATE users SET uploaded=uploaded-? WHERE id=?');
        $stmt->bind_param('ii', $buyin, $userId);
        $stmt->execute();
        $stmt->close();

        $state = poker_house_default_state($table, $buyin);
        $json = json_encode($state, JSON_UNESCAPED_SLASHES);
        $status = 'waiting';
        $stmt = $db->prepare('INSERT INTO poker_house_sessions (template_id,user_id,username,player_stack,session_status,state_json,active,created_at,last_seen_at,updated_at) VALUES (?,?,?,?,?,?,1,NOW(),NOW(),NOW())');
        $username = isset($user['username']) ? (string)$user['username'] : ('User ' . $userId);
        $stmt->bind_param('iisiss', $templateId, $userId, $username, $buyin, $status, $json);
        $stmt->execute();
        $stmt->close();
        $db->commit();
    } catch (Throwable $e) {
        $db->rollback();
        throw $e;
    }
}

function poker_house_leave_session($db, $templateId, $userId)
{
    $db->begin_transaction();
    try {
        $row = poker_house_session_row($db, $templateId, $userId, true);
        if (!$row) {
            throw new RuntimeException('You are not in a House game.');
        }
        $table = poker_table_for_update($db, $templateId);
        $state = poker_house_decode_state($row, $table);
        if ((string)$state['status'] === 'playing') {
            throw new RuntimeException('Finish the current hand before leaving the House game.');
        }
        $cashout = max(0, (int)$state['player']['stack']);
        if ($cashout > 0) {
            $stmt = $db->prepare('UPDATE users SET uploaded=uploaded+? WHERE id=?');
            $stmt->bind_param('ii', $cashout, $userId);
            $stmt->execute();
            $stmt->close();
        }
        $stmt = $db->prepare('UPDATE poker_house_sessions SET active=0, player_stack=0, session_status=\'closed\', last_seen_at=NOW(), updated_at=NOW() WHERE id=?');
        $sid = (int)$row['id'];
        $stmt->bind_param('i', $sid);
        $stmt->execute();
        $stmt->close();
        $db->commit();
    } catch (Throwable $e) {
        $db->rollback();
        throw $e;
    }
}

function poker_house_log(&$state, $seat, $username, $action, $amount)
{
    $state['actions'][] = array(
        'seat_no' => (int)$seat,
        'username' => (string)$username,
        'action_name' => (string)$action,
        'amount' => (int)$amount,
        'time' => date('H:i:s')
    );
}

function poker_house_pay(&$seat, $amount)
{
    $amount = max(0, min((int)$amount, (int)$seat['stack']));
    $seat['stack'] -= $amount;
    $seat['round_bet'] += $amount;
    $seat['hand_contribution'] += $amount;
    if ($seat['stack'] <= 0) {
        $seat['hand_state'] = 'allin';
    }
    return $amount;
}

function poker_house_effective_max_to($state, $who, $table)
{
    $me = $state[$who];
    $other = $state[$who === 'player' ? 'bot' : 'player'];
    $ownRoom = max(0, (int)$table['max_buyin'] - (int)$me['hand_contribution']);
    $ownMax = (int)$me['round_bet'] + min((int)$me['stack'], $ownRoom);
    $otherMax = (int)$other['round_bet'] + (int)$other['stack'];
    return max((int)$state['current_bet'], min($ownMax, $otherMax));
}

function poker_house_set_turn(&$state, $seatNo)
{
    $state['current_turn'] = $seatNo === null ? null : (int)$seatNo;
    $state['turn_expires_at'] = $seatNo === null ? null : date('Y-m-d H:i:s', time() + POKER_TURN_SECONDS);
}

function poker_house_start_session_hand($db, $templateId, $userId)
{
    $db->begin_transaction();
    try {
        $table = poker_table_for_update($db, $templateId);
        if (!$table || !poker_is_house_table($table)) throw new RuntimeException('House table not found.');
        $row = poker_house_session_row($db, $templateId, $userId, true);
        if (!$row) throw new RuntimeException('Take a seat before starting a House hand.');
        $state = poker_house_decode_state($row, $table);
        if ((string)$state['status'] === 'playing') throw new RuntimeException('A hand is already in progress.');
        if ((int)$state['player']['stack'] < (int)$table['big_blind']) throw new RuntimeException('Your stack is below the big blind. Leave and buy in again.');

        $state['hand_no'] = (int)$state['hand_no'] + 1;
        // House cash games use fixed blinds. They do not escalate like tournaments.
        $smallBlind = (int)$table['starting_small_blind'];
        $bigBlind = (int)$table['starting_big_blind'];
        $state['dealer_seat'] = ((int)$state['dealer_seat'] === poker_house_player_seat()) ? poker_house_bot_seat() : poker_house_player_seat();
        $state['status'] = 'playing';
        $state['street'] = 'preflop';
        $state['community'] = array();
        $state['current_bet'] = 0;
        $state['min_raise'] = $bigBlind;
        $state['actions'] = array();
        $state['deck'] = poker_build_deck();

        // Match the House stack to the player's original session buy-in for every hand.
        $houseStack = isset($state['session_buyin']) ? (int)$state['session_buyin'] : (int)$row['player_stack'];
        $state['bot']['stack'] = max($houseStack, $bigBlind);
        foreach (array('player','bot') as $k) {
            $state[$k]['round_bet'] = 0;
            $state[$k]['hand_contribution'] = 0;
            $state[$k]['hand_state'] = 'active';
            $state[$k]['acted'] = 0;
            $state[$k]['hole1'] = poker_take_card($state['deck']);
            $state[$k]['hole2'] = poker_take_card($state['deck']);
        }

        $dealerKey = ((int)$state['dealer_seat'] === poker_house_player_seat()) ? 'player' : 'bot';
        $bbKey = $dealerKey === 'player' ? 'bot' : 'player';
        $sbPaid = poker_house_pay($state[$dealerKey], $smallBlind);
        $bbPaid = poker_house_pay($state[$bbKey], $bigBlind);
        $state['current_bet'] = max($sbPaid, $bbPaid);
        poker_house_log($state, $state['dealer_seat'], $dealerKey === 'player' ? $row['username'] : 'House Bot', 'small blind', $sbPaid);
        poker_house_log($state, $state['dealer_seat'] === poker_house_player_seat() ? poker_house_bot_seat() : poker_house_player_seat(), $bbKey === 'player' ? $row['username'] : 'House Bot', 'big blind', $bbPaid);
        $state['hand_started'] = date('M j, Y H:i:s');
        $state['hand_small_blind'] = $smallBlind;
        $state['hand_big_blind'] = $bigBlind;
        $state['player_starting_stack'] = (int)$state['player']['stack'] + (int)$state['player']['hand_contribution'];
        $state['bot_starting_stack'] = (int)$state['bot']['stack'] + (int)$state['bot']['hand_contribution'];
        $state['message'] = 'Hand #' . $state['hand_no'] . ' — heads-up against the House.';
        poker_house_set_turn($state, (int)$state['dealer_seat']);
        poker_house_save_session($db, (int)$row['id'], $state);
        $db->commit();
    } catch (Throwable $e) {
        $db->rollback();
        throw $e;
    }
}

function poker_house_round_done($state)
{
    if ($state['player']['hand_state'] === 'folded' || $state['bot']['hand_state'] === 'folded') return true;
    if ($state['player']['hand_state'] === 'allin' || $state['bot']['hand_state'] === 'allin') {
        return (int)$state['player']['round_bet'] === (int)$state['bot']['round_bet'];
    }
    return !empty($state['player']['acted']) && !empty($state['bot']['acted']) && (int)$state['player']['round_bet'] === (int)$state['bot']['round_bet'];
}

function poker_house_finish_session_hand(&$state, $winner, $message)
{
    $pot = (int)$state['player']['hand_contribution'] + (int)$state['bot']['hand_contribution'];
    if ($winner === 'player') {
        $state['player']['stack'] += $pot;
    } elseif ($winner === 'bot') {
        $state['bot']['stack'] += $pot;
    } else {
        $half = intdiv($pot, 2);
        $state['player']['stack'] += $half + ($pot % 2);
        $state['bot']['stack'] += $half;
    }

    $history = array(
        'hand_no' => (int)$state['hand_no'],
        'dealer_seat' => (int)$state['dealer_seat'],
        'board' => $state['community'],
        'result_text' => $message,
        'showdown_reached' => ($state['player']['hand_state'] !== 'folded' && $state['bot']['hand_state'] !== 'folded') ? 1 : 0,
        'small_blind_text' => poker_format_bytes(isset($state['hand_small_blind']) ? (int)$state['hand_small_blind'] : 0),
        'big_blind_text' => poker_format_bytes(isset($state['hand_big_blind']) ? (int)$state['hand_big_blind'] : 0),
        'started' => isset($state['hand_started']) ? (string)$state['hand_started'] : '',
        'ended' => date('M j, Y H:i:s'),
        'players' => array(
            array('seat_no'=>poker_house_player_seat(),'username'=>'You','starting_stack_text'=>poker_format_bytes(isset($state['player_starting_stack']) ? (int)$state['player_starting_stack'] : 0),'cards'=>array($state['player']['hole1'],$state['player']['hole2'])),
            array('seat_no'=>poker_house_bot_seat(),'username'=>'House Bot','starting_stack_text'=>poker_format_bytes(isset($state['bot_starting_stack']) ? (int)$state['bot_starting_stack'] : 0),'cards'=>($state['player']['hand_state'] !== 'folded' && $state['bot']['hand_state'] !== 'folded') ? array($state['bot']['hole1'],$state['bot']['hole2']) : array())
        ),
        'actions' => $state['actions']
    );
    array_unshift($state['history'], $history);
    if (count($state['history']) > 20) $state['history'] = array_slice($state['history'], 0, 20);

    $state['status'] = 'showdown';
    $state['current_turn'] = null;
    $state['turn_expires_at'] = null;
    $state['message'] = $message;
    $state['player']['hand_state'] = 'waiting';
    $state['bot']['hand_state'] = 'waiting';
    $state['player']['round_bet'] = $state['bot']['round_bet'] = 0;
    $state['player']['hand_contribution'] = $state['bot']['hand_contribution'] = 0;
    $state['player']['acted'] = $state['bot']['acted'] = 0;
}

function poker_house_showdown_session(&$state)
{
    while (count($state['community']) < 5) {
        $state['community'][] = poker_take_card($state['deck']);
    }
    $pScore = poker_best_score(array_merge(array($state['player']['hole1'],$state['player']['hole2']), $state['community']));
    $bScore = poker_best_score(array_merge(array($state['bot']['hole1'],$state['bot']['hole2']), $state['community']));
    $cmp = poker_compare_score($pScore, $bScore);
    if ($cmp > 0) {
        poker_house_finish_session_hand($state, 'player', 'You win with ' . poker_hand_name($pScore) . '.');
    } elseif ($cmp < 0) {
        poker_house_finish_session_hand($state, 'bot', 'House Bot wins with ' . poker_hand_name($bScore) . '.');
    } else {
        poker_house_finish_session_hand($state, 'split', 'Split pot — both hands tie with ' . poker_hand_name($pScore) . '.');
    }
}

function poker_house_advance_session(&$state, $table)
{
    if ($state['player']['hand_state'] === 'folded') {
        poker_house_finish_session_hand($state, 'bot', 'House Bot wins — you folded.');
        return;
    }
    if ($state['bot']['hand_state'] === 'folded') {
        poker_house_finish_session_hand($state, 'player', 'You win — House Bot folded.');
        return;
    }
    if (!poker_house_round_done($state)) return;

    if ($state['player']['hand_state'] === 'allin' || $state['bot']['hand_state'] === 'allin') {
        poker_house_showdown_session($state);
        return;
    }

    foreach (array('player','bot') as $k) {
        $state[$k]['round_bet'] = 0;
        $state[$k]['acted'] = 0;
    }
    $state['current_bet'] = 0;
    $state['min_raise'] = isset($state['hand_big_blind']) ? (int)$state['hand_big_blind'] : (int)$table['big_blind'];
    if ($state['street'] === 'preflop') {
        $state['community'][] = poker_take_card($state['deck']);
        $state['community'][] = poker_take_card($state['deck']);
        $state['community'][] = poker_take_card($state['deck']);
        $state['street'] = 'flop';
    } elseif ($state['street'] === 'flop') {
        $state['community'][] = poker_take_card($state['deck']);
        $state['street'] = 'turn';
    } elseif ($state['street'] === 'turn') {
        $state['community'][] = poker_take_card($state['deck']);
        $state['street'] = 'river';
    } else {
        poker_house_showdown_session($state);
        return;
    }
    $first = ((int)$state['dealer_seat'] === poker_house_player_seat()) ? poker_house_bot_seat() : poker_house_player_seat();
    poker_house_set_turn($state, $first);
}

function poker_house_session_action($db, $templateId, $userId, $action, $raiseTo, $automatic = false)
{
    $db->begin_transaction();
    try {
        $table = poker_table_for_update($db, $templateId);
        if (!$table || !poker_is_house_table($table)) throw new RuntimeException('House table not found.');
        $row = poker_house_session_row($db, $templateId, $userId, true);
        if (!$row) throw new RuntimeException('No active House game.');
        $state = poker_house_decode_state($row, $table);
        if ($state['status'] !== 'playing') throw new RuntimeException('No hand is in progress.');
        $seatNo = $automatic ? poker_house_bot_seat() : poker_house_player_seat();
        if ((int)$state['current_turn'] !== $seatNo) throw new RuntimeException('It is not your turn.');
        $who = $automatic ? 'bot' : 'player';
        $other = $who === 'player' ? 'bot' : 'player';
        $seat =& $state[$who];
        $call = max(0, (int)$state['current_bet'] - (int)$seat['round_bet']);
        $name = $automatic ? 'House Bot' : (string)$row['username'];
        $action = strtolower((string)$action);

        if ($action === 'fold') {
            $seat['hand_state'] = 'folded';
            $seat['acted'] = 1;
            poker_house_log($state, $seatNo, $name, 'fold', 0);
        } elseif ($action === 'check') {
            if ($call > 0) throw new RuntimeException('You cannot check while facing a bet.');
            $seat['acted'] = 1;
            poker_house_log($state, $seatNo, $name, 'check', 0);
        } elseif ($action === 'call') {
            if ($call <= 0) throw new RuntimeException('There is nothing to call.');
            $paid = poker_house_pay($seat, $call);
            $seat['acted'] = 1;
            poker_house_log($state, $seatNo, $name, 'call', $paid);
        } elseif ($action === 'allin') {
            $raiseTo = poker_house_effective_max_to($state, $who, $table);
            if ($raiseTo <= (int)$seat['round_bet']) throw new RuntimeException('No chips available for an all-in.');
            $paid = poker_house_pay($seat, $raiseTo - (int)$seat['round_bet']);
            if ((int)$seat['round_bet'] > (int)$state['current_bet']) {
                $raiseSize = (int)$seat['round_bet'] - (int)$state['current_bet'];
                $state['min_raise'] = max((int)$state['min_raise'], $raiseSize);
                $state['current_bet'] = (int)$seat['round_bet'];
                $state[$other]['acted'] = 0;
            }
            $seat['acted'] = 1;
            poker_house_log($state, $seatNo, $name, 'all-in', $paid);
        } elseif ($action === 'raise') {
            $maxTo = poker_house_effective_max_to($state, $who, $table);
            $minTo = (int)$state['current_bet'] + (int)$state['min_raise'];
            if ($raiseTo < $minTo || $raiseTo > $maxTo) {
                throw new RuntimeException('Raise-to must be between ' . poker_format_bytes($minTo) . ' and ' . poker_format_bytes($maxTo) . '.');
            }
            $paid = poker_house_pay($seat, $raiseTo - (int)$seat['round_bet']);
            $raiseSize = (int)$seat['round_bet'] - (int)$state['current_bet'];
            $state['min_raise'] = max(1, $raiseSize);
            $state['current_bet'] = (int)$seat['round_bet'];
            $seat['acted'] = 1;
            $state[$other]['acted'] = 0;
            poker_house_log($state, $seatNo, $name, 'raise', $paid);
        } else {
            throw new RuntimeException('Unknown poker action.');
        }

        $roundWasDone = poker_house_round_done($state);
        poker_house_advance_session($state, $table);
        if ($state['status'] === 'playing' && !$roundWasDone) {
            $next = $seatNo === poker_house_player_seat() ? poker_house_bot_seat() : poker_house_player_seat();
            poker_house_set_turn($state, $next);
        }
        poker_house_save_session($db, (int)$row['id'], $state);
        $db->commit();
    } catch (Throwable $e) {
        $db->rollback();
        throw $e;
    }
}

function poker_house_session_bot_turn($db, $templateId, $userId)
{
    $table = poker_get_table($db, $templateId);
    $row = poker_house_session_row($db, $templateId, $userId, false);

    if (!$table || !$row) {
        return;
    }

    $state = poker_house_decode_state($row, $table);

    if (
        $state['status'] !== 'playing' ||
        (int)$state['current_turn'] !== poker_house_bot_seat()
    ) {
        return;
    }

    $seat = $state['bot'];
    $call = max(
        0,
        (int)$state['current_bet'] - (int)$seat['round_bet']
    );

    $stack = (int)$seat['stack'];
    $maxTo = poker_house_effective_max_to($state, 'bot', $table);
    $minimumRaiseTo =
        (int)$state['current_bet'] +
        max(1, (int)$state['min_raise']);

    /*
     * Equity is estimated without ever looking at the player's cards.
     * More trials are used when real money is effectively on the line.
     */
    $pressure = $call > 0
        ? $call / max(1, $stack)
        : 0.0;

    $isShove = poker_house_is_shove($state, $call, $stack);

    if ($isShove) {
        $trials = 260;
    } elseif ($call > 0) {
        $trials = 180;
    } else {
        $trials = 120;
    }

    $equity = poker_house_estimated_equity(
        $seat['hole1'],
        $seat['hole2'],
        $state['community'],
        $trials
    );

    $roll = random_int(1, 1000) / 1000;

    if ($call > 0) {
        $pot = poker_house_hand_pot($state);
        $potOdds = $call / max(1, $pot + $call);

        /*
         * The old bot folded some weak hands, then blindly called almost
         * everything else. Repeated all-ins exploited that behavior.
         *
         * Now the House compares estimated showdown equity to the price of
         * the call. A shove gets only a small safety margin; ordinary bets
         * get a slightly larger one. A tiny random wobble keeps the bot from
         * being completely deterministic without turning it into a coin flip.
         */
        if ($isShove) {
            $margin = 0.025;
            $wobble = ($roll - 0.5) * 0.030;
        } elseif ($pressure >= 0.40) {
            $margin = 0.045;
            $wobble = ($roll - 0.5) * 0.040;
        } elseif ($pressure >= 0.20) {
            $margin = 0.035;
            $wobble = ($roll - 0.5) * 0.050;
        } else {
            $margin = 0.020;
            $wobble = ($roll - 0.5) * 0.060;
        }

        $callThreshold = min(0.92, $potOdds + $margin);

        /*
         * Do not re-raise a player who is already all-in. Against a normal
         * bet, strong equity can still produce a value raise.
         */
        if (
            !$isShove &&
            $equity >= 0.72 &&
            $maxTo >= $minimumRaiseTo &&
            $stack > $call &&
            $roll <= 0.38
        ) {
            $bigBlind = isset($state['hand_big_blind'])
                ? (int)$state['hand_big_blind']
                : (int)$table['big_blind'];

            $extra = $equity >= 0.86
                ? ($bigBlind * 2)
                : $bigBlind;

            $target = min(
                $maxTo,
                $minimumRaiseTo + $extra
            );

            if ($target >= $minimumRaiseTo) {
                poker_house_session_action(
                    $db,
                    $templateId,
                    $userId,
                    'raise',
                    $target,
                    true
                );
                return;
            }
        }

        if (($equity + $wobble) >= $callThreshold) {
            poker_house_session_action(
                $db,
                $templateId,
                $userId,
                'call',
                0,
                true
            );
        } else {
            poker_house_session_action(
                $db,
                $templateId,
                $userId,
                'fold',
                0,
                true
            );
        }

        return;
    }

    /*
     * When nobody has bet, use equity rather than the old raw hand-category
     * score. This accounts for draws and board texture as the hand develops.
     */
    if ($maxTo >= $minimumRaiseTo) {
        if ($equity >= 0.78 && $roll <= 0.70) {
            $bigBlind = isset($state['hand_big_blind'])
                ? (int)$state['hand_big_blind']
                : (int)$table['big_blind'];

            $extra = $equity >= 0.88
                ? ($bigBlind * 2)
                : $bigBlind;

            $target = min(
                $maxTo,
                $minimumRaiseTo + $extra
            );

            poker_house_session_action(
                $db,
                $templateId,
                $userId,
                'raise',
                $target,
                true
            );
            return;
        }

        if ($equity >= 0.62 && $roll <= 0.34) {
            poker_house_session_action(
                $db,
                $templateId,
                $userId,
                'raise',
                $minimumRaiseTo,
                true
            );
            return;
        }

        /*
         * Small bluff frequency so the House is not completely face-up.
         */
        if ($equity < 0.42 && $roll <= 0.055) {
            poker_house_session_action(
                $db,
                $templateId,
                $userId,
                'raise',
                $minimumRaiseTo,
                true
            );
            return;
        }
    }

    poker_house_session_action(
        $db,
        $templateId,
        $userId,
        'check',
        0,
        true
    );
}

function poker_house_session_timeout($db, $templateId, $userId)
{
    $row = poker_house_session_row($db, $templateId, $userId, false);
    if (!$row) return;
    $table = poker_get_table($db, $templateId);
    $state = poker_house_decode_state($row, $table);
    if ($state['status'] !== 'playing' || (int)$state['current_turn'] !== poker_house_player_seat() || empty($state['turn_expires_at'])) return;
    if (strtotime($state['turn_expires_at']) > time()) return;
    $call = max(0, (int)$state['current_bet'] - (int)$state['player']['round_bet']);
    poker_house_session_action($db, $templateId, $userId, $call > 0 ? 'fold' : 'check', 0, false);
}

function poker_house_session_public_state($db, $templateId, $userId, $user)
{
    poker_house_session_timeout($db, $templateId, $userId);
    $table = poker_get_table($db, $templateId);
    if (!$table || !poker_is_house_table($table)) throw new RuntimeException('House table not found.');
    $row = poker_house_session_row($db, $templateId, $userId, false);
    $state = $row ? poker_house_decode_state($row, $table) : poker_house_default_state($table, 0);
    if ($row) {
        $stmt = $db->prepare('UPDATE poker_house_sessions SET last_seen_at=NOW() WHERE id=?');
        $sid = (int)$row['id'];
        $stmt->bind_param('i', $sid);
        $stmt->execute();
        $stmt->close();
    }
    $seats = array();
    for ($i=1;$i<=10;$i++) $seats[$i]=null;
    if ($row) {
        $showdown = $state['status'] === 'showdown';
        $seats[poker_house_player_seat()] = array(
            'seat'=>poker_house_player_seat(),'user_id'=>$userId,'username'=>(string)$row['username'],'avatar'=>isset($user['avatar'])?(string)$user['avatar']:'',
            'stack'=>(int)$state['player']['stack'],'stack_text'=>poker_format_bytes($state['player']['stack']),
            'round_bet'=>(int)$state['player']['round_bet'],'round_bet_text'=>poker_format_bytes($state['player']['round_bet']),
            'state'=>(string)$state['player']['hand_state'],'sitting_out'=>false,'connected'=>true,'reconnecting'=>false,'reconnect_seconds_left'=>0,
            'cards'=>($state['status']==='playing'||$showdown) && $state['player']['hole1'] ? array($state['player']['hole1'],$state['player']['hole2']) : array(),
            'is_turn'=>$state['status']==='playing' && (int)$state['current_turn']===poker_house_player_seat()
        );
        $botCards = array();
        if ($state['status']==='playing' && $state['bot']['hole1']) $botCards = array('BACK','BACK');
        if ($showdown && $state['bot']['hole1']) $botCards = array($state['bot']['hole1'],$state['bot']['hole2']);
        $seats[poker_house_bot_seat()] = array(
            'seat'=>poker_house_bot_seat(),'user_id'=>0,'username'=>'House Bot','avatar'=>'','stack'=>(int)$state['bot']['stack'],'stack_text'=>poker_format_bytes($state['bot']['stack']),
            'round_bet'=>(int)$state['bot']['round_bet'],'round_bet_text'=>poker_format_bytes($state['bot']['round_bet']),
            'state'=>(string)$state['bot']['hand_state'],'sitting_out'=>false,'connected'=>true,'reconnecting'=>false,'reconnect_seconds_left'=>0,
            'cards'=>$botCards,'is_turn'=>$state['status']==='playing' && (int)$state['current_turn']===poker_house_bot_seat()
        );
    }
    $pot = (int)$state['player']['hand_contribution'] + (int)$state['bot']['hand_contribution'];
    $call = $row ? max(0,(int)$state['current_bet']-(int)$state['player']['round_bet']) : 0;
    $maxTo = $row ? poker_house_effective_max_to($state,'player',$table) : 0;

    $stmt=$db->prepare('SELECT uploaded FROM users WHERE id=? LIMIT 1');
    $stmt->bind_param('i',$userId);$stmt->execute();$acc=$stmt->get_result()->fetch_assoc();$stmt->close();
    $uploaded=$acc?(int)$acc['uploaded']:0;
    $stmt=$db->prepare('SELECT COALESCE(SUM(player_stack),0) AS total FROM poker_house_sessions WHERE user_id=? AND active=1');
    $stmt->bind_param('i',$userId);$stmt->execute();$r=$stmt->get_result()->fetch_assoc();$stmt->close();
    $houseStack=$r?(int)$r['total']:0;
    $stmt=$db->prepare("SELECT COALESCE(SUM(s.stack),0) AS total FROM poker_seats s INNER JOIN poker_tables t ON t.id=s.table_id AND t.game_type='cash' WHERE s.user_id=?");
    $stmt->bind_param('i',$userId);$stmt->execute();$r=$stmt->get_result()->fetch_assoc();$stmt->close();
    $cashStack=$r?(int)$r['total']:0;
    $totalCredit=$uploaded+$houseStack+$cashStack;
    $turnLeft=0;
    if (!empty($state['turn_expires_at'])) $turnLeft=max(0,strtotime($state['turn_expires_at'])-time());
    $maintenanceState=poker_maintenance_state($db);

    return array(
        'ok'=>true,
        'maintenance'=>array('state'=>$maintenanceState,'draining'=>$maintenanceState===1,'offline'=>$maintenanceState===2),
        'table'=>array(
            'id'=>(int)$table['id'],'name'=>(string)$table['name'],'status'=>(string)$state['status'],'street'=>(string)$state['street'],
            'dealer_seat'=>$state['dealer_seat']===null?null:(int)$state['dealer_seat'],'current_turn'=>$state['current_turn']===null?null:(int)$state['current_turn'],
            'turn_seconds'=>POKER_TURN_SECONDS,'turn_seconds_left'=>$turnLeft,'turn_expires_at'=>$state['turn_expires_at'],'reconnect_grace_seconds'=>0,
            'pot'=>$pot,'pot_text'=>poker_format_bytes($pot),'current_bet'=>(int)$state['current_bet'],'current_bet_text'=>poker_format_bytes($state['current_bet']),
            'min_raise'=>(int)$state['min_raise'],'min_raise_text'=>poker_format_bytes($state['min_raise']),
            'small_blind'=>(isset($state['hand_small_blind']) ? (int)$state['hand_small_blind'] : (int)$table['small_blind']),'small_blind_text'=>poker_format_bytes(isset($state['hand_small_blind']) ? (int)$state['hand_small_blind'] : (int)$table['small_blind']),'big_blind'=>(isset($state['hand_big_blind']) ? (int)$state['hand_big_blind'] : (int)$table['big_blind']),'big_blind_text'=>poker_format_bytes(isset($state['hand_big_blind']) ? (int)$state['hand_big_blind'] : (int)$table['big_blind']),
            'starting_small_blind'=>(int)$table['starting_small_blind'],'starting_small_blind_text'=>poker_format_bytes($table['starting_small_blind']),
            'starting_big_blind'=>(int)$table['starting_big_blind'],'starting_big_blind_text'=>poker_format_bytes($table['starting_big_blind']),'blind_hands_per_level'=>(int)$table['blind_hands_per_level'],
            'min_buyin'=>(int)$table['min_buyin'],'min_buyin_text'=>poker_format_bytes($table['min_buyin']),'max_buyin'=>(int)$table['max_buyin'],'max_buyin_text'=>poker_format_bytes($table['max_buyin']),
            'game_type'=>'house','tournament_status'=>'registration','tournament_entry_fee_text'=>poker_format_bytes(0),'tournament_starting_stack_text'=>poker_format_chips(0),'tournament_prize_pool_text'=>poker_format_bytes(0),'tournament_entries'=>0,
            'max_seats'=>1,'community'=>$state['community'],'hand_no'=>(int)$state['hand_no'],'message'=>(string)$state['message']
        ),
        'seats'=>$seats,'spectators'=>array(),'spectator_count'=>0,'chat'=>array(),
        'me'=>array(
            'seat'=>$row?poker_house_player_seat():null,'is_admin'=>poker_user_is_admin($user),'is_turn'=>$row&&$state['status']==='playing'&&(int)$state['current_turn']===poker_house_player_seat(),
            'can_check'=>$call===0,'call'=>$call,'call_text'=>poker_format_bytes($call),'max_raise_to'=>$maxTo,'max_raise_to_text'=>poker_format_bytes($maxTo),'sitting_out'=>false,
            'uploaded_credit'=>$uploaded,'uploaded_credit_text'=>poker_format_bytes($uploaded),'total_credit'=>$totalCredit,'total_credit_text'=>poker_format_bytes($totalCredit)
        )
    );
}

function poker_house_session_history_data($db, $templateId, $userId, $handNo = 0)
{
    $table = poker_get_table($db, $templateId);
    $row = poker_house_session_row($db, $templateId, $userId, false);
    $history = array();
    if ($row) {
        $state = poker_house_decode_state($row, $table);
        $history = isset($state['history']) && is_array($state['history']) ? $state['history'] : array();
    }

    if ($handNo <= 0) {
        $hands = array();
        foreach ($history as $h) {
            $hands[] = array(
                'hand_no' => (int)$h['hand_no'],
                'status' => 'finished',
                'result' => (string)$h['result_text'],
                'started' => isset($h['started']) ? (string)$h['started'] : 'House session'
            );
        }
        return array('ok' => true, 'hands' => $hands);
    }

    foreach ($history as $h) {
        if ((int)$h['hand_no'] !== (int)$handNo) continue;
        $players = array();
        foreach ($h['players'] as $p) {
            $players[] = array(
                'seat_no' => (int)$p['seat_no'],
                'username' => (string)$p['username'],
                'starting_stack_text' => isset($p['starting_stack_text']) ? (string)$p['starting_stack_text'] : '',
                'cards' => isset($p['cards']) && is_array($p['cards']) ? $p['cards'] : array()
            );
        }
        $actions = array();
        foreach ($h['actions'] as $idx => $a) {
            $amount = (int)$a['amount'];
            $actions[] = array(
                'id' => $idx + 1,
                'seat_no' => (int)$a['seat_no'],
                'username' => (string)$a['username'],
                'action' => (string)$a['action_name'],
                'amount' => $amount,
                'amount_text' => $amount > 0 ? poker_format_bytes($amount) : '',
                'time' => (string)$a['time']
            );
        }
        return array(
            'ok' => true,
            'hand' => array(
                'hand_no' => (int)$h['hand_no'],
                'dealer_seat' => (int)$h['dealer_seat'],
                'small_blind_text' => isset($h['small_blind_text']) ? (string)$h['small_blind_text'] : poker_format_bytes((int)$table['small_blind']),
                'big_blind_text' => isset($h['big_blind_text']) ? (string)$h['big_blind_text'] : poker_format_bytes((int)$table['big_blind']),
                'players' => $players,
                'board' => isset($h['board']) ? $h['board'] : array(),
                'status' => 'finished',
                'showdown_reached' => !empty($h['showdown_reached']),
                'result' => (string)$h['result_text'],
                'started' => isset($h['started']) ? (string)$h['started'] : 'House session',
                'ended' => isset($h['ended']) ? (string)$h['ended'] : '',
                'actions' => $actions
            )
        );
    }
    throw new RuntimeException('House hand history not found.');
}
