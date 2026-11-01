# Orbit Earn Bot v1

An original Telegram earn bot (English UI, space/"orbit" theme). Its *mechanics*
were learned from hands-on analysis of two reference earn bots — nothing is
copied: no text, no names, no design, no artwork.

**Currency:** `orbit` (integer only). 100,000 orbit = $1.

## What v1 includes

- **Telegram bot** (`bot.php`, webhook): /start with referral attribution,
  "Open Orbit" web-app button, reply keyboard (Balance | Referral | Withdraw),
  guided withdraw conversation (gateway → receiving address → amount).
- **Mini App** (`index.html`, `app.js`, `style.css`): 5 tabs — Home (balance +
  daily lucky-wheel spin), Earn (Watch & Earn ad units + tasks), Play (4 games),
  Friends (referral link + stats), Me (profile + withdraw + history).
- **4 original games** (`games/`): Orbit Blaster, Star Tap, Cosmic Tic Tac Toe,
  Nebula Words. 6 paid runs per game per day (orbit day starts 06:00
  Asia/Dhaka), 100–200 orbit per run by score. Server-side anti-cheat rejects
  impossible scores, durations, tap rates (>12/sec), and duplicate submissions.
- **Watch & Earn**: 4 ad units with daily caps (AD1 +145×7, AD2 +84×5,
  AD3 +55×10, AD4 +62×5) + a 45s server-side cooldown between credited views.
  **Real ad SDKs are wired** (owner's decision): per-unit provider —
  **Adsgram** or **Giga.pub** — configured in Admin → Ads. Each unit plays
  the network's rewarded ad; the orbit credit fires only after the SDK's
  completion callback AND the server-side cap/cooldown check. Units stay on
  a placeholder in-app timer until the owner pastes real IDs.
- **Tasks**: admin-configurable; Telegram-channel tasks are verified via Bot API
  `getChatMember`; manual tasks are claimable as marked by the admin.
- **Referral (3 stages, total 1,000 orbit)**: +200 when a friend joins via
  your link, +500 when they play & claim 2 games, +300 on their first
  withdrawal; +5% of the friend's game/ad/task/spin earnings credited
  automatically, forever, no recursion. Withdrawals unlock only after at
  least one invited friend becomes active (played 2 games).
- **Quick Safety Check (anti-bot)**: before the first-ever earning action,
  the bot sends a 3-digit code to the user's chat; entering it in the app
  grants +25 orbit and marks the account human. All earning actions
  (games, ads, tasks, spin, withdraw) require a passed check.
- **Lucky wheel**: 1 free spin/day, prizes 50–250 orbit (weighted).
- **Tasks — Go → Check → DONE**: tapping Go opens the channel and the
  button becomes Check; Check runs a live getChatMember verification and,
  on success, credits instantly with a DONE badge. "No ad available right
  now" shows when an ad unit's daily cap is exhausted.
- **Withdrawal — MANUAL + OTP confirm** (owner's rule): user picks a gateway
  (xRocket / USDT BEP-20 / FaucetPay / TON), enters their receiving address,
  then the bot sends a 6-digit code to their chat — entering it submits the
  request as `pending`. Minimum 2,000 orbit ($0.02); per-gateway fee
  configurable. Balance is deducted on confirm and refunded automatically
  on reject. The admin panel shows each request with a one-click
  **Copy address** button; the admin sends the payment manually, uploads
  proof (photo and/or tx hash), and approves (status `paid`) or rejects
  with a reason (status `rejected`).
- **Approve/reject notifications** (owner's rule): every decision sends the user
  a bot message automatically — nothing completes silently. Approve =
  "Withdrawal Successful!" with amount (orbit + USD), gateway, receiving
  address, proof photo and/or tx hash. Reject = reason + refund note.
- **Admin panel** (`admin/`): separate login (username + `password_hash`);
  dashboard (users, active today, orbit issued, pending/paid withdrawals);
  withdrawals (copy-address, proof upload, approve/reject + auto-notify);
  tasks CRUD; games & ads economy config; users (search, balance adjust with
  ledger row, ban/unban); broadcast to all users; settings (rate, min
  withdraw, fees).
- **Security**: Telegram WebApp initData HMAC-SHA256 verification, fail-closed
  when BOT_TOKEN is unset; all money moves through the `ledger` table;
  no secrets in code (`env.example.php` only).

## Ad monetization (Adsgram + Giga.pub + Monetag + Adsterra)

Owner's 2026-10-31 decisions: the bot earns with **Adsgram**, **Giga.pub**,
**Monetag** and **Adsterra** ad networks, like the reference bots. **No
popunders ever** — only rewarded / banner / native / social-bar formats
(owner's standing rule). The SDKs are wired in `app.js`; what is still
needed is the OWNER's publisher accounts and IDs — no real block IDs,
zone IDs, keys or tokens are stored in this codebase (placeholders only).

### How it works

- Each ad unit (AD1–AD4) has a **provider** (`placeholder` | `adsgram` |
  `gigapub` | `monetag` | `adsterra`), stored in the `settings` table and
  editable in **Admin → Ads**.
  - Adsgram → per-unit **Block ID**; Giga.pub → one **Project ID** for all
    units; Monetag → per-unit main **Zone ID** + one global **SDK script
    URL** (exact src from your Monetag integration tab); Adsterra →
    per-unit **placement key** (banner/native) or full **script URL**
    (Social Bar) + a per-unit **format** choice (Banner / Native /
    Social Bar) + one global **invoke domain**.
- Default is `placeholder`: a 5-second in-app timer (for testing only —
  pays no real money). A unit whose provider needs an ID but has none
  configured shows users **"No ad available right now"**, same as when its
  daily cap is exhausted.
- When a unit is on a real provider, the Mini App lazy-loads the network's
  SDK and shows the ad (rewarded interstitial for Adsgram/Giga.pub/Monetag;
  timed banner/native/social-bar view for Adsterra). The orbit credit fires
  only after **both**: (1) the SDK's reward/completion callback (or the
  fixed view duration for Adsterra display formats), and (2) the existing
  server-side `ad_view` check (per-unit daily cap + 45s global cooldown).
  The client is never trusted with money — exactly like the reference bots'
  "watch to the end" rule.

### Official integration snippets (for reference)

Adsgram rewarded (docs: https://docs.adsgram.ai/publisher/reward-interstitial-integration):
```html
<script src="https://sad.adsgram.ai/js/sad.min.js"></script>
```
```js
const AdController = window.Adsgram.init({ blockId: "123" }); // your Block ID
AdController.show().then((result) => {
  // user watched till the end -> ask the backend to grant the reward
}).catch(() => { /* no reward */ });
```
Get the Block ID at https://partner.adsgram.ai: create account → Create
**Ad Platform** → Create **Ad unit** of type **Reward** → Start (moderation)
→ copy the **Block ID** (digits, e.g. `123`). Never enable debug mode on a
production block — test ads pay nothing and never fire the reward callback.

Giga.pub rewarded (docs: https://docs.giga.pub/integration-guide.html):
```html
<script src="https://ad.gigapub.tech/script?id=YOUR_PROJECT_ID"></script>
```
```js
window.showGiga()
  .then(() => { /* user watched -> ask the backend to grant the reward */ })
  .catch(() => { /* no reward */ });
```
Get the Project ID from the Giga.pub publisher dashboard (giga.pub) and
paste it once in Admin → Ads.

Monetag rewarded (docs: https://docs.monetag.com/docs/sdk-reference/):
```html
<script src="YOUR_SDK_URL_FROM_MONETAG_DASHBOARD"
        data-zone="123456" data-sdk="show_123456"></script>
```
```js
window.show_123456({ type: 'end', requestVar: 'ad1', timeout: 10 })
  .then((result) => {
    // 'end' = Rewarded Interstitial, resolves after the ad is closed;
    // on reward zones Monetag first confirms the event.
    if (!result || !result.reward_event_type || result.reward_event_type === 'valued') {
      // user watched -> ask the backend to grant the reward
    }
  })
  .catch(() => { /* no reward — e.g. 'Empty feed' */ });
```
Get the main **Zone ID** (digits) and the exact **SDK script URL** from the
Monetag publisher dashboard (monetag.com → create a Telegram Mini App
zone of type Rewarded Interstitial → integration tab). Always use the
**main** zone ID, never a sub-zone. We never use `type: 'pop'`
(popunders) or auto `inApp` interstitials.

Adsterra banner/native (mild formats only — never popunders):
```html
<script type="text/javascript">
  atOptions = {
    'key': 'YOUR_PLACEMENT_KEY',   /* from the publisher panel ad code */
    'format': 'iframe',
    'height': 300,
    'width': 320,
    'params': {}
  };
</script>
<script type="text/javascript"
        src="https://www.highperformanceformat.com/YOUR_PLACEMENT_KEY/invoke.js"></script>
```
Adsterra Social Bar ships as a single script URL instead:
```html
<script type="text/javascript" src="https://YOUR_SOCIALBAR_SCRIPT_URL.js"></script>
```
Get both from the Adsterra publisher panel (partners.adsterra.com →
Websites → add/approve your site → create a **Banner**, **Native Banner**
or **Social Bar** ad unit → copy the key / script URL). In Admin → Ads
pick the matching format per unit (Banner / Native / Social Bar). The
mini app renders banner/native in a 10-second timed modal and Social Bar
via its own script, then asks the backend to grant the reward.

### Honest note

- SDKs are **wired**, but real earnings start only when the owner finishes
  the steps above and pastes the IDs in Admin → Ads. Until then units earn
  nothing real.
- Ad revenue + the owner's reserve must cover the 100,000 orbit = $1
  liability. There is no earning guarantee anywhere in the UI.

## Honest v1 limits (not hidden)

- **Payouts are manual** — no auto-payouts, no payment API integration.
- **Owner funding**: 100,000 orbit = $1 of real liability. Ad revenue +
  the owner's reserve must cover payouts; issuance alone creates no money.
  There is no earning guarantee anywhere in the UI.
- **Deploy needs**: a BotFather token (owner pastes into `env.php`),
  MySQL import of `schema.sql`, webhook set to `bot.php`, WEBAPP_URL set,
  and one admin user created (`INSERT INTO admin_users` with
  `password_hash()`).

## Layout

```
schema.sql  config.php  env.example.php  api.php  bot.php
index.html  app.js  style.css
games/      blaster.js  startap.js  tictactoe.js  words.js
admin/      index.php  logout.php  panel.php  common.php
uploads/proofs/   (created at runtime by the admin panel)
design/     (mockups — not part of the app)
test_logic.py  README.md
```

## Tests

`python3 test_logic.py` — 112 checks mirroring the PHP rules: orbit-day
boundary, money math, game rewards, anti-cheat rejections, daily caps, ad
caps + cooldown, ad-unit provider availability (placeholder / Adsgram /
Giga.pub, misconfigured units unavailable), safety-check gating (+25 bonus,
wrong-code rejection),
referral stages (join 200 / active 500 on 2 games / withdraw 300, each paid
once; total still 1,000), 5% commission (incl. no-recursion and no commission
on the safety bonus), spin limits, withdraw OTP two-step (wrong code, gateway
binding, single-use, no deduction before confirm), withdraw-requires-active-
friend, withdraw min/fee math. Must be 100% green.
