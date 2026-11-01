/* Orbit Earn Bot v1 — Mini App logic. All earnings verified server-side. */
(function () {
  'use strict';
  const tg = window.Telegram && window.Telegram.WebApp ? window.Telegram.WebApp : null;
  const initData = tg ? tg.initData : '';
  if (tg) { tg.ready(); tg.expand(); }

  const view = document.getElementById('view');
  const toastEl = document.getElementById('toast');
  let S = null; // summary

  function toast(msg) {
    toastEl.textContent = msg;
    toastEl.style.display = 'block';
    setTimeout(() => { toastEl.style.display = 'none'; }, 2600);
  }
  function esc(s) {
    return String(s == null ? '' : s).replace(/[&<>"']/g, c => ({ '&': '&amp;', '<': '&lt;', '>': '&gt;', '"': '&quot;', "'": '&#39;' }[c]));
  }
  async function api(action, extra) {
    const res = await fetch('api.php', {
      method: 'POST',
      headers: { 'Content-Type': 'application/json' },
      body: JSON.stringify(Object.assign({ action: action, initData: initData }, extra || {})),
    });
    const data = await res.json();
    if (!data.ok) {
      if (String(data.error || '').indexOf('safety_required') === 0) showSafetyDialog();
      throw new Error(data.error || 'request failed');
    }
    return data;
  }
  function nonce() {
    return Date.now().toString(36) + Math.random().toString(36).slice(2, 12);
  }

  // ---------- Ad SDKs (owner's decision: Adsgram + Giga.pub + Monetag + Adsterra) ----------
  // SDKs are loaded lazily — only when a unit actually uses them.
  // Credit NEVER happens here: the reward fires only after the SDK's
  // reward callback AND the server-side ad_view check (caps + cooldown).
  // No popunders anywhere — only rewarded / banner / native / social-bar.
  function loadScript(src) {
    return new Promise((resolve, reject) => {
      if (document.querySelector('script[src="' + src + '"]')) { resolve(); return; }
      const s = document.createElement('script');
      s.src = src; s.async = true;
      s.onload = () => resolve();
      s.onerror = () => reject(new Error('sdk_load_failed'));
      document.head.appendChild(s);
    });
  }

  const monetagWaiters = {};
  function loadMonetag(zone, sdkSrc) {
    // Official Monetag TMA SDK (docs.monetag.com/docs/sdk-reference/):
    //   <script src="SDK_URL" data-zone="ZONE" data-sdk="show_ZONE"></script>
    // then window['show_ZONE']({ type: 'end' }) -> Promise.
    // type 'end' = Rewarded Interstitial; the promise resolves after the
    // ad is closed, and on reward zones only after Monetag confirms the
    // event (else it rejects, e.g. 'Failed to verify the ad show').
    // The exact SDK script URL comes from the owner's Monetag dashboard.
    return new Promise((resolve, reject) => {
      const fn = 'show_' + zone;
      if (window[fn]) { resolve(); return; }
      if (monetagWaiters[zone]) { monetagWaiters[zone].push({ resolve, reject }); return; }
      monetagWaiters[zone] = [{ resolve, reject }];
      const settle = (ok, err) => {
        const ws = monetagWaiters[zone] || [];
        delete monetagWaiters[zone];
        ws.forEach(w => ok ? w.resolve() : w.reject(err || new Error('sdk_load_failed')));
      };
      const s = document.createElement('script');
      s.src = sdkSrc;
      s.setAttribute('data-zone', zone);
      s.setAttribute('data-sdk', fn);
      s.async = true;
      s.onload = () => settle(!!window[fn]);
      s.onerror = () => settle(false);
      document.head.appendChild(s);
    });
  }

  // Adsterra mild formats only (never popunders). Banner/native use the
  // standard ad code: window.atOptions = { key, format:'iframe', ... }
  // then https://<invokeDomain>/<key>/invoke.js renders the creative.
  // We show it in a modal for a fixed view duration; the orbit credit
  // still requires the server-side ad_view check (caps + cooldown).
  function showAdsterraDisplay(key, invokeDomain, secs) {
    return new Promise((resolve, reject) => {
      const ov = document.createElement('div');
      ov.className = 'modal';
      ov.innerHTML = '<div class="modal-card" style="text-align:center">'
        + '<div class="small">Sponsored — please watch <b id="as-t">' + secs + 's</b></div>'
        + '<div id="as-slot" style="margin:10px auto;min-height:90px;display:flex;align-items:center;justify-content:center;overflow:hidden"></div>'
        + '<button class="btn ghost" id="as-close">Close (no reward)</button></div>';
      document.body.appendChild(ov);
      let done = false, t = null;
      const finish = (ok, err) => {
        if (done) return; done = true;
        if (t) clearInterval(t);
        ov.remove();
        ok ? resolve() : reject(err || new Error('ad_closed'));
      };
      document.getElementById('as-close').onclick = () => finish(false);
      window.atOptions = { key: key, format: 'iframe', height: 300, width: 320, params: {} };
      const s = document.createElement('script');
      s.src = 'https://' + invokeDomain + '/' + encodeURIComponent(key) + '/invoke.js';
      s.async = true;
      s.onerror = () => finish(false, new Error('sdk_load_failed'));
      document.getElementById('as-slot').appendChild(s);
      let left = secs;
      t = setInterval(() => {
        left--;
        const el = document.getElementById('as-t');
        if (el) el.textContent = left + 's';
        if (left <= 0) finish(true);
      }, 1000);
    });
  }

  // Adsterra Social Bar: ships as a full script URL from the publisher
  // panel and renders itself (corner notification style). Same fixed view
  // duration + server verification as the banner flow.
  function showAdsterraSocialBar(scriptUrl, secs) {
    return new Promise((resolve, reject) => {
      const ov = document.createElement('div');
      ov.className = 'modal';
      ov.innerHTML = '<div class="modal-card" style="text-align:center">'
        + '<div class="small">Sponsored message — please wait <b id="asb-t">' + secs + 's</b></div>'
        + '<button class="btn ghost" id="asb-close">Close (no reward)</button></div>';
      document.body.appendChild(ov);
      let done = false, t = null;
      const finish = (ok, err) => {
        if (done) return; done = true;
        if (t) clearInterval(t);
        ov.remove();
        ok ? resolve() : reject(err || new Error('ad_closed'));
      };
      document.getElementById('asb-close').onclick = () => finish(false);
      const s = document.createElement('script');
      s.src = scriptUrl;
      s.async = true;
      s.onerror = () => finish(false, new Error('sdk_load_failed'));
      document.head.appendChild(s);
      let left = secs;
      t = setInterval(() => {
        left--;
        const el = document.getElementById('asb-t');
        if (el) el.textContent = left + 's';
        if (left <= 0) finish(true);
      }, 1000);
    });
  }
  const adsgramControllers = {};
  function adsgramController(blockId) {
    // window.Adsgram.init is idempotent per blockId — one controller each.
    if (!adsgramControllers[blockId]) {
      adsgramControllers[blockId] = window.Adsgram.init({ blockId: blockId });
    }
    return adsgramControllers[blockId];
  }
  function loadGiga(projectId) {
    return new Promise((resolve, reject) => {
      if (window.showGiga) { resolve(); return; }
      const s = document.createElement('script');
      s.src = 'https://ad.gigapub.tech/script?id=' + encodeURIComponent(projectId);
      s.async = true;
      s.onload = () => (window.showGiga ? resolve() : reject(new Error('sdk_load_failed')));
      s.onerror = () => reject(new Error('sdk_load_failed'));
      document.head.appendChild(s);
    });
  }

  // ---------- Quick Safety Check (anti-bot) ----------
  function showSafetyDialog() {
    if (document.getElementById('safety-modal')) return;
    const m = document.createElement('div');
    m.id = 'safety-modal';
    m.className = 'modal';
    m.innerHTML = '<div class="modal-card"><h3>🛡️ Quick Safety Check</h3>'
      + '<div class="small">Tap "Send code" — we will message a 3-digit code to your bot chat. Enter it here to prove you are human and earn <b>+25 orbit</b>.</div>'
      + '<div style="margin-top:10px"><button class="btn" id="sf-send">Send code to my chat</button></div>'
      + '<div style="display:flex;gap:8px;margin-top:8px"><input id="sf-code" inputmode="numeric" placeholder="3-digit code" maxlength="3" style="flex:1">'
      + '<button class="btn" id="sf-verify">Verify</button></div>'
      + '<div id="sf-res" class="small" style="margin-top:8px"></div>'
      + '<button class="btn ghost" id="sf-close" style="margin-top:8px">Close</button></div>';
    document.body.appendChild(m);
    document.getElementById('sf-close').onclick = () => m.remove();
    document.getElementById('sf-send').onclick = async (e) => {
      e.target.disabled = true;
      try {
        await api('safety_send');
        document.getElementById('sf-res').textContent = 'Code sent! Check your bot chat (it expires in 10 minutes).';
      } catch (err) { document.getElementById('sf-res').textContent = err.message; e.target.disabled = false; }
    };
    document.getElementById('sf-verify').onclick = async () => {
      const code = document.getElementById('sf-code').value.trim();
      try {
        const r = await api('safety_verify', { code: code });
        m.remove();
        toast('✅ Verified! +' + r.bonus_orbit + ' orbit earned.');
        await refresh();
      } catch (err) { document.getElementById('sf-res').textContent = err.message; }
    };
  }

  async function refresh() {
    S = await api('summary');
    return S;
  }

  // ---------------- tabs ----------------
  function renderHome() {
    const u = S.user;
    view.innerHTML =
      '<h2>🪐 Orbit Earn</h2>'
      + '<div class="card bal-card"><div class="planet">🪐</div>'
      + '<div class="bal-num">' + Number(u.balance_orbit).toLocaleString() + '</div>'
      + '<div class="small">ORBIT</div><div class="bal-usd">≈ $' + u.balance_usd + '</div></div>'
      + '<div class="row">'
      + '<div class="stat"><b>' + u.referral_count + '</b><span>referrals</span></div>'
      + '<div class="stat"><b>' + u.pending_withdrawals + '</b><span>pending payout</span></div>'
      + '<div class="stat"><b>' + (S.spin_done_today ? '0' : '1') + '</b><span>free spin</span></div>'
      + '</div>'
      + '<div class="card"><h3>🎡 Lucky Wheel</h3>'
      + (S.spin_done_today
        ? '<div class="small">Free spin used today. Come back tomorrow!</div>'
        : '<div class="small">One free spin per day. Prizes up to 250 orbit.</div><button class="btn big" id="spin-btn">SPIN NOW</button>')
      + '<div id="spin-res"></div></div>';
    const sb = document.getElementById('spin-btn');
    if (sb) sb.onclick = async () => {
      sb.disabled = true;
      try {
        const r = await api('spin');
        document.getElementById('spin-res').innerHTML = '<div class="notice">🎉 You won <b>+' + r.prize_orbit + ' orbit</b>!</div>';
        await refresh(); setTab('home');
      } catch (e) { toast(e.message); sb.disabled = false; }
    };
  }

  function renderEarn() {
    let h = '<h2>📺 Earn</h2><h3>Watch &amp; Earn</h3><div class="small">Watch an ad to the end to earn orbit. Views are verified server-side.</div>';
    const labels = { ad1: 'AD #1', ad2: 'AD #2', ad3: 'AD #3', ad4: 'AD #4' };
    for (const k of Object.keys(labels)) {
      const a = S.ads[k];
      h += '<div class="adunit"><div><div class="t">' + labels[k] + '</div>'
        + '<div class="r">+' + a.reward + ' orbit</div><div class="small">' + a.used + '/' + a.cap + ' watched</div></div>'
        + (a.available ? '<button class="btn" data-ad="' + k + '">Watch</button>' : '<span class="done">No ad available right now</span>') + '</div>';
    }
    h += '<h3>Tasks</h3><div id="tasks"><div class="small">Loading…</div></div>';
    view.innerHTML = h;
    view.querySelectorAll('[data-ad]').forEach(b => {
      b.onclick = () => watchAd(b.getAttribute('data-ad'), b);
    });
    api('tasks_list').then(r => {
      const el = document.getElementById('tasks');
      if (!r.tasks.length) { el.innerHTML = '<div class="small">No tasks right now. Check back soon!</div>'; return; }
      el.innerHTML = '';
      r.tasks.forEach(t => {
        const d = document.createElement('div');
        d.className = 'task';
        const done = (t.done === 1 || t.done === '1');
        // Task flow: Go → Check/Verify → DONE (membership checked live on the server).
        let btn;
        if (done) btn = '<span class="done">DONE ✓</span>';
        else if (t.type === 'telegram') btn = '<button class="btn" data-go="' + t.id + '">Go</button>';
        else btn = '<button class="btn" data-claim="' + t.id + '">Claim</button>';
        d.innerHTML = '<div><div class="t">' + esc(t.title) + '</div><div class="r">+' + t.reward_orbit + ' orbit</div></div>'
          + '<span>' + btn + '</span>';
        el.appendChild(d);
      });
      const bindCheck = (b) => {
        b.onclick = async () => {
          b.disabled = true;
          try {
            const r2 = await api('task_claim', { task_id: parseInt(b.getAttribute('data-check'), 10) });
            toast('+' + r2.reward_orbit + ' orbit earned!');
            await refresh(); renderEarn();
          } catch (e) {
            if (String(e.message).indexOf('channel_join_required') === 0) toast('Please join the channel first, then tap Check.');
            else toast(e.message);
            b.disabled = false;
          }
        };
      };
      el.querySelectorAll('[data-go]').forEach(b => {
        b.onclick = () => {
          const id = b.getAttribute('data-go');
          const t = r.tasks.find(x => String(x.id) === String(id)) || {};
          const url = t.target_url || (t.channel_username ? 'https://t.me/' + String(t.channel_username).replace(/^@/, '') : '');
          if (url) { if (tg && tg.openTelegramLink) tg.openTelegramLink(url); else if (tg) tg.openLink(url); else window.open(url, '_blank'); }
          b.textContent = 'Check';
          b.removeAttribute('data-go');
          b.setAttribute('data-check', id);
          bindCheck(b);
        };
      });
      el.querySelectorAll('[data-claim]').forEach(b => {
        b.onclick = async () => {
          b.disabled = true;
          try {
            const r2 = await api('task_claim', { task_id: parseInt(b.getAttribute('data-claim'), 10) });
            toast('+' + r2.reward_orbit + ' orbit earned!');
            await refresh(); renderEarn();
          } catch (e) { toast(e.message); b.disabled = false; }
        };
      });
    }).catch(e => { document.getElementById('tasks').innerHTML = '<div class="warn">' + esc(e.message) + '</div>'; });
  }

  // Watch flow per ad unit. The unit's provider (Admin > Ads) decides
  // what plays; the reward is credited only after BOTH the ad SDK's
  // completion callback AND the server-side ad_view check (daily cap
  // + 45s cooldown). Placeholder = the old 5s in-app timer, kept for
  // testing and for units whose real IDs are not pasted yet.
  async function watchAd(unit, btn) {
    const cfg = S.ads[unit] || {};
    if (cfg.provider === 'placeholder') {
      watchAdPlaceholder(unit, btn);
      return;
    }
    btn.disabled = true;
    btn.textContent = 'Loading ad…';
    try {
      if (cfg.provider === 'adsgram') {
        // Official Adsgram rewarded integration:
        // <script src="https://sad.adsgram.ai/js/sad.min.js"></script>
        // window.Adsgram.init({ blockId }) -> controller.show()
        // The promise resolves only when the ad is watched to the end.
        await loadScript('https://sad.adsgram.ai/js/sad.min.js');
        await adsgramController(cfg.block_id).show();
      } else if (cfg.provider === 'gigapub') {
        // Official Giga.pub integration:
        // <script src="https://ad.gigapub.tech/script?id=PROJECT_ID"></script>
        // window.showGiga() -> promise resolves on a completed view.
        await loadGiga(cfg.giga_project_id);
        await window.showGiga();
      } else if (cfg.provider === 'monetag') {
        // Official Monetag TMA SDK (docs.monetag.com/docs/sdk-reference/):
        // window['show_ZONE']({ type: 'end' }) shows a Rewarded
        // Interstitial; the promise resolves after the ad is closed, and
        // on reward zones only after Monetag confirms the event. A
        // 'non_valued' result means the view was not monetized -> no credit.
        await loadMonetag(cfg.monetag_zone, cfg.monetag_sdk_src);
        const res = await window['show_' + cfg.monetag_zone]({
          type: 'end', requestVar: unit, timeout: 10
        });
        if (res && res.reward_event_type && res.reward_event_type !== 'valued') {
          throw new Error('ad_not_valued');
        }
      } else if (cfg.provider === 'adsterra') {
        // Adsterra mild formats only (never popunders): banner/native show
        // the standard atOptions + invoke.js creative in a timed modal;
        // Social Bar loads its own script URL. The fixed view duration is
        // the "completion callback" here — the server ad_view check still
        // gates the credit.
        if (cfg.adsterra_format === 'socialbar') {
          await showAdsterraSocialBar(cfg.adsterra_key, 10);
        } else {
          await showAdsterraDisplay(cfg.adsterra_key, cfg.adsterra_invoke_domain, 10);
        }
      } else {
        throw new Error('ad_provider_unavailable');
      }
      // Ad SDK confirmed a full view — now the server verifies caps/cooldown.
      const r = await api('ad_view', { unit: unit });
      toast('+' + r.reward_orbit + ' orbit earned!');
      await refresh(); renderEarn();
    } catch (e) {
      const msg = String((e && e.message) || e);
      if (/sdk_load_failed/.test(msg)) toast('Ad failed to load. Please try again later.');
      else if (/ad_closed/.test(msg)) toast('Ad closed early — no reward.');
      else if (/ad_cap_reached|ad_cooldown|ad_provider_unavailable|ad_not_valued|Empty feed|Failed to verify/.test(msg)) toast('No ad available right now.');
      else toast(msg);
      btn.disabled = false;
      btn.textContent = 'Watch';
    }
  }

  function watchAdPlaceholder(unit, btn) {
    btn.disabled = true;
    btn.textContent = 'Watching… 5s';
    let s = 5;
    const t = setInterval(async () => {
      s--;
      if (s > 0) { btn.textContent = 'Watching… ' + s + 's'; return; }
      clearInterval(t);
      try {
        const r = await api('ad_view', { unit: unit });
        toast('+' + r.reward_orbit + ' orbit earned!');
        await refresh(); renderEarn();
      } catch (e) { toast(e.message); btn.disabled = false; btn.textContent = 'Watch'; }
    }, 1000);
  }

  function renderPlay() {
    const order = ['blaster', 'startap', 'tictactoe', 'words'];
    let h = '<h2>🎮 Play</h2><div class="small">6 paid runs per game per day. Runs reset at 06:00.</div><div class="grid2">';
    for (const g of order) {
      const meta = window.OrbitGames[g];
      const left = S.user.runs_left[g];
      h += '<div class="game-card" data-game="' + g + '"><div class="gi">' + meta.icon + '</div>'
        + '<div class="gn">' + meta.name + '</div><div class="small">' + left + ' runs left</div></div>';
    }
    h += '</div><div id="game-area"></div>';
    view.innerHTML = h;
    view.querySelectorAll('[data-game]').forEach(c => {
      c.onclick = () => {
        const g = c.getAttribute('data-game');
        if (S.user.runs_left[g] <= 0) { toast('No paid runs left for this game today.'); return; }
        const area = document.getElementById('game-area');
        area.innerHTML = '';
        area.scrollIntoView();
        window.OrbitGames[g].start(area, async (result) => {
          area.innerHTML = '<div class="card"><div class="small">Submitting score…</div></div>';
          try {
            const r = await api('game_submit', {
              game: g, score: result.score | 0, taps: result.taps | 0,
              duration_ms: result.duration_ms | 0, nonce: nonce(),
            });
            area.innerHTML = '<div class="notice">🎉 +' + r.reward_orbit + ' orbit earned! (' + r.runs_left + ' runs left today)</div>';
            await refresh();
          } catch (e) {
            area.innerHTML = '<div class="warn">' + esc(e.message) + '</div>';
          }
        });
      };
    });
  }

  function renderFriends() {
    view.innerHTML = '<h2>👥 Friends</h2><div class="card"><div class="small">Share your link. Loading…</div></div>';
    api('referral_info').then(r => {
      view.innerHTML = '<h2>👥 Friends</h2>'
        + '<div class="card"><h3>Your referral link</h3><div class="linkbox">' + esc(r.link || 'not available yet') + '</div>'
        + '<div style="margin-top:8px"><button class="btn" id="copy-ref">Copy link</button></div></div>'
        + '<div class="card"><div class="small">• +' + Number(r.stages.join.bonus_orbit).toLocaleString() + ' orbit when a friend joins</div>'
        + '<div class="small">• +' + Number(r.stages.active.bonus_orbit).toLocaleString() + ' orbit when they play 2 games</div>'
        + '<div class="small">• +' + Number(r.stages.withdraw.bonus_orbit).toLocaleString() + ' orbit on their first withdrawal</div>'
        + '<div class="small">• +' + r.commission_pct + '% of their earnings, forever</div>'
        + '<div class="small">Withdrawals unlock after one invited friend plays 2 games.</div></div>'
        + '<div class="row"><div class="stat"><b>' + r.friends + '</b><span>friends</span></div>'
        + '<div class="stat"><b>' + r.stages.active.paid + '</b><span>active</span></div>'
        + '<div class="stat"><b>' + Number(r.commission_today).toLocaleString() + '</b><span>commission today</span></div></div>';
      document.getElementById('copy-ref').onclick = () => {
        const done = () => toast('Link copied!');
        if (navigator.clipboard && r.link) navigator.clipboard.writeText(r.link).then(done, done);
        else done();
      };
    }).catch(e => { view.innerHTML = '<div class="warn">' + esc(e.message) + '</div>'; });
  }

  function renderMe() {
    const u = S.user;
    const gw = S.gateways;
    let h = '<h2>👤 Me</h2>'
      + '<div class="card bal-card"><div class="bal-num">' + Number(u.balance_orbit).toLocaleString() + '</div>'
      + '<div class="small">ORBIT ≈ $' + u.balance_usd + '</div></div>'
      + '<div class="card"><h3>💸 Withdraw</h3>'
      + '<div class="small">Minimum ' + Number(S.min_withdraw).toLocaleString() + ' orbit. Payouts are sent manually by the admin — you will get a bot message when paid.</div>'
      + '<select id="wd-gw">' + Object.keys(gw).map(k => '<option value="' + k + '">' + esc(gw[k]) + '</option>').join('') + '</select>'
      + '<input type="text" id="wd-addr" placeholder="Your receiving address">'
      + '<input type="number" id="wd-amt" placeholder="Amount in orbit" min="' + S.min_withdraw + '">'
      + '<button class="btn big" id="wd-btn">Request withdrawal</button><div id="wd-res"></div></div>'
      + '<div class="card"><h3>History</h3><div id="wd-hist"><div class="small">Loading…</div></div></div>';
    view.innerHTML = h;
    document.getElementById('wd-btn').onclick = async () => {
      const btn = document.getElementById('wd-btn');
      btn.disabled = true;
      const payload = {
        gateway: document.getElementById('wd-gw').value,
        address: document.getElementById('wd-addr').value,
        amount: parseInt(document.getElementById('wd-amt').value, 10) || 0,
      };
      try {
        const r = await api('withdraw_request', payload);
        if (r.otp_sent) {
          // Step 2: the bot sent a 6-digit code to the user's chat — confirm it here.
          document.getElementById('wd-res').innerHTML = '<div class="notice">🔐 We sent a 6-digit confirmation code to your bot chat. Enter it to submit:</div>'
            + '<div style="display:flex;gap:8px;margin-top:8px"><input id="wd-code" inputmode="numeric" placeholder="6-digit code" maxlength="6" style="flex:1">'
            + '<button class="btn" id="wd-confirm">Confirm</button></div><div id="wd-res2" style="margin-top:8px"></div>';
          document.getElementById('wd-confirm').onclick = async () => {
            try {
              const r2 = await api('withdraw_request', Object.assign({}, payload, { code: document.getElementById('wd-code').value.trim() }));
              document.getElementById('wd-res').innerHTML = '<div class="notice">Request #' + r2.withdrawal_id + ' submitted and pending admin approval.</div>';
              await refresh();
            } catch (e2) {
              document.getElementById('wd-res2').innerHTML = '<div class="warn">' + esc(e2.message) + '</div>';
            }
          };
        } else {
          document.getElementById('wd-res').innerHTML = '<div class="notice">Request #' + r.withdrawal_id + ' submitted and pending admin approval.</div>';
          await refresh();
        }
      } catch (e) {
        document.getElementById('wd-res').innerHTML = '<div class="warn">' + esc(e.message) + '</div>';
      }
      btn.disabled = false;
    };
    api('withdraw_history').then(r => {
      const el = document.getElementById('wd-hist');
      if (!r.withdrawals.length) { el.innerHTML = '<div class="small">No withdrawals yet.</div>'; return; }
      el.innerHTML = r.withdrawals.map(w =>
        '<div class="wrow"><div><div class="t">#' + w.id + ' — ' + esc(w.gateway) + '</div>'
        + '<div class="small">' + Number(w.amount_orbit).toLocaleString() + ' orbit · ' + esc(w.status)
        + (w.status === 'rejected' && w.reject_reason ? ' — ' + esc(w.reject_reason) : '') + '</div></div></div>'
      ).join('');
    }).catch(e => { document.getElementById('wd-hist').innerHTML = '<div class="warn">' + esc(e.message) + '</div>'; });
  }

  const tabs = { home: renderHome, earn: renderEarn, play: renderPlay, friends: renderFriends, me: renderMe };
  function setTab(name) {
    document.querySelectorAll('#tabs button').forEach(b => b.classList.toggle('on', b.getAttribute('data-tab') === name));
    tabs[name]();
    window.scrollTo(0, 0);
  }
  document.querySelectorAll('#tabs button').forEach(b => {
    b.onclick = () => setTab(b.getAttribute('data-tab'));
  });

  // boot
  (async () => {
    if (!initData) {
      view.innerHTML = '<div class="card"><h2>🪐 Orbit Earn</h2><div class="warn">Open this app from the Telegram bot (the "Open Orbit" button) so your account can be verified.</div></div>';
      return;
    }
    try {
      await refresh();
      setTab('home');
      if (S.user && S.user.safety_passed === false) showSafetyDialog();
    } catch (e) {
      view.innerHTML = '<div class="card"><div class="warn">' + esc(e.message) + '</div></div>';
    }
  })();
})();
