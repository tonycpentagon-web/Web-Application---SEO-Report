from pathlib import Path
p=Path('backend/app/Services/EvidenceValidation.php');s=p.read_text(encoding='utf-8');s=s.replace("/ADDRESSED\\s+TO\\s*:\\s*([^\\r\\n]+)/iu", "/ADDRESSED[ \\t]+TO[ \\t]*:[ \\t]*([^\\r\\n]+)/iu")
s=s.replace("(?:Client|Client name|Prepared for|Addressed to)\\s*:\\s*([^\\r\\n]+)","(?:Client|Client name|Prepared for|Addressed to)[ \\t]*:[ \\t]*([^\\r\\n]+)")
p.write_text(s,encoding='utf-8')
p=Path('local/review/trial-hanns-period.php');s=p.read_text(encoding='utf-8-sig');a=s.index('echo json_encode(');s=s[:a]+'''echo json_encode(['eligible'=>$a['eligible'],'blockers'=>$a['blockers'],'periods'=>collect($a['source_periods'])->map(fn($v)=>['document'=>$v['document'],'status'=>$v['status'],'ranges'=>$v['ranges']]),'warning_sections'=>collect($a['warnings'])->groupBy('section')->map->count(),'counts'=>$v['summary']['counts'],'invalid_issues'=>collect($v['records'])->where('status','INVALID')->pluck('issues')->flatten()->unique()->values()],JSON_PRETTY_PRINT);
''';p.write_text(s,encoding='utf-8')
