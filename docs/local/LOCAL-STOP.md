# Stop without losing local data

Wait for source processing, report generation and PDF exports to finish. Then run:

```powershell
.\local\Stop.ps1
```

Stop targets only recorded wrapper processes and their descendants, checks exact PID start times and wrapper executable identity, and preserves the database/uploads. It refuses a reused or unrelated PID; uses native Windows taskkill for those exact recorded trees without WMI or administrator rights; it never stops all PHP/Node/XAMPP processes by executable name. A forced stop during extraction may leave a stale/failed job; restart and use the report/source retry controls after timeout. Do not reset the database or bulk-delete jobs.

If the computer rebooted, stale process records can remain. Run Stop; it skips already exited wrappers and removes the record. If a PID was reused, inspect the identified process manually and remove only this installation's stale processes.json after confirming none of its recorded services remain. Keep logs for troubleshooting. Do not close services by guessing process IDs.
