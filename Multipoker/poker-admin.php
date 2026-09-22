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

if (empty($CURUSER['id']) || (int) $CURUSER['class'] < 7) {
    http_response_code(403);
    die('Access denied.');
}

$db = poker_db();

function poker_admin_redirect($message, $type = 'success', $target = '')
{
    $location = 'poker-admin.php?type=' . rawurlencode($type) . '&message=' . rawurlencode($message);
    if ($target !== '') {
        $location .= '#' . rawurlencode($target);
    }
    header('Location: ' . $location);
    exit;
}

function poker_admin_post_value($target, $name, $default = '')
{
    global $formTarget;

    if ($formTarget === $target && isset($_POST[$name]) && !is_array($_POST[$name])) {
        return (string) $_POST[$name];
    }

    return (string) $default;
}

function poker_admin_selected($target, $name, $value, $default = '')
{
    return poker_admin_post_value($target, $name, $default) === (string) $value ? ' selected' : '';
}

function poker_admin_valid_csrf()
{
    $token = isset($_POST['csrf']) ? (string) $_POST['csrf'] : '';

    return $token !== ''
        && !empty($_SESSION['poker_csrf'])
        && hash_equals($_SESSION['poker_csrf'], $token);
}

function poker_admin_parse_mb($value)
{
    if (!is_numeric($value)) {
        return 0;
    }

    $mb = (float) $value;

    if ($mb <= 0 || floor($mb) != $mb) {
        return 0;
    }

    return poker_mb_to_bytes($mb);
}

function poker_admin_parse_gb($value)
{
    if (!is_numeric($value)) {
        return 0;
    }

    $gb = (float) $value;

    if ($gb <= 0 || floor($gb) != $gb) {
        return 0;
    }

    return poker_gb_to_bytes($gb);
}

function poker_admin_parse_buyin($amount, $unit)
{
    $unit = strtoupper(trim((string) $unit));

    if ($unit === 'MB') {
        return poker_admin_parse_mb($amount);
    }

    if ($unit === 'GB') {
        return poker_admin_parse_gb($amount);
    }

    return 0;
}

function poker_admin_buyin_display($bytes)
{
    $bytes = (int) $bytes;

    if ($bytes < GB || ($bytes % GB) !== 0) {
        return array(
            'amount' => round($bytes / MB, 2),
            'unit' => 'MB'
        );
    }

    return array(
        'amount' => round($bytes / GB, 2),
        'unit' => 'GB'
    );
}

function poker_admin_validate_table_input()
{
    $name = isset($_POST['name']) ? trim((string) $_POST['name']) : '';
    if (isset($_POST['min_buyin_amount'], $_POST['min_buyin_unit'])) {
        $minBuyin = poker_admin_parse_buyin($_POST['min_buyin_amount'], $_POST['min_buyin_unit']);
    } else {
        // Backward compatibility with the previous GB-only admin form.
        $minBuyin = poker_admin_parse_gb(isset($_POST['min_buyin_gb']) ? $_POST['min_buyin_gb'] : '');
    }

    if (isset($_POST['max_buyin_amount'], $_POST['max_buyin_unit'])) {
        $maxBuyin = poker_admin_parse_buyin($_POST['max_buyin_amount'], $_POST['max_buyin_unit']);
    } else {
        // Backward compatibility with the previous GB-only admin form.
        $maxBuyin = poker_admin_parse_gb(isset($_POST['max_buyin_gb']) ? $_POST['max_buyin_gb'] : '');
    }
    if (isset($_POST['starting_small_blind_amount'], $_POST['starting_small_blind_unit'])) {
        $startingSmallBlind = poker_admin_parse_buyin($_POST['starting_small_blind_amount'], $_POST['starting_small_blind_unit']);
    } else {
        $startingSmallBlind = poker_admin_parse_mb(isset($_POST['starting_small_blind_mb']) ? $_POST['starting_small_blind_mb'] : '');
    }

    if (isset($_POST['starting_big_blind_amount'], $_POST['starting_big_blind_unit'])) {
        $startingBigBlind = poker_admin_parse_buyin($_POST['starting_big_blind_amount'], $_POST['starting_big_blind_unit']);
    } else {
        $startingBigBlind = poker_admin_parse_mb(isset($_POST['starting_big_blind_mb']) ? $_POST['starting_big_blind_mb'] : '');
    }
    $blindHandsPerLevel = isset($_POST['blind_hands_per_level']) ? (int) $_POST['blind_hands_per_level'] : 5;
    $maxSeats = isset($_POST['max_seats']) ? (int) $_POST['max_seats'] : 10;

    if ($name === '' || strlen($name) < 2 || strlen($name) > 64) {
        throw new RuntimeException('Table name must be between 2 and 64 characters.');
    }

    if ($minBuyin <= 0 || $maxBuyin <= 0) {
        throw new RuntimeException('Buy-in values must be greater than zero.');
    }

    if ($maxBuyin < $minBuyin) {
        throw new RuntimeException('Maximum buy-in cannot be lower than minimum buy-in.');
    }

    if ($startingSmallBlind <= 0 || $startingBigBlind <= 0) {
        throw new RuntimeException('Starting blinds must be greater than zero.');
    }

    if ($startingBigBlind <= $startingSmallBlind) {
        throw new RuntimeException('Starting big blind must be greater than the starting small blind.');
    }

    if ($minBuyin < $startingBigBlind) {
        throw new RuntimeException('Minimum buy-in cannot be lower than the starting big blind.');
    }

    if ($blindHandsPerLevel < 1 || $blindHandsPerLevel > 100) {
        throw new RuntimeException('Blind increase interval must be between 1 and 100 hands.');
    }

    if ($maxSeats < 2 || $maxSeats > 10) {
        throw new RuntimeException('Max seats must be between 2 and 10.');
    }

    return array($name, $minBuyin, $maxBuyin, $startingSmallBlind, $startingBigBlind, $blindHandsPerLevel, $maxSeats);
}

$formError = '';
$formTarget = '';
$focusField = '';

