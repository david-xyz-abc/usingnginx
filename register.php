<?php
session_start();

$usersFile = __DIR__ . '/users.json';
$baseWebdavDir = '/var/www/html/webdav/users';

if (!file_exists($usersFile)) {
    file_put_contents($usersFile, json_encode([]));
    chmod($usersFile, 0666);
}

if ($_SERVER['REQUEST_METHOD'] === 'POST') {
    $username = trim($_POST['username'] ?? '');
    $password = $_POST['password'] ?? '';

    if (empty($username) || empty($password)) {
        $_SESSION['error'] = "Username and password are required.";
        header("Location: index.php");
        exit;
    }

    $users = json_decode(file_get_contents($usersFile), true);
    if (isset($users[$username])) {
        $_SESSION['error'] = "Username already exists.";
        header("Location: index.php");
        exit;
    }

    $users[$username] = password_hash($password, PASSWORD_DEFAULT);
    if (file_put_contents($usersFile, json_encode($users, JSON_PRETTY_PRINT)) === false) {
        $_SESSION['error'] = "Failed to save user data.";
        header("Location: index.php");
        exit;
    }

    $userDir = "$baseWebdavDir/$username/Home";
    if (!is_dir($userDir)) {
        if (!mkdir($userDir, 0777, true) || !chown($userDir, 'www-data') || !chgrp($userDir, 'www-data')) {
            $_SESSION['error'] = "Failed to create user directory.";
            unset($users[$username]); // Roll back user addition
            file_put_contents($usersFile, json_encode($users, JSON_PRETTY_PRINT));
            header("Location: index.php");
            exit;
        }
    }

    $_SESSION['message'] = "Registration successful! Please sign in.";
    header("Location: index.php");
    exit;
} else {
    header("Location: index.php");
    exit;
}
?>
