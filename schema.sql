-- Orbit Earn Bot v1 — database schema (MySQL / MariaDB, utf8mb4).
-- All money movement is integer `orbit` through the `ledger` table.
-- Deploy: import this file once, then configure env.php (never shipped).
-- Price: 100,000 orbit = $1 (stored in settings as rate_orbit_per_usd).

CREATE TABLE IF NOT EXISTS users (
    id            INT UNSIGNED NOT NULL AUTO_INCREMENT PRIMARY KEY,
    telegram_id   BIGINT NOT NULL UNIQUE,
    username      VARCHAR(64) NULL,
    first_name    VARCHAR(128) NULL,
    chat_id       BIGINT NOT NULL DEFAULT 0,
    balance_orbit BIGINT NOT NULL DEFAULT 0,
    referred_by   INT UNSIGNED NULL,
    is_banned     TINYINT NOT NULL DEFAULT 0,
    safety_code         CHAR(6) NULL,              -- Quick Safety Check OTP (3-digit anti-bot code, stored plain: short-lived)
    safety_code_expires DATETIME NULL,
    safety_passed       TINYINT NOT NULL DEFAULT 0, -- 1 after the user passes the Quick Safety Check (+25 orbit once)
    created_at    TIMESTAMP NOT NULL DEFAULT CURRENT_TIMESTAMP,
    INDEX idx_referred_by (referred_by),
    CONSTRAINT fk_users_referrer FOREIGN KEY (referred_by) REFERENCES users (id)
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci;

CREATE TABLE IF NOT EXISTS ledger (
    id           BIGINT UNSIGNED NOT NULL AUTO_INCREMENT PRIMARY KEY,
    user_id      INT UNSIGNED NOT NULL,
    source       VARCHAR(32) NOT NULL,           -- game, ad, task, spin, safety, ref_bonus_join, ref_bonus_active, ref_bonus_withdraw, ref_commission, withdraw, withdraw_refund, admin_adjust
    amount_orbit BIGINT NOT NULL,                -- signed: earnings positive, debits negative
    ref          VARCHAR(128) NOT NULL DEFAULT '',
    ledger_date  DATE NOT NULL,                  -- Asia/Dhaka "orbit day" (starts 06:00)
    created_at   TIMESTAMP NOT NULL DEFAULT CURRENT_TIMESTAMP,
    INDEX idx_ledger_user_date (user_id, ledger_date),
    INDEX idx_ledger_source (source),
    CONSTRAINT fk_ledger_user FOREIGN KEY (user_id) REFERENCES users (id)
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci;

CREATE TABLE IF NOT EXISTS game_runs (
    id          BIGINT UNSIGNED NOT NULL AUTO_INCREMENT PRIMARY KEY,
    user_id     INT UNSIGNED NOT NULL,
    game        VARCHAR(16) NOT NULL,            -- blaster | startap | tictactoe | words
    run_date    DATE NOT NULL,                   -- orbit day
    run_no      TINYINT UNSIGNED NOT NULL,       -- 1..6 within the day
    nonce       VARCHAR(64) NOT NULL,            -- client-generated, anti replay
    score       INT NOT NULL DEFAULT 0,
    taps        INT NOT NULL DEFAULT 0,
    duration_ms INT NOT NULL DEFAULT 0,
    reward_orbit INT NOT NULL DEFAULT 0,
    created_at  TIMESTAMP NOT NULL DEFAULT CURRENT_TIMESTAMP,
    UNIQUE KEY uq_run (user_id, game, run_date, run_no),
    UNIQUE KEY uq_nonce (user_id, nonce),
    CONSTRAINT fk_runs_user FOREIGN KEY (user_id) REFERENCES users (id)
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci;

CREATE TABLE IF NOT EXISTS ad_views (
    id           BIGINT UNSIGNED NOT NULL AUTO_INCREMENT PRIMARY KEY,
    user_id      INT UNSIGNED NOT NULL,
    unit         VARCHAR(8) NOT NULL,            -- ad1 | ad2 | ad3 | ad4
    view_date    DATE NOT NULL,
    view_no      TINYINT UNSIGNED NOT NULL,
    reward_orbit INT NOT NULL DEFAULT 0,
    created_at   TIMESTAMP NOT NULL DEFAULT CURRENT_TIMESTAMP,
    UNIQUE KEY uq_view (user_id, unit, view_date, view_no),
    CONSTRAINT fk_views_user FOREIGN KEY (user_id) REFERENCES users (id)
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci;

CREATE TABLE IF NOT EXISTS tasks (
    id               INT UNSIGNED NOT NULL AUTO_INCREMENT PRIMARY KEY,
    title            VARCHAR(160) NOT NULL,
    type             VARCHAR(16) NOT NULL DEFAULT 'manual',  -- telegram | manual
    channel_username VARCHAR(64) NULL,          -- for type=telegram: verified via getChatMember
    target_url       VARCHAR(255) NULL,
    reward_orbit     INT NOT NULL DEFAULT 100,
    is_active        TINYINT NOT NULL DEFAULT 1,
    sort             INT NOT NULL DEFAULT 0,
    created_at       TIMESTAMP NOT NULL DEFAULT CURRENT_TIMESTAMP
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci;

CREATE TABLE IF NOT EXISTS user_tasks (
    id         BIGINT UNSIGNED NOT NULL AUTO_INCREMENT PRIMARY KEY,
    user_id    INT UNSIGNED NOT NULL,
    task_id    INT UNSIGNED NOT NULL,
    status     VARCHAR(16) NOT NULL DEFAULT 'done',
    created_at TIMESTAMP NOT NULL DEFAULT CURRENT_TIMESTAMP,
    UNIQUE KEY uq_user_task (user_id, task_id),
    CONSTRAINT fk_utask_user FOREIGN KEY (user_id) REFERENCES users (id),
    CONSTRAINT fk_utask_task FOREIGN KEY (task_id) REFERENCES tasks (id)
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci;

CREATE TABLE IF NOT EXISTS referrals (
    id          BIGINT UNSIGNED NOT NULL AUTO_INCREMENT PRIMARY KEY,
    referrer_id INT UNSIGNED NOT NULL,
    referred_id INT UNSIGNED NOT NULL UNIQUE,
    join_paid     TINYINT NOT NULL DEFAULT 0,   -- stage 1: friend joined via link
    active_paid   TINYINT NOT NULL DEFAULT 0,   -- stage 2: friend played & claimed 2+ games
    withdraw_paid TINYINT NOT NULL DEFAULT 0,   -- stage 3: friend's first withdrawal request
    created_at  TIMESTAMP NOT NULL DEFAULT CURRENT_TIMESTAMP,
    INDEX idx_ref_referrer (referrer_id),
    CONSTRAINT fk_ref_referrer FOREIGN KEY (referrer_id) REFERENCES users (id),
    CONSTRAINT fk_ref_referred FOREIGN KEY (referred_id) REFERENCES users (id)
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci;

CREATE TABLE IF NOT EXISTS spins (
    id          BIGINT UNSIGNED NOT NULL AUTO_INCREMENT PRIMARY KEY,
    user_id     INT UNSIGNED NOT NULL,
    spin_date   DATE NOT NULL,
    prize_orbit INT NOT NULL DEFAULT 0,
    created_at  TIMESTAMP NOT NULL DEFAULT CURRENT_TIMESTAMP,
    UNIQUE KEY uq_spin (user_id, spin_date),
    CONSTRAINT fk_spin_user FOREIGN KEY (user_id) REFERENCES users (id)
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci;

CREATE TABLE IF NOT EXISTS withdrawals (
    id            BIGINT UNSIGNED NOT NULL AUTO_INCREMENT PRIMARY KEY,
    user_id       INT UNSIGNED NOT NULL,
    chat_id       BIGINT NOT NULL DEFAULT 0,    -- Telegram chat to notify on decide
    gateway       VARCHAR(16) NOT NULL,         -- xrocket | usdt | faucetpay | ton
    address       VARCHAR(255) NOT NULL,        -- user's RECEIVING address
    amount_orbit  INT NOT NULL,                 -- requested (gross)
    fee_orbit     INT NOT NULL,                 -- gateway fee
    net_orbit     INT NOT NULL,                 -- amount - fee
    status        VARCHAR(16) NOT NULL DEFAULT 'pending',  -- pending | paid | rejected
    proof_path    VARCHAR(255) NULL,            -- uploaded proof image (relative)
    proof_tx      VARCHAR(255) NULL,            -- tx hash / proof text
    reject_reason VARCHAR(255) NULL,
    decided_at    TIMESTAMP NULL,
    created_at    TIMESTAMP NOT NULL DEFAULT CURRENT_TIMESTAMP,
    INDEX idx_wd_status (status),
    INDEX idx_wd_user (user_id),
    CONSTRAINT fk_wd_user FOREIGN KEY (user_id) REFERENCES users (id)
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci;

-- Short-lived bot-chat conversation state for the withdraw flow.
CREATE TABLE IF NOT EXISTS conversations (
    user_id    INT UNSIGNED NOT NULL PRIMARY KEY,
    step       VARCHAR(24) NOT NULL,             -- wd_gateway | wd_address | wd_confirm | wd_code
    data_json  TEXT NOT NULL,
    updated_at TIMESTAMP NOT NULL DEFAULT CURRENT_TIMESTAMP ON UPDATE CURRENT_TIMESTAMP,
    CONSTRAINT fk_conv_user FOREIGN KEY (user_id) REFERENCES users (id)
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci;

-- One-time codes for Mini App withdrawal confirmation (bot sends the code to the user's chat).
CREATE TABLE IF NOT EXISTS withdraw_otps (
    id           BIGINT UNSIGNED NOT NULL AUTO_INCREMENT PRIMARY KEY,
    user_id      INT UNSIGNED NOT NULL UNIQUE,
    gateway      VARCHAR(16) NOT NULL,
    address      VARCHAR(255) NOT NULL,
    amount_orbit INT NOT NULL,
    code         CHAR(6) NOT NULL,
    expires_at   DATETIME NOT NULL,
    created_at   TIMESTAMP NOT NULL DEFAULT CURRENT_TIMESTAMP,
    CONSTRAINT fk_wotp_user FOREIGN KEY (user_id) REFERENCES users (id)
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci;

CREATE TABLE IF NOT EXISTS broadcasts (
    id         BIGINT UNSIGNED NOT NULL AUTO_INCREMENT PRIMARY KEY,
    admin      VARCHAR(64) NOT NULL DEFAULT '',
    text       TEXT NOT NULL,
    sent_count INT NOT NULL DEFAULT 0,
    created_at TIMESTAMP NOT NULL DEFAULT CURRENT_TIMESTAMP
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci;

CREATE TABLE IF NOT EXISTS admin_users (
    id         INT UNSIGNED NOT NULL AUTO_INCREMENT PRIMARY KEY,
    username   VARCHAR(64) NOT NULL UNIQUE,
    pass_hash  VARCHAR(255) NOT NULL,           -- password_hash()
    created_at TIMESTAMP NOT NULL DEFAULT CURRENT_TIMESTAMP
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci;

CREATE TABLE IF NOT EXISTS settings (
    skey   VARCHAR(64) NOT NULL PRIMARY KEY,
    svalue VARCHAR(255) NOT NULL DEFAULT ''
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci;

-- ---- Default settings (admin can change all of these in the panel) ----
INSERT INTO settings (skey, svalue) VALUES
    ('rate_orbit_per_usd', '100000'),
    ('min_withdraw', '2000'),
    ('fee_xrocket', '100'),
    ('fee_usdt', '100'),
    ('fee_faucetpay', '50'),
    ('fee_ton', '100'),
    ('game_runs_per_day', '6'),
    ('game_day_start_hour', '6'),
    ('ad_cooldown_sec', '45'),
    ('ad1_reward', '145'), ('ad1_cap', '7'),
    ('ad2_reward', '84'),  ('ad2_cap', '5'),
    ('ad3_reward', '55'),  ('ad3_cap', '10'),
    ('ad4_reward', '62'),  ('ad4_cap', '5'),
    ('ad1_provider', 'placeholder'), ('ad1_block_id', ''), ('ad1_monetag_zone', ''), ('ad1_adsterra_key', ''), ('ad1_adsterra_format', 'banner'),
    ('ad2_provider', 'placeholder'), ('ad2_block_id', ''), ('ad2_monetag_zone', ''), ('ad2_adsterra_key', ''), ('ad2_adsterra_format', 'banner'),
    ('ad3_provider', 'placeholder'), ('ad3_block_id', ''), ('ad3_monetag_zone', ''), ('ad3_adsterra_key', ''), ('ad3_adsterra_format', 'banner'),
    ('ad4_provider', 'placeholder'), ('ad4_block_id', ''), ('ad4_monetag_zone', ''), ('ad4_adsterra_key', ''), ('ad4_adsterra_format', 'banner'),
    ('giga_project_id', ''),
    ('monetag_sdk_src', ''),
    ('adsterra_invoke_domain', 'www.highperformanceformat.com'),
    ('spin_prizes', '50,75,100,150,250'),
    ('spin_weights', '40,30,15,10,5'),
    ('ref_bonus_join', '200'),
    ('ref_bonus_active', '500'),
    ('ref_bonus_withdraw', '300'),
    ('ref_commission_pct', '5'),
    ('safety_bonus', '25'),
    ('app_name', 'Orbit Earn Bot')
ON DUPLICATE KEY UPDATE svalue = VALUES(svalue);

-- Sample tasks (inactive until the owner configures real channels).
INSERT INTO tasks (title, type, channel_username, target_url, reward_orbit, is_active, sort) VALUES
    ('Join our announcement channel', 'telegram', NULL, NULL, 300, 0, 1),
    ('Join our community group', 'telegram', NULL, NULL, 200, 0, 2),
    ('Follow us on X', 'manual', NULL, 'https://x.com/', 200, 0, 3);
