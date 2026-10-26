import json,unittest
from pathlib import Path
from normalization import Normalizer,number,rank,classify

def content(text,identifier='c1',kind='text',data=None,source=None,confidence='native'):
 return {'id':identifier,'ordinal':0,'type':kind,'text':text,'data':data or {'reading_order':'original_text_stream'},'source':source or {'page':1},'confidence':confidence}
def document(text='',identifier='doc-a',items=None,assets=None):
 return {'id':identifier,'extraction_run_id':'extract-'+identifier,'status':'extracted','content':items or [content(text,identifier+'-text')],'assets':assets or []}
def normalize(docs):
 return Normalizer({'report':{'id':'period','start_date':'2026-08-01','end_date':'2026-08-31'},'documents':docs}).run()

class NormalizationTests(unittest.TestCase):
 def test_numbers_units_and_rank_page_are_not_exact_position(self):
  self.assertEqual(1200,number('1,200'));self.assertEqual(.68,number('0.68%','percent'));self.assertEqual(83,number('00:01:23','seconds'))
  self.assertIsNone(rank('1st Page')['exact_position']);self.assertEqual(12,rank('#12')['exact_position']);self.assertEqual('not_ranked',rank('Not Ranked')['group'])
  for raw in ['1.234,56','NaN','-4','12,34']:
   with self.assertRaises(ValueError):number(raw)
 def test_different_client_layout_and_values_produce_distinct_ga4_and_gsc_metrics(self):
  r=normalize([document('Total users\n800\nNew users\n700\nViews\n900\nAverage Session Duration\n00:02:10\nClicks\n90\nImpressions\n2,000\nSite CTR\n4.5%')])
  data={x['data']['metric_key']:x['data']['value'] for x in r['records'] if x['kind']=='report_metrics'}
  self.assertEqual(800,data['ga4.total_users']);self.assertEqual(90,data['gsc.clicks']);self.assertEqual(130,data['ga4.average_session_duration']);self.assertEqual(4.5,data['gsc.ctr'])
 def test_traffic_devices_and_landing_records_from_native_labels(self):
  text='Total users\n800\nSession default\nchannel group\nTotal users\nNew users\nOrganic Search\n500\n450\nDirect\n300\n250\nGrand total\n800\n700\nClicks\n90\nImpressions\n2,000\nSite CTR\n4.5%\nDevice Category\nClicks\nImpressions\nMOBILE\n70\n1,500\nDESKTOP\n20\n500\nGrand total\n90\n2,000\nLanding Page\nUrl Clicks\nImpressions\nURL CTR\nhttps://different.test/a\n90\n2,000\n4.5%'
  r=normalize([document(text)]);self.assertEqual(2,r['counts']['traffic_channels']);self.assertEqual(2,r['counts']['device_metrics']);self.assertEqual(1,r['counts']['landing_page_metrics'])
 def test_keywords_two_column_csv_layout_and_source_page_labels(self):
  c=content('Keyword,Ranking\nCustom treatment,2nd Page\nOther,12',kind='table',data={'rows':[['Keyword','Ranking'],['Custom treatment','2nd Page'],['Other','12']]})
  r=normalize([document(items=[c])]);rows=[x for x in r['records'] if x['kind']=='keyword_rankings']
  self.assertEqual(2,len(rows));self.assertEqual('page_2',rows[0]['data']['group']);self.assertIsNone(rows[0]['data']['exact_position']);self.assertEqual(12,rows[1]['data']['exact_position'])
 def test_multiline_keyword_rows_and_alternate_labels_are_not_discarded(self):
  items=[content('KEYWORDS\nRANKING\n1st Page','text'),content('',kind='table',data={'rows':[['SL. NO.','KEYWORDS','RANKING'],['1','Keyword A',None],['','Alternate A','1st Page']]},identifier='table')]
  r=normalize([document(items=items)]);row=next(x for x in r['records'] if x['kind']=='keyword_rankings')
  self.assertEqual(['Alternate A'],row['data']['alternative_labels']);self.assertEqual('inferred',row['confidence']);self.assertTrue(any(w['code']=='multiline_keyword_rank' for w in r['warnings']))
 def test_events_are_not_automatically_leads_and_total_is_validated(self):
  text='Eventname\nEventcount\n1.\ncustom_event\n7\n2.\nphone_tap\n2\nGrand total\n10'
  r=normalize([document(items=[content(text,kind='ocr_text',confidence='ocr')])])
  self.assertEqual(2,r['counts']['event_metrics']);self.assertTrue(all(x['data']['classification']=='reported_event_not_verified_lead' for x in r['records'] if x['kind']=='event_metrics'))
  self.assertTrue(any(w['code']=='event_total_mismatch' for w in r['warnings']))
 def test_duplicate_and_conflicting_metrics_are_retained_not_summed(self):
  r=normalize([document('Total users\n50',identifier='a'),document('Total users\n55',identifier='b')])
  self.assertEqual(2,r['counts']['report_metrics']);self.assertTrue(any(w['code']=='conflicting_metric' for w in r['warnings']))
 def test_wrapped_activity_url_conflict_retains_both_source_representations(self):
  items=[content('ARTICLE SUBMISSION\n1\nhttps://example.test/long-\npath0'),content('https://example.test/long-path',identifier='link',kind='url',data={'kind':'hyperlink'})]
  r=normalize([document(items=items)]);row=next(x for x in r['records'] if x['kind']=='seo_activities')
  self.assertEqual('https://example.test/long-path',row['data']['url']);self.assertIn('path0',row['raw']);self.assertFalse(row['data']['live_link_verified']);self.assertTrue(any(w['code']=='url_source_disagreement' for w in r['warnings']))
 def test_date_mismatch_partial_ocr_and_unclassified_data_remain_visible(self):
  d=document('Total users\n12\n1 Aug 2026 - 30 Aug 2026\nCustom content');d['status']='partially_extracted';d['content'].append(content('uncertain','ocr','ocr_text',confidence='ocr'))
  r=normalize([d]);codes={w['code'] for w in r['warnings']};self.assertTrue({'partial_period_coverage','partial_extraction','uncertain_ocr'}<=codes);self.assertGreater(r['counts']['unclassified'],0)
 def test_ai_evidence_has_no_invented_ranking_or_brand_appearance(self):
  a={'id':'asset-a','type':'image','source':{'page':2,'block':1},'metadata':{'associated_heading':'Query from another client'}}
  r=normalize([document(items=[content('AI VISIBILITY\nQuery from another client',source={'page':2})],assets=[a])])
  row=next(x for x in r['records'] if x['kind']=='ai_visibility_evidence')
  self.assertEqual('unreviewed',row['data']['brand_appearance']);self.assertIsNone(row['data']['platform']);self.assertNotIn('ranking',row['data'])
 def test_technical_and_backlink_classification_is_content_based(self):
  self.assertEqual('technical_audit',classify('Technical SEO audit robots.txt crawl errors',[])[0]['type'])
  r=normalize([document('Backlink report\nhttps://another.test/listed-link')]);self.assertEqual(1,r['counts']['seo_activities'])
 def test_real_uploaded_manifest_values_and_idempotent_keys_when_available(self):
  path=Path(__file__).resolve().parents[2]/'.tools'/'stage4-manifest.json'
  if not path.exists():self.skipTest('Local real-upload manifest not available')
  m=json.loads(path.read_text());a=Normalizer(m).run();b=Normalizer(m).run()
  self.assertEqual(a,b);self.assertEqual(70,a['counts']['keyword_rankings']);self.assertEqual(95,a['counts']['seo_activities']);self.assertEqual(20,a['counts']['landing_page_metrics']);self.assertEqual(5,a['counts']['event_metrics']);self.assertEqual(9,a['counts']['ai_visibility_evidence'])
  values={r['data']['metric_key']:r['data']['value'] for r in a['records'] if r['kind']=='report_metrics'}
  for k,v in {'ga4.total_users':4515,'ga4.new_users':4383,'ga4.views':6257,'ga4.average_session_duration':83,'ga4.organic_search_users':2871,'gsc.clicks':2950,'gsc.impressions':432736,'gsc.ctr':.68,'events.reported_total':97}.items():self.assertEqual(v,values[k])


