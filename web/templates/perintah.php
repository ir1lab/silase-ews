<?php

use Silase\Perintah\PengirimPerintah;
use Silase\Web\Format as F;

/** @var list<array> $drum */
/** @var list<array> $riwayat */
/** @var bool $siap */
/** @var string $csrf */

$bidang = 'mt-1 w-full rounded border border-stone-300 bg-white px-3 py-2 text-sm';
$uraian = [
    'sample_now' => 'Node langsung mengukur dan mengirim data, tanpa menunggu jadwal 5 menit.',
    'mute' => 'Buzzer pada node diam sampai status berubah. LED tetap menyala sesuai status.',
];
?>
<h1 class="text-xl font-bold">Perintah ke node</h1>
<p class="text-sm text-stone-600">
  Perintah hanya dijalankan bila node sedang tersambung. Node tidak mengirim balasan, jadi "terkirim"
  berarti perintah sampai di broker.
</p>

<?php if (!$siap) : ?>
<p class="mt-4 rounded-lg border border-amber-300 bg-amber-50 p-4 text-sm text-amber-900">Broker MQTT belum dikonfigurasi, jadi perintah belum bisa dikirim.</p>
<?php elseif ($drum === []) : ?>
<p class="mt-4 rounded-lg border border-stone-300 bg-white p-4 text-sm text-stone-600">Belum ada drum.</p>
<?php else : ?>
<div class="mt-4 grid gap-3 sm:grid-cols-2">
<?php foreach ($uraian as $kode => $ket) : ?>
  <form method="post" action="/perintah" class="rounded-lg border border-stone-300 bg-white p-4">
    <input type="hidden" name="csrf" value="<?= F::e($csrf) ?>">
    <input type="hidden" name="perintah" value="<?= $kode ?>">
    <h2 class="text-base font-bold"><?= F::e(PengirimPerintah::PERINTAH[$kode]) ?></h2>
    <p class="text-sm text-stone-600"><?= F::e($ket) ?></p>
    <label class="mt-3 block text-sm">Drum
      <select name="device_id" required class="<?= $bidang ?>">
<?php foreach ($drum as $d) : ?>
        <option value="<?= F::e($d['device_id']) ?>"><?= F::e($d['nama']) ?></option>
<?php endforeach; ?>
      </select>
    </label>
    <label class="mt-3 flex items-start gap-2 text-sm">
      <input type="checkbox" name="yakin" value="1" required class="mt-0.5 h-4 w-4">
      <span>Ya, kirim perintah ini.</span>
    </label>
    <button class="mt-3 rounded bg-stone-800 px-4 py-2 text-sm font-semibold text-white hover:bg-stone-700">Kirim</button>
  </form>
<?php endforeach; ?>
</div>
<p class="mt-2 text-xs text-stone-500">Memulai siklus baru dilakukan dari halaman <a href="/siklus" class="underline">Siklus</a>.</p>
<?php endif; ?>

<section class="mt-6">
  <h2 class="text-lg font-bold">Catatan perintah</h2>
<?php if ($riwayat === []) : ?>
  <p class="mt-2 rounded-lg border border-stone-300 bg-white p-4 text-sm text-stone-600">Belum ada perintah yang dikirim.</p>
<?php else : ?>
  <div class="mt-2 overflow-x-auto rounded-lg border border-stone-300 bg-white">
    <table class="w-full min-w-[32rem] text-left text-sm">
      <thead class="border-b border-stone-200 bg-stone-50 text-xs uppercase tracking-wide text-stone-500">
        <tr><th class="px-3 py-2">Waktu</th><th class="px-3 py-2">Pengirim</th><th class="px-3 py-2">Drum</th><th class="px-3 py-2">Perintah</th><th class="px-3 py-2">Status</th></tr>
      </thead>
      <tbody class="divide-y divide-stone-200">
<?php foreach ($riwayat as $r) : ?>
        <tr>
          <td class="whitespace-nowrap px-3 py-2 text-stone-600"><?= F::e(F::wib($r['dikirim_pada'])) ?></td>
          <td class="px-3 py-2"><?= F::e($r['pengirim']) ?></td>
          <td class="px-3 py-2"><?= F::e($r['nama_drum']) ?></td>
          <td class="px-3 py-2"><?= F::e(PengirimPerintah::PERINTAH[$r['perintah']] ?? $r['perintah']) ?></td>
          <td class="px-3 py-2">
            <span class="rounded px-2 py-0.5 text-xs font-semibold <?= (int) $r['terkirim'] ? 'bg-emerald-100 text-emerald-900' : 'bg-red-100 text-red-900' ?>"><?= (int) $r['terkirim'] ? 'terkirim' : 'gagal' ?></span>
          </td>
        </tr>
<?php endforeach; ?>
      </tbody>
    </table>
  </div>
<?php endif; ?>
</section>
