<?php
/**
 * Memory Game API Endpoint Handler
 * 
 * This file serves as the main API router and handles:
 * - User authentication (login/register)
 * - Score saving and retrieval
 * - Leaderboard data
 */

require_once 'config.php';

// Get the request path
$request_uri = $_SERVER['REQUEST_URI'];
$path = parse_url($request_uri, PHP_URL_PATH);
$path_parts = explode('/', trim($path, '/'));
$endpoint = end($path_parts);

// Get JSON data for POST requests
$json_data = null;
if ($_SERVER['REQUEST_METHOD'] === 'POST') {
    $json_data = json_decode(file_get_contents('php://input'), true);
    if (json_last_error() !== JSON_ERROR_NONE) {
        http_response_code(400);
        echo json_encode(['error' => 'Invalid JSON data']);
        exit;
    }
}

try {
    switch ($_SERVER['REQUEST_METHOD']) {
        case 'POST':
            switch ($endpoint) {
                case 'login':
                    /**
                     * User Login Endpoint
                     * Validates credentials and returns user ID on success
                     * 
                     * Request body:
                     * {
                     *   "username": string,
                     *   "password": string
                     * }
                     * 
                     * Response:
                     * {
                     *   "success": boolean,
                     *   "userId"?: number,
                     *   "message"?: string
                     * }
                     */
                    if (!isset($json_data['username']) || !isset($json_data['password'])) {
                        throw new Exception('Missing username or password');
                    }

                    $stmt = $pdo->prepare('SELECT id, password FROM users WHERE username = ?');
                    $stmt->execute([$json_data['username']]);
                    $user = $stmt->fetch();

                    if ($user && password_verify($json_data['password'], $user['password'])) {
                        echo json_encode(['success' => true, 'userId' => $user['id']]);
                    } else {
                        echo json_encode(['success' => false, 'message' => 'Invalid credentials']);
                    }
                    break;

                case 'register':
                    /**
                     * User Registration Endpoint
                     * Creates new user account with hashed password
                     * 
                     * Request body:
                     * {
                     *   "username": string,
                     *   "password": string
                     * }
                     * 
                     * Response:
                     * {
                     *   "success": boolean,
                     *   "message"?: string
                     * }
                     */
                    if (!isset($json_data['username']) || !isset($json_data['password'])) {
                        throw new Exception('Missing username or password');
                    }

                    $hashedPassword = password_hash($json_data['password'], PASSWORD_DEFAULT);
                    
                    try {
                        $stmt = $pdo->prepare('INSERT INTO users (username, password) VALUES (?, ?)');
                        $stmt->execute([$json_data['username'], $hashedPassword]);
                        echo json_encode(['success' => true]);
                    } catch (PDOException $e) {
                        if ($e->getCode() == 23000) { // Duplicate entry error
                            echo json_encode(['success' => false, 'message' => 'Username already exists']);
                        } else {
                            throw $e;
                        }
                    }
                    break;

                case 'save-score':
                    if (!isset($json_data['userId']) || !isset($json_data['score']) || !isset($json_data['timeRemaining'])) {
                        throw new Exception('Missing required score data');
                    }

                    $stmt = $pdo->prepare('INSERT INTO scores (user_id, score, time_remaining) VALUES (?, ?, ?)');
                    $stmt->execute([
                        $json_data['userId'],
                        $json_data['score'],
                        $json_data['timeRemaining']
                    ]);
                    echo json_encode(['success' => true]);
                    break;

                default:
                    http_response_code(404);
                    echo json_encode(['error' => 'Endpoint not found']);
                    break;
            }
            break;

        case 'GET':
            switch ($endpoint) {
                case 'leaderboard':
                    $stmt = $pdo->query('
                        SELECT 
                            ROW_NUMBER() OVER (ORDER BY s.score DESC, s.time_remaining DESC) as rank,
                            u.username as player, 
                            s.score, 
                            s.time_remaining as time_left
                        FROM scores s
                        JOIN users u ON s.user_id = u.id
                        ORDER BY s.score DESC, s.time_remaining DESC
                    ');
                    echo json_encode($stmt->fetchAll());
                    break;

                default:
                    http_response_code(404);
                    echo json_encode(['error' => 'Endpoint not found']);
                    break;
            }
            break;

        default:
            http_response_code(405);
            echo json_encode(['error' => 'Method not allowed']);
            break;
    }
} catch (Exception $e) {
    http_response_code(500);
    echo json_encode(['error' => $e->getMessage()]);
} 