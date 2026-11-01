<?php
// Orbit Earn Bot v1 — single API router for the Mini App.
// Every earning is verified server-side. The client is never trusted.
// Gates (hard, in order):
//  1. Telegram initData must verify with BOT_TOKEN (HMAC-SHA256), else 401.
//     If BOT_TOKEN is empty, auth FAILS CLOSED — nothing works unconfigured.
//  2. Banned users get 403 on everything.
//  3. Per-game daily runs (6/day, orbit day starts 06:00 Asia/Dhaka).
//  4. Per-ad-unit daily caps + a global ad cooldown.
//  5. One lucky-wheel spin per orbit day.
// All amounts are integer orbit. Money moves only through orbit_credit().
declare(strict_types=1);
require __DIR__ . '/config.php';

header('Access-Control-Allow-Origin: *');
header('Access-Control-Allow-Headers: Content-Type');
header('Access-Control-Allow-Methods: POST, OPTIONS');
if (($_SERVER['REQUEST_METHOD'] ?? '') === 'OPTIONS') {
    exit;
}

function orbit_fail(string $msg, int $code = 400): void
{
    http_response_code($code);
    header('Content-Type: application/json');
    echo json_encode(['ok' => false, 'error' => $msg]);
    exit;
}

function orbit_ok(array $data = []): void
{
    header('Content-Type: application/json');
    echo json_encode(array_merge(['ok' => true], $data));
    exit;
}

// ---------- Telegram WebApp initData verification (fail closed) ----------
function orbit_verify_init_data(string $initData, string $botToken): ?array
{
    if ($botToken === '' || $initData === '') {
        return null;
    }
    parse_str($initData, $params);
    if (!isset($params['hash']) || !isset($params['user'])) {
        return null;
    }
    $hash = (string)$params['hash'];
    unset($params['hash']);
    ksort($params);
    $pairs = [];
    foreach ($params as $k => $v) {
        $pairs[] = $k . '=' . $v;
    }
    $checkString = implode("\n", $pairs);
    $secret = hash_hmac('sha256', $botToken, 'WebAppData', true);
    $calc = hash_hmac('sha256', $checkString, $secret);
    if (!hash_equals($calc, $hash)) {
        return null;
    }
    $user = json_decode((string)$params['user'], true);
    if (!is_array($user) || !isset($user['id'])) {
        return null;
    }
    return $user;
}

$env = orbit_env();
$input = json_decode((string)file_get_contents('php://input'), true);
if (!is_array($input)) {
    $input = [];
}
$action = (string)($input['action'] ?? '');
$initData = (string)($input['initData'] ?? '');

