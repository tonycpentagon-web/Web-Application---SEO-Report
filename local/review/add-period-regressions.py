from pathlib import Path
p=Path('backend/tests/Feature/EvidenceValidationTest.php');s=p.read_text(encoding='utf-8');idx=s.rfind('\n}')
s=s[:idx]+'''
    public function test_partial_month_requires_one_audited_assignment_without_faking_human_verification(): void
    {
        $row=$this->row();
        DB::table('document_extracted_content')->where('id',$this->content)->update(['original_text'=>"PREPARED FOR:\\n1 Aug 2026 - 30 Aug 2026\\nTotal users 100"]);
        $v=$this->evaluate();$this->assertSame('NEEDS_REVIEW',$this->state($v,$row));
        $state=$this->getJson($this->base.'/sharing')->assertOk()->json('data');
        $this->assertTrue($state['assessment']['eligible']);$this->assertCount(1,$state['assessment']['period_confirmations']);
        $payload=['version'=>1,'fingerprint'=>$state['assessment']['fingerprint'],'acknowledge_uncertainty'=>true];
        $this->postJson($this->base.'/delivery/share-report',$payload)->assertUnprocessable();
        $link=$this->postJson($this->base.'/delivery/share-report',[...$payload,'confirm_source_periods'=>true])->assertCreated()->json('data');
        $snapshot=\\App\\Models\\ReportPublication::findOrFail($link['publication_id'])->snapshot;
        $this->assertSame(['2026-08-31'],$snapshot['source_periods'][0]['uncovered_dates']);$this->assertTrue($snapshot['source_periods'][0]['scope_confirmed']);
        $this->assertDatabaseCount('report_source_scope_verifications',1);$this->assertDatabaseCount('record_verifications',0);$this->assertSame('pending',$row->fresh()->review_status);
        $this->assertDatabaseHas('report_delivery_events',['action'=>'report.source_periods_confirmed','actor_id'=>$this->admin->id]);
    }

    public function test_refresh_checks_distinguishes_stale_validation_from_changed_sources_for_an_employee(): void
    {
        $this->row();$this->evaluate();
        DB::table('report_validation_runs')->update(['fingerprint'=>str_repeat('0',64)]);
        $employee=User::factory()->create(['role'=>'seo_executive','is_super_admin'=>false]);$this->p->project->members()->attach($employee->id,['assignment_role'=>'member']);$this->actingAs($employee);
        $state=$this->getJson($this->base.'/sharing')->assertOk()->json('data.assessment');
        $this->assertFalse($state['requires_regeneration']);$this->assertTrue($state['requires_validation_refresh']);$this->assertStringNotContainsString('Sources changed',implode(' ',$state['blockers']));
        $before=$this->doc->fresh()->getAttributes();
        $this->postJson($this->base.'/sharing/refresh',['version'=>1,'confirmed'=>true])->assertOk();
        $this->assertSame($before,$this->doc->fresh()->getAttributes());$this->assertTrue(app(EvidenceValidation::class)->current($this->p)['current']);$this->assertDatabaseCount('report_documents',1);
        $this->p->update(['requires_regeneration'=>true]);
        $this->postJson($this->base.'/sharing/refresh',['version'=>1,'confirmed'=>true])->assertConflict();
        $this->getJson($this->base.'/sharing')->assertJsonPath('data.assessment.requires_regeneration',true)->assertJsonPath('data.assessment.requires_validation_refresh',false);
    }
'''+s[idx:];p.write_text(s,encoding='utf-8')
p=Path('frontend/src/test/simplified-sharing.test.tsx');s=p.read_text(encoding='utf-8').replace("import {ReportSharePage} from '../pages/report-share';", "import {ReportSharePage,type SharingState} from '../pages/report-share';").replace("let state={version:1", "let state:SharingState={version:1")
s+='''
it('offers refresh checks for unchanged generation instead of falsely asking for regeneration',async()=>{state={...state,assessment:{...assessment,eligible:false,requires_validation_refresh:true,requires_regeneration:false,blockers:['Generation matches the current source files. Evidence checks need refreshing.']}};mount();await screen.findByRole('button',{name:'Refresh report checks'});expect(screen.queryByRole('button',{name:'Regenerate Report'})).not.toBeInTheDocument();await userEvent.click(screen.getByRole('button',{name:'Refresh report checks'}));await waitFor(()=>expect(mock.post).toHaveBeenCalledWith('/api/projects/p/reports/r/sharing/refresh',{version:1,confirmed:true}));});
it('requires explicit partial-period assignment and preserves printed coverage in the sharing summary',async()=>{state={...state,report:{title:'August 2026',client_name:'Synthetic client',month:'August 2026'},assessment:{...assessment,period_confirmations:[{document_id:'d',document:'Analytics.pdf',status:'partial',ranges:[{start:'2026-08-01',end:'2026-08-30'}]}],source_periods:[{document_id:'d',document:'Analytics.pdf',status:'partial',ranges:[{start:'2026-08-01',end:'2026-08-30'}]}]}};mount();await screen.findByText('2026-08-01 to 2026-08-30');const button=screen.getByRole('button',{name:'Share Report'});expect(button).toBeDisabled();await userEvent.click(screen.getByRole('checkbox',{name:/I confirm the listed partial or uncertain sources/}));await userEvent.click(button);await waitFor(()=>expect(mock.post).toHaveBeenCalledWith('/api/projects/p/reports/r/delivery/share-report',expect.objectContaining({acknowledge_uncertainty:true,confirm_source_periods:true})));});
''';p.write_text(s,encoding='utf-8')