if ($_SERVER['REQUEST_METHOD'] === 'POST') {
    $action = isset($_POST['action']) ? (string) $_POST['action'] : '';
    $tableIdForTarget = isset($_POST['table_id']) ? (int) $_POST['table_id'] : 0;
    $formTargets = array(
        'create_table' => 'create-table',
        'create_house' => 'create-house',
        'create_tournament' => 'create-tournament',
        'set_maintenance' => 'maintenance'
    );
    $formTarget = isset($formTargets[$action])
        ? $formTargets[$action]
        : ($tableIdForTarget > 0 ? 'table-' . $tableIdForTarget : 'poker-admin');
    $focusField = isset($_POST['_focus_field']) ? trim((string) $_POST['_focus_field']) : '';

    try {
        if (!poker_admin_valid_csrf()) {
            throw new RuntimeException('Invalid request token. Please refresh the page and try again.');
        }
        if ($action === 'create_table') {
            list(
                $name,
                $minBuyin,
                $maxBuyin,
                $startingSmallBlind,
                $startingBigBlind,
                $blindHandsPerLevel,
                $maxSeats
            ) = poker_admin_validate_table_input();

            $smallBlind = $startingSmallBlind;
            $bigBlind = $startingBigBlind;
            $minRaise = $bigBlind;
            $waitingMessage = 'Waiting for players.';
            $emptyJson = '[]';

            $stmt = $db->prepare("INSERT INTO poker_tables
                (name,small_blind,big_blind,starting_small_blind,starting_big_blind,blind_hands_per_level,min_buyin,max_buyin,max_seats,status,street,current_bet,min_raise,deck_json,community_json,hand_no,last_message)
                VALUES (?,?,?,?,?,?,?,?,?,'waiting','preflop',0,?,?,?,0,?)");
            $stmt->bind_param(
                'siiiiiiiiisss',
                $name,
                $smallBlind,
                $bigBlind,
                $startingSmallBlind,
                $startingBigBlind,
                $blindHandsPerLevel,
                $minBuyin,
                $maxBuyin,
                $maxSeats,
                $minRaise,
                $emptyJson,
                $emptyJson,
                $waitingMessage
            );
            $stmt->execute();
            $stmt->close();

            poker_admin_redirect('Poker table created: ' . $name);
        }

        if ($action === 'create_house') {
            list(
                $name,
                $minBuyin,
                $maxBuyin,
                $startingSmallBlind,
                $startingBigBlind,
                $blindHandsPerLevel,
                $ignoredSeats
            ) = poker_admin_validate_table_input();

            $smallBlind = $startingSmallBlind;
            $bigBlind = $startingBigBlind;
            $minRaise = $bigBlind;
            $waitingMessage = 'Waiting for a player to challenge the House.';
            $emptyJson = '[]';
            $internalSeats = 10;
            $gameType = 'house';

            $stmt = $db->prepare("INSERT INTO poker_tables
                (name,game_type,small_blind,big_blind,starting_small_blind,starting_big_blind,blind_hands_per_level,min_buyin,max_buyin,max_seats,status,street,current_bet,min_raise,deck_json,community_json,hand_no,last_message)
                VALUES (?,?,?,?,?,?,?,?,?,?,'waiting','preflop',0,?,?,?,0,?)");
            $stmt->bind_param(
                'ssiiiiiiiiisss',
                $name,
                $gameType,
                $smallBlind,
                $bigBlind,
                $startingSmallBlind,
                $startingBigBlind,
                $blindHandsPerLevel,
                $minBuyin,
                $maxBuyin,
                $internalSeats,
                $minRaise,
                $emptyJson,
                $emptyJson,
                $waitingMessage
            );
            $stmt->execute();
            $stmt->close();

            poker_admin_redirect('House table created: ' . $name);
        }

        if ($action === 'update_table') {
            $tableId = isset($_POST['table_id']) ? (int) $_POST['table_id'] : 0;

            if ($tableId <= 0) {
                throw new RuntimeException('Invalid poker table.');
            }

            list(
                $name,
                $minBuyin,
                $maxBuyin,
                $startingSmallBlind,
                $startingBigBlind,
                $blindHandsPerLevel,
                $maxSeats
            ) = poker_admin_validate_table_input();

            $stmt = $db->prepare('SELECT COALESCE(MAX(seat_no),0) AS highest_seat FROM poker_seats WHERE table_id=?');
            $stmt->bind_param('i', $tableId);
            $stmt->execute();
            $row = $stmt->get_result()->fetch_assoc();
            $stmt->close();

            $highestSeat = $row ? (int) $row['highest_seat'] : 0;

            if ($highestSeat > $maxSeats) {
                throw new RuntimeException('Cannot lower max seats to ' . $maxSeats . ' while a player is sitting in seat ' . $highestSeat . '.');
            }

            $stmt = $db->prepare("
                UPDATE poker_tables
                SET
                    name=?,
                    min_buyin=?,
                    max_buyin=?,
                    starting_small_blind=?,
                    starting_big_blind=?,
                    blind_hands_per_level=?,
                    max_seats=?,
                    small_blind=CASE WHEN status='waiting' THEN ? ELSE small_blind END,
                    big_blind=CASE WHEN status='waiting' THEN ? ELSE big_blind END,
                    min_raise=CASE WHEN status='waiting' THEN ? ELSE min_raise END
                WHERE id=?
            ");
            $stmt->bind_param(
                'siiiiiiiiii',
                $name,
                $minBuyin,
                $maxBuyin,
                $startingSmallBlind,
                $startingBigBlind,
                $blindHandsPerLevel,
                $maxSeats,
                $startingSmallBlind,
                $startingBigBlind,
                $startingBigBlind,
                $tableId
            );
            $stmt->execute();

            if ($stmt->affected_rows === 0) {
                $check = $db->prepare('SELECT id FROM poker_tables WHERE id=? LIMIT 1');
                $check->bind_param('i', $tableId);
                $check->execute();
                $exists = $check->get_result()->fetch_assoc();
                $check->close();

                if (!$exists) {
                    $stmt->close();
                    throw new RuntimeException('Poker table not found.');
                }
            }

            $stmt->close();
            poker_admin_redirect('Poker table updated: ' . $name);
        }

        if ($action === 'create_tournament') {
            $name=isset($_POST['name'])?trim((string)$_POST['name']):'';
            $entry=poker_admin_parse_buyin(isset($_POST['entry_amount'])?$_POST['entry_amount']:'',isset($_POST['entry_unit'])?$_POST['entry_unit']:'GB');
            $chips=isset($_POST['starting_chips'])?(int)$_POST['starting_chips']:10000;
            $sb=isset($_POST['tournament_sb'])?(int)$_POST['tournament_sb']:50;
            $bb=isset($_POST['tournament_bb'])?(int)$_POST['tournament_bb']:100;
            $interval=isset($_POST['blind_hands_per_level'])?(int)$_POST['blind_hands_per_level']:5;
            $seats=isset($_POST['max_seats'])?(int)$_POST['max_seats']:10;
            if($name===''||strlen($name)<2||strlen($name)>64) throw new RuntimeException('Tournament name must be between 2 and 64 characters.');
            if($entry<=0||$chips<100||$sb<=0||$bb<=$sb||$interval<1||$interval>100||$seats<2||$seats>10) throw new RuntimeException('Invalid tournament configuration.');
            $empty='[]'; $msg='Tournament registration is open.';
            $stmt=$db->prepare("INSERT INTO poker_tables (name,small_blind,big_blind,starting_small_blind,starting_big_blind,blind_hands_per_level,min_buyin,max_buyin,max_seats,status,street,current_bet,min_raise,deck_json,community_json,hand_no,last_message,game_type,tournament_status,tournament_entry_fee,tournament_starting_stack,tournament_prize_pool,tournament_entries) VALUES (?,?,?,?,?,?,?, ?,?,'waiting','preflop',0,?,?,?,0,?,'tournament','registration',?,?,0,0)");
            $dummyMin=$entry; $dummyMax=$chips;
            $stmt->bind_param('siiiiiiiiisssii',$name,$sb,$bb,$sb,$bb,$interval,$dummyMin,$dummyMax,$seats,$bb,$empty,$empty,$msg,$entry,$chips);
            $stmt->execute(); $stmt->close();
            poker_admin_redirect('Tournament created: '.$name);
        }

        if ($action === 'start_tournament') {
            $tableId = isset($_POST['table_id']) ? (int) $_POST['table_id'] : 0;
            poker_start_tournament($db, $tableId, $CURUSER);
            poker_admin_redirect('Tournament started. Registration is now closed.');
        }

        if ($action === 'kick_player') {
            $tableId = isset($_POST['table_id']) ? (int) $_POST['table_id'] : 0;
            $targetUserId = isset($_POST['user_id']) ? (int) $_POST['user_id'] : 0;
            $kickReason = isset($_POST['kick_reason']) ? trim((string) $_POST['kick_reason']) : '';

            if (strlen($kickReason) > 255) {
                throw new RuntimeException('Kick reason cannot exceed 255 characters.');
            }

            if ($tableId <= 0 || $targetUserId <= 0) {
                throw new RuntimeException('Select a seated player to kick.');
            }

            $stmt = $db->prepare('SELECT t.name,t.status,t.game_type,s.seat_no,s.username,s.hand_state FROM poker_tables t INNER JOIN poker_seats s ON s.table_id=t.id WHERE t.id=? AND s.user_id=? LIMIT 1');
            $stmt->bind_param('ii', $tableId, $targetUserId);
            $stmt->execute();
            $kickRow = $stmt->get_result()->fetch_assoc();
            $stmt->close();

            if (!$kickRow) {
                throw new RuntimeException('That player is no longer seated at this table.');
            }

            if ((string) $kickRow['game_type'] === 'house') {
                throw new RuntimeException('House sessions are private games and are not managed by the multiplayer kick control.');
            }

            if ((string) $kickRow['status'] === 'playing') {
                throw new RuntimeException($kickRow['username'] . ' is in a hand right now. Kick them after the hand finishes.');
            }

            poker_leave($db, $tableId, $targetUserId);

            $kickMessage = 'You have been removed from ' . $kickRow['name'] . ' by an administrator.';
            if ($kickReason !== '') {
                $kickMessage .= ' Reason: ' . $kickReason;
            }
            $kickMessage .= ' Your remaining stack has been returned to your upload credit.';

            poker_create_user_notice(
                $db,
                $targetUserId,
                $tableId,
                'admin_kick',
                'Removed from Poker Table',
                $kickMessage,
                (int) $CURUSER['id']
            );

            poker_admin_redirect('Kicked ' . $kickRow['username'] . ' from ' . $kickRow['name'] . '. Their remaining stack was returned to upload credit.' . ($kickReason !== '' ? ' Reason: ' . $kickReason : ''));
        }

        if ($action === 'clear_chat') {
            $tableId = isset($_POST['table_id']) ? (int) $_POST['table_id'] : 0;

            if ($tableId <= 0) {
                throw new RuntimeException('Invalid poker table.');
            }

            $stmt = $db->prepare('SELECT name FROM poker_tables WHERE id=? LIMIT 1');
            $stmt->bind_param('i', $tableId);
            $stmt->execute();
            $table = $stmt->get_result()->fetch_assoc();
            $stmt->close();

            if (!$table) {
                throw new RuntimeException('Poker table not found.');
            }

            $stmt = $db->prepare('DELETE FROM poker_chat WHERE table_id=?');
            $stmt->bind_param('i', $tableId);
            $stmt->execute();
            $deleted = $stmt->affected_rows;
            $stmt->close();

            poker_admin_redirect('Cleared ' . $deleted . ' chat message' . ($deleted === 1 ? '' : 's') . ' from ' . $table['name'] . '.');
        }

        if ($action === 'delete_table') {
            $tableId = isset($_POST['table_id']) ? (int) $_POST['table_id'] : 0;

            if ($tableId <= 0) {
                throw new RuntimeException('Invalid poker table.');
            }

            $db->begin_transaction();

            try {
                $stmt = $db->prepare('SELECT id,name,status,game_type FROM poker_tables WHERE id=? FOR UPDATE');
                $stmt->bind_param('i', $tableId);
                $stmt->execute();
                $table = $stmt->get_result()->fetch_assoc();
                $stmt->close();

                if (!$table) {
                    throw new RuntimeException('Poker table not found.');
                }

                $stmt = $db->prepare('SELECT COUNT(*) AS player_count FROM poker_seats WHERE table_id=? FOR UPDATE');
                $stmt->bind_param('i', $tableId);
                $stmt->execute();
                $row = $stmt->get_result()->fetch_assoc();
                $stmt->close();

                $playerCount = $row ? (int) $row['player_count'] : 0;

                if ($playerCount > 0) {
                    throw new RuntimeException('Cannot remove ' . $table['name'] . ' while ' . $playerCount . ' player' . ($playerCount === 1 ? ' is' : 's are') . ' seated.');
                }

                if ((string)$table['game_type'] === 'house') {
                    $stmt = $db->prepare('SELECT COUNT(*) AS session_count FROM poker_house_sessions WHERE template_id=? AND active=1');
                    $stmt->bind_param('i', $tableId);
                    $stmt->execute();
                    $houseSessionRow = $stmt->get_result()->fetch_assoc();
                    $stmt->close();
                    $houseSessionCount = $houseSessionRow ? (int)$houseSessionRow['session_count'] : 0;
                    if ($houseSessionCount > 0) {
                        throw new RuntimeException('Cannot remove ' . $table['name'] . ' while ' . $houseSessionCount . ' House game' . ($houseSessionCount === 1 ? ' is' : 's are') . ' active.');
                    }
                }

                if ((string) $table['status'] !== 'waiting') {
                    throw new RuntimeException('Cannot remove ' . $table['name'] . ' while the table is active.');
                }

                if ((string)$table['game_type'] === 'house') {
                    $stmt = $db->prepare('DELETE FROM poker_house_sessions WHERE template_id=? AND active=0');
                    $stmt->bind_param('i', $tableId);
                    $stmt->execute();
                    $stmt->close();
                }

                $cleanupTables = array('poker_chat', 'poker_actions', 'poker_hand_history', 'poker_spectators');

                foreach ($cleanupTables as $cleanupTable) {
                    $stmt = $db->prepare('DELETE FROM ' . $cleanupTable . ' WHERE table_id=?');
                    $stmt->bind_param('i', $tableId);
                    $stmt->execute();
                    $stmt->close();
                }

                $stmt = $db->prepare('DELETE FROM poker_tables WHERE id=?');
                $stmt->bind_param('i', $tableId);
                $stmt->execute();

                if ($stmt->affected_rows !== 1) {
                    $stmt->close();
                    throw new RuntimeException('Unable to remove poker table.');
                }

                $stmt->close();
                $db->commit();

                poker_admin_redirect('Removed poker table: ' . $table['name']);
            } catch (Throwable $e) {
                $db->rollback();
                throw $e;
            }
        }

        if ($action === 'set_maintenance') {
            $enable = isset($_POST['maintenance_mode']) && (int) $_POST['maintenance_mode'] === 1;

            $db->begin_transaction();

            try {
                poker_lock_settings($db);

                $stmt = $db->prepare('SELECT COUNT(*) AS player_count FROM poker_seats');
                $stmt->execute();
                $row = $stmt->get_result()->fetch_assoc();
                $stmt->close();
                $playerCount = $row ? (int) $row['player_count'] : 0;

                if ($enable) {
                    // 1 = draining, 2 = fully offline. Empty rooms can go offline immediately.
                    $maintenanceValue = $playerCount > 0 ? 1 : 2;
                } else {
                    $maintenanceValue = 0;
                }

                $updatedBy = (int) $CURUSER['id'];

                $stmt = $db->prepare('UPDATE poker_settings SET maintenance_mode=?,updated_at=NOW(),updated_by=? WHERE id=1');
                $stmt->bind_param('ii', $maintenanceValue, $updatedBy);
                $stmt->execute();
                $stmt->close();

                if ($enable) {
                    $stmt = $db->prepare('SELECT DISTINCT table_id FROM poker_seats');
                    $stmt->execute();
                    $result = $stmt->get_result();
                    while ($seatTable = $result->fetch_assoc()) {
                        $seatTableId = (int) $seatTable['table_id'];
                        $tableRow = poker_get_table($db, $seatTableId);
                        $dealerText = ($tableRow && (string) $tableRow['status'] === 'playing')
                            ? 'Poker maintenance is starting. This is the final hand; please cash out when it finishes.'
                            : 'Poker maintenance is starting. No new hands will begin; please leave the table to cash out.';
                        poker_dealer_message($db, $seatTableId, $dealerText);
                    }
                    $stmt->close();
                } else {
                    $stmt = $db->prepare('SELECT id FROM poker_tables');
                    $stmt->execute();
                    $result = $stmt->get_result();
                    while ($tableRow = $result->fetch_assoc()) {
                        poker_dealer_message($db, (int) $tableRow['id'], 'Poker maintenance has ended. Tables are open again.');
                    }
                    $stmt->close();
                }

                $db->commit();

                if (!$enable) {
                    poker_admin_redirect('Poker maintenance mode disabled. Poker is open to users again.');
                }

                if ($maintenanceValue === 1) {
                    poker_admin_redirect(
                        'Poker maintenance is draining. Current hands may finish; no new players or hands can begin. ' .
                        $playerCount . ' seated player' . ($playerCount === 1 ? ' remains.' : 's remain.')
                    );
                }

                poker_admin_redirect('Poker maintenance mode enabled. All tables are offline.');
            } catch (Throwable $e) {
                $db->rollback();
                throw $e;
            }
        }

        throw new RuntimeException('Unknown admin action.');
    } catch (Throwable $e) {
        $formError = $e->getMessage();
    }
}

$stmt = $db->prepare("SELECT
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
    t.game_type,t.tournament_status,t.tournament_entry_fee,t.tournament_starting_stack,t.tournament_prize_pool,t.tournament_entries,
    t.status,
    t.hand_no,
    COUNT(DISTINCT CASE WHEN s.user_id>0 THEN s.seat_no END) AS player_count,
    COUNT(DISTINCT c.id) AS chat_count,
    (SELECT COUNT(*)
     FROM poker_spectators ps
     WHERE ps.table_id=t.id
       AND ps.last_seen_at >= DATE_SUB(NOW(), INTERVAL 12 SECOND)) AS spectator_count
FROM poker_tables t
LEFT JOIN poker_seats s ON s.table_id=t.id
LEFT JOIN poker_chat c ON c.table_id=t.id
GROUP BY
    t.id,t.name,t.small_blind,t.big_blind,t.starting_small_blind,
    t.starting_big_blind,t.blind_hands_per_level,t.min_buyin,t.max_buyin,
    t.max_seats,t.game_type,t.tournament_status,t.tournament_entry_fee,t.tournament_starting_stack,t.tournament_prize_pool,t.tournament_entries,t.status,t.hand_no
ORDER BY t.id ASC");
$stmt->execute();
$result = $stmt->get_result();
$tables = array();
while ($row = $result->fetch_assoc()) {
    $tables[] = $row;
}
$stmt->close();

$seatedPlayersByTable = array();
$stmt = $db->prepare("SELECT table_id,user_id,seat_no,username,hand_state FROM poker_seats WHERE user_id>0 ORDER BY table_id ASC, seat_no ASC");
$stmt->execute();
$result = $stmt->get_result();
while ($playerRow = $result->fetch_assoc()) {
    $playerTableId = (int) $playerRow['table_id'];
    if (!isset($seatedPlayersByTable[$playerTableId])) {
        $seatedPlayersByTable[$playerTableId] = array();
    }
    $seatedPlayersByTable[$playerTableId][] = $playerRow;
}
$stmt->close();

$maintenanceState = poker_maintenance_state($db);
$maintenanceEnabled = $maintenanceState !== 0;

$stmt = $db->prepare('SELECT COUNT(*) AS player_count FROM poker_seats WHERE user_id>0');
$stmt->execute();
$row = $stmt->get_result()->fetch_assoc();
$stmt->close();
$totalSeatedPlayers = $row ? (int) $row['player_count'] : 0;

$starterGrants = array();
$stmt = $db->prepare('SELECT g.user_id,g.amount,g.credit_before,g.claimed_at,u.username FROM poker_starter_grants g LEFT JOIN users u ON u.id=g.user_id ORDER BY g.id DESC LIMIT 25');
$stmt->execute();
$result = $stmt->get_result();
while ($grantRow = $result->fetch_assoc()) {
    $starterGrants[] = $grantRow;
}
$stmt->close();

$message = isset($_GET['message']) ? trim((string) $_GET['message']) : '';
$messageType = isset($_GET['type']) && $_GET['type'] === 'error' ? 'error' : 'success';
$title = 'Poker Admin Manager';

if (function_exists('stdhead')) {
    stdhead($title);
}

if (function_exists('begin_frame')) {
    begin_frame($title);
}
?>
<style>
    #poker-admin {
        width: min(1120px, calc(100% - 8px));
        margin: 16px auto;
        color: #ddd;
        font-family: Arial, Helvetica, sans-serif;
    }

    #poker-admin * {
        box-sizing: border-box;
    }

    #poker-admin .admin-head,
    #poker-admin .admin-card {
        background: #151515;
        border: 1px solid #383838;
        border-radius: 7px;
    }

    #poker-admin .admin-head {
        display: flex;
        justify-content: space-between;
        align-items: center;
        gap: 20px;
        padding: 14px 18px;
        margin-bottom: 14px;
    }

    #poker-admin .admin-title {
        margin: 0;
        color: #fff;
        font-size: 22px;
    }

    #poker-admin .admin-subtitle {
        margin-top: 4px;
        color: #777;
        font-size: 12px;
    }

    #poker-admin .back-link,
    #poker-admin button,
    #poker-admin .open-link {
        display: inline-flex;
        align-items: center;
        justify-content: center;
        min-height: 32px;
        padding: 7px 11px;
        border: 1px solid #555;
        border-radius: 4px;
        background: #292929;
        color: #eee;
        text-decoration: none;
        font: inherit;
        font-weight: 700;
        cursor: pointer;
    }

    #poker-admin .back-link:hover,
    #poker-admin button:hover,
    #poker-admin .open-link:hover {
        background: #393939;
    }

    #poker-admin .notice {
        margin-bottom: 14px;
        padding: 10px 13px;
        border: 1px solid #315437;
        border-radius: 5px;
        background: #182b1b;
        color: #a9e0af;
    }

    #poker-admin .notice.error {
        border-color: #623838;
        background: #351b1b;
        color: #f0a5a5;
    }

    #poker-admin .form-notice {
        margin: 0 0 12px;
    }

    #poker-admin .form-error-target {
        border-color: #8a4646;
        box-shadow: 0 0 0 1px rgba(138, 70, 70, .28);
    }

    #poker-admin .blind-rule-note {
        margin-top: 10px;
        padding: 8px 10px;
        border: 1px solid #315437;
        border-radius: 3px;
        background: #182b1b;
        color: #a9e0af;
        font-size: 11px;
        font-weight: 800;
        text-align: center;
    }

    #poker-admin .blind-rule-note.error {
        border-color: #b34d4d;
        background: #321919;
        color: #f0a5a5;
    }

    #poker-admin .blind-rule-note.pending {
        border-color: #775c2d;
        background: #2a2112;
        color: #d9b875;
    }

    #poker-admin .buyin-editor.blind-rule-field input,
    #poker-admin .buyin-editor.blind-rule-field select {
        border-color: #b34d4d;
        box-shadow: 0 0 0 1px rgba(179, 77, 77, .3);
    }

    #poker-admin .admin-card {
        margin-bottom: 14px;
        padding: 15px;
    }

    #poker-admin .card-title {
        margin: 0 0 12px;
        color: #fff;
        font-size: 16px;
    }

    #poker-admin .create-grid {
        display: grid;
        grid-template-columns: repeat(4, minmax(0, 1fr));
        gap: 12px 14px;
        align-items: end;
    }

    #poker-admin .create-grid > div:first-child {
        grid-column: span 2;
    }

    #poker-admin .create-grid > div:last-child {
        display: flex;
        justify-content: flex-end;
        align-items: end;
    }

    #poker-admin .create-grid > div:last-child .save-button {
        width: 100%;
        min-height: 34px;
    }

    #poker-admin .create-info {
        min-height: 34px;
        display: flex;
        align-items: center;
        padding: 7px 10px;
        border: 1px solid #3d4d35;
        border-radius: 4px;
        background: #182018;
        color: #b9d7a8;
        font-size: 12px;
        font-weight: 700;
        white-space: nowrap;
    }

    #poker-admin label {
        display: block;
        margin-bottom: 5px;
        color: #999;
        font-size: 11px;
        font-weight: 700;
        text-transform: uppercase;
    }

    #poker-admin input,
    #poker-admin select {
        width: 100%;
        min-height: 34px;
        padding: 7px 8px;
        border: 1px solid #444;
        border-radius: 4px;
        background: #0d0d0d;
        color: #eee;
    }

    #poker-admin .starter-grants-table {
        width: 100%;
        border-collapse: collapse;
        font-size: 11px;
    }

    #poker-admin .starter-grants-table th,
    #poker-admin .starter-grants-table td {
        padding: 8px 9px;
        border-bottom: 1px solid #2b2b2b;
        text-align: left;
    }

    #poker-admin .starter-grants-table th {
        color: #888;
        font-size: 10px;
        text-transform: uppercase;
    }

    #poker-admin .starter-grants-table td {
        color: #ccc;
    }

    #poker-admin .starter-grants-empty {
        color: #777;
        font-size: 12px;
    }

    #poker-admin .manage-tables-section {
        background: #1b160d;
        border-color: #ff8c00;
    }

    #poker-admin .manage-tables-section .card-title {
        color: #ff9f1a;
    }

    #poker-admin .manage-list {
        display: grid;
        gap: 12px;
    }

    #poker-admin .manage-table-card {
        padding: 13px;
        border: 1px solid #303030;
        border-radius: 6px;
        background: #101010;
    }

    #poker-admin .manage-table-card:hover {
        border-color: #3d3d3d;
    }

    #poker-admin .manage-table-card.table-type-multiplayer {
        box-shadow: inset 3px 0 0 #3b82c4;
    }

    #poker-admin .manage-table-card.table-type-house {
        box-shadow: inset 3px 0 0 #9b59b6;
    }

    #poker-admin .manage-table-card.table-type-tournament {
        box-shadow: inset 3px 0 0 #d89a34;
    }

    #poker-admin .table-card-head {
        display: flex;
        align-items: center;
        justify-content: space-between;
        gap: 16px;
        margin-bottom: 11px;
        padding-bottom: 9px;
        border-bottom: 1px solid #292929;
    }

    #poker-admin .table-card-id {
        color: #777;
        font-size: 10px;
        font-weight: 700;
        text-transform: uppercase;
    }

    #poker-admin .table-card-name {
        margin-left: 7px;
        color: #fff;
        font-size: 14px;
        font-weight: 700;
    }

    #poker-admin .table-type-badge {
        display: inline-flex;
        align-items: center;
        min-height: 22px;
        padding: 4px 9px;
        border: 1px solid;
        border-radius: 12px;
        font-size: 9px;
        font-weight: 800;
        letter-spacing: .45px;
        line-height: 1;
        text-transform: uppercase;
        white-space: nowrap;
    }

    #poker-admin .table-type-badge.multiplayer {
        border-color: #356f9e;
        background: #162a3b;
        color: #88c9f7;
    }

    #poker-admin .table-type-badge.house {
        border-color: #70447f;
        background: #29182f;
        color: #d5a1e5;
    }

    #poker-admin .table-type-badge.tournament {
        border-color: #86601f;
        background: #30230f;
        color: #f1c268;
    }

    #poker-admin .manage-settings-layout {
        display: grid;
        grid-template-columns: minmax(250px, 1.25fr) minmax(310px, 1fr) minmax(310px, 1fr);
        gap: 10px;
        align-items: stretch;
    }

    #poker-admin .manage-settings-layout.house-settings {
        grid-template-columns: minmax(250px, 1.15fr) minmax(310px, 1fr) minmax(310px, 1fr);
    }

    #poker-admin .setting-group {
        padding: 11px;
        border: 1px solid #2d2d2d;
        border-radius: 5px;
        background: #151515;
    }

    #poker-admin .setting-group-title {
        margin: 0 0 9px;
        padding-bottom: 6px;
        border-bottom: 1px solid #292929;
        color: #d58b2a;
        font-size: 10px;
        font-weight: 700;
        letter-spacing: .5px;
        text-transform: uppercase;
    }

    #poker-admin .setting-fields {
        display: grid;
        grid-template-columns: repeat(2, minmax(0, 1fr));
        gap: 9px;
        align-items: end;
    }

    #poker-admin .setting-fields.single-field {
        grid-template-columns: 1fr;
    }

    #poker-admin .table-setup-fields {
        grid-template-columns: minmax(150px, 1.5fr) minmax(80px, .7fr) minmax(70px, .6fr);
    }

    #poker-admin .house-settings .table-setup-fields {
        grid-template-columns: 1fr;
    }

    #poker-admin .setting-group label {
        white-space: nowrap;
    }

    #poker-admin .tournament-settings {
        grid-template-columns: repeat(5, minmax(0, 1fr));
    }

    #poker-admin .tournament-settings .setting-group {
        min-width: 0;
    }

    #poker-admin .house-info-subtext {
        margin-top: 9px;
        color: #87939c;
        font-size: 11px;
        line-height: 1.5;
    }

    #poker-admin .table-name-input,
    #poker-admin .number-input,
    #poker-admin .seat-select {
        width: 100%;
        min-width: 0;
    }

    #poker-admin .buyin-editor {
        display: grid;
        grid-template-columns: minmax(72px, 1fr) 50px;
        gap: 5px;
        width: 100%;
    }

    #poker-admin .buyin-editor input,
    #poker-admin .buyin-editor select {
        min-width: 0;
    }

    #poker-admin .buyin-editor select {
        padding-left: 5px;
        padding-right: 5px;
        font-size: 11px;
    }

    #poker-admin .table-card-footer {
        display: flex;
        align-items: center;
        justify-content: space-between;
        gap: 12px;
        margin-top: 11px;
        padding-top: 10px;
        border-top: 1px solid #292929;
    }

    #poker-admin .table-meta {
        display: flex;
        align-items: center;
        gap: 18px;
        min-width: 0;
    }

    #poker-admin .table-meta-item {
        color: #aaa;
        font-size: 11px;
        white-space: nowrap;
    }

    #poker-admin .table-meta-label {
        display: block;
        margin-bottom: 2px;
        color: #666;
        font-size: 9px;
        font-weight: 700;
        text-transform: uppercase;
    }

    #poker-admin .table-card-actions,
    #poker-admin .table-card-danger {
        display: flex;
        align-items: center;
        gap: 6px;
        flex-wrap: nowrap;
    }

    #poker-admin .table-card-danger {
        justify-content: flex-end;
        margin-top: 8px;
    }

    #poker-admin .status {
        display: inline-block;
        min-width: 70px;
        padding: 4px 7px;
        border-radius: 12px;
        background: #252525;
        color: #bbb;
        text-align: center;
        font-size: 9px;
        font-weight: 700;
        text-transform: uppercase;
    }

    #poker-admin .status.playing {
        background: #173220;
        color: #87d99c;
    }

    #poker-admin .status.showdown {
        background: #332b17;
        color: #dfca82;
    }

    #poker-admin .row-actions {
        display: flex;
        gap: 4px;
        flex-wrap: nowrap;
    }

    #poker-admin .row-actions button,
    #poker-admin .row-actions .open-link {
        min-height: 28px;
        padding: 5px 7px;
        font-size: 10px;
    }

    #poker-admin .save-button {
        border-color: #4b6538;
        background: #26351d;
        color: #bde39d;
    }

    #poker-admin .danger-button {
        border-color: #684040;
        background: #3b2020;
        color: #efaaaa;
    }

    #poker-admin .kick-player-form {
        display: flex;
        align-items: center;
        gap: 6px;
    }

    #poker-admin .kick-player-form select,
    #poker-admin .kick-player-form input[type="text"] {
        min-height: 30px;
        padding: 5px 7px;
        border: 1px solid #4d3434;
        border-radius: 4px;
        background: #171212;
        color: #ddd;
        font-size: 10px;
    }

    #poker-admin .kick-player-form select {
        min-width: 165px;
        max-width: 220px;
    }

    #poker-admin .kick-player-form input[type="text"] {
        width: 210px;
    }

    #poker-admin .kick-player-button {
        border-color: #8a4a32;
        background: #4a2519;
        color: #ffc1a8;
    }

    #poker-admin .small-note {
        margin-top: 9px;
        color: #666;
        font-size: 10px;
    }

    #poker-admin .maintenance-card {
        display: flex;
        flex-wrap: wrap;
        align-items: center;
        justify-content: space-between;
        gap: 20px;
    }

    #poker-admin .maintenance-state {
        display: inline-block;
        margin-left: 8px;
        padding: 4px 9px;
        border-radius: 12px;
        background: #18351f;
        color: #9ae2a9;
        font-size: 10px;
        font-weight: 700;
        text-transform: uppercase;
    }

    #poker-admin .maintenance-state.draining {
        color: #e3c96f;
    }

    #poker-admin .maintenance-state.offline {
        background: #442020;
        color: #f0aaaa;
    }

    #poker-admin .maintenance-info {
        color: #888;
        font-size: 12px;
        line-height: 1.5;
    }

    #poker-admin .maintenance-card .form-notice {
        width: 100%;
    }

    #poker-admin .maintenance-button {
        white-space: nowrap;
    }
