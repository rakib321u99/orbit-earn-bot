<?php
// Orbit Earn Bot v1 — admin panel (single router).
// Pages: dashboard | withdrawals | tasks | games | ads | users | broadcast | settings | telegram
declare(strict_types=1);
require __DIR__ . '/common.php';

[$pdo, $admin] = admin_require_login();
$env = orbit_env();
$page = (string)($_GET['page'] ?? 'dashboard');
$flash = '';

function proof_upload(array $file): ?string
{
    if (($file['error'] ?? UPLOAD_ERR_NO_FILE) !== UPLOAD_ERR_OK) {
        return null;
    }
    if ($file['size'] > 5 * 1024 * 1024) {
        return null;
    }
    $ext = strtolower(pathinfo((string)$file['name'], PATHINFO_EXTENSION));
    if (!in_array($ext, ['jpg', 'jpeg', 'png', 'webp', 'gif'], true)) {
        return null;
    }
    $dir = __DIR__ . '/../uploads/proofs';
    if (!is_dir($dir)) {
        mkdir($dir, 0755, true);
    }
    $name = 'proof_' . date('Ymd_His') . '_' . bin2hex(random_bytes(6)) . '.' . $ext;
    if (!move_uploaded_file($file['tmp_name'], $dir . '/' . $name)) {
        return null;
    }
    return 'uploads/proofs/' . $name;
}

function tg_flash(string $label, array $res): string
{
    $ok = is_array($res['data'] ?? null) && ($res['data']['ok'] ?? false) === true;
    if ($ok) {
        return '<div class="ok">' . admin_h($label) . ' — Telegram confirmed.</div>';
    }
    $desc = is_array($res['data'] ?? null) ? (string)($res['data']['description'] ?? '') : '';
    return '<div class="err">' . admin_h($label) . ' FAILED (http ' . (int)($res['code'] ?? 0) . ($desc !== '' ? ': ' . $desc : '') . ').</div>';
}

