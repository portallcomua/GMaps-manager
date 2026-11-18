(function () {
  'use strict';

  var C = window.GCM || {};

  function $(s, r) {
    return (r || document).querySelector(s);
  }

  function $$(s, r) {
    return Array.prototype.slice.call((r || document).querySelectorAll(s));
  }

  function el(tag, attrs, text) {
    var e = document.createElement(tag);
    if (attrs) Object.keys(attrs).forEach(function (k) { e.setAttribute(k, attrs[k]); });
    if (text !== undefined && text !== null) e.textContent = text;
    return e;
  }

  function post(action, data) {
    var fd = new FormData();
    fd.append('action', action);
    fd.append('nonce', C.nonce || '');
    Object.keys(data || {}).forEach(function (k) { fd.append(k, data[k]); });
    return fetch(C.ajax, {
      method: 'POST',
      credentials: 'same-origin',
      body: fd
    }).then(function (r) {
      return r.json();
    });
  }

  function debounce(fn, ms) {
    var t;
    return function () {
      var a = arguments, c = this;
      clearTimeout(t);
      t = setTimeout(function () {
        fn.apply(c, a);
      }, ms);
    };
  }

  /* Копіювання */
  document.addEventListener('click', function (e) {
    var b = e.target.closest && e.target.closest('[data-copy]');
    if (!b) return;

    var value = b.getAttribute('data-copy');

    function done() {
      var old = b.textContent;
      b.textContent = 'Скопійовано ✓';
      setTimeout(function () {
        b.textContent = old;
      }, 1500);
    }

    if (navigator.clipboard && navigator.clipboard.writeText) {
      navigator.clipboard.writeText(value).then(done).catch(function () {});
    } else {
      var t = el('textarea');
      t.value = value;
      document.body.appendChild(t);
      t.select();
      try {
        document.execCommand('copy');
        done();
      } catch (x) {}
      document.body.removeChild(t);
    }
  });

  /* Поділитися */
  var share = $('#gcm-share');

  if (share && navigator.share) {
    share.hidden = false;
    share.addEventListener('click', function () {
      navigator.share({
        title: share.dataset.title,
        url: share.dataset.url
      }).catch(function () {});
    });
  }

  /* Спосіб оплати */
  var method = $('#gcm-method');
  var cardBox = $('#gcm-card-box');

  if (method && cardBox) {
    var syncPayment = function () {
      cardBox.hidden = method.value !== 'card';
    };
    method.addEventListener('change', syncPayment);
    syncPayment();
  }

  /* Попередній перегляд */
  var out = $('#gcm-preview-out');
  var pv = $('#gcm-preview-btn');
  var vl = $('#gcm-validate-btn');

  if (pv && out) {
    pv.addEventListener('click', function () {
      out.textContent = 'Завантажую…';
      pv.disabled = true;

      post('gcm_preview_case', { case_id: pv.dataset.case })
        .then(function (r) {
          if (r.success) {
            out.innerHTML = r.data.html;
          } else {
            out.textContent = (r.data && r.data.message) || 'Не вдалося завантажити перегляд.';
          }
        })
        .catch(function () {
          out.textContent = 'Помилка мережі.';
        })
        .then(function () {
          pv.disabled = false;
        });
    });
  }

  if (vl && out) {
    vl.addEventListener('click', function () {
      out.textContent = 'Перевіряю в Google…';
      vl.disabled = true;

      post('gcm_validate_case', { case_id: vl.dataset.case })
        .then(function (r) {
          out.textContent = '';

          var box = el(
            'div',
            { 'class': 'gcm-alert ' + (r.success && r.data.ok ? 'gcm-alert-ok' : 'gcm-alert-err') }
          );

          var lines = r.success
            ? r.data.lines
            : [(r.data && r.data.message) || 'Не вдалося перевірити.'];

          lines.forEach(function (line) {
            box.appendChild(el('div', null, line));
          });

          out.appendChild(box);
        })
        .catch(function () {
          out.textContent = 'Помилка мережі.';
        })
        .then(function () {
          vl.disabled = false;
        });
    });
  }

  /* Форма роботи */
  var form = $('#gcm-work');
  if (!form) return;

  var isEdit = form.dataset.edit === '1';
  var kind = form.dataset.kind;

  function currentKind() {
    return isEdit
      ? kind
      : (($('input[name=kind]:checked', form) || {}).value || 'map');
  }

  function applyKind() {
    kind = currentKind();

    $$('.gcm-map-only', form).forEach(function (b) {
      b.hidden = kind === 'reviews';
    });

    var none = $('#gcm-none-opt');
    if (none) none.hidden = kind === 'reviews';
  }

  $$('input[name=kind]', form).forEach(function (r) {
    r.addEventListener('change', applyKind);
  });

  applyKind();

  /* Елементи клієнта */
  var email = $('#gcm-email');
  var result = $('#gcm-client-result');
  var clientName = $('#gcm-cname');
  var clientPhone = $('#gcm-cphone');
  var googleEmail = $('#gcm-google-email');

  var q = $('#gcm-q');
  var find = $('#gcm-find');
  var cands = $('#gcm-cands');

  var pid = $('#gcm-place-id');
  var murl = $('#gcm-maps-url');
  var rt = $('#gcm-rt');
  var pst = $('#gcm-place-status');

  var clientState = isEdit ? 'valid' : 'idle';
  var blocked = false;
  var lookupSequence = 0;
  var searchSequence = 0;
  var currentEmail = '';
  var selectedPlace = false;

  function validEmail(value) {
    return /^\S+@\S+\.\S+$/.test(value);
  }

  function setClientMessage(message, cls) {
    if (!result) return;
    result.textContent = message || '';
    result.className = cls || 'gcm-muted';
  }

  function setStatus(message, cls) {
    if (!pst) return;
    pst.hidden = !message;
    pst.textContent = message || '';
    pst.className = 'gcm-alert ' + (cls || '');
  }

  function clearPlaceSelection() {
    selectedPlace = false;

    if (rt) rt.value = '';
    if (pid) pid.value = '';
    if (murl) murl.value = '';

    if (cands) cands.textContent = '';

    setStatus('', '');
  }

  function clientIsReady() {
    return isEdit || clientState === 'new' || clientState === 'mine';
  }

  function syncSearchControls() {
    var ready = clientIsReady() && !blocked;

    if (find) find.disabled = !ready;
    if (q) q.disabled = !ready;

    if (googleEmail) googleEmail.disabled = !ready;
  }

  function syncSubmit() {
    var btn = form.querySelector('button.gcm-btn-lg');
    if (!btn) return;

    var ready = clientIsReady() && !blocked;

    if (currentKind() === 'reviews') {
      ready = ready && !!(pid && pid.value);
    } else if (!isEdit) {
      ready = ready && !!(rt && rt.value);
    }

    btn.disabled = !ready;
  }

  function resetClientFields() {
    if (clientName) clientName.value = '';
    if (clientPhone) clientPhone.value = '';
    if (googleEmail) googleEmail.value = '';
  }

  function showClientCases(cases) {
    if (!result || !cases || !cases.length) return;

    result.appendChild(document.createTextNode(' Попередні роботи: '));

    cases.forEach(function (c, i) {
      var a = el('a', {
        href: c.url
      }, '#' + c.id + ' ' + (c.biz || c.type) + ' (' + c.status + ')');

      result.appendChild(a);

      if (i < cases.length - 1) {
        result.appendChild(document.createTextNode(', '));
      }
    });
  }

  function lookupClient(value) {
    var sequence = ++lookupSequence;
    currentEmail = value;

    blocked = false;
    clientState = 'checking';

    clearPlaceSelection();

    if (!validEmail(value)) {
      clientState = 'idle';
      resetClientFields();
      setClientMessage('Введіть коректний email клієнта для перевірки.');
      syncSearchControls();
      syncSubmit();
      return;
    }

    setClientMessage('Перевіряю клієнта…');
    syncSearchControls();
    syncSubmit();

    post('gcm_lookup_client', { email: value })
      .then(function (r) {
        if (sequence !== lookupSequence || !email || email.value.trim().toLowerCase() !== value.toLowerCase()) {
          return;
        }

        if (!r.success) {
          clientState = 'error';
          setClientMessage(
            (r.data && r.data.message) || 'Не вдалося перевірити клієнта. Спробуйте ще раз.',
            'gcm-alert gcm-alert-err'
          );
          return;
        }

        var d = r.data || {};

        if (d.state === 'invalid') {
          clientState = 'idle';
          setClientMessage('Введіть коректний email.');
          return;
        }

        if (d.state === 'other') {
          clientState = 'blocked';
          blocked = true;
          setClientMessage(
            'Цей клієнт уже закріплений за іншим менеджером. Зверніться до адміністратора.',
            'gcm-alert gcm-alert-err'
          );
          return;
        }

        if (d.state === 'new') {
          clientState = 'new';
          setClientMessage('Новий клієнт. Вкажіть ім’я, телефон та Google email клієнта.');
          return;
        }

        if (d.state === 'mine') {
          clientState = 'mine';

          if (clientName && !clientName.value) {
            clientName.value = d.name || '';
          }

          if (clientPhone && !clientPhone.value) {
            clientPhone.value = d.phone || '';
          }

          if (googleEmail && !googleEmail.value) {
            googleEmail.value = d.google_email || '';
          }

          setClientMessage('Це ваш клієнт: ' + (d.name || '') + '.');

          showClientCases(d.cases || []);

          if (q && !q.value && d.cases && d.cases.length && d.cases[0].biz) {
            q.value = d.cases[0].biz;
          }

          return;
        }

        clientState = 'error';
        setClientMessage('Невідома відповідь сервера. Повторіть перевірку.');
      })
      .catch(function () {
        if (sequence !== lookupSequence) return;

        clientState = 'error';
        setClientMessage('Помилка мережі під час перевірки клієнта.', 'gcm-alert gcm-alert-err');
      })
      .then(function () {
        if (sequence !== lookupSequence) return;
        syncSearchControls();
        syncSubmit();
      });
  }

  if (email && result && !isEdit) {
    var delayedLookup = debounce(function () {
      lookupClient(email.value.trim().toLowerCase());
    }, 400);

    email.addEventListener('input', function () {
      lookupSequence++;
      searchSequence++;

      clientState = 'idle';
      blocked = false;

      resetClientFields();
      clearPlaceSelection();

      setClientMessage('Перевіряю email…');
      syncSearchControls();
      syncSubmit();

      delayedLookup();
    });

    if (email.value.trim()) {
      lookupClient(email.value.trim().toLowerCase());
    } else {
      setClientMessage('Спочатку введіть email клієнта.');
    }
  }

  /* Пошук Google */
  function choose(type, id, url, name) {
    if (!clientIsReady() || blocked) return;

    selectedPlace = true;

    if (rt) {
      rt.value = currentKind() === 'reviews' ? 'reviews' : type;
    }

    if (pid) pid.value = id || '';
    if (murl) murl.value = url || '';

    if (type === 'edit') {
      setStatus(
        currentKind() === 'reviews'
          ? 'Картку вибрано.'
          : 'Вибрано існуючу картку. Заявка — на редагування.',
        'gcm-alert-ok'
      );

      var bn = form.elements.biz_name;

      if (bn && !bn.value && name) {
        bn.placeholder = name;
      }
    } else {
      setStatus('Картки немає — буде створена нова. Заповніть дані нижче.', 'gcm-alert-ok');
    }

    syncSubmit();
  }

  function renderCands(list) {
    if (!cands) return;

    cands.textContent = '';

    list.forEach(function (p) {
      var lab = el('label', { 'class': 'gcm-cand' });
      var radio = el('input', { type: 'radio', name: 'gcm_cand' });

      radio.addEventListener('change', function () {
        choose('edit', p.id, p.url, p.name);
      });

      lab.appendChild(radio);

      var box = el('span');

      box.appendChild(el('strong', null, p.name || '(без назви)'));
      box.appendChild(el('br'));
      box.appendChild(el('span', { 'class': 'gcm-muted' }, p.address || ''));

      if (p.url) {
        box.appendChild(document.createTextNode(' '));
        box.appendChild(el('a', {
          href: p.url,
          target: '_blank',
          rel: 'noopener'
        }, 'на мапі ↗'));
      }

      lab.appendChild(box);
      cands.appendChild(lab);
    });

    if (currentKind() !== 'reviews') {
      var none = el('label', {
        'class': 'gcm-cand',
        id: 'gcm-none-opt'
      });

      var nr = el('input', { type: 'radio', name: 'gcm_cand' });

      nr.addEventListener('change', function () {
        choose('create', '', '', '');
      });

      none.appendChild(nr);
      none.appendChild(el('span', null, 'Жоден не підходить — картки на Google немає, створити нову'));
      cands.appendChild(none);
    }

    if (!list.length) {
      setStatus(
        currentKind() === 'reviews'
          ? 'Нічого не знайдено — уточніть назву або вставте посилання.'
          : 'Нічого не знайдено. Якщо картки точно немає — оберіть «створити нову».',
        ''
      );
    } else {
      setStatus('', '');
    }

    syncSubmit();
  }

  function runSearch() {
    if (!clientIsReady() || blocked) {
      setStatus('Спочатку введіть email клієнта та дочекайтеся перевірки.', 'gcm-alert-err');
      return;
    }

    var value = q ? q.value.trim() : '';

    if (!value) {
      setStatus('Введіть назву й місто або посилання на Google Maps.', 'gcm-alert-err');
      return;
    }

    var sequence = ++searchSequence;

    clearPlaceSelection();

    if (find) find.disabled = true;
    if (cands) cands.textContent = 'Шукаю на Google…';

    post('gcm_search_place', { q: value })
      .then(function (r) {
        if (sequence !== searchSequence) return;

        if (!r.success) {
          if (cands) cands.textContent = '';
          setStatus((r.data && r.data.message) || 'Помилка пошуку.', 'gcm-alert-err');
          return;
        }

        renderCands(r.data.places || []);
      })
      .catch(function () {
        if (sequence !== searchSequence) return;

        if (cands) cands.textContent = '';
        setStatus('Помилка мережі під час пошуку Google.', 'gcm-alert-err');
      })
      .then(function () {
        if (sequence === searchSequence) {
          syncSearchControls();
          syncSubmit();
        }
      });
  }

  if (find && q) {
    find.addEventListener('click', runSearch);

    q.addEventListener('keydown', function (e) {
      if (e.key === 'Enter') {
        e.preventDefault();
        runSearch();
      }
    });
  }

  /* Категорії */
  var cat = $('#gcm-cat');
  var catId = $('#gcm-cat-id');
  var dl = $('#gcm-cat-list');
  var catMap = {};

  if (cat && dl) {
    cat.addEventListener('input', debounce(function () {
      var value = cat.value.trim();

      if (catMap[value]) {
        catId.value = catMap[value];
        return;
      }

      catId.value = '';

      if (value.length < 2) return;

      post('gcm_categories', { term: value }).then(function (r) {
        if (!r.success) return;

        dl.textContent = '';

        r.data.items.forEach(function (item) {
          catMap[item.name] = item.id;

          var option = el('option');
          option.value = item.name;
          dl.appendChild(option);
        });

        if (catMap[cat.value.trim()]) {
          catId.value = catMap[cat.value.trim()];
        }
      });
    }, 300));

    cat.addEventListener('change', function () {
      var value = cat.value.trim();
      if (catMap[value]) catId.value = catMap[value];
    });
  }

  /* Опис */
  var desc = $('#gcm-desc');
  var cnt = $('#gcm-desc-count');

  if (desc && cnt) {
    var updateCount = function () {
      cnt.textContent = desc.value.length + '/750';
    };

    desc.addEventListener('input', updateCount);
    updateCount();
  }

  /* Години */
  var hon = $('#gcm-hours-on');
  var hbox = $('#gcm-hours');

  if (hon && hbox) {
    hon.addEventListener('change', function () {
      hbox.hidden = !hon.checked;
    });
  }

  /* Попередній перегляд файлів */
  var prev = $('#gcm-previews');

  if (prev) {
    $$('.gcm-file', form).forEach(function (inp) {
      inp.addEventListener('change', function () {
        prev.textContent = '';

        $$('.gcm-file', form).forEach(function (input) {
          Array.prototype.forEach.call(input.files, function (file) {
            var box = el('div', { 'class': 'gcm-thumb' });

            if (/^image\//.test(file.type)) {
              var im = el('img');
              im.src = URL.createObjectURL(file);
              box.appendChild(im);
            } else if (/^video\//.test(file.type)) {
              var video = el('video', { muted: '', playsinline: '' });
              video.src = URL.createObjectURL(file);
              box.appendChild(video);
            }

            box.appendChild(el('span', { 'class': 'gcm-muted' }, file.name.slice(0, 24)));
            prev.appendChild(box);
          });
        });
      });
    });
  }

  /* Перевірка перед відправкою */
  form.addEventListener('submit', function (e) {
    if (blocked || !clientIsReady()) {
      e.preventDefault();
      alert('Спочатку введіть email клієнта та дочекайтеся успішної перевірки.');
      return;
    }

    var k = currentKind();

    if (k === 'reviews') {
      if (!pid || !pid.value) {
        e.preventDefault();
        setStatus('Знайдіть і оберіть картку клієнта.', 'gcm-alert-err');
        if (q) q.focus();
        return;
      }
    } else if (!isEdit && (!rt || !rt.value)) {
      e.preventDefault();
      setStatus('Знайдіть картку на Google й оберіть варіант зі списку або «створити нову».', 'gcm-alert-err');
      if (q) q.focus();
      return;
    }

    var btn = form.querySelector('button.gcm-btn-lg');

    if (btn) {
      btn.disabled = true;
      btn.textContent = 'Зберігаю…';
    }
  });

  syncSearchControls();
  syncSubmit();
})();
