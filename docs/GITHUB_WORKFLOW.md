# GitHub workflow

No Git repository exists in this checkout; no repository was initialized, staged, committed or pushed. A Git history audit is therefore Not verified. Do not assume a clean source scan proves prior history safe.

Prepare a private repository only under later explicit authorization. Before staging, review .gitignore and run python ops/package_release.py. The source allowlist excludes original/reference documents, private storage, databases/dumps, backups, exports, archives, .env, logs, credentials, vendor/node_modules and local tools. Keep only sanitized env examples; every VITE variable is public. Review staged paths with git diff --cached --name-only and the optional package scanner --check-staged (requires a real repository). Independently inspect content and use a dedicated secret scanner over full history before pushing. If a real secret is found, stop publication and coordinate remediation; do not silently rotate this project's credentials.

Suggested flow: protected main, short feature branch, reviewed PR describing behavior/data/security effects and checks, required backend/React/TypeScript/Python tests and production build. CI uses a new isolated test database, synthetic clients/files and generated test-only key; never production secrets, original documents or the live SQLite database. MySQL integration needs an explicitly disposable empty pentagon_test_* schema and opt-in; test retains it for operator inspection.

Artifacts: build source ZIP via python ops/package_release.py --output <ignored-private-output.zip>. Destination must be new; inspect SOURCE_MANIFEST.json and hashes. Build compiled assets separately in CI/staging. Never attach private PDF smoke-test artifacts to public CI or a public PR. No GitHub workflow has been activated or uploaded by this stage.

For a future authorized deployment, do not git pull inside a serving tree with writable runtime data. Fetch and inspect the reviewed commit in a clean staging checkout, create a new release, then build/test there. Example preparation commands (operator-run only):

```sh
git fetch --prune origin
git status --short
git log --oneline HEAD..origin/main
# In a clean deployment staging checkout after review:
git pull --ff-only origin main
git rev-parse HEAD
(cd backend && composer install --no-dev --prefer-dist --no-interaction --optimize-autoloader)
(cd frontend && npm ci && npm run build)
(cd backend/pdf-renderer && npm ci --omit=dev && npx playwright install --with-deps chromium)
python3 -m venv /srv/pentagon/venv
/srv/pentagon/venv/bin/pip install -r document-processor/requirements.txt
```

Install the chosen OCR engine separately and verify service permissions. Supply private environment/persistent storage, back up, pass preflight, then run the reviewed additive migration and cache sequence in PRODUCTION_DEPLOYMENT.md. Commit selection, fast-forward-only pull, deployment promotion and worker restart require an approved future deployment window. Retain the previous release commit and use ROLLBACK_PROCEDURE.md; never git reset runtime evidence or restore an old environment key over the established key.
