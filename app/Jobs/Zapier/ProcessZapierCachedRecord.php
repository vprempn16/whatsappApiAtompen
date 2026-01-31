<?php

namespace App\Jobs\Zapier;

use App\Modules\Api\V1\Zapier\Models\ZapierWebhookCache;
use App\Modules\Api\V1\Zapier\Services\ZapierCachedImportService;
use App\Modules\Api\V1\Zapier\Services\ZapierFieldMapperService;
use Illuminate\Bus\Queueable;
use Illuminate\Contracts\Queue\ShouldQueue;
use Illuminate\Foundation\Bus\Dispatchable;
use Illuminate\Queue\InteractsWithQueue;
use Illuminate\Queue\SerializesModels;

class ProcessZapierCachedRecord implements ShouldQueue
{
    use Dispatchable, InteractsWithQueue, Queueable, SerializesModels;

    public int $tries = 3;
    public int $timeout = 120;

    public function __construct(protected string $cacheId)
    {
    }

    public function handle(
        ZapierCachedImportService $cachedImportService,
        ZapierFieldMapperService $fieldMapperService,
        \App\Modules\Api\V1\Zapier\Services\ZapierIdempotencyService $idempotencyService
    ): void {
        $cache = ZapierWebhookCache::find($this->cacheId);

        if (!$cache) {
            return;
        }

        $cachedImportService->processCacheRecord($cache, $fieldMapperService, $idempotencyService);
    }
}
