<?php

use Silase\Web\Format as F;

/** @var list<array> $aktif */
/** @var list<array> $riwayat */
/** @var string $csrf */

$lencana = fn (string $level): string => $level === 'BAHAYA' ? 'bg-red-600 text-white' : 'bg-amber-400 text-amber-950';
$tepi = fn (string $level): string => $level === 'BAHAYA' ? 'border-l-red-600' : 'border-l-amber-500';
$uraian = function (array $a): string {
    $bagian = [$a['reason'] ?? ''];
    if ($a['dt'] !== null) {
        $bagian[] = 'ΔT ' . F::angka($a['dt']) . ' °C' . ($a['hotspot'] ? ' di lapis ' . $a['hotspot'] : '');
    }
    return implode(' · ', array_filter($bagian));
};
?>
<h1 class="text-xl font-bold">Peringatan</h1>
<p class="text-sm text-stone-600">
<?php if ($aktif === []) : ?>
  Tidak ada peringatan yang sedang berlangsung.
<?php else : ?>
  <?= count($aktif) ?> peringatan sedang berlangsung.
<?php endif; ?>
</p>

<section class="mt-4">
  <h2 class="text-lg font-bold">Sedang berlangsung</h2>
<?php if ($aktif === []) : ?>
  <p class="mt-2 rounded-lg border border-stone-300 bg-white p-4 text-sm text-stone-600">
    Semua drum dalam kondisi normal menurut pembacaan terakhirnya.
  </p>
<?php endif; ?>
  <ul class="mt-2 space-y-3">
<?php foreach ($aktif as $a) : ?>
    <li class="rounded-lg border border-stone-300 border-l-8 <?= $tepi($a['level']) ?> bg-white p-4 shadow-sm">
      <div class="flex flex-wrap items-start justify-between gap-3">
        <div class="min-w-0">
          <p class="flex flex-wrap items-center gap-2">
            <span class="rounded-full px-2.5 py-0.5 text-xs font-bold <?= $lencana($a['level']) ?>"><?= F::e($a['level']) ?></span>
            <a href="/drum/<?= F::e(rawurlencode($a['device_id'])) ?>" class="text-base font-semibold hover:underline"><?= F::e($a['nama']) ?></a>
<?php if ($a['lokasi']) : ?>
            <span class="text-sm text-stone-500"><?= F::e($a['lokasi']) ?></span>
<?php endif; ?>
          </p>
          <p class="mt-1 text-sm text-stone-800"><?= F::e($uraian($a)) ?></p>
          <p class="mt-1 text-xs text-stone-500">Sejak <?= F::e(F::wib($a['mulai'])) ?></p>
        </div>
<?php if ($a['diakui_pada'] === null) : ?>
        <form method="post" action="/peringatan/<?= (int) $a['id'] ?>/akui" class="shrink-0">
          <input type="hidden" name="csrf" value="<?= F::e($csrf) ?>">
          <button class="rounded bg-stone-800 px-4 py-2 text-sm font-semibold text-white hover:bg-stone-700">Akui</button>
        </form>
<?php else : ?>
        <p class="shrink-0 rounded bg-stone-100 px-3 py-2 text-xs text-stone-600">
          Diakui <?= F::e(F::wib($a['diakui_pada'])) ?><?= $a['diakui_nama'] ? ' oleh ' . F::e($a['diakui_nama']) : '' ?>
        </p>
<?php endif; ?>
      </div>
<?php if ($a['diakui_pada'] === null && $a['level'] === 'BAHAYA') : ?>
      <p class="mt-2 text-xs text-stone-500">
        Selama belum diakui, pengingat WhatsApp dikirim tiap 6 jam, paling banyak 3 kali
        (sudah <?= (int) $a['pengingat_terkirim'] ?>).
      </p>
<?php endif; ?>
    </li>
<?php endforeach; ?>
  </ul>
</section>

<section class="mt-6">
  <h2 class="text-lg font-bold">Riwayat</h2>
<?php if ($riwayat === []) : ?>
  <p class="mt-2 rounded-lg border border-stone-300 bg-white p-4 text-sm text-stone-600">Belum ada peringatan yang selesai.</p>
<?php else : ?>
  <div class="mt-2 overflow-x-auto rounded-lg border border-stone-300 bg-white">
    <table class="w-full min-w-[36rem] text-left text-sm">
      <thead class="border-b border-stone-200 bg-stone-50 text-xs uppercase tracking-wide text-stone-500">
        <tr><th class="px-3 py-2">Level</th><th class="px-3 py-2">Drum</th><th class="px-3 py-2">Keterangan</th><th class="px-3 py-2">Mulai</th><th class="px-3 py-2">Selesai</th><th class="px-3 py-2">Diakui</th></tr>
      </thead>
      <tbody class="divide-y divide-stone-200">
<?php foreach ($riwayat as $a) : ?>
        <tr>
          <td class="px-3 py-2"><span class="rounded-full px-2 py-0.5 text-xs font-bold <?= $lencana($a['level']) ?>"><?= F::e($a['level']) ?></span></td>
          <td class="px-3 py-2"><a href="/drum/<?= F::e(rawurlencode($a['device_id'])) ?>" class="font-medium hover:underline"><?= F::e($a['nama']) ?></a></td>
          <td class="px-3 py-2 text-stone-700"><?= F::e($uraian($a)) ?></td>
          <td class="whitespace-nowrap px-3 py-2 text-stone-600"><?= F::e(F::wib($a['mulai'])) ?></td>
          <td class="whitespace-nowrap px-3 py-2 text-stone-600"><?= F::e(F::wib($a['selesai'])) ?></td>
          <td class="whitespace-nowrap px-3 py-2 text-stone-600"><?= $a['diakui_pada'] ? F::e(F::wib($a['diakui_pada'])) : 'tidak' ?></td>
        </tr>
<?php endforeach; ?>
      </tbody>
    </table>
  </div>
<?php endif; ?>
</section>
