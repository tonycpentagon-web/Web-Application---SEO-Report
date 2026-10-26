<?php
namespace Tests\Unit;
use App\Services\PublicationCommentary;
use PHPUnit\Framework\TestCase;
class PublicationCommentaryTest extends TestCase {
 public function test_summary_counts_the_exact_publication_rows_and_never_invents_sessions():void {
  $s=['kpis'=>[['metric_key'=>'ga4.total_users','unit'=>'count','value'=>4515]],'records'=>[
   ['kind'=>'keyword_rankings','data'=>['ranking_label'=>'1st Page']],['kind'=>'keyword_rankings','data'=>['ranking_label'=>'3rd Page']],
   ['kind'=>'seo_activities','data'=>['category'=>'social_bookmarking']]]];
  $r=(new PublicationCommentary)->sections($s);$this->assertStringContainsString('2 distinct keyword observations',$r['keywords']);$this->assertStringContainsString('1st Page: 1',$r['keywords']);$this->assertStringContainsString('1 distinct listed activities',$r['seo_work']);$this->assertStringContainsString('Sessions: not reported',$r['website_traffic']);$this->assertStringContainsString('4,515 users',$r['website_traffic']);
 }
}
