<?php
// Background studio check, started from /admin/slots.
if (PHP_SAPI !== 'cli') exit;
chdir(__DIR__ . '/..');
require __DIR__ . '/mysql_compat.php';
require __DIR__ . '/bd.php';
require __DIR__ . '/wgc.php';
$r = wgc_probe();
echo json_encode($r, JSON_UNESCAPED_UNICODE), "\n";
