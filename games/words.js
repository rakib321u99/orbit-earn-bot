/* Nebula Words — find 3 hidden words in the letter grid. 60s. */
(function () {
  'use strict';
  const WORDS = ['ORBIT', 'STAR', 'NOVA', 'COMET', 'LUNAR', 'SOLAR', 'NEBULA', 'ASTRO'];
  function start(container, onDone) {
    const words = [];
    const pool = WORDS.slice();
    for (let i = 0; i < 3; i++) words.push(pool.splice(Math.floor(Math.random() * pool.length), 1)[0]);
    const N = 6;
    const grid = Array.from({ length: N }, () => Array.from({ length: N }, () => 'ABCDEFGHIJKLMNOPQRSTUVWXYZ'[Math.floor(Math.random() * 26)]));
    // place words horizontally or vertically
    for (const w of words) {
      let placed = false;
      while (!placed) {
        const horiz = Math.random() < 0.5;
        const r = Math.floor(Math.random() * N), c = Math.floor(Math.random() * N);
        if (horiz && c + w.length <= N) { for (let i = 0; i < w.length; i++) grid[r][c + i] = w[i]; placed = true; }
        else if (!horiz && r + w.length <= N) { for (let i = 0; i < w.length; i++) grid[r + i][c] = w[i]; placed = true; }
      }
    }
    container.innerHTML = '<div class="card"><h3>🔤 Nebula Words</h3>'
      + '<div class="small">Find: <b>' + words.join(', ') + '</b> — tap letters in order, then Check. 60 seconds.</div>'
      + '<div class="wgrid" id="nw-grid"></div>'
      + '<div class="row"><button class="btn ghost" id="nw-clear">Clear</button><button class="btn" id="nw-check">Check word</button></div>'
      + '<div class="wbank" id="nw-found"></div>'
      + '<div class="row"><div class="stat"><b id="nw-score">0</b><span>found</span></div>'
      + '<div class="stat"><b id="nw-time">60</b><span>seconds</span></div></div></div>';
    const gridEl = container.querySelector('#nw-grid');
    const foundEl = container.querySelector('#nw-found');
    const scoreEl = container.querySelector('#nw-score');
    const timeEl = container.querySelector('#nw-time');
    let sel = [], found = [], over = false, taps = 0;
    const t0 = Date.now();
    const cellBtns = [];
    for (let r = 0; r < N; r++) for (let c = 0; c < N; c++) {
      const b = document.createElement('button');
      b.textContent = grid[r][c];
      b.onclick = () => {
        if (over) return;
        taps++;
        const key = r + ',' + c;
        if (!sel.includes(key)) { sel.push(key); b.style.borderColor = '#06b6d4'; }
      };
      gridEl.appendChild(b); cellBtns.push(b);
    }
    function wordOf() { return sel.map(k => { const [r, c] = k.split(',').map(Number); return grid[r][c]; }).join(''); }
    container.querySelector('#nw-clear').onclick = () => {
      sel = []; cellBtns.forEach(b => b.style.borderColor = '');
    };
    container.querySelector('#nw-check').onclick = () => {
      if (over || !sel.length) return;
      const w = wordOf();
      if (words.includes(w) && !found.includes(w)) {
        found.push(w); scoreEl.textContent = found.length;
        const s = document.createElement('span'); s.className = 'small done'; s.textContent = '✓ ' + w;
        foundEl.appendChild(s);
        sel.forEach(k => { const [r, c] = k.split(',').map(Number); cellBtns[r * N + c].classList.add('hit'); });
        if (found.length === 3) { finish(); return; }
      }
      sel = []; cellBtns.forEach(b => { if (!b.classList.contains('hit')) b.style.borderColor = ''; });
    };
    const timer = setInterval(() => {
      if (over) return;
      const left = Math.max(0, Math.ceil(60 - (Date.now() - t0) / 1000));
      timeEl.textContent = left;
      if (left <= 0) finish();
    }, 500);
    function finish() {
      if (over) return;
      over = true; clearInterval(timer);
      onDone({ score: Math.min(found.length, 3), taps: taps, duration_ms: Date.now() - t0 });
    }
  }
  window.OrbitGames = window.OrbitGames || {};
  window.OrbitGames.words = { name: 'Nebula Words', icon: '🔤', start: start };
})();
