<?php

namespace App\Services;

use Illuminate\Database\QueryException;
use Symfony\Component\Process\Exception\ProcessTimedOutException;
use Symfony\Component\Process\Process;

class PythonDocumentProcessor
{
    public function binary(): string
    {
        $value = config('documents.python');
        if (str_contains($value, '/') || str_contains($value, '\\')) {
            return realpath(base_path($value)) ?: $value;
        }

        return $value;
    }

    public function doctor(): array
    {
        try {
            $p = new Process([$this->binary(), config('documents.script'), '--doctor'], base_path(), [...ProcessEnvironment::local(), 'PYTHONUTF8' => '1', 'DOCUMENT_TESSERACT' => config('documents.tesseract')]);
            $p->setTimeout(25);
            $p->run();
            if (! $p->isSuccessful()) {
                throw new \RuntimeException;
            }
            $result = json_decode(trim($p->getOutput()), true, 512, JSON_THROW_ON_ERROR);

            $engine = config('documents.ocr_engine');
            $result['ocr']['configured_engine'] = $engine;
            if ($engine === 'none') {
                $result['ocr']['available'] = false;
            }
            if (in_array($engine, ['rapidocr', 'tesseract'])) {
                $result['ocr']['available'] = $result['ocr'][$engine] ?? false;
            }

            return ['available' => true, ...$result, 'max_file_mb' => config('documents.max_file_mb'), 'max_batch' => config('documents.max_batch'), 'max_total_mb' => config('documents.max_total_mb'), 'accepted_extensions' => config('documents.extensions')];
        } catch (\Throwable $e) {
            return ['available' => false, 'message' => 'Python or extraction dependencies are unavailable. Configure DOCUMENT_PYTHON and run php artisan documents:doctor.', 'formats' => [], 'ocr' => ['available' => false], 'max_file_mb' => config('documents.max_file_mb'), 'max_batch' => config('documents.max_batch'), 'max_total_mb' => config('documents.max_total_mb'), 'accepted_extensions' => config('documents.extensions')];
        }
    }

    public function process(string $input, string $output, string $extension, callable $event): array
    {
        $options = ['max_pages' => config('documents.max_pages'), 'max_cells' => config('documents.max_cells'), 'max_expanded_mb' => config('documents.max_expanded_mb'), 'max_output_mb' => config('documents.max_output_mb'), 'ocr_engine' => config('documents.ocr_engine'), 'tesseract' => config('documents.tesseract')];
        $p = new Process([$this->binary(), config('documents.script')], base_path(), [...ProcessEnvironment::local(), 'PYTHONUTF8' => '1', 'DOCUMENT_TESSERACT' => config('documents.tesseract')]);
        $p->setTimeout(config('documents.timeout'));
        $p->setInput(json_encode(['input' => $input, 'output' => $output, 'extension' => $extension, 'options' => $options], JSON_THROW_ON_ERROR));
        $buffer = '';
        $error = null;
        try {
            $p->run(function ($type, $chunk) use (&$buffer, &$error, $event) {
                if ($type !== Process::OUT) {
                    return;
                }$buffer .= $chunk;
                while (($index = strpos($buffer, "\n")) !== false) {
                    $line = substr($buffer, 0, $index);
                    $buffer = substr($buffer, $index + 1);
                    $data = json_decode($line, true);
                    if (! is_array($data)) {
                        continue;
                    }
                    if (($data['event'] ?? null) === 'error') {
                        $error = mb_substr((string) ($data['message'] ?? 'Extraction failed.'), 0, 1000);
                    }
                    if (($data['event'] ?? null) === 'progress') {
                        $event($data);
                    }
                }
            });
        } catch (ProcessTimedOutException $e) {
            throw new \RuntimeException('Document processing timed out. Review configured limits and retry.');
        } catch (QueryException $e) {
            throw $e;
        } catch (\Throwable $e) {
            throw new \RuntimeException('Python could not start or processing was interrupted. Check DOCUMENT_PYTHON and documents:doctor.');
        }
        if (! $p->isSuccessful()) {
            throw new \RuntimeException($error ?: 'Python extraction failed. Check requirements, document integrity and documents:doctor.');
        }
        $raw = $output.DIRECTORY_SEPARATOR.'raw.json';
        if (! is_file($raw) || filesize($raw) > config('documents.max_output_mb') * 1024 * 1024) {
            throw new \RuntimeException('Extraction output is missing or exceeds the configured limit.');
        }
        $json = file_get_contents($raw);
        $result = json_decode($json, true, 512, JSON_THROW_ON_ERROR);
        if (($result['schema_version'] ?? null) !== 1 || ! in_array($result['status'] ?? null, ['extracted', 'partially_extracted', 'needs_review']) || ! is_array($result['content'] ?? null) || ! is_array($result['assets'] ?? null)) {
            throw new \RuntimeException('Processor returned an invalid extraction schema.');
        }

        return ['data' => $result, 'json' => $json];
    }
}
