// Anteprima Google Maps mentre si scrive il nome del posto (nessuna chiave API).
document.querySelectorAll('.place-form').forEach(form => {
  const input = form.querySelector('[data-maps-query]');
  const box = form.querySelector('[data-maps-preview]');
  const frame = box.querySelector('iframe');
  const open = form.querySelector('[data-maps-open]');
  let timer;
  input.addEventListener('input', () => {
    clearTimeout(timer);
    const q = input.value.trim();
    open.href = q ? 'https://www.google.com/maps/search/?api=1&query=' + encodeURIComponent(q) : 'https://www.google.com/maps';
    timer = setTimeout(() => {
      if (q.length < 3) { box.hidden = true; return; }
      frame.src = 'https://maps.google.com/maps?q=' + encodeURIComponent(q) + '&hl=it&z=15&output=embed';
      box.hidden = false;
    }, 600);
  });
});