</style>

<div id="poker-admin">
    <div class="admin-head">
        <div>
            <h2 class="admin-title">Poker Admin Manager</h2>
            <div class="admin-subtitle">Create and configure game tables, control maintenance, inspect activity, and manage table data.</div>
        </div>
        <a class="back-link" href="poker-lobby.php">Back to Poker Lobby</a>
    </div>

    <?php if ($message !== '' && $messageType !== 'error') { ?>
        <div class="notice <?php echo $messageType === 'error' ? 'error' : ''; ?>">
            <?php echo htmlspecialchars($message, ENT_QUOTES, 'UTF-8'); ?>
        </div>
    <?php } ?>

    <div id="maintenance" class="admin-card maintenance-card<?php echo $formTarget === 'maintenance' && $formError !== '' ? ' form-error-target' : ''; ?>">
        <?php if ($formTarget === 'maintenance' && $formError !== '') { ?><div class="notice error form-notice"><?php echo htmlspecialchars($formError, ENT_QUOTES, 'UTF-8'); ?></div><?php } ?>
        <div>
            <h3 class="card-title">
                Poker Maintenance: Tables are
                <span class="maintenance-state <?php echo $maintenanceState === 1 ? 'draining' : ($maintenanceState === 2 ? 'offline' : ''); ?>">
                    <?php echo $maintenanceState === 1 ? 'Draining' : ($maintenanceState === 2 ? 'Offline' : 'Online'); ?>
                </span>
            </h3>
            <div class="maintenance-info">
                <?php if ($maintenanceState === 1) { ?>
                    Maintenance is pending. Current hands may finish, but no new players or new hands can begin.
                    Players can leave after the hand and their remaining stacks will be returned to upload credit.
                <?php } elseif ($maintenanceState === 2) { ?>
                    Admin only access while maintenance is enabled.
                <?php } else { ?>
                    Users currently have normal poker access. Enabling maintenance will gracefully drain active tables and allow users to cash out.
                <?php } ?>
                Seated players: <strong><?php echo $totalSeatedPlayers; ?></strong>.
            </div>
        </div>
        <form method="post" action="poker-admin.php" onsubmit="return confirm('<?php echo $maintenanceEnabled ? 'Turn poker back on for users?' : 'Enable maintenance? Active hands will be allowed to finish, then players can cash out and tables will close.'; ?>');">
            <input type="hidden" name="csrf" value="<?php echo htmlspecialchars($_SESSION['poker_csrf'], ENT_QUOTES, 'UTF-8'); ?>">
            <input type="hidden" name="action" value="set_maintenance">
            <input type="hidden" name="maintenance_mode" value="<?php echo $maintenanceEnabled ? '0' : '1'; ?>">
            <button class="maintenance-button <?php echo $maintenanceEnabled ? 'save-button' : 'danger-button'; ?>" type="submit">
                <?php echo $maintenanceEnabled ? 'Disable Maintenance' : 'Enable Maintenance'; ?>
            </button>
        </form>
    </div>

    <div id="create-table" class="admin-card<?php echo $formTarget === 'create-table' && $formError !== '' ? ' form-error-target' : ''; ?>">
        <h3 class="card-title">Create Multiplayer Table</h3>
        <?php if ($formTarget === 'create-table' && $formError !== '') { ?><div class="notice error form-notice"><?php echo htmlspecialchars($formError, ENT_QUOTES, 'UTF-8'); ?></div><?php } ?>
        <form method="post" action="poker-admin.php" autocomplete="off">
            <input type="hidden" name="csrf" value="<?php echo htmlspecialchars($_SESSION['poker_csrf'], ENT_QUOTES, 'UTF-8'); ?>">
            <input type="hidden" name="action" value="create_table">

            <div class="create-grid">
                <div>
                    <label for="new_name">Table Name</label>
                    <input id="new_name" class="clear-placeholder-on-focus" type="text" name="name" maxlength="64" placeholder="Friday Night Table" value="<?php echo htmlspecialchars(poker_admin_post_value('create-table', 'name'), ENT_QUOTES, 'UTF-8'); ?>" autocomplete="off" required>
                </div>
                <div>
                    <label for="new_min">Min Buy-In</label>
                    <div class="buyin-editor">
                        <input id="new_min" type="number" name="min_buyin_amount" min="1" step="1" value="<?php echo htmlspecialchars(poker_admin_post_value('create-table', 'min_buyin_amount', '1'), ENT_QUOTES, 'UTF-8'); ?>" autocomplete="off" required>
                        <select name="min_buyin_unit" aria-label="Minimum buy-in unit">
                            <option value="MB"<?php echo poker_admin_selected('create-table', 'min_buyin_unit', 'MB', 'GB'); ?>>MB</option>
                            <option value="GB"<?php echo poker_admin_selected('create-table', 'min_buyin_unit', 'GB', 'GB'); ?>>GB</option>
                        </select>
                    </div>
                </div>
                <div>
                    <label for="new_max">Max Buy-In</label>
                    <div class="buyin-editor">
                        <input id="new_max" type="number" name="max_buyin_amount" min="1" step="1" value="<?php echo htmlspecialchars(poker_admin_post_value('create-table', 'max_buyin_amount', '200'), ENT_QUOTES, 'UTF-8'); ?>" autocomplete="off" required>
                        <select name="max_buyin_unit" aria-label="Maximum buy-in unit">
                            <option value="MB"<?php echo poker_admin_selected('create-table', 'max_buyin_unit', 'MB', 'GB'); ?>>MB</option>
                            <option value="GB"<?php echo poker_admin_selected('create-table', 'max_buyin_unit', 'GB', 'GB'); ?>>GB</option>
                        </select>
                    </div>
                </div>
                <div>
                    <label for="new_sb">Starting Small Blind</label>
                    <div class="buyin-editor">
                        <input id="new_sb" type="number" name="starting_small_blind_amount" min="1" step="1" value="<?php echo htmlspecialchars(poker_admin_post_value('create-table', 'starting_small_blind_amount', '100'), ENT_QUOTES, 'UTF-8'); ?>" autocomplete="off" required>
                        <select name="starting_small_blind_unit" aria-label="Starting small blind unit">
                            <option value="MB"<?php echo poker_admin_selected('create-table', 'starting_small_blind_unit', 'MB', 'MB'); ?>>MB</option>
                            <option value="GB"<?php echo poker_admin_selected('create-table', 'starting_small_blind_unit', 'GB', 'MB'); ?>>GB</option>
                        </select>
                    </div>
                </div>
                <div>
                    <label for="new_bb">Starting Big Blind</label>
                    <div class="buyin-editor">
                        <input id="new_bb" type="number" name="starting_big_blind_amount" min="1" step="1" value="<?php echo htmlspecialchars(poker_admin_post_value('create-table', 'starting_big_blind_amount', '200'), ENT_QUOTES, 'UTF-8'); ?>" autocomplete="off" required>
                        <select name="starting_big_blind_unit" aria-label="Starting big blind unit">
                            <option value="MB"<?php echo poker_admin_selected('create-table', 'starting_big_blind_unit', 'MB', 'MB'); ?>>MB</option>
                            <option value="GB"<?php echo poker_admin_selected('create-table', 'starting_big_blind_unit', 'GB', 'MB'); ?>>GB</option>
                        </select>
                    </div>
                </div>
                <div>
                    <label for="new_interval">Increase Blinds Every (Hands)</label>
                    <input id="new_interval" type="number" name="blind_hands_per_level" min="1" max="100" step="1" value="<?php echo htmlspecialchars(poker_admin_post_value('create-table', 'blind_hands_per_level', '5'), ENT_QUOTES, 'UTF-8'); ?>" autocomplete="off" required>
                </div>
                <div>
                    <label for="new_seats">Seats</label>
                    <select id="new_seats" name="max_seats">
                        <?php for ($i = 2; $i <= 10; $i++) { ?>
                            <option value="<?php echo $i; ?>"<?php echo poker_admin_selected('create-table', 'max_seats', $i, '10'); ?>><?php echo $i; ?></option>
                        <?php } ?>
                    </select>
                </div>
                <div>
                    <button class="save-button" type="submit">Create Table</button>
                </div>
            </div>
        </form>
        <div class="blind-rule-note">&#10003; Buy-In / Big Blind: OK</div>
        <div class="small-note">Small and big blinds are forced starting bets. Both increase after the selected number of completed hands.</div>
    </div>

    <div id="create-house" class="admin-card<?php echo $formTarget === 'create-house' && $formError !== '' ? ' form-error-target' : ''; ?>">
        <h3 class="card-title">Create House Table</h3>
        <?php if ($formTarget === 'create-house' && $formError !== '') { ?><div class="notice error form-notice"><?php echo htmlspecialchars($formError, ENT_QUOTES, 'UTF-8'); ?></div><?php } ?>
        <form method="post" action="poker-admin.php" autocomplete="off">
            <input type="hidden" name="csrf" value="<?php echo htmlspecialchars($_SESSION['poker_csrf'], ENT_QUOTES, 'UTF-8'); ?>">
            <input type="hidden" name="action" value="create_house">
            <input type="hidden" name="max_seats" value="10">
            <div class="create-grid">
                <div><label>House Table Name</label><input class="clear-placeholder-on-focus" type="text" name="name" maxlength="64" placeholder="Play the House" value="<?php echo htmlspecialchars(poker_admin_post_value('create-house', 'name'), ENT_QUOTES, 'UTF-8'); ?>" autocomplete="off" required></div>
                <div><label>Min Buy-In</label><div class="buyin-editor"><input type="number" name="min_buyin_amount" min="1" step="1" value="<?php echo htmlspecialchars(poker_admin_post_value('create-house', 'min_buyin_amount', '5'), ENT_QUOTES, 'UTF-8'); ?>" autocomplete="off" required><select name="min_buyin_unit"><option value="MB"<?php echo poker_admin_selected('create-house', 'min_buyin_unit', 'MB', 'GB'); ?>>MB</option><option value="GB"<?php echo poker_admin_selected('create-house', 'min_buyin_unit', 'GB', 'GB'); ?>>GB</option></select></div></div>
                <div><label>Max Buy-In</label><div class="buyin-editor"><input type="number" name="max_buyin_amount" min="1" step="1" value="<?php echo htmlspecialchars(poker_admin_post_value('create-house', 'max_buyin_amount', '20'), ENT_QUOTES, 'UTF-8'); ?>" autocomplete="off" required><select name="max_buyin_unit"><option value="MB"<?php echo poker_admin_selected('create-house', 'max_buyin_unit', 'MB', 'GB'); ?>>MB</option><option value="GB"<?php echo poker_admin_selected('create-house', 'max_buyin_unit', 'GB', 'GB'); ?>>GB</option></select></div></div>
                <div><label>Starting Small Blind</label><div class="buyin-editor"><input type="number" name="starting_small_blind_amount" min="1" step="1" value="<?php echo htmlspecialchars(poker_admin_post_value('create-house', 'starting_small_blind_amount', '100'), ENT_QUOTES, 'UTF-8'); ?>" autocomplete="off" required><select name="starting_small_blind_unit"><option value="MB"<?php echo poker_admin_selected('create-house', 'starting_small_blind_unit', 'MB', 'MB'); ?>>MB</option><option value="GB"<?php echo poker_admin_selected('create-house', 'starting_small_blind_unit', 'GB', 'MB'); ?>>GB</option></select></div></div>
                <div><label>Starting Big Blind</label><div class="buyin-editor"><input type="number" name="starting_big_blind_amount" min="1" step="1" value="<?php echo htmlspecialchars(poker_admin_post_value('create-house', 'starting_big_blind_amount', '200'), ENT_QUOTES, 'UTF-8'); ?>" autocomplete="off" required><select name="starting_big_blind_unit"><option value="MB"<?php echo poker_admin_selected('create-house', 'starting_big_blind_unit', 'MB', 'MB'); ?>>MB</option><option value="GB"<?php echo poker_admin_selected('create-house', 'starting_big_blind_unit', 'GB', 'MB'); ?>>GB</option></select></div></div>
                <div><label>Blind Increases</label><input type="hidden" name="blind_hands_per_level" value="1"><div class="create-info">Fixed — Never</div></div>
                <div><label>Game</label><div class="create-info">1 Player vs The Collector</div></div>
                <div><button class="save-button" type="submit">Create House Table</button></div>
            </div>
        </form>
        <div class="blind-rule-note">&#10003; Buy-In / Big Blind: OK</div>
        <div class="small-note">Heads-up Hold'em against a server-controlled opponent known as The Collector. House blinds stay fixed, and the bot starts each hand with the same amount the player originally bought in for. The House never reads the player's hidden cards.</div>
    </div>

    <div id="create-tournament" class="admin-card<?php echo $formTarget === 'create-tournament' && $formError !== '' ? ' form-error-target' : ''; ?>">
        <h3 class="card-title">Create Tournament</h3>
        <?php if ($formTarget === 'create-tournament' && $formError !== '') { ?><div class="notice error form-notice"><?php echo htmlspecialchars($formError, ENT_QUOTES, 'UTF-8'); ?></div><?php } ?>
        <form method="post" action="poker-admin.php" autocomplete="off">
            <input type="hidden" name="csrf" value="<?php echo htmlspecialchars($_SESSION['poker_csrf'], ENT_QUOTES, 'UTF-8'); ?>">
            <input type="hidden" name="action" value="create_tournament">
            <div class="create-grid">
                <div><label>Tournament Name</label><input class="clear-placeholder-on-focus" type="text" name="name" maxlength="64" placeholder="Friday Night Tournament" value="<?php echo htmlspecialchars(poker_admin_post_value('create-tournament', 'name'), ENT_QUOTES, 'UTF-8'); ?>" autocomplete="off" required></div>
                <div><label>Entry Fee</label><div class="buyin-editor"><input type="number" name="entry_amount" min="1" step="1" value="<?php echo htmlspecialchars(poker_admin_post_value('create-tournament', 'entry_amount', '1'), ENT_QUOTES, 'UTF-8'); ?>" autocomplete="off" required><select name="entry_unit"><option<?php echo poker_admin_selected('create-tournament', 'entry_unit', 'MB', 'GB'); ?>>MB</option><option<?php echo poker_admin_selected('create-tournament', 'entry_unit', 'GB', 'GB'); ?>>GB</option></select></div></div>
                <div><label>Starting Chips</label><input type="number" name="starting_chips" min="100" step="100" value="<?php echo htmlspecialchars(poker_admin_post_value('create-tournament', 'starting_chips', '10000'), ENT_QUOTES, 'UTF-8'); ?>" autocomplete="off" required></div>
                <div><label>Starting Small Blind (Chips)</label><input type="number" name="tournament_sb" min="1" step="1" value="<?php echo htmlspecialchars(poker_admin_post_value('create-tournament', 'tournament_sb', '50'), ENT_QUOTES, 'UTF-8'); ?>" autocomplete="off" required></div>
                <div><label>Starting Big Blind (Chips)</label><input type="number" name="tournament_bb" min="2" step="1" value="<?php echo htmlspecialchars(poker_admin_post_value('create-tournament', 'tournament_bb', '100'), ENT_QUOTES, 'UTF-8'); ?>" autocomplete="off" required></div>
                <div><label>Increase Blinds Every (Hands)</label><input type="number" name="blind_hands_per_level" min="1" max="100" value="<?php echo htmlspecialchars(poker_admin_post_value('create-tournament', 'blind_hands_per_level', '5'), ENT_QUOTES, 'UTF-8'); ?>" autocomplete="off" required></div>
                <div><label>Seats</label><select name="max_seats"><?php for($i=2;$i<=10;$i++){ ?><option value="<?php echo $i; ?>"<?php echo poker_admin_selected('create-tournament', 'max_seats', $i, '10'); ?>><?php echo $i; ?></option><?php } ?></select></div>
                <div><button class="save-button" type="submit">Create Tournament</button></div>
            </div>
        </form>
        <div class="small-note">Single-table tournament. Entry fees form a winner-take-all upload-credit prize pool. Every player receives the same tournament chip stack.</div>
    </div>

    <div class="admin-card">
        <h3 class="card-title">Poker Starter Stakes</h3>
        <?php if (empty($starterGrants)) { ?>
            <div class="starter-grants-empty">No starter stakes have been claimed yet.</div>
        <?php } else { ?>
            <table class="starter-grants-table">
                <thead>
                    <tr>
                        <th>User</th>
                        <th>Granted</th>
                        <th>Credit Before</th>
                        <th>Claimed</th>
                    </tr>
                </thead>
                <tbody>
                    <?php foreach ($starterGrants as $grant) { ?>
                        <tr>
                            <td><?php echo htmlspecialchars($grant['username'] !== null ? $grant['username'] : ('User #' . (int)$grant['user_id']), ENT_QUOTES, 'UTF-8'); ?></td>
                            <td><?php echo htmlspecialchars(poker_format_bytes((int)$grant['amount']), ENT_QUOTES, 'UTF-8'); ?></td>
                            <td><?php echo htmlspecialchars(poker_format_bytes((int)$grant['credit_before']), ENT_QUOTES, 'UTF-8'); ?></td>
                            <td><?php echo htmlspecialchars((string)$grant['claimed_at'], ENT_QUOTES, 'UTF-8'); ?></td>
                        </tr>
                    <?php } ?>
                </tbody>
            </table>
        <?php } ?>
    </div>

    <div class="admin-card manage-tables-section">
        <h3 class="card-title">Manage Tables</h3>

        <?php if (!$tables) { ?>
            <div class="small-note">No poker tables are configured.</div>
        <?php } ?>

        <div class="manage-list">
        <?php foreach ($tables as $table) {
            $status = (string) $table['status'];
            $statusText = $status === 'showdown' ? 'Between' : ucfirst($status);
            $minBuyinDisplay = poker_admin_buyin_display($table['min_buyin']);
            $maxBuyinDisplay = poker_admin_buyin_display($table['max_buyin']);
            $startSmallDisplay = poker_admin_buyin_display($table['starting_small_blind']);
            $startBigDisplay = poker_admin_buyin_display($table['starting_big_blind']);
            $isTournament = isset($table['game_type']) && $table['game_type'] === 'tournament';
            $isHouse = isset($table['game_type']) && $table['game_type'] === 'house';
        ?>
            <?php $tableTarget = 'table-' . (int) $table['id']; ?>
            <div id="<?php echo $tableTarget; ?>" class="manage-table-card table-type-<?php echo $isTournament ? 'tournament' : ($isHouse ? 'house' : 'multiplayer'); ?><?php echo $formTarget === $tableTarget && $formError !== '' ? ' form-error-target' : ''; ?>">
                <?php if ($formTarget === $tableTarget && $formError !== '') { ?><div class="notice error form-notice"><?php echo htmlspecialchars($formError, ENT_QUOTES, 'UTF-8'); ?></div><?php } ?>
                <div class="table-card-head">
                    <div>
                        <span class="table-card-id">Table #<?php echo (int) $table['id']; ?></span>
                        <span class="table-card-name"><?php echo htmlspecialchars($table['name'], ENT_QUOTES, 'UTF-8'); ?></span>
                    </div>
                    <?php if ($isTournament) { ?><span class="table-type-badge tournament">Tournament · <?php echo htmlspecialchars($table['tournament_status'], ENT_QUOTES, 'UTF-8'); ?></span><?php } elseif ($isHouse) { ?><span class="table-type-badge house">House Table</span><?php } else { ?><span class="table-type-badge multiplayer">Multiplayer Table</span><?php } ?>
                    <span class="status <?php echo htmlspecialchars($status, ENT_QUOTES, 'UTF-8'); ?>"><?php echo htmlspecialchars($statusText, ENT_QUOTES, 'UTF-8'); ?></span>
                </div>

                <form method="post" action="poker-admin.php">
                    <input type="hidden" name="csrf" value="<?php echo htmlspecialchars($_SESSION['poker_csrf'], ENT_QUOTES, 'UTF-8'); ?>">
                    <input type="hidden" name="action" value="update_table">
                    <input type="hidden" name="table_id" value="<?php echo (int) $table['id']; ?>">

                    <?php if (!$isTournament) { ?>
                    <div class="manage-settings-layout<?php echo $isHouse ? ' house-settings' : ''; ?>">
                        <div class="setting-group">
                            <div class="setting-group-title">Table Setup</div>
                            <div class="setting-fields table-setup-fields">
                                <div>
                                    <label>Table Name</label>
                                    <input class="table-name-input" type="text" name="name" maxlength="64" value="<?php echo htmlspecialchars(poker_admin_post_value($tableTarget, 'name', $table['name']), ENT_QUOTES, 'UTF-8'); ?>" required>
                                </div>

                                <?php if (!$isHouse) { ?>
                                <div>
                                    <label>Increase Blinds Every (Hands)</label>
                                    <input class="number-input" type="number" name="blind_hands_per_level" min="1" max="100" step="1" value="<?php echo htmlspecialchars(poker_admin_post_value($tableTarget, 'blind_hands_per_level', $table['blind_hands_per_level']), ENT_QUOTES, 'UTF-8'); ?>" required>
                                </div>

                                <div>
                                    <label>Seats</label>
                                    <select class="seat-select" name="max_seats">
                                        <?php for ($i = 2; $i <= 10; $i++) { ?>
                                            <option value="<?php echo $i; ?>"<?php echo poker_admin_selected($tableTarget, 'max_seats', $i, $table['max_seats']); ?>><?php echo $i; ?></option>
                                        <?php } ?>
                                    </select>
                                </div>
                                <?php } else { ?>
                                    <input type="hidden" name="blind_hands_per_level" value="1">
                                    <input type="hidden" name="max_seats" value="10">
                                <?php } ?>
                            </div>
                        </div>

                        <div class="setting-group">
                            <div class="setting-group-title">Buy-In Range</div>
                            <div class="setting-fields">
                                <div>
                                    <label>Minimum</label>
                                    <div class="buyin-editor">
                                        <input class="number-input" type="number" name="min_buyin_amount" min="1" step="1" value="<?php echo htmlspecialchars(poker_admin_post_value($tableTarget, 'min_buyin_amount', $minBuyinDisplay['amount']), ENT_QUOTES, 'UTF-8'); ?>" required>
                                        <select name="min_buyin_unit" aria-label="Minimum buy-in unit">
                                            <option value="MB"<?php echo poker_admin_selected($tableTarget, 'min_buyin_unit', 'MB', $minBuyinDisplay['unit']); ?>>MB</option>
                                            <option value="GB"<?php echo poker_admin_selected($tableTarget, 'min_buyin_unit', 'GB', $minBuyinDisplay['unit']); ?>>GB</option>
                                        </select>
                                    </div>
                                </div>

                                <div>
                                    <label>Maximum</label>
                                    <div class="buyin-editor">
                                        <input class="number-input" type="number" name="max_buyin_amount" min="1" step="1" value="<?php echo htmlspecialchars(poker_admin_post_value($tableTarget, 'max_buyin_amount', $maxBuyinDisplay['amount']), ENT_QUOTES, 'UTF-8'); ?>" required>
                                        <select name="max_buyin_unit" aria-label="Maximum buy-in unit">
                                            <option value="MB"<?php echo poker_admin_selected($tableTarget, 'max_buyin_unit', 'MB', $maxBuyinDisplay['unit']); ?>>MB</option>
                                            <option value="GB"<?php echo poker_admin_selected($tableTarget, 'max_buyin_unit', 'GB', $maxBuyinDisplay['unit']); ?>>GB</option>
                                        </select>
                                    </div>
                                </div>
                            </div>
                        </div>

                        <div class="setting-group">
                            <div class="setting-group-title">Starting Blinds</div>
                            <div class="setting-fields">
                                <div>
                                    <label>Small Blind</label>
                                    <div class="buyin-editor">
                                        <input class="number-input" type="number" name="starting_small_blind_amount" min="1" step="1" value="<?php echo htmlspecialchars(poker_admin_post_value($tableTarget, 'starting_small_blind_amount', $startSmallDisplay['amount']), ENT_QUOTES, 'UTF-8'); ?>" required>
                                        <select name="starting_small_blind_unit" aria-label="Starting small blind unit">
                                            <option value="MB"<?php echo poker_admin_selected($tableTarget, 'starting_small_blind_unit', 'MB', $startSmallDisplay['unit']); ?>>MB</option>
                                            <option value="GB"<?php echo poker_admin_selected($tableTarget, 'starting_small_blind_unit', 'GB', $startSmallDisplay['unit']); ?>>GB</option>
                                        </select>
                                    </div>
                                </div>

                                <div>
                                    <label>Big Blind</label>
                                    <div class="buyin-editor">
                                        <input class="number-input" type="number" name="starting_big_blind_amount" min="1" step="1" value="<?php echo htmlspecialchars(poker_admin_post_value($tableTarget, 'starting_big_blind_amount', $startBigDisplay['amount']), ENT_QUOTES, 'UTF-8'); ?>" required>
                                        <select name="starting_big_blind_unit" aria-label="Starting big blind unit">
                                            <option value="MB"<?php echo poker_admin_selected($tableTarget, 'starting_big_blind_unit', 'MB', $startBigDisplay['unit']); ?>>MB</option>
                                            <option value="GB"<?php echo poker_admin_selected($tableTarget, 'starting_big_blind_unit', 'GB', $startBigDisplay['unit']); ?>>GB</option>
                                        </select>
                                    </div>
                                </div>
                            </div>
                        </div>
                    </div>
                    <div class="blind-rule-note">&#10003; Buy-In / Big Blind: OK</div>
                    <?php if ($isHouse) { ?>
                    <div class="house-info-subtext">
                        Heads-up Hold'em against a server-controlled opponent known as The Collector. Blinds stay fixed (no increases). The House starts each hand with the same amount the player originally bought in for.
                    </div>
                    <?php } ?>
                    <?php } else { ?>
                    <div class="manage-settings-layout tournament-settings">
                        <div class="setting-group"><div class="setting-group-title">Entry Fee</div><strong><?php echo htmlspecialchars(poker_format_bytes($table['tournament_entry_fee']), ENT_QUOTES, 'UTF-8'); ?></strong></div>
                        <div class="setting-group"><div class="setting-group-title">Starting Stack</div><strong><?php echo number_format((int)$table['tournament_starting_stack']); ?> chips</strong></div>
                        <div class="setting-group"><div class="setting-group-title">Starting Blinds</div><strong><?php echo number_format((int)$table['starting_small_blind']); ?> / <?php echo number_format((int)$table['starting_big_blind']); ?></strong></div>
                        <div class="setting-group"><div class="setting-group-title">Prize Pool</div><strong><?php echo htmlspecialchars(poker_format_bytes($table['tournament_prize_pool']), ENT_QUOTES, 'UTF-8'); ?></strong></div>
                        <div class="setting-group"><div class="setting-group-title">Registered</div><strong><?php echo (int)$table['tournament_entries']; ?> / <?php echo (int)$table['max_seats']; ?></strong></div>
                    </div>
                    <?php } ?>

                    <div class="table-card-footer">
                        <div class="table-meta">
                            <div class="table-meta-item">
                                <span class="table-meta-label">Players</span>
                                <?php echo (int) $table['player_count']; ?> / <?php echo $isHouse ? 1 : (int) $table['max_seats']; ?>
                            </div>
                            <?php if (!$isHouse) { ?>
                            <div class="table-meta-item">
                                <span class="table-meta-label">Watching</span>
                                <?php echo (int) $table['spectator_count']; ?>
                            </div>
                            <?php } ?>
                            <div class="table-meta-item">
                                <span class="table-meta-label">Current Blinds</span>
                                <?php echo htmlspecialchars($isTournament ? (number_format((int)$table['small_blind']).' / '.number_format((int)$table['big_blind']).' chips') : (poker_format_bytes($table['small_blind']).' / '.poker_format_bytes($table['big_blind'])), ENT_QUOTES, 'UTF-8'); ?>
                            </div>
                            <?php if (!$isHouse) { ?>
                            <div class="table-meta-item">
                                <span class="table-meta-label">Hand</span>
                                <?php echo (int) $table['hand_no'] > 0 ? '#' . (int) $table['hand_no'] : '-'; ?>
                            </div>
                            <?php } ?>
                            <?php if ((string) $table['game_type'] !== 'house') { ?>
                            <div class="table-meta-item">
                                <span class="table-meta-label">Chat</span>
                                <?php echo (int) $table['chat_count']; ?>
                            </div>
                            <?php } ?>
                        </div>

                        <div class="table-card-actions">
                            <?php if ($isTournament && $table['tournament_status'] === 'registration') { ?>
                                <button class="save-button" type="submit" name="action" value="start_tournament">Start Tournament</button>
                            <?php } ?>
                            <?php if (!$isTournament) { ?><button class="save-button" type="submit">Save Changes</button><?php } ?>
                            <a class="open-link" href="poker.php?table_id=<?php echo (int) $table['id']; ?>">Open Table</a>
                        </div>
                    </div>
                </form>

                <div class="table-card-danger">
                    
<?php if ((string)$table['game_type'] !== 'house') { ?>
<?php $kickPlayers = isset($seatedPlayersByTable[(int)$table['id']]) ? $seatedPlayersByTable[(int)$table['id']] : array(); ?>
<?php if (!empty($kickPlayers)) { ?>
<form class="kick-player-form" method="post" action="poker-admin.php" autocomplete="off" onsubmit="var s=this.querySelector('select[name=user_id]'); if (!s || !s.value) { alert('Select a player to kick.'); return false; } var r=this.querySelector('input[name=kick_reason]'); var reason=r ? r.value.trim() : ''; var msg='Kick ' + s.options[s.selectedIndex].text + ' from this poker table? Their remaining stack will be returned to upload credit.'; if (reason) msg += '\n\nReason: ' + reason; return confirm(msg);">
    <input type="hidden" name="csrf" value="<?php echo htmlspecialchars($_SESSION['poker_csrf'], ENT_QUOTES, 'UTF-8'); ?>">
    <input type="hidden" name="action" value="kick_player">
    <input type="hidden" name="table_id" value="<?php echo (int) $table['id']; ?>">
    <select name="user_id" aria-label="Player to kick" required>
        <option value="">Kick player...</option>
        <?php foreach ($kickPlayers as $kickPlayer) { ?>
            <option value="<?php echo (int) $kickPlayer['user_id']; ?>"<?php echo poker_admin_selected($tableTarget, 'user_id', $kickPlayer['user_id']); ?>><?php echo htmlspecialchars($kickPlayer['username'] . ' · Seat ' . (int)$kickPlayer['seat_no'], ENT_QUOTES, 'UTF-8'); ?></option>
        <?php } ?>
    </select>
    <input type="text" name="kick_reason" maxlength="255" placeholder="Reason (optional)" value="<?php echo htmlspecialchars(poker_admin_post_value($tableTarget, 'kick_reason'), ENT_QUOTES, 'UTF-8'); ?>" aria-label="Kick reason" autocomplete="off">
    <button class="danger-button kick-player-button" type="submit">Kick</button>
</form>
<?php } ?>
<form method="post" action="poker-admin.php" onsubmit="return confirm('Clear all live chat messages from this poker table?');">
                        <input type="hidden" name="csrf" value="<?php echo htmlspecialchars($_SESSION['poker_csrf'], ENT_QUOTES, 'UTF-8'); ?>">
                        <input type="hidden" name="action" value="clear_chat">
                        <input type="hidden" name="table_id" value="<?php echo (int) $table['id']; ?>">
                        <button class="danger-button" type="submit">Clear Chat (<?php echo (int) $table['chat_count']; ?>)</button>
                    </form>
<?php } ?>


                    <form method="post" action="poker-admin.php" onsubmit="return confirm('Permanently remove this poker table and its chat, actions, and hand history? This cannot be undone.');">
                        <input type="hidden" name="csrf" value="<?php echo htmlspecialchars($_SESSION['poker_csrf'], ENT_QUOTES, 'UTF-8'); ?>">
                        <input type="hidden" name="action" value="delete_table">
                        <input type="hidden" name="table_id" value="<?php echo (int) $table['id']; ?>">
                        <button class="danger-button" type="submit">Remove Table</button>
                    </form>
                </div>
            </div>
        <?php } ?>
        </div>
    </div>