// ---------- Auth: verify, upsert user, attribute referral ----------
function orbit_auth_user(PDO $pdo, array $env, string $initData): array
{
    $tg = orbit_verify_init_data($initData, (string)($env['BOT_TOKEN'] ?? ''));
    if ($tg === null) {
        orbit_fail('telegram_auth_failed: initData could not be verified. The bot token must be configured; unsigned data is never accepted.', 401);
    }
    $telegramId = (int)$tg['id'];
    $username = isset($tg['username']) ? (string)$tg['username'] : null;
    $firstName = isset($tg['first_name']) ? (string)$tg['first_name'] : null;

    $stmt = $pdo->prepare('SELECT * FROM users WHERE telegram_id = ?');
    $stmt->execute([$telegramId]);
    $user = $stmt->fetch();
    if ($user === false) {
        $ins = $pdo->prepare('INSERT INTO users (telegram_id, username, first_name, chat_id) VALUES (?, ?, ?, ?)');
        $ins->execute([$telegramId, $username, $firstName, $telegramId]);
        $stmt->execute([$telegramId]);
        $user = $stmt->fetch();
    } else {
        $upd = $pdo->prepare('UPDATE users SET username = ?, first_name = ? WHERE id = ?');
        $upd->execute([$username, $firstName, (int)$user['id']]);
        $user['username'] = $username;
        $user['first_name'] = $firstName;
    }

    // Referral attribution via Mini App start_param (ref_<telegram_id>).
    if (empty($user['referred_by'])) {
        parse_str($initData, $p);
        $startParam = (string)($p['start_param'] ?? '');
        if (preg_match('/^ref_(\d+)$/', $startParam, $m) === 1) {
            $refTelegramId = (int)$m[1];
            if ($refTelegramId !== $telegramId && $refTelegramId > 0) {
                $rst = $pdo->prepare('SELECT id FROM users WHERE telegram_id = ?');
                $rst->execute([$refTelegramId]);
                $refRow = $rst->fetch();
                if ($refRow !== false) {
                    $pdo->beginTransaction();
                    try {
                        $pdo->prepare('UPDATE users SET referred_by = ? WHERE id = ? AND referred_by IS NULL')
                            ->execute([(int)$refRow['id'], (int)$user['id']]);
                        $pdo->prepare('INSERT IGNORE INTO referrals (referrer_id, referred_id) VALUES (?, ?)')
                            ->execute([(int)$refRow['id'], (int)$user['id']]);
                        $pdo->commit();
                        $user['referred_by'] = (int)$refRow['id'];
                    } catch (Throwable $e) {
                        $pdo->rollBack();
                    }
                    // Stage 1 of the referral bonus: paid the moment the friend joins.
                    $rid = $pdo->prepare('SELECT id FROM referrals WHERE referred_id = ?');
                    $rid->execute([(int)$user['id']]);
                    $rrow = $rid->fetch();
                    if ($rrow !== false) {
                        orbit_pay_referral_stage($pdo, (int)$rrow['id'], 'join');
                    }
                }
            }
        }
    }
    return $user;
}

function orbit_require_active(array $user): void
{
    if ((int)($user['is_banned'] ?? 0) === 1) {
        orbit_fail('account_banned: this account has been suspended. Contact support.', 403);
    }
}

// ---------- Shared earning helpers ----------
function orbit_game_runs_today(PDO $pdo, int $userId, string $game): int
{
    $stmt = $pdo->prepare('SELECT COUNT(*) AS c FROM game_runs WHERE user_id = ? AND game = ? AND run_date = ?');
    $stmt->execute([$userId, $game, orbit_today()]);
    return (int)($stmt->fetch()['c'] ?? 0);
}

function orbit_ad_views_today(PDO $pdo, int $userId, string $unit): int
{
    $stmt = $pdo->prepare('SELECT COUNT(*) AS c FROM ad_views WHERE user_id = ? AND unit = ? AND view_date = ?');
    $stmt->execute([$userId, $unit, orbit_today()]);
    return (int)($stmt->fetch()['c'] ?? 0);
}

function orbit_total_game_runs(PDO $pdo, int $userId): int
{
    $stmt = $pdo->prepare('SELECT COUNT(*) AS c FROM game_runs WHERE user_id = ?');
    $stmt->execute([$userId]);
    return (int)($stmt->fetch()['c'] ?? 0);
}

function orbit_total_ad_views(PDO $pdo, int $userId): int
{
    $stmt = $pdo->prepare('SELECT COUNT(*) AS c FROM ad_views WHERE user_id = ?');
    $stmt->execute([$userId]);
    return (int)($stmt->fetch()['c'] ?? 0);
}

// Referral stage 2 (active): paid once the referred friend has played
// and claimed at least 2 games (verified server-side from real rows).
function orbit_maybe_pay_referral_active(PDO $pdo, int $userId): void
{
    $stmt = $pdo->prepare('SELECT id FROM referrals WHERE referred_id = ?');
    $stmt->execute([$userId]);
    $ref = $stmt->fetch();
    if ($ref === false) {
        return;
    }
    if (orbit_total_game_runs($pdo, $userId) >= 2) {
        orbit_pay_referral_stage($pdo, (int)$ref['id'], 'active');
    }
}

function orbit_require_safety(array $user): void
{
    if (orbit_safety_required($user)) {
        orbit_fail('safety_required: complete the Quick Safety Check before earning.', 403);
    }
}

