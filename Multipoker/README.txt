FastTracker Poker
==================

INSTALLATION

After installing the poker files and database, link your site's Poker
menu/button directly to:

    poker-lobby.php

The Poker Lobby is the main starting point for the game.

From the lobby, players can:

- Join multiplayer cash tables
- Join tournament tables
- Play against The Collector
- Return to an active poker game
- View the leaderboard and player profiles

There is no need to link directly to poker.php or individual tables.
The lobby handles directing players to the appropriate game.

Example:

<a href="poker-lobby.php">Poker</a>

IMPORTANT:
Run sql/poker.sql manually before using Poker. The PHP files do not
create or modify the required database tables.