<?php

use Silase\Siklus\SiklusRepo;
use Silase\Web\Format as F;

/** @var list<array> $drum */
/** @var list<array> $daftar */
/** @var bool $bolehKelola */
/** @var string $csrf */

$bidang = 'mt-1 w-full rounded border border-stone-300 bg-white px-3 py-2 text-sm';
$berjalan = array_values(array_filter($daftar, fn ($s) => $s['selesai'] === null));
$selesai = array_values(array_filter($daftar, fn ($s) => $s['selesai'] !== null));
$sibuk = array_column($berjalan, 'device_id');
$bebas = array_values(array_filter($drum, fn ($d) => !in_array($d['device_id'], $sibuk, true)));
?>
<h1 class="text-xl font-bold">Siklus fermentasi</h1>
<p class="text-sm text-stone-600">Satu siklus dimulai saat drum diisi dan ditutup, lalu diakhiri dengan mencatat hasil akhirnya.</p>

<section class="mt-4">
  <h2 class="text-lg font-bold">Sedang berjalan</h2>
<?php if ($berjalan === []) : ?>
  <p class="mt-2 rounded-lg border border-stone-300 bg-white p-4 text-sm text-stone-600">Belum ada siklus yang berjalan.</p>
<?php endif; ?>
  <ul class="mt-2 space-y-3">
<?php foreach ($berjalan as $s) : ?>
    <li class="rounded-lg border border-stone-300 bg-white p-4">
      <p class="flex flex-wrap items-baseline gap-x-2">
        <a href="/drum/<?= F::e(rawurlencode($s['device_id'])) ?>" class="font-semibold hover:underline"><?= F::e($s['nama']) ?></a>
        <span class="text-sm text-stone-600">sejak <?= F::e(F::wib($s['mulai'])) ?></span>
      </p>
      <p class="mt-1 text-sm text-stone-700">
        Bahan pakan: <?= F::e($s['bahan_pakan'] ?? 'belum diisi') ?><?= $s['catatan'] ? ' · ' . F::e($s['catatan']) : '' ?>
      </p>
<?php if ($bolehKelola) : ?>
      <form method="post" action="/siklus/<?= (int) $s['id'] ?>/tutup" class="mt-3 border-t border-stone-200 pt-3">
        <input type="hidden" name="csrf" value="<?= F::e($csrf) ?>">
        <h3 class="text-sm font-semibold">Tutup siklus dan catat hasil akhir</h3>
        <div class="mt-2 grid gap-3 sm:grid-cols-3">
          <label class="text-sm">Hasil
            <select name="hasil" required class="<?= $bidang ?>">
              <option value="">Pilih…</option>
<?php foreach (SiklusRepo::HASIL as $kode => $label) : ?>
              <option value="<?= $kode ?>"><?= $label ?></option>
<?php endforeach; ?>
            </select>
          </label>
          <label class="text-sm">pH akhir (boleh kosong)
            <input name="ph_akhir" inputmode="decimal" placeholder="4,2" autocomplete="off" class="<?= $bidang ?>">
          </label>
          <label class="text-sm sm:col-span-1">Catatan organoleptik
            <input name="catatan_organoleptik" maxlength="2000" placeholder="warna, aroma, tekstur" autocomplete="off" class="<?= $bidang ?>">
          </label>
        </div>
        <button class="mt-3 rounded bg-stone-800 px-4 py-2 text-sm font-semibold text-white hover:bg-stone-700">Tutup siklus</button>
      </form>
<?php endif; ?>
    </li>
<?php endforeach; ?>
  </ul>
</section>

<?php if ($bolehKelola) : ?>
<section class="mt-5 rounded-lg border border-stone-300 bg-white p-4">
  <h2 class="text-lg font-bold">Mulai siklus baru</h2>
<?php if ($bebas === []) : ?>
  <p class="mt-1 text-sm text-stone-600">
    <?= $drum === [] ? 'Belum ada drum.' : 'Semua drum masih punya siklus yang berjalan. Tutup dulu siklusnya.' ?>
  </p>
<?php else : ?>
  <p class="text-sm text-stone-600">
    Memulai siklus mengirim perintah ke node: umur siklus dan CO₂ maksimum di node kembali ke nol.
    Lakukan hanya saat drum baru diisi.
  </p>
  <form method="post" action="/siklus/mulai" class="mt-2">
    <input type="hidden" name="csrf" value="<?= F::e($csrf) ?>">
    <div class="grid gap-3 sm:grid-cols-3">
      <label class="text-sm">Drum
        <select name="device_id" required class="<?= $bidang ?>">
<?php foreach ($bebas as $d) : ?>
          <option value="<?= F::e($d['device_id']) ?>"><?= F::e($d['nama']) ?></option>
<?php endforeach; ?>
        </select>
      </label>
      <label class="text-sm">Bahan pakan
        <input name="bahan_pakan" maxlength="150" placeholder="rumput gajah + dedak" autocomplete="off" class="<?= $bidang ?>">
      </label>
      <label class="text-sm">Catatan
        <input name="catatan" maxlength="2000" autocomplete="off" class="<?= $bidang ?>">
      </label>
    </div>
    <label class="mt-3 flex items-start gap-2 text-sm">
      <input type="checkbox" name="yakin" value="1" required class="mt-0.5 h-4 w-4">
      <span>Drum ini baru diisi dan saya ingin memulai hitungan siklus dari nol.</span>
    </label>
    <button class="mt-3 rounded bg-stone-800 px-4 py-2 text-sm font-semibold text-white hover:bg-stone-700">Mulai siklus</button>
  </form>
<?php endif; ?>
</section>
<?php endif; ?>

<section class="mt-6">
  <h2 class="text-lg font-bold">Riwayat siklus</h2>
<?php if ($selesai === []) : ?>
  <p class="mt-2 rounded-lg border border-stone-300 bg-white p-4 text-sm text-stone-600">Belum ada siklus yang selesai.</p>
<?php else : ?>
  <div class="mt-2 overflow-x-auto rounded-lg border border-stone-300 bg-white">
    <table class="w-full min-w-[44rem] text-left text-sm">
      <thead class="border-b border-stone-200 bg-stone-50 text-xs uppercase tracking-wide text-stone-500">
        <tr><th class="px-3 py-2">Drum</th><th class="px-3 py-2">Mulai</th><th class="px-3 py-2">Selesai</th><th class="px-3 py-2">Bahan pakan</th><th class="px-3 py-2">Hasil</th><th class="px-3 py-2">pH</th><th class="px-3 py-2">Catatan</th><th class="px-3 py-2">Data</th></tr>
      </thead>
      <tbody class="divide-y divide-stone-200">
<?php foreach ($selesai as $s) : ?>
        <tr>
          <td class="px-3 py-2 font-medium"><?= F::e($s['nama']) ?></td>
          <td class="whitespace-nowrap px-3 py-2 text-stone-600"><?= F::e(F::wib($s['mulai'])) ?></td>
          <td class="whitespace-nowrap px-3 py-2 text-stone-600"><?= F::e(F::wib($s['selesai'])) ?></td>
          <td class="px-3 py-2"><?= F::e($s['bahan_pakan'] ?? '–') ?></td>
          <td class="px-3 py-2">
            <span class="rounded px-2 py-0.5 text-xs font-semibold <?= $s['hasil'] === 'rusak' ? 'bg-red-100 text-red-900' : 'bg-emerald-100 text-emerald-900' ?>"><?= F::e(SiklusRepo::HASIL[$s['hasil']] ?? '–') ?></span>
          </td>
          <td class="px-3 py-2 tabular-nums"><?= F::angka($s['ph_akhir'], 2) ?></td>
          <td class="px-3 py-2 text-stone-700"><?= F::e($s['catatan_organoleptik'] ?? '') ?></td>
          <td class="px-3 py-2"><a href="/ekspor/unduh?siklus=<?= (int) $s['id'] ?>" class="font-medium text-stone-800 underline">CSV</a></td>
        </tr>
<?php endforeach; ?>
      </tbody>
    </table>
  </div>
<?php endif; ?>
</section>
