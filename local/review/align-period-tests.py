from pathlib import Path
p=Path('backend/tests/Unit/SourceReportingPeriodTest.php');s=p.read_text(encoding='utf-8').replace('use PHPUnit\\Framework\\TestCase;','use Tests\\TestCase;');p.write_text(s,encoding='utf-8')
p=Path('document-processor/tests/test_normalization.py');s=p.read_text(encoding='utf-8').replace("{'date_range_mismatch','partial_extraction','uncertain_ocr'}", "{'partial_period_coverage','partial_extraction','uncertain_ocr'}").replace("self.assertTrue(any(w['code']=='date_range_mismatch' for w in r['warnings']))", "self.assertTrue(any(w['code']=='partial_period_coverage' for w in r['warnings']))",1)
s+='''
class SourcePeriodContextTests(unittest.TestCase):
 def test_metadata_chart_and_activity_ranges_do_not_become_report_periods(self):
  r=normalize([document('Google Analytics\\nReporting period: 1 Aug 2026 - 31 Aug 2026\\nCreated: 1 Jul 2026 - 31 Jul 2026\\nChart dates\\n1 Sep 2026 - 30 Sep 2026\\nActivity date: 1 Jun 2026 - 30 Jun 2026')])
  self.assertEqual([{'start':'2026-08-01','end':'2026-08-31'}],r['classifications'][0]['source_dates']);self.assertFalse(any(w['code']=='date_range_mismatch' for w in r['warnings']))
 def test_ocr_dates_are_candidates_not_confirmed_mismatches(self):
  r=normalize([document(items=[content('1Aug 2026-30Aug 2026',kind='ocr_text',confidence='ocr')])])
  self.assertEqual([{'start':'2026-08-01','end':'2026-08-30'}],r['classifications'][0]['source_dates']);self.assertTrue(any(w['code']=='uncertain_period' for w in r['warnings']));self.assertFalse(any(w['code']=='date_range_mismatch' for w in r['warnings']))
''';p.write_text(s,encoding='utf-8')
