<?php

use Silase\Web\Format as F;

/** @var list<array{device: array, terakhir: ?array, kondisi: Silase\Drum\Kondisi}> $kartu */

$perhatian = count(array_filter($kartu, fn ($k) => $k['kondisi']->urutan < 4));
?>
<div class="mb-4 flex items-end justify-between gap-3">
  <div>
    <h1 class="text-xl font-bold">Ringkasan drum</h1>
    <p class="text-sm text-stone-600">
<?php if ($kartu === []) : ?>
      Belum ada drum yang mengirim data.
<?php elseif ($perhatian === 0) : ?>
      Semua <?= count($kartu) ?> drum normal.
<?php else : ?>
      <?= $perhatian ?> dari <?= count($kartu) ?> drum perlu diperiksa.
<?php endif; ?>
    </p>
  </div>
  <p class="shrink-0 text-xs text-stone-500">Diperbarui tiap menit</p>
</div>

<?php if ($kartu === []) : ?>
<div class="rounded-lg border border-dashed border-stone-400 bg-white p-6 text-sm text-stone-600">
  Drum muncul di sini secara otomatis setelah node pertama kali mengirim data ke broker.
  Pastikan worker ingest sedang berjalan.
</div>
<?php endif; ?>

<ul class="grid gap-3 sm:grid-cols-2 lg:grid-cols-3">
<?php foreach ($kartu as $k) :
    $d = $k['device'];
    $t = $k['terakhir'];
    $kon = $k['kondisi'];
    $gagal = $t ? Silase\Drum\Kondisi::sensorGagal((int) $t['sensor_flags']) : [];
    ?>
  <li>
    <a href="/drum/<?= F::e(rawurlencode($d['device_id'])) ?>"
       class="block rounded-lg border border-stone-300 border-l-8 <?= $kon->kelasTepi ?> bg-white p-4 shadow-sm hover:shadow">
      <div class="flex items-start justify-between gap-2">
        <div class="min-w-0">
          <h2 class="truncate text-base font-semibold"><?= F::e($d['nama']) ?></h2>
          <p class="truncate text-xs text-stone-500"><?= F::e($d['lokasi'] ?: $d['device_id']) ?></p>
        </div>
        <span class="shrink-0 rounded-full px-2.5 py-1 text-xs font-bold uppercase tracking-wide <?= $kon->kelasLencana ?>">
          <?= F::e($kon->label) ?>
        </span>
      </div>

<?php if ($t === null) : ?>
      <p class="mt-4 text-sm text-stone-500">Belum ada pembacaan.</p>
<?php else : ?>
      <div class="mt-3 flex items-baseline gap-1 <?= $kon->segar() ? '' : 'opacity-50' ?>">
        <span class="text-3xl font-bold tabular-nums <?= $kon->kelasTeks ?>"><?= F::angka($t['dt']) ?></span>
        <span class="text-sm text-stone-600">°C di atas ambien</span>
      </div>
      <dl class="mt-3 grid grid-cols-2 gap-x-3 gap-y-1 text-sm <?= $kon->segar() ? '' : 'opacity-50' ?>">
        <dt class="text-stone-500">CO₂</dt>
        <dd class="text-right tabular-nums"><?= F::angka($t['co2_ppm'], 0) ?> ppm</dd>
        <dt class="text-stone-500">Umur siklus</dt>
        <dd class="text-right"><?= F::e(F::umur($t['cycle_h'])) ?></dd>
      </dl>
<?php if ($gagal !== []) : ?>
      <p class="mt-2 text-xs font-medium text-amber-700">Sensor gagal: <?= F::e(implode(', ', $gagal)) ?></p>
<?php endif; ?>
      <p class="mt-3 border-t border-stone-200 pt-2 text-xs <?= $kon->segar() ? 'text-stone-500' : 'font-semibold text-stone-700' ?>">
        Data terakhir <?= F::e(F::lalu($kon->umurDataDetik ?? 0)) ?> · <?= F::e(F::wib($t['measured_at'])) ?>
      </p>
<?php endif; ?>
    </a>
  </li>
<?php endforeach; ?>
</ul>
