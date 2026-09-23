<?php
/*
 * FastTracker Poker - Player Profile / Achievements
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
    if (function_exists('stdhead')) { stdhead($title); }
    if (function_exists('begin_frame')) { begin_frame($title); }
    echo '<div style="max-width:760px;margin:35px auto;padding:24px;text-align:center;background:#171717;border:1px solid #444;border-radius:7px;color:#ddd;">';
    echo '<h2 style="margin-top:0;color:#fff;">Poker is temporarily offline</h2>';
    echo '<p style="margin-bottom:0;color:#aaa;">' . htmlspecialchars(poker_maintenance_message(), ENT_QUOTES, 'UTF-8') . '</p>';
    echo '</div>';
    if (function_exists('end_frame')) { end_frame(); }
    if (function_exists('stdfoot')) { stdfoot(); }
    exit;
}

$profileUserId = isset($_GET['user_id']) ? (int) $_GET['user_id'] : (int) $CURUSER['id'];
if ($profileUserId <= 0) {
    $profileUserId = (int) $CURUSER['id'];
}

$profile = poker_player_profile_row($db, $profileUserId);
if (!$profile) {
    $title = 'Poker Profile';
    if (function_exists('stdhead')) { stdhead($title); }
    echo '<div style="max-width:760px;margin:35px auto;padding:24px;text-align:center;background:#171717;border:1px solid #444;border-radius:7px;color:#ddd;">Poker player not found.</div>';
    if (function_exists('stdfoot')) { stdfoot(); }
    exit;
}

$isOwnProfile = (int) $profile['user_id'] === (int) $CURUSER['id'];
$achievements = poker_player_achievements($profile);
$unlockedCount = poker_achievement_count($profile);
$totalAchievements = count($achievements);
$title = htmlspecialchars((string) $profile['username'], ENT_QUOTES, 'UTF-8') . ' - Poker Profile';
$avatar = trim((string) $profile['avatar']);
if ($avatar === '') {
    $avatar = 'avatars/default_avatar.webp';
}

if (function_exists('stdhead')) { stdhead($title); }
?>
<style>
#poker-profile{width:min(1120px,calc(100% - 20px));margin:16px auto 30px;color:#ddd;font-family:Arial,Helvetica,sans-serif}#poker-profile *{box-sizing:border-box}.profile-head,.profile-card,.achievement-card{background:#171717;border:1px solid #353535;border-radius:7px}.profile-head{display:flex;justify-content:space-between;align-items:center;gap:20px;padding:18px 20px;margin-bottom:14px}.profile-head h2,.profile-card h3{margin:0;color:#f2f2f2}.subtitle{margin-top:6px;color:#888;font-size:12px}.head-links{display:flex;gap:7px;flex-wrap:wrap}.link-button{display:inline-block;padding:8px 11px;background:#282828;border:1px solid #444;border-radius:5px;color:#ddd;text-decoration:none;font-size:11px;font-weight:700}.link-button:hover{background:#333;color:#fff}.profile-card{padding:20px;margin-bottom:14px}.identity{display:flex;align-items:center;gap:18px}.avatar{width:88px;height:88px;border-radius:50%;object-fit:cover;background:#0c0c0c;border:2px solid #444}.identity-name{font-size:24px;font-weight:700;color:#fff}.achievement-summary{margin-top:7px;color:#aaa;font-size:12px}.stats-grid{display:grid;grid-template-columns:repeat(5,1fr);gap:8px;margin-top:18px}.stat-box{min-height:72px;padding:11px 12px;background:#101010;border:1px solid #2b2b2b;border-radius:5px}.stat-box span{display:block;margin-bottom:6px;color:#777;font-size:9px;font-weight:700;text-transform:uppercase}.stat-box strong{color:#e8e8e8;font-size:15px}.positive{color:#8ddd9c!important}.negative{color:#ef9a9a!important}.achievements-head{display:flex;justify-content:space-between;align-items:end;margin:4px 0 10px}.achievements-head h3{margin:0;color:#f1f1f1}.achievement-grid{display:grid;grid-template-columns:repeat(3,1fr);gap:10px}.achievement-card{padding:14px;min-height:142px;position:relative;overflow:hidden}.achievement-card.locked{opacity:.56}.achievement-name{display:flex;align-items:center;gap:8px;color:#eee;font-weight:700;font-size:13px}.achievement-icon{width:26px;height:26px;border-radius:50%;display:flex;align-items:center;justify-content:center;background:#292929;border:1px solid #494949;font-size:13px}.achievement-card.unlocked .achievement-icon{background:#3a3320;border-color:#75632d}.achievement-desc{margin-top:9px;color:#999;font-size:11px;line-height:1.45}.achievement-progress{margin-top:13px}.progress-track{height:6px;background:#0c0c0c;border-radius:999px;overflow:hidden;border:1px solid #252525}.progress-fill{height:100%;background:#777}.achievement-card.unlocked .progress-fill{background:#b39a4b}.progress-text{display:flex;justify-content:space-between;margin-top:5px;color:#777;font-size:9px}.unlocked-label{color:#c8b46b;font-weight:700}.locked-label{color:#666;font-weight:700}@media(max-width:900px){.stats-grid{grid-template-columns:repeat(2,1fr)}.achievement-grid{grid-template-columns:repeat(2,1fr)}}
</style>
<div id="poker-profile">
    <div class="profile-head">
        <div>
            <h2><?php echo $isOwnProfile ? 'My Poker Profile' : 'Poker Player Profile'; ?></h2>
            <div class="subtitle">Career statistics, milestones and achievements.</div>
        </div>
        <div class="head-links">
            <a class="link-button" href="poker-lobby.php">Poker Lobby</a>
            <a class="link-button" href="poker-leaderboard.php">Leaderboard</a>
            <?php if (!$isOwnProfile) { ?><a class="link-button" href="poker-profile.php">My Profile</a><?php } ?>
            <?php if (poker_user_is_admin($CURUSER)) { ?><a class="link-button" href="poker-admin.php">Poker Admin</a><?php } ?>
        </div>
    </div>

    <div class="profile-card">
        <div class="identity">
            <img class="avatar" src="<?php echo htmlspecialchars($avatar, ENT_QUOTES, 'UTF-8'); ?>" alt="">
            <div>
                <div class="identity-name"><?php echo htmlspecialchars((string) $profile['username'], ENT_QUOTES, 'UTF-8'); ?></div>
                <div class="achievement-summary"><?php echo number_format($unlockedCount); ?> of <?php echo number_format($totalAchievements); ?> achievements unlocked</div>
            </div>
        </div>

        <div class="stats-grid">
            <div class="stat-box"><span>Hands Played</span><strong><?php echo number_format((int) $profile['hands_played']); ?></strong></div>
            <div class="stat-box"><span>Hands Won</span><strong><?php echo number_format((int) $profile['hands_won']); ?> (<?php echo poker_stats_win_rate($profile['hands_won'], $profile['hands_played']); ?>)</strong></div>
            <?php $net=(int)$profile['net_profit']; ?>
            <div class="stat-box"><span>Net Profit / Loss</span><strong class="<?php echo $net > 0 ? 'positive' : ($net < 0 ? 'negative' : ''); ?>"><?php echo poker_format_signed_bytes($net); ?></strong></div>
            <div class="stat-box"><span>Total Chips Won</span><strong><?php echo poker_format_bytes((int) $profile['total_won']); ?></strong></div>
            <div class="stat-box"><span>Biggest Pot Won</span><strong><?php echo poker_format_bytes((int) $profile['biggest_pot']); ?></strong></div>
            <div class="stat-box"><span>Showdowns</span><strong><?php echo number_format((int) $profile['showdowns_seen']); ?></strong></div>
            <div class="stat-box"><span>Showdowns Won</span><strong><?php echo number_format((int) $profile['showdowns_won']); ?> (<?php echo poker_stats_showdown_rate($profile['showdowns_won'], $profile['showdowns_seen']); ?>)</strong></div>
            <div class="stat-box"><span>Folds</span><strong><?php echo number_format((int) $profile['folds']); ?></strong></div>
            <div class="stat-box"><span>All-Ins</span><strong><?php echo number_format((int) $profile['allins']); ?></strong></div>
            <div class="stat-box"><span>Achievements</span><strong><?php echo $unlockedCount; ?> / <?php echo $totalAchievements; ?></strong></div>
        </div>
    </div>

    <div class="achievements-head">
        <h3>Achievements</h3>
        <div class="subtitle">Unlocked automatically from career statistics.</div>
    </div>

    <div class="achievement-grid">
        <?php foreach ($achievements as $achievement) { ?>
            <div class="achievement-card <?php echo $achievement['unlocked'] ? 'unlocked' : 'locked'; ?>">
                <div class="achievement-name"><span class="achievement-icon"><?php echo $achievement['unlocked'] ? '&#9733;' : '&#9671;'; ?></span><?php echo htmlspecialchars($achievement['name'], ENT_QUOTES, 'UTF-8'); ?></div>
                <div class="achievement-desc"><?php echo htmlspecialchars($achievement['description'], ENT_QUOTES, 'UTF-8'); ?></div>
                <div class="achievement-progress">
                    <div class="progress-track"><div class="progress-fill" style="width:<?php echo number_format((float)$achievement['progress'], 2, '.', ''); ?>%;"></div></div>
                    <div class="progress-text"><span><?php echo htmlspecialchars($achievement['progress_text'], ENT_QUOTES, 'UTF-8'); ?></span><span class="<?php echo $achievement['unlocked'] ? 'unlocked-label' : 'locked-label'; ?>"><?php echo $achievement['unlocked'] ? 'UNLOCKED' : 'LOCKED'; ?></span></div>
                </div>
            </div>
        <?php } ?>
    </div>
</div>
<?php if (function_exists('stdfoot')) { stdfoot(); } ?>
