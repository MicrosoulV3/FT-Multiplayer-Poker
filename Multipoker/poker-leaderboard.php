<?php
/*
 * FastTracker Poker - Player Statistics / Leaderboard
 */

require_once("backend/functions.php");

global $TTCache, $site_config, $CURUSER;

dbconn();

$site_config["LEFTNAV"] = $site_config["MIDDLENAV"] = $site_config["RIGHTNAV"] = false;

if ($site_config["MEMBERSONLY"]) {
    loggedinonly();
}

require_once __DIR__ . '/poker-lib.php';

poker_session_init();

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

$title = 'Poker Leaderboard';
$me = poker_player_stats_row($db, (int) $CURUSER['id']);

$netRows = poker_leaderboard_rows($db, 'net_profit', 20);
$winsRows = poker_leaderboard_rows($db, 'hands_won', 20);
$potRows = poker_leaderboard_rows($db, 'biggest_pot', 20);
$handsRows = poker_leaderboard_rows($db, 'hands_played', 20);

if (function_exists('stdhead')) {
    stdhead($title);
}

function poker_leaderboard_table($rows, $valueField, $valueLabel)
{
    ?>
    <table class="leader-table">
        <thead>
            <tr>
                <th>#</th>
                <th>Player</th>
                <th><?php echo htmlspecialchars($valueLabel, ENT_QUOTES, 'UTF-8'); ?></th>
                <th>Hands</th>
                <th>Wins</th>
                <th>Win Rate</th>
            </tr>
        </thead>
        <tbody>
        <?php if (!$rows) { ?>
            <tr>
                <td colspan="6" class="empty-board">No poker statistics yet.</td>
            </tr>
        <?php } else { ?>
            <?php foreach ($rows as $index => $row) { ?>
                <?php
                if ($valueField === 'net_profit') {
                    $value = poker_format_signed_bytes((int) $row[$valueField]);
                    $valueClass = ((int) $row[$valueField] > 0)
                        ? 'positive'
                        : (((int) $row[$valueField] < 0) ? 'negative' : '');
                } elseif ($valueField === 'biggest_pot') {
                    $value = poker_format_bytes((int) $row[$valueField]);
                    $valueClass = '';
                } else {
                    $value = number_format((int) $row[$valueField]);
                    $valueClass = '';
                }
                ?>
                <tr>
                    <td class="rank"><?php echo $index + 1; ?></td>
                    <td class="player"><a href="poker-profile.php?user_id=<?php echo (int) $row['user_id']; ?>"><?php echo htmlspecialchars($row['username'], ENT_QUOTES, 'UTF-8'); ?></a></td>
                    <td class="value <?php echo $valueClass; ?>"><?php echo htmlspecialchars($value, ENT_QUOTES, 'UTF-8'); ?></td>
                    <td><?php echo number_format((int) $row['hands_played']); ?></td>
                    <td><?php echo number_format((int) $row['hands_won']); ?></td>
                    <td><?php echo poker_stats_win_rate($row['hands_won'], $row['hands_played']); ?></td>
                </tr>
            <?php } ?>
        <?php } ?>
        </tbody>
    </table>
    <?php
}
?>

<style>
    #poker-leaderboard {
        width: min(1120px, calc(100% - 20px));
        margin: 16px auto 30px;
        color: #ddd;
        font-family: Arial, Helvetica, sans-serif;
    }

    #poker-leaderboard * {
        box-sizing: border-box;
    }

    #poker-leaderboard .leader-head {
        display: flex;
        justify-content: space-between;
        align-items: center;
        gap: 20px;
        margin-bottom: 14px;
        padding: 18px 20px;
        background: #171717;
        border: 1px solid #353535;
        border-radius: 7px;
    }

    #poker-leaderboard h2,
    #poker-leaderboard h3 {
        margin: 0;
        color: #f1f1f1;
    }

    #poker-leaderboard .subtitle {
        margin-top: 6px;
        color: #888;
        font-size: 12px;
    }

    #poker-leaderboard .head-links {
        display: flex;
        gap: 7px;
    }

    #poker-leaderboard .link-button {
        display: inline-block;
        padding: 8px 11px;
        background: #282828;
        border: 1px solid #444;
        border-radius: 5px;
        color: #ddd;
        text-decoration: none;
        font-size: 11px;
        font-weight: 700;
    }

    #poker-leaderboard .link-button:hover {
        background: #333;
        color: #fff;
    }

    #poker-leaderboard .career {
        margin-bottom: 14px;
        padding: 18px 20px;
        background: #171717;
        border: 1px solid #353535;
        border-radius: 7px;
    }

    #poker-leaderboard .career-grid {
        display: grid;
        grid-template-columns: repeat(5, 1fr);
        gap: 8px;
        margin-top: 14px;
    }

    #poker-leaderboard .stat-box {
        min-height: 72px;
        padding: 11px 12px;
        background: #101010;
        border: 1px solid #2b2b2b;
        border-radius: 5px;
    }

    #poker-leaderboard .stat-box span {
        display: block;
        margin-bottom: 6px;
        color: #777;
        font-size: 9px;
        font-weight: 700;
        text-transform: uppercase;
    }

    #poker-leaderboard .stat-box strong {
        color: #e8e8e8;
        font-size: 15px;
    }

    #poker-leaderboard .positive {
        color: #8ddd9c !important;
    }

    #poker-leaderboard .negative {
        color: #ef9a9a !important;
    }

    #poker-leaderboard .boards {
        display: grid;
        grid-template-columns: 1fr 1fr;
        gap: 14px;
    }

    #poker-leaderboard .board {
        overflow: hidden;
        background: #171717;
        border: 1px solid #353535;
        border-radius: 7px;
    }

    #poker-leaderboard .board h3 {
        padding: 13px 15px;
        border-bottom: 1px solid #303030;
        font-size: 13px;
    }

    #poker-leaderboard .leader-table {
        width: 100%;
        border-collapse: collapse;
        table-layout: fixed;
    }

    #poker-leaderboard .leader-table th {
        padding: 8px 7px;
        border-bottom: 1px solid #303030;
        color: #777;
        font-size: 9px;
        text-align: left;
        text-transform: uppercase;
    }

    #poker-leaderboard .leader-table td {
        padding: 8px 7px;
        border-bottom: 1px solid #262626;
        color: #bbb;
        font-size: 11px;
        overflow: hidden;
        text-overflow: ellipsis;
        white-space: nowrap;
    }

    #poker-leaderboard .leader-table tr:last-child td {
        border-bottom: 0;
    }

    #poker-leaderboard .leader-table th:nth-child(1),
    #poker-leaderboard .leader-table td:nth-child(1) {
        width: 34px;
    }

    #poker-leaderboard .leader-table th:nth-child(2),
    #poker-leaderboard .leader-table td:nth-child(2) {
        width: 32%;
    }

    #poker-leaderboard .rank {
        color: #888;
        font-weight: 700;
    }

    #poker-leaderboard .player {
        color: #eee !important;
        font-weight: 700;
    }

    #poker-leaderboard .player a {
        color: #eee;
        text-decoration: none;
    }

    #poker-leaderboard .player a:hover {
        color: #fff;
        text-decoration: underline;
    }

    #poker-leaderboard .value {
        font-weight: 700;
    }

    #poker-leaderboard .empty-board {
        padding: 24px !important;
        color: #777 !important;
        text-align: center;
    }

