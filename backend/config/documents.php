<?php

return [
    'python' => env('DOCUMENT_PYTHON', 'python'),
    'script' => base_path(env('DOCUMENT_PROCESSOR_SCRIPT', '../document-processor/processor.py')),
    'queue' => env('DOCUMENT_QUEUE', 'documents'),
    'max_file_mb' => (int) env('DOCUMENT_MAX_FILE_MB', 25), 'max_batch' => (int) env('DOCUMENT_MAX_BATCH', 10), 'max_total_mb' => (int) env('DOCUMENT_MAX_TOTAL_MB', 100),
    'timeout' => (int) env('DOCUMENT_TIMEOUT', 900), 'max_pages' => (int) env('DOCUMENT_MAX_PAGES', 500), 'max_cells' => (int) env('DOCUMENT_MAX_CELLS', 250000),
    'max_expanded_mb' => (int) env('DOCUMENT_MAX_EXPANDED_MB', 100), 'max_output_mb' => (int) env('DOCUMENT_MAX_OUTPUT_MB', 32),
    'ocr_engine' => env('DOCUMENT_OCR_ENGINE', 'auto'), 'tesseract' => env('DOCUMENT_TESSERACT', 'tesseract'),
    'extensions' => ['pdf', 'docx', 'xlsx', 'xls', 'csv', 'png', 'jpg', 'jpeg', 'webp'],
];
