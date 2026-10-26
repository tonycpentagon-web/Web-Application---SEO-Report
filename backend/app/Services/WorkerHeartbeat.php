<?php

namespace App\Services;

use Illuminate\Support\Facades\DB;

class WorkerHeartbeat
{
    private string $id;

    private array $queues = [];

    private int $last = 0;

    public function __construct()
    {
        $this->id = hash('sha256', gethostname().':'.getmypid().':'.microtime(true));
    }

    public function loop(string $queue): void
    {
        $this->queues = array_filter(explode(',', $queue));
        if (time() - $this->last >= 10) {
            $this->pulse('idle');
        }
    }

    public function state(string $queue, string $state): void
    {
        if (! $this->queues) {
            $this->queues = [$queue];
        }$this->pulse($state === 'busy' ? ($queue === 'exports' ? 'export_busy' : 'document_busy') : $state);
    }

    private function pulse(string $state): void
    {
        try {
            foreach ($this->queues as $queue) {
                if (! in_array($queue, [config('documents.queue'), 'exports'])) {
                    continue;
                }DB::table('worker_heartbeats')->updateOrInsert(['worker_id' => $this->id, 'queue' => $queue], ['last_seen_at' => now(), 'state' => $state]);
            }$this->last = time();
        } catch (\Throwable) { /* A missing monitoring table must not discard queued work. Health will be Not verified. */
        }
    }
}
