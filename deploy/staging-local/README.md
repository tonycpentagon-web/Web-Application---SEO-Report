# Local synthetic Linux staging (prepared, execution BLOCKED here)

Docker CLI/Desktop is absent on the inspected Windows host. WSL --status exits 50; no usable Linux distribution was found. No OS software was installed. These files were YAML/static/shell checked; **images have not been built and containers have not been run**. Use a Docker Engine with Compose v2 after an operator installs/approves it. No public deployment is authorized.

The stack uses separate backend PHP-FPM, production React/Nginx TLS, MySQL 8.0, combined document/export worker, scheduler and Mailpit. Python/OCR and Node/Chromium live in the backend/worker image because the application uses local subprocesses. No separate Python HTTP service or Redis is required. All application state is in this project's named volumes; no source/development-database/upload bind mounts exist. MySQL and FPM have no host ports. Web ports are localhost 8088/8443; Mailpit UI is localhost 8028. The internal network prevents ordinary outbound service access; no external mail relay is configured. Check Docker firewall behavior on the actual host before trusting network isolation.

From repository root (PowerShell commands):

```powershell
.tools/python/python.exe ops/staging_setup.py compose-env
# Creates ignored deploy/staging-local/.env exclusively; never prints values or overwrites existing secrets.
docker compose -f deploy/staging-local/docker-compose.yml config --quiet
docker compose -f deploy/staging-local/docker-compose.yml build backend
# Worker FROM references the backend tag: build backend FIRST.
docker compose -f deploy/staging-local/docker-compose.yml build worker web
docker compose -f deploy/staging-local/docker-compose.yml up -d
docker compose -f deploy/staging-local/docker-compose.yml ps
docker compose -f deploy/staging-local/docker-compose.yml exec backend php artisan system:health --probe
docker compose -f deploy/staging-local/docker-compose.yml exec backend php /app/ops/staging/fixture.php seed
docker compose -f deploy/staging-local/docker-compose.yml exec backend /opt/venv/bin/python /app/ops/staging/synthetic_files.py /runtime/synthetic-sources
```

Open https://localhost:8443 (self-signed local certificate only) and http://localhost:8028 for capture. No certificate trust-store installation is performed. Health may show static production configuration Warning: APP_ENV=staging/local origins are deliberately distinct from actual production. Do not weaken the production validator to turn that green. A successful local TLS handshake is not public certificate/DNS/SMTP validation.

To run the synthetic HTTP workflow from Windows against Compose, create a new ignored .tools/stage8-local-compose folder; copy only the synthetic `accounts.json`, `credentials.json` and `.synthetic-stage8` from backend:/runtime into it using docker compose cp. Generate its sources using ops/staging/synthetic_files.py. Set STAGE8_ROOT to that absolute folder and STAGE8_URL=https://localhost:8443 in the Node process, then run `node ops/staging/workflow.mjs`. The client refuses non-local hostnames and ignores certificate errors only for its local self-signed test. Never import real client data. The Windows fallback's batch/load/recovery tools are explicitly Windows/SQLite tests, not MySQL certifications.

For backend unit/regression tests in the container, explicitly force disposable SQLite memory and test services at exec time; phpunit XML alone does not reliably override inherited Compose environment values:

```powershell
docker compose -f deploy/staging-local/docker-compose.yml exec -e APP_ENV=testing -e DB_CONNECTION=sqlite -e DB_DATABASE=:memory: -e DB_URL= -e CACHE_STORE=array -e SESSION_DRIVER=array -e QUEUE_CONNECTION=sync -e MAIL_MAILER=array backend php artisan test --compact
docker compose -f deploy/staging-local/docker-compose.yml exec backend /opt/venv/bin/python /app/document-processor/run_tests.py
```

TEST_REFERENCE_DIR points at generated synthetic sources. Generate them before tests. The optional original-upload Python manifest test will skip; it must not cause private development files to be copied into the image. Dedicated actual MySQL test commands are in docs/STAGE_8_MYSQL_COMPATIBILITY.md. Runtime PHP 8.4, Node 22 and Python 3.11 libraries/OCR/Chromium still require actual build/run verification. Do not disable browser or container isolation to hide missing host capabilities. Mutable base-image tags must be resolved/reviewed to digests for a future release after the first successful staging build.

Shutdown retains all test data:

```powershell
docker compose -f deploy/staging-local/docker-compose.yml stop -t 1200
docker compose -f deploy/staging-local/docker-compose.yml down --remove-orphans
```

Optional irreversible cleanup only after inspecting labels/data and stopping this exact project: `docker volume inspect pentagon-stage8-local_mysql pentagon-stage8-local_runtime pentagon-stage8-local_private pentagon-stage8-local_operations`. Confirm each has `com.docker.compose.project=pentagon-stage8-local`. Then explicitly remove only those four named volumes using `docker volume rm pentagon-stage8-local_mysql pentagon-stage8-local_runtime pentagon-stage8-local_private pentagon-stage8-local_operations`. Never use prune, wildcard deletion, down -v in another project, or delete any development directory. Cleanup was not executed here.

WSL alternative: after an operator installs WSL2/Ubuntu with required administrative approval, install the Docker Engine/Compose plugin inside that distro or enable Docker Desktop's distro integration. Copy only the allowlisted source package into a new Linux workspace, generate isolated staging credentials, and use the same Compose commands. A bare wsl.exe installer is insufficient. If using manually installed Nginx/PHP/MySQL/Python instead of Docker, create distinct staging/test schemas, service accounts, storage and queues; run the same security/recovery gates and do not point it at Windows development files.

References: [Compose startup health ordering](https://docs.docker.com/compose/how-tos/startup-order), [Compose network model](https://docs.docker.com/compose/how-tos/networking/), [MySQL official image](https://hub.docker.com/_/mysql/), [Playwright container requirements](https://playwright.dev/docs/docker). These references informed preparation; they are not evidence that this host ran Linux.
