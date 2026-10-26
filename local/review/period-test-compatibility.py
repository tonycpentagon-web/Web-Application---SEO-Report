from pathlib import Path
p=Path('backend/app/Services/EvidenceValidation.php');s=p.read_text(encoding='utf-8').replace("if (! $info['period']['known']) {", "if (! $info['period']['known'] && !($info['period']['scope_confirmed'] ?? false)) {");p.write_text(s,encoding='utf-8')
# Include chart-axis context without using uploaded/file-creation metadata to establish coverage.
p=Path('backend/app/Services/SourceReportingPeriod.php');s=p.read_text(encoding='utf-8').replace("chart (?:date|range|axis)|activity date", "chart(?: date| range| axis)?|x.axis|daily trend|activity date");p.write_text(s,encoding='utf-8')
p=Path('document-processor/normalization.py');s=p.read_text(encoding='utf-8').replace("chart (?:date|range|axis)|activity date", "chart(?: date| range| axis)?|x.axis|daily trend|activity date");p.write_text(s,encoding='utf-8')
# Existing integration helpers now explicitly exercise source assignment as part of sharing when required.
p=Path('backend/tests/Feature/SimplifiedWorkflowTest.php');s=p.read_text(encoding='utf-8').replace("'acknowledge_uncertainty'=>true])->assertCreated()", "'acknowledge_uncertainty'=>true,'confirm_source_periods'=>true])->assertCreated()");p.write_text(s,encoding='utf-8')
