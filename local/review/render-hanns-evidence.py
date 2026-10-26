import sqlite3,json,pymupdf
from pathlib import Path
c=sqlite3.connect('backend/database/database.sqlite')
for identifier in ['01M5CC6TKR09ADWFSYBSYY8QWJ','01M5CC6VS3F7N5Y3PWS9W9YNWK']:
 path=c.execute('select storage_path from report_documents where id=?',(identifier,)).fetchone()[0]
 full=Path('backend/storage/app/private')/path
 print(identifier,full.exists(),str(full))
 if full.suffix=='.pdf':
  d=pymupdf.open(full);d[1].get_pixmap(matrix=pymupdf.Matrix(1.2,1.2)).save('local/review/hanns-analytics-page-2.png')
