<?php
// Orbit Earn Bot v1 — admin shared helpers.
// Session auth, HTML shell, and the withdrawal decision notifier.
// OWNER RULE (2026-10-31): no approve/reject may complete silently —
// the bot MUST message the user's chat on every decision.
declare(strict_types=1);
require __DIR__ . '/../config.php';

session_start();

function admin_pdo(): PDO
{
    return orbit_db();
}

function admin_require_login(): array
{
    if (empty($_SESSION['admin_id'])) {
        header('Location: index.php');
        exit;
    }
    $pdo = admin_pdo();
    $stmt = $pdo->prepare('SELECT * FROM admin_users WHERE id = ?');
    $stmt->execute([(int)$_SESSION['admin_id']]);
    $admin = $stmt->fetch();
    if ($admin === false) {
        unset($_SESSION['admin_id']);
        header('Location: index.php');
        exit;
    }
    return [$pdo, $admin];
}

function admin_h(string $s): string
{
    return htmlspecialchars($s, ENT_QUOTES, 'UTF-8');
}

function admin_shell(string $title, string $body, string $active = ''): void
{
    $pages = [
        'dashboard' => '📊 Dashboard',
        'withdrawals' => '💸 Withdrawals',
        'tasks' => '✅ Tasks',
        'games' => '🎮 Games',
        'ads' => '📺 Ads',
        'users' => '👥 Users',
        'broadcast' => '📢 Broadcast',
        'settings' => '⚙️ Settings',
        'telegram' => '🤖 Telegram',
    ];
    $nav = '';
    foreach ($pages as $key => $label) {
        $cls = $key === $active ? 'active' : '';
        $nav .= '<a class="' . $cls . '" href="panel.php?page=' . $key . '">' . $label . '</a>';
    }
    echo '<!DOCTYPE html><html lang="en"><head><meta charset="utf-8">'
        . '<meta name="viewport" content="width=device-width,initial-scale=1">'
        . '<title>' . admin_h($title) . ' — Orbit Admin</title>'
        . '<style>'
        . 'body{font-family:system-ui,sans-serif;background:#0b1026;color:#e8ecff;margin:0}'
        . '.wrap{max-width:1100px;margin:0 auto;padding:20px}'
        . 'nav{background:#111736;padding:10px 20px;display:flex;gap:6px;flex-wrap:wrap;position:sticky;top:0;z-index:5}'
        . 'nav a{color:#aab4e8;text-decoration:none;padding:8px 12px;border-radius:8px}'
        . 'nav a.active,nav a:hover{background:#7c3aed;color:#fff}'
        . '.card{background:#141b3d;border:1px solid #2a3568;border-radius:12px;padding:16px;margin:14px 0}'
        . 'table{width:100%;border-collapse:collapse;font-size:14px}'
        . 'th,td{padding:10px;border-bottom:1px solid #2a3568;text-align:left;vertical-align:top}'
        . 'th{color:#9aa6dd}.addr{font-family:monospace;word-break:break-all;font-size:12px;color:#bfe3ff}'
        . 'input,select,textarea{background:#0e1440;border:1px solid #2a3568;color:#e8ecff;border-radius:8px;padding:9px 10px;font-size:14px}'
        . 'button,.btn{background:linear-gradient(135deg,#7c3aed,#06b6d4);color:#fff;border:0;border-radius:8px;padding:9px 16px;font-size:14px;cursor:pointer;text-decoration:none;display:inline-block}'
        . 'button.danger{background:linear-gradient(135deg,#dc2626,#7f1d1d)}'
        . 'button.ghost{background:#232c5e}'
        . '.ok{background:#052e22;border:1px solid #10b981;color:#a7f3d0;padding:10px;border-radius:8px;margin:10px 0}'
        . '.err{background:#3b0a0a;border:1px solid #ef4444;color:#fecaca;padding:10px;border-radius:8px;margin:10px 0}'
        . '.stats{display:grid;grid-template-columns:repeat(auto-fit,minmax(160px,1fr));gap:12px}'
        . '.stat{background:#141b3d;border:1px solid #2a3568;border-radius:12px;padding:14px}'
        . '.stat b{font-size:22px;display:block;color:#fff}.stat span{color:#9aa6dd;font-size:12px}'
        . 'form.inline{display:inline}img.proof{max-width:220px;border-radius:8px;border:1px solid #2a3568}'
        . '</style></head><body><nav>' . $nav . '<a href="logout.php">🚪 Logout</a></nav>'
        . '<div class="wrap">' . $body . '</div></body></html>';
}

