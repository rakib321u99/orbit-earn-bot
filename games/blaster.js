/* Orbit Blaster — tap/click asteroids to destroy them. 30s round. */
(function () {
  'use strict';
  function start(container, onDone) {
    container.innerHTML = '<div class="card"><h3>🚀 Orbit Blaster</h3>'
      + '<div class="small">Tap the asteroids before they drift past! 30 seconds.</div>'
      + '<canvas id="bl-cv" width="340" height="380"></canvas>'
      + '<div class="row" style="margin-top:8px"><div class="stat"><b id="bl-score">0</b><span>destroyed</span></div>'
      + '<div class="stat"><b id="bl-time">30</b><span>seconds</span></div></div></div>';
    const cv = container.querySelector('#bl-cv');
    const ctx = cv.getContext('2d');
    const scoreEl = container.querySelector('#bl-score');
    const timeEl = container.querySelector('#bl-time');
    let rocks = [], score = 0, taps = 0, over = false;
    const t0 = Date.now();
    const W = cv.width, H = cv.height;

    function spawn() {
      const r = 14 + Math.random() * 16;
      rocks.push({ x: r + Math.random() * (W - 2 * r), y: -r, r: r,
        vy: 0.6 + Math.random() * 1.4, vx: (Math.random() - 0.5) * 0.8 });
    }
    function hit(x, y) {
      if (over) return;
      taps++;
      for (let i = rocks.length - 1; i >= 0; i--) {
        const rk = rocks[i];
        const d = Math.hypot(rk.x - x, rk.y - y);
        if (d <= rk.r + 8) { rocks.splice(i, 1); score++; scoreEl.textContent = score; return; }
      }
    }
    cv.addEventListener('pointerdown', e => {
      const b = cv.getBoundingClientRect();
      hit((e.clientX - b.left) * (W / b.width), (e.clientY - b.top) * (H / b.height));
    });
    const spawnTimer = setInterval(() => { if (!over && rocks.length < 9) spawn(); }, 650);
    function frame() {
      if (over) return;
      const elapsed = (Date.now() - t0) / 1000;
      const left = Math.max(0, Math.ceil(30 - elapsed));
      timeEl.textContent = left;
      if (elapsed >= 30) { finish(); return; }
      ctx.fillStyle = '#070b1d'; ctx.fillRect(0, 0, W, H);
      // stars
      ctx.fillStyle = '#334';
      for (let i = 0; i < 40; i++) ctx.fillRect((i * 97) % W, (i * 57) % H, 2, 2);
      for (const rk of rocks) {
        rk.x += rk.vx; rk.y += rk.vy;
        if (rk.y > H + rk.r) { rk.y = -rk.r; rk.x = Math.random() * W; }
        ctx.beginPath(); ctx.arc(rk.x, rk.y, rk.r, 0, 7);
        ctx.fillStyle = '#4c5a8a'; ctx.fill();
        ctx.strokeStyle = '#7c3aed'; ctx.stroke();
      }
      requestAnimationFrame(frame);
    }
    function finish() {
      if (over) return;
      over = true; clearInterval(spawnTimer);
      onDone({ score: Math.min(score, 60), taps: taps, duration_ms: Date.now() - t0 });
    }
    for (let i = 0; i < 4; i++) spawn();
    frame();
  }
  window.OrbitGames = window.OrbitGames || {};
  window.OrbitGames.blaster = { name: 'Orbit Blaster', icon: '🚀', start: start };
})();
