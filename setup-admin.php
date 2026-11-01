<?php
// setup-admin.php — one-time admin creation. Key-gated, self-deleting.
// The key comes from the SETUP_KEY environment variable (set it in the
// hosting dashboard, then remove it after use). Refuses to run if an
// admin already exists.
declare(strict_types=1);

$key = (string)(getenv('SETUP_KEY') ?: '');
if ($key === '' || ($_GET['key'] ?? '') !== $key) {
    http_response_code(404);
    echo 'Not found.';
    exit;
}

require __DIR__ . '/config.php';

try {
    $pdo = orbit_db();
} catch (Throwable $e) {
    http_response_code(500);
    echo 'DB error. Check DB_HOST/DB_NAME/DB_USER/DB_PASS.';
    exit;
}

$existing = (int)$pdo->query('SELECT COUNT(*) FROM admin_users')->fetchColumn();
if ($existing > 0) {
    @unlink(__FILE__);
    http_response_code(403);
    echo 'An admin already exists. This page has deleted itself.';
    exit;
}

$err = '';
if (($_SERVER['REQUEST_METHOD'] ?? '') === 'POST') {
    $u = trim((string)($_POST['username'] ?? ''));
    $p = (string)($_POST['password'] ?? '');
    $p2 = (string)($_POST['password2'] ?? '');
    if ($u === '' || strlen($p) < 8) {
        $err = 'Username required; password minimum 8 characters.';
    } elseif ($p !== $p2) {
        $err = 'Passwords do not match.';
    } else {
        $pdo->prepare('INSERT INTO admin_users (username, pass_hash) VALUES (?, ?)')
            ->execute([$u, password_hash($p, PASSWORD_DEFAULT)]);
        @unlink(__FILE__);
        echo '<!DOCTYPE html><html><body style="font-family:sans-serif;background:#0b1026;color:#e8ecff;text-align:center;padding:60px">'
            . '<h1>✅ Admin created.</h1><p>This page has deleted itself.</p>'
            . '<p><a style="color:#7dd3fc" href="admin/">Go to admin login →</a></p></body></html>';
        exit;
    }
}
?>
<!DOCTYPE html>
<html lang="en">
<head><meta charset="utf-8"><meta name="viewport" content="width=device-width,initial-scale=1">
<title>Create admin — Orbit Earn</title></head>
<body style="font-family:sans-serif;background:#0b1026;color:#e8ecff;display:flex;justify-content:center;padding:40px">
<form method="post" style="background:#141b3d;padding:28px;border-radius:12px;min-width:280px">
<h2>🪐 Create admin</h2>
<?php if ($err !== '') echo '<p style="color:#f87171">' . htmlspecialchars($err) . '</p>'; ?>
<p><input name="username" placeholder="Username" required style="width:100%;padding:10px"></p>
<p><input name="password" type="password" placeholder="Password (min 8)" required style="width:100%;padding:10px"></p>
<p><input name="password2" type="password" placeholder="Repeat password" required style="width:100%;padding:10px"></p>
<p><button type="submit" style="width:100%;padding:12px;background:#7c3aed;color:#fff;border:0;border-radius:8px">Create admin</button></p>
</form></body></html>
