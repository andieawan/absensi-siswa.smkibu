// JS ringan (tanpa framework): konfirmasi, hitung ulang H/I/S/A, "Set Semua", filter tabel, salin tautan.
(function () {
  'use strict';

  // Konfirmasi: <form data-confirm="Yakin?"> atau <button data-confirm="...">
  document.addEventListener('submit', function (e) {
    var m = e.target.getAttribute('data-confirm');
    if (m && !window.confirm(m)) e.preventDefault();
  });
  document.addEventListener('click', function (e) {
    var t = e.target.closest('[data-confirm]');
    if (t && t.tagName !== 'FORM' && !window.confirm(t.getAttribute('data-confirm'))) {
      e.preventDefault();
      e.stopPropagation();
    }
    var all = e.target.closest('[data-setall]');
    if (all) {
      var v = all.getAttribute('data-setall');
      document.querySelectorAll('input[type=radio][data-status="' + v + '"]').forEach(function (r) { r.checked = true; });
      recount();
    }
    var cp = e.target.closest('[data-copy]');
    if (cp) {
      var el = document.querySelector(cp.getAttribute('data-copy'));
      if (el && navigator.clipboard) {
        navigator.clipboard.writeText(el.textContent.trim());
        var old = cp.textContent;
        cp.textContent = 'Tersalin ✓';
        setTimeout(function () { cp.textContent = old; }, 1500);
      }
    }
  });

  // Ringkasan H/I/S/A pada form absensi
  function recount() {
    var box = document.getElementById('recount');
    if (!box) return;
    var c = { H: 0, I: 0, S: 0, A: 0 };
    document.querySelectorAll('input[type=radio][data-status]:checked').forEach(function (r) { c[r.getAttribute('data-status')]++; });
    ['H', 'I', 'S', 'A'].forEach(function (k) {
      var el = box.querySelector('[data-c="' + k + '"]');
      if (el) el.textContent = c[k];
    });
  }
  document.addEventListener('change', function (e) {
    if (e.target.matches && e.target.matches('input[type=radio][data-status]')) recount();
  });
  recount();

  // Isi cepat nilai
  document.addEventListener('click', function (e) {
    var q = e.target.closest('[data-quickfill]');
    if (!q) return;
    var v = q.getAttribute('data-quickfill');
    document.querySelectorAll('input.score').forEach(function (i) { i.value = v; });
  });

  // Ganti tipe skala: ubah atribut input nilai
  var tipe = document.getElementById('tipe_skala');
  if (tipe) {
    var apply = function () {
      document.querySelectorAll('input.score').forEach(function (i) {
        if (tipe.value === 'angka') { i.inputMode = 'decimal'; i.placeholder = '0–100'; i.maxLength = 6; }
        else { i.inputMode = 'text'; i.placeholder = 'A–E'; i.maxLength = 1; }
      });
    };
    tipe.addEventListener('change', apply);
    apply();
  }

  // Filter tabel sisi klien: <input data-filter="#tabel">
  document.querySelectorAll('input[data-filter]').forEach(function (inp) {
    inp.addEventListener('input', function () {
      var q = inp.value.toLowerCase();
      document.querySelectorAll(inp.getAttribute('data-filter') + ' tbody tr, ' + inp.getAttribute('data-filter') + ' .item').forEach(function (tr) {
        tr.classList.toggle('hide', q && tr.textContent.toLowerCase().indexOf(q) === -1);
      });
    });
  });

  // Auto-submit filter: <select data-auto>
  document.querySelectorAll('select[data-auto]').forEach(function (s) {
    s.addEventListener('change', function () { s.form.submit(); });
  });

  // Cegah kirim ganda
  document.addEventListener('submit', function (e) {
    var f = e.target;
    if (e.defaultPrevented || f.hasAttribute('data-multi')) return;
    f.querySelectorAll('button[type=submit],button:not([type])').forEach(function (b) {
      setTimeout(function () { b.disabled = true; }, 0);
    });
  });
})();
