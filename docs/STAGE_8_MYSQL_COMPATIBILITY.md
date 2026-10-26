# Stage 8 MySQL compatibility

**BLOCKED: actual MySQL 8 is unavailable.** Docker CLI/Desktop is absent; no usable WSL Linux distribution was found. The available XAMPP SQL client identifies as MariaDB 10.4.27. No MariaDB result is presented as MySQL 8 evidence. The guarded integration test skipped in the local suite. No MySQL migrations, concurrency or rollback were actually executed.

Prepared target: mysql:8.0, utf8mb4_unicode_ci, strict Laravel connection, InnoDB, internal network, no published SQL port. Main synthetic schema pentagon_stage8_local; isolated integration schema pentagon_test_stage8 must be separately provisioned empty. Existing development SQLite was never converted or imported. The new source package excludes all real source/evidence/database files.

On a functioning Compose host, first run the stack's normal additive migrate service and synthetic workflow. Provision only the dedicated empty integration schema with the container's own root account (POSIX shell inside this exact mysql container):

```sh
MYSQL_PWD="$MYSQL_ROOT_PASSWORD" mysql -uroot <<'SQL'
CREATE DATABASE pentagon_test_stage8 CHARACTER SET utf8mb4 COLLATE utf8mb4_unicode_ci;
GRANT ALL ON pentagon_test_stage8.* TO 'pentagon_stage8'@'%';
SQL
```

Invoke that shell through `docker compose -f deploy/staging-local/docker-compose.yml exec mysql sh` without printing credentials. Never run it on another SQL server or existing schema. Then in the backend container shell run:

```sh
STAGE7_MYSQL_ALLOW=YES STAGE7_MYSQL_DATABASE=pentagon_test_stage8 STAGE7_MYSQL_HOST=mysql STAGE7_MYSQL_USER=pentagon_stage8 STAGE7_MYSQL_PASSWORD="$DB_PASSWORD" php artisan test --filter=Mysql8IntegrationTest
```

Use `docker compose ... exec backend sh` for the above; values remain in the local container environment, not committed files/output. The harness requires actual server VERSION() starting 8., an exact pentagon_test_* name and zero tables before migrations. It checks strict SQL mode, Unicode round-trip, synthetic three-client scoping, JSON-path query and a real transaction rollback. It never drops the schema, so the next run needs another explicitly new empty test schema and matching operator review.

This focused harness does not alone prove all production workloads. Remaining required MySQL gates: full migration/index/FK/unique inspection; valid and deliberately invalid FK/unique writes; pagination/filter/sort comparisons; 1,000+ records; competing normalization/export/publication transactions; reconnect/timeout/deadlock behavior and backup/restore into a new schema. Use the synthetic HTTP fixture and extend captured evidence on the actual server. Do not mark these PASS from SQLite tests.

All current migrations were reviewed and local fresh SQLite migration passed. Stage 7 operational migration is additive and refuses destructive down rollback. Rehearse code rollback retaining compatible additive schema; destructive schema rollback is not a supported shortcut. A disposable MySQL clone must be used for any future rollback experiment; it was NOT TESTED here.
