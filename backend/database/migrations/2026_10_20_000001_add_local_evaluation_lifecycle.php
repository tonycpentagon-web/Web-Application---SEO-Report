<?php
use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;
return new class extends Migration {
 public function up(): void {
  foreach (['clients','projects','reporting_periods'] as $table) Schema::table($table, function(Blueprint $t){$t->softDeletes();$t->string('lifecycle_previous_status',24)->nullable();});
  Schema::table('reporting_periods',fn(Blueprint $t)=>$t->boolean('requires_regeneration')->default(false));
  Schema::table('report_share_links',fn(Blueprint $t)=>$t->string('access_mode',24)->default('authenticated'));
  // Deliberately independent of foreign keys: retained audit survives permanent removal.
  Schema::create('retained_lifecycle_events',function(Blueprint $t){$t->ulid('id')->primary();$t->string('entity_type',24);$t->string('entity_id',26);$t->unsignedBigInteger('actor_id')->nullable();$t->string('action',64);$t->longText('metadata');$t->timestamp('created_at')->index();$t->index(['entity_type','entity_id']);});
  Schema::create('private_file_removals',function(Blueprint $t){$t->ulid('id')->primary();$t->text('storage_path');$t->timestamp('created_at');});
 }
 public function down():void {throw new RuntimeException('Lifecycle audit is retained; use additive migrations.');}
};
