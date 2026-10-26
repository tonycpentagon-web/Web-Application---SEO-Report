Latest: [Stage 5 final approval/verification/validation review — 91 Laravel tests, 60 React tests and preserved live records](STAGE-5-FINAL-REVIEW.md).

Latest: [Stage 5 UI refinement — 89 Laravel tests, 57 React tests, 23 Edge checks and preservation verification](STAGE-5-UI-REFINEMENT.md).

Current: [Stage 5 internal Draft Preview — tests, live projection and preservation audit](STAGE-5-DRAFT-PREVIEW.md). Earlier stage records follow.

Latest: [Stage 4 bulk-review enhancement — 82 Laravel tests, 44 React tests, build and existing approval preservation](STAGE-4-BULK-REVIEW.md).

Current: [Stage 4 live normalization, preservation and test results](STAGE-4-VERIFICATION.md). [Stage 4 workflow and commands](STAGE-4.md). The records below describe earlier stages and their database state at those times.

Latest: [Stage 3 extraction repair and live-file results](STAGE-3-EXTRACTION-REPAIR.md). Earlier verification below is historical.

# Current verification

See [Stage 3 verification](STAGE-3-VERIFICATION.md) for document ingestion, real queued extraction/OCR, data preservation and current test results. Earlier stage records below are historical.

# Latest verification — Stage 2

Validated on 16 October 2026 in the existing workspace.

| Check | Result |
| --- | --- |
| Full Laravel suite | **49 passed, 368 assertions** |
| Frontend Vitest suite | **21 passed** |
| TypeScript validation | Passed as part of production build |
| Vite production build | Passed |
| SQLite migrations | All seven migrations Ran; Stage 2 migration is batch 4 |
| Real HTTP integration | Passed: CSRF/cookies/login/logout, clients, report periods/duplicates/transitions, team assignment and disabled session rejection |
| Live data preservation | Every original user/project column unchanged; existing active Super Admin and Hann's project ID retained |
| Canonical client migration | Exactly one Hann's Pharmacy client; no duplicate |
| Report data in live database | Zero periods seeded; no fabricated SEO metrics |
| Browser visual testing | **Not performed** |
| MySQL 8 runtime | **Unavailable / unverified** |

The Stage 2 suite covers client creation/edit/archive/restore, private logo validation/access, project creation/edit/archive/restore/filtering/sorting, memberships, org roles and demotion, report ranges/duplicates/stale versions/history/transitions/sharing, viewer data isolation, team provisioning/password links, existing login/logout regressions, and preservation/idempotency on SQLite.

The local pre-migration snapshot and preservation result are retained in ignored .tools/ artifacts. No live account was recreated, no password was changed, and no original project field or ID was rewritten. No report sample, testing account or reporting period was added to the configured application database.

See [Stage 2 implementation, migration status, file inventory and browser walkthrough](STAGE-2.md). Report document uploads/extraction remain deferred; no Stage 3 or Stage 4 work began.

---

## Earlier Stage 1 / authentication verification history

The material below records the previous stage's results and database state at that earlier time; it is not the current Stage 2 record.

# Verification record — Stage 1

Validated on 16 October 2026 in D:\xampp\htdocs\pentagon-insight-flow.

## Executed successfully

| Check | Observed result |
| --- | --- |
| Laravel migrations on local SQLite | users/reset tokens/sessions/cache/jobs/projects/Sanctum tables and account security fields migrated |
| Default seeder | Never creates or modifies authentication accounts |
| Backend PHPUnit suite | 33 passed, 231 assertions (31 application feature tests plus two scaffold tests) |
| Frontend Vitest + Testing Library | 14 passed |
| TypeScript and Vite production build | Passed; frontend/dist generated |
| Composer validate --strict | Valid manifest and synchronized lockfile |
| Composer audit | No security vulnerability advisories |
| npm audit | 0 vulnerabilities after updating test/build tooling |
| Real HTTP smoke integration | Passed |
| Reference screenshot SHA-256 | Source and copied design-reference file identical |
| UTF-8 source scan | No mojibake/replacement characters found |

Backend tests cover guest access, login/logout, hidden credentials, throttling, password strength/current-password checks, generic recovery responses, reset URLs, invalid/expired/single-use reset tokens, database session revocation, actual CSRF enforcement, CORS behavior, project validation, malicious owner fields, list/search/overview isolation, foreign ID access/update/dashboard rejection, archive/edit, pagination and empty reporting sections.

Frontend tests cover protected routes, credential submission after CSRF, login failures, connection recovery, logout, API-driven project rendering, search/status requests, empty and loading/error/retry states, create success, validation error/value preservation, report tabs and recovery requests.

The HTTP smoke check uses a random temporary account/database and a real PHP server with production-style cookie middleware. It verifies an unauthenticated 401, missing-CSRF 419, CSRF cookie 204, cookie-based login, authenticated identity, CSRF-protected project creation, project dashboard access, logout and a subsequent 401. The server is stopped and its temporary database removed.

## Authentication follow-up

See [account setup and investigation](AUTHENTICATION.md). The reported prefilled account was absent from source, built assets and the configured database. Empty login defaults are explicit and native browser autofill remains allowed. Account creation is interactive-only and uses Laravel hashing. Safe disablement requires a different active Super Admin with a successful login and matching password, revokes only the selected account access, and retains projects/records. Tests cover these commands and disabled account rejection. The extended HTTP smoke check confirms Super Admin login verification and denial of an existing session after disablement.

The configured application database had zero users and zero projects both before and after this update. No actual account was created, replaced or disabled. The account-security migration was additive. The personal setup must be performed privately by the owner; the browser/password-manager source of the original observation remains unverified.

## Environment findings and remaining limits

| Item | Finding |
| --- | --- |
| Original XAMPP PHP | 8.0.25, incompatible with Laravel 12 |
| Runtime used | Workspace-local PHP 8.4.26; dependency resolution targets PHP 8.2.0 |
| Composer | Not originally installed; local composer.phar used |
| Node / npm | Node 22.14.0; npm 10.9.2 |
| Dependency installer | npm 11 via npx; npm 10 crashed resolving optional Vitest peers |
| Database | XAMPP MariaDB 10.4.27, server stopped; no MySQL 8 available |
| Database verification | SQLite only; MySQL 8 migration/runtime integration remains unverified |
| Python | Windows Store alias could not execute; no working Python found on PATH |
| Python extraction | Intentionally deferred; not a Stage 1 runtime requirement |
| Live design reference | URL fetch failed; supplied screenshot used |
| Administrative/mobile references | Missing; temporary styling documented |
| Browser visual verification | Not run; no browser automation/preview tool available |
| Mail | Local log transport only; SMTP delivery unverified |
| Deployment | None; loopback development only |

The portable runtime and dependencies do not upgrade XAMPP or install MySQL/Python system-wide. No original reference report was modified. No analytics were seeded, inferred from reference documents or hardcoded into UI components. Report features remain outside this stage.

## Local verification commands

Run from backend/:

```powershell
& ..\.tools\php\php.exe artisan test
& ..\.tools\php\php.exe ..\.tools\composer.phar validate --strict
& ..\.tools\php\php.exe ..\.tools\composer.phar audit
```

Run from frontend/:

```powershell
npm.cmd test
npm.cmd run build
npm.cmd audit
```

Run from repository root:

```powershell
node docs/smoke.mjs
```
