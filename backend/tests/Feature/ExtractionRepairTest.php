<?php

namespace Tests\Feature;

use App\Services\DocumentFailure;
use Illuminate\Database\QueryException;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Storage;
use Symfony\Component\Process\Process;
use Tests\TestCase;

class ExtractionRepairTest extends TestCase
{
    public function test_specific_database_lock_message_does_not_expose_sql_or_bindings(): void
    {
        $e = new QueryException('sqlite', 'update private_table set secret=?', ['do-not-display'], new \PDOException('SQLSTATE[HY000]: General error: 5 database is locked'));
        $text = DocumentFailure::message($e);
        $this->assertStringContainsString('SQLite database was busy', $text);
        $this->assertStringNotContainsString('private_table', $text);
        $this->assertStringNotContainsString('do-not-display', $text);
    }

    public function test_immediate_transaction_waits_for_another_process_writer_without_upgrade_deadlock(): void
    {
        Storage::fake('local');
        $dir = Storage::disk('local')->path('');
        $path = $dir.'/lock-probe.sqlite';
        $ready = $dir.'/writer.ready';
        touch($path);
        config(['database.connections.lock_probe' => [...config('database.connections.sqlite'), 'database' => $path, 'url' => null]]);
        $connection = DB::connection('lock_probe');
        $connection->statement('CREATE TABLE lock_probe(value INTEGER)');
        $connection->statement('INSERT INTO lock_probe VALUES(0)');
        $writer = new Process([PHP_BINARY, base_path('tests/fixtures/hold-sqlite.php'), $path, $ready]);
        $writer->start();
        try {
            $deadline = microtime(true) + 5;
            while (! is_file($ready) && $writer->isRunning() && microtime(true) < $deadline) {
                usleep(10000);
            }
            $this->assertFileExists($ready);
            $connection->transaction(function () use ($connection) {
                $this->assertSame(1, (int) $connection->table('lock_probe')->value('value'));
                $connection->table('lock_probe')->increment('value');
            }, 5);
            $writer->wait();
            $this->assertTrue($writer->isSuccessful(), $writer->getErrorOutput());
            $this->assertSame(2, (int) $connection->table('lock_probe')->value('value'));
            $this->assertSame('IMMEDIATE', $connection->getConfig('transaction_mode'));
            $this->assertSame('wal', $connection->select('pragma journal_mode')[0]->journal_mode);
        } finally {
            $writer->stop();
            DB::purge('lock_probe');
            foreach ([$ready, $path, $path.'-wal', $path.'-shm'] as $file) {
                if (is_file($file)) {
                    unlink($file);
                }
            }
        }
    }
}
