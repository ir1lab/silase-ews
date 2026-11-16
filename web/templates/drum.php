<?php

use Silase\Drum\Deret;
use Silase\Web\Format as F;

/** @var array $device */
/** @var ?array $terakhir */
/** @var Silase\Drum\Kondisi $kondisi */
/** @var list<string> $sensorGagal */
/** @var string $rentang */
/** @var list<array> $peringatan */

$id = rawurlencode($device['device_id']);
$redup = $kondisi->segar() ? '' : 'opacity-50';
$nilai = [
    ['Bawah', $terakhir['t_bawah'] ?? null, '°C', 1],
    ['Tengah', $terakhir['t_tengah'] ?? null, '°C', 1],
    ['Atas', $terakhir['t_atas'] ?? null, '°C', 1],
    ['Ambien', $terakhir['t_ambien'] ?? null, '°C', 1],
    ['CO₂', $terakhir['co2_ppm'] ?? null, 'ppm', 0],
    ['RH headspace', $terakhir['hs_rh'] ?? null, '%', 1],
];
?>
<a href="/" class="text-sm text-stone-600 hover:underline">← Ringkasan</a>

<section class="mt-2 rounded-lg border border-stone-300 border-l-8 <?= $kondisi->kelasTepi ?> bg-white p-4 shadow-sm">
  <div class="flex items-start justify-between gap-3">
    <div class="min-w-0">
      <h1 class="truncate text-xl font-bold"><?= F::e($device['nama']) ?></h1>
      <p class="text-sm text-stone-500">
        <?= F::e($device['lokasi'] ?: 'Lokasi belum diisi') ?> · <?= F::e($device['device_id']) ?>
<?php if ($device['fw']) : ?>
        · firmware <?= F::e($device['fw']) ?>
<?php endif; ?>
      </p>
    </div>
    <span class="shrink-0 rounded-full px-3 py-1 text-sm font-bold uppercase tracking-wide <?= $kondisi->kelasLencana ?>">
      <?= F::e($kondisi->label) ?>
    </span>
  </div>

<?php if ($terakhir === null) : ?>
  <p class="mt-4 text-sm text-stone-600">Drum ini belum mengirim pembacaan.</p>
<?php else : ?>
<?php if (!$kondisi->segar()) : ?>
  <p class="mt-3 rounded bg-stone-200 px-3 py-2 text-sm font-medium text-stone-800">
    Tidak ada data baru sejak <?= F::e(F::wib($terakhir['measured_at'])) ?>
    (<?= F::e(F::lalu($kondisi->umurDataDetik ?? 0)) ?>). Angka di bawah adalah pembacaan terakhir.
  </p>
<?php endif; ?>

  <div class="mt-4 flex flex-wrap items-end gap-x-8 gap-y-3 <?= $redup ?>">
    <div>
      <p class="text-xs uppercase tracking-wide text-stone-500">Selisih suhu (ΔT)</p>
      <p>
        <span class="text-4xl font-bold tabular-nums <?= $kondisi->kelasTeks ?>"><?= F::angka($terakhir['dt']) ?></span>
        <span class="text-sm text-stone-600">°C di atas ambien</span>
      </p>
    </div>
    <div class="text-sm text-stone-700">
      <p><?= F::e($terakhir['reason'] ?? '') ?><?= $terakhir['hotspot'] ? ' · titik terpanas: lapis ' . F::e($terakhir['hotspot']) : '' ?></p>
      <p class="text-stone-500">
        Umur siklus <?= F::e(F::umur($terakhir['cycle_h'])) ?><?= (int) $terakhir['stabilizing'] ? ' · masih fase stabilisasi' : '' ?>
<?php if ((int) $terakhir['co2_leak']) : ?>
        · <span class="font-semibold text-amber-700">CO₂ turun tajam, periksa kebocoran udara</span>
<?php endif; ?>
      </p>
    </div>
  </div>

  <dl class="mt-4 grid grid-cols-3 gap-2 sm:grid-cols-6 <?= $redup ?>">
<?php foreach ($nilai as [$nama, $v, $satuan, $des]) : ?>
    <div class="rounded bg-stone-100 px-2 py-2">
      <dt class="text-xs text-stone-500"><?= $nama ?></dt>
      <dd class="text-base font-semibold tabular-nums">
        <?= F::angka($v, $des) ?><span class="ml-0.5 text-xs font-normal text-stone-500"><?= $satuan ?></span>
      </dd>
    </div>
<?php endforeach; ?>
  </dl>