// ---------------- POST actions ----------------
if (($_SERVER['REQUEST_METHOD'] ?? '') === 'POST') {
    $do = (string)($_POST['do'] ?? '');

    // --- Withdrawal: approve ---
    if ($do === 'wd_approve') {
        $id = (int)($_POST['id'] ?? 0);
        $tx = trim((string)($_POST['proof_tx'] ?? ''));
        $stmt = $pdo->prepare("SELECT * FROM withdrawals WHERE id = ? AND status = 'pending'");
        $stmt->execute([$id]);
        $wd = $stmt->fetch();
        if ($wd === false) {
            $flash = '<div class="err">Withdrawal not found or already decided.</div>';
        } else {
            $proofPath = proof_upload($_FILES['proof'] ?? []);
            $pdo->prepare("UPDATE withdrawals SET status = 'paid', proof_path = COALESCE(?, proof_path), proof_tx = ?, decided_at = NOW() WHERE id = ?")
                ->execute([$proofPath, $tx !== '' ? $tx : null, $id]);
            $wd['proof_path'] = $proofPath ?? $wd['proof_path'];
            $wd['proof_tx'] = $tx !== '' ? $tx : $wd['proof_tx'];
            [$sent, $note] = orbit_notify_withdrawal($pdo, $env, $wd, 'paid');
            $flash = $sent
                ? '<div class="ok">Withdrawal #' . $id . ' approved and paid. User notified via bot (' . admin_h($note) . ').</div>'
                : '<div class="err">Withdrawal #' . $id . ' marked PAID, but the bot notification FAILED (' . admin_h($note) . '). Message the user manually.</div>';
        }
    }

    // --- Withdrawal: reject (refunds the orbit) ---
    if ($do === 'wd_reject') {
        $id = (int)($_POST['id'] ?? 0);
        $reason = trim((string)($_POST['reason'] ?? ''));
        if ($reason === '') {
            $flash = '<div class="err">A rejection reason is required.</div>';
        } else {
            $stmt = $pdo->prepare("SELECT * FROM withdrawals WHERE id = ? AND status = 'pending'");
            $stmt->execute([$id]);
            $wd = $stmt->fetch();
            if ($wd === false) {
                $flash = '<div class="err">Withdrawal not found or already decided.</div>';
            } else {
                $pdo->prepare("UPDATE withdrawals SET status = 'rejected', reject_reason = ?, decided_at = NOW() WHERE id = ?")
                    ->execute([$reason, $id]);
                // Refund the deducted orbit.
                orbit_credit($pdo, (int)$wd['user_id'], 'withdraw_refund', (int)$wd['amount_orbit'], 'wd:' . $id);
                $wd['reject_reason'] = $reason;
                [$sent, $note] = orbit_notify_withdrawal($pdo, $env, $wd, 'rejected');
                $flash = $sent
                    ? '<div class="ok">Withdrawal #' . $id . ' rejected, orbit refunded, user notified (' . admin_h($note) . ').</div>'
                    : '<div class="err">Withdrawal #' . $id . ' rejected and refunded, but the bot notification FAILED (' . admin_h($note) . '). Message the user manually.</div>';
            }
        }
    }

    // --- Tasks CRUD ---
    if ($do === 'task_save') {
        $id = (int)($_POST['id'] ?? 0);
        $title = trim((string)($_POST['title'] ?? ''));
        $type = (string)($_POST['type'] ?? 'manual') === 'telegram' ? 'telegram' : 'manual';
        $channel = trim((string)($_POST['channel_username'] ?? ''));
        $channel = ltrim($channel, '@');
        $url = trim((string)($_POST['target_url'] ?? ''));
        $reward = max(0, (int)($_POST['reward_orbit'] ?? 0));
        $active = isset($_POST['is_active']) ? 1 : 0;
        $sort = (int)($_POST['sort'] ?? 0);
        if ($title === '') {
            $flash = '<div class="err">Task title is required.</div>';
        } elseif ($id > 0) {
            $pdo->prepare('UPDATE tasks SET title=?, type=?, channel_username=?, target_url=?, reward_orbit=?, is_active=?, sort=? WHERE id=?')
                ->execute([$title, $type, $channel !== '' ? $channel : null, $url !== '' ? $url : null, $reward, $active, $sort, $id]);
            $flash = '<div class="ok">Task updated.</div>';
        } else {
            $pdo->prepare('INSERT INTO tasks (title, type, channel_username, target_url, reward_orbit, is_active, sort) VALUES (?, ?, ?, ?, ?, ?, ?)')
                ->execute([$title, $type, $channel !== '' ? $channel : null, $url !== '' ? $url : null, $reward, $active, $sort]);
            $flash = '<div class="ok">Task added.</div>';
        }
    }
    if ($do === 'task_delete') {
        $pdo->prepare('DELETE FROM tasks WHERE id = ?')->execute([(int)($_POST['id'] ?? 0)]);
        $flash = '<div class="ok">Task deleted.</div>';
    }

    // --- Games config (stored in settings) ---
    if ($do === 'save_economy') {
        $keys = ['game_runs_per_day', 'ad_cooldown_sec', 'spin_prizes', 'spin_weights', 'ref_bonus_join', 'ref_bonus_active', 'ref_bonus_withdraw', 'ref_commission_pct', 'safety_bonus'];
        foreach ($keys as $k) {
            $v = trim((string)($_POST[$k] ?? ''));
            if ($v !== '') {
                $pdo->prepare('INSERT INTO settings (skey, svalue) VALUES (?, ?) ON DUPLICATE KEY UPDATE svalue = VALUES(svalue)')->execute([$k, $v]);
            }
        }
        $flash = '<div class="ok">Economy settings saved.</div>';
    }

    // --- Ads config: per-unit provider + IDs (stored in settings) ---
    if ($do === 'save_ads') {
        $providers = orbit_ad_providers();
        foreach (['ad1', 'ad2', 'ad3', 'ad4'] as $u) {
            $prov = (string)($_POST[$u . '_provider'] ?? 'placeholder');
            if (!in_array($prov, $providers, true)) {
                $prov = 'placeholder';
            }
            $pdo->prepare('INSERT INTO settings (skey, svalue) VALUES (?, ?) ON DUPLICATE KEY UPDATE svalue = VALUES(svalue)')->execute([$u . '_provider', $prov]);
            $block = trim((string)($_POST[$u . '_block_id'] ?? ''));
            $pdo->prepare('INSERT INTO settings (skey, svalue) VALUES (?, ?) ON DUPLICATE KEY UPDATE svalue = VALUES(svalue)')->execute([$u . '_block_id', $block]);
            $mzone = trim((string)($_POST[$u . '_monetag_zone'] ?? ''));
            $pdo->prepare('INSERT INTO settings (skey, svalue) VALUES (?, ?) ON DUPLICATE KEY UPDATE svalue = VALUES(svalue)')->execute([$u . '_monetag_zone', $mzone]);
            $akey = trim((string)($_POST[$u . '_adsterra_key'] ?? ''));
            $pdo->prepare('INSERT INTO settings (skey, svalue) VALUES (?, ?) ON DUPLICATE KEY UPDATE svalue = VALUES(svalue)')->execute([$u . '_adsterra_key', $akey]);
            $afmt = (string)($_POST[$u . '_adsterra_format'] ?? 'banner');
            if (!in_array($afmt, ['banner', 'native', 'socialbar'], true)) {
                $afmt = 'banner';
            }
            $pdo->prepare('INSERT INTO settings (skey, svalue) VALUES (?, ?) ON DUPLICATE KEY UPDATE svalue = VALUES(svalue)')->execute([$u . '_adsterra_format', $afmt]);
            foreach (['reward', 'cap'] as $f) {
                $v = trim((string)($_POST[$u . '_' . $f] ?? ''));
                if ($v !== '') {
                    $pdo->prepare('INSERT INTO settings (skey, svalue) VALUES (?, ?) ON DUPLICATE KEY UPDATE svalue = VALUES(svalue)')->execute([$u . '_' . $f, $v]);
                }
            }
        }
        $giga = trim((string)($_POST['giga_project_id'] ?? ''));
        $pdo->prepare('INSERT INTO settings (skey, svalue) VALUES (?, ?) ON DUPLICATE KEY UPDATE svalue = VALUES(svalue)')->execute(['giga_project_id', $giga]);
        $msdk = trim((string)($_POST['monetag_sdk_src'] ?? ''));
        $pdo->prepare('INSERT INTO settings (skey, svalue) VALUES (?, ?) ON DUPLICATE KEY UPDATE svalue = VALUES(svalue)')->execute(['monetag_sdk_src', $msdk]);
        $adom = trim((string)($_POST['adsterra_invoke_domain'] ?? ''));
        if ($adom !== '') {
            $pdo->prepare('INSERT INTO settings (skey, svalue) VALUES (?, ?) ON DUPLICATE KEY UPDATE svalue = VALUES(svalue)')->execute(['adsterra_invoke_domain', $adom]);
        }
        $flash = '<div class="ok">Ad settings saved. Units on placeholder stay on the in-app timer until real IDs are pasted.</div>';
    }

    // --- Users: adjust balance / ban ---
    if ($do === 'user_adjust') {
        $uid = (int)($_POST['user_id'] ?? 0);
        $delta = (int)($_POST['delta'] ?? 0);
        $note = trim((string)($_POST['note'] ?? 'admin'));
        if ($uid > 0 && $delta !== 0) {
            orbit_credit($pdo, $uid, 'admin_adjust', $delta, 'admin:' . $_SESSION['admin_id'] . ':' . $note);
            $flash = '<div class="ok">Balance adjusted by ' . ($delta > 0 ? '+' : '') . number_format($delta) . ' orbit.</div>';
        }
    }
    if ($do === 'user_ban') {
        $uid = (int)($_POST['user_id'] ?? 0);
        $ban = (int)($_POST['ban'] ?? 0);
        $pdo->prepare('UPDATE users SET is_banned = ? WHERE id = ?')->execute([$ban ? 1 : 0, $uid]);
        $flash = '<div class="ok">' . ($ban ? 'User banned.' : 'User unbanned.') . '</div>';
    }

    // --- Broadcast ---
    if ($do === 'broadcast') {
        $text = trim((string)($_POST['text'] ?? ''));
        $token = (string)($env['BOT_TOKEN'] ?? '');
        if ($text === '') {
            $flash = '<div class="err">Message text is required.</div>';
        } elseif ($token === '') {
            $flash = '<div class="err">BOT_TOKEN is not configured.</div>';
        } else {
            $stmt = $pdo->query('SELECT DISTINCT chat_id FROM users WHERE chat_id > 0 AND is_banned = 0');
            $chats = $stmt ? $stmt->fetchAll(PDO::FETCH_COLUMN) : [];
            $sent = 0;
            $pdo->prepare('INSERT INTO broadcasts (admin, text) VALUES (?, ?)')->execute([(string)$admin['username'], $text]);
            $bcId = (int)$pdo->lastInsertId();
            foreach ($chats as $i => $cid) {
                $res = orbit_tg_send($token, 'sendMessage', ['chat_id' => (int)$cid, 'text' => $text]);
                if ($res['code'] === 200 && ($res['data']['ok'] ?? false) === true) {
                    $sent++;
                }
                if ($i > 0 && $i % 25 === 0) {
                    usleep(1100000); // ~1 msg/sec politeness
                }
            }
            $pdo->prepare('UPDATE broadcasts SET sent_count = ? WHERE id = ?')->execute([$sent, $bcId]);
            $flash = '<div class="ok">Broadcast sent to ' . $sent . ' / ' . count($chats) . ' chats.</div>';
        }
    }

    // --- Settings ---
    if ($do === 'save_settings') {
        $keys = ['rate_orbit_per_usd', 'min_withdraw', 'fee_xrocket', 'fee_usdt', 'fee_faucetpay', 'fee_ton', 'app_name'];
        foreach ($keys as $k) {
            $v = trim((string)($_POST[$k] ?? ''));
            if ($v !== '') {
                $pdo->prepare('INSERT INTO settings (skey, svalue) VALUES (?, ?) ON DUPLICATE KEY UPDATE svalue = VALUES(svalue)')->execute([$k, $v]);
            }
        }
        $flash = '<div class="ok">Settings saved.</div>';
    }

    // --- Telegram: webhook + menu button (server-side, works from any host) ---
    if (in_array($do, ['tg_set_webhook', 'tg_del_webhook', 'tg_set_menu'], true)) {
        $token = (string)($env['BOT_TOKEN'] ?? '');
        $webapp = rtrim((string)($env['WEBAPP_URL'] ?? ''), '/');
        if ($token === '') {
            $flash = '<div class="err">BOT_TOKEN is not set (env.php or environment).</div>';
        } elseif ($do === 'tg_del_webhook') {
            $r = orbit_tg_send($token, 'deleteWebhook', ['drop_pending_updates' => false]);
            $flash = tg_flash('Webhook deleted', $r);
        } elseif ($do === 'tg_set_menu') {
            if ($webapp === '') {
                $flash = '<div class="err">WEBAPP_URL is not set.</div>';
            } else {
                $r = orbit_tg_send($token, 'setChatMenuButton', [
                    'menu_button' => ['type' => 'web_app', 'text' => '🚀 Open Orbit', 'web_app' => ['url' => $webapp . '/']],
                ]);
                $flash = tg_flash('Menu button set', $r);
            }
        } else { // tg_set_webhook
            if ($webapp === '') {
                $flash = '<div class="err">WEBAPP_URL is not set.</div>';
            } else {
                $r = orbit_tg_send($token, 'setWebhook', ['url' => $webapp . '/bot.php', 'max_connections' => 40]);
                $flash = tg_flash('Webhook set to ' . $webapp . '/bot.php', $r);
            }
        }
    }
}

