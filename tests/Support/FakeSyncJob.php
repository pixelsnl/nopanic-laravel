<?php

namespace NoPanic\Laravel\Tests\Support;

use Illuminate\Contracts\Queue\ShouldQueue;
use Illuminate\Support\Facades\Log;

class FakeSyncJob implements ShouldQueue
{
    public function handle(): void
    {
        Log::info('inside the sync job');
    }
}
