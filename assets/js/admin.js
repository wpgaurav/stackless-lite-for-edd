/* Stackless dashboard: instant module switches, search and filters. */
(function () {
  'use strict';
  var cfg = window.stacklessEddAdmin || {};
  var root = document.querySelector('.stackless-dashboard');
  if (!root) {
    return;
  }
  var search = document.getElementById('stackless-module-search');
  var count = document.getElementById('stackless-module-count');
  var empty = root.querySelector('.stackless-empty');
  var filter = 'all';

  function apply() {
    var q = search ? search.value.trim().toLowerCase() : '';
    var shown = 0;
    root.querySelectorAll('.stackless-group').forEach(function (group) {
      var visible = 0;
      group.querySelectorAll('.stackless-card').forEach(function (card) {
        var match = (q === '' || card.dataset.search.indexOf(q) !== -1) && (filter === 'all' || card.dataset.state === filter);
        card.hidden = !match;
        if (match) { visible++; }
      });
      group.hidden = visible === 0;
      shown += visible;
    });
    if (empty) { empty.hidden = shown !== 0; }
  }

  if (search) { search.addEventListener('input', apply); }
  root.querySelectorAll('.stackless-filter').forEach(function (btn) {
    btn.addEventListener('click', function () {
      filter = btn.dataset.filter;
      root.querySelectorAll('.stackless-filter').forEach(function (b) {
        var on = b === btn;
        b.classList.toggle('is-active', on);
        b.setAttribute('aria-pressed', on ? 'true' : 'false');
      });
      apply();
    });
  });

  // One card's switch, settings link and "Needs …" note, from the server's view after a toggle.
  function update(card, state) {
    var on = !!state.enabled;
    card.classList.toggle('is-on', on);
    card.dataset.state = on ? 'on' : 'off';
    var sw = card.querySelector('.stackless-switch');
    if (sw) { sw.setAttribute('aria-checked', on ? 'true' : 'false'); }
    var link = card.querySelector('.stackless-card__link');
    if (link) {
      link.hidden = !state.settingsUrl;
      if (state.settingsUrl) { link.href = state.settingsUrl; }
    }
    var also = card.querySelector('.stackless-card__also');
    if (also) { also.remove(); }
    var blocked = card.querySelector('.stackless-card__blocked');
    if (blocked && (!on || state.note || blocked.hasAttribute('data-needs'))) {
      blocked.remove();
    }
    if (on && state.note) {
      blocked = document.createElement('span');
      blocked.className = 'stackless-card__blocked';
      blocked.setAttribute('data-needs', '');
      blocked.innerHTML = cfg.icon || '';
      blocked.appendChild(document.createTextNode(state.note));
      card.querySelector('.stackless-card__foot').prepend(blocked);
    }
  }

  root.addEventListener('click', function (e) {
    var sw = e.target.closest('.stackless-switch');
    if (!sw || sw.getAttribute('aria-busy') === 'true') {
      return;
    }
    var card = sw.closest('.stackless-card');
    var next = sw.getAttribute('aria-checked') !== 'true';
    sw.setAttribute('aria-busy', 'true');
    sw.setAttribute('aria-checked', next ? 'true' : 'false');

    var body = new URLSearchParams();
    body.append('action', 'stackless_edd_toggle_module');
    body.append('nonce', cfg.nonce || '');
    body.append('module', sw.dataset.module);
    body.append('enabled', next ? '1' : '');

    fetch(cfg.ajaxUrl, { method: 'POST', credentials: 'same-origin', body: body })
      .then(function (r) { return r.json(); })
      .then(function (res) {
        if (!res || !res.success) { throw new Error('save'); }
        var cards = res.data.cards || {};
        if (!cards[sw.dataset.module]) {
          cards[sw.dataset.module] = { enabled: !!res.data.enabled, settingsUrl: res.data.settingsUrl, note: null };
        }
        Object.keys(cards).forEach(function (id) {
          var other = root.querySelector('.stackless-card[data-module="' + id + '"]');
          if (other) { update(other, cards[id]); }
        });
        if (res.data.also) {
          var also = document.createElement('p');
          also.className = 'stackless-card__also';
          also.setAttribute('role', 'status');
          also.textContent = res.data.also;
          card.querySelector('.stackless-card__desc').after(also);
        }
        if (count && cfg.i18n) {
          count.textContent = cfg.i18n.count.replace('%1$d', res.data.count).replace('%2$d', res.data.total);
        }
        apply();
        // A toast confirms the switch when the toast script is on this screen.
        var title = card.querySelector('.stackless-card__title');
        if (window.StacklessEddToast && title && cfg.i18n && cfg.i18n.turnedOn) {
          window.StacklessEddToast.success((res.data.enabled ? cfg.i18n.turnedOn : cfg.i18n.turnedOff).replace('%s', title.textContent.trim()));
        }
      })
      .catch(function () {
        sw.setAttribute('aria-checked', next ? 'false' : 'true');
        var message = (cfg.i18n && cfg.i18n.failed) || 'Could not save.';
        if (window.StacklessEddToast) { window.StacklessEddToast.error(message); } else { window.alert(message); }
      })
      .finally(function () { sw.removeAttribute('aria-busy'); });
  });
})();
