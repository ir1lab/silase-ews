<?php

use Silase\Web\Format as F;

/** @var string $csrf */
/** @var bool $belumAdaPengguna */

$bidang = 'mt-1 w-full rounded border border-stone-300 bg-white px-3 py-2.5 text-base';
?>
<div class="mx-auto max-w-sm">
  <h1 class="text-xl font-bold">Masuk</h1>
  <p class="text-sm text-stone-600">Dasbor pemantauan fermentasi silase.</p>

<?php if ($belumAdaPengguna) : ?>
  <p class="mt-4 rounded-lg border border-amber-300 bg-amber-50 p-3 text-sm text-amber-900">
    Belum ada pengguna. Buat admin pertama dari server dengan
    <code class="rounded bg-white px-1">php bin/pengguna.php admin-awal<?= !empty($sso) ? ' alamat@gmail.com' : '' ?></code> (lihat README).
  </p>
<?php endif; ?>

<?php if (!empty($sso)) : ?>
  <div class="mt-4 rounded-lg border border-stone-300 bg-white p-4">
    <a href="/masuk/google" class="block w-full rounded bg-stone-800 px-4 py-3 text-center text-sm font-semibold text-white hover:bg-stone-700">
      Masuk dengan Google
    </a>
    <p class="mt-3 text-xs text-stone-500">
      Hanya akun Google yang alamat emailnya sudah didaftarkan admin yang bisa masuk.
    </p>
  </div>
<?php else : ?>
  <form method="post" action="/masuk" class="mt-4 rounded-lg border border-stone-300 bg-white p-4">
    <input type="hidden" name="csrf" value="<?= F::e($csrf) ?>">
    <label class="block text-sm font-medium">Email
      <input type="email" name="email" required autofocus autocomplete="username" class="<?= $bidang ?>">
    </label>
    <label class="mt-3 block text-sm font-medium">Kata sandi
      <input type="password" name="sandi" required autocomplete="current-password" class="<?= $bidang ?>">
    </label>
    <button class="mt-4 w-full rounded bg-stone-800 px-4 py-2.5 text-sm font-semibold text-white hover:bg-stone-700">Masuk</button>
  </form>
  <p class="mt-3 text-xs text-stone-500">Lupa kata sandi? Minta admin menggantinya.</p>
<?php endif; ?>
</div>
