import json,pymupdf,sqlite3,hashlib
from pathlib import Path
pdf=pymupdf.open('local/review/hanns-published-period-fixed.pdf');text='\n'.join(p.get_text() for p in pdf)
pdfInfo={'pages':len(pdf),'has_partial_coverage_notice':'Partial or uncertain source coverage' in text,'has_missing_31_august':'2026-08-31' in text,'has_printed_end_date':'2026-08-30' in text,'has_provisional_labels':'Provisional' in text};print(json.dumps(pdfInfo));Path('local/review/hanns-pdf-verification.json').write_text(json.dumps(pdfInfo,indent=2),encoding='utf-8')
a=sqlite3.connect('local/review/hanns-period-before.sqlite');b=sqlite3.connect('backend/database/database.sqlite');target='01m5cc50zfzk56dwfc9ft9erb6'
# Record all original-source identities, extraction IDs, paths and checksums, and check the actual original bytes.
cols='id,reporting_period_id,storage_path,checksum,current_run_id,deleted_at'
before=a.execute('select '+cols+' from report_documents order by id').fetchall();after=b.execute('select '+cols+' from report_documents order by id').fetchall()
originals=[{'id':r[0],'intact':hashlib.sha256((Path('backend/storage/app/private')/r[2]).read_bytes()).hexdigest()==r[3]} for r in before]
unchangedParents=all(a.execute('select * from '+t+' order by id').fetchall()==b.execute('select * from '+t+' order by id').fetchall() for t in ['clients','projects'])
otherReports=a.execute('select * from reporting_periods where id!=? order by id',(target,)).fetchall()==b.execute('select * from reporting_periods where id!=? order by id',(target,)).fetchall()
# Original source values, overrides, review decisions and human-verification history must remain intact.
recordCols='id,source_data,source_hash,manual_override,review_status,reviewed_by,reviewed_at'
recordPreserved=a.execute('select '+recordCols+' from normalized_records where reporting_period_id=? order by id',(target,)).fetchall()==b.execute('select '+recordCols+' from normalized_records where reporting_period_id=? order by id',(target,)).fetchall()
verifications=a.execute('select * from record_verifications order by id').fetchall()==b.execute('select * from record_verifications order by id').fetchall()
result={'same_document_rows_and_source_ids':before==after,'original_files_intact':all(r['intact'] for r in originals),'documents':len(originals),'clients_projects_unchanged':unchangedParents,'unrelated_months_unchanged':otherReports,'source_values_overrides_and_review_decisions_preserved':recordPreserved,'human_verification_history_unchanged':verifications};print(json.dumps(result));Path('local/review/hanns-preservation-final.json').write_text(json.dumps(result,indent=2),encoding='utf-8')
