<?php

namespace App\Services\Provisioning;

use App\Enums\ProvisioningWorkflow;
use InvalidArgumentException;

class ProvisioningWorkflowRegistry
{
    /**
     * @return array{label: string, steps: list<array<string, mixed>>}
     */
    public function get(string|ProvisioningWorkflow $workflow): array
    {
        $key = $workflow instanceof ProvisioningWorkflow ? $workflow->value : $workflow;
        $graph = config('provisioning_workflows.workflows.'.$key);

        if (! is_array($graph) || empty($graph['steps'])) {
            throw new InvalidArgumentException("Unknown provisioning workflow [{$key}].");
        }

        return $graph;
    }

    /**
     * @return array<string, mixed>|null
     */
    public function step(string|ProvisioningWorkflow $workflow, string $stepKey): ?array
    {
        foreach ($this->get($workflow)['steps'] as $step) {
            if (($step['key'] ?? null) === $stepKey) {
                return $step;
            }
        }

        return null;
    }

    /**
     * @return list<array<string, mixed>>
     */
    public function steps(string|ProvisioningWorkflow $workflow): array
    {
        return $this->get($workflow)['steps'];
    }
}
