<?php
use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\{DB,Schema};
return new class extends Migration {
 public function up():void {
  Schema::table('reporting_periods',fn(Blueprint $t)=>$t->timestamp('workspace_archived_at')->nullable()->index());
  Schema::table('report_share_links',fn(Blueprint $t)=>$t->text('encrypted_token')->nullable());
  // Classify existing published public shares without altering statuses, snapshots, versions or links.
  $published=DB::table('report_share_links as s')->join('report_publications as p','p.id','=','s.publication_id')->where('s.access_mode','public')->groupBy('p.reporting_period_id')->selectRaw('p.reporting_period_id, MIN(s.created_at) as shared_at')->get();
  foreach($published as $row)DB::table('reporting_periods')->where('id',$row->reporting_period_id)->where('status','published')->update(['workspace_archived_at'=>$row->shared_at]);
 }
 public function down():void {throw new RuntimeException('Published link and workspace history are retained. Use additive migrations.');}
};
