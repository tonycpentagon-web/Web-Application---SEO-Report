<?php

namespace App\Models;

use Illuminate\Database\Eloquent\Concerns\HasUlids;
use Illuminate\Database\Eloquent\Model;

class DocumentExtractedContent extends Model
{
    use HasUlids;

    public $timestamps = false;

    protected $table = 'document_extracted_content';

    protected $guarded = ['id'];

    protected function casts(): array
    {
        return ['structured_json' => 'array', 'source_location' => 'array'];
    }
}
