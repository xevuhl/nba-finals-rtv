<?php
define('DB_PATH', __DIR__ . '/data/bracket.db');
define('ADMIN_PIN', '2586'); // Change this to your desired admin PIN
define('POINTS_CORRECT_WINNER', 10);
define('POINTS_CORRECT_GAMES', 5);

// Ensure data directory exists
if (!is_dir(__DIR__ . '/data')) {
    mkdir(__DIR__ . '/data', 0755, true);
}
