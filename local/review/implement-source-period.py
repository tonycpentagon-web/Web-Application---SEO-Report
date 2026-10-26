from pathlib import Path
p=Path('backend/app/Services/SourceReportingPeriod.php');p.write_text(p.read_text(encoding='utf-8-sig'),encoding='utf-8')
p=Path('backend/app/Services/EvidenceValidation.php');s=p.read_text(encoding='utf-8');s=s.replace("'evidence-validation/1.1'","'evidence-validation/1.2'")
a=s.index('    /** Native month titles');b=s.index('    private function semantics',a);s=s[:a]+s[b:]
a=s.index('                $period = $this->periodText');b=s.index('                $association = true;',a)
s=s[:a]+'''                $period = app(SourceReportingPeriod::class)->detect($content->where('document_id', $doc->id), $p);
                $scopeVerified = $scopeVerifications->contains(fn ($v) => $v->document_id === $doc->id && hash_equals($v->fingerprint, $scopes[$doc->id]));
                $period['scope_confirmed'] = $scopeVerified;
'''+s[b:]
a=s.index("                if ($period['known'] && ! $period['matches'])");b=s.index("                if (in_array($doc->extraction_status",a)
s=s[:a]+'''                if ($period['status'] === 'mismatch') {
                    $documentIssues[] = ['code' => 'date_range_mismatch', 'document_id' => $doc->id, 'message' => 'An explicit reporting-period header falls outside the selected month. Correct the source or report period; this cannot be waived.', 'blocking' => true, 'source_period' => $period];
                } elseif ($period['requires_confirmation']) {
                    $message = $period['status'] === 'partial'
                        ? 'Source covers only part of the selected month. Preserve the printed dates and explicitly confirm publication with incomplete coverage.'
                        : 'The reporting period is uncertain. Confirm source assignment without claiming the dates or OCR values are verified.';
                    $documentIssues[] = ['code' => $period['status'] === 'partial' ? 'partial_period_coverage' : 'period_not_reported', 'document_id' => $doc->id, 'message' => $message, 'blocking' => !$scopeVerified, 'requires_confirmation' => !$scopeVerified, 'source_period' => $period];
                }
'''+s[b:]
s=s.replace("} elseif (! $info['period']['matches']) {", "} elseif ($info['period']['status'] === 'mismatch') {")
s=s.replace("$hard[] = 'Source date range conflicts with the reporting period.';\n                    }", "$hard[] = 'Source date range conflicts with the reporting period.';\n                    } elseif ($info['period']['status'] === 'partial') {\n                        $issues[] = 'Partial-month source coverage; values do not include every day of the selected month.';\n                    }")
p.write_text(s,encoding='utf-8')
