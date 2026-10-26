<?php
namespace Tests\Unit;
use App\Models\ReportingPeriod;
use App\Services\SourceReportingPeriod;
use Tests\TestCase;
class SourceReportingPeriodTest extends TestCase {
 private function detect(string $text,string $method='native',string $type='text'):array {
  $p=new ReportingPeriod(['start_date'=>'2026-08-01','end_date'=>'2026-08-31']);
  $piece=(object)['id'=>'content','original_text'=>$text,'content_type'=>$type,'confidence_status'=>$method,'source_location'=>json_encode(['page'=>2])];
  return (new SourceReportingPeriod)->detect([$piece],$p);
 }
 public function test_explicit_period_wins_over_creation_upload_chart_and_comparison_dates():void {
  $r=$this->detect("Reporting period: 1 Aug 2026 - 31 Aug 2026\nCreated: 1 Jul 2026 - 31 Jul 2026\nScreenshot timestamp: 1 Sep 2026 - 30 Sep 2026\nChart dates\n1 Jul 2026 - 31 Jul 2026\nActivity date: 1 Jun 2026 - 30 Jun 2026\nPrevious period: 1 Jul 2026 - 31 Jul 2026");
  $this->assertSame('exact',$r['status']);$this->assertCount(5,$r['ignored_dates']);$this->assertSame(1,$r['evidence'][0]['location']['line']);
 }
 public function test_partial_native_coverage_is_not_a_different_month_and_missing_day_is_retained():void {
  $r=$this->detect("Total users\n4515\n1 Aug 2026 - 30 Aug 2026");
  $this->assertSame('partial',$r['status']);$this->assertTrue($r['requires_confirmation']);$this->assertSame(['2026-08-31'],$r['uncovered_dates']);$this->assertSame('2026-08-30',$r['ranges'][0]['end']);
 }
 public function test_ocr_dates_are_uncertain_even_when_they_look_like_a_range():void {
  $r=$this->detect("1Aug 2026-30Aug 2026\nEvent name\nEvent count",'ocr','ocr_text');
  $this->assertSame('uncertain',$r['status']);$this->assertFalse($r['known']);$this->assertTrue($r['requires_confirmation']);$this->assertSame('ocr',$r['evidence'][0]['method']);$this->assertSame('2026-08-30',$r['ranges'][0]['end']);
 }
 public function test_explicit_other_month_and_cross_month_coverage_remain_mismatches():void {
  foreach(["Reporting period: 1 Sep 2026 - 30 Sep 2026","29 Aug 2026 - 27 Sept 2026","SEO REPORT - S E P T '2 6"] as $text)$this->assertSame('mismatch',$this->detect($text)['status']);
 }
 public function test_file_metadata_and_unrelated_dates_cannot_establish_a_period():void {
  $this->assertSame('uncertain',$this->detect('Creation date: 1 Sep 2026 - 30 Sep 2026')['status']);
  $this->assertSame('uncertain',$this->detect('1 Sep 2026 - 30 Sep 2026','native','metadata')['status']);
  $this->assertSame('uncertain',$this->detect('The campaign ran 1 Sep 2026 - 30 Sep 2026 and a task was completed.')['status']);
 }
 public function test_uncertain_ocr_outside_the_month_cannot_be_assigned_without_repair():void {
  $r=$this->detect('1 Sep 2026 - 30 Sep 2026','ocr','ocr_text');$this->assertSame('uncertain',$r['status']);$this->assertTrue($r['candidate_outside_period']);
 }
 public function test_month_first_date_selector_detects_cross_month_coverage_instead_of_uncertainty():void {
  $p=new ReportingPeriod(['start_date'=>'2026-09-01','end_date'=>'2026-09-30']);
  $piece=(object)['id'=>'native-source','original_text'=>"Total users\n702\nAug 29, 2026 - Sep 27, 2026 arrow_drop_down",'content_type'=>'text','confidence_status'=>'native','source_location'=>['page'=>2]];
  $r=(new SourceReportingPeriod)->detect([$piece],$p);$this->assertSame('mismatch',$r['status']);$this->assertSame([['start'=>'2026-08-29','end'=>'2026-09-27']],$r['ranges']);$this->assertTrue($r['known']);
 }

 public function test_compact_month_first_ocr_range_remains_uncertain_and_outside_candidate():void {
  $p=new ReportingPeriod(['start_date'=>'2026-09-01','end_date'=>'2026-09-30']);$piece=(object)['id'=>'ocr-source','original_text'=>'Aug 29,2026-Sep 27,2026','content_type'=>'ocr_text','confidence_status'=>'ocr','source_location'=>['page'=>1]];
  $r=(new SourceReportingPeriod)->detect([$piece],$p);$this->assertSame('uncertain',$r['status']);$this->assertTrue($r['candidate_outside_period']);$this->assertSame('2026-08-29',$r['ranges'][0]['start']);$this->assertSame('2026-09-27',$r['ranges'][0]['end']);
 }

}