// Game reward: 100–200 orbit by game and score.
function orbit_game_reward(string $game, int $score): int
{
    switch ($game) {
        case 'blaster':
            return 100 + min($score * 2, 100);
        case 'startap':
            return 100 + min($score * 3, 100);
        case 'tictactoe':
            // score: 2 = win, 1 = draw, 0 = loss
            return $score === 2 ? 200 : ($score === 1 ? 150 : 100);
        case 'words':
            return min(100 + $score * 35, 200);
        default:
            return 100;
    }
}

// ---------- Router ----------
try {
    $pdo = orbit_db();
} catch (Throwable $e) {
    orbit_fail('database_unavailable', 503);
}

if ($action === 'auth' || $action === 'summary' || $action === 'me') {
    $user = orbit_auth_user($pdo, $env, $initData);
    orbit_require_active($user);
    $rate = orbit_setting_int($pdo, 'rate_orbit_per_usd', 100000);
    $balance = (int)$user['balance_orbit'];
    $rst = $pdo->prepare('SELECT COUNT(*) AS c FROM referrals WHERE referrer_id = ?');
    $rst->execute([(int)$user['id']]);
    $refCount = (int)($rst->fetch()['c'] ?? 0);
    $games = orbit_games();
    $runsLeft = [];
    foreach ($games as $g => $meta) {
        $runsLeft[$g] = max(0, orbit_setting_int($pdo, 'game_runs_per_day', 6) - orbit_game_runs_today($pdo, (int)$user['id'], $g));
    }
    $wst = $pdo->prepare("SELECT COUNT(*) AS c FROM withdrawals WHERE user_id = ? AND status = 'pending'");
    $wst->execute([(int)$user['id']]);
    $ads = [];
    foreach (['ad1', 'ad2', 'ad3', 'ad4'] as $u) {
        // Per-unit ad provider config (Adsgram / Giga.pub / Monetag / Adsterra / placeholder).
        // The client shows the real SDK ad only when the unit is configured
        // for its provider; the server-side ad_view still enforces the
        // daily cap and cooldown before any credit. block_id is a public
        // publisher identifier by design (it ships in client code).
        $cfg = orbit_ad_unit_config($pdo, $u);
        $used = orbit_ad_views_today($pdo, (int)$user['id'], $u);
        $ads[$u] = [
            'reward' => $cfg['reward'],
            'cap' => $cfg['cap'],
            'used' => $used,
            'left' => max(0, $cfg['cap'] - $used),
            'provider' => $cfg['provider'],
            'block_id' => $cfg['block_id'],
            'giga_project_id' => $cfg['giga_project_id'],
            'monetag_zone' => $cfg['monetag_zone'],
            'monetag_sdk_src' => $cfg['monetag_sdk_src'],
            'adsterra_key' => $cfg['adsterra_key'],
            'adsterra_format' => $cfg['adsterra_format'],
            'adsterra_invoke_domain' => $cfg['adsterra_invoke_domain'],
            'available' => $cfg['configured'] && $used < $cfg['cap'],
        ];
    }
    orbit_ok([
        'user' => [
            'telegram_id' => (int)$user['telegram_id'],
            'username' => $user['username'],
            'first_name' => $user['first_name'],
            'balance_orbit' => $balance,
            'balance_usd' => orbit_to_usd($balance, $rate),
            'referral_count' => $refCount,
            'runs_left' => $runsLeft,
            'pending_withdrawals' => (int)($wst->fetch()['c'] ?? 0),
            'safety_passed' => !orbit_safety_required($user),
        ],
        'rate_orbit_per_usd' => $rate,
        'min_withdraw' => orbit_setting_int($pdo, 'min_withdraw', 2000),
        'spin_done_today' => orbit_spin_done($pdo, (int)$user['id']),
        'ads' => $ads,
        'gateways' => orbit_gateways(),
    ]);
}