// ---------------- Pages ----------------
$body = $flash;
$rate = orbit_setting_int($pdo, 'rate_orbit_per_usd', 100000);

if ($page === 'dashboard') {
    $stats = [];
    $stats['users'] = (int)($pdo->query('SELECT COUNT(*) c FROM users')->fetch()['c'] ?? 0);
    $stats['active'] = (int)($pdo->query("SELECT COUNT(DISTINCT user_id) c FROM ledger WHERE ledger_date = '" . orbit_today() . "'")->fetch()['c'] ?? 0);
    $stats['issued'] = (int)($pdo->query("SELECT COALESCE(SUM(amount_orbit),0) s FROM ledger WHERE amount_orbit > 0 AND source IN ('game','ad','task','spin','safety','ref_bonus_join','ref_bonus_active','ref_bonus_withdraw')")->fetch()['s'] ?? 0);
    $stats['pending'] = (int)($pdo->query("SELECT COUNT(*) c FROM withdrawals WHERE status = 'pending'")->fetch()['c'] ?? 0);
    $stats['paid_out'] = (int)($pdo->query("SELECT COALESCE(SUM(net_orbit),0) s FROM withdrawals WHERE status = 'paid'")->fetch()['s'] ?? 0);
    $body .= '<h2>📊 Dashboard</h2><div class="stats">'
        . '<div class="stat"><b>' . number_format($stats['users']) . '</b><span>Total users</span></div>'
        . '<div class="stat"><b>' . number_format($stats['active']) . '</b><span>Active today</span></div>'
        . '<div class="stat"><b>' . number_format($stats['issued']) . '</b><span>Orbit issued</span></div>'
        . '<div class="stat"><b>' . number_format($stats['pending']) . '</b><span>Pending withdrawals</span></div>'
        . '<div class="stat"><b>' . number_format($stats['paid_out']) . '</b><span>Orbit paid out</span></div>'
        . '</div>';
    $rows = $pdo->query('SELECT w.id, w.gateway, w.amount_orbit, w.status, w.created_at, u.username, u.telegram_id FROM withdrawals w JOIN users u ON u.id = w.user_id ORDER BY w.id DESC LIMIT 8')->fetchAll();
    $body .= '<div class="card"><h3>Latest withdrawals</h3><table><tr><th>ID</th><th>User</th><th>Amount</th><th>Gateway</th><th>Status</th><th>When</th></tr>';
    foreach ($rows as $r) {
        $body .= '<tr><td>#' . $r['id'] . '</td><td>@' . admin_h((string)($r['username'] ?? '')) . ' (' . $r['telegram_id'] . ')</td>'
            . '<td>' . number_format((int)$r['amount_orbit']) . '</td><td>' . admin_h($r['gateway']) . '</td><td>' . admin_h($r['status']) . '</td><td>' . admin_h((string)$r['created_at']) . '</td></tr>';
    }
    $body .= '</table></div>';
}

