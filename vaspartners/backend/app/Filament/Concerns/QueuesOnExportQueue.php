<?php

namespace App\Filament\Concerns;

trait QueuesOnExportQueue
{
    public function getJobQueue(): ?string
    {
        return (string) config('queue.names.export', 'export');
    }
}
