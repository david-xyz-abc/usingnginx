<?php
session_start();
?>
<!DOCTYPE html>
<html lang="en">
<head>
  <meta charset="UTF-8">
  <title>DrivePulse - Login</title>
  <meta name="viewport" content="width=device-width, initial-scale=1.0">
  <link rel="preconnect" href="https://fonts.googleapis.com">
  <link rel="preconnect" href="https://fonts.gstatic.com" crossorigin>
  <link href="https://fonts.googleapis.com/css2?family=Poppins:wght@300;400;500;600&display=swap" rel="stylesheet">
  <link href="https://cdnjs.cloudflare.com/ajax/libs/font-awesome/6.0.0/css/all.min.css" rel="stylesheet"/>

  <style>
    :root {
      --background: #121212;
      --text-color: #fff;
      --content-bg: #1e1e1e;
      --border-color: #333;
      --button-bg: linear-gradient(135deg, #555, #777);
      --button-hover: linear-gradient(135deg, #777, #555);
      --accent-red: #d32f2f; /* Red for dark mode, matches explorer.php */
    }
    body.light-mode {
      --background: #f5f5f5;
      --text-color: #333;
      --content-bg: #fff;
      --border-color: #ccc;
      --button-bg: linear-gradient(135deg, #888, #aaa);
      --button-hover: linear-gradient(135deg, #aaa, #888);
      --accent-red: #f44336; /* Red for light mode, matches explorer.php */
    }
    * {
      margin: 0;
      padding: 0;
      box-sizing: border-box;
      font-family: 'Poppins', sans-serif;
    }
    body {
      background: var(--background);
      color: var(--text-color);
      display: flex;
      justify-content: center;
      align-items: center;
      min-height: 100vh;
      transition: background 0.3s, color 0.3s;
    }
    .login-container {
      background: var(--content-bg);
      border: 1px solid var(--border-color);
      border-radius: 8px;
      padding: 30px;
      width: 350px;
      box-shadow: 0 4px 8px rgba(0,0,0,0.3);
      text-align: center;
    }
    .logo-icon {
      font-size: 50px;
      margin-bottom: 15px;
      color: var(--accent-red); /* Changed from blue to red */
    }
    .project-name {
      font-size: 18px;
      font-weight: 500;
      margin-bottom: 25px;
      color: var(--text-color);
    }
    .error {
      color: var(--accent-red); /* Error messages in red */
      margin-bottom: 15px;
    }
    .form-group {
      text-align: left;
      margin-bottom: 20px;
    }
    .form-group label {
      display: block;
      margin-bottom: 6px;
      font-size: 14px;
      color: #ccc;
    }
    .form-group input {
      width: 100%;
      padding: 10px;
      background: #2a2a2a;
      border: 1px solid var(--border-color);
      border-radius: 4px;
      color: var(--text-color);
      font-size: 14px;
      transition: border-color 0.3s;
    }
    .form-group input:focus {
      outline: none;
      border-color: var(--accent-red); /* Changed from blue to red */
    }
    .button {
      width: 100%;
      padding: 12px;
      background: var(--button-bg);
      border: none;
      border-radius: 4px;
      color: var(--text-color);
      font-size: 15px;
      font-weight: 500;
      cursor: pointer;
      transition: background 0.3s, transform 0.2s;
    }
    .button:hover {
      background: var(--button-hover);
      transform: scale(1.03);
    }
    .button:active {
      transform: scale(0.98);
    }
    .register-button {
      background: linear-gradient(135deg, var(--accent-red), #b71c1c);
    }
    .register-button:hover {
      background: linear-gradient(135deg, #b71c1c, var(--accent-red));
      transform: scale(1.03);
    }
    .toggle-link {
      color: var(--accent-red); /* Changed from blue to red */
      cursor: pointer;
      text-decoration: underline;
      margin-top: 15px;
      display: inline-block;
      transition: color 0.3s;
    }
    .toggle-link:hover {
      color: #ff6666; /* Lighter red for hover */
    }
    .hidden {
      display: none;
    }
  </style>
</head>
<body>
  <div class="login-container">
    <i class="fas fa-cloud-upload-alt logo-icon"></i>
    <div class="project-name">DrivePulse</div>

    <?php 
      if (isset($_SESSION['error'])) {
          echo '<div class="error">' . htmlspecialchars($_SESSION['error']) . '</div>';
          unset($_SESSION['error']);
      }
      if (isset($_SESSION['message'])) {
          echo '<div style="color: var(--accent-red); margin-bottom: 15px;">' . htmlspecialchars($_SESSION['message']) . '</div>';
          unset($_SESSION['message']);
      }
    ?>

    <!-- Login Form -->
    <form action="authenticate.php" method="post" id="loginForm">
      <div class="form-group">
        <label for="username">Username</label>
        <input type="text" id="username" name="username" required>
      </div>

      <div class="form-group">
        <label for="password">Password</label>
        <input type="password" id="password" name="password" required>
      </div>

      <button type="submit" class="button">Sign In</button>
    </form>

    <span class="toggle-link" onclick="toggleForms()">Need an account? Register here</span>

    <!-- Registration Form -->
    <form action="register.php" method="post" id="registerForm" class="hidden">
      <div class="form-group">
        <label for="reg_username">Username</label>
        <input type="text" id="reg_username" name="username" required>
      </div>

      <div class="form-group">
        <label for="reg_password">Password</label>
        <input type="password" id="reg_password" name="password" required>
      </div>

      <button type="submit" class="button register-button">Register</button>
    </form>

    <span class="toggle-link hidden" onclick="toggleForms()" id="loginLink">Already have an account? Sign in</span>
  </div>

  <script>
    function toggleForms() {
      const loginForm = document.getElementById('loginForm');
      const registerForm = document.getElementById('registerForm');
      const loginLink = document.getElementById('loginLink');
      loginForm.classList.toggle('hidden');
      registerForm.classList.toggle('hidden');
      loginLink.classList.toggle('hidden');
      document.querySelectorAll('.toggle-link')[0].classList.toggle('hidden');
    }
  </script>
</body>
</html>
