import sqlite3,json,pathlib,hashlib,collections
b=sqlite3.connect('local/review/base44-baseline.sqlite');a=sqlite3.connect('backend/database/database.sqlite');b.row_factory=sqlite3.Row;a.row_factory=sqlite3.Row
report='01m5h5pm1xacszarrdcgxwbtnp';checks={}
for table in ['clients','projects','report_documents','document_extracted_content','document_assets','record_verifications']:
 checks[table]=[dict(r) for r in b.execute('select * from '+table+' order by id')]==[dict(r) for r in a.execute('select * from '+table+' order by id')]
checks['other_reports']=[dict(r) for r in b.execute('select * from reporting_periods where id!=? order by id',(report,))]==[dict(r) for r in a.execute('select * from reporting_periods where id!=? order by id',(report,))]
checks['other_report_normalized_values']=[dict(r) for r in b.execute('select * from normalized_records where reporting_period_id!=? order by id',(report,))]==[dict(r) for r in a.execute('select * from normalized_records where reporting_period_id!=? order by id',(report,))]
checks['existing_publications_immutable']=all(dict(a.execute('select * from report_publications where id=?',(r['id'],)).fetchone())==dict(r) for r in b.execute('select * from report_publications'))
docs=list(a.execute('select * from report_documents'));checks['original_checksums']=all(hashlib.sha256((pathlib.Path('backend/storage/app/private')/r['storage_path']).read_bytes()).hexdigest()==r['checksum'] for r in docs)
changed=collections.Counter()
for r in b.execute('select * from normalized_records where reporting_period_id=?',(report,)):
 new=a.execute('select * from normalized_records where id=?',(r['id'],)).fetchone();assert new is not None
 if r['source_data']!=new['source_data']:changed[r['kind']]+=1
checks['only_documented_landing_column_corrections']=dict(changed)=={'landing_page_metrics':20}
checks['existing_manual_overrides_preserved']=all(r['manual_override']==a.execute('select manual_override from normalized_records where id=?',(r['id'],)).fetchone()[0] for r in b.execute('select * from normalized_records'))
result={'checks':checks,'original_count':len(docs),'source_data_corrections':dict(changed),'active_keywords':a.execute("select count(*) from normalized_records where reporting_period_id=? and active=1 and kind='keyword_rankings'",(report,)).fetchone()[0]};pathlib.Path('local/review/base44-preservation.json').write_text(json.dumps(result,indent=2),encoding='utf-8');print(json.dumps(result,indent=2));assert all(checks.values())