class AdditionalLayoutTests(unittest.TestCase):
 def test_zero_values_in_structured_spreadsheets_are_preserved(self):
  c=content("GA4",kind='table',data={'rows':[['Channel','Total users','New users'],['Custom channel',0,0]]})
  r=normalize([document(items=[c])])
  row=next(x for x in r['records'] if x['kind']=='traffic_channels')
  self.assertEqual(0,row['data']['total_users']);self.assertEqual(0,row['data']['new_users'])
 def test_explicit_keyword_date_and_location_with_reordered_columns(self):
  c=content("Keyword Ranking",kind='table',data={'rows':[['Location','Keyword','Date','Position'],['London','Custom Keyword','2026-09-30',12]]})
  r=normalize([document(items=[c])]);row=next(x for x in r['records'] if x['kind']=='keyword_rankings')
  self.assertEqual('London',row['data']['search_location']);self.assertEqual('2026-09-30',row['data']['ranking_date']);self.assertEqual(12,row['data']['exact_position']);self.assertIsNone(row['data']['source_serial'])

class MonthlyEnhancementTests(unittest.TestCase):
 def test_explicit_iso_coverage_preserved_and_mismatched_period_warned(self):
  r=normalize([document('GA4\nTotal users\n15\n2026-08-01 to 2026-08-30')])
  self.assertEqual([{'start':'2026-08-01','end':'2026-08-30'}],r['classifications'][0]['source_dates'])
  self.assertTrue(any(w['code']=='partial_period_coverage' for w in r['warnings']))
 def test_structured_metric_and_activity_metadata_preserve_duplicate_rows_and_zero(self):
  tables=[content('',identifier='metric',kind='table',data={'rows':[['Metric','Value'],['Total users',0],['Clicks',12],['Impressions',300],['CTR','4%']]}),content('',identifier='work',kind='table',data={'rows':[['Category','URL','Description','Status','Date'],['profile','https://synthetic.test','Retained description','Submitted','2026-08-05'],['profile','https://synthetic.test','Retained description','Submitted','2026-08-05']]})]
  r=normalize([document(items=tables)]);metrics={v['data']['metric_key']:v['data']['value'] for v in r['records'] if v['kind']=='report_metrics'}
  self.assertEqual(0,metrics['ga4.total_users']);self.assertEqual(4,metrics['gsc.ctr']);self.assertEqual(2,r['counts']['seo_activities'])
  work=[v for v in r['records'] if v['kind']=='seo_activities'];self.assertEqual('2026-08-05',work[0]['data']['activity_date']);self.assertEqual('Submitted',work[0]['data']['status']);self.assertFalse(work[0]['data']['live_link_verified'])
 def test_event_spreadsheet_total_is_source_value_not_inferred_sum(self):
  r=normalize([document(items=[content('',kind='table',data={'rows':[['Event','Count'],['custom_tap',3],['Grand total',9]]})])])
  self.assertEqual(1,r['counts']['event_metrics']);self.assertEqual(9,next(v['data']['value'] for v in r['records'] if v['kind']=='report_metrics'))
  self.assertTrue(any(w['code']=='event_total_mismatch' for w in r['warnings']))