if ($page === 'withdrawals') {
    $filter = (string)($_GET['f'] ?? 'pending');
    $body .= '<h2>💸 Withdrawals</h2>'
        . '<p><a class="btn ghost" href="panel.php?page=withdrawals&f=pending">Pending</a> '
        . '<a class="btn ghost" href="panel.php?page=withdrawals&f=paid">Paid</a> '
        . '<a class="btn ghost" href="panel.php?page=withdrawals&f=rejected">Rejected</a> '
        . '<a class="btn ghost" href="panel.php?page=withdrawals&f=all">All</a></p>';
    $where = $filter === 'all' ? '' : "WHERE w.status = '" . ($filter === 'paid' ? 'paid' : ($filter === 'rejected' ? 'rejected' : 'pending')) . "'";
    $rows = $pdo->query('SELECT w.*, u.username, u.telegram_id FROM withdrawals w JOIN users u ON u.id = w.user_id ' . $where . ' ORDER BY w.id DESC LIMIT 100')->fetchAll();
    $gateways = orbit_gateways();
    foreach ($rows as $w) {
        $usd = orbit_to_usd((int)$w['amount_orbit'], $rate);
        $body .= '<div class="card"><b>#' . $w['id'] . '</b> — @' . admin_h((string)($w['username'] ?? '')) . ' (' . $w['telegram_id'] . ') — '
            . '<b>' . number_format((int)$w['amount_orbit']) . ' orbit</b> ($' . $usd . ') — ' . admin_h($gateways[$w['gateway']] ?? $w['gateway'])
            . ' — fee ' . number_format((int)$w['fee_orbit']) . ' — net ' . number_format((int)$w['net_orbit']) . '<br>'
            . '<span class="addr" id="addr-' . $w['id'] . '">' . admin_h((string)$w['address']) . '</span> '
            . '<button class="ghost" type="button" onclick="navigator.clipboard.writeText(document.getElementById(\'addr-' . $w['id'] . '\').textContent);this.textContent=\'Copied!\'">📋 Copy address</button><br>'
            . '<span style="color:#9aa6dd">Status: ' . admin_h($w['status']) . ' — ' . admin_h((string)$w['created_at']) . '</span>';
        if ($w['status'] === 'pending') {
            $body .= '<form method="post" enctype="multipart/form-data" style="margin-top:10px">'
                . '<input type="hidden" name="do" value="wd_approve"><input type="hidden" name="id" value="' . $w['id'] . '">'
                . '<label>Proof photo (optional): <input type="file" name="proof" accept="image/*"></label> '
                . '<label>TX hash (optional): <input name="proof_tx" size="30" placeholder="tx hash"></label> '
                . '<button type="submit">✅ Approve &amp; mark paid</button></form>'
                . '<form method="post" class="inline" style="margin-top:6px">'
                . '<input type="hidden" name="do" value="wd_reject"><input type="hidden" name="id" value="' . $w['id'] . '">'
                . '<input name="reason" size="40" placeholder="Rejection reason (required)" required> '
                . '<button type="submit" class="danger">✖ Reject &amp; refund</button></form>'
                . '<div style="color:#9aa6dd;font-size:12px;margin-top:6px">Approving or rejecting sends a bot message to the user automatically — nothing completes silently.</div>';
        } else {
            if (!empty($w['proof_tx'])) {
                $body .= '<br><span class="addr">TX: ' . admin_h((string)$w['proof_tx']) . '</span>';
            }
            if (!empty($w['proof_path']) && is_file(__DIR__ . '/../' . $w['proof_path'])) {
                $body .= '<br><img class="proof" src="../' . admin_h((string)$w['proof_path']) . '">';
            }
            if (!empty($w['reject_reason'])) {
                $body .= '<br>Reason: ' . admin_h((string)$w['reject_reason']);
            }
        }
        $body .= '</div>';
    }
    if (empty($rows)) {
        $body .= '<div class="card">No withdrawals in this view.</div>';
    }
}

