<?php
// Orbit Earn Bot v1 — environment template. COPY to env.php and fill in.
// env.php is NEVER committed, never shipped in a zip, never pasted in chat.
// BOT_TOKEN comes from @BotFather; the owner pastes it at deploy time.
declare(strict_types=1);

return [
    'DB_HOST' => 'localhost',
    'DB_NAME' => 'orbit_earn',
    'DB_USER' => '',
    'DB_PASS' => '',

    // Telegram bot (from @BotFather). EMPTY until the owner configures it.
    'BOT_TOKEN' => '',
    'BOT_USERNAME' => '',          // e.g. orbit_earn_bot (no @)
    'WEBAPP_URL' => '',            // e.g. https://example.com/index.html

    // Optional: owner's Telegram user id — receives new-withdrawal alerts.
    'ADMIN_TELEGRAM_ID' => '',
];
