<?php

use Silase\Pengguna\PenggunaRepo;
use Silase\Web\Format as F;

/** @var list<array> $daftar */
/** @var array $pemakai */
/** @var string $csrf */

$bidang = 'rounded border border-stone-300 bg-white px-2.5 py-1.5 text-sm';
?>
<h1 class="text-xl font-bold">Pengguna</h1>
<p class="text-sm text-stone-600">
  Admin mengatur semuanya. Operator bisa mengakui peringatan, mengelola siklus, dan mengirim perintah.
  Peneliti hanya melihat dan mengekspor data.
<?php if (!empty($sso)) : ?>
  Pengguna masuk dengan akun Google; daftarkan alamat email Google mereka di sini.
<?php endif; ?>
</p>

<ul class="mt-4 space-y-3">
<?php foreach ($daftar as $u) : ?>
  <li class="rounded-lg border border-stone-300 bg-white p-4 <?= (int) $u['aktif'] ? '' : 'opacity-70' ?>">
    <p class="flex flex-wrap items-baseline gap-x-2">
      <span class="font-semibold"><?= F::e($u['nama']) ?></span>
      <span class="text-sm text-stone-600"><?= F::e($u['email']) ?></span>
<?php if ((int) $u['id'] === (int) $pemakai['id']) : ?>
      <span class="rounded bg-stone-200 px-1.5 py-0.5 text-xs">Anda</span>
<?php endif; ?>
<?php if (!(int) $u['aktif']) : ?>
      <span class="rounded bg-red-100 px-1.5 py-0.5 text-xs text-red-900">nonaktif</span>
<?php endif; ?>
    </p>
    <div class="mt-3 flex flex-wrap items-end gap-x-6 gap-y-3">
      <form method="post" action="/pengguna/<?= (int) $u['id'] ?>/ubah" class="flex flex-wrap items-end gap-2">
        <input type="hidden" name="csrf" value="<?= F::e($csrf) ?>">
        <label class="text-xs text-stone-600">Peran
          <select name="peran" class="<?= $bidang ?> mt-1 block">
<?php foreach (PenggunaRepo::PERAN as $kode => $label) : ?>
            <option value="<?= $kode ?>" <?= $kode === $u['peran'] ? 'selected' : '' ?>><?= $label ?></option>
<?php endforeach; ?>
          </select>
        </label>
        <label class="text-xs text-stone-600">Status
          <select name="aktif" class="<?= $bidang ?> mt-1 block">
            <option value="1" <?= (int) $u['aktif'] ? 'selected' : '' ?>>Aktif</option>
            <option value="0" <?= (int) $u['aktif'] ? '' : 'selected' ?>>Nonaktif</option>
          </select>
        </label>
        <button class="rounded border border-stone-300 px-3 py-1.5 text-sm font-medium hover:bg-stone-100">Simpan</button>
      </form>
<?php if (empty($sso)) : ?>
      <form method="post" action="/pengguna/<?= (int) $u['id'] ?>/sandi" class="flex flex-wrap items-end gap-2">
        <input type="hidden" name="csrf" value="<?= F::e($csrf) ?>">
        <label class="text-xs text-stone-600">Kata sandi baru
          <input type="password" name="sandi" required minlength="<?= PenggunaRepo::SANDI_MIN ?>" autocomplete="new-password" class="<?= $bidang ?> mt-1 block">
        </label>
        <button class="rounded border border-stone-300 px-3 py-1.5 text-sm font-medium hover:bg-stone-100">Ganti kata sandi</button>
      </form>
<?php endif; ?>
    </div>
  </li>
<?php endforeach; ?>
</ul>

<form method="post" action="/pengguna" class="mt-5 rounded-lg border border-stone-300 bg-white p-4">
  <input type="hidden" name="csrf" value="<?= F::e($csrf) ?>">
  <h2 class="text-lg font-bold">Tambah pengguna</h2>
  <div class="mt-2 grid gap-3 sm:grid-cols-2">
    <label class="text-sm">Nama
      <input name="nama" required maxlength="100" autocomplete="off" class="<?= $bidang ?> mt-1 w-full">
    </label>
    <label class="text-sm"><?= !empty($sso) ? 'Email akun Google' : 'Email' ?>
      <input type="email" name="email" required maxlength="190" autocomplete="off" class="<?= $bidang ?> mt-1 w-full">
    </label>
    <label class="text-sm">Peran
      <select name="peran" class="<?= $bidang ?> mt-1 w-full">
<?php foreach (PenggunaRepo::PERAN as $kode => $label) : ?>
        <option value="<?= $kode ?>" <?= $kode === 'operator' ? 'selected' : '' ?>><?= $label ?></option>
<?php endforeach; ?>
      </select>
    </label>
<?php if (empty($sso)) : ?>
    <label class="text-sm">Kata sandi awal (paling sedikit <?= PenggunaRepo::SANDI_MIN ?> karakter)
      <input type="password" name="sandi" required minlength="<?= PenggunaRepo::SANDI_MIN ?>" autocomplete="new-password" class="<?= $bidang ?> mt-1 w-full">
    </label>
<?php endif; ?>
  </div>
  <button class="mt-3 rounded bg-stone-800 px-4 py-2 text-sm font-semibold text-white hover:bg-stone-700">Tambah pengguna</button>
</form>
