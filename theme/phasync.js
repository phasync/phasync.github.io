// phasync theme: the light/dark toggle and the copy buttons. No dependencies.
(() => {
  const root = document.documentElement;
  const isDark = () => root.dataset.theme ? root.dataset.theme === 'dark' : matchMedia('(prefers-color-scheme: dark)').matches;

  document.addEventListener('click', (e) => {
    if (!e.target.closest('.theme-toggle')) return;
    root.dataset.theme = isDark() ? 'light' : 'dark';
    try { localStorage.theme = root.dataset.theme; } catch (_) {}
  });

  document.querySelectorAll('.code, .terminal').forEach((box) => {
    const button = Object.assign(document.createElement('button'), { type: 'button', className: 'copy', textContent: 'Copy' });
    button.addEventListener('click', async () => {
      let text = box.querySelector('pre').innerText;
      if (box.classList.contains('terminal')) { // copy the commands, not the prompt or the output
        const commands = text.split('\n').filter((l) => l.startsWith('$ ')).map((l) => l.slice(2));
        if (commands.length) text = commands.join('\n');
      }
      try { await navigator.clipboard.writeText(text); button.textContent = 'Copied'; } catch (_) { button.textContent = 'Select and copy'; }
      setTimeout(() => (button.textContent = 'Copy'), 1500);
    });
    box.append(button);
  });
})();