class MetricDefinitionSafetyTests(unittest.TestCase):
 def test_active_and_unspecified_users_are_not_relabeled_as_total_users(self):
  r=normalize([document('GA4\nActive users\n7\nUsers\n9')]);data={v['data']['metric_key']:v['data']['value'] for v in r['records'] if v['kind']=='report_metrics'}
  self.assertEqual(7,data['ga4.active_users']);self.assertEqual(9,data['ga4.users']);self.assertNotIn('ga4.total_users',data)
 def test_ambiguous_click_metric_table_is_not_assigned_to_search_console(self):
  r=normalize([document(items=[content('',kind='table',data={'rows':[['Metric','Value'],['Clicks',8]]})])]);self.assertEqual(0,r['counts']['report_metrics'])

class EvidencePeriodTests(unittest.TestCase):
 def test_sept_spelling_keeps_explicit_cross_month_range_and_warning(self):
  result=Normalizer({'report':{'id':'skyhub','start_date':'2026-09-01','end_date':'2026-09-30'},'documents':[document('Google Analytics 4\n29 Aug 2026 - 27 Sept 2026\nTotal users\n100')]}).run()
  self.assertEqual([{'start':'2026-08-29','end':'2026-09-27'}],result['classifications'][0]['source_dates'])
  self.assertTrue(any(w['code']=='date_range_mismatch' for w in result['warnings']))
 def test_engagement_metrics_keep_native_percent_duration_and_count_units(self):
  result=normalize([document('Google Analytics 4\nEngaged sessions\n120\nEngagement rate\n51.2%\nBounce rate\n48.8%\nAverage engagement time\n00:01:23')])
  metrics={r['data']['metric_key']:r['data'] for r in result['records'] if r['kind']=='report_metrics'}
  self.assertEqual(120,metrics['ga4.engaged_sessions']['value'])
  self.assertEqual(51.2,metrics['ga4.engagement_rate']['value'])
  self.assertEqual('percent',metrics['ga4.bounce_rate']['unit'])
  self.assertEqual(83,metrics['ga4.average_engagement_time']['value'])

