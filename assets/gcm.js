(function () {
  'use strict';
  var C = window.GCM || {};
  function $(s, r) { return (r || document).querySelector(s); }
  function $$(s, r) { return Array.prototype.slice.call((r || document).querySelectorAll(s)); }
  function el(tag, attrs, text) {
    var e = document.createElement(tag);
    if (attrs) Object.keys(attrs).forEach(function (k) { e.setAttribute(k, attrs[k]); });
    if (text) e.textContent = text;
    return e;
  }
  function post(action, data) {
    var fd = new FormData();
    fd.append('action', action);
    fd.append('nonce', C.nonce || '');
    Object.keys(data || {}).forEach(function (k) { fd.append(k, data[k]); });
    return fetch(C.ajax, { method: 'POST', credentials: 'same-origin', body: fd }).then(function (r) { return r.json(); });
  }
  function debounce(fn, ms) { var t; return function () { var a = arguments, c = this; clearTimeout(t); t = setTimeout(function () { fn.apply(c, a); }, ms); }; }

  /* Копіювання */
  document.addEventListener('click', function (e) {
    var b = e.target.closest && e.target.closest('[data-copy]');
    if (!b) return;
    var text = b.getAttribute('data-copy');
    var done = function () { var o = b.textContent; b.textContent = 'Скопійовано ✓'; setTimeout(function () { b.textContent = o; }, 1500); };
    if (navigator.clipboard && navigator.clipboard.writeText) navigator.clipboard.writeText(text).then(done);
    else { var t = el('textarea'); t.value = text; document.body.appendChild(t); t.select(); try { document.execCommand('copy'); done(); } catch (x) {} document.body.removeChild(t); }
  });

  /* Поділитися (мобільні) */
  var share = $('#gcm-share');
  if (share && navigator.share) {
    share.hidden = false;
    share.addEventListener('click', function () { navigator.share({ title: share.dataset.title, url: share.dataset.url }).catch(function () {}); });
  }

  /* Спосіб оплати -> блок із Приват Конвертом */
  var method = $('#gcm-method'), cardBox = $('#gcm-card-box');
  if (method && cardBox) {
    var sync = function () { cardBox.hidden = method.value !== 'card'; };
    method.addEventListener('change', sync); sync();
  }

  /* Попередній перегляд і перевірка в Google (сторінка роботи) */
  var out = $('#gcm-preview-out');
  var pv = $('#gcm-preview-btn'), vl = $('#gcm-validate-btn');
  if (pv && out) pv.addEventListener('click', function () {
    out.textContent = 'Завантажую…'; pv.disabled = true;
    post('gcm_preview_case', { case_id: pv.dataset.case }).then(function (r) {
      if (r.success) out.innerHTML = r.data.html; else out.textContent = (r.data && r.data.message) || 'Не вдалося завантажити перегляд.';
    }).catch(function () { out.textContent = 'Помилка мережі.'; }).then(function () { pv.disabled = false; });
  });
  if (vl && out) vl.addEventListener('click', function () {
    out.textContent = 'Перевіряю в Google…'; vl.disabled = true;
    post('gcm_validate_case', { case_id: vl.dataset.case }).then(function (r) {
      out.textContent = '';
      var box = el('div', { 'class': 'gcm-alert ' + (r.success && r.data.ok ? 'gcm-alert-ok' : 'gcm-alert-err') });
      var lines = r.success ? r.data.lines : [(r.data && r.data.message) || 'Не вдалося перевірити.'];
      lines.forEach(function (l) { box.appendChild(el('div', null, l)); });
      out.appendChild(box);
    }).catch(function () { out.textContent = 'Помилка мережі.'; }).then(function () { vl.disabled = false; });
  });

  /* Форма роботи */
  var form = $('#gcm-work');
  if (!form) return;
  var isEdit = form.dataset.edit === '1';
  var kind = form.dataset.kind;

  function applyKind() {
    var k = isEdit ? kind : ($('input[name=kind]:checked', form) || {}).value || 'map';
    kind = k;
    $$('.gcm-map-only', form).forEach(function (b) { b.hidden = (k === 'reviews'); });
    var none = $('#gcm-none-opt'); if (none) none.hidden = (k === 'reviews');
  }
  $$('input[name=kind]', form).forEach(function (r) { r.addEventListener('change', applyKind); });
  applyKind();

  /* Клієнт за email */
  var email = $('#gcm-email'), res = $('#gcm-client-result'), blocked = false;
  if (email && res) {
    var check = debounce(function () {
      var v = email.value.trim(); blocked = false;
      if (!/^\S+@\S+\.\S+$/.test(v)) { res.textContent = 'Введіть email — перевірю, чи вже є такий клієнт і чи є в нього робота.'; return; }
      res.textContent = 'Перевіряю…';
      post('gcm_lookup_client', { email: v }).then(function (r) {
        res.textContent = '';
        if (!r.success) { res.textContent = (r.data && r.data.message) || 'Не вдалося перевірити.'; return; }
        var d = r.data;
        if (d.state === 'new') res.textContent = 'Новий клієнт — вкажіть ім’я.';
        else if (d.state === 'other') { blocked = true; res.textContent = 'Цей клієнт уже закріплений за іншим менеджером. Зверніться до адміністратора.'; }
        else if (d.state === 'mine') {
          var n = $('#gcm-cname'), p = $('#gcm-cphone');
          if (n && !n.value) n.value = d.name || '';
          if (p && !p.value) p.value = d.phone || '';
          res.appendChild(document.createTextNode('Це ваш клієнт: ' + d.name + '. '));
          if (d.cases && d.cases.length) {
            res.appendChild(document.createTextNode('Попередні роботи: '));
            d.cases.forEach(function (c, i) {
              var a = el('a', { href: c.url }, '#' + c.id + ' ' + (c.biz || c.type) + ' (' + c.status + ')');
              res.appendChild(a); if (i < d.cases.length - 1) res.appendChild(document.createTextNode(', '));
            });
            var q = $('#gcm-q');
            if (q && !q.value && d.cases[0].biz) { q.value = d.cases[0].biz; }
          }
        }
      }).catch(function () { res.textContent = 'Помилка мережі.'; });
    }, 400);
    email.addEventListener('input', check);
    if (email.value) check();
  }

  /* Пошук картки */
  var q = $('#gcm-q'), find = $('#gcm-find'), cands = $('#gcm-cands');
  var pid = $('#gcm-place-id'), murl = $('#gcm-maps-url'), rt = $('#gcm-rt'), pst = $('#gcm-place-status');
  function setStatus(t, cls) { if (!pst) return; pst.hidden = !t; pst.textContent = t || ''; pst.className = 'gcm-alert ' + (cls || ''); }
  function choose(type, id, url, name) {
    rt.value = (kind === 'reviews') ? 'reviews' : type; pid.value = id || ''; murl.value = url || '';
    if (type === 'edit') {
      setStatus(kind === 'reviews' ? 'Картку вибрано.' : 'Вибрано існуючу картку. Заявка — на редагування.', 'gcm-alert-ok');
      var bn = form.elements.biz_name; if (bn && !bn.value && name) bn.placeholder = name;
    } else setStatus('Картки немає — буде створена нова. Заповніть дані нижче.', 'gcm-alert-ok');
  }
  function renderCands(list) {
    cands.textContent = '';
    list.forEach(function (p) {
      var lab = el('label', { 'class': 'gcm-cand' });
      var r = el('input', { type: 'radio', name: 'gcm_cand' });
      r.addEventListener('change', function () { choose('edit', p.id, p.url, p.name); });
      lab.appendChild(r);
      var box = el('span'); box.appendChild(el('strong', null, p.name || '(без назви)')); box.appendChild(el('br')); box.appendChild(el('span', { 'class': 'gcm-muted' }, p.address || ''));
      if (p.url) { box.appendChild(document.createTextNode(' ')); box.appendChild(el('a', { href: p.url, target: '_blank', rel: 'noopener' }, 'на мапі ↗')); }
      lab.appendChild(box); cands.appendChild(lab);
    });
    var none = el('label', { 'class': 'gcm-cand', id: 'gcm-none-opt' });
    var nr = el('input', { type: 'radio', name: 'gcm_cand' });
    nr.addEventListener('change', function () { choose('create', '', '', ''); });
    none.appendChild(nr); none.appendChild(el('span', null, 'Жоден не підходить — картки на Google немає, створити нову'));
    none.hidden = (kind === 'reviews');
    cands.appendChild(none);
    if (!list.length) setStatus(kind === 'reviews' ? 'Нічого не знайдено — уточніть назву або вставте посилання.' : 'Нічого не знайдено. Якщо картки точно немає — оберіть «створити нову».', '');
    else setStatus('', '');
  }
  if (find && q) {
    var run = function () {
      var v = q.value.trim(); if (!v) { setStatus('Введіть назву й місто або посилання.', 'gcm-alert-err'); return; }
      find.disabled = true; cands.textContent = 'Шукаю на Google…'; rt.value = ''; pid.value = ''; murl.value = '';
      post('gcm_search_place', { q: v }).then(function (r) {
        if (!r.success) { cands.textContent = ''; setStatus((r.data && r.data.message) || 'Помилка пошуку.', 'gcm-alert-err'); return; }
        renderCands(r.data.places || []);
      }).catch(function () { cands.textContent = ''; setStatus('Помилка мережі.', 'gcm-alert-err'); }).then(function () { find.disabled = false; });
    };
    find.addEventListener('click', run);
    q.addEventListener('keydown', function (e) { if (e.key === 'Enter') { e.preventDefault(); run(); } });
  }

  /* Категорії (підказки Google) */
  var cat = $('#gcm-cat'), catId = $('#gcm-cat-id'), dl = $('#gcm-cat-list'), catMap = {};
  if (cat && dl) {
    cat.addEventListener('input', debounce(function () {
      var v = cat.value.trim();
      if (catMap[v]) { catId.value = catMap[v]; return; }
      catId.value = '';
      if (v.length < 2) return;
      post('gcm_categories', { term: v }).then(function (r) {
        if (!r.success) return;
        dl.textContent = '';
        r.data.items.forEach(function (i) { catMap[i.name] = i.id; var o = el('option'); o.value = i.name; dl.appendChild(o); });
        if (catMap[cat.value.trim()]) catId.value = catMap[cat.value.trim()];
      });
    }, 300));
    cat.addEventListener('change', function () { var v = cat.value.trim(); if (catMap[v]) catId.value = catMap[v]; });
  }

  /* Опис, години */
  var desc = $('#gcm-desc'), cnt = $('#gcm-desc-count');
  if (desc && cnt) { var u = function () { cnt.textContent = desc.value.length + '/750'; }; desc.addEventListener('input', u); u(); }
  var hon = $('#gcm-hours-on'), hbox = $('#gcm-hours');
  if (hon && hbox) hon.addEventListener('change', function () { hbox.hidden = !hon.checked; });

  /* Попередній перегляд вибраних файлів */
  var prev = $('#gcm-previews');
  if (prev) $$('.gcm-file', form).forEach(function (inp) {
    inp.addEventListener('change', function () {
      prev.textContent = '';
      var total = 0;
      $$('.gcm-file', form).forEach(function (i) {
        Array.prototype.forEach.call(i.files, function (f) {
          total++;
          var box = el('div', { 'class': 'gcm-thumb' });
          if (/^image\//.test(f.type)) { var im = el('img'); im.src = URL.createObjectURL(f); box.appendChild(im); }
          else if (/^video\//.test(f.type)) { var v = el('video', { muted: '', playsinline: '' }); v.src = URL.createObjectURL(f); box.appendChild(v); }
          box.appendChild(el('span', { 'class': 'gcm-muted' }, f.name.slice(0, 24)));
          prev.appendChild(box);
        });
      });
    });
  });

  /* Перевірка перед відправкою */
  form.addEventListener('submit', function (e) {
    if (blocked) { e.preventDefault(); alert('Цей клієнт закріплений за іншим менеджером.'); return; }
    var k = isEdit ? kind : (($('input[name=kind]:checked', form) || {}).value || 'map');
    if (!isEdit || !rt.value) {
      if (k === 'reviews' && !pid.value) { e.preventDefault(); setStatus('Знайдіть і оберіть картку клієнта.', 'gcm-alert-err'); q && q.focus(); return; }
      if (k === 'map' && !rt.value) { e.preventDefault(); setStatus('Знайдіть картку на Google й оберіть варіант зі списку (або «створити нову»).', 'gcm-alert-err'); q && q.focus(); return; }
    }
    var btn = form.querySelector('button.gcm-btn-lg'); if (btn) { btn.disabled = true; btn.textContent = 'Зберігаю…'; }
  });
})();
