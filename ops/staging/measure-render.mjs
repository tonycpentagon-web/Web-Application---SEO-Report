import fs from 'node:fs/promises';
const started=performance.now();
await import('../../backend/pdf-renderer/render.mjs');
const usage=process.resourceUsage();
await fs.writeFile(process.argv[5],JSON.stringify({elapsed_ms:Math.round(performance.now()-started),node_max_rss_kib:usage.maxRSS,node_cpu_user_us:usage.userCPUTime,node_cpu_system_us:usage.systemCPUTime,browser_subprocess_memory:'NOT MEASURED',exit_code:process.exitCode||0},null,2));
