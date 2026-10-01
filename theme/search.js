// The docs' search: a typeahead over /search.json, php.net style. Also the 404 page's lookup.
(() => {
  let index;
  const load = () => (index ??= fetch('/search.json').then((r) => r.json()));

  // Names are matched case-insensitively; `Swerve::pub`, `swerve::publish` and `publish` all find Swerve::publish
  function find(items, query, limit = 8) {
    const q = query.trim().toLowerCase().replace(/^\\/, '');
    if (!q) return [];
    const scored = [];
    for (const item of items) {
      const name = item.n.toLowerCase();
      const full = item.f.toLowerCase();
      const member = name.includes('::') ? name.split('::')[1] : name;
      const score = name === q || full === q ? 0 : full.startsWith(q) ? 1 : name.startsWith(q) ? 1 : member.startsWith(q) ? 2 : name.includes(q) ? 3 : item.d.toLowerCase().includes(q) ? 4 : 9;
      if (score < 9) scored.push([score, item.n.length, item]);
    }
    return scored.sort((a, b) => a[0] - b[0] || a[1] - b[1]).slice(0, limit).map((s) => s[2]);
  }

  const form = document.querySelector('form.search');
  if (form) {
    form.hidden = false;
    const input = form.querySelector('input');
    const list = form.querySelector('.suggest');
    let hits = [], selected = -1;
    const show = () => {
      list.replaceChildren(...hits.map((h, i) => {
        const li = document.createElement('li');
        li.role = 'option';
        li.setAttribute('aria-selected', i === selected);
        const a = Object.assign(document.createElement('a'), { href: h.u });
        a.append(Object.assign(document.createElement('b'), { textContent: h.n }), h.d.length > 60 ? h.d.slice(0, 57) + '...' : h.d);
        li.append(a);
        return li;
      }));
      list.hidden = !hits.length;
    };
    input.addEventListener('focus', load);
    input.addEventListener('input', async () => { hits = find(await load(), input.value); selected = -1; show(); });
    input.addEventListener('keydown', (e) => {
      if (e.key === 'ArrowDown' || e.key === 'ArrowUp') {
        e.preventDefault();
        const n = hits.length + 1; // the hits, and -1 for none
        selected = (selected + 1 + (e.key === 'ArrowDown' ? 1 : -1) + n) % n - 1;
        show();
      } else if (e.key === 'Escape') { hits = []; show(); }
    });
    form.addEventListener('submit', async (e) => {
      e.preventDefault(); // before the await: it is too late after
      const hit = hits[Math.max(selected, 0)] ?? find(await load(), input.value, 1)[0];
      location.href = hit ? hit.u : '/404.html?q=' + encodeURIComponent(input.value);
    });
    document.addEventListener('click', (e) => { if (!form.contains(e.target)) { hits = []; show(); } });
  }

  // 404: GitHub Pages is case-sensitive and cannot redirect, so /swerve/publish lands here
  const results = document.getElementById('nf-results');
  if (results) {
    const params = new URLSearchParams(location.search);
    const asked = params.get('q');
    const path = decodeURIComponent(location.pathname).replace(/^\/+|\/+$/g, '').replace(/::/g, '/');
    document.getElementById('nf-path').textContent = location.pathname;
    load().then((items) => {
      if (asked === null) {
        const want = path.toLowerCase();
        const hit = items.find((s) => s.u.replace(/^\/|\/$/g, '').toLowerCase() === want || s.f.replace(/\\|::/g, '/').toLowerCase() === want);
        if (hit) { location.replace(hit.u); return; }
      }
      const term = asked ?? path.split('/').filter(Boolean).join('::');
      const hits = find(items, term, 12).concat(find(items, term.split('::').pop(), 12)).filter((h, i, all) => all.indexOf(h) === i).slice(0, 12);
      results.replaceChildren(...hits.map((h) => {
        const li = document.createElement('li');
        const a = Object.assign(document.createElement('a'), { href: h.u, textContent: h.n });
        li.append(a, ' ' + h.d);
        return li;
      }));
      if (asked !== null) document.getElementById('nf-msg').textContent = hits.length ? 'Results for "' + asked + '":' : 'Nothing found for "' + asked + '".';
    });
  }
})();
