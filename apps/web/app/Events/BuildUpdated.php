<?php

namespace App\Events;

use App\Models\Build;
use Illuminate\Broadcasting\InteractsWithSockets;
use Illuminate\Broadcasting\PrivateChannel;
use Illuminate\Contracts\Broadcasting\ShouldBroadcastNow;
use Illuminate\Foundation\Events\Dispatchable;
use Illuminate\Queue\SerializesModels;

class BuildUpdated implements ShouldBroadcastNow
{
    use Dispatchable, InteractsWithSockets, SerializesModels;

    public function __construct(public Build $build) {}

    /** @return list<PrivateChannel> */
    public function broadcastOn(): array
    {
        return [
            new PrivateChannel('builds.'.$this->build->id),
            new PrivateChannel('projects.'.$this->build->project_id),
        ];
    }

    public function broadcastAs(): string
    {
        return 'build.updated';
    }

    /** @return array<string, mixed> */
    public function broadcastWith(): array
    {
        $b = $this->build;
        $jobs = $b->captureJobs()->selectRaw('status, count(*) as c')->groupBy('status')->pluck('c', 'status');

        return [
            'id' => $b->id,
            'number' => $b->number,
            'status' => $b->status->value,
            'snapshots' => $b->snapshots()->count(),
            'jobs' => ['total' => (int) $jobs->sum(), 'done' => (int) (($jobs['completed'] ?? 0) + ($jobs['failed'] ?? 0))],
        ];
    }
}