if ($page === 'tasks') {
    $edit = null;
    if (isset($_GET['edit'])) {
        $stmt = $pdo->prepare('SELECT * FROM tasks WHERE id = ?');
        $stmt->execute([(int)$_GET['edit']]);
        $edit = $stmt->fetch() ?: null;
    }
    $body .= '<h2>✅ Tasks</h2><div class="card"><h3>' . ($edit ? 'Edit task #' . (int)$edit['id'] : 'Add task') . '</h3>'
        . '<form method="post"><input type="hidden" name="do" value="task_save">'
        . '<input type="hidden" name="id" value="' . (int)($edit['id'] ?? 0) . '">'
        . '<input name="title" size="40" placeholder="Title" value="' . admin_h((string)($edit['title'] ?? '')) . '" required> '
        . '<select name="type"><option value="telegram"' . (($edit['type'] ?? '') === 'telegram' ? ' selected' : '') . '>telegram (verified)</option>'
        . '<option value="manual"' . (($edit['type'] ?? 'manual') === 'manual' ? ' selected' : '') . '>manual</option></select> '
        . '<input name="channel_username" size="18" placeholder="channel (no @)" value="' . admin_h((string)($edit['channel_username'] ?? '')) . '"> '
        . '<input name="target_url" size="24" placeholder="https://t.me/..." value="' . admin_h((string)($edit['target_url'] ?? '')) . '"> '
        . '<input name="reward_orbit" type="number" min="0" value="' . (int)($edit['reward_orbit'] ?? 100) . '" style="width:90px"> '
        . '<input name="sort" type="number" value="' . (int)($edit['sort'] ?? 0) . '" style="width:60px"> '
        . '<label><input type="checkbox" name="is_active" ' . ((int)($edit['is_active'] ?? 1) === 1 ? 'checked' : '') . '> active</label> '
        . '<button type="submit">Save</button></form></div>';
    $rows = $pdo->query('SELECT t.*, (SELECT COUNT(*) FROM user_tasks ut WHERE ut.task_id = t.id) AS done_count FROM tasks t ORDER BY t.sort, t.id')->fetchAll();
    $body .= '<div class="card"><table><tr><th>ID</th><th>Title</th><th>Type</th><th>Channel</th><th>Reward</th><th>Done</th><th>Active</th><th></th></tr>';
    foreach ($rows as $t) {
        $body .= '<tr><td>' . $t['id'] . '</td><td>' . admin_h($t['title']) . '</td><td>' . admin_h($t['type']) . '</td>'
            . '<td>' . admin_h((string)($t['channel_username'] ?? '')) . '</td><td>' . number_format((int)$t['reward_orbit']) . '</td>'
            . '<td>' . $t['done_count'] . '</td><td>' . ((int)$t['is_active'] === 1 ? 'yes' : 'no') . '</td>'
            . '<td><a class="btn ghost" href="panel.php?page=tasks&edit=' . $t['id'] . '">Edit</a> '
            . '<form method="post" class="inline" onsubmit="return confirm(\'Delete this task?\')"><input type="hidden" name="do" value="task_delete"><input type="hidden" name="id" value="' . $t['id'] . '"><button class="danger" type="submit">Delete</button></form></td></tr>';
    }
    $body .= '</table></div>';
}

