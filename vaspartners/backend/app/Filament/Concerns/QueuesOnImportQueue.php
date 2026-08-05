<?php

namespace App\Filament\Concerns;

trait QueuesOnImportQueue
{
    public function getJobQueue(): ?string
    {
        return (string) config('queue.names.import', 'import');
    }
}
