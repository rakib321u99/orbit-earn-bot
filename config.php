<?php
// Orbit Earn Bot v1 — config loader + shared helpers.
// env.php is NEVER shipped in this project. Placeholders live in env.example.php.
// Without env.php the app runs "not configured": auth fails closed, bot.php exits.
declare(strict_types=1);
date_default_timezone_set('Asia/Dhaka');

function orbit_env(): array
{
    $env = [];
    $file = __DIR__ . '/env.php';
    if (is_file($file)) {
        $v = require $file;
        if (is_array($v)) {
            $env = $v;
        }
    } elseif (is_file(__DIR__ . '/env.example.php')) {
        $v = require __DIR__ . '/env.example.php';
        if (is_array($v)) {
            $env = $v;
        }
    }
    // Real environment variables (e.g. Render.com) override file values.
    // On classic hosting no such vars exist, so env.php keeps working unchanged.
    foreach (['DB_HOST', 'DB_PORT', 'DB_NAME', 'DB_USER', 'DB_PASS', 'DB_SSL', 'DB_SSL_CA', 'BOT_TOKEN', 'BOT_USERNAME', 'WEBAPP_URL', 'ADMIN_TELEGRAM_ID'] as $k) {
        $gv = getenv($k);
        if ($gv !== false && $gv !== '') {
            $env[$k] = $gv;
        }
    }
    return $env;
}

function orbit_db(): PDO
{
    $e = orbit_env();
    $port = (string)($e['DB_PORT'] ?? '');
    $dsn = 'mysql:host=' . ($e['DB_HOST'] ?? 'localhost')
        . ($port !== '' ? ';port=' . $port : '')
        . ';dbname=' . ($e['DB_NAME'] ?? 'orbit_earn') . ';charset=utf8mb4';
    $opts = [
        PDO::ATTR_ERRMODE => PDO::ERRMODE_EXCEPTION,
        PDO::ATTR_DEFAULT_FETCH_MODE => PDO::FETCH_ASSOC,
    ];
    // Managed MySQL (e.g. TiDB Cloud) requires TLS. DB_SSL=1 enables it
    // using the system CA bundle; DB_SSL_CA can point at a custom CA file.
    if (($e['DB_SSL'] ?? '') === '1') {
        $ca = (string)($e['DB_SSL_CA'] ?? '');
        if ($ca === '' || !is_file($ca)) {
            $ca = '/etc/ssl/certs/ca-certificates.crt';
        }
        if (is_file($ca)) {
            $opts[PDO::MYSQL_ATTR_SSL_CA] = $ca;
        }
    }
    $pdo = new PDO($dsn, (string)($e['DB_USER'] ?? ''), (string)($e['DB_PASS'] ?? ''), $opts);
    return $pdo;
}

// ---- Settings (DB-backed, admin-editable; defaults mirror schema.sql) ----
function orbit_setting(PDO $pdo, string $key, string $default = ''): string
{
    static $cache = [];
    if (array_key_exists($key, $cache)) {
        return $cache[$key];
    }
    try {
        $stmt = $pdo->prepare('SELECT svalue FROM settings WHERE skey = ?');
        $stmt->execute([$key]);
        $row = $stmt->fetch();
        $val = $row === false ? $default : (string)$row['svalue'];
    } catch (Throwable $e) {
        $val = $default;
    }
    $cache[$key] = $val;
    return $val;
}

function orbit_setting_int(PDO $pdo, string $key, int $default = 0): int
{
    return (int)orbit_setting($pdo, $key, (string)$default);
}

// ---- Money math (integer orbit only; no floats for money) ----
// 100,000 orbit = $1  =>  1 orbit = $0.00001. USD shown with 4 decimals.
function orbit_to_usd(int $orbit, int $ratePerUsd): string
{
    if ($ratePerUsd <= 0) {
        $ratePerUsd = 100000;
    }
    $usd = $orbit / $ratePerUsd;
    return number_format($usd, 4, '.', '');
}

