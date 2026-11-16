<?php

use Silase\Pengguna\PenggunaRepo;
use Silase\Web\Format as F;

/** @var string $judul */
/** @var string $isi */
/** @var ?array $pemakai pengguna yang sedang masuk, null di halaman masuk */

$menu ??= '';
$peran = $pemakai['peran'] ?? null;
$tautan = array_filter([
    'ringkasan' => ['/', 'Ringkasan'],
    'peringatan' => ['/peringatan', 'Peringatan'],
    'siklus' => ['/siklus', 'Siklus'],
    'perintah' => in_array($peran, ['admin', 'operator'], true) ? ['/perintah', 'Perintah'] : null,
    'ekspor' => ['/ekspor', 'Ekspor'],
    'whatsapp' => $peran === 'admin' ? ['/whatsapp', 'WhatsApp'] : null,
    'pengguna' => $peran === 'admin' ? ['/pengguna', 'Pengguna'] : null,
]);
?>
<!doctype html>
<html lang="id">
<head>
  <meta charset="utf-8">
  <meta name="viewport" content="width=device-width, initial-scale=1">
<?php if (!empty($segarkan)) : ?>
  <meta http-equiv="refresh" content="<?= (int) $segarkan ?>">
<?php endif; ?>
  <title><?= F::e($judul) ?> · SILASE-EWS</title>
  <link rel="stylesheet" href="/assets/app.css">
</head>
<body class="min-h-screen bg-stone-100 text-stone-900 antialiased">
  <header class="border-b border-stone-300 bg-white">
    <div class="mx-auto flex max-w-5xl flex-wrap items-center justify-between gap-x-4 gap-y-2 px-4 py-3">
      <a href="/" class="flex items-baseline gap-2">
        <span class="text-lg font-bold tracking-tight">SILASE-EWS</span>
        <span class="hidden text-sm text-stone-500 lg:inline">Pemantauan fermentasi silase</span>
      </a>
<?php if ($pemakai !== null) : ?>
      <div class="flex items-center gap-2 text-sm">
        <a href="/akun" class="rounded px-2 py-1 <?= $menu === 'akun' ? 'bg-stone-800 text-white' : 'text-stone-700 hover:bg-stone-100' ?>">
          <span class="font-medium"><?= F::e($pemakai['nama']) ?></span>
          <span class="<?= $menu === 'akun' ? 'text-stone-300' : 'text-stone-500' ?>">· <?= F::e(PenggunaRepo::PERAN[$pemakai['peran']] ?? $pemakai['peran']) ?></span>
        </a>
        <form method="post" action="/keluar">
          <input type="hidden" name="csrf" value="<?= F::e($csrf ?? '') ?>">
          <button class="rounded border border-stone-300 px-2.5 py-1 font-medium text-stone-700 hover:bg-stone-100">Keluar</button>
        </form>
      </div>
      <nav class="flex w-full flex-wrap gap-1 text-sm font-medium" aria-label="Menu utama">
<?php foreach ($tautan as $kode => [$url, $label]) : ?>
        <a href="<?= $url ?>" <?= $kode === $menu ? 'aria-current="page"' : '' ?>
           class="rounded px-2.5 py-1.5 <?= $kode === $menu ? 'bg-stone-800 text-white' : 'text-stone-700 hover:bg-stone-100' ?>">
          <?= $label ?>
<?php if ($kode === 'peringatan' && !empty($jumlahAktif)) : ?>
          <span class="ml-1 rounded-full bg-red-600 px-1.5 py-0.5 text-xs font-bold text-white"><?= (int) $jumlahAktif ?></span>
<?php endif; ?>
        </a>
<?php endforeach; ?>
      </nav>
<?php endif; ?>
    </div>
  </header>

<?php if (!empty($waTerputus)) : ?>
  <div class="bg-red-600 text-white" role="alert">
    <div class="mx-auto flex max-w-5xl flex-wrap items-center justify-between gap-2 px-4 py-2 text-sm font-semibold">
      <span>WhatsApp terputus, pindai ulang QR. Peringatan tetap tercatat di sini, tetapi pesan belum terkirim.</span>
<?php if ($menu !== 'whatsapp' && $peran === 'admin') : ?>
      <a href="/whatsapp" class="rounded bg-white px-2.5 py-1 text-red-700">Buka pengaturan WhatsApp</a>
<?php endif; ?>
    </div>
  </div>
<?php endif; ?>

  <main class="mx-auto max-w-5xl px-4 py-5">
<?php if (!empty($kilat)) : ?>
    <p class="mb-4 rounded-lg border px-4 py-2.5 text-sm font-medium <?= $kilat['galat'] ? 'border-red-300 bg-red-50 text-red-900' : 'border-emerald-300 bg-emerald-50 text-emerald-900' ?>" role="status">
      <?= F::e($kilat['pesan']) ?>
    </p>
<?php endif; ?>
<?= $isi ?>
  </main>

<?php foreach ($skrip ?? [] as $s) : ?>
  <script src="<?= F::e($s) ?>" defer></script>
<?php endforeach; ?>
</body>
</html>
