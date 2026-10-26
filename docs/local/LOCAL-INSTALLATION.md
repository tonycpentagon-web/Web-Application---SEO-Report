# Fresh local installation

1. Install the prerequisites in WINDOWS-REQUIREMENTS.md. Extract the complete local evaluation ZIP into a NEW folder outside web document roots, for example D:\Pentagon-SEO-Test. Do not extract over the existing application.
2. Open normal Windows PowerShell in that folder. Review local/Install.ps1. For this terminal only, if needed: `Set-ExecutionPolicy -Scope Process Bypass` (organizational policies still apply).
3. Run with actual installed executable paths:

```powershell
.\local\Install.ps1 -Php 'D:\xampp\php\php.exe' -Python 'C:\Python312\python.exe' -Node 'C:\Program Files\nodejs\node.exe' -Browser 'C:\Program Files (x86)\Microsoft\Edge\Application\msedge.exe'
```

Select `-Port 8788` if 8787 is occupied. Setup refuses any existing backend/.env or local-runtime directory. It checks PHP/platform capability, Python/Node/browser paths, creates an isolated Python environment, installs pinned requirements, creates an EMPTY SQLite database, generates an app key, runs additive migrations and checks the processor. It never imports Hann's data or creates a default user. A failed install leaves evidence/configuration in the new folder; inspect it and retry in another fresh extraction rather than bypassing overwrite guards. `-UseExistingPython` is for operators with a fully provisioned Python 3.12 environment; the script still checks imports. No shared/global Python is modified by default.

4. Run `.\local\New-TestAdmin.ps1` in an interactive terminal. Enter your own name, email and strong password using hidden prompts. Do not supply passwords on command lines or in chat.
5. Run `.\local\Start.ps1 -OpenBrowser`, then `.\local\Health.ps1`. Sign in at http://127.0.0.1:8787 (or the selected port). Complete the PDF/scanned-source test in TEST-ACCOUNTS-SETUP.md.

Keep backend/.env, local-runtime/settings.json, the database, storage and logs private. Do not move an installed folder: paths are recorded deliberately, and Start refuses a mismatched root. For a move/update follow BACKUP-RESTORE.md. Setup is Windows-only and does not install a Windows service, firewall rule, XAMPP service, Docker, WSL or remote server.

If choosing MariaDB instead of SQLite, an operator must create a dedicated empty database/user privately, configure the existing Laravel mysql connection, verify actual compatibility and backups, and run migrations. Do not change the default SQLite install into MariaDB by merely renaming a file; no automated SQLite-to-MariaDB conversion is provided.
