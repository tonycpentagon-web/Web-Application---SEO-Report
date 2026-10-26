import sqlite3,json,pathlib,hashlib
before=sqlite3.connect('local/review/public-audit-baseline.sqlite');after=sqlite3.connect('backend/database/database.sqlite')
checks={}
tables=['clients','projects','report_documents','document_extracted_content','document_assets','normalized_records','metric_source_references','record_corrections','record_verifications','report_summary_revisions','report_source_scope_verifications']
for table in tables:
 try:checks[table]=before.execute('select * from '+table+' order by id').fetchall()==after.execute('select * from '+table+' order by id').fetchall()
 except sqlite3.OperationalError:pass
report='01m5cc50zfzk56dwfc9ft9erb6'
checks['other_reports']=before.execute('select * from reporting_periods where id!=? order by id',(report,)).fetchall()==after.execute('select * from reporting_periods where id!=? order by id',(report,)).fetchall()
checks['existing_publications']=all(after.execute('select * from report_publications where id=?',(row[0],)).fetchone()==row for row in before.execute('select * from report_publications'))
after.row_factory=sqlite3.Row;docs=list(after.execute('select * from report_documents'));checks['all_original_files_match_checksums']=all(hashlib.sha256((pathlib.Path('backend/storage/app/private')/r['storage_path']).read_bytes()).hexdigest()==r['checksum'] for r in docs)
checks['original_document_count']=len(docs);pathlib.Path('local/review/public-preservation.json').write_text(json.dumps(checks,indent=2),encoding='utf-8');print(json.dumps(checks,indent=2));assert all(v is True for k,v in checks.items() if k!='original_document_count')