if ($action === 'game_submit') {
    $user = orbit_auth_user($pdo, $env, $initData);
    orbit_require_active($user);
    orbit_require_safety($user);
    $userId = (int)$user['id'];
    $game = (string)($input['game'] ?? '');
    $games = orbit_games();
    if (!isset($games[$game])) {
        orbit_fail('unknown_game');
    }
    $meta = $games[$game];
    $score = (int)($input['score'] ?? 0);
    $taps = (int)($input['taps'] ?? 0);
    $durationMs = (int)($input['duration_ms'] ?? 0);
    $nonce = substr((string)($input['nonce'] ?? ''), 0, 64);

    // ---- Anti-cheat ----
    if ($nonce === '') {
        orbit_fail('bad_nonce: submission nonce is required.', 422);
    }
    if ($score < 0 || $score > $meta['max_score']) {
        orbit_fail('impossible_score: score is outside the possible range for this game.', 422);
    }
    if ($durationMs < $meta['min_ms'] || $durationMs > $meta['max_ms']) {
        orbit_fail('impossible_duration: run duration is outside the possible range.', 422);
    }
    // Tap-rate cheat: no human taps faster than ~12/sec sustained.
    if ($taps > 0 && $durationMs > 0 && ($taps / ($durationMs / 1000)) > 12) {
        orbit_fail('impossible_tap_rate: tap rate exceeds human limits.', 422);
    }
    $runsPerDay = orbit_setting_int($pdo, 'game_runs_per_day', 6);
    $runNo = orbit_game_runs_today($pdo, $userId, $game) + 1;
    if ($runNo > $runsPerDay) {
        orbit_fail('daily_runs_reached: no paid runs left for this game today.', 403);
    }
    try {
        $stmt = $pdo->prepare('INSERT INTO game_runs (user_id, game, run_date, run_no, nonce, score, taps, duration_ms, reward_orbit) VALUES (?, ?, ?, ?, ?, ?, ?, ?, ?)');
        $reward = orbit_game_reward($game, $score);
        $stmt->execute([$userId, $game, orbit_today(), $runNo, $nonce, $score, $taps, $durationMs, $reward]);
    } catch (PDOException $e) {
        // Duplicate nonce or run_no = replay / double submit.
        orbit_fail('duplicate_submission: this run was already recorded.', 409);
    }
    orbit_credit($pdo, $userId, 'game', $reward, $game . ':' . $runNo);
    orbit_maybe_pay_referral_active($pdo, $userId);
    orbit_ok(['reward_orbit' => $reward, 'runs_left' => $runsPerDay - $runNo]);
}

if ($action === 'ad_view') {
    $user = orbit_auth_user($pdo, $env, $initData);
    orbit_require_active($user);
    orbit_require_safety($user);
    $userId = (int)$user['id'];
    $unit = (string)($input['unit'] ?? '');
    if (!in_array($unit, ['ad1', 'ad2', 'ad3', 'ad4'], true)) {
        orbit_fail('unknown_ad_unit');
    }
    $cap = orbit_setting_int($pdo, $unit . '_cap', 5);
    $reward = orbit_setting_int($pdo, $unit . '_reward', 50);
    $viewNo = orbit_ad_views_today($pdo, $userId, $unit) + 1;
    if ($viewNo > $cap) {
        orbit_fail('ad_cap_reached: daily limit for this ad unit is reached.', 403);
    }
    // Cooldown between credited views (server-side view verification).
    // NOTE: compared on the DB clock (NOW()) so PHP/DB timezone differences
    // can never shrink or skip the cooldown.
    $cooldown = orbit_setting_int($pdo, 'ad_cooldown_sec', 45);
    $stmt = $pdo->prepare('SELECT COUNT(*) FROM ad_views WHERE user_id = ? AND created_at > (NOW() - INTERVAL ? SECOND)');
    $stmt->execute([$userId, $cooldown]);
    if ((int)$stmt->fetchColumn() > 0) {
        orbit_fail('ad_cooldown: please wait before the next ad view is credited.', 429);
    }
    $stmt = $pdo->prepare('INSERT INTO ad_views (user_id, unit, view_date, view_no, reward_orbit) VALUES (?, ?, ?, ?, ?)');
    $stmt->execute([$userId, $unit, orbit_today(), $viewNo, $reward]);
    orbit_credit($pdo, $userId, 'ad', $reward, $unit . ':' . $viewNo);
    orbit_maybe_pay_referral_active($pdo, $userId);
    orbit_ok(['reward_orbit' => $reward, 'views_left' => $cap - $viewNo]);
}

