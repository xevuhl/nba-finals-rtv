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
        created_at TEXT DEFAULT (datetime("now"))
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
        created_at TEXT DEFAULT (datetime("now")),
        updated_at TEXT DEFAULT (datetime("now")),
        FOREIGN KEY (user_id) REFERENCES users(id),
        FOREIGN KEY (series_id) REFERENCES series(id),
        UNIQUE(user_id, series_id)
    )');

    // Seed default series if table is empty
    $count = $db->querySingle('SELECT COUNT(*) FROM series');
    if ($count == 0) {
        $db->exec("INSERT INTO series (round, label, team1, team2, sort_order) VALUES
            ('conf_finals', 'Eastern Conference Finals', '', '', 1),
            ('conf_finals', 'Western Conference Finals', '', '', 2),
            ('finals', 'NBA Finals', '', '', 3)
        ");
    }
}

initDB();

