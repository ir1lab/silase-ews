<?php

use Silase\Web\Format as F;

/** @var list<array> $drum */
/** @var list<array> $siklus */
/** @var string $hariIni */
/** @var string $seminggu */

$bidang = 'mt-1 w-full rounded border border-stone-300 bg-white px-3 py-2 text-sm';
?>
<h1 class="text-xl font-bold">Ekspor data</h1>
<p class="text-sm text-stone-600">
  Berkas CSV berisi semua pembacaan apa adanya: pemisah koma, desimal titik, waktu dalam WIB dan UTC.
  Sel kosong berarti sensor tidak memberi nilai yang valid.
</p>

<?php if ($drum === []) : ?>
<p class="mt-4 rounded-lg border border-stone-300 bg-white p-4 text-sm text-stone-600">Belum ada drum.</p>
<?php else : ?>
<form method="get" action="/ekspor/unduh" class="mt-4 rounded-lg border border-stone-300 bg-white p-4">
  <h2 class="text-lg font-bold">Per rentang tanggal</h2>
  <div class="mt-2 grid gap-3 sm:grid-cols-3">
    <label class="text-sm">Drum
      <select name="device_id" required class="<?= $bidang ?>">
<?php foreach ($drum as $d) : ?>
        <option value="<?= F::e($d['device_id']) ?>"><?= F::e($d['nama']) ?></option>
<?php endforeach; ?>
      </select>
    </label>
    <label class="text-sm">Dari tanggal
      <input type="date" name="dari" required value="<?= F::e($seminggu) ?>" class="<?= $bidang ?>">
    </label>
    <label class="text-sm">Sampai tanggal
      <input type="date" name="sampai" required value="<?= F::e($hariIni) ?>" class="<?= $bidang ?>">
    </label>
  </div>
  <button class="mt-3 rounded bg-stone-800 px-4 py-2 text-sm font-semibold text-white hover:bg-stone-700">Unduh CSV</button>
</form>

<form method="get" action="/ekspor/unduh" class="mt-4 rounded-lg border border-stone-300 bg-white p-4">
  <h2 class="text-lg font-bold">Per siklus</h2>
<?php if ($siklus === []) : ?>
  <p class="mt-1 text-sm text-stone-600">Belum ada siklus yang tercatat. Siklus dibuat dari halaman Siklus.</p>
<?php else : ?>
  <label class="mt-2 block text-sm sm:max-w-md">Siklus
    <select name="siklus" required class="<?= $bidang ?>">
<?php foreach ($siklus as $s) : ?>
      <option value="<?= (int) $s['id'] ?>">
        <?= F::e($s['nama']) ?> · <?= F::e(F::wib($s['mulai'])) ?> – <?= $s['selesai'] ? F::e(F::wib($s['selesai'])) : 'berjalan' ?>
      </option>
<?php endforeach; ?>
    </select>
  </label>
  <button class="mt-3 rounded bg-stone-800 px-4 py-2 text-sm font-semibold text-white hover:bg-stone-700">Unduh CSV</button>
<?php endif; ?>
</form>
<?php endif; ?>
