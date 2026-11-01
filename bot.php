<?php
// Orbit Earn Bot v1 — Telegram bot webhook.
// Handles: /start (with ref_<telegram_id> attribution), reply keyboard
// (Balance | Referral | Withdraw), and a guided withdraw conversation:
// gateway -> receiving address -> confirm. Nothing is sent to the chat
// except through this file and the admin panel's approve/reject notifier.
// If BOT_TOKEN is empty the webhook does nothing (fail closed).
declare(strict_types=1);
require __DIR__ . '/config.php';

$env = orbit_env();
$token = (string)($env['BOT_TOKEN'] ?? '');
$webapp = (string)($env['WEBAPP_URL'] ?? '');
$botUsername = (string)($env['BOT_USERNAME'] ?? '');

if ($token === '') {
    http_response_code(200);
    echo 'bot not configured';
    exit;
}

$update = json_decode((string)file_get_contents('php://input'), true);
if (!is_array($update)) {
    http_response_code(200);
    echo 'ok';
    exit;
}

try {
    $pdo = orbit_db();
} catch (Throwable $e) {
    http_response_code(200);
    echo 'ok';
    exit;
}

$MAIN_KEYBOARD = [
    'keyboard' => [[['text' => '💰 Balance'], ['text' => '👥 Referral'], ['text' => '💸 Withdraw']]],
    'resize_keyboard' => true,
];

function orbit_ensure_user(PDO $pdo, int $telegramId, ?string $username, ?string $firstName, int $chatId): array
{
    $stmt = $pdo->prepare('SELECT * FROM users WHERE telegram_id = ?');
    $stmt->execute([$telegramId]);
    $user = $stmt->fetch();
    if ($user === false) {
        $ins = $pdo->prepare('INSERT INTO users (telegram_id, username, first_name, chat_id) VALUES (?, ?, ?, ?)');
        $ins->execute([$telegramId, $username, $firstName, $chatId]);
        $stmt->execute([$telegramId]);
        $user = $stmt->fetch();
    } else {
        $pdo->prepare('UPDATE users SET username = ?, first_name = ?, chat_id = ? WHERE id = ?')
            ->execute([$username, $firstName, $chatId, (int)$user['id']]);
        $user['username'] = $username;
        $user['first_name'] = $firstName;
        $user['chat_id'] = $chatId;
    }
    return $user;
}

function orbit_attribute_referral(PDO $pdo, array $user, int $refTelegramId): void
{
    if (!empty($user['referred_by']) || $refTelegramId <= 0 || $refTelegramId === (int)$user['telegram_id']) {
        return;
    }
    $stmt = $pdo->prepare('SELECT id FROM users WHERE telegram_id = ?');
    $stmt->execute([$refTelegramId]);
    $refRow = $stmt->fetch();
    if ($refRow === false) {
        return;
    }
    $pdo->beginTransaction();
    try {
        $pdo->prepare('UPDATE users SET referred_by = ? WHERE id = ? AND referred_by IS NULL')
            ->execute([(int)$refRow['id'], (int)$user['id']]);
        $pdo->prepare('INSERT IGNORE INTO referrals (referrer_id, referred_id) VALUES (?, ?)')
            ->execute([(int)$refRow['id'], (int)$user['id']]);
        $pdo->commit();
    } catch (Throwable $e) {
        $pdo->rollBack();
    }
    // Referral stage 1: paid the moment the friend joins via the link.
    $rid = $pdo->prepare('SELECT id FROM referrals WHERE referred_id = ?');
    $rid->execute([(int)$user['id']]);
    $rrow = $rid->fetch();
    if ($rrow !== false) {
        orbit_pay_referral_stage($pdo, (int)$rrow['id'], 'join');
    }
}

function orbit_conversation(PDO $pdo, int $userId): ?array
{
    $stmt = $pdo->prepare('SELECT * FROM conversations WHERE user_id = ?');
    $stmt->execute([$userId]);
    $row = $stmt->fetch();
    return $row === false ? null : $row;
}

function orbit_set_conversation(PDO $pdo, int $userId, string $step, array $data): void
{
    $pdo->prepare('INSERT INTO conversations (user_id, step, data_json) VALUES (?, ?, ?) ON DUPLICATE KEY UPDATE step = VALUES(step), data_json = VALUES(data_json)')
        ->execute([$userId, $step, json_encode($data)]);
}

function orbit_clear_conversation(PDO $pdo, int $userId): void
{
    $pdo->prepare('DELETE FROM conversations WHERE user_id = ?')->execute([$userId]);
}

