<?php

use Silase\Web\Format as F;

/** @var string $judul */
/** @var string $pesan */
?>
<div class="rounded-lg border border-stone-300 bg-white p-6">
  <h1 class="text-xl font-bold"><?= F::e($judul) ?></h1>
  <p class="mt-1 text-sm text-stone-600"><?= F::e($pesan) ?></p>
  <a href="/" class="mt-4 inline-block rounded bg-stone-800 px-3 py-2 text-sm font-semibold text-white">Kembali ke ringkasan</a>
</div>
