<?php

use Silase\Pengguna\PenggunaRepo;
use Silase\Web\Format as F;

/** @var array $pemakai */
/** @var string $csrf */

$bidang = 'mt-1 w-full rounded border border-stone-300 bg-white px-3 py-2 text-sm';
?>
<h1 class="text-xl font-bold">Akun</h1>

<dl class="mt-3 grid max-w-md grid-cols-[7rem_1fr] gap-y-1 rounded-lg border border-stone-300 bg-white p-4 text-sm">
  <dt class="text-stone-500">Nama</dt><dd class="font-medium"><?= F::e($pemakai['nama']) ?></dd>
  <dt class="text-stone-500">Email</dt><dd><?= F::e($pemakai['email']) ?></dd>
  <dt class="text-stone-500">Peran</dt><dd><?= F::e(PenggunaRepo::PERAN[$pemakai['peran']] ?? $pemakai['peran']) ?></dd>
</dl>

<?php if (!empty($sso)) : ?>
<p class="mt-4 max-w-md rounded-lg border border-stone-300 bg-white p-4 text-sm text-stone-700">
  Anda masuk dengan akun Google, jadi tidak ada kata sandi yang diatur di sini.
  Keamanan akun (kata sandi, verifikasi dua langkah) diatur di akun Google Anda.
</p>
<?php else : ?>
<form method="post" action="/akun/sandi" class="mt-4 max-w-md rounded-lg border border-stone-300 bg-white p-4">
  <input type="hidden" name="csrf" value="<?= F::e($csrf) ?>">
  <h2 class="text-lg font-bold">Ganti kata sandi</h2>
  <label class="mt-2 block text-sm">Kata sandi lama
    <input type="password" name="sandi_lama" required autocomplete="current-password" class="<?= $bidang ?>">
  </label>
  <label class="mt-3 block text-sm">Kata sandi baru (paling sedikit <?= PenggunaRepo::SANDI_MIN ?> karakter)
    <input type="password" name="sandi_baru" required minlength="<?= PenggunaRepo::SANDI_MIN ?>" autocomplete="new-password" class="<?= $bidang ?>">
  </label>
  <label class="mt-3 block text-sm">Ulangi kata sandi baru
    <input type="password" name="sandi_ulang" required minlength="<?= PenggunaRepo::SANDI_MIN ?>" autocomplete="new-password" class="<?= $bidang ?>">
  </label>
  <button class="mt-4 rounded bg-stone-800 px-4 py-2 text-sm font-semibold text-white hover:bg-stone-700">Ganti kata sandi</button>
</form>
<?php endif; ?>