function orbit_gw_keyboard(array $gateways): array
{
    $rows = [];
    foreach ($gateways as $label) {
        $rows[] = ['text' => $label];
    }
    return [$rows];
}

// ---------------- message handling ----------------
$msg = $update['message'] ?? null;
if (!is_array($msg)) {
    http_response_code(200);
    echo 'ok';
    exit;
}
$chatId = (int)($msg['chat']['id'] ?? 0);
$from = $msg['from'] ?? [];
$telegramId = (int)($from['id'] ?? 0);
$text = trim((string)($msg['text'] ?? ''));
if ($chatId <= 0 || $telegramId <= 0) {
    http_response_code(200);
    echo 'ok';
    exit;
}

$user = orbit_ensure_user($pdo, $telegramId, $from['username'] ?? null, $from['first_name'] ?? null, $chatId);
if ((int)($user['is_banned'] ?? 0) === 1) {
    orbit_tg_send($token, 'sendMessage', ['chat_id' => $chatId, 'text' => '⛔ This account has been suspended.']);
    http_response_code(200);
    echo 'ok';
    exit;
}
$userId = (int)$user['id'];
$rate = orbit_setting_int($pdo, 'rate_orbit_per_usd', 100000);
$gateways = orbit_gateways();

$send = function (string $text, ?array $markup = null) use ($token, $chatId, $MAIN_KEYBOARD): void {
    $params = ['chat_id' => $chatId, 'text' => $text, 'reply_markup' => $markup ?? $MAIN_KEYBOARD];
    orbit_tg_send($token, 'sendMessage', $params);
};

$openAppKeyboard = null;
if ($webapp !== '') {
    $openAppKeyboard = ['inline_keyboard' => [[['text' => '🪐 Open Orbit', 'web_app' => ['url' => $webapp]]]]];
}

// ---- /start ----
if (strpos($text, '/start') === 0) {
    orbit_clear_conversation($pdo, $userId);
    $parts = explode(' ', $text, 2);
    $payload = $parts[1] ?? '';
    if (strpos($payload, 'ref_') === 0) {
        orbit_attribute_referral($pdo, $user, (int)substr($payload, 4));
    } elseif ($payload !== '' && ctype_digit($payload)) {
        // t.me/<bot>?startapp=<telegram_id> style payloads also accepted.
        orbit_attribute_referral($pdo, $user, (int)$payload);
    }
    $hello = "🪐 Welcome to Orbit Earn!\n\n"
        . "Play games, watch ads & invite friends to earn *orbit*.\n"
        . "💱 100,000 orbit = $1\n"
        . "💸 Minimum withdrawal: 2,000 orbit ($0.02)\n\n"
        . "Tap below to open the app and start earning.";
    $params = ['chat_id' => $chatId, 'text' => $hello, 'parse_mode' => 'Markdown', 'reply_markup' => $MAIN_KEYBOARD];
    orbit_tg_send($token, 'sendMessage', $params);
    if ($openAppKeyboard !== null) {
        orbit_tg_send($token, 'sendMessage', ['chat_id' => $chatId, 'text' => '👇', 'reply_markup' => $openAppKeyboard]);
    }
    http_response_code(200);
    echo 'ok';
    exit;
}

