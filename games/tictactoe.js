/* Cosmic Tic Tac Toe — you (🚀) vs the onboard computer (🛸). One game. */
(function () {
  'use strict';
  function start(container, onDone) {
    container.innerHTML = '<div class="card"><h3>🛸 Cosmic Tic Tac Toe</h3>'
      + '<div class="small">Beat the ship computer! Win = 200, draw = 150, loss = 100 orbit.</div>'
      + '<div class="ttt" id="tt-board"></div><div id="tt-msg" class="small"></div></div>';
    const board = container.querySelector('#tt-board');
    const msg = container.querySelector('#tt-msg');
    const t0 = Date.now();
    let cells = Array(9).fill(''), over = false, moves = 0;
    const cells2 = cells;

    function render() {
      board.innerHTML = '';
      cells2.forEach((v, i) => {
        const b = document.createElement('button');
        b.textContent = v;
        b.onclick = () => play(i);
        board.appendChild(b);
      });
    }
    function winner(c) {
      const L = [[0,1,2],[3,4,5],[6,7,8],[0,3,6],[1,4,7],[2,5,8],[0,4,8],[2,4,6]];
      for (const [a,b2,d] of L) if (c[a] && c[a] === c[b2] && c[a] === c[d]) return c[a];
      return c.every(x => x) ? 'draw' : null;
    }
    function aiMove() {
      // win if possible, block if needed, else random
      for (let i = 0; i < 9; i++) {
        if (!cells2[i]) { cells2[i] = '🛸'; if (winner(cells2) === '🛸') { render(); return; } cells2[i] = ''; }
      }
      for (let i = 0; i < 9; i++) {
        if (!cells2[i]) { cells2[i] = '🚀'; if (winner(cells2) === '🚀') { cells2[i] = '🛸'; render(); return; } cells2[i] = ''; }
      }
      const free = cells2.map((v, i) => v ? -1 : i).filter(i => i >= 0);
      if (free.length) cells2[free[Math.floor(Math.random() * free.length)]] = '🛸';
      render();
    }
    function finish(result) {
      if (over) return;
      over = true;
      const score = result === '🚀' ? 2 : (result === 'draw' ? 1 : 0);
      msg.innerHTML = result === '🚀' ? '<span class="done">You win! +200 orbit</span>'
        : result === 'draw' ? '<span class="done">Draw! +150 orbit</span>' : 'Computer wins. +100 orbit';
      setTimeout(() => onDone({ score: score, taps: moves, duration_ms: Date.now() - t0 }), 1200);
    }
    function play(i) {
      if (over || cells2[i]) return;
      cells2[i] = '🚀'; moves++; render();
      let w = winner(cells2);
      if (w) { finish(w); return; }
      setTimeout(() => { aiMove(); const w2 = winner(cells2); if (w2) finish(w2); }, 350);
    }
    render();
  }
  window.OrbitGames = window.OrbitGames || {};
  window.OrbitGames.tictactoe = { name: 'Cosmic Tic Tac Toe', icon: '🛸', start: start };
})();