if ($action === 'tasks_list') {
    $user = orbit_auth_user($pdo, $env, $initData);
    orbit_require_active($user);
    $stmt = $pdo->prepare("SELECT t.id, t.title, t.type, t.channel_username, t.target_url, t.reward_orbit, (ut.id IS NOT NULL) AS done FROM tasks t LEFT JOIN user_tasks ut ON ut.task_id = t.id AND ut.user_id = ? WHERE t.is_active = 1 ORDER BY t.sort, t.id");
    $stmt->execute([(int)$user['id']]);
    orbit_ok(['tasks' => $stmt->fetchAll()]);
}

if ($action === 'task_claim') {
    $user = orbit_auth_user($pdo, $env, $initData);
    orbit_require_active($user);
    orbit_require_safety($user);
    $userId = (int)$user['id'];
    $taskId = (int)($input['task_id'] ?? 0);
    $stmt = $pdo->prepare('SELECT * FROM tasks WHERE id = ? AND is_active = 1');
    $stmt->execute([$taskId]);
    $task = $stmt->fetch();
    if ($task === false) {
        orbit_fail('task_not_found');
    }
    $done = $pdo->prepare('SELECT id FROM user_tasks WHERE user_id = ? AND task_id = ?');
    $done->execute([$userId, $taskId]);
    if ($done->fetch() !== false) {
        orbit_fail('task_already_done');
    }
    if ($task['type'] === 'telegram') {
        $channel = (string)($task['channel_username'] ?? '');
        if ($channel === '') {
            orbit_fail('task_not_configured: this task has no channel set yet.', 503);
        }
        $token = (string)($env['BOT_TOKEN'] ?? '');
        if ($token === '') {
            orbit_fail('bot_not_configured', 503);
        }
        $status = orbit_tg_get_chat_member($token, ltrim($channel, '@'), (int)$user['telegram_id']);
        if (!in_array($status, ['creator', 'administrator', 'member'], true)) {
            orbit_fail('channel_join_required: join the channel first, then claim.', 403);
        }
    }
    // type=manual: admin-marked completable; the claim itself is the proof.
    $pdo->prepare("INSERT INTO user_tasks (user_id, task_id, status) VALUES (?, ?, 'done')")->execute([$userId, $taskId]);
    $reward = (int)$task['reward_orbit'];
    orbit_credit($pdo, $userId, 'task', $reward, 'task:' . $taskId);
    orbit_ok(['reward_orbit' => $reward]);
}

if ($action === 'referral_info') {
    $user = orbit_auth_user($pdo, $env, $initData);
    orbit_require_active($user);
    $userId = (int)$user['id'];
    $botUsername = (string)($env['BOT_USERNAME'] ?? '');
    $link = $botUsername !== '' ? 'https://t.me/' . $botUsername . '?startapp=ref_' . (int)$user['telegram_id'] : '';
    $stmt = $pdo->prepare('SELECT COUNT(*) AS c, COALESCE(SUM(join_paid),0) AS j, COALESCE(SUM(active_paid),0) AS a, COALESCE(SUM(withdraw_paid),0) AS w FROM referrals WHERE referrer_id = ?');
    $stmt->execute([$userId]);
    $row = $stmt->fetch();
    $est = $pdo->prepare("SELECT COALESCE(SUM(amount_orbit),0) AS s FROM ledger WHERE user_id = ? AND source = 'ref_commission' AND ledger_date = ?");
    $est->execute([$userId, orbit_today()]);
    orbit_ok([
        'link' => $link,
        'friends' => (int)($row['c'] ?? 0),
        'stages' => [
            'join' => ['paid' => (int)($row['j'] ?? 0), 'bonus_orbit' => orbit_setting_int($pdo, 'ref_bonus_join', 200), 'label' => 'friend joined'],
            'active' => ['paid' => (int)($row['a'] ?? 0), 'bonus_orbit' => orbit_setting_int($pdo, 'ref_bonus_active', 500), 'label' => 'friend played 2 games'],
            'withdraw' => ['paid' => (int)($row['w'] ?? 0), 'bonus_orbit' => orbit_setting_int($pdo, 'ref_bonus_withdraw', 300), 'label' => 'friend\'s first withdrawal'],
        ],
        'commission_pct' => orbit_setting_int($pdo, 'ref_commission_pct', 5),
        'commission_today' => (int)($est->fetch()['s'] ?? 0),
    ]);
}

