/* Visualization Lab — live generation. Each "Generate" button POSTs the
   subject + model to viz_generate.php and fills its card with the render,
   CLIP score, and judge verdict. */
(function () {
  'use strict';

  var wrap = document.querySelector('.viz-compare');
  if (!wrap) return;

  function pct(v) { return (v === null || v === undefined) ? '—' : Math.round(v * 100) + '%'; }

  wrap.addEventListener('click', function (e) {
    var btn = e.target.closest('.viz-btn');
    if (!btn || btn.disabled) return;

    var card    = btn.closest('.viz-model');
    var model   = card.getAttribute('data-model');
    var subject = card.getAttribute('data-subject');
    var force = card.classList.contains('has-image') ? '1' : '';

    card.classList.add('is-loading');
    btn.disabled = true;
    var origLabel = btn.textContent;
    btn.textContent = 'Generating…';
    card.querySelector('.viz-model-notes').textContent = '';

    var body = new URLSearchParams();
    body.set('subject', subject);
    body.set('model', model);
    if (force) body.set('force', '1');

    fetch('/viz_generate.php', {
      method: 'POST',
      headers: { 'Content-Type': 'application/x-www-form-urlencoded' },
      body: body.toString()
    })
      .then(function (r) { return r.json().then(function (j) { return { ok: r.ok, j: j }; }); })
      .then(function (res) {
        var j = res.j;
        if (!j || !j.ok) {
          card.querySelector('.viz-model-notes').textContent = (j && j.error) || 'Generation failed.';
          card.classList.add('is-error');
          return;
        }
        var g = j.generation;

        var imgBox = card.querySelector('.viz-model-img');
        imgBox.innerHTML = '';
        var img = document.createElement('img');
        img.src = g.image_url;
        img.alt = model + ' reconstruction';
        imgBox.appendChild(img);
        card.classList.add('has-image');

        card.querySelector('.viz-clip').textContent  = pct(g.clip_similarity);
        card.querySelector('.viz-judge').textContent = (g.judge_score === null || g.judge_score === undefined) ? '—' : g.judge_score;
        card.querySelector('.viz-model-notes').textContent = g.judge_notes || '';
        btn.textContent = 'Regenerate';
      })
      .catch(function () {
        card.querySelector('.viz-model-notes').textContent = 'Network error — try again.';
        card.classList.add('is-error');
      })
      .finally(function () {
        card.classList.remove('is-loading');
        btn.disabled = false;
        if (btn.textContent === 'Generating…') btn.textContent = origLabel;
      });
  });
})();