// "Orbit day": Asia/Dhaka calendar day starting at 06:00 (game reset time).
function orbit_today(?int $now = null): string
{
    $now = $now ?? time();
    $dt = new DateTime('@' . $now);
    $dt->setTimezone(new DateTimeZone('Asia/Dhaka'));
    if ((int)$dt->format('H') < 6) {
        $dt->modify('-1 day');
    }
    return $dt->format('Y-m-d');
}

// ---- Game catalogue (original games) ----
function orbit_games(): array
{
    return [
        'blaster'   => ['name' => 'Orbit Blaster',    'max_score' => 60,  'min_ms' => 15000, 'max_ms' => 90000],
        'startap'   => ['name' => 'Star Tap',         'max_score' => 120, 'min_ms' => 10000, 'max_ms' => 60000],
        'tictactoe' => ['name' => 'Cosmic Tic Tac Toe', 'max_score' => 1, 'min_ms' => 8000,  'max_ms' => 300000],
        'words'     => ['name' => 'Nebula Words',     'max_score' => 3,   'min_ms' => 10000, 'max_ms' => 180000],
    ];
}

function orbit_gateways(): array
{
    return [
        'xrocket'   => 'xRocket',
        'usdt'      => 'USDT (BEP-20)',
        'faucetpay' => 'FaucetPay',
        'ton'       => 'TON',
    ];
}

function orbit_gateway_fee(PDO $pdo, string $gateway): int
{
    $map = ['xrocket' => 'fee_xrocket', 'usdt' => 'fee_usdt', 'faucetpay' => 'fee_faucetpay', 'ton' => 'fee_ton'];
    $key = $map[$gateway] ?? 'fee_xrocket';
    return orbit_setting_int($pdo, $key, 100);
}

// ---- Ad providers (owner's 2026-10-31 decisions): Adsgram + Giga.pub +
// Monetag + Adsterra. Each ad unit has a provider
// (placeholder | adsgram | gigapub | monetag | adsterra) and the ID its
// provider needs, stored in settings (admin-editable in Admin > Ads).
// "placeholder" is the safe default: a 5-second in-app timer, no real ads.
// Monetag's TMA SDK script URL is account-specific, so it is one global
// setting; each unit carries its own main zone ID. Adsterra's invoke
// domain comes from the publisher's own ad code (global, with a sane
// default); each unit carries its placement key. NO popunders anywhere —
// only rewarded / banner / native formats, per the owner's standing rule.
function orbit_ad_providers(): array
{
    return ['placeholder', 'adsgram', 'gigapub', 'monetag', 'adsterra'];
}

