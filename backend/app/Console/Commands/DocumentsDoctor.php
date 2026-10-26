<?php

namespace App\Console\Commands;

use App\Services\PythonDocumentProcessor;
use Illuminate\Console\Command;

class DocumentsDoctor extends Command
{
    protected $signature = 'documents:doctor';

    protected $description = 'Check Python extraction dependencies, format support and OCR availability';

    public function handle(PythonDocumentProcessor $p): int
    {
        $health = $p->doctor();
        $health['runtime'] = ['php_memory_limit' => ini_get('memory_limit'), 'python_timeout_seconds' => config('documents.timeout'), 'job_timeout_seconds' => config('documents.timeout') + 60, 'queue_retry_after_seconds' => config('queue.connections.database.retry_after'), 'sqlite_transaction_mode' => config('database.connections.sqlite.transaction_mode'), 'sqlite_busy_timeout_ms' => config('database.connections.sqlite.busy_timeout')];
        $this->line(json_encode($health, JSON_PRETTY_PRINT | JSON_UNESCAPED_SLASHES));
        if ((int) config('queue.connections.database.retry_after') <= config('documents.timeout') + 60) {
            $this->warn('DB_QUEUE_RETRY_AFTER must exceed the document job timeout (DOCUMENT_TIMEOUT + 60).');
        }

        return ($health['available'] ?? false) ? self::SUCCESS : self::FAILURE;
    }
}
