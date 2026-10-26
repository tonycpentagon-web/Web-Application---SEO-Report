from pathlib import Path
p=Path('backend/app/Services/SimplifiedReportSharing.php');s=p.read_text(encoding='utf-8').replace('app(EvidenceValidation::class)->evaluate($p, $user);', "$periods = app(EvidenceValidation::class)->evaluate($p, $user)['summary']['source_periods'];");p.write_text(s,encoding='utf-8')