// ---- Withdraw conversation steps ----
$conv = orbit_conversation($pdo, $userId);
if ($conv !== null) {
    $step = $conv['step'];
    $data = json_decode((string)$conv['data_json'], true) ?: [];

    if ($step === 'wd_gateway') {
        $picked = null;
        foreach ($gateways as $key => $label) {
            if (stripos($text, $label) !== false || strtolower($text) === $key) {
                $picked = $key;
                break;
            }
        }
        if ($picked === null) {
            $send("Please choose a gateway from the buttons below.", ['keyboard' => orbit_gw_keyboard($gateways), 'resize_keyboard' => true, 'one_time_keyboard' => true]);
            http_response_code(200);
            echo 'ok';
            exit;
        }
        $data['gateway'] = $picked;
        orbit_set_conversation($pdo, $userId, 'wd_address', $data);
        $send("You chose *" . $gateways[$picked] . "*.\n\nNow send your *receiving address* (your " . $gateways[$picked] . " address where we should send the payment).", null);
        http_response_code(200);
        echo 'ok';
        exit;
    }

    if ($step === 'wd_address') {
        if (strlen($text) < 5 || strlen($text) > 255) {
            $send("That doesn't look like a valid address. Please send your receiving address again, or /start to cancel.");
            http_response_code(200);
            echo 'ok';
            exit;
        }
        $data['address'] = $text;
        orbit_set_conversation($pdo, $userId, 'wd_confirm', $data);
        $fee = orbit_gateway_fee($pdo, $data['gateway']);
        $min = orbit_setting_int($pdo, 'min_withdraw', 2000);
        $send("Confirm your withdrawal:\n\n"
            . "Gateway: *" . $gateways[$data['gateway']] . "*\n"
            . "Address: `" . $text . "`\n"
            . "Fee: " . number_format($fee) . " orbit\n"
            . "Minimum: " . number_format($min) . " orbit\n\n"
            . "Now enter the *amount* in orbit (minimum " . number_format($min) . "), or /start to cancel.",
            null);
        http_response_code(200);
        echo 'ok';
        exit;
    }

    if ($step === 'wd_confirm') {
        $amount = (int)preg_replace('/[^0-9]/', '', $text);
        $min = orbit_setting_int($pdo, 'min_withdraw', 2000);
        $balance = (int)$user['balance_orbit'];
        if ($amount < $min) {
            $send("Minimum withdrawal is " . number_format($min) . " orbit. Enter a larger amount, or /start to cancel.");
            http_response_code(200);
            echo 'ok';
            exit;
        }
        if ($amount > $balance) {
            $send("Insufficient balance (" . number_format($balance) . " orbit). Enter a smaller amount, or /start to cancel.");
            http_response_code(200);
            echo 'ok';
            exit;
        }
        $fee = orbit_gateway_fee($pdo, $data['gateway']);
        $net = $amount - $fee;
        if ($net <= 0) {
            $send("The fee exceeds the amount. Enter a larger amount, or /start to cancel.");
            http_response_code(200);
            echo 'ok';
            exit;
        }
        // One-time confirmation code (bot → user chat → user types it here).
        // Both the code and its expiry live in the conversation payload, so
        // the PHP clock is the only clock involved — no timezone mixing.
        $otp = (string)random_int(100000, 999999);
        $data['amount'] = $amount;
        $data['fee'] = $fee;
        $data['net'] = $net;
        $data['otp_code'] = $otp;
        $data['otp_expires'] = time() + 600;
        orbit_set_conversation($pdo, $userId, 'wd_code', $data);
        orbit_tg_send($token, 'sendMessage', [
            'chat_id' => $chatId,
            'text' => "🔐 *Withdrawal confirmation*\n\n"
                . "Amount: " . number_format($amount) . " orbit ($" . orbit_to_usd($amount, $rate) . ")\n"
                . "Gateway: " . $gateways[$data['gateway']] . "\n"
                . "To: `" . $data['address'] . "`\n\n"
                . "Your code is: `" . $otp . "`\n\n"
                . "Send the 6-digit code here within 10 minutes to confirm.",
            'parse_mode' => 'Markdown',
        ]);
        $send("🔐 I sent a 6-digit confirmation code to this chat.\nSend it here to confirm your withdrawal, or /start to cancel.");
        http_response_code(200);
        echo 'ok';
        exit;
    }

    if ($step === 'wd_code') {
        $entered = (string)preg_replace('/[^0-9]/', '', $text);
        $expected = (string)($data['otp_code'] ?? '');
        $expires = (int)($data['otp_expires'] ?? 0);
        if ($expected === '' || !hash_equals($expected, $entered) || time() > $expires) {
            orbit_clear_conversation($pdo, $userId);
            $send("❌ Wrong or expired code. Withdrawal cancelled for your safety — start again with 💸 Withdraw.");
            http_response_code(200);
            echo 'ok';
            exit;
        }
        $amount = (int)($data['amount'] ?? 0);
        $fee = (int)($data['fee'] ?? 0);
        $net = (int)($data['net'] ?? 0);
        $min = orbit_setting_int($pdo, 'min_withdraw', 2000);
        // Balance could have changed while the code was on its way; re-check.
        $bst = $pdo->prepare('SELECT balance_orbit FROM users WHERE id = ?');
        $bst->execute([$userId]);
        $balance = (int)($bst->fetch()['balance_orbit'] ?? 0);
        if ($amount < $min || $amount > $balance || $net <= 0) {
            orbit_clear_conversation($pdo, $userId);
            $send("❌ This withdrawal is no longer valid (balance changed). Start again with 💸 Withdraw.");
            http_response_code(200);
            echo 'ok';
            exit;
        }
        orbit_credit($pdo, $userId, 'withdraw', -$amount, $data['gateway']);
        $stmt = $pdo->prepare("INSERT INTO withdrawals (user_id, chat_id, gateway, address, amount_orbit, fee_orbit, net_orbit, status) VALUES (?, ?, ?, ?, ?, ?, ?, 'pending')");
        $stmt->execute([$userId, $chatId, $data['gateway'], $data['address'], $amount, $fee, $net]);
        $wdId = (int)$pdo->lastInsertId();
        orbit_clear_conversation($pdo, $userId);

        // Referral stage 3: paid on this user's first-ever withdrawal request.
        $cnt = $pdo->prepare('SELECT COUNT(*) AS c FROM withdrawals WHERE user_id = ?');
        $cnt->execute([$userId]);
        if ((int)($cnt->fetch()['c'] ?? 0) === 1) {
            orbit_maybe_pay_referral_withdraw($pdo, $userId);
        }

        $send("✅ *Withdrawal submitted!* (#$wdId)\n\n"
            . "Amount: " . number_format($amount) . " orbit ($" . orbit_to_usd($amount, $rate) . ")\n"
            . "Gateway: " . $gateways[$data['gateway']] . "\n"
            . "Your request is *pending admin approval*. You will get a message here once it is paid or if it is rejected.",
            null);
        $adminTg = (string)($env['ADMIN_TELEGRAM_ID'] ?? '');
        if ($adminTg !== '') {
            orbit_tg_send($token, 'sendMessage', [
                'chat_id' => $adminTg,
                'text' => "🆕 New withdrawal request #$wdId\nUser: $telegramId (@" . ($user['username'] ?? '-') . ")\n"
                    . "Amount: " . number_format($amount) . " orbit ($" . orbit_to_usd($amount, $rate) . ")\nGateway: " . $gateways[$data['gateway']],
            ]);
        }
        http_response_code(200);
        echo 'ok';
        exit;
    }
}

