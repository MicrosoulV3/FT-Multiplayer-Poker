<?php
require_once("backend/functions.php");

global $TTCache, $site_config, $CURUSER;

dbconn();

if ($site_config["MEMBERSONLY"]) {
    loggedinonly();
}

require_once __DIR__ . '/poker-lib.php';

poker_session_init();

global $CURUSER;
$db = poker_db();
$tableId = isset($_REQUEST['table_id']) ? (int) $_REQUEST['table_id'] : 1;
$action = isset($_REQUEST['action']) ? (string) $_REQUEST['action'] : 'state';
$userId = (int) $CURUSER['id'];

function poker_api_amount_to_bytes($amount, $unit, $label)
{
    if (!is_numeric($amount)) {
        throw new RuntimeException($label . ' must be a number.');
    }

    $amount = (float) $amount;
    if ($amount <= 0) {
        throw new RuntimeException($label . ' must be greater than zero.');
    }

    $unit = strtoupper(trim((string) $unit));

    if ($unit === 'MB') {
        return poker_mb_to_bytes($amount);
    }

    if ($unit === 'GB') {
        return poker_gb_to_bytes($amount);
    }

    throw new RuntimeException('Invalid ' . strtolower($label) . ' unit.');
}

try {
    $maintenanceState = poker_maintenance_state($db);

    if (!poker_user_is_admin($CURUSER) && $maintenanceState === 2) {
        poker_json(array(
            'ok' => false,
            'maintenance' => true,
            'maintenance_state' => 2,
            'error' => poker_maintenance_message()
        ), 503);
    }

    if ($action === 'lobby') {
        poker_json(poker_lobby_data($db, $userId));
    }

    if ($action === 'starter_claim') {
        if ($_SERVER['REQUEST_METHOD'] !== 'POST') {
            poker_json(array('ok' => false, 'error' => 'POST required.'), 405);
        }
        poker_csrf_check();
        poker_claim_starter_stake($db, $userId);
        poker_json(poker_lobby_data($db, $userId));
    }

    $requestTable = poker_get_table($db, $tableId);
    $isHouseRequest = $requestTable && poker_is_house_table($requestTable);

    if (!$isHouseRequest) {
        poker_touch_presence($db, $tableId, $userId);
    }

    if ($action === 'history') {
        $handNo = isset($_REQUEST['hand_no']) ? (int) $_REQUEST['hand_no'] : 0;
        poker_json($isHouseRequest
            ? poker_house_session_history_data($db, $tableId, $userId, $handNo)
            : poker_history_data($db, $tableId, $userId, $handNo));
    }

    if ($action === 'state') {
        poker_json($isHouseRequest
            ? poker_house_session_public_state($db, $tableId, $userId, $CURUSER)
            : poker_public_state($db, $tableId, $userId));
    }

    if ($_SERVER['REQUEST_METHOD'] !== 'POST') {
        poker_json(array('ok' => false, 'error' => 'POST required.'), 405);
    }

    poker_csrf_check();

    if ($action === 'spectator_leave') {
        if (!$isHouseRequest) {
            poker_remove_spectator($db, $tableId, $userId);
        }
        poker_json(array('ok' => true));
    }

    if ($maintenanceState === 1 && in_array($action, array('join', 'start', 'start_tournament', 'house_tick'), true)) {
        throw new RuntimeException('Poker maintenance is pending. No new players or new hands are allowed.');
    }

    if ($isHouseRequest) {
        if ($action === 'join') {
            if (isset($_POST['buyin_amount'], $_POST['buyin_unit'])) {
                $buyin = poker_api_amount_to_bytes(
                    $_POST['buyin_amount'],
                    $_POST['buyin_unit'],
                    'Buy-in'
                );
            } else {
                $buyin = isset($_POST['buyin_gb']) ? poker_gb_to_bytes($_POST['buyin_gb']) : 0;
            }
            poker_house_join_session($db, $tableId, $buyin, $CURUSER);
        } elseif ($action === 'leave') {
            poker_house_leave_session($db, $tableId, $userId);
        } elseif ($action === 'start') {
            poker_house_start_session_hand($db, $tableId, $userId);
        } elseif ($action === 'house_tick') {
            poker_house_session_bot_turn($db, $tableId, $userId);
        } elseif ($action === 'move') {
            $move = isset($_POST['move']) ? (string) $_POST['move'] : '';
            if (isset($_POST['raise_to_amount'], $_POST['raise_to_unit'])) {
                $raiseTo = poker_api_amount_to_bytes(
                    $_POST['raise_to_amount'],
                    $_POST['raise_to_unit'],
                    'Raise-to amount'
                );
            } else {
                $raiseTo = isset($_POST['raise_to_gb']) ? poker_gb_to_bytes($_POST['raise_to_gb']) : 0;
            }
            poker_house_session_action($db, $tableId, $userId, $move, $raiseTo, false);
        } elseif ($action === 'chat') {
            throw new RuntimeException('House games are private heads-up sessions and do not use table chat.');
        } elseif ($action === 'sit_out' || $action === 'sit_in') {
            throw new RuntimeException('Sit Out is not used in House games.');
        } else {
            throw new RuntimeException('Unknown House game action.');
        }

        poker_json(poker_house_session_public_state($db, $tableId, $userId, $CURUSER));
    }

    if ($action === 'start_tournament') {
        poker_start_tournament($db, $tableId, $CURUSER);
    } elseif ($action === 'house_tick') {
        poker_house_play_turn($db, $tableId);
    } elseif ($action === 'join') {
        $seat = isset($_POST['seat']) ? (int) $_POST['seat'] : 0;

        $tableForJoin = poker_get_table($db, $tableId);
        if ($tableForJoin && poker_is_tournament_table($tableForJoin)) {
            $buyin = (int)$tableForJoin['tournament_entry_fee'];
        } elseif (isset($_POST['buyin_amount'], $_POST['buyin_unit'])) {
            $buyin = poker_api_amount_to_bytes(
                $_POST['buyin_amount'],
                $_POST['buyin_unit'],
                'Buy-in'
            );
        } else {
            // Backward compatibility with the older poker.php client.
            $buyin = isset($_POST['buyin_gb']) ? poker_gb_to_bytes($_POST['buyin_gb']) : 0;
        }

        poker_join($db, $tableId, $seat, $buyin, $CURUSER);
    } elseif ($action === 'leave') {
        poker_leave($db, $tableId, $userId);
    } elseif ($action === 'start') {
        poker_start_hand($db, $tableId, $userId);
    } elseif ($action === 'sit_out') {
        poker_set_sitting_out($db, $tableId, $userId, true);
    } elseif ($action === 'sit_in') {
        poker_set_sitting_out($db, $tableId, $userId, false);
    } elseif ($action === 'chat') {
        $message = isset($_POST['message']) ? (string) $_POST['message'] : '';
        poker_chat_send($db, $tableId, $userId, $message);
    } elseif ($action === 'move') {
        $move = isset($_POST['move']) ? (string) $_POST['move'] : '';
        $moveTable = poker_get_table($db,$tableId);
        if ($moveTable && poker_is_tournament_table($moveTable) && isset($_POST['raise_to_amount'])) {
            if (!is_numeric($_POST['raise_to_amount']) || (int) $_POST['raise_to_amount'] <= 0) {
                throw new RuntimeException('Raise-to amount must be a positive number.');
            }
            $raiseTo = (int) $_POST['raise_to_amount'];
        } elseif (isset($_POST['raise_to_amount'], $_POST['raise_to_unit'])) {
            $raiseTo = poker_api_amount_to_bytes(
                $_POST['raise_to_amount'],
                $_POST['raise_to_unit'],
                'Raise-to amount'
            );
        } else {
            // Backward compatibility with the older poker.php client.
            $raiseTo = isset($_POST['raise_to_gb']) ? poker_gb_to_bytes($_POST['raise_to_gb']) : 0;
        }
        poker_action($db, $tableId, $userId, $move, $raiseTo);
    } else {
        throw new RuntimeException('Unknown action.');
    }

    poker_json(poker_public_state($db, $tableId, $userId));
} catch (Throwable $e) {
    poker_json(array('ok' => false, 'error' => $e->getMessage()), 400);
}