</style>

<div id="poker-leaderboard">
    <div class="leader-head">
        <div>
            <h2>FastTracker Poker Leaderboard</h2>
            <div class="subtitle">Career statistics from completed poker hands.</div>
        </div>
        <div class="head-links">
            <a class="link-button" href="poker-lobby.php">Poker Lobby</a>
            <a class="link-button" href="poker-profile.php">My Profile</a>
            <?php if (poker_user_is_admin($CURUSER)) { ?>
                <a class="link-button" href="poker-admin.php">Poker Admin</a>
            <?php } ?>
        </div>
    </div>

    <div class="career">
        <h3>Your Poker Career</h3>

        <?php if (!$me) { ?>
            <div class="subtitle">You do not have any recorded hands yet. Play a completed hand and your statistics will appear here.</div>
        <?php } else { ?>
            <div class="career-grid">
                <div class="stat-box">
                    <span>Hands Played</span>
                    <strong><?php echo number_format((int) $me['hands_played']); ?></strong>
                </div>
                <div class="stat-box">
                    <span>Hands Won</span>
                    <strong><?php echo number_format((int) $me['hands_won']); ?> (<?php echo poker_stats_win_rate($me['hands_won'], $me['hands_played']); ?>)</strong>
                </div>
                <div class="stat-box">
                    <span>Net Profit / Loss</span>
                    <?php $myNet = (int) $me['net_profit']; ?>
                    <strong class="<?php echo $myNet > 0 ? 'positive' : ($myNet < 0 ? 'negative' : ''); ?>"><?php echo poker_format_signed_bytes($myNet); ?></strong>
                </div>
                <div class="stat-box">
                    <span>Total Chips Won</span>
                    <strong><?php echo poker_format_bytes((int) $me['total_won']); ?></strong>
                </div>
                <div class="stat-box">
                    <span>Biggest Pot Won</span>
                    <strong><?php echo poker_format_bytes((int) $me['biggest_pot']); ?></strong>
                </div>
                <div class="stat-box">
                    <span>Showdowns</span>
                    <strong><?php echo number_format((int) $me['showdowns_seen']); ?></strong>
                </div>
                <div class="stat-box">
                    <span>Showdowns Won</span>
                    <strong><?php echo number_format((int) $me['showdowns_won']); ?> (<?php echo poker_stats_showdown_rate($me['showdowns_won'], $me['showdowns_seen']); ?>)</strong>
                </div>
                <div class="stat-box">
                    <span>Folds</span>
                    <strong><?php echo number_format((int) $me['folds']); ?></strong>
                </div>
                <div class="stat-box">
                    <span>All-Ins</span>
                    <strong><?php echo number_format((int) $me['allins']); ?></strong>
                </div>
                <div class="stat-box">
                    <span>Last Updated</span>
                    <strong style="font-size:11px;"><?php echo htmlspecialchars((string) $me['updated_at'], ENT_QUOTES, 'UTF-8'); ?></strong>
                </div>
            </div>
        <?php } ?>
    </div>

    <div class="boards">
        <div class="board">
            <h3>Top Net Winners</h3>
            <?php poker_leaderboard_table($netRows, 'net_profit', 'Net'); ?>
        </div>

        <div class="board">
            <h3>Most Hands Won</h3>
            <?php poker_leaderboard_table($winsRows, 'hands_won', 'Wins'); ?>
        </div>

        <div class="board">
            <h3>Biggest Pots Won</h3>
            <?php poker_leaderboard_table($potRows, 'biggest_pot', 'Biggest Pot'); ?>
        </div>

        <div class="board">
            <h3>Most Hands Played</h3>
            <?php poker_leaderboard_table($handsRows, 'hands_played', 'Hands'); ?>
        </div>
    </div>

</div>

<?php
if (function_exists('stdfoot')) {
    stdfoot();
}
?>
