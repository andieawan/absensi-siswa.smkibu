// JS ringan (tanpa framework). Semua fitur bersifat tambahan: halaman tetap berfungsi tanpa JavaScript.
(function () {
  'use strict';
  var $ = function (s, r) { return (r || document).querySelector(s); };
  var $$ = function (s, r) { return Array.prototype.slice.call((r || document).querySelectorAll(s)); };

  // ---- Dialog konfirmasi/pemberitahuan sendiri (pengganti confirm()/alert() bawaan browser) ----
  var Dlg = (function () {
    var root, last;
    function close() { if (root) { root.remove(); root = null; } if (last && last.focus) { try { last.focus(); } catch (e) {} } }
    function open(msg, o) {
      if (root) close();
      last = document.activeElement;
      root = document.createElement('div');
      root.className = 'dlg-back';
      var danger = o.danger ? ' btn-danger' : ' btn-pri';
      root.innerHTML = '<div class="dlg" role="alertdialog" aria-modal="true" aria-labelledby="dlg-t" aria-describedby="dlg-m"><h2 id="dlg-t"></h2><p id="dlg-m"></p><div class="dlg-act"></div></div>';
      $('#dlg-t', root).textContent = o.title;
      $('#dlg-m', root).textContent = msg;
      var act = $('.dlg-act', root), ok = document.createElement('button');
      ok.type = 'button'; ok.className = 'btn' + danger; ok.textContent = o.ok;
      if (o.cancel) {
        var no = document.createElement('button'); no.type = 'button'; no.className = 'btn'; no.textContent = o.cancel;
        no.addEventListener('click', close); act.appendChild(no);
      }
      act.appendChild(ok);
      ok.addEventListener('click', function () { close(); if (o.onOk) o.onOk(); });
      root.addEventListener('mousedown', function (e) { if (e.target === root && o.cancel) close(); });
      root.addEventListener('keydown', function (e) {
        if (e.key === 'Escape') { e.preventDefault(); close(); }
        else if (e.key === 'Tab') {
          var b = $$('button', root), f = b[0], l = b[b.length - 1];
          if (e.shiftKey && document.activeElement === f) { e.preventDefault(); l.focus(); }
          else if (!e.shiftKey && document.activeElement === l) { e.preventDefault(); f.focus(); }
        }
      });
      document.body.appendChild(root);
      (o.danger && o.cancel ? $$('button', root)[0] : ok).focus(); // aksi berbahaya: fokus awal di Batal
    }
    return {
      confirm: function (msg, onOk) {
        var danger = /hapus|cabut|timpa|reset|nonaktif|kosongkan|buang/i.test(msg);
        open(msg, { title: 'Konfirmasi', ok: danger ? 'Ya, lanjutkan' : 'Ya', cancel: 'Batal', danger: danger, onOk: onOk });
      },
      alert: function (msg) { open(msg, { title: 'Perhatian', ok: 'Mengerti' }); }
    };
  })();
  var alert = function (m) { Dlg.alert(m); };

  // ---- Konfirmasi: <form data-confirm="…"> atau <a/button data-confirm="…"> ----
  document.addEventListener('submit', function (e) {
    var f = e.target, m = f.getAttribute && f.getAttribute('data-confirm');
    if (!m || f.__ok) { f.__ok = false; return; }
    e.preventDefault();
    var sub = e.submitter;
    Dlg.confirm(m, function () { f.__ok = true; if (f.requestSubmit) f.requestSubmit(sub || undefined); else f.submit(); });
  });

  document.addEventListener('click', function (e) {
    var t = e.target.closest('[data-confirm]');
    if (t && t.tagName !== 'FORM') {
      if (t.__ok) { t.__ok = false; }
      else {
        e.preventDefault(); e.stopPropagation();
        Dlg.confirm(t.getAttribute('data-confirm'), function () { t.__ok = true; t.click(); });
        return;
      }
    }
    // Tandai semua dengan status tertentu
    var all = e.target.closest('[data-setall]');
    if (all) {
      var v = all.getAttribute('data-setall');
      $$('input[type=radio][data-status="' + v + '"]').forEach(function (r) {
        if (!r.checked) { r.checked = true; r.dispatchEvent(new Event('change', { bubbles: true })); }
      });
      recount();
    }
    // Salin teks
    var cp = e.target.closest('[data-copy]');
    if (cp) {
      var el = $(cp.getAttribute('data-copy'));
      if (el) {
        var text = el.textContent.trim();
        var done = function () { var old = cp.textContent; cp.textContent = 'Tersalin ✓'; setTimeout(function () { cp.textContent = old; }, 1600); };
        if (navigator.clipboard) navigator.clipboard.writeText(text).then(done, function () { selectText(el); });
        else selectText(el);
      }
    }
    // Tampilkan/sembunyikan password
    var eye = e.target.closest('[data-toggle-pw]');
    if (eye) {
      var pw = $(eye.getAttribute('data-toggle-pw'));
      if (pw) {
        var show = pw.type === 'password';
        pw.type = show ? 'text' : 'password';
        eye.textContent = show ? 'Sembunyi' : 'Lihat';
        eye.setAttribute('aria-pressed', show ? 'true' : 'false');
        eye.setAttribute('aria-label', show ? 'Sembunyikan password' : 'Tampilkan password');
      }
    }
    // Tutup pesan
    var x = e.target.closest('[data-dismiss]');
    if (x) { var box = x.closest('.alert'); if (box) box.remove(); }
    // Buka menu akun dari navigasi bawah
    if (e.target.closest('[data-open-acct]')) {
      var acct = $('.acct');
      if (acct) { acct.open = !acct.open; if (acct.open) { var first = $('.acct-menu a, .acct-menu button:not(.hide)', acct); if (first) first.focus(); } }
      return;
    }
    // Tutup menu yang terbuka bila klik di luar
    $$('details[data-close-outside][open]').forEach(function (d) { if (!d.contains(e.target)) d.open = false; });
    // Buka/tutup catatan absensi
    var nt = e.target.closest('[data-note-toggle]');
    if (nt) {
      var inp = document.getElementById(nt.getAttribute('aria-controls'));
      if (inp) {
        var open = !inp.classList.contains('note-open');
        inp.classList.toggle('note-open', open);
        nt.setAttribute('aria-expanded', open ? 'true' : 'false');
        if (open) inp.focus();
      }
    }
  });

  function selectText(el) {
    var r = document.createRange(); r.selectNodeContents(el);
    var s = window.getSelection(); s.removeAllRanges(); s.addRange(r);
  }

  document.addEventListener('keydown', function (e) {
    if (e.key === 'Escape') $$('details[data-close-outside][open]').forEach(function (d) { d.open = false; });
  });

  // ---- Ringkasan H/I/S/A pada form absensi ----
  function recount() {
    var box = document.getElementById('recount');
    if (!box) return;
    var c = { H: 0, I: 0, S: 0, A: 0, D: 0 };
    $$('input[type=radio][data-status]:checked').forEach(function (r) { c[r.getAttribute('data-status')]++; });
    ['H', 'I', 'S', 'A', 'D'].forEach(function (k) {
      var el = box.querySelector('[data-c="' + k + '"]');
      if (el) el.textContent = c[k];
    });
  }
  document.addEventListener('change', function (e) {
    if (e.target.matches && e.target.matches('input[type=radio][data-status]')) recount();
  });
  recount();

  // ---- Nilai: isi cepat, Enter pindah ke siswa berikutnya, hitung yang sudah terisi ----
  function countFilled() {
    var out = $('[data-filled]');
    if (!out) return;
    var n = 0;
    $$('input.score').forEach(function (i) { if (i.value.trim() !== '') n++; });
    out.textContent = n + $$('.stu input[disabled]').length;
  }
  document.addEventListener('click', function (e) {
    var q = e.target.closest('[data-quickfill]');
    if (!q) return;
    var v = q.getAttribute('data-quickfill');
    var empty = $$('input.score').filter(function (i) { return i.value.trim() === ''; });
    var targets = empty.length ? empty : $$('input.score');
    var apply = function () {
      targets.forEach(function (i) { i.value = v; });
      markDirty(q.closest('form'));
      countFilled();
    };
    if (!empty.length) Dlg.confirm('Semua siswa sudah bernilai. Timpa semua nilai dengan ' + v + '?', apply); else apply();
  });
  document.addEventListener('input', function (e) { if (e.target.matches && e.target.matches('input.score')) countFilled(); });
  document.addEventListener('keydown', function (e) {
    if (e.key !== 'Enter' || !e.target.matches || !e.target.matches('input.score')) return;
    e.preventDefault();
    var list = $$('input.score');
    var next = list[list.indexOf(e.target) + 1];
    if (next) { next.focus(); next.select(); }
    else { var btn = e.target.form && e.target.form.querySelector('.sticky-save .btn-pri'); if (btn) btn.focus(); }
  });
  countFilled();

  var tipe = document.getElementById('tipe_skala');
  if (tipe) {
    var apply = function () {
      $$('input.score').forEach(function (i) {
        if (tipe.value === 'angka') { i.inputMode = 'decimal'; i.placeholder = '0–100'; i.maxLength = 6; }
        else { i.inputMode = 'text'; i.placeholder = 'A–E'; i.maxLength = 1; i.autocapitalize = 'characters'; }
      });
    };
    tipe.addEventListener('change', apply);
    apply();
  }

  // ---- Peringatan perubahan belum disimpan ----
  var dirty = null;
  function markDirty(form) { if (form && form.hasAttribute('data-unsaved')) dirty = form; }
  document.addEventListener('input', function (e) { markDirty(e.target.form); });
  document.addEventListener('change', function (e) { markDirty(e.target.form); });
  window.addEventListener('beforeunload', function (e) {
    if (dirty) { e.preventDefault(); e.returnValue = ''; }
  });

  // ---- Filter otomatis: <select data-auto> ----
  $$('select[data-auto]').forEach(function (s) {
    s.addEventListener('change', function () {
      // Ganti mapel → daftar kelas hanya yang diajar guru untuk mapel itu.
      if (s.dataset.classMap) {
        var cls = s.form.querySelector('select[name=class]'), m = {};
        try { m = JSON.parse(s.dataset.classMap); } catch (e) {}
        if (cls) {
          var list = m[s.value] || [], cur = cls.value;
          cls.innerHTML = '';
          list.forEach(function (x) { var o = document.createElement('option'); o.value = x[0]; o.textContent = x[1]; if (String(x[0]) === cur) o.selected = true; cls.appendChild(o); });
        }
      }
      s.form.submit();
    });
  });

  // ---- Filter tabel sisi klien: <input data-filter="#tabel"> ----
  $$('input[data-filter]').forEach(function (inp) {
    inp.addEventListener('input', function () {
      var q = inp.value.toLowerCase();
      $$(inp.getAttribute('data-filter') + ' tbody tr, ' + inp.getAttribute('data-filter') + ' .item').forEach(function (tr) {
        tr.classList.toggle('hide', q && tr.textContent.toLowerCase().indexOf(q) === -1);
      });
    });
  });

  // ---- Pilih Kelas → Nama siswa: <select data-pick-class="#siswa"> menyaring <optgroup data-class> ----
  $$('select[data-pick-class]').forEach(function (cls) {
    var stu = $(cls.getAttribute('data-pick-class'));
    if (!stu) return;
    var groups = $$('optgroup[data-class]', stu);
    var apply = function () {
      var v = cls.value;
      groups.forEach(function (g) {
        var show = !v || g.getAttribute('data-class') === v;
        g.hidden = !show; g.disabled = !show;
      });
      var sel = stu.options[stu.selectedIndex];
      if (sel && sel.parentNode.disabled) stu.value = '';
      if (v && groups.length) { var only = groups.filter(function (g) { return !g.disabled; }); if (only.length === 1 && only[0].children.length === 1 && !stu.value) stu.value = only[0].children[0].value; }
    };
    cls.addEventListener('change', apply);
    apply();
  });

  // ---- Absen mandiri: form[data-geo="1"] meminta lokasi GPS sebelum dikirim ----
  document.addEventListener('submit', function (e) {
    var f = e.target;
    if (!f.matches || !f.matches('form[data-geo="1"]') || f.getAttribute('data-geo-done')) return;
    e.preventDefault();
    var sub = e.submitter;
    var go = function () { f.setAttribute('data-geo-done', '1'); if (sub && sub.name) f.querySelector('input[name=aksi]').value = sub.value; HTMLFormElement.prototype.submit.call(f); };
    if (!navigator.geolocation) { alert('Browser ini tidak mendukung lokasi. Gunakan browser lain atau hubungi TU.'); return; }
    var busy = sub; if (busy) { busy.disabled = true; busy.dataset.old = busy.textContent; busy.textContent = 'Mengambil lokasi…'; }
    navigator.geolocation.getCurrentPosition(function (p) {
      f.elements['lat'].value = p.coords.latitude; f.elements['lng'].value = p.coords.longitude; go();
    }, function () {
      if (busy) { busy.disabled = false; busy.textContent = busy.dataset.old; }
      alert('Lokasi tidak dapat dibaca. Aktifkan GPS dan izinkan akses lokasi untuk situs ini (perlu alamat https://), lalu coba lagi.');
    }, { enableHighAccuracy: true, timeout: 15000, maximumAge: 30000 });
  }, true);

  // ---- Isi koordinat dari lokasi saat ini: <button data-geo-fill="#lat,#lng"> ----
  document.addEventListener('click', function (e) {
    var b = e.target.closest('[data-geo-fill]');
    if (!b) return;
    var ids = b.getAttribute('data-geo-fill').split(',');
    if (!navigator.geolocation) { alert('Browser tidak mendukung lokasi.'); return; }
    b.disabled = true;
    navigator.geolocation.getCurrentPosition(function (p) {
      $(ids[0]).value = p.coords.latitude.toFixed(7); $(ids[1]).value = p.coords.longitude.toFixed(7); b.disabled = false;
    }, function () { b.disabled = false; alert('Lokasi tidak dapat dibaca. Izinkan akses lokasi dan pastikan memakai https://.'); }, { enableHighAccuracy: true, timeout: 15000 });
  });

  // ---- Ambil foto langsung dari kamera HP: <button data-camera="#input-file"> ----
  document.addEventListener('click', function (e) {
    var b = e.target.closest('[data-camera]');
    if (!b) return;
    var inp = $(b.getAttribute('data-camera'));
    if (!inp) return;
    var acc = inp.getAttribute('accept');
    inp.setAttribute('capture', 'environment'); inp.setAttribute('accept', 'image/*');
    inp.click();
    setTimeout(function () { inp.removeAttribute('capture'); inp.setAttribute('accept', acc); }, 1000);
  });

  // ---- Tampilkan kolom sesuai jenis surat: <select data-jenis-switch> + elemen [data-jenis="a b"] ----
  $$('select[data-jenis-switch]').forEach(function (sel) {
    var form = sel.form;
    var apply = function () {
      $$('[data-jenis]', form).forEach(function (el) {
        var on = el.getAttribute('data-jenis').split(' ').indexOf(sel.value) !== -1;
        el.classList.toggle('hide', !on);
        $$('input,select', el).forEach(function (i) { i.disabled = !on; });
      });
    };
    sel.addEventListener('change', apply);
    apply();
  });

  // ---- Cegah kirim ganda + tampilkan status "Menyimpan…" ----
  document.addEventListener('submit', function (e) {
    var f = e.target;
    if (e.defaultPrevented) return;
    if (f === dirty) dirty = null;
    if (f.hasAttribute('data-multi')) return;
    var btn = e.submitter || f.querySelector('button:not([type=button])');
    setTimeout(function () {
      $$('button:not([type=button])', f).forEach(function (b) { b.disabled = true; });
      if (btn && btn.hasAttribute('data-busy')) { btn.classList.add('is-busy'); btn.textContent = btn.getAttribute('data-busy'); }
    }, 0);
  });
  // Tombol kembali ke halaman dari cache: aktifkan lagi tombol
  window.addEventListener('pageshow', function (e) {
    if (e.persisted) $$('button[disabled]').forEach(function (b) { if (!b.hasAttribute('data-keep-disabled')) b.disabled = false; });
  });
})();

