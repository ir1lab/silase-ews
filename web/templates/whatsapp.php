<?php

use Silase\Notifikasi\PenerimaRepo;
use Silase\Web\Format as F;

/** @var ?string $provider */
/** @var ?array{ok: bool, status: string} $status */
/** @var bool $adaQr */
/** @var list<array> $drum */
/** @var list<array> $penerima */
/** @var list<array> $antrean */
/** @var string $csrf */

$artiStatus = [
    'WORKING' => 'Tersambung',
    'CLOUD_API' => 'Cloud API resmi (tanpa sesi)',
    'SCAN_QR_CODE' => 'Menunggu pemindaian kode QR',
    'STARTING' => 'Sedang memulai sesi',
    'STOPPED' => 'Sesi berhenti',
    'FAILED' => 'Sesi gagal',
    'BELUM_DIBUAT' => 'Sesi belum dibuat',
    'TIDAK_TERJANGKAU' => 'Gateway tidak terjangkau (cek kontainer silase-waha)',
    'KUNCI_DITOLAK' => 'Kunci API ditolak (cek WA_GATEWAY_KEY)',
];
$gayaStatus = [
    'antre' => 'bg-stone-200 text-stone-800',
    'terkirim' => 'bg-emerald-100 text-emerald-900',
    'gagal' => 'bg-red-100 text-red-900',
    'kedaluwarsa' => 'bg-amber-100 text-amber-900',
];
$perDrum = [];
foreach ($penerima as $p) {
    $perDrum[$p['device_id']][] = $p;
}
$bidang = 'w-full rounded border border-stone-300 bg-white px-3 py-2 text-sm';
?>
<h1 class="text-xl font-bold">WhatsApp</h1>
<p class="text-sm text-stone-600">Peringatan WASPADA dan BAHAYA dikirim ke penerima drum yang bersangkutan, begitu juga kabar saat drum pulih.</p>

<section class="mt-4 rounded-lg border border-stone-300 bg-white p-4">
  <h2 class="text-lg font-bold">Status sambungan</h2>
<?php if ($status === null) : ?>
  <p class="mt-2 text-sm text-stone-700">
    WhatsApp belum dikonfigurasi. Peringatan tetap tercatat di dasbor, tetapi belum ada pesan yang dikirim.
    Isi <code class="rounded bg-stone-100 px-1">WA_GATEWAY_URL</code> dan <code class="rounded bg-stone-100 px-1">WA_GATEWAY_KEY</code> di berkas <code class="rounded bg-stone-100 px-1">.env</code>, lalu nyalakan layanan WhatsApp (lihat README).
  </p>
<?php else : ?>
  <p class="mt-2 flex flex-wrap items-center gap-2 text-sm">
    <span class="rounded-full px-2.5 py-1 text-xs font-bold uppercase <?= $status['ok'] ? 'bg-emerald-600 text-white' : 'bg-red-600 text-white' ?>">
      <?= $status['ok'] ? 'Tersambung' : 'Terputus' ?>
    </span>
    <span><?= F::e($artiStatus[$status['status']] ?? $status['status']) ?></span>
    <span class="text-stone-500">· provider <?= F::e($provider) ?></span>
  </p>
<?php if ($adaQr) : ?>
  <div class="mt-4 flex flex-wrap items-start gap-4">
    <img src="/whatsapp/qr.png?t=<?= time() ?>" alt="Kode QR untuk menautkan WhatsApp" width="264" height="264"
         class="rounded border border-stone-300 bg-white">
    <ol class="max-w-sm list-decimal space-y-1 pl-5 text-sm text-stone-700">
      <li>Buka WhatsApp di ponsel bernomor khusus SILASE.</li>
      <li>Pilih <strong>Perangkat tertaut</strong>, lalu <strong>Tautkan perangkat</strong>.</li>
      <li>Pindai kode QR di samping.</li>
      <li>Halaman ini memuat ulang sendiri tiap 30 detik; kode QR ikut diperbarui.</li>
    </ol>
  </div>
  <p class="mt-2 text-xs text-stone-500">Bila gambar kode QR tidak muncul, tunggu beberapa detik sampai sesi siap, lalu muat ulang halaman.</p>
<?php endif; ?>
<?php endif; ?>
</section>

<section class="mt-5">
  <h2 class="text-lg font-bold">Penerima per drum</h2>
<?php if ($drum === []) : ?>
  <p class="mt-2 rounded-lg border border-stone-300 bg-white p-4 text-sm text-stone-600">Belum ada drum. Penerima bisa ditambahkan setelah node pertama mengirim data.</p>
<?php else : ?>
  <div class="mt-2 space-y-3">
<?php foreach ($drum as $d) :
    $daftar = $perDrum[$d['device_id']] ?? [];
    ?>
    <div class="rounded-lg border border-stone-300 bg-white">
      <h3 class="border-b border-stone-200 px-4 py-2 text-sm font-semibold">
        <?= F::e($d['nama']) ?> <span class="font-normal text-stone-500">· <?= F::e($d['lokasi'] ?: $d['device_id']) ?></span>
      </h3>
<?php if ($daftar === []) : ?>
      <p class="px-4 py-3 text-sm text-stone-600">Belum ada penerima. Peringatan drum ini tidak dikirim ke siapa pun.</p>
<?php endif; ?>
      <ul class="divide-y divide-stone-200">
<?php foreach ($daftar as $p) : ?>
        <li class="flex flex-wrap items-center justify-between gap-2 px-4 py-2 text-sm">
          <span class="<?= (int) $p['aktif'] ? '' : 'text-stone-400 line-through' ?>">
            <span class="font-medium"><?= F::e($p['nama']) ?></span>
            <span class="tabular-nums text-stone-600"><?= F::e($p['nomor']) ?></span>
          </span>
          <span class="flex items-center gap-2">
