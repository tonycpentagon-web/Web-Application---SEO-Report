from pathlib import Path
p=Path('backend/app/Services/EvidenceValidation.php');s=p.read_text(encoding='utf-8');needle="$summary = ['counts' => $counts,"
assert needle in s;s=s.replace(needle,"$summary = ['source_periods' => collect($docInfo)->map(fn ($info, $id) => ['document_id' => $id, 'document' => $info['filename'], ...$info['period']])->values()->all(), 'counts' => $counts,")
p.write_text(s,encoding='utf-8')
p=Path('backend/app/Services/SimplifiedReportSharing.php');s=p.read_text(encoding='utf-8')
a=s.index('        if (! $context[\'current\']');b=s.index("        if ($p->project->status",a)
s=s[:a]+'''        $needsGeneration = !$context['current'] || $p->requires_regeneration;
        $needsValidation = !$needsGeneration && (!$validation || !$validation['current']);
        if ($needsGeneration) {
            $blockers[] = 'Source files, extraction or reporting dates changed since the last completed generation. Regenerate Report using the existing uploads.';
        } elseif ($needsValidation) {
            $blockers[] = 'Generation matches the current source files. Evidence checks need refreshing because validation rules or evidence context changed.';
        }
        // Do not present a previous ruleset's stale mismatch decisions as current facts.
        $currentValidation = $validation && $validation['current'] ? $validation : null;
        $periods = $currentValidation['summary']['source_periods'] ?? [];
        $confirmations = array_values(array_filter($periods, fn ($period) => $period['requires_confirmation'] && !($period['scope_confirmed'] ?? false)));
        foreach ($periods as $period) {
            if ($period['candidate_outside_period'] ?? false) {
                $blockers[] = $period['document'].': OCR suggests dates outside the selected month. Inspect and correct the source date evidence before assigning it to this month.';
            }
        }
'''+s[b:]
s=s.replace("foreach ($validation['records'] ?? []", "foreach ($currentValidation['records'] ?? []").replace("foreach ($validation['summary']['document_issues'] ?? []", "foreach ($currentValidation['summary']['document_issues'] ?? []")
s=s.replace("                $warnings[] = ['section' => 'sources', 'document' => $issue['document_name'] ?? 'Source', 'message' => $issue['message']];", "                $rangeText = collect($issue['source_period']['ranges'] ?? [])->map(fn ($v) => $v['start'].' to '.$v['end'])->implode('; ');\n                $warnings[] = ['section' => 'sources', 'document' => $issue['document_name'] ?? 'Source', 'message' => ($rangeText ? 'Printed source coverage: '.$rangeText.'. ' : '').$issue['message']];")
s=s.replace("if (! $warning->normalized_record_id) {", "if (! $needsGeneration && ! $warning->normalized_record_id && $warning->code !== 'date_range_mismatch') {")
s=s.replace("return ['eligible' => ! $blockers,", "return ['requires_regeneration' => (bool)$needsGeneration, 'requires_validation_refresh' => (bool)$needsValidation, 'source_periods' => $periods, 'period_confirmations' => $confirmations, 'eligible' => ! $blockers,")
s=s.replace("$validation['fingerprint'] ?? null, $summary?->id, $blockers, $warnings", "$validation['fingerprint'] ?? null, $summary?->id, $blockers, $warnings, $periods")
needle="                abort_if($assessment['requires_acknowledgment']"
idx=s.index(needle)
s=s[:idx]+'''                abort_if($assessment['period_confirmations'] && !($v['confirm_source_periods'] ?? false), 422, 'Explicitly confirm the listed uncertain or partial source periods before sharing. Printed ranges will remain disclosed.');
                $periods = $assessment['source_periods'];
                if ($assessment['period_confirmations']) {
                    $input = app(EvidenceValidation::class)->input($p);
                    foreach ($assessment['period_confirmations'] as $period) {
                        DB::table('report_source_scope_verifications')->insert(['id' => (string) Str::ulid(), 'reporting_period_id' => $p->id, 'document_id' => $period['document_id'], 'actor_id' => $user->id, 'fingerprint' => $input['scopes'][$period['document_id']], 'reason' => 'Employee explicitly assigned this source to '.$p->start_date->format('F Y').' with disclosed '.$period['status'].' coverage; printed dates remain '.json_encode($period['ranges']).'. This does not verify OCR values or supply missing days.', 'created_at' => now()]);
                    }
                    $delivery->event($p, null, $user, 'report.source_periods_confirmed', ['assessment_fingerprint' => $assessment['fingerprint'], 'documents' => $assessment['period_confirmations']]);
                    app(EvidenceValidation::class)->evaluate($p, $user);
                }
'''+s[idx:]
s=s.replace("$snapshot['schema'] = 2;", "$snapshot['schema'] = 2;\n                $snapshot['source_periods'] = $periods;\n                $snapshot['source_period_confirmation'] = ['confirmed' => (bool)($v['confirm_source_periods'] ?? false), 'confirmed_at' => now()->toIso8601String()];")
# The uncertainty acknowledgment must be checked before adding any audit history (transaction rolls back on rejection).
p.write_text(s,encoding='utf-8')
