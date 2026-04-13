<?php
require_once __DIR__ . '/db.php';

header('Content-Type: application/json');
header('Access-Control-Allow-Origin: *');
header('Access-Control-Allow-Methods: GET, POST, OPTIONS');
header('Access-Control-Allow-Headers: Content-Type');

if ($_SERVER['REQUEST_METHOD'] === 'OPTIONS') {
    http_response_code(204);
    exit;
}

$action = $_GET['action'] ?? '';
$db = getDB();

function jsonResponse($data, $code = 200) {
    http_response_code($code);
    echo json_encode($data);
    exit;
}

function getInput() {
    $raw = file_get_contents('php://input');
    return json_decode($raw, true) ?? [];
}

function requireAuth($db) {
    $input = getInput();
    $name = trim($input['name'] ?? '');
    $pin = $input['pin'] ?? '';

    if (!$name || !$pin) {
        jsonResponse(['error' => 'Name and PIN required'], 401);
    }

    $stmt = $db->prepare('SELECT * FROM users WHERE name = :name');
    $stmt->bindValue(':name', $name, SQLITE3_TEXT);
    $user = $stmt->execute()->fetchArray(SQLITE3_ASSOC);

    if (!$user || !password_verify($pin, $user['pin_hash'])) {
        jsonResponse(['error' => 'Invalid name or PIN'], 401);
    }

    return [$user, $input];
}

