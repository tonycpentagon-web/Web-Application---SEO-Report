from pathlib import Path
p=Path('backend/tests/Feature/EvidenceValidationTest.php');s=p.read_text(encoding='utf-8');idx=s.rfind('\n}')
s=s[:idx]+'''
    public function test_snapshot_uses_current_period_evidence_instead_of_old_normalization_date_guesses(): void
    {
        $this->row();
        $this->run->update(['summary'=>['classifications'=>[['document_id'=>$this->doc->id,'source_dates'=>[['start'=>'2026-07-01','end'=>'2026-07-31']]]]]]);
        $this->evaluate();
        $snapshot=app(\\App\\Services\\ReportDelivery::class)->snapshot($this->p,$this->admin,true);
        $this->assertSame([['start'=>'2026-08-01','end'=>'2026-08-31']],$snapshot['records'][0]['sources'][0]['source_period']);
    }
'''+s[idx:];p.write_text(s,encoding='utf-8')