if ($page === 'games') {
    $body .= '<h2>🎮 Games economy</h2><div class="card"><form method="post"><input type="hidden" name="do" value="save_economy"><table>';
    $fields = [
        'game_runs_per_day' => 'Paid runs per game per day',
        'ad_cooldown_sec' => 'Ad view cooldown (seconds)',
        'spin_prizes' => 'Spin prizes (comma orbit)', 'spin_weights' => 'Spin weights (comma)',
        'ref_bonus_join' => 'Referral bonus: join stage (orbit)', 'ref_bonus_active' => 'Referral bonus: active stage (orbit)', 'ref_bonus_withdraw' => 'Referral bonus: withdraw stage (orbit)', 'ref_commission_pct' => 'Referral commission %',
        'safety_bonus' => 'Quick Safety Check bonus (orbit)',
    ];
    foreach ($fields as $k => $label) {
        $body .= '<tr><td>' . $label . '</td><td><input name="' . $k . '" value="' . admin_h(orbit_setting($pdo, $k, '')) . '" size="24"></td></tr>';
    }
    $body .= '</table><button type="submit">Save economy</button></form></div>'
        . '<div class="card" style="color:#9aa6dd;font-size:13px">Game rewards are 100–200 orbit per run by score (fixed formula in api.php). Per-game daily runs reset at 06:00 Asia/Dhaka. Ad units (providers, block IDs, rewards, caps) are configured on the <a href="panel.php?page=ads">📺 Ads</a> page.</div>';
}