// ---- PWA: daftarkan service worker & tombol "Pasang Aplikasi" ----
(function () {
  'use strict';
  var meta = document.querySelector('meta[name="sw-url"]');
  if ('serviceWorker' in navigator && meta && (location.protocol === 'https:' || location.hostname === 'localhost' || location.hostname === '127.0.0.1')) {
    window.addEventListener('load', function () {
      navigator.serviceWorker.register(meta.content).catch(function () {});
    });
  }
  var btn = document.getElementById('pwa-install');
  var deferred = null;
  window.addEventListener('beforeinstallprompt', function (e) {
    e.preventDefault();
    deferred = e;
    if (btn) btn.classList.remove('hide');
  });
  if (btn) {
    btn.addEventListener('click', function () {
      if (!deferred) return;
      deferred.prompt();
      deferred.userChoice.finally(function () { deferred = null; btn.classList.add('hide'); });
    });
  }
  window.addEventListener('appinstalled', function () { if (btn) btn.classList.add('hide'); });

  // ---- Pertahankan posisi scroll saat filter/tab/halaman diganti (halaman yang sama) ----
  (function () {
    var K = 'keepScroll';
    function save(y) { try { sessionStorage.setItem(K, JSON.stringify({ p: location.pathname, y: y == null ? window.scrollY : y })); } catch (e) {} }
    document.addEventListener('click', function (e) {
      var a = e.target.closest && e.target.closest('.pager a, .seg a, .tabs-keep a');
      if (a && a.pathname === location.pathname && !a.closest('.nav, .bottom-nav')) {
        var box = a.closest('.pager') ? (a.closest('.card') || a.closest('aside') || a.parentNode) : null;
        save(box ? Math.max(0, box.getBoundingClientRect().top + window.scrollY - 80) : null); // pager: kembali ke awal daftar
      }
    });
    document.addEventListener('change', function (e) { if (e.target.matches && e.target.matches('select[data-auto]')) save(); }, true);
    try {
      var d = JSON.parse(sessionStorage.getItem(K) || 'null');
      sessionStorage.removeItem(K);
      if (d && d.p === location.pathname && !location.hash && d.y > 0) {
        if ('scrollRestoration' in history) history.scrollRestoration = 'manual';
        window.scrollTo(0, d.y);
      }
    } catch (e) {}
  })();

  // ---- Ganti isi area [data-swap] tanpa muat ulang halaman (posisi layar tetap) ----
  document.addEventListener('click', function (e) {
    var a = e.target.closest && e.target.closest('[data-swap] .pager a[href], [data-swap] .seg a[href]');
    if (!a || e.defaultPrevented || e.metaKey || e.ctrlKey || e.shiftKey || e.button !== 0 || a.target) return;
    var box = a.closest('[data-swap]');
    if (!box.id || !window.fetch || !window.DOMParser || a.pathname !== location.pathname) return;
    e.preventDefault();
    var url = a.href.split('#')[0];
    box.style.opacity = '.55';
    fetch(url, { headers: { 'X-Requested-With': 'XMLHttpRequest' }, credentials: 'same-origin' }).then(function (r) {
      if (!r.ok || r.redirected && new URL(r.url).pathname !== location.pathname) throw 0;
      return r.text();
    }).then(function (t) {
      var n = new DOMParser().parseFromString(t, 'text/html').getElementById(box.id);
      if (!n) throw 0;
      box.replaceWith(n);
      history.replaceState(null, '', url + '#' + box.id);
    }).catch(function () { location.href = a.href; });
  });
})();