</div>

<script>
function pokerAdminAmountInMb(form, amountName, unitName) {
    var amount = form.querySelector('[name="' + amountName + '"]');
    var unit = form.querySelector('[name="' + unitName + '"]');
    if (!amount || !unit || amount.value === '') return null;
    return parseFloat(amount.value) * (unit.value === 'GB' ? 1024 : 1);
}

function pokerAdminValidateBlindRule(form, focusField) {
    var minimum = pokerAdminAmountInMb(form, 'min_buyin_amount', 'min_buyin_unit');
    var bigBlind = pokerAdminAmountInMb(form, 'starting_big_blind_amount', 'starting_big_blind_unit');
    var note = form.parentElement.querySelector('.blind-rule-note');
    var fields = form.querySelectorAll('[name="min_buyin_amount"], [name="starting_big_blind_amount"]');

    fields.forEach(function (field) {
        var editor = field.closest('.buyin-editor');
        if (editor) editor.classList.remove('blind-rule-field');
    });
    if (note) {
        note.classList.remove('error');
        note.classList.remove('pending');
        note.textContent = '\u2713 Buy-In / Big Blind: OK';
    }

    if (minimum === null || bigBlind === null) {
        if (note) {
            note.classList.add('pending');
            note.textContent = 'Enter both values to check this rule.';
        }
        return true;
    }

    if (minimum >= bigBlind) return true;

    fields.forEach(function (field) {
        var editor = field.closest('.buyin-editor');
        if (editor) editor.classList.add('blind-rule-field');
    });
    if (note) {
        note.classList.add('error');
        note.textContent = 'Minimum buy-in must be equal to or greater than the starting big blind.';
    }

    if (focusField) {
        var minimumField = form.querySelector('[name="min_buyin_amount"]');
        if (minimumField) {
            minimumField.focus();
            minimumField.select();
        }
    }
    return false;
}