if ($page === 'ads') {
    $body .= '<h2>📺 Ads — Watch &amp; Earn</h2>'
        . '<div class="card" style="color:#9aa6dd;font-size:13px">'
        . '<b>Adsgram</b>: publisher account at partner.adsgram.ai → create an Ad Platform → create an Ad unit of type <b>Reward</b> → copy the <b>Block ID</b> (digits, e.g. 123) and paste it below. '
        . 'Do NOT enable debug mode on the block — test ads pay nothing.<br>'
        . '<b>Giga.pub</b>: publisher account at giga.pub → copy your <b>Project ID</b> and paste it in the field at the bottom; then set any units to the Giga.pub provider.<br>'
        . '<b>Monetag</b>: publisher account at monetag.com → create a Telegram Mini App zone (Rewarded Interstitial) → copy the <b>main Zone ID</b> (digits) into the unit row, and paste the <b>exact SDK script URL</b> from the integration tab into the field at the bottom.<br>'
        . '<b>Adsterra</b>: publisher account at partners.adsterra.com → Websites → add/approve your site → <b>Banner</b>/<b>Native Banner</b>: copy the <b>placement key</b> from the atOptions ad code (the \'key\' value, e.g. 46ab20bd…); <b>Social Bar</b>: copy the <b>full Social Bar script URL</b> (the src from the Social Bar code). Pick the matching format per unit below. <b>Popunders are never used</b> — only banner / native / social bar.<br>'
        . 'Units on <b>placeholder</b> use the 5-second in-app timer (for testing only — pays no real money). A unit whose provider needs an ID but has none shows users "No ad available right now".'
        . '</div>'
        . '<div class="card"><form method="post"><input type="hidden" name="do" value="save_ads"><table>'
        . '<tr><th>Unit</th><th>Provider</th><th>Adsgram block ID</th><th>Monetag zone ID</th><th>Adsterra key / script URL</th><th>Adsterra format</th><th>Reward (orbit)</th><th>Daily cap</th></tr>';
    $providers = orbit_ad_providers();
    $afmts = ['banner' => 'Banner', 'native' => 'Native', 'socialbar' => 'Social Bar'];
    foreach (['ad1' => 'AD #1', 'ad2' => 'AD #2', 'ad3' => 'AD #3', 'ad4' => 'AD #4'] as $u => $label) {
        $cur = orbit_setting($pdo, $u . '_provider', 'placeholder');
        $body .= '<tr><td><b>' . $label . '</b></td><td><select name="' . $u . '_provider">';
        foreach ($providers as $p) {
            $body .= '<option value="' . $p . '"' . ($cur === $p ? ' selected' : '') . '>' . $p . '</option>';
        }
        $body .= '</select></td>'
            . '<td><input name="' . $u . '_block_id" value="' . admin_h(orbit_setting($pdo, $u . '_block_id', '')) . '" size="10" placeholder="e.g. 123"></td>'
            . '<td><input name="' . $u . '_monetag_zone" value="' . admin_h(orbit_setting($pdo, $u . '_monetag_zone', '')) . '" size="10" placeholder="zone id"></td>'
            . '<td><input name="' . $u . '_adsterra_key" value="' . admin_h(orbit_setting($pdo, $u . '_adsterra_key', '')) . '" size="18" placeholder="key or script URL"></td>'
            . '<td><select name="' . $u . '_adsterra_format">';
        $curf = orbit_setting($pdo, $u . '_adsterra_format', 'banner');
        foreach ($afmts as $fv => $fl) {
            $body .= '<option value="' . $fv . '"' . ($curf === $fv ? ' selected' : '') . '>' . $fl . '</option>';
        }
        $body .= '</select></td>'
            . '<td><input name="' . $u . '_reward" type="number" min="0" value="' . orbit_setting_int($pdo, $u . '_reward', 50) . '" style="width:70px"></td>'
            . '<td><input name="' . $u . '_cap" type="number" min="0" value="' . orbit_setting_int($pdo, $u . '_cap', 5) . '" style="width:60px"></td></tr>';
    }
    $body .= '</table><table>'
        . '<tr><td><b>Giga.pub project ID</b> (one for all units)</td>'
        . '<td><input name="giga_project_id" value="' . admin_h(orbit_setting($pdo, 'giga_project_id', '')) . '" size="24" placeholder="paste from giga.pub dashboard"></td></tr>'
        . '<tr><td><b>Monetag SDK script URL</b> (exact src from your Monetag integration tab)</td>'
        . '<td><input name="monetag_sdk_src" value="' . admin_h(orbit_setting($pdo, 'monetag_sdk_src', '')) . '" size="40" placeholder="https://…/sdk.js"></td></tr>'
        . '<tr><td><b>Adsterra invoke domain</b> (from your Adsterra ad code; change only if yours differs)</td>'
        . '<td><input name="adsterra_invoke_domain" value="' . admin_h(orbit_setting($pdo, 'adsterra_invoke_domain', 'www.highperformanceformat.com')) . '" size="32"></td></tr>'
        . '</table>'
        . '<button type="submit">Save ad settings</button></form></div>'
        . '<div class="card" style="color:#9aa6dd;font-size:13px">The server still verifies every credited view: daily caps per unit plus a global cooldown between views — the ad network SDK confirms the view, the server confirms the money. Popunders are never used.</div>';
}

if ($page === 'users') {
    $q = trim((string)($_GET['q'] ?? ''));
    $body .= '<h2>👥 Users</h2><div class="card"><form method="get"><input type="hidden" name="page" value="users">'
        . '<input name="q" placeholder="username or telegram id" value="' . admin_h($q) . '"> <button type="submit">Search</button></form></div>';
    if ($q !== '') {
        $like = '%' . $q . '%';
        $stmt = $pdo->prepare('SELECT * FROM users WHERE username LIKE ? OR CAST(telegram_id AS CHAR) LIKE ? ORDER BY id DESC LIMIT 30');
        $stmt->execute([$like, $like]);
        foreach ($stmt->fetchAll() as $u) {
            $led = $pdo->prepare('SELECT source, amount_orbit, ref, created_at FROM ledger WHERE user_id = ? ORDER BY id DESC LIMIT 10');
            $led->execute([(int)$u['id']]);
            $body .= '<div class="card"><b>@' . admin_h((string)($u['username'] ?? '')) . '</b> (' . $u['telegram_id'] . ') — '
                . number_format((int)$u['balance_orbit']) . ' orbit — ' . ((int)$u['is_banned'] === 1 ? '<b>BANNED</b>' : 'active')
                . '<form method="post" class="inline"> <input type="hidden" name="do" value="user_adjust"><input type="hidden" name="user_id" value="' . $u['id'] . '">'
                . '<input name="delta" type="number" placeholder="+/- orbit" style="width:110px"> <input name="note" placeholder="note" size="12"> <button type="submit">Adjust</button></form> '
                . '<form method="post" class="inline"><input type="hidden" name="do" value="user_ban"><input type="hidden" name="user_id" value="' . $u['id'] . '">'
                . '<input type="hidden" name="ban" value="' . ((int)$u['is_banned'] === 1 ? '0' : '1') . '">'
                . '<button type="submit" class="' . ((int)$u['is_banned'] === 1 ? '' : 'danger') . '">' . ((int)$u['is_banned'] === 1 ? 'Unban' : 'Ban') . '</button></form>'
                . '<table><tr><th>Source</th><th>Amount</th><th>Ref</th><th>When</th></tr>';
            foreach ($led->fetchAll() as $l) {
                $body .= '<tr><td>' . admin_h($l['source']) . '</td><td>' . number_format((int)$l['amount_orbit']) . '</td><td>' . admin_h($l['ref']) . '</td><td>' . admin_h($l['created_at']) . '</td></tr>';
            }
            $body .= '</table></div>';
        }
    }
}

