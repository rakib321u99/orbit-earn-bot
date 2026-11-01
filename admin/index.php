<?php
// Orbit Earn Bot v1 — admin login.
declare(strict_types=1);
require __DIR__ . '/common.php';

$error = '';
if (($_SERVER['REQUEST_METHOD'] ?? '') === 'POST') {
    $username = trim((string)($_POST['username'] ?? ''));
    $password = (string)($_POST['password'] ?? '');
    try {
        $pdo = admin_pdo();
        $stmt = $pdo->prepare('SELECT * FROM admin_users WHERE username = ?');
        $stmt->execute([$username]);
        $admin = $stmt->fetch();
        if ($admin !== false && password_verify($password, (string)$admin['pass_hash'])) {
            $_SESSION['admin_id'] = (int)$admin['id'];
            header('Location: panel.php');
            exit;
        }
        $error = 'Invalid username or password.';
    } catch (Throwable $e) {
        $error = 'Database unavailable. Import schema.sql and configure env.php first.';
    }
}
?>
<!DOCTYPE html>
<html lang="en">
<head>
<meta charset="utf-8">
<meta name="viewport" content="width=device-width,initial-scale=1">
<title>Orbit Admin — Login</title>
<style>
body{font-family:system-ui,sans-serif;background:#0b1026;color:#e8ecff;display:flex;align-items:center;justify-content:center;min-height:100vh;margin:0}
.box{background:#141b3d;border:1px solid #2a3568;border-radius:16px;padding:32px;width:320px;box-shadow:0 0 60px #7c3aed33}
h1{margin:0 0 6px;font-size:22px}.sub{color:#9aa6dd;font-size:13px;margin-bottom:18px}
input{width:100%;box-sizing:border-box;background:#0e1440;border:1px solid #2a3568;color:#e8ecff;border-radius:8px;padding:10px;margin:6px 0;font-size:14px}
button{width:100%;background:linear-gradient(135deg,#7c3aed,#06b6d4);color:#fff;border:0;border-radius:8px;padding:11px;font-size:15px;cursor:pointer;margin-top:8px}
.err{background:#3b0a0a;border:1px solid #ef4444;color:#fecaca;padding:10px;border-radius:8px;margin-bottom:10px;font-size:13px}
</style>
</head>
<body>
<div class="box">
<h1>🪐 Orbit Admin</h1>
<div class="sub">Sign in to manage Orbit Earn Bot</div>
<?php if ($error !== ''): ?><div class="err"><?php echo admin_h($error); ?></div><?php endif; ?>
<form method="post">
<input name="username" placeholder="Username" autocomplete="username" required>
<input name="password" type="password" placeholder="Password" autocomplete="current-password" required>
<button type="submit">Login</button>
</form>
</div>
</body>
</html>
