-- FastTracker Poker - 9-11-2026
--
-- IMPORTANT:
--   * Run this SQL manually.
--   * This is intended for a NEW poker installation, not as an upgrade script.

SET NAMES utf8mb4;

CREATE TABLE `poker_tables` (
  `id` INT UNSIGNED NOT NULL AUTO_INCREMENT,
  `name` VARCHAR(64) NOT NULL,

  `game_type` ENUM('cash','tournament','house') NOT NULL DEFAULT 'cash',

  `small_blind` BIGINT UNSIGNED NOT NULL DEFAULT 104857600,
  `big_blind` BIGINT UNSIGNED NOT NULL DEFAULT 209715200,
  `starting_small_blind` BIGINT UNSIGNED NOT NULL DEFAULT 104857600,
  `starting_big_blind` BIGINT UNSIGNED NOT NULL DEFAULT 209715200,
  `blind_hands_per_level` SMALLINT UNSIGNED NOT NULL DEFAULT 5,

  `min_buyin` BIGINT UNSIGNED NOT NULL DEFAULT 1073741824,
  `max_buyin` BIGINT UNSIGNED NOT NULL DEFAULT 214748364800,
  `max_seats` TINYINT UNSIGNED NOT NULL DEFAULT 10,

  `status` ENUM('waiting','playing','showdown') NOT NULL DEFAULT 'waiting',
  `dealer_seat` TINYINT UNSIGNED DEFAULT NULL,
  `current_turn` TINYINT UNSIGNED DEFAULT NULL,
  `turn_expires_at` DATETIME DEFAULT NULL,
  `street` ENUM('preflop','flop','turn','river') NOT NULL DEFAULT 'preflop',
  `current_bet` BIGINT UNSIGNED NOT NULL DEFAULT 0,
  `min_raise` BIGINT UNSIGNED NOT NULL DEFAULT 0,

  `deck_json` TEXT DEFAULT NULL,
  `community_json` VARCHAR(255) DEFAULT NULL,
  `hand_no` INT UNSIGNED NOT NULL DEFAULT 0,
  `last_message` VARCHAR(500) NOT NULL DEFAULT '',

  `tournament_status` ENUM('registration','running','finished') NOT NULL DEFAULT 'registration',
  `tournament_entry_fee` BIGINT UNSIGNED NOT NULL DEFAULT 0,
  `tournament_starting_stack` BIGINT UNSIGNED NOT NULL DEFAULT 10000,
  `tournament_prize_pool` BIGINT UNSIGNED NOT NULL DEFAULT 0,
  `tournament_entries` SMALLINT UNSIGNED NOT NULL DEFAULT 0,
  `tournament_winner_user_id` INT UNSIGNED DEFAULT NULL,
  `tournament_started_at` DATETIME DEFAULT NULL,
  `tournament_ended_at` DATETIME DEFAULT NULL,

  `updated_at` TIMESTAMP NOT NULL DEFAULT CURRENT_TIMESTAMP ON UPDATE CURRENT_TIMESTAMP,

  PRIMARY KEY (`id`),
  KEY `idx_poker_table_type_status` (`game_type`,`status`),
  KEY `idx_poker_tournament_status` (`game_type`,`tournament_status`)
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci;


CREATE TABLE `poker_seats` (
  `table_id` INT UNSIGNED NOT NULL,
  `seat_no` TINYINT UNSIGNED NOT NULL,
  `user_id` INT UNSIGNED NOT NULL,
  `username` VARCHAR(64) NOT NULL,
  `avatar` VARCHAR(255) NOT NULL DEFAULT '',

  `stack` BIGINT UNSIGNED NOT NULL DEFAULT 0,
  `round_bet` BIGINT UNSIGNED NOT NULL DEFAULT 0,
  `hand_contribution` BIGINT UNSIGNED NOT NULL DEFAULT 0,
  `hand_state` ENUM('waiting','active','folded','allin') NOT NULL DEFAULT 'waiting',
  `acted` TINYINT(1) UNSIGNED NOT NULL DEFAULT 0,

  `hole1` CHAR(3) DEFAULT NULL,
  `hole2` CHAR(3) DEFAULT NULL,

  `sitting_out` TINYINT(1) UNSIGNED NOT NULL DEFAULT 0,
  `last_seen_at` DATETIME DEFAULT NULL,
  `reconnect_grace_until` DATETIME DEFAULT NULL,
  `reconnect_grace_used` TINYINT(1) UNSIGNED NOT NULL DEFAULT 0,

  `joined_at` TIMESTAMP NOT NULL DEFAULT CURRENT_TIMESTAMP,

  PRIMARY KEY (`table_id`,`seat_no`),
  UNIQUE KEY `uq_poker_table_user` (`table_id`,`user_id`),
  KEY `idx_poker_seat_user` (`user_id`),
  KEY `idx_poker_presence` (`table_id`,`last_seen_at`)
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci;


CREATE TABLE `poker_actions` (
  `id` BIGINT UNSIGNED NOT NULL AUTO_INCREMENT,
  `table_id` INT UNSIGNED NOT NULL,
  `hand_no` INT UNSIGNED NOT NULL,
  `user_id` INT UNSIGNED NOT NULL,
  `seat_no` TINYINT UNSIGNED NOT NULL,
  `action_name` VARCHAR(32) NOT NULL,
  `amount` BIGINT UNSIGNED NOT NULL DEFAULT 0,
  `created_at` DATETIME NOT NULL DEFAULT CURRENT_TIMESTAMP,

  PRIMARY KEY (`id`),
  KEY `idx_poker_action_hand` (`table_id`,`hand_no`,`id`),
  KEY `idx_poker_action_user` (`user_id`,`id`)
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci;


CREATE TABLE `poker_hand_history` (
  `id` BIGINT UNSIGNED NOT NULL AUTO_INCREMENT,
  `table_id` INT UNSIGNED NOT NULL,
  `hand_no` INT UNSIGNED NOT NULL,
  `dealer_seat` TINYINT UNSIGNED NOT NULL,
  `small_blind` BIGINT UNSIGNED NOT NULL DEFAULT 0,
  `big_blind` BIGINT UNSIGNED NOT NULL DEFAULT 0,
  `players_json` TEXT NOT NULL,
  `board_json` VARCHAR(255) NOT NULL DEFAULT '[]',
  `status` ENUM('playing','finished') NOT NULL DEFAULT 'playing',
  `showdown_reached` TINYINT(1) UNSIGNED NOT NULL DEFAULT 0,
  `result_text` VARCHAR(500) NOT NULL DEFAULT '',
  `started_at` DATETIME NOT NULL,
  `ended_at` DATETIME DEFAULT NULL,

  PRIMARY KEY (`id`),
  UNIQUE KEY `uq_poker_history_hand` (`table_id`,`hand_no`),
  KEY `idx_poker_history_recent` (`table_id`,`hand_no`)
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci;


CREATE TABLE `poker_chat` (
  `id` BIGINT UNSIGNED NOT NULL AUTO_INCREMENT,
  `table_id` INT UNSIGNED NOT NULL,
  `user_id` INT UNSIGNED NOT NULL,
  `username` VARCHAR(64) NOT NULL,
  `message` VARCHAR(300) NOT NULL,
  `created_at` DATETIME NOT NULL,

  PRIMARY KEY (`id`),
  KEY `idx_poker_chat_table` (`table_id`,`id`),
  KEY `idx_poker_chat_recent_user` (`table_id`,`user_id`,`created_at`)
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci;


CREATE TABLE `poker_player_stats` (
  `user_id` INT UNSIGNED NOT NULL,
  `hands_played` INT UNSIGNED NOT NULL DEFAULT 0,
  `hands_won` INT UNSIGNED NOT NULL DEFAULT 0,
  `folds` INT UNSIGNED NOT NULL DEFAULT 0,
  `allins` INT UNSIGNED NOT NULL DEFAULT 0,
  `showdowns_seen` INT UNSIGNED NOT NULL DEFAULT 0,
  `showdowns_won` INT UNSIGNED NOT NULL DEFAULT 0,
  `total_won` BIGINT UNSIGNED NOT NULL DEFAULT 0,
  `net_profit` BIGINT NOT NULL DEFAULT 0,
  `biggest_pot` BIGINT UNSIGNED NOT NULL DEFAULT 0,
  `updated_at` DATETIME DEFAULT NULL,

  PRIMARY KEY (`user_id`),
  KEY `idx_poker_stats_profit` (`net_profit`),
  KEY `idx_poker_stats_wins` (`hands_won`),
  KEY `idx_poker_stats_biggest` (`biggest_pot`),
  KEY `idx_poker_stats_hands` (`hands_played`)
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci;


CREATE TABLE `poker_spectators` (
  `table_id` INT UNSIGNED NOT NULL,
  `user_id` INT UNSIGNED NOT NULL,
  `username` VARCHAR(64) NOT NULL,
  `last_seen_at` DATETIME NOT NULL,

  PRIMARY KEY (`table_id`,`user_id`),
  KEY `idx_poker_spectator_active` (`table_id`,`last_seen_at`),
  KEY `idx_poker_spectator_user` (`user_id`)
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci;


CREATE TABLE `poker_house_sessions` (
  `id` BIGINT UNSIGNED NOT NULL AUTO_INCREMENT,
  `template_id` INT UNSIGNED NOT NULL,
  `user_id` INT UNSIGNED NOT NULL,
  `username` VARCHAR(64) NOT NULL,
  `player_stack` BIGINT UNSIGNED NOT NULL DEFAULT 0,
  `session_status` ENUM('waiting','playing','showdown','closed') NOT NULL DEFAULT 'waiting',
  `state_json` LONGTEXT NOT NULL,
  `active` TINYINT(1) UNSIGNED NOT NULL DEFAULT 1,
  `created_at` DATETIME NOT NULL,
  `last_seen_at` DATETIME NOT NULL,
  `updated_at` DATETIME NOT NULL,

  PRIMARY KEY (`id`),
  KEY `idx_house_template_active` (`template_id`,`active`,`last_seen_at`),
  KEY `idx_house_user_active` (`user_id`,`active`,`id`)
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci;


CREATE TABLE `poker_user_notices` (
  `id` BIGINT UNSIGNED NOT NULL AUTO_INCREMENT,
  `user_id` INT UNSIGNED NOT NULL,
  `table_id` INT UNSIGNED DEFAULT NULL,
  `notice_type` VARCHAR(32) NOT NULL DEFAULT 'info',
  `title` VARCHAR(100) NOT NULL,
  `message` VARCHAR(500) NOT NULL,
  `created_by` INT UNSIGNED DEFAULT NULL,
  `created_at` DATETIME NOT NULL,

  PRIMARY KEY (`id`),
  KEY `idx_poker_notice_user` (`user_id`,`id`)
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci;


CREATE TABLE `poker_settings` (
  `id` TINYINT UNSIGNED NOT NULL,
  `maintenance_mode` TINYINT UNSIGNED NOT NULL DEFAULT 0,
  `updated_at` DATETIME DEFAULT NULL,
  `updated_by` INT UNSIGNED DEFAULT NULL,

  PRIMARY KEY (`id`)
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci;

INSERT INTO `poker_settings`
  (`id`,`maintenance_mode`,`updated_at`,`updated_by`)
VALUES
  (1,0,NULL,NULL);


-- The current admin page reads this table even when no grants exist,
-- so it is part of the master schema.
CREATE TABLE `poker_starter_grants` (
  `id` BIGINT UNSIGNED NOT NULL AUTO_INCREMENT,
  `user_id` INT UNSIGNED NOT NULL,
  `amount` BIGINT UNSIGNED NOT NULL,
  `credit_before` BIGINT UNSIGNED NOT NULL DEFAULT 0,
  `claimed_at` DATETIME NOT NULL,

  PRIMARY KEY (`id`),
  UNIQUE KEY `uq_poker_starter_user` (`user_id`),
  KEY `idx_poker_starter_claimed` (`claimed_at`)
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci;

-- No poker tables are pre-created here.
-- Use poker-admin.php to create cash, House, and tournament tables with the
-- exact stakes and limits desired for the installation.