// ---- Reply keyboard buttons ----
if ($text === '💰 Balance' || $text === '/balance') {
    $balance = (int)$user['balance_orbit'];
    $send("💰 *Your balance*\n\n" . number_format($balance) . " orbit\n≈ $" . orbit_to_usd($balance, $rate));
} elseif ($text === '👥 Referral' || $text === '/referral') {
    $stmt = $pdo->prepare('SELECT COUNT(*) AS c FROM referrals WHERE referrer_id = ?');
    $stmt->execute([$userId]);
    $count = (int)($stmt->fetch()['c'] ?? 0);
    $bj = orbit_setting_int($pdo, 'ref_bonus_join', 200);
    $ba = orbit_setting_int($pdo, 'ref_bonus_active', 500);
    $bw = orbit_setting_int($pdo, 'ref_bonus_withdraw', 300);
    $link = $botUsername !== '' ? 'https://t.me/' . $botUsername . '?startapp=ref_' . $telegramId : '(bot username not configured yet)';
    $send("👥 *Referral*\n\nYour link:\n$link\n\n"
        . "• +" . number_format($bj) . " orbit when a friend joins\n"
        . "• +" . number_format($ba) . " orbit when they play 2 games\n"
        . "• +" . number_format($bw) . " orbit on their first withdrawal\n"
        . "• +5% of their earnings, forever\n\n"
        . "Friends so far: $count");
} elseif ($text === '💸 Withdraw' || $text === '/withdraw') {
    if (orbit_safety_required($user)) {
        $send("🛡️ *Quick Safety Check needed*\n\nOpen the Orbit app and complete the Quick Safety Check first — it takes 10 seconds and proves you are human.");
    } elseif (!orbit_has_active_friend($pdo, $userId)) {
        $send("👥 *Invite & activate a friend first*\n\nWithdrawals unlock after at least one friend you invited plays 2 games. Share your link from the app's Friends tab!");
    } else {
        $balance = (int)$user['balance_orbit'];
        $min = orbit_setting_int($pdo, 'min_withdraw', 2000);
        if ($balance < $min) {
            $send("💸 *Withdraw*\n\nBalance: " . number_format($balance) . " orbit\nMinimum: " . number_format($min) . " orbit — keep earning first! 🚀");
        } else {
            orbit_set_conversation($pdo, $userId, 'wd_gateway', []);
            $send("💸 *Withdraw* — step 1/4\n\nChoose the gateway:", ['keyboard' => orbit_gw_keyboard($gateways), 'resize_keyboard' => true, 'one_time_keyboard' => true]);
        }
    }
} else {
    $send("Use the menu below, or open the app to earn orbit. 🪐");
}

http_response_code(200);
echo 'ok';
