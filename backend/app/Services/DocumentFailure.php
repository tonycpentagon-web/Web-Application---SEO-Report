<?php

namespace App\Services;

use Illuminate\Database\QueryException;
use Illuminate\Queue\MaxAttemptsExceededException;
use Illuminate\Queue\TimeoutExceededException;
use PDOException;
use Throwable;

class DocumentFailure
{
    public static function message(?Throwable $e): string
    {
        for ($cause = $e; $cause; $cause = $cause->getPrevious()) {
            if ($cause instanceof QueryException || $cause instanceof PDOException) {
                if (preg_match('/database (?:is )?(?:locked|busy)|SQLITE_BUSY/i', $cause->getMessage())) {
                    return 'SQLite database was busy while saving extraction state. No document was lost. Retry after other database writes finish; use the configured IMMEDIATE transactions and one local document worker.';
                }

                return 'The database could not save extraction state (SQLSTATE '.preg_replace('/[^A-Z0-9]/i', '', (string) $cause->getCode()).'). Check database availability and retry. The original file is retained.';
            }
            if ($cause instanceof TimeoutExceededException) {
                return 'The queue worker exceeded this extraction job’s time limit. Check the last recorded processing activity before retrying.';
            }
            if ($cause instanceof MaxAttemptsExceededException) {
                return 'The queue delivery exceeded its permitted attempts before completion. Check worker interruption and retry_after configuration, then retry extraction.';
            }
        }
        if ($e instanceof \JsonException) {
            return 'The processor returned unreadable extraction JSON. The original and any generated evidence are retained; inspect the processor output and retry.';
        }
        if ($e instanceof \RuntimeException && ! str_contains($e->getMessage(), 'SQLSTATE') && ! str_contains($e->getMessage(), 'Stack trace')) {
            return mb_substr($e->getMessage(), 0, 1000);
        }

        return $e ? 'Extraction stopped because of '.class_basename($e).'. The original file is retained. Inspect the processing log before retrying.' : 'The queue worker stopped without reporting an exception. Inspect its process log and retry extraction.';
    }
}
