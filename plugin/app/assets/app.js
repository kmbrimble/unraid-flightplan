/* Flightplan web app. Plain JS, no build step. All text goes through textContent, never innerHTML. */
(function () {
  'use strict';

  var root = document.getElementById('app');
  var csrf = root.getAttribute('data-csrf') || '';
  var API = '../scripts/flightplan-api.php';
  var PRESETS = [
    ['off', 'Off (manual and boot only)', ''],
    ['hourly', 'Every hour', '0 * * * *'],
    ['6h', 'Every 6 hours', '0 */6 * * *'],
    ['daily', 'Daily at 03:00', '0 3 * * *'],
    ['weekly', 'Weekly, Sunday 03:00', '0 3 * * 0'],
    ['custom', 'Custom cron expression', '']
  ];

  var cfg = { schedule_enabled: true, schedule: '0 */6 * * *', sources: [] };
  var dirty = false;
  var status = null;
  var els = {};
  var pollTimer = null;
  var cfgSnap = '';
  var forceCustom = false;
  var statusSig = '';

  function el(tag, attrs, kids) {
    var n = document.createElement(tag);
    Object.keys(attrs || {}).forEach(function (k) {
      if (k === 'text') n.textContent = attrs[k];
      else if (k === 'class') n.className = attrs[k];
      else if (k.slice(0, 2) === 'on') n.addEventListener(k.slice(2), attrs[k]);
      else n.setAttribute(k, attrs[k]);
    });
    (kids || []).forEach(function (c) { if (c) n.appendChild(typeof c === 'string' ? document.createTextNode(c) : c); });
    return n;
  }

  function post(action, extra) {
    var body = new URLSearchParams();
    body.set('action', action);
    body.set('csrf_token', csrf);
    Object.keys(extra || {}).forEach(function (k) { body.set(k, extra[k]); });
    return fetch(API, {
      method: 'POST',
      headers: { 'Content-Type': 'application/x-www-form-urlencoded', 'X-CSRF-Token': csrf },
      body: body.toString(),
      credentials: 'same-origin'
    }).then(function (r) { return r.text(); }).then(function (t) {
      if (!t) throw new Error('Empty response: the session may have expired. Reload the page.');
      var j;
      try { j = JSON.parse(t); } catch (e) { throw new Error('The server did not return JSON.'); }
      return j;
    });
  }

  function when(ts) { return ts ? new Date(ts * 1000).toLocaleString() : 'never'; }
  function chip(text, kind) { return el('span', { class: 'chip' + (kind ? ' chip--' + kind : ''), text: text }); }
  function say(msg, bad) {
    els.msg.textContent = msg || '';
    els.msg.className = 'fp-muted' + (bad ? ' fp-err' : '');
  }

  function blankSource() {
    return { id: '', forge: 'gitea', base_url: '', repo: '', branch: '', subpath: '', name: '', token_ref: '', resolve_ip: '' };
  }

  function field(label, input, hint) {
    return el('label', { class: 'fp-field' }, [el('span', { text: label }), input, hint ? el('span', { class: 'fp-muted', text: hint }) : null]);
  }

  function textInput(src, key, placeholder) {
    var i = el('input', { class: 'input', type: 'text', value: src[key] || '', placeholder: placeholder || '', autocomplete: 'off', spellcheck: 'false' });
    i.addEventListener('input', function () { src[key] = i.value; markDirty(); });
    return i;
  }

  function markDirty() { dirty = true; els.dirty.hidden = false; }

  function sourceCard(src, idx) {
    var forge = el('select', { class: 'input' }, [el('option', { value: 'gitea', text: 'Gitea' }), el('option', { value: 'github', text: 'GitHub' })]);
    forge.value = src.forge;
    var base = textInput(src, 'base_url', 'https://git.kiztigs.com');
    function syncBase() { base.disabled = src.forge === 'github'; }
    forge.addEventListener('change', function () { src.forge = forge.value; syncBase(); markDirty(); });
    syncBase();
    var remove = el('button', { class: 'btn btn--ghost', type: 'button', text: 'Remove source' });
    var armed = false;
    remove.addEventListener('click', function () {
      if (!armed) { armed = true; remove.textContent = 'Click again to remove'; return; }
      cfg.sources.splice(idx, 1); markDirty(); renderSources();
    });
    var st = el('div', { class: 'fp-src-status', 'data-src': src.id });
    return el('div', { class: 'fp-source' }, [
      el('div', { class: 'fp-grid' }, [
        field('Forge', forge),
        field('Base URL', base, 'Gitea only'),
        field('Repository', textInput(src, 'repo', 'owner/repo')),
        field('Branch', textInput(src, 'branch', 'default branch')),
        field('Subpath', textInput(src, 'subpath', 'whole repository')),
        field('Folder name', textInput(src, 'name', 'repository name'), 'Shown in Apps as "<name> Repository"'),
        field('Token', textInput(src, 'token_ref', '!secret namespace/key'), 'A SecretsMan reference. Leave blank for public repositories.')
      ]),
      el('details', {}, [
        el('summary', { text: 'Advanced' }),
        el('div', { class: 'fp-grid' }, [field('Resolve IP', textInput(src, 'resolve_ip', 'optional'), 'Connect to this IP instead of resolving the Base URL host (for a host whose DNS cannot see a LAN-only forge).')])
      ]),
      st,
      el('div', { class: 'fp-row' }, [remove])
    ]);
  }

  function renderSources() {
    els.sources.textContent = '';
    if (!cfg.sources.length) els.sources.appendChild(el('p', { class: 'fp-muted', text: 'No sources yet. Add the repository that holds your template XML files.' }));
    cfg.sources.forEach(function (s, i) { els.sources.appendChild(sourceCard(s, i)); });
    paintSourceStatus();
  }

  function paintSourceStatus() {
    if (!status) return;
    var byId = {};
    (status.config.sources || []).forEach(function (s) { byId[s.id] = s.state; });
    Array.prototype.forEach.call(els.sources.querySelectorAll('.fp-src-status'), function (box) {
      var s = byId[box.getAttribute('data-src')];
      box.textContent = '';
      if (!box.getAttribute('data-src')) { box.appendChild(el('span', { class: 'fp-muted', text: 'Not saved yet.' })); return; }
      if (!s) { box.appendChild(el('span', { class: 'fp-muted', text: 'Not synced since boot.' })); return; }
      var kind = s.status === 'error' ? 'alert' : (s.status === 'updated' ? 'ok' : '');
      var label = s.status === 'error' ? 'Error' : (s.status === 'updated' ? 'Updated' : 'Up to date');
      box.appendChild(el('div', { class: 'fp-row' }, [
        chip(label, kind),
        el('span', { text: s.count + ' templates' }),
        el('span', { class: 'fp-muted', text: 'branch ' + (s.branch || '?') }),
        el('span', { class: 'fp-mono', text: s.sha ? s.sha.slice(0, 12) : '' }),
        el('span', { class: 'fp-muted', text: 'checked ' + when(s.checked_at) })
      ]));
      if (s.error) box.appendChild(el('div', { class: 'fp-err', text: s.error }));
      (s.template_warnings || []).slice(0, 5).forEach(function (w) { box.appendChild(el('div', { class: 'fp-muted', text: w })); });
      if ((s.notes || []).length) {
        box.appendChild(el('details', {}, [el('summary', { text: s.notes.length + ' note(s) from the last download' }),
          el('div', { class: 'fp-mono fp-pre', text: s.notes.join('\n') })]));
      }
    });
  }

  function paintStatus() {
    if (!status) return;
    var b = els.facts;
    b.textContent = '';
    function fact(k, v) { b.appendChild(el('div', {}, [el('dt', { text: k }), el('dd', {}, [v])])); }
    var state = status.running ? chip('Syncing', 'warn') :
      (!status.last_sync ? chip('Not yet this boot', '') : (status.last_ok ? chip('OK', 'ok') : chip('Needs attention', 'alert')));
    fact('State', state);
    fact('Last sync', document.createTextNode(when(status.last_sync) + (status.last_trigger ? ' (' + status.last_trigger + ')' : '')));
    var total = 0, warns = 0;
    (status.config.sources || []).forEach(function (s) { if (s.state) { total += s.state.count || 0; warns += s.state.warning_total || 0; } });
    fact('Templates', document.createTextNode(String(total)));
    fact('GitHub references', warns ? chip(warns + ' found', 'warn') : chip('none', 'ok'));
    fact('SecretsMan', status.secretsman_installed ? chip('installed', 'ok') : chip('not installed', 'warn'));
    els.banner.textContent = '';
    if (status.config_status !== 'ok') {
      els.banner.appendChild(el('div', { class: 'fp-banner fp-bad', text: status.config_status === 'missing'
        ? 'No configuration saved yet. Nothing is synced or removed until you save a source list.'
        : 'The saved configuration is unreadable (' + status.config_error + '). Nothing is synced or removed until it is fixed.' }));
    }
    if ((status.pruned || []).length) {
      els.banner.appendChild(el('div', { class: 'fp-banner', text: 'Removed folders whose source was deleted: ' + status.pruned.join(', ') }));
    }
    els.sync.disabled = !!status.running;
    // GitHub reference table
    var tb = els.refs; tb.textContent = '';
    var rows = [];
    (status.config.sources || []).forEach(function (s) {
      if (s.state) (s.state.warnings || []).forEach(function (w) { rows.push([s.name, w.file, w.tag, w.host, w.value]); });
    });
    if (!rows.length) tb.appendChild(el('p', { class: 'fp-muted', text: 'No template points at GitHub. These are informational and never block a sync.' }));
    else {
      var t = el('table', { class: 'fp-table' }, [el('thead', {}, [el('tr', {}, ['Folder', 'File', 'Field', 'Host', 'Value'].map(function (h) { return el('th', { text: h }); }))])]);
      var tbody = el('tbody');
      rows.slice(0, 200).forEach(function (r) { tbody.appendChild(el('tr', {}, r.map(function (c) { return el('td', { text: c }); }))); });
      t.appendChild(tbody); tb.appendChild(el('div', { class: 'fp-table-wrap' }, [t]));
    }
    els.log.textContent = (status.log || []).join('\n') || '(empty since boot)';
    paintSourceStatus();
  }

  function cfgSignature(c) {
    return JSON.stringify({ e: c.schedule_enabled, s: c.schedule, src: (c.sources || []).map(function (x) {
      return [x.id, x.forge, x.base_url, x.repo, x.branch, x.subpath, x.name, x.token_ref, x.resolve_ip];
    }) });
  }

  // Rebuilding the editors or the status blocks throws away open <details>, focus and half-typed text, so each is
  // redone only when the server's data actually changed (and the editors never while there are unsaved edits).
  function refresh() {
    return post('status').then(function (j) {
      if (!j.ok) throw new Error(j.error || 'status failed');
      status = j;
      var sig = cfgSignature(j.config);
      if (!dirty && sig !== cfgSnap) {
        cfgSnap = sig;
        forceCustom = false;
        cfg = JSON.parse(JSON.stringify(j.config));
        cfg.sources = (j.config.sources || []).map(function (s) { var c = {}; Object.keys(blankSource()).forEach(function (k) { c[k] = s[k] || ''; }); return c; });
        renderSchedule(); renderSources();
        statusSig = '';
      }
      var ssig = JSON.stringify(Object.assign({}, j, { now: 0, log: 0 })) + '|' + (j.log || []).length;
      if (ssig !== statusSig) { statusSig = ssig; paintStatus(); }
      else els.log.textContent = (j.log || []).join('\n') || '(empty since boot)';
      schedulePoll(j.running ? 2000 : 15000);
    }).catch(function (e) { say(e.message, true); schedulePoll(15000); });
  }

  function schedulePoll(ms) { clearTimeout(pollTimer); pollTimer = setTimeout(refresh, ms); }

  function currentPreset() {
    if (!cfg.schedule_enabled) return 'off';
    if (forceCustom) return 'custom';
    for (var i = 0; i < PRESETS.length; i++) if (PRESETS[i][2] && PRESETS[i][2] === cfg.schedule) return PRESETS[i][0];
    return 'custom';
  }

  function renderSchedule() {
    els.preset.value = currentPreset();
    els.custom.value = cfg.schedule || '';
    els.customWrap.hidden = els.preset.value !== 'custom';
  }

  function onPreset() {
    var v = els.preset.value;
    var p = PRESETS.filter(function (x) { return x[0] === v; })[0];
    cfg.schedule_enabled = v !== 'off';
    forceCustom = v === 'custom';
    if (v !== 'off' && v !== 'custom') cfg.schedule = p[2];
    renderSchedule(); markDirty();
  }

  function save() {
    say('Saving...');
    els.save.disabled = true;
    return post('save', { payload: JSON.stringify(cfg) }).then(function (j) {
      els.save.disabled = false;
      if (!j.ok) { say(j.error || 'Save failed', true); return; }
      dirty = false; els.dirty.hidden = true;
      say(j.message || 'Saved.');
      return refresh();
    }).catch(function (e) { els.save.disabled = false; say(e.message, true); });
  }

  function syncNow() {
    if (dirty) { say('Save your changes first: Sync now uses the saved configuration.', true); return; }
    say('Starting sync...');
    els.sync.disabled = true;
    post('sync').then(function (j) {
      say(j.message || (j.ok ? 'Sync started.' : j.error), !j.ok);
      schedulePoll(1500);
    }).catch(function (e) { say(e.message, true); els.sync.disabled = false; });
  }

  function build() {
    els.banner = el('div');
    els.facts = el('dl', { class: 'fp-facts' });
    els.sync = el('button', { class: 'btn btn--secondary', type: 'button', text: 'Sync now', onclick: syncNow });
    els.msg = el('span', { class: 'fp-muted', role: 'status', 'aria-live': 'polite' });
    els.sources = el('div', { class: 'fp-card-body' });
    els.preset = el('select', { class: 'input', onchange: onPreset }, PRESETS.map(function (p) { return el('option', { value: p[0], text: p[1] }); }));
    els.custom = el('input', { class: 'input', type: 'text', placeholder: '0 */6 * * *', autocomplete: 'off', spellcheck: 'false' });
    els.custom.addEventListener('input', function () { cfg.schedule = els.custom.value.trim(); markDirty(); });
    els.customWrap = field('Cron expression', els.custom, 'Five fields: minute hour day-of-month month day-of-week.');
    els.save = el('button', { class: 'btn btn--primary', type: 'button', text: 'Save changes', onclick: save });
    els.dirty = el('span', { class: 'chip chip--warn', text: 'Unsaved changes' });
    els.dirty.hidden = true;
    els.refs = el('div', { class: 'fp-card-body' });
    els.log = el('pre', { class: 'fp-mono fp-log' });
    var add = el('button', { class: 'btn btn--secondary', type: 'button', text: 'Add source', onclick: function () { cfg.sources.push(blankSource()); markDirty(); renderSources(); } });

    root.textContent = '';
    root.className = 'fp-wrap';
    root.appendChild(el('div', { class: 'fp-head' }, [el('h1', { class: 'fp-title', text: 'Flightplan' }), el('span', { class: 'fp-muted', text: 'Your own templates, in Community Applications.' })]));
    root.appendChild(el('div', { class: 'card' }, [el('div', { class: 'fp-card-body' }, [
      el('p', { class: 'section-label', text: 'Status' }), els.banner, els.facts,
      el('div', { class: 'fp-row' }, [els.sync, els.msg])
    ])]));
    root.appendChild(el('div', { class: 'card' }, [el('div', { class: 'fp-card-body' }, [
      el('div', { class: 'fp-row fp-spread' }, [el('p', { class: 'section-label', text: 'Sources' }), add]), els.sources
    ])]));
    root.appendChild(el('div', { class: 'card' }, [el('div', { class: 'fp-card-body' }, [
      el('p', { class: 'section-label', text: 'Schedule' }),
      el('div', { class: 'fp-grid' }, [field('Sync', els.preset), els.customWrap]),
      el('p', { class: 'fp-muted', text: 'A sync also runs when the array starts and at install. It only downloads when a branch head has changed.' })
    ])]));
    root.appendChild(el('div', { class: 'fp-row' }, [els.save, els.dirty]));
    root.appendChild(el('div', { class: 'card' }, [el('div', { class: 'fp-card-body' }, [
      el('p', { class: 'section-label', text: 'References to GitHub in synced templates' }), els.refs
    ])]));
    root.appendChild(el('details', { class: 'card' }, [el('summary', { class: 'fp-card-body', text: 'Log (since boot)' }), el('div', { class: 'fp-card-body' }, [els.log])]));
  }

  build();
  refresh();
})();
