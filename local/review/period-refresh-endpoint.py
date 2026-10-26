from pathlib import Path
p=Path('backend/app/Http/Controllers/SimplifiedSharingController.php');s=p.read_text(encoding='utf-8');s=s.replace("'status' => $p->status, 'assessment'", "'status' => $p->status, 'report' => ['title' => $p->title, 'client_name' => $p->project->client?->name ?? $p->project->name, 'month' => $p->start_date->format('F Y')], 'assessment'")
s=s.replace("'acknowledge_uncertainty' => 'sometimes|boolean',", "'acknowledge_uncertainty' => 'sometimes|boolean', 'confirm_source_periods' => 'sometimes|boolean',")
idx=s.index('    public function store(')
s=s[:idx]+'''    public function refresh(Request $r, string $project, string $report, DocumentAccess $access)
    {
        $p = $access->report($r->user(), $project, $report);
        abort_unless($access->workspace->workReports($r->user(), $p->project), 403);
        $v = $r->validate(['version' => 'required|integer|min:1', 'confirmed' => 'required|accepted']);
        return DB::transaction(function () use ($p, $r, $v) {
            $p = \\App\\Models\\ReportingPeriod::whereKey($p->id)->lockForUpdate()->firstOrFail();
            abort_unless($p->version === $v['version'], 409, 'The report changed. Reload before refreshing.');
            app(\\App\\Services\\DraftInvalidation::class)->assertIdle([$p->id]);
            $context = app(\\App\\Services\\RecordReview::class)->context($p, true);
            abort_unless($context['current'] && !$p->requires_regeneration, 409, 'Sources changed. Regenerate Report using the existing uploads.');
            app(\\App\\Services\\EvidenceValidation::class)->evaluate($p, $r->user());
            app(\\App\\Services\\ReportDelivery::class)->event($p, null, $r->user(), 'report.evidence_checks_refreshed', ['normalization_run_id' => $context['run']->id]);
            return response()->json(['message' => 'Evidence checks refreshed. The original source files and report values are unchanged.']);
        }, 5);
    }

'''+s[idx:];p.write_text(s,encoding='utf-8')
p=Path('backend/routes/api.php');s=p.read_text(encoding='utf-8');needle="    Route::get('/projects/{project}/reports/{report}/sharing'";idx=s.index(needle);s=s[:idx]+"    Route::post('/projects/{project}/reports/{report}/sharing/refresh', [\\App\\Http\\Controllers\\SimplifiedSharingController::class, 'refresh'])->middleware('throttle:exports');\n"+s[idx:];p.write_text(s,encoding='utf-8')
p=Path('backend/app/Jobs/GenerateMonthlyReport.php');s=p.read_text(encoding='utf-8');needle='            app(DraftCommentary::class)->generate($period, $user);'
assert needle in s;s=s.replace(needle,'''            $period = DB::transaction(function () use ($period, $run, $engine, $user) {
                $current = ReportingPeriod::whereKey($period->id)->lockForUpdate()->firstOrFail();
                abort_unless($engine->fingerprint($current, $engine->snapshot($current)) === $run->fingerprint, 409, 'Sources changed during generation. Regenerate using the current uploads.');
                $current->forceFill(['version' => $current->version + 1, 'updated_by' => $user->id])->save();
                return $current;
            }, 5);
'''+needle);p.write_text(s,encoding='utf-8')