// ---- Withdrawal decision notifier (owner rule: never silent) ----
// Returns [bool $sent, string $note].
function orbit_notify_withdrawal(PDO $pdo, array $env, array $wd, string $decision): array
{
    $token = (string)($env['BOT_TOKEN'] ?? '');
    $chatId = (int)($wd['chat_id'] ?? 0);
    if ($token === '' || $chatId <= 0) {
        return [false, 'not sent: bot token or user chat id missing'];
    }
    $rate = orbit_setting_int($pdo, 'rate_orbit_per_usd', 100000);
    $gateways = orbit_gateways();
    $gwLabel = $gateways[$wd['gateway']] ?? (string)$wd['gateway'];
    $usd = orbit_to_usd((int)$wd['amount_orbit'], $rate);

    if ($decision === 'paid') {
        $text = "🎉 *Withdrawal Successful!*\n\n"
            . "Amount: " . number_format((int)$wd['amount_orbit']) . " orbit (\$$usd)\n"
            . "Gateway: $gwLabel\n"
            . "Address: `" . (string)$wd['address'] . "`\n";
        if (!empty($wd['proof_tx'])) {
            $text .= "TX: `" . (string)$wd['proof_tx'] . "`\n";
        }
        $text .= "\nYour funds have been sent successfully! 🚀";

        $photoPath = (string)($wd['proof_path'] ?? '');
        if ($photoPath !== '' && is_file(__DIR__ . '/../' . $photoPath)) {
            // sendPhoto with multipart upload (Bot API file upload).
            $ch = curl_init('https://api.telegram.org/bot' . $token . '/sendPhoto');
            $post = [
                'chat_id' => (string)$chatId,
                'caption' => $text,
                'parse_mode' => 'Markdown',
                'photo' => new CURLFile(__DIR__ . '/../' . $photoPath),
            ];
            curl_setopt_array($ch, [CURLOPT_RETURNTRANSFER => true, CURLOPT_POST => true, CURLOPT_POSTFIELDS => $post, CURLOPT_TIMEOUT => 30]);
            $body = curl_exec($ch);
            $code = (int)curl_getinfo($ch, CURLINFO_RESPONSE_CODE);
            curl_close($ch);
            $ok = $code === 200 && $body !== false && (json_decode((string)$body, true)['ok'] ?? false) === true;
            return [$ok, $ok ? 'sent with proof photo' : 'photo send failed (HTTP ' . $code . ')'];
        }
        $res = orbit_tg_send($token, 'sendMessage', ['chat_id' => $chatId, 'text' => $text, 'parse_mode' => 'Markdown']);
        $ok = $res['code'] === 200 && ($res['data']['ok'] ?? false) === true;
        return [$ok, $ok ? 'sent' : 'send failed (HTTP ' . $res['code'] . ')'];
    }

    // rejected
    $text = "❌ *Withdrawal Rejected*\n\n"
        . "Amount: " . number_format((int)$wd['amount_orbit']) . " orbit (\$$usd)\n"
        . "Gateway: $gwLabel\n"
        . "Reason: " . (string)($wd['reject_reason'] ?? 'not specified') . "\n\n"
        . "Your orbit has been refunded to your balance.";
    $res = orbit_tg_send($token, 'sendMessage', ['chat_id' => $chatId, 'text' => $text, 'parse_mode' => 'Markdown']);
    $ok = $res['code'] === 200 && ($res['data']['ok'] ?? false) === true;
    return [$ok, $ok ? 'sent' : 'send failed (HTTP ' . $res['code'] . ')'];
}
