// Animazioni allo scorrimento, in stile pagine prodotto.
(() => {
  const reduce = matchMedia('(prefers-reduced-motion: reduce)').matches;

  // 1. Apertura: progress 0..1 mentre la sezione scorre via.
  const intro = document.querySelector('.intro');
  if (intro && !reduce) {
    let ticking = false;
    const update = () => {
      const total = Math.max(intro.offsetHeight - innerHeight, 1);
      const p = Math.min(1, Math.max(0, -intro.getBoundingClientRect().top / total));
      intro.style.setProperty('--p', p.toFixed(3));
      ticking = false;
    };
    addEventListener('scroll', () => {
      if (!ticking) { ticking = true; requestAnimationFrame(update); }
    }, { passive: true });
    addEventListener('resize', update);
    update();
  }

  // 2. Comparsa dei blocchi quando entrano nello schermo.
  const sel = '.section-head, .tile, .panel, .toolbar, .empty, .past-events, .quiz-q, .back, .comments-title';
  const els = [...document.querySelectorAll(sel)].filter(el => !el.parentElement.closest(sel) && !el.closest('dialog'));
  if (reduce || !('IntersectionObserver' in window)) return;
  els.forEach(el => el.setAttribute('data-reveal', ''));
  const io = new IntersectionObserver(entries => {
    let i = 0;
    for (const en of entries) {
      if (!en.isIntersecting) continue;
      en.target.style.setProperty('--d', Math.min(i++, 6) * 80 + 'ms');
      en.target.classList.add('in');
      io.unobserve(en.target);
    }
  }, { rootMargin: '0px 0px -6% 0px', threshold: 0.04 });
  els.forEach(el => io.observe(el));
})();
