// Grafik halaman detail drum. Waktu selalu ditampilkan dalam WIB.
(function () {
  'use strict';

  const wadah = document.getElementById('grafik');
  if (!wadah || typeof Chart === 'undefined') return;

  const ZONA = 'Asia/Jakarta';
  const fmtJam = new Intl.DateTimeFormat('id-ID', { timeZone: ZONA, hour: '2-digit', minute: '2-digit', hour12: false });
  const fmtHari = new Intl.DateTimeFormat('id-ID', { timeZone: ZONA, day: 'numeric', month: 'short' });
  const fmtLengkap = new Intl.DateTimeFormat('id-ID', {
    timeZone: ZONA, day: 'numeric', month: 'short', year: 'numeric', hour: '2-digit', minute: '2-digit', hour12: false,
  });
  const fmtAngka = new Intl.NumberFormat('id-ID', { maximumFractionDigits: 2 });

  const WARNA = {
    bawah: '#1d4ed8', tengah: '#c2410c', atas: '#15803d', ambien: '#57534e',
    dt: '#1c1917', waspada: '#d97706', bahaya: '#dc2626', co2: '#6d28d9', rh: '#0e7490',
  };

  Chart.defaults.locale = 'id-ID';
  Chart.defaults.font.family = 'ui-sans-serif, system-ui, sans-serif';

  const pesan = document.getElementById('grafik-pesan');
  const catatan = document.getElementById('grafik-catatan');
  const grafik = {};

  // Titik {x, y}. Bila jarak antartitik jauh lebih besar dari selang data, sisipkan
  // titik kosong supaya garis terputus (node mati atau data hilang), bukan disambung.
  function titik(t, nilai, selangMs) {
    const hasil = [];
    for (let i = 0; i < t.length; i++) {
      if (i > 0 && t[i] - t[i - 1] > selangMs * 2.5) hasil.push({ x: t[i - 1] + selangMs, y: null });
      hasil.push({ x: t[i], y: nilai[i] });
    }
    return hasil;
  }

  function garis(label, data, warna, lain) {
    return Object.assign({
      label, data, borderColor: warna, backgroundColor: warna,
      borderWidth: 1.75, pointRadius: 0, pointHitRadius: 8, tension: 0.15, spanGaps: false,
    }, lain || {});
  }

  function ambang(label, y, warna, d) {
    return garis(label, [{ x: d.dari, y }, { x: d.sampai, y }], warna, { borderDash: [6, 4], borderWidth: 1.25, pointHitRadius: 0 });
  }

  function opsi(d, sumbuY) {
    const lebar = d.sampai - d.dari;
    return {
      responsive: true, maintainAspectRatio: false, animation: false,
      interaction: { mode: 'nearest', axis: 'x', intersect: false },
      scales: Object.assign({
        x: {
          type: 'linear', min: d.dari, max: d.sampai,
          ticks: {
            maxTicksLimit: 7, maxRotation: 0,
            callback: (v) => (lebar <= 36 * 3600e3 ? fmtJam.format(v).replace(':', '.') : fmtHari.format(v)),
          },
          grid: { color: '#e7e5e4' },
        },
      }, sumbuY),
      plugins: {
        legend: { position: 'bottom', labels: { boxWidth: 14, boxHeight: 2, font: { size: 12 } } },
        tooltip: {
          filter: (item) => item.parsed.y !== null,
          callbacks: {
            title: (items) => (items.length ? fmtLengkap.format(items[0].parsed.x).replace(':', '.') + ' WIB' : ''),
            label: (item) => item.dataset.label + ': ' + fmtAngka.format(item.parsed.y),
          },
        },
      },
    };
  }

  function gambar(id, datasets, options) {
    if (grafik[id]) {
      grafik[id].data.datasets = datasets;
      grafik[id].options = options;
      grafik[id].update();
    } else {
      grafik[id] = new Chart(document.getElementById(id), { type: 'line', data: { datasets }, options });
    }
  }

  function tampil(d) {
    if (!d.t.length) {
      pesan.textContent = 'Belum ada data pada rentang ini.';
      pesan.classList.remove('hidden');
      wadah.querySelectorAll('[data-grafik]').forEach((el) => el.classList.add('hidden'));
      return;
    }
    pesan.classList.add('hidden');
    wadah.querySelectorAll('[data-grafik]').forEach((el) => el.classList.remove('hidden'));

    const ms = d.selang_s * 1000;
    const y = (judul) => ({ title: { display: false, text: judul }, grid: { color: '#e7e5e4' } });

    gambar('grafik-suhu', [
      garis('Bawah', titik(d.t, d.bawah, ms), WARNA.bawah),
      garis('Tengah', titik(d.t, d.tengah, ms), WARNA.tengah),
      garis('Atas', titik(d.t, d.atas, ms), WARNA.atas),
      garis('Ambien', titik(d.t, d.ambien, ms), WARNA.ambien, { borderDash: [4, 3] }),
    ], opsi(d, { y: y('°C') }));

    gambar('grafik-dt', [
      garis('ΔT', titik(d.t, d.dt, ms), WARNA.dt, { borderWidth: 2 }),
      ambang('Waspada (2 °C)', 2, WARNA.waspada, d),
      ambang('Bahaya (5 °C)', 5, WARNA.bahaya, d),
    ], opsi(d, { y: Object.assign(y('°C'), { suggestedMin: 0, suggestedMax: 6 }) }));

    gambar('grafik-gas', [
      garis('CO₂ (ppm)', titik(d.t, d.co2, ms), WARNA.co2, { yAxisID: 'y' }),
      garis('RH (%)', titik(d.t, d.rh, ms), WARNA.rh, { yAxisID: 'rh' }),
    ], opsi(d, {
      y: Object.assign(y('ppm'), { position: 'left', beginAtZero: true }),
      rh: { position: 'right', min: 0, max: 100, grid: { drawOnChartArea: false } },
    }));

    const ket = [];
    if (d.selang_s > 300) ket.push('Tiap titik adalah rata-rata ' + Math.round(d.selang_s / 60) + ' menit.');
    if (d.tanpa_jam > 0) ket.push(d.tanpa_jam + ' pembacaan memakai waktu server karena jam node belum sinkron.');
    ket.push('Celah pada garis berarti sensor gagal atau tidak ada data.');
    catatan.textContent = ket.join(' ');
    catatan.classList.remove('hidden');
  }

  function muat() {
    fetch(wadah.dataset.url, { headers: { Accept: 'application/json' } })
      .then((r) => { if (!r.ok) throw new Error('HTTP ' + r.status); return r.json(); })
      .then(tampil)
      .catch(() => {
        if (!Object.keys(grafik).length) {
          pesan.textContent = 'Grafik gagal dimuat. Periksa sambungan lalu muat ulang halaman.';
          pesan.classList.remove('hidden');
        }
      });
  }

  muat();
  setInterval(muat, 60000);
})();
