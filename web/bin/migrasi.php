#!/usr/bin/env php
<?php

// Memasang skema ke basis data di .env. Aman dijalankan berulang.

declare(strict_types=1);

use Silase\Config;
use Silase\Db;

require dirname(__DIR__) . '/vendor/autoload.php';

Config::muat(dirname(__DIR__));
$pdo = Db::sambung();
Db::pasangSkema($pdo, dirname(__DIR__) . '/sql/schema.sql');

echo "Skema terpasang.\n";
