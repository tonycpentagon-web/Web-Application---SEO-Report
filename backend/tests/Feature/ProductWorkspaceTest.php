<?php
namespace Tests\Feature;

use App\Models\Client;
use App\Models\Project;
use App\Models\User;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Tests\TestCase;

class ProductWorkspaceTest extends TestCase
{
    use RefreshDatabase;

    public function test_thirty_assigned_employees_have_scoped_pipeline_counts_and_administration_is_restricted(): void
    {
        $this->travelTo(\Illuminate\Support\Carbon::parse('2026-10-20 12:00:00'));
        $admin=User::factory()->create(['role'=>'admin','is_super_admin'=>false]);
        $other=User::factory()->create(['role'=>'admin','is_super_admin'=>false]);
        $projects=[];
        foreach([$admin,$other] as $i=>$owner){
            $client=new Client(['name'=>'Scoped Client '.$i,'status'=>'active']);$client->forceFill(['user_id'=>$owner->id,'identity_key'=>hash('sha256','client-'.$i)])->save();
            $projects[]=Project::factory()->create(['client_id'=>$client->id,'user_id'=>$owner->id]);
        }
        $employees=User::factory()->count(30)->create(['role'=>'seo_executive','is_super_admin'=>false,'managed_by'=>$admin->id]);
        foreach($employees as $i=>$person)$projects[$i%2]->members()->attach($person->id,['assignment_role'=>'member']);
        $a=$employees[0];$b=$employees[1];$rootA='/api/projects/'.$projects[0]->id.'/reports';$rootB='/api/projects/'.$projects[1]->id.'/reports';
        $ra=$this->actingAs($a)->postJson($rootA,['title'=>'Employee A October','kind'=>'monthly','reporting_month'=>'2026-10'])->assertCreated()->json('data');
        $this->assertSame(1,$ra['version']);
        $rb=$this->actingAs($b)->postJson($rootB,['title'=>'Employee B October','kind'=>'monthly','reporting_month'=>'2026-10'])->assertCreated()->json('data');
        $this->actingAs($a)->getJson('/api/dashboard')->assertOk()->assertJsonPath('active_projects',1)->assertJsonPath('reports_this_month',1)->assertJsonPath('draft_reports',1)->assertJsonPath('recent_reports.0.title','Employee A October')->assertDontSee('Employee B October');
        $this->getJson('/api/administration')->assertForbidden();$this->getJson($rootB)->assertNotFound();
        $root=$rootA.'/'.$ra['id'];
        $this->patchJson($root.'/status',['status'=>'needs_review','version'=>$ra['version']])->assertOk();
        $this->actingAs($employees[2])->patchJson($root.'/status',['status'=>'needs_review','version'=>$ra['version']])->assertConflict();
        $this->patchJson($root.'/status',['status'=>'approved','version'=>$ra['version']+1])->assertConflict();
        $this->postJson($root.'/delivery/publish',['confirmed'=>true,'version'=>$ra['version']+1])->assertForbidden();
        foreach([[$ra,$projects[0]],[$rb,$projects[1]]] as [$period,$project]){\App\Models\ReportGeneration::create(['reporting_period_id'=>$period['id'],'requested_by'=>$admin->id,'status'=>'failed']);\App\Models\ReportPdfExport::create(['reporting_period_id'=>$period['id'],'generated_by'=>$admin->id,'type'=>'draft','status'=>'failed','payload'=>[],'sections'=>[]]);}
        $this->actingAs($admin)->getJson('/api/administration')->assertOk()->assertJsonPath('data.projects',1)->assertJsonPath('data.pending.0.title','Employee A October')->assertJsonCount(1,'data.failed_generations')->assertJsonCount(1,'data.failed_exports')->assertDontSee('Employee B October');
        $viewer=User::factory()->create(['role'=>'client_viewer','is_super_admin'=>false]);
        $this->actingAs($viewer)->getJson('/api/dashboard')->assertForbidden();$this->getJson('/api/administration')->assertForbidden();
        $a->forceFill(['is_active'=>false])->save();$this->actingAs($a)->getJson('/api/dashboard')->assertUnauthorized();
    }
}
