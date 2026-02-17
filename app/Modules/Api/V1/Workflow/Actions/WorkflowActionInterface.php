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
}