if ($action === 'spin') {
    $user = orbit_auth_user($pdo, $env, $initData);
    orbit_require_active($user);
    orbit_require_safety($user);
    $userId = (int)$user['id'];
    if (orbit_spin_done($pdo, $userId)) {
        orbit_fail('spin_used: the free daily spin is already used. Come back tomorrow.', 403);
    }
    $prizes = array_map('intval', explode(',', orbit_setting($pdo, 'spin_prizes', '50,75,100,150,250')));
    $weights = array_map('intval', explode(',', orbit_setting($pdo, 'spin_weights', '40,30,15,10,5')));
    $total = array_sum($weights);
    $roll = random_int(1, max(1, $total));
    $acc = 0;
    $prize = $prizes[0];
    foreach ($prizes as $i => $p) {
        $acc += $weights[$i] ?? 0;
        if ($roll <= $acc) {
            $prize = $p;
            break;
        }
    }
    $pdo->prepare('INSERT INTO spins (user_id, spin_date, prize_orbit) VALUES (?, ?, ?)')
        ->execute([$userId, orbit_today(), $prize]);
    orbit_credit($pdo, $userId, 'spin', $prize, 'daily');
    orbit_ok(['prize_orbit' => $prize]);
}

// ---------- Quick Safety Check (anti-bot) ----------
// safety_status: does this user still need to pass the check?
if ($action === 'safety_status') {
    $user = orbit_auth_user($pdo, $env, $initData);
    orbit_require_active($user);
    orbit_ok([
        'passed' => !orbit_safety_required($user),
        'bonus_orbit' => orbit_setting_int($pdo, 'safety_bonus', 25),
    ]);
}

// safety_send: generate a 3-digit code and send it to the user's bot chat.
if ($action === 'safety_send') {
    $user = orbit_auth_user($pdo, $env, $initData);
    orbit_require_active($user);
    if (!orbit_safety_required($user)) {
        orbit_ok(['already_passed' => true]);
    }
    $token = (string)($env['BOT_TOKEN'] ?? '');
    if ($token === '') {
        orbit_fail('bot_not_configured', 503);
    }
    $code = (string)random_int(100, 999);
    $pdo->prepare('UPDATE users SET safety_code = ?, safety_code_expires = (NOW() + INTERVAL 10 MINUTE) WHERE id = ?')
        ->execute([$code, (int)$user['id']]);
    $res = orbit_tg_send($token, 'sendMessage', [
        'chat_id' => (int)$user['telegram_id'],
        'text' => "🛡️ *Quick Safety Check*\n\nYour verification code is: `" . $code . "`\n\nEnter it in the app within 10 minutes to prove you are human and earn +" . orbit_setting_int($pdo, 'safety_bonus', 25) . " orbit.",
        'parse_mode' => 'Markdown',
    ]);
    if (($res['code'] ?? 0) !== 200) {
        orbit_fail('code_send_failed: could not message your Telegram chat. Open the bot chat first and try again.', 502);
    }
    orbit_ok(['sent' => true]);
}

