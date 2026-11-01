/* Star Tap — tap the jumping star. 20s round. */
(function () {
  'use strict';
  function start(container, onDone) {
    container.innerHTML = '<div class="card"><h3>⭐ Star Tap</h3>'
      + '<div class="small">Tap the glowing star as fast as you can! 20 seconds.</div>'
      + '<canvas id="st-cv" width="340" height="300"></canvas>'
      + '<div class="row" style="margin-top:8px"><div class="stat"><b id="st-score">0</b><span>hits</span></div>'
      + '<div class="stat"><b id="st-time">20</b><span>seconds</span></div></div></div>';
    const cv = container.querySelector('#st-cv');
    const ctx = cv.getContext('2d');
    const scoreEl = container.querySelector('#st-score');
    const timeEl = container.querySelector('#st-time');
    let sx = 170, sy = 150, score = 0, taps = 0, over = false;
    const t0 = Date.now();
    const W = cv.width, H = cv.height;
    const R = 26;

    function jump() {
      sx = R + 8 + Math.random() * (W - 2 * (R + 8));
      sy = R + 8 + Math.random() * (H - 2 * (R + 8));
    }
    const jumpTimer = setInterval(() => { if (!over) jump(); }, 750);
    cv.addEventListener('pointerdown', e => {
      if (over) return;
      taps++;
      const b = cv.getBoundingClientRect();
      const x = (e.clientX - b.left) * (W / b.width), y = (e.clientY - b.top) * (H / b.height);
      if (Math.hypot(sx - x, sy - y) <= R + 10) { score++; scoreEl.textContent = score; jump(); }
    });
    function drawStar(x, y, r) {
      ctx.save(); ctx.translate(x, y);
      ctx.shadowColor = '#fbbf24'; ctx.shadowBlur = 18;
      ctx.fillStyle = '#fbbf24'; ctx.beginPath();
      for (let i = 0; i < 10; i++) {
        const rr = i % 2 === 0 ? r : r * 0.45;
        const a = -Math.PI / 2 + i * Math.PI / 5;
        ctx[i === 0 ? 'moveTo' : 'lineTo'](Math.cos(a) * rr, Math.sin(a) * rr);
      }
      ctx.closePath(); ctx.fill(); ctx.restore();
    }
    function frame() {
      if (over) return;
      const elapsed = (Date.now() - t0) / 1000;
      const left = Math.max(0, Math.ceil(20 - elapsed));
      timeEl.textContent = left;
      if (elapsed >= 20) { finish(); return; }
      ctx.fillStyle = '#070b1d'; ctx.fillRect(0, 0, W, H);
      drawStar(sx, sy, R);
      requestAnimationFrame(frame);
    }
    function finish() {
      if (over) return;
      over = true; clearInterval(jumpTimer);
      onDone({ score: Math.min(score, 120), taps: taps, duration_ms: Date.now() - t0 });
    }
    frame();
  }
  window.OrbitGames = window.OrbitGames || {};
  window.OrbitGames.startap = { name: 'Star Tap', icon: '⭐', start: start };
})();
