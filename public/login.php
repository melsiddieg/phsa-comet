<?php
declare(strict_types=1);

/* ------------------------------------------------------------------
   0.  Bootstrap helpers  (unified session handling)
   ------------------------------------------------------------------ */
require_once 'common.php';       // defines my_session_start(), verify_session_*
my_session_start();              // same session cookie site-wide

/* ------------------------------------------------------------------
   1.  DB credentials
   ------------------------------------------------------------------ */
$dbHost = getenv('DB_HOST')        ?: 'db';
$dbPort = getenv('DB_PORT')        ?: '3306';
$dbName = getenv('MYSQL_DATABASE') ?: 'shaye067_phsa_db';
$dbUser = getenv('MYSQL_USER')     ?: 'comet_app';
$dbPass = trim(file_get_contents('/run/secrets/comet_app_pw'));

/* ------------------------------------------------------------------
   2.  PDO connection
   ------------------------------------------------------------------ */
$pdo = new PDO(
    "mysql:host={$dbHost};port={$dbPort};dbname={$dbName};charset=utf8mb4",
    $dbUser,
    $dbPass,
    [PDO::ATTR_ERRMODE => PDO::ERRMODE_EXCEPTION,
     PDO::ATTR_DEFAULT_FETCH_MODE => PDO::FETCH_ASSOC]
);

/* ------------------------------------------------------------------
   3.  Handle POST (login form)
   ------------------------------------------------------------------ */
$error = '';

if ($_SERVER['REQUEST_METHOD'] === 'POST') {
    $username = trim($_POST['username'] ?? '');
    $password = $_POST['password'] ?? '';

    if ($username === '' || $password === '') {
        $error = 'Please enter both username and password.';
    } else {
        /* Fetch user row */
        $stmt = $pdo->prepare(
            'SELECT id,
                    password_hash,
                    enabled,
                    mapper,
                    portal_admin,
                    importer,
                    reviewer
               FROM phsa_users
              WHERE username = ?'
        );
        $stmt->execute([$username]);
        $row = $stmt->fetch();

        /* Verify credentials */
        if ($row &&
            $row['enabled'] === '1' &&
            password_verify($password, $row['password_hash'])) {

            /* ---- session keys required by verify_session_vars() ---- */
            $_SESSION['PHSA_USER']   = 'Y';
            $_SESSION['PHSA_UID']    = $row['id'];
            $_SESSION['PHSA_UNAME']  = $username;

            /* privilege flags used in menus */
            $_SESSION['PHSA_PRIV_MAP']    = $row['mapper'];
            $_SESSION['PHSA_PRIV_REVIEW'] = $row['reviewer'];
            $_SESSION['PHSA_PRIV_IMPORT'] = $row['importer'];
            $_SESSION['PHSA_PRIV_ADMIN']  = $row['portal_admin'];
            $_SESSION['lastAcc'] = time();
            $_SESSION['key']     = md5(_SESSION_PASS . $_SESSION['lastAcc']);
            /* Audit login */
            $pdo->prepare(
                'UPDATE phsa_users
                    SET last_login_time = NOW(),
                        last_login_ip   = ?
                  WHERE username = ?'
            )->execute([$_SERVER['REMOTE_ADDR'] ?? '0.0.0.0', $username]);

            header('Location: index.php');
            exit;
        }

        $error = 'Invalid credentials or account disabled.';
    }
}
?>
<!DOCTYPE html>
<html lang="en">
<head>
  <meta charset="UTF-8">
  <title>COMET Login</title>
  <link rel="stylesheet" href="styles.css">
  <style>
      body { font-family: sans-serif; background:#f2f2f2; }
      .login-container { width:320px; margin:60px auto; padding:25px;
                         background:#fff; border-radius:8px;
                         box-shadow:0 2px 6px rgba(0,0,0,.1);}
      h1 { font-size:1.4rem; margin-bottom:1rem; }
      label { display:block; margin:.5rem 0 .2rem; }
      input { width:100%; padding:.45rem; margin-bottom:.7rem; }
      button { width:100%; padding:.5rem; background:#0078d4; color:#fff;
               border:0; border-radius:4px; cursor:pointer; }
      .error { color:#b00020; margin-bottom:.7rem; }
  </style>
</head>
<body>
  <div class="login-container">
    <h1>COMET Login</h1>

    <?php if ($error): ?>
      <p class="error"><?= htmlspecialchars($error, ENT_QUOTES) ?></p>
    <?php endif; ?>

    <form method="post" action="login.php">
      <label for="username">Username</label>
      <input type="text" id="username" name="username"
             autocomplete="username" required autofocus>

      <label for="password">Password</label>
      <input type="password" id="password" name="password"
             autocomplete="current-password" required>

      <button type="submit">Log&nbsp;In</button>
    </form>
  </div>
</body>
</html>