// safety_verify: check the code; on success mark human and grant the bonus once.
if ($action === 'safety_verify') {
    $user = orbit_auth_user($pdo, $env, $initData);
    orbit_require_active($user);
    if (!orbit_safety_required($user)) {
        orbit_ok(['already_passed' => true]);
    }
    $code = trim((string)($input['code'] ?? ''));
    // Expiry is compared on the DB clock (NOW()) so PHP/DB timezone
    // differences can never accept an expired code or reject a fresh one.
    $stmt = $pdo->prepare('SELECT safety_code FROM users WHERE id = ? AND safety_code_expires > NOW()');
    $stmt->execute([(int)$user['id']]);
    $row = $stmt->fetch();
    $valid = $row !== false && $row['safety_code'] !== null && $row['safety_code'] !== ''
        && hash_equals((string)$row['safety_code'], $code);
    if (!$valid) {
        orbit_fail('bad_code: wrong or expired code. Request a new one.', 422);
    }
    $bonus = orbit_setting_int($pdo, 'safety_bonus', 25);
    $pdo->prepare('UPDATE users SET safety_passed = 1, safety_code = NULL, safety_code_expires = NULL WHERE id = ?')
        ->execute([(int)$user['id']]);
    orbit_credit($pdo, (int)$user['id'], 'safety', $bonus, 'quick_safety_check');
    orbit_ok(['passed' => true, 'bonus_orbit' => $bonus]);
}