document.querySelectorAll('#poker-admin form').forEach(function (form) {
    form.addEventListener('focusin', function (event) {
        var field = event.target;
        if (field && field.name && field.type !== 'hidden' && field.type !== 'submit') {
            form.dataset.lastFocusField = field.name;
        }
    });

    form.addEventListener('submit', function (event) {
        if (!pokerAdminValidateBlindRule(form, true)) {
            event.preventDefault();
            return;
        }

        var hidden = form.querySelector('input[name="_focus_field"]');
        if (!hidden) {
            hidden = document.createElement('input');
            hidden.type = 'hidden';
            hidden.name = '_focus_field';
            form.appendChild(hidden);
        }
        hidden.value = form.dataset.lastFocusField || '';
    });

    form.addEventListener('input', function () {
        pokerAdminValidateBlindRule(form, false);
    });

    form.addEventListener('change', function () {
        pokerAdminValidateBlindRule(form, false);
    });
});

document.querySelectorAll('#poker-admin input.clear-placeholder-on-focus[placeholder]').forEach(function (input) {
    input.addEventListener('focus', function () {
        input.dataset.savedPlaceholder = input.placeholder;
        input.placeholder = '';
    });

    input.addEventListener('blur', function () {
        if (input.dataset.savedPlaceholder) {
            input.placeholder = input.dataset.savedPlaceholder;
        }
    });
});