<?php if (!(int) $p['aktif']) : ?>
            <span class="rounded bg-stone-200 px-2 py-0.5 text-xs text-stone-700">nonaktif</span>
<?php endif; ?>
            <form method="post" action="/whatsapp/penerima/<?= (int) $p['id'] ?>/aktif">
              <input type="hidden" name="csrf" value="<?= F::e($csrf) ?>">
              <button class="rounded border border-stone-300 px-2.5 py-1 text-xs font-medium hover:bg-stone-100"><?= (int) $p['aktif'] ? 'Nonaktifkan' : 'Aktifkan' ?></button>
            </form>
            <form method="post" action="/whatsapp/penerima/<?= (int) $p['id'] ?>/hapus">
              <input type="hidden" name="csrf" value="<?= F::e($csrf) ?>">
              <button class="rounded border border-red-300 px-2.5 py-1 text-xs font-medium text-red-700 hover:bg-red-50">Hapus</button>
            </form>
          </span>
        </li>
<?php endforeach; ?>
      </ul>
    </div>
<?php endforeach; ?>
  </div>

  <form method="post" action="/whatsapp/penerima" class="mt-3 rounded-lg border border-stone-300 bg-white p-4">
    <input type="hidden" name="csrf" value="<?= F::e($csrf) ?>">
    <h3 class="text-sm font-semibold">Tambah penerima</h3>
    <div class="mt-2 grid gap-3 sm:grid-cols-3">
      <label class="text-sm">Drum
        <select name="device_id" required class="<?= $bidang ?> mt-1">
<?php foreach ($drum as $d) : ?>
          <option value="<?= F::e($d['device_id']) ?>"><?= F::e($d['nama']) ?></option>
<?php endforeach; ?>
        </select>
      </label>
      <label class="text-sm">Nama
        <input name="nama" required maxlength="100" autocomplete="off" class="<?= $bidang ?> mt-1">
      </label>
      <label class="text-sm">Nomor WhatsApp
        <input name="nomor" required inputmode="tel" placeholder="081234567890" autocomplete="off" class="<?= $bidang ?> mt-1">
      </label>
    </div>
    <button class="mt-3 rounded bg-stone-800 px-4 py-2 text-sm font-semibold text-white hover:bg-stone-700">Tambah penerima</button>
  </form>
<?php endif; ?>
</section>

<?php if ($penerima !== []) : ?>
<section class="mt-5 rounded-lg border border-stone-300 bg-white p-4">
  <h2 class="text-lg font-bold">Kirim pesan uji</h2>
  <p class="text-sm text-stone-600">Pesan uji hanya bisa dikirim ke nomor yang terdaftar sebagai penerima.</p>
  <form method="post" action="/whatsapp/uji" class="mt-2 flex flex-wrap items-end gap-3">
    <input type="hidden" name="csrf" value="<?= F::e($csrf) ?>">
    <label class="text-sm">Penerima
      <select name="penerima_id" required class="<?= $bidang ?> mt-1">
<?php foreach ($penerima as $p) : ?>
        <option value="<?= (int) $p['id'] ?>"><?= F::e($p['nama']) ?> · <?= F::e($p['nomor']) ?> (<?= F::e($p['nama_drum']) ?>)</option>
<?php endforeach; ?>
      </select>
    </label>
    <button class="rounded bg-stone-800 px-4 py-2 text-sm font-semibold text-white hover:bg-stone-700">Kirim pesan uji</button>
  </form>
</section>
<?php endif; ?>

<section class="mt-5">
  <h2 class="text-lg font-bold">Antrean pesan terakhir</h2>
<?php if ($antrean === []) : ?>
  <p class="mt-2 rounded-lg border border-stone-300 bg-white p-4 text-sm text-stone-600">Belum ada pesan.</p>
<?php else : ?>
  <div class="mt-2 overflow-x-auto rounded-lg border border-stone-300 bg-white">
    <table class="w-full min-w-[38rem] text-left text-sm">
      <thead class="border-b border-stone-200 bg-stone-50 text-xs uppercase tracking-wide text-stone-500">
        <tr><th class="px-3 py-2">Dibuat</th><th class="px-3 py-2">Jenis</th><th class="px-3 py-2">Drum</th><th class="px-3 py-2">Nomor</th><th class="px-3 py-2">Status</th><th class="px-3 py-2">Keterangan</th></tr>
      </thead>
      <tbody class="divide-y divide-stone-200">
<?php foreach ($antrean as $n) : ?>
        <tr>
          <td class="whitespace-nowrap px-3 py-2 text-stone-600"><?= F::e(F::wib($n['dibuat_pada'])) ?></td>
          <td class="px-3 py-2"><?= F::e($n['jenis']) ?></td>
          <td class="px-3 py-2"><?= F::e($n['nama_drum'] ?? '–') ?></td>
          <td class="whitespace-nowrap px-3 py-2 tabular-nums text-stone-600"><?= F::e(PenerimaRepo::samarkan($n['nomor'])) ?></td>
          <td class="px-3 py-2"><span class="rounded px-2 py-0.5 text-xs font-semibold <?= $gayaStatus[$n['status']] ?? '' ?>"><?= F::e($n['status']) ?></span></td>
          <td class="px-3 py-2 text-xs text-stone-600">
            <?= $n['status'] === 'terkirim' ? F::e(F::wib($n['dikirim_pada'])) : F::e($n['galat_terakhir'] ?? '') ?>
          </td>
        </tr>
<?php endforeach; ?>
      </tbody>
    </table>
  </div>
<?php endif; ?>
</section>
