<?php

namespace App\Modules\Api\V1\Workflow\Actions;

use App\Models\WorkflowQueue;

interface WorkflowActionInterface
{
    /**
     * Execute the workflow action.
     *
     * @param WorkflowQueue $job
     * @return void
     */
    public function handle(WorkflowQueue $job): void;

    /**
     * Validate and prepare parameters for saving.
     *
     * @param array $params
     * @param string $module
     * @param string $orgId
     * @return array
     * @throws \Exception
     */
    public function save(array $params, string $module, string $orgId): array;
}
