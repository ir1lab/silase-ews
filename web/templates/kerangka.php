<?php

use Silase\Web\Format as F;

/** @var string $judul */
/** @var string $isi */
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
    <div class="mx-auto flex max-w-5xl items-center justify-between px-4 py-3">
      <a href="/" class="flex items-baseline gap-2">
        <span class="text-lg font-bold tracking-tight">SILASE-EWS</span>
        <span class="hidden text-sm text-stone-500 sm:inline">Pemantauan fermentasi silase</span>
      </a>
      <nav class="text-sm font-medium">
        <a href="/" class="rounded px-2 py-1 text-stone-700 hover:bg-stone-100">Ringkasan</a>
      </nav>
    </div>
  </header>

  <main class="mx-auto max-w-5xl px-4 py-5">
<?= $isi ?>
  </main>

<?php foreach ($skrip ?? [] as $s) : ?>
  <script src="<?= F::e($s) ?>" defer></script>
<?php endforeach; ?>
</body>
</html>