<?php if ($formError !== '' && $formTarget !== '') { ?>
(function () {
    var target = document.getElementById(<?php echo json_encode($formTarget); ?>);
    if (!target) return;

    target.scrollIntoView({ behavior: 'auto', block: 'center' });

    var focusName = <?php echo json_encode($focusField); ?>;
    var blindRuleError = <?php echo json_encode($formError === 'Minimum buy-in cannot be lower than the starting big blind.'); ?>;
    if (blindRuleError) {
        var form = target.querySelector('form');
        if (form) pokerAdminValidateBlindRule(form, false);
        focusName = 'min_buyin_amount';
    }
    var field = null;
    if (focusName) {
        target.querySelectorAll('input, select, textarea, button').forEach(function (candidate) {
            if (!field && candidate.name === focusName) field = candidate;
        });
    }
    if (!field) {
        field = target.querySelector('input:not([type="hidden"]), select, textarea, button');
    }
    if (field) {
        window.setTimeout(function () {
            field.focus({ preventScroll: true });
            if (typeof field.select === 'function' && field.tagName === 'INPUT') field.select();
        }, 0);
    }
})();
<?php } ?>
</script>

<?php
if (function_exists('end_frame')) {
    end_frame();
}

if (function_exists('stdfoot')) {
    stdfoot();
}
?>
