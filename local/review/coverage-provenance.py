from pathlib import Path
p=Path('backend/app/Services/SourceReportingPeriod.php');s=p.read_text(encoding='utf-8');needle="        return ['status'=>$status,"
insert='''        $missing=[];
        if ($observedRanges && !$outside) {
            for ($day=$p->start_date->copy();$day->lte($p->end_date);$day->addDay()) {
                $iso=$day->format('Y-m-d');
                if (!collect($observedRanges)->contains(fn($r)=>$r['start']<=$iso&&$r['end']>=$iso)) $missing[]=$iso;
            }
        }
''';assert needle in s;s=s.replace(needle,insert+needle).replace("'ranges'=>$observedRanges,'evidence'", "'uncovered_dates'=>$missing,'ranges'=>$observedRanges,'evidence'");p.write_text(s,encoding='utf-8')
p=Path('backend/app/Services/EvidenceValidation.php');s=p.read_text(encoding='utf-8');s=s.replace("['partial_extraction', 'uncertain_ocr', 'duplicate_metric', 'unclassified_content', 'ranking_context_missing']", "['date_range_mismatch', 'partial_period_coverage', 'uncertain_period', 'partial_extraction', 'uncertain_ocr', 'duplicate_metric', 'unclassified_content', 'ranking_context_missing']");p.write_text(s,encoding='utf-8')
p=Path('backend/app/Services/SimplifiedReportSharing.php');s=p.read_text(encoding='utf-8');s=s.replace("                $warnings[] = ['section' => 'sources', 'message' => $warning->message];", "                $message = match($warning->code) {\n                    'unclassified_content' => 'Additional source context is preserved for inspection; only supported observations are charted.',\n                    'uncertain_ocr' => 'OCR-derived observations remain provisional and may contain recognition errors.',\n                    default => $warning->message,\n                };\n                $warnings[] = ['section' => 'sources', 'message' => $message];")
s=s.replace("$rangeText.'. ' : '').$issue['message']", "$rangeText.'. ' : '').(!empty($issue['source_period']['uncovered_dates']) ? 'Dates not covered by this printed range: '.implode(', ', $issue['source_period']['uncovered_dates']).'. ' : '').$issue['message']")
p.write_text(s,encoding='utf-8')
p=Path('backend/app/Services/ReportDashboard.php');s=p.read_text(encoding='utf-8').replace("return ['mode' => 'internal_draft_preview',", "return ['source_periods' => $validation && $validation['current'] ? ($validation['summary']['source_periods'] ?? []) : [], 'mode' => 'internal_draft_preview',");p.write_text(s,encoding='utf-8')
p=Path('document-processor/normalization.py');s=p.read_text(encoding='utf-8');a=s.index(' def dates(');b=s.index(' def scalar_metrics(',a)
s=s[:a]+''' def dates(self,doc,cs):
    # Do not promote metadata, activity/chart dates or arbitrary narrative dates into report coverage.
    atom=r"(?:\\d{1,2}\\s*[A-Za-z]{3,9}\\s*20\\d{2}|20\\d{2}-\\d{2}-\\d{2})"
    pattern=re.compile(r"(?P<start>"+atom+r")\\s*(?:to|[-\\u2013\\u2014])\\s*(?P<end>"+atom+r")",re.I)
    months={n.lower():i for i in range(1,13) for n in (calendar.month_name[i],calendar.month_abbr[i])};months["sept"]=9
    def parse(raw):
      if re.fullmatch(r"20\\d{2}-\\d{2}-\\d{2}",raw):return date.fromisoformat(raw).isoformat()
      m=re.fullmatch(r"(\\d{1,2})\\s*([A-Za-z]{3,9})\\s*(20\\d{2})",raw)
      return date(int(m[3]),months[m[2].lower()],int(m[1])).isoformat()
    seen=set()
    for c in cs:
      if c['type'] not in ('text','worksheet_text','heading','paragraph','ocr_text'):continue
      lines=c.get('text','').replace('\\u00a0',' ').splitlines()
      for index,line in enumerate(lines):
        context=(lines[index-1]+' ' if index else '')+line
        if re.search(r"\\b(?:created|creation|uploaded|upload date|generated (?:on|at)|printed|downloaded|captured|screenshot (?:date|taken|timestamp)|timestamp|chart (?:date|range|axis)|activity date|task date|comparison|previous period|prior period)\\b",context,re.I):continue
        labelled=bool(re.search(r"report(?:ing)?\\s*(?:period|date|range)|date range|period covered|coverage",context,re.I))
        if not labelled and pattern.sub('',line).strip(' \\t:;,()[]'):continue
        for m in pattern.finditer(line):
          try:a=parse(m['start']);b=parse(m['end'])
          except (ValueError,KeyError,TypeError):continue
          if a>b or (a,b) in seen:continue
          seen.add((a,b))
          if a==self.period['start_date'] and b==self.period['end_date']:continue
          ocr=c['type']=='ocr_text' or c.get('confidence') in ('ocr','uncertain','low','inferred','ambiguous')
          outside=a<self.period['start_date'] or b>self.period['end_date']
          code='uncertain_period' if ocr else 'date_range_mismatch' if outside else 'partial_period_coverage'
          self.warn(code,f"Source reports {a} to {b}. Printed dates are retained; "+('OCR dates require explicit source assignment.' if ocr else 'coverage falls outside the selected report.' if outside else 'coverage is partial within the selected month.'),doc,{**c.get('source',{}),'line':index+1})
    return [{"start":a,"end":b} for a,b in sorted(seen)]
'''+s[b:];p.write_text(s,encoding='utf-8')
