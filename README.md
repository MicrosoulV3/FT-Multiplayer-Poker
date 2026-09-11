# FastTracker Poker

A complete browser-based poker system built for **TorrentTrader / TTv3**, featuring multiplayer poker, tournament play, and private single-player games against the house.

FastTracker Poker integrates directly with the tracker's existing user accounts and upload-credit economy, allowing members to use their upload credit as poker chips without requiring a separate currency or account system.

![FastTracker Poker Lobby](screenshots/poker-lobby.webp)

## 🎰 Three Ways to Play

### Multiplayer Poker

Create traditional player-versus-player poker tables with support for up to **10 players per table**.

Players can join an open seat, watch games as spectators, buy in using upload credit, and compete against other tracker members in real time.

### Tournament Poker

Run organized poker tournaments with:

- Player registration
- Tournament entry fees
- Prize pools
- Tournament blinds
- Player limits
- Spectator support
- Administrative tournament controls

### The Collector — House Poker

Players can also play privately against **The Collector**, the FastTracker house opponent.

Each player receives their own independent house game, allowing multiple members to play against the house simultaneously without interfering with one another.

---

## ♠ Features

- 10-seat multiplayer poker tables
- Single-player house poker
- Tournament system
- Upload credit used as poker chips
- Configurable small and big blinds
- Configurable minimum and maximum buy-ins
- MB / GB credit support
- Raise-to betting controls
- Player turn countdown
- Automatic fold/check on timeout
- Reconnect grace period
- Spectator mode
- Live spectator count
- Poker chat
- Game sounds
- Room ambience with on/off control
- Player statistics
- Poker leaderboard
- Player profiles
- Administrative table management
- Tournament administration
- Poker maintenance mode
- Responsive live lobby
- Automatic lobby updates

---

## 🎮 Poker Lobby

The lobby provides a single location for every available game.

Multiplayer, tournament, and house tables are separated into their own sections with live information including:

- Players seated
- Spectators watching
- Blinds
- Buy-in limits
- Current hand
- Table status
- Tournament registration
- Prize pools

The player's available upload credit is displayed directly in the lobby.

---

## 💰 Upload Credit Economy

FastTracker Poker does not require a separate virtual currency.

The system uses the member's existing **TorrentTrader upload credit** as poker chips.

Table administrators can configure:

- Small blind
- Big blind
- Minimum buy-in
- Maximum buy-in
- Tournament entry requirements

Low-stakes tables can operate using MB while larger games can use GB-sized stakes.

---

## 👁 Spectator Mode

Members don't have to play to participate.

Users can watch active poker tables without occupying a seat. Spectator counts are displayed in the lobby, and spectators can take an available seat when appropriate.

---

## 🏆 Player Statistics & Leaderboard

FastTracker Poker tracks player performance and provides dedicated player profiles and a site-wide leaderboard.

Players can follow their poker history and compare their performance with other members.

---

## 🔧 Administration

Poker administrators can manage the system through the integrated Poker Admin Manager.

Administration includes:

- Create and delete tables
- Configure blinds
- Configure buy-in limits
- Manage tournaments
- Start tournaments
- Clear poker chat
- Control poker maintenance mode
- Manage table availability

Maintenance mode allows administrators to gracefully take the poker system offline while allowing active players to finish and cash out.

---

## 🖥 Requirements

FastTracker Poker is designed for integration with a **TorrentTrader / TTv3** installation.

Typical environment:

- PHP 7.4 or newer
- PHP 8.x compatible
- MySQL / MariaDB
- JavaScript-enabled modern browser
- Existing TorrentTrader user database and authentication system

The current code has been developed and tested with modern PHP 8.x environments.

---

## 📦 Installation

FastTracker Poker is an integrated TorrentTrader module rather than a standalone poker server.

Installation generally consists of:

1. Copying the poker files into the TorrentTrader installation.
2. Importing the supplied poker SQL schema.
3. Installing the supplied images and audio assets.
4. Verifying the required TorrentTrader includes and database connection.
5. Configuring poker administration permissions.
6. Creating the desired poker tables through the Poker Admin Manager.

**Database tables are installed using the supplied SQL file and are not automatically created by PHP.**

---

## 📸 Screenshots

### Poker Lobby

![FastTracker Poker Lobby](screenshots/poker-lobby.webp)

Additional screenshots of multiplayer games, tournaments, The Collector, and administration can be added here.

---

## 🔒 Intended Use

FastTracker Poker was designed as an integrated entertainment feature for private TorrentTrader communities.

Poker chips represent tracker upload credit and have no real-world monetary value.

---

## ♥ FastTracker Poker

**Multiplayer. Tournaments. The Collector.**

Built for TTv3.
