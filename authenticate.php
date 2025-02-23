<?php
session_start();

$usersFile = __DIR__ . '/users.json';

if ($_SERVER['REQUEST_METHOD'] === 'POST') {
    $username = trim($_POST['username'] ?? '');
    $password = $_POST['password'] ?? '';

    if (empty($username) || empty($password)) {
        $_SESSION['error'] = "Username and password are required.";
        header("Location: index.php");
        exit;
    }

    if (!file_exists($usersFile)) {
        $_SESSION['error'] = "No users registered yet.";
        header("Location: index.php");
        exit;
    }

    $users = json_decode(file_get_contents($usersFile), true);
    if (!is_array($users) || !isset($users[$username]) || !password_verify($password, $users[$username])) {
        $_SESSION['error'] = "Invalid username or password.";
        file_put_contents('/tmp/auth_debug.log', date('[Y-m-d H:i:s] ') . "Authenticate: Login failed for $username\n", FILE_APPEND);
        header("Location: index.php");
        exit;
    }

    $_SESSION['loggedin'] = true;
    $_SESSION['username'] = $username;
    session_regenerate_id(true); // Prevent session fixation
    file_put_contents('/tmp/auth_debug.log', date('[Y-m-d H:i:s] ') . "Authenticate: Session ID: " . session_id() . "\n", FILE_APPEND);
    file_put_contents('/tmp/auth_debug.log', date('[Y-m-d H:i:s] ') . "Authenticate: Loggedin set: " . var_export($_SESSION['loggedin'], true) . "\n", FILE_APPEND);
    file_put_contents('/tmp/auth_debug.log', date('[Y-m-d H:i:s] ') . "Authenticate: Username: $username\n", FILE_APPEND);
    header("Location: explorer.php?folder=Home");
    exit;
} else {
    header("Location: index.php");
    exit;
}
?>