function orbit_ad_unit_config(PDO $pdo, string $unit): array
{
    $provider = orbit_setting($pdo, $unit . '_provider', 'placeholder');
    if (!in_array($provider, orbit_ad_providers(), true)) {
        $provider = 'placeholder';
    }
    $blockId = trim(orbit_setting($pdo, $unit . '_block_id', ''));
    $gigaProject = trim(orbit_setting($pdo, 'giga_project_id', ''));
    $monetagZone = trim(orbit_setting($pdo, $unit . '_monetag_zone', ''));
    if ($monetagZone !== '' && !ctype_digit($monetagZone)) {
        $monetagZone = ''; // Monetag main zone IDs are digits (docs.monetag.com)
    }
    $monetagSdk = trim(orbit_setting($pdo, 'monetag_sdk_src', ''));
    if (!preg_match('#^https://[^\s<>"\']+$#', $monetagSdk)) {
        $monetagSdk = ''; // must be the exact script URL from the Monetag dashboard
    }
    $adsterraFormat = trim(orbit_setting($pdo, $unit . '_adsterra_format', 'banner'));
    if (!in_array($adsterraFormat, ['banner', 'native', 'socialbar'], true)) {
        $adsterraFormat = 'banner'; // popunders are never an option (owner's rule)
    }
    $adsterraKey = trim(orbit_setting($pdo, $unit . '_adsterra_key', ''));
    if ($adsterraFormat === 'socialbar') {
        // Social Bar ships as a full script URL from the publisher panel.
        if (!preg_match('#^https://[^\s<>"\']+$#', $adsterraKey)) {
            $adsterraKey = '';
        }
    } elseif (!preg_match('/^[A-Za-z0-9]{8,64}$/', $adsterraKey)) {
        $adsterraKey = ''; // banner/native placement key (from atOptions in the ad code)
    }
    $adsterraDomain = trim(orbit_setting($pdo, 'adsterra_invoke_domain', ''));
    if (!preg_match('/^[A-Za-z0-9.-]+$/', $adsterraDomain)) {
        $adsterraDomain = 'www.highperformanceformat.com'; // Adsterra's standard invoke domain
    }
    $cap = orbit_setting_int($pdo, $unit . '_cap', 5);
    $reward = orbit_setting_int($pdo, $unit . '_reward', 50);
    // A unit is usable only when it is properly configured for its
    // provider: Adsgram needs a block ID, Giga.pub needs a project ID,
    // Monetag needs its SDK script URL + a zone ID, Adsterra needs a
    // placement key. The per-day cap check happens separately
    // (server-side, in ad_view).
    $configured = $provider === 'placeholder'
        || ($provider === 'adsgram' && $blockId !== '')
        || ($provider === 'gigapub' && $gigaProject !== '')
        || ($provider === 'monetag' && $monetagSdk !== '' && $monetagZone !== '')
        || ($provider === 'adsterra' && $adsterraKey !== '');
    return [
        'provider' => $provider,
        'block_id' => $blockId,
        'giga_project_id' => $gigaProject,
        'monetag_zone' => $monetagZone,
        'monetag_sdk_src' => $monetagSdk,
        'adsterra_key' => $adsterraKey,
        'adsterra_format' => $adsterraFormat,
        'adsterra_invoke_domain' => $adsterraDomain,
        'reward' => $reward,
        'cap' => $cap,
        'configured' => $configured,
    ];
}

// ---- Ledger: every orbit movement goes through here ----
function orbit_credit(PDO $pdo, int $userId, string $source, int $amount, string $ref = ''): void
{
    if ($amount === 0) {
        return;
    }
    $pdo->beginTransaction();
    try {
        $stmt = $pdo->prepare('UPDATE users SET balance_orbit = balance_orbit + ? WHERE id = ?');
        $stmt->execute([$amount, $userId]);
        $led = $pdo->prepare('INSERT INTO ledger (user_id, source, amount_orbit, ref, ledger_date) VALUES (?, ?, ?, ?, ?)');
        $led->execute([$userId, $source, $amount, $ref, orbit_today()]);
        $pdo->commit();
    } catch (Throwable $e) {
        $pdo->rollBack();
        throw $e;
    }
    // 5% lifetime commission to the referrer on real earnings (no recursion).
    if ($amount > 0 && in_array($source, ['game', 'ad', 'task', 'spin'], true)) {
        orbit_pay_commission($pdo, $userId, $amount);
    }
}

function orbit_pay_commission(PDO $pdo, int $userId, int $earned): void
{
    $stmt = $pdo->prepare('SELECT referred_by FROM users WHERE id = ?');
    $stmt->execute([$userId]);
    $row = $stmt->fetch();
    $referrer = $row === false ? 0 : (int)($row['referred_by'] ?? 0);
    if ($referrer <= 0) {
        return;
    }
    $pct = orbit_setting_int($pdo, 'ref_commission_pct', 5);
    $commission = intdiv($earned * $pct, 100);
    if ($commission <= 0) {
        return;
    }
    // Direct credit without triggering another commission level.
    $pdo->beginTransaction();
    try {
        $stmt = $pdo->prepare('UPDATE users SET balance_orbit = balance_orbit + ? WHERE id = ?');
        $stmt->execute([$commission, $referrer]);
        $led = $pdo->prepare("INSERT INTO ledger (user_id, source, amount_orbit, ref, ledger_date) VALUES (?, 'ref_commission', ?, ?, ?)");
        $led->execute([$referrer, $commission, 'from_user:' . $userId, orbit_today()]);
        $pdo->commit();
    } catch (Throwable $e) {
        $pdo->rollBack();
        throw $e;
    }
}