switch ($action) {

    // ── Register ──
    case 'register':
        $input = getInput();
        $name = trim($input['name'] ?? '');
        $pin = $input['pin'] ?? '';

        if (!$name || strlen($name) < 2 || strlen($name) > 30) {
            jsonResponse(['error' => 'Name must be 2-30 characters'], 400);
        }
        if (!$pin || strlen($pin) < 4 || strlen($pin) > 20) {
            jsonResponse(['error' => 'PIN must be 4-20 characters'], 400);
        }

        // Check if name exists
        $stmt = $db->prepare('SELECT id FROM users WHERE name = :name');
        $stmt->bindValue(':name', $name, SQLITE3_TEXT);
        if ($stmt->execute()->fetchArray()) {
            jsonResponse(['error' => 'Name already taken'], 409);
        }

        $hash = password_hash($pin, PASSWORD_BCRYPT);
        $isAdmin = ($pin === ADMIN_PIN) ? 1 : 0;

        $stmt = $db->prepare('INSERT INTO users (name, pin_hash, is_admin) VALUES (:name, :hash, :admin)');
        $stmt->bindValue(':name', $name, SQLITE3_TEXT);
        $stmt->bindValue(':hash', $hash, SQLITE3_TEXT);
        $stmt->bindValue(':admin', $isAdmin, SQLITE3_INTEGER);
        $stmt->execute();

        jsonResponse(['success' => true, 'name' => $name, 'is_admin' => $isAdmin]);
        break;

    // ── Login ──
    case 'login':
        $input = getInput();
        $name = trim($input['name'] ?? '');
        $pin = $input['pin'] ?? '';

        $stmt = $db->prepare('SELECT * FROM users WHERE name = :name');
        $stmt->bindValue(':name', $name, SQLITE3_TEXT);
        $user = $stmt->execute()->fetchArray(SQLITE3_ASSOC);

        if (!$user || !password_verify($pin, $user['pin_hash'])) {
            jsonResponse(['error' => 'Invalid name or PIN'], 401);
        }

        jsonResponse([
            'success' => true,
            'name' => $user['name'],
            'is_admin' => (bool)$user['is_admin']
        ]);
        break;

    // ── Get bracket state ──
    case 'bracket':
        $results = $db->query('SELECT * FROM series ORDER BY sort_order');
        $series = [];
        while ($row = $results->fetchArray(SQLITE3_ASSOC)) {
            $series[] = $row;
        }
        jsonResponse(['series' => $series]);
        break;

    // ── Submit a pick ──
    case 'pick':
        [$user, $input] = requireAuth($db);

        $seriesId = (int)($input['series_id'] ?? 0);
        $winner = trim($input['winner'] ?? '');
        $games = (int)($input['games'] ?? 0);

        if (!$seriesId || !$winner || $games < 4 || $games > 7) {
            jsonResponse(['error' => 'Invalid pick data'], 400);
        }

        // Check series exists and picks aren't locked
        $stmt = $db->prepare('SELECT * FROM series WHERE id = :id');
        $stmt->bindValue(':id', $seriesId, SQLITE3_INTEGER);
        $series = $stmt->execute()->fetchArray(SQLITE3_ASSOC);

        if (!$series) {
            jsonResponse(['error' => 'Series not found'], 404);
        }
        if ($series['picks_locked']) {
            jsonResponse(['error' => 'Picks are locked for this series'], 403);
        }
        if ($winner !== $series['team1'] && $winner !== $series['team2']) {
            jsonResponse(['error' => 'Winner must be one of the teams in the series'], 400);
        }

        // Upsert pick
        $stmt = $db->prepare('INSERT INTO picks (user_id, series_id, winner, games)
            VALUES (:uid, :sid, :winner, :games)
            ON CONFLICT(user_id, series_id) DO UPDATE SET
                winner = :winner, games = :games, updated_at = datetime("now")');
        $stmt->bindValue(':uid', $user['id'], SQLITE3_INTEGER);
        $stmt->bindValue(':sid', $seriesId, SQLITE3_INTEGER);
        $stmt->bindValue(':winner', $winner, SQLITE3_TEXT);
        $stmt->bindValue(':games', $games, SQLITE3_INTEGER);
        $stmt->execute();

        jsonResponse(['success' => true]);
        break;

    // ── Get all picks (visible after locks) ──
    case 'picks':
        $results = $db->query('
            SELECT p.*, u.name as user_name, s.label as series_label,
                   s.team1, s.team2, s.picks_locked
            FROM picks p
            JOIN users u ON p.user_id = u.id
            JOIN series s ON p.series_id = s.id
            ORDER BY s.sort_order, u.name
        ');
        $picks = [];
        while ($row = $results->fetchArray(SQLITE3_ASSOC)) {
            $picks[] = $row;
        }
        jsonResponse(['picks' => $picks]);
        break;

    // ── Get my picks ──
    case 'my_picks':
        [$user, $input] = requireAuth($db);

        $stmt = $db->prepare('SELECT p.*, s.label, s.team1, s.team2, s.picks_locked
            FROM picks p JOIN series s ON p.series_id = s.id
            WHERE p.user_id = :uid ORDER BY s.sort_order');
        $stmt->bindValue(':uid', $user['id'], SQLITE3_INTEGER);
        $results = $stmt->execute();

        $picks = [];
        while ($row = $results->fetchArray(SQLITE3_ASSOC)) {
            $picks[] = $row;
        }
        jsonResponse(['picks' => $picks]);
        break;

    // ── Leaderboard ──
    case 'leaderboard':
        $users = $db->query('SELECT id, name FROM users ORDER BY name');
        $allUsers = [];
        while ($row = $users->fetchArray(SQLITE3_ASSOC)) {
            $allUsers[] = $row;
        }

        $completedSeries = $db->query('SELECT * FROM series WHERE status = "completed"');
        $completed = [];
        while ($row = $completedSeries->fetchArray(SQLITE3_ASSOC)) {
            $completed[] = $row;
        }

        $leaderboard = [];
        foreach ($allUsers as $user) {
            $points = 0;
            $correctWinners = 0;
            $correctGames = 0;
            $totalPicks = 0;

            foreach ($completed as $s) {
                $stmt = $db->prepare('SELECT * FROM picks WHERE user_id = :uid AND series_id = :sid');
                $stmt->bindValue(':uid', $user['id'], SQLITE3_INTEGER);
                $stmt->bindValue(':sid', $s['id'], SQLITE3_INTEGER);
                $pick = $stmt->execute()->fetchArray(SQLITE3_ASSOC);

                if ($pick) {
                    $totalPicks++;
                    if ($pick['winner'] === $s['actual_winner']) {
                        $points += POINTS_CORRECT_WINNER;
                        $correctWinners++;
                        if ($pick['games'] == $s['actual_games']) {
                            $points += POINTS_CORRECT_GAMES;
                            $correctGames++;
                        }
                    }
                }
            }

            $leaderboard[] = [
                'name' => $user['name'],
                'points' => $points,
                'correct_winners' => $correctWinners,
                'correct_games' => $correctGames,
                'total_picks' => $totalPicks
            ];
        }

        usort($leaderboard, fn($a, $b) => $b['points'] - $a['points']);
        jsonResponse(['leaderboard' => $leaderboard, 'scoring' => [
            'correct_winner' => POINTS_CORRECT_WINNER,
            'correct_games' => POINTS_CORRECT_GAMES
        ]]);
        break;

    // ── Admin: Update series ──
    case 'admin_update_series':
        [$user, $input] = requireAuth($db);

        if (!$user['is_admin']) {
            jsonResponse(['error' => 'Admin access required'], 403);
        }

        $seriesId = (int)($input['series_id'] ?? 0);
        if (!$seriesId) {
            jsonResponse(['error' => 'Series ID required'], 400);
        }

        $fields = [];
        $params = [];

        foreach (['team1', 'team2', 'status', 'actual_winner', 'label'] as $field) {
            if (isset($input[$field])) {
                $fields[] = "$field = :$field";
                $params[$field] = $input[$field];
            }
        }
        if (isset($input['actual_games'])) {
            $fields[] = 'actual_games = :actual_games';
            $params['actual_games'] = (int)$input['actual_games'];
        }
        if (isset($input['picks_locked'])) {
            $fields[] = 'picks_locked = :picks_locked';
            $params['picks_locked'] = (int)$input['picks_locked'];
        }

        if (empty($fields)) {
            jsonResponse(['error' => 'No fields to update'], 400);
        }

        $sql = 'UPDATE series SET ' . implode(', ', $fields) . ' WHERE id = :id';
        $stmt = $db->prepare($sql);
        $stmt->bindValue(':id', $seriesId, SQLITE3_INTEGER);
        foreach ($params as $key => $val) {
            $stmt->bindValue(":$key", $val, is_int($val) ? SQLITE3_INTEGER : SQLITE3_TEXT);
        }
        $stmt->execute();

        // If finals teams need auto-populating from conf finals winners
        if (isset($input['status']) && $input['status'] === 'completed') {
            $eastWinner = $db->querySingle("SELECT actual_winner FROM series WHERE round = 'conf_finals' AND sort_order = 1");
            $westWinner = $db->querySingle("SELECT actual_winner FROM series WHERE round = 'conf_finals' AND sort_order = 2");
            if ($eastWinner && $westWinner) {
                $stmt2 = $db->prepare("UPDATE series SET team1 = :t1, team2 = :t2 WHERE round = 'finals' AND team1 = '' AND team2 = ''");
                $stmt2->bindValue(':t1', $eastWinner, SQLITE3_TEXT);
                $stmt2->bindValue(':t2', $westWinner, SQLITE3_TEXT);
                $stmt2->execute();
            }
        }

        jsonResponse(['success' => true]);
        break;

    // ── Admin: Get all users ──
    case 'admin_users':
        [$user, $input] = requireAuth($db);
        if (!$user['is_admin']) {
            jsonResponse(['error' => 'Admin access required'], 403);
        }

        $results = $db->query('SELECT id, name, is_admin, created_at FROM users ORDER BY name');
        $users = [];
        while ($row = $results->fetchArray(SQLITE3_ASSOC)) {
            $users[] = $row;
        }
        jsonResponse(['users' => $users]);
        break;

    default:
        jsonResponse(['error' => 'Unknown action'], 400);
}