if ($action === 'withdraw_request') {
    $user = orbit_auth_user($pdo, $env, $initData);
    orbit_require_active($user);
    orbit_require_safety($user);
    $userId = (int)$user['id'];
    // Withdrawals require at least one ACTIVE friend (anti-abuse).
    if (!orbit_has_active_friend($pdo, $userId)) {
        orbit_fail('withdraw_requires_active_friend: invite a friend and help them play 2 games before you can withdraw.', 403);
    }
    $gateway = (string)($input['gateway'] ?? '');
    $gateways = orbit_gateways();
    if (!isset($gateways[$gateway])) {
        orbit_fail('unknown_gateway');
    }
    $address = trim((string)($input['address'] ?? ''));
    if (strlen($address) < 5 || strlen($address) > 255) {
        orbit_fail('bad_address: enter your receiving address for the selected gateway.', 422);
    }
    $amount = (int)($input['amount'] ?? 0);
    $min = orbit_setting_int($pdo, 'min_withdraw', 2000);
    if ($amount < $min) {
        orbit_fail('below_minimum: minimum withdrawal is ' . $min . ' orbit.', 422);
    }
    $balance = (int)$user['balance_orbit'];
    if ($amount > $balance) {
        orbit_fail('insufficient_balance', 422);
    }
    $fee = orbit_gateway_fee($pdo, $gateway);
    $net = $amount - $fee;
    if ($net <= 0) {
        orbit_fail('fee_exceeds_amount', 422);
    }
    $code = trim((string)($input['code'] ?? ''));
    $token = (string)($env['BOT_TOKEN'] ?? '');

    // Step 2: the user entered the one-time code the bot sent to their chat.
    if ($code !== '') {
        // Compared on the DB clock; the row must match this exact request.
        $stmt = $pdo->prepare('SELECT gateway, address, amount_orbit, code FROM withdraw_otps WHERE user_id = ? AND expires_at > NOW()');
        $stmt->execute([$userId]);
        $otp = $stmt->fetch();
        if ($otp === false || !hash_equals((string)$otp['code'], $code)
            || $otp['gateway'] !== $gateway || (int)$otp['amount_orbit'] !== $amount
            || $otp['address'] !== $address) {
            orbit_fail('bad_code: wrong or expired confirmation code. Start the withdrawal again.', 422);
        }
        $pdo->prepare('DELETE FROM withdraw_otps WHERE user_id = ?')->execute([$userId]);
        // Balance could have changed since the code was sent; re-check.
        $bst = $pdo->prepare('SELECT balance_orbit FROM users WHERE id = ?');
        $bst->execute([$userId]);
        if ((int)($bst->fetch()['balance_orbit'] ?? 0) < $amount) {
            orbit_fail('insufficient_balance', 422);
        }
        // Deduct immediately; refunded automatically if the admin rejects.
        orbit_credit($pdo, $userId, 'withdraw', -$amount, $gateway);
        $stmt = $pdo->prepare("INSERT INTO withdrawals (user_id, chat_id, gateway, address, amount_orbit, fee_orbit, net_orbit, status) VALUES (?, ?, ?, ?, ?, ?, ?, 'pending')");
        $stmt->execute([$userId, (int)$user['chat_id'], $gateway, $address, $amount, $fee, $net]);
        $wdId = (int)$pdo->lastInsertId();

        // Referral stage 3: paid on this user's first-ever withdrawal request.
        $cnt = $pdo->prepare('SELECT COUNT(*) AS c FROM withdrawals WHERE user_id = ?');
        $cnt->execute([$userId]);
        if ((int)($cnt->fetch()['c'] ?? 0) === 1) {
            orbit_maybe_pay_referral_withdraw($pdo, $userId);
        }

        // Notify the owner (if configured) about the new pending request.
        $adminTg = (string)($env['ADMIN_TELEGRAM_ID'] ?? '');
        if ($adminTg !== '' && $token !== '') {            $rate = orbit_setting_int($pdo, 'rate_orbit_per_usd', 100000);
            orbit_tg_send($token, 'sendMessage', [
                'chat_id' => $adminTg,
                'text' => "🆕 New withdrawal request #$wdId\n"
                    . "User: " . (int)$user['telegram_id'] . " (@" . ($user['username'] ?? '-') . ")\n"
                    . "Amount: " . number_format($amount) . " orbit ($" . orbit_to_usd($amount, $rate) . ")\n"
                    . "Gateway: " . $gateways[$gateway] . "\n"
                    . "Review it in the admin panel.",
            ]);
        }
        orbit_ok(['withdrawal_id' => $wdId, 'net_orbit' => $net, 'fee_orbit' => $fee]);
    }

    // Step 1: everything validates, so send a one-time code to the user's
    // bot chat. The request is created only after the code is confirmed.
    if ($token === '') {
        orbit_fail('bot_not_configured', 503);
    }
    $otpCode = (string)random_int(100000, 999999);
    $pdo->prepare('INSERT INTO withdraw_otps (user_id, gateway, address, amount_orbit, code, expires_at) VALUES (?, ?, ?, ?, ?, NOW() + INTERVAL 10 MINUTE) ON DUPLICATE KEY UPDATE gateway = VALUES(gateway), address = VALUES(address), amount_orbit = VALUES(amount_orbit), code = VALUES(code), expires_at = VALUES(expires_at)')
        ->execute([$userId, $gateway, $address, $amount, $otpCode]);
    $res = orbit_tg_send($token, 'sendMessage', [
        'chat_id' => (int)$user['telegram_id'],
        'text' => "💸 *Withdrawal confirmation*\n\n"
            . "Amount: " . number_format($amount) . " orbit\n"
            . "Gateway: " . $gateways[$gateway] . "\n"
            . "To: `" . $address . "`\n\n"
            . "Your confirmation code is: `" . $otpCode . "`\n\n"
            . "Enter it in the app within 10 minutes to submit the request.",
        'parse_mode' => 'Markdown',
    ]);
    if (($res['code'] ?? 0) !== 200) {
        $pdo->prepare('DELETE FROM withdraw_otps WHERE user_id = ?')->execute([$userId]);
        orbit_fail('code_send_failed: could not message your Telegram chat. Open the bot chat first and try again.', 502);
    }
    orbit_ok(['otp_sent' => true]);
}

if ($action === 'withdraw_history') {
    $user = orbit_auth_user($pdo, $env, $initData);
    orbit_require_active($user);
    $stmt = $pdo->prepare('SELECT id, gateway, amount_orbit, fee_orbit, net_orbit, status, reject_reason, proof_tx, created_at, decided_at FROM withdrawals WHERE user_id = ? ORDER BY id DESC LIMIT 20');
    $stmt->execute([(int)$user['id']]);
    orbit_ok(['withdrawals' => $stmt->fetchAll()]);
}

orbit_fail('unknown_action', 404);

// ---------- small helpers used above ----------
function orbit_spin_done(PDO $pdo, int $userId): bool
{
    $stmt = $pdo->prepare('SELECT id FROM spins WHERE user_id = ? AND spin_date = ?');
    $stmt->execute([$userId, orbit_today()]);
    return $stmt->fetch() !== false;
}
