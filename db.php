<?php
require_once __DIR__ . '/config.php';

function getDB() {
    static $db = null;
    if ($db === null) {
        $dbDir = dirname(DB_PATH);
        if (!is_dir($dbDir)) {
            mkdir($dbDir, 0755, true);
        }
        $db = new SQLite3(DB_PATH);
        $db->busyTimeout(5000);
        $db->exec('PRAGMA journal_mode=WAL');
        $db->exec('PRAGMA foreign_keys=ON');
    }
    return $db;
}

function initDB() {
    $db = getDB();

    $db->exec('CREATE TABLE IF NOT EXISTS users (
        id INTEGER PRIMARY KEY AUTOINCREMENT,
        name TEXT UNIQUE NOT NULL COLLATE NOCASE,
        pin_hash TEXT NOT NULL,
        is_admin INTEGER DEFAULT 0,
        created_at TEXT DEFAULT CURRENT_TIMESTAMP
    )');

    $db->exec('CREATE TABLE IF NOT EXISTS series (
        id INTEGER PRIMARY KEY AUTOINCREMENT,
        round TEXT NOT NULL,
        label TEXT NOT NULL,
        team1 TEXT DEFAULT "",
        team2 TEXT DEFAULT "",
        status TEXT DEFAULT "upcoming",
        actual_winner TEXT DEFAULT "",
        actual_games INTEGER DEFAULT 0,
        picks_locked INTEGER DEFAULT 0,
        sort_order INTEGER DEFAULT 0
    )');

    $db->exec('CREATE TABLE IF NOT EXISTS picks (
        id INTEGER PRIMARY KEY AUTOINCREMENT,
        user_id INTEGER NOT NULL,
        series_id INTEGER NOT NULL,
        winner TEXT NOT NULL,
        games INTEGER NOT NULL,
        created_at TEXT DEFAULT CURRENT_TIMESTAMP,
        updated_at TEXT DEFAULT CURRENT_TIMESTAMP,
        FOREIGN KEY (user_id) REFERENCES users(id),
        FOREIGN KEY (series_id) REFERENCES series(id),
        UNIQUE(user_id, series_id)
    )');

    // Seed default series if table is empty
    $count = $db->querySingle('SELECT COUNT(*) FROM series');
    if ($count == 0) {
        $db->exec("INSERT INTO series (round, label, team1, team2, sort_order) VALUES
            -- West First Round
            ('first_round_west', '(1) Thunder vs (8) TBD', 'Thunder', '', 1),
            ('first_round_west', '(4) Lakers vs (5) Rockets', 'Lakers', 'Rockets', 2),
            ('first_round_west', '(3) Nuggets vs (6) Timberwolves', 'Nuggets', 'Timberwolves', 3),
            ('first_round_west', '(2) Spurs vs (7) TBD', 'Spurs', '', 4),
            -- East First Round
            ('first_round_east', '(1) Pistons vs (8) TBD', 'Pistons', '', 5),
            ('first_round_east', '(4) Cavaliers vs (5) Raptors', 'Cavaliers', 'Raptors', 6),
            ('first_round_east', '(3) Knicks vs (6) Hawks', 'Knicks', 'Hawks', 7),
            ('first_round_east', '(2) Celtics vs (7) TBD', 'Celtics', '', 8),
            -- West Conf Semis
            ('conf_semis_west', 'West Semifinal 1', '', '', 9),
            ('conf_semis_west', 'West Semifinal 2', '', '', 10),
            -- East Conf Semis
            ('conf_semis_east', 'East Semifinal 1', '', '', 11),
            ('conf_semis_east', 'East Semifinal 2', '', '', 12),
            -- Conf Finals
            ('conf_finals', 'Western Conference Finals', '', '', 13),
            ('conf_finals', 'Eastern Conference Finals', '', '', 14),
            -- NBA Finals
            ('finals', 'NBA Finals', '', '', 15)
        ");
    }
}

try {
    initDB();
} catch (Throwable $e) {
    // Let api.php handle the error
    throw $e;
}