class SimplifiedTableTests(unittest.TestCase):
 def test_search_query_table_preserves_decimal_positions_and_source_refs(self):
  result=normalize([document(items=[content('',kind='table',data={'rows':[['Query','Clicks','Impressions','CTR','Position'],['synthetic pharmacy',12,300,'4%',2.5]]})])])
  row=next(r for r in result['records'] if r['kind']=='query_metrics')
  self.assertEqual('synthetic pharmacy',row['data']['query']);self.assertEqual(2.5,row['data']['position']);self.assertEqual(4,row['data']['ctr']);self.assertEqual(1,row['sources'][0]['source']['row'])
 def test_structured_sessions_and_engagement_keep_their_units(self):
  result=normalize([document('Google Analytics',items=[content('Google Analytics',kind='heading'),content('',identifier='metrics',kind='table',data={'rows':[['Metric','Value'],['Sessions',42],['Engagement rate','51.2%']]})])])
  metrics={r['data']['metric_key']:r['data'] for r in result['records'] if r['kind']=='report_metrics'}
  self.assertEqual(42,metrics['ga4.sessions']['value']);self.assertEqual('percent',metrics['ga4.engagement_rate']['unit'])

class SourcePeriodContextTests(unittest.TestCase):
 def test_metadata_chart_and_activity_ranges_do_not_become_report_periods(self):
  r=normalize([document('Google Analytics\nReporting period: 1 Aug 2026 - 31 Aug 2026\nCreated: 1 Jul 2026 - 31 Jul 2026\nChart dates\n1 Sep 2026 - 30 Sep 2026\nActivity date: 1 Jun 2026 - 30 Jun 2026')])
  self.assertEqual([{'start':'2026-08-01','end':'2026-08-31'}],r['classifications'][0]['source_dates']);self.assertFalse(any(w['code']=='date_range_mismatch' for w in r['warnings']))
 def test_ocr_dates_are_candidates_not_confirmed_mismatches(self):
  r=normalize([document(items=[content('1Aug 2026-30Aug 2026',kind='ocr_text',confidence='ocr')])])
  self.assertEqual([{'start':'2026-08-01','end':'2026-08-30'}],r['classifications'][0]['source_dates']);self.assertTrue(any(w['code']=='uncertain_period' for w in r['warnings']));self.assertFalse(any(w['code']=='date_range_mismatch' for w in r['warnings']))