// ---- Quick Safety Check (anti-bot) ----
// True when the user must still pass the safety check before earning.
function orbit_safety_required(array $user): bool
{
    return (int)($user['safety_passed'] ?? 0) !== 1;
}

// ---- Referral staged bonuses ----
// Stage 1 (join): paid when the friend joins via the link.
// Stage 2 (active): paid when the friend plays & claims 2+ games.
// Stage 3 (withdraw): paid on the friend's first withdrawal request.
// Total = join + active + withdraw (default 200 + 500 + 300 = 1,000).
function orbit_pay_referral_stage(PDO $pdo, int $referralId, string $stage): void
{
    $col = ['join' => 'join_paid', 'active' => 'active_paid', 'withdraw' => 'withdraw_paid'][$stage] ?? null;
    $setting = ['join' => 'ref_bonus_join', 'active' => 'ref_bonus_active', 'withdraw' => 'ref_bonus_withdraw'][$stage] ?? null;
    if ($col === null || $setting === null) {
        return;
    }
    $stmt = $pdo->prepare("SELECT referrer_id, {$col} AS paid FROM referrals WHERE id = ?");
    $stmt->execute([$referralId]);
    $row = $stmt->fetch();
    if ($row === false || (int)$row['paid'] === 1) {
        return;
    }
    $bonus = orbit_setting_int($pdo, $setting, 0);
    if ($bonus > 0) {
        orbit_credit($pdo, (int)$row['referrer_id'], 'ref_bonus_' . $stage, $bonus, 'referred_stage:' . $stage . ':' . $referralId);
    }
    $pdo->prepare("UPDATE referrals SET {$col} = 1 WHERE id = ?")->execute([$referralId]);
}

// Withdraw requires the user to have at least one ACTIVE friend
// (a referral whose stage-2 bonus was paid).
function orbit_has_active_friend(PDO $pdo, int $userId): bool
{
    $stmt = $pdo->prepare('SELECT COUNT(*) AS c FROM referrals WHERE referrer_id = ? AND active_paid = 1');
    $stmt->execute([$userId]);
    return (int)($stmt->fetch()['c'] ?? 0) > 0;
}

// Referral stage 3 (withdraw): paid on the referred friend's first
// withdrawal request. Shared by api.php and bot.php.
function orbit_maybe_pay_referral_withdraw(PDO $pdo, int $userId): void
{
    $stmt = $pdo->prepare('SELECT id FROM referrals WHERE referred_id = ?');
    $stmt->execute([$userId]);
    $ref = $stmt->fetch();
    if ($ref === false) {
        return;
    }
    orbit_pay_referral_stage($pdo, (int)$ref['id'], 'withdraw');
}

// ---- Bot API helper (used by bot.php and admin notifications) ----
function orbit_tg_send(string $token, string $method, array $params): array
{
    $ch = curl_init('https://api.telegram.org/bot' . $token . '/' . $method);
    curl_setopt_array($ch, [
        CURLOPT_RETURNTRANSFER => true,
        CURLOPT_POST => true,
        CURLOPT_POSTFIELDS => json_encode($params),
        CURLOPT_HTTPHEADER => ['Content-Type: application/json'],
        CURLOPT_TIMEOUT => 20,
    ]);
    $body = curl_exec($ch);
    $code = (int)curl_getinfo($ch, CURLINFO_RESPONSE_CODE);
    curl_close($ch);
    $data = $body === false ? null : json_decode((string)$body, true);
    return ['code' => $code, 'data' => $data];
}

function orbit_tg_get_chat_member(string $token, string $channelUsername, int $userId): string
{
    $url = 'https://api.telegram.org/bot' . $token . '/getChatMember?chat_id=@'
        . rawurlencode($channelUsername) . '&user_id=' . $userId;
    $body = @file_get_contents($url);
    $data = $body === false ? null : json_decode($body, true);
    if (!is_array($data) || ($data['ok'] ?? false) !== true) {
        return '';
    }
    return (string)($data['result']['status'] ?? '');
}