<?php if ($sensorGagal !== []) : ?>
  <div class="mt-3 rounded border border-amber-300 bg-amber-50 px-3 py-2 text-sm text-amber-900">
    <span class="font-semibold">Sensor gagal:</span> <?= F::e(implode(', ', $sensorGagal)) ?>.
    Nilainya dikosongkan dan tampil sebagai celah pada grafik.
  </div>
<?php endif; ?>

  <p class="mt-3 text-xs text-stone-500">
    Pembacaan terakhir <?= F::e(F::wib($terakhir['measured_at'])) ?><?= (int) $terakhir['ts_valid'] ? '' : ' (waktu server; jam node belum sinkron)' ?>
  </p>
<?php endif; ?>
</section>

<section class="mt-5">
  <div class="flex flex-wrap items-center justify-between gap-2">
    <h2 class="text-lg font-bold">Grafik</h2>
    <div class="inline-flex overflow-hidden rounded-md border border-stone-300 bg-white text-sm" role="group" aria-label="Rentang waktu">
<?php foreach (Deret::RENTANG as $kode => $label) : ?>
      <a href="?rentang=<?= $kode ?>"
         class="px-3 py-1.5 <?= $kode === $rentang ? 'bg-stone-800 font-semibold text-white' : 'text-stone-700 hover:bg-stone-100' ?>"
         <?= $kode === $rentang ? 'aria-current="true"' : '' ?>><?= $label ?></a>
<?php endforeach; ?>
    </div>
  </div>

  <div id="grafik" data-url="/api/drum/<?= F::e($id) ?>/deret?rentang=<?= F::e($rentang) ?>" class="mt-3 space-y-3">
    <p id="grafik-pesan" class="rounded-lg border border-stone-300 bg-white p-4 text-sm text-stone-600">Memuat grafik…</p>
    <figure class="hidden rounded-lg border border-stone-300 bg-white p-3" data-grafik>
      <figcaption class="mb-1 text-sm font-semibold">Suhu (°C)</figcaption>
      <div class="h-56"><canvas id="grafik-suhu" role="img" aria-label="Grafik suhu lapis bawah, tengah, atas, dan ambien"></canvas></div>
    </figure>
    <figure class="hidden rounded-lg border border-stone-300 bg-white p-3" data-grafik>
      <figcaption class="mb-1 text-sm font-semibold">
        ΔT terhadap ambien (°C)
        <span class="font-normal text-stone-500">· ambang waspada 2 °C, bahaya 5 °C</span>
      </figcaption>
      <div class="h-56"><canvas id="grafik-dt" role="img" aria-label="Grafik selisih suhu dengan garis ambang 2 dan 5 derajat"></canvas></div>
    </figure>
    <figure class="hidden rounded-lg border border-stone-300 bg-white p-3" data-grafik>
      <figcaption class="mb-1 text-sm font-semibold">CO₂ (ppm) dan kelembapan headspace (%)</figcaption>
      <div class="h-56"><canvas id="grafik-gas" role="img" aria-label="Grafik CO2 dan kelembapan relatif headspace"></canvas></div>
    </figure>
    <p id="grafik-catatan" class="hidden text-xs text-stone-500"></p>
  </div>
</section>

<section class="mt-6">
  <h2 class="text-lg font-bold">Riwayat peringatan</h2>
<?php if ($peringatan === []) : ?>
  <p class="mt-2 rounded-lg border border-stone-300 bg-white p-4 text-sm text-stone-600">Belum ada peringatan untuk drum ini.</p>
<?php else : ?>
  <ul class="mt-2 divide-y divide-stone-200 rounded-lg border border-stone-300 bg-white text-sm">
<?php foreach ($peringatan as $p) : ?>
    <li class="flex flex-wrap items-center gap-x-3 gap-y-1 px-4 py-2.5">
      <span class="rounded-full px-2 py-0.5 text-xs font-bold <?= $p['level'] === 'BAHAYA' ? 'bg-red-600 text-white' : 'bg-amber-400 text-amber-950' ?>">
        <?= F::e($p['level']) ?>
      </span>
      <span class="font-medium"><?= F::e($p['reason'] ?? '') ?></span>
      <span class="text-stone-500">
        <?= F::e(F::wib($p['mulai'])) ?> – <?= $p['selesai'] ? F::e(F::wib($p['selesai'])) : 'masih berlangsung' ?>
      </span>
<?php if ($p['diakui_pada']) : ?>
      <span class="text-stone-500">· diakui <?= F::e($p['diakui_nama'] ?? '') ?></span>
<?php endif; ?>
    </li>
<?php endforeach; ?>
  </ul>
<?php endif; ?>
</section>