class MergedKeywordGeometryTests(unittest.TestCase):
 def test_merged_header_indexes_follow_geometry_and_continuation_pages(self):
  # Header text is centered in narrow subcells; data spans their merged bands.
  headers=['','SL. NO.','','','KEYWORDS','','','RANKING','']
  boxes=[[0,0,2,10],[2,0,8,10],[8,0,10,10],[10,0,12,10],[12,0,28,10],[28,0,30,10],[30,0,32,10],[32,0,48,10],[48,0,50,10]]
  data_boxes=[[0,10,10,20],None,None,[10,10,30,20],None,None,[30,10,50,20],None,None]
  a=content('',identifier='table-a',kind='table',data={'rows':[headers,['1',None,None,'Primary term',None,None,'1st Page',None,None],['','EXTRA KEYWORDS',None,None,None,None,None,None,''],['1',None,None,'Additional term',None,None,'2nd Page',None,None]],'cell_bboxes':[boxes,data_boxes,data_boxes,data_boxes]})
  b=content('',identifier='table-b',kind='table',data={'rows':[['2','Continued term','3rd Page']],'cell_bboxes':[[[0,0,10,10],[10,0,30,10],[30,0,50,10]]]})
  result=normalize([document(items=[a,b])]);rows=[r for r in result['records'] if r['kind']=='keyword_rankings'];self.assertEqual(3,len(rows));self.assertEqual(['primary','additional','additional'],[r['data']['category'] for r in rows]);self.assertEqual('Continued term',rows[2]['data']['keyword']);self.assertIsNone(rows[0]['data']['exact_position'])
 def test_empty_detected_keyword_source_has_meaningful_processing_error(self):
  result=normalize([document('KEYWORDS\nRANKING\n1st Page')]);self.assertTrue(any(w['code']=='keyword_extraction_empty' for w in result['warnings']))

class MergedEmptyRankTests(unittest.TestCase):
 def test_keyword_spanning_empty_rank_band_is_not_used_as_its_own_ranking(self):
  a=content('',identifier='table-a',kind='table',data={'rows':[['No','Keywords','Ranking'],['1','Main phrase',None],['','Other source wording','2nd Page']], 'cell_bboxes':[[[0,0,10,10],[10,0,30,10],[30,0,50,10]],[[0,10,10,20],[10,10,50,20],None],[[0,20,10,30],[10,20,30,30],[30,20,50,30]]]})
  rows=[r for r in normalize([document(items=[a])])['records'] if r['kind']=='keyword_rankings'];self.assertEqual(1,len(rows));self.assertEqual('2nd Page',rows[0]['data']['ranking_label']);self.assertEqual(['Other source wording'],rows[0]['data']['alternative_labels']);self.assertEqual('inferred',rows[0]['confidence'])

class NativeHeaderOrderTests(unittest.TestCase):
 def test_landing_columns_follow_impressions_before_clicks(self):
  r=normalize([document('Google Search Console\nLanding Page\nImpressions\nUrl Clicks\nURL CTR\nhttps://different.test/\n7,000\n210\n3%')]);row=next(x for x in r['records'] if x['kind']=='landing_page_metrics');self.assertEqual(210,row['data']['clicks']);self.assertEqual(7000,row['data']['impressions']);self.assertEqual(3,row['data']['ctr'])
 def test_month_first_date_selector_is_a_confirmed_mismatch(self):
  r=normalize([document('Reporting period: Jul 29, 2026 - Aug 27, 2026 arrow_drop_down\nTotal users\n11')]);self.assertTrue(any(w['code']=='date_range_mismatch' for w in r['warnings']))

class CompactMonthFirstOcrTests(unittest.TestCase):
 def test_ocr_month_first_commas_without_spaces_keep_uncertain_period(self):
  r=normalize([document(items=[content('Jul 29,2026-Aug 27,2026\nEventname\nEventcount\n1.\nfile_download\n7\nGrand total\n7',kind='ocr_text',confidence='ocr')])]);self.assertTrue(any(w['code']=='uncertain_period' for w in r['warnings']));self.assertFalse(any(w['code']=='date_range_mismatch' for w in r['warnings']))
