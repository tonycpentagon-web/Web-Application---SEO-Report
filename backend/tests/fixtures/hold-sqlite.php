<?php
// Test helper: hold a write reservation on the explicitly supplied test database.
if (count($argv)!==3 || !str_contains($argv[1], 'testing')) exit(2);
$pdo=new PDO('sqlite:'.$argv[1]);$pdo->exec('PRAGMA busy_timeout=5000');$pdo->exec('BEGIN IMMEDIATE');$pdo->exec('UPDATE lock_probe SET value=value+1');
file_put_contents($argv[2],'ready');usleep(700000);$pdo->exec('COMMIT');