if ($page === 'broadcast') {
    $body .= '<h2>📢 Broadcast</h2><div class="card"><form method="post"><input type="hidden" name="do" value="broadcast">'
        . '<textarea name="text" rows="4" cols="60" placeholder="Message to ALL users..." required></textarea><br><br>'
        . '<button type="submit" onclick="return confirm(\'Send to ALL users?\')">Send broadcast</button></form></div>';
    $rows = $pdo->query('SELECT * FROM broadcasts ORDER BY id DESC LIMIT 10')->fetchAll();
    $body .= '<div class="card"><h3>History</h3><table><tr><th>ID</th><th>Admin</th><th>Text</th><th>Sent</th><th>When</th></tr>';
    foreach ($rows as $b) {
        $body .= '<tr><td>' . $b['id'] . '</td><td>' . admin_h($b['admin']) . '</td><td>' . admin_h((string)$b['text']) . '</td><td>' . $b['sent_count'] . '</td><td>' . admin_h((string)$b['created_at']) . '</td></tr>';
    }
    $body .= '</table></div>';
}

if ($page === 'settings') {
    $body .= '<h2>⚙️ Settings</h2><div class="card"><form method="post"><input type="hidden" name="do" value="save_settings"><table>';
    $fields = [
        'app_name' => 'App name',
        'rate_orbit_per_usd' => 'Orbit per $1',
        'min_withdraw' => 'Min withdrawal (orbit)',
        'fee_xrocket' => 'Fee xRocket (orbit)',
        'fee_usdt' => 'Fee USDT BEP-20 (orbit)',
        'fee_faucetpay' => 'Fee FaucetPay (orbit)',
        'fee_ton' => 'Fee TON (orbit)',
    ];
    foreach ($fields as $k => $label) {
        $body .= '<tr><td>' . $label . '</td><td><input name="' . $k . '" value="' . admin_h(orbit_setting($pdo, $k, '')) . '" size="24"></td></tr>';
    }
    $body .= '</table><button type="submit">Save settings</button></form></div>';
}

if ($page === 'telegram') {
    $token = (string)($env['BOT_TOKEN'] ?? '');
    $webapp = rtrim((string)($env['WEBAPP_URL'] ?? ''), '/');
    $body .= '<h2>🤖 Telegram</h2>';
    $body .= '<div class="card"><div class="small">Bot: <b>' . admin_h((string)($env['BOT_USERNAME'] ?? '(not set)')) . '</b><br>Web App URL: <b>' . admin_h($webapp !== '' ? $webapp : '(not set — add WEBAPP_URL to env)') . '</b><br>Token: <b>' . ($token !== '' ? 'set ✓' : 'MISSING') . '</b></div></div>';
    if ($token !== '') {
        $wi = orbit_tg_send($token, 'getWebhookInfo', []);
        if (is_array($wi['data'] ?? null) && ($wi['data']['ok'] ?? false)) {
            $r = $wi['data']['result'];
            $body .= '<div class="card"><div class="small">Current webhook: <b>' . admin_h((string)($r['url'] ?? '(none)')) . '</b><br>Pending updates: ' . (int)($r['pending_update_count'] ?? 0)
                . '<br>Last error: ' . admin_h((string)($r['last_error_message'] ?? '(none)')) . '</div></div>';
        } else {
            $body .= '<div class="card"><div class="warn">Could not read webhook status (http ' . (int)($wi['code'] ?? 0) . '). The server may not reach Telegram.</div></div>';
        }
    }
    $body .= '<div class="card"><form method="post" style="display:inline"><input type="hidden" name="do" value="tg_set_webhook"><button type="submit">Set webhook to this server</button></form> '
        . '<form method="post" style="display:inline"><input type="hidden" name="do" value="tg_set_menu"><button type="submit">Set “🚀 Open Orbit” menu button</button></form> '
        . '<form method="post" style="display:inline" onsubmit="return confirm(\'Delete the webhook?\')"><input type="hidden" name="do" value="tg_del_webhook"><button type="submit" class="ghost">Delete webhook</button></form>'
        . '<div class="small" style="margin-top:8px">Use after moving hosts or changing the domain. The webhook must point at <b>/bot.php</b> on this server.</div></div>';
}

admin_shell('Admin', $body, $page);
