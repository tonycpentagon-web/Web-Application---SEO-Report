<?php

namespace App\Services;

use Illuminate\Support\Facades\DB;
use Illuminate\Support\Str;

class LegacyClientBackfill
{
    public function run(): void
    {
        DB::transaction(function () {
            DB::table('projects')->whereNull('client_id')->orderBy('id')->get()->each(function ($p) {
                $name = trim(preg_replace('/\s+(?:(?:local|technical)\s+)?seo$/i', '', $p->client_name)) ?: $p->client_name;
                $key = hash('sha256', mb_strtolower(trim($name)));
                $client = DB::table('clients')->where('user_id', $p->user_id)->where('identity_key', $key)->first();
                if (! $client) {
                    $id = (string) Str::ulid();
                    DB::table('clients')->insert(['id' => $id, 'user_id' => $p->user_id, 'identity_key' => $key, 'name' => $name, 'website' => $p->website, 'status' => $p->status, 'created_at' => $p->created_at, 'updated_at' => $p->updated_at]);
                } else {
                    $id = $client->id;
                }
                // Preserve every existing project field, ID and owner; attach only the new client relationship.
                DB::table('projects')->where('id', $p->id)->update(['client_id' => $id]);
            });
        });
    }
}
