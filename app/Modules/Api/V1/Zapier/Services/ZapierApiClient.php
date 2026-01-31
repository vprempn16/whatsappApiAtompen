<?php

namespace App\Modules\Api\V1\Zapier\Services;

use Illuminate\Support\Facades\Http;
use Illuminate\Support\Facades\Log;

class ZapierApiClient
{
    protected string $apiKey;
    protected string $baseUrl;
    protected int $timeout;
    protected int $maxRetries;
    protected array $retryDelays;

    public function __construct(string $apiKey, string $baseUrl = 'https://hooks.zapier.com')
    {
        $this->apiKey = $apiKey;
        $this->baseUrl = $baseUrl;
        $this->timeout = 30;
        $this->maxRetries = 3;
        $this->retryDelays = [1, 5, 30]; // Exponential backoff in seconds
    }

    /**
     * Fetch records from Zapier webhook/API
     * 
     * @param string $endpoint Webhook endpoint or API endpoint
     * @param array $params Query parameters
     * @param string $syncMode 'initial' or 'incremental'
     * @param string|null $lastSyncTimestamp For incremental syncs
     * @return array ['data' => [], 'has_more' => bool, 'next_cursor' => string|null]
     */
    public function fetchRecords(
        string $endpoint,
        array $params = [],
        string $syncMode = 'initial',
        ?string $lastSyncTimestamp = null
    ): array {
        $url = $this->buildUrl($endpoint);
        
        // Add sync parameters
        $params['sync_mode'] = $syncMode;
        if ($lastSyncTimestamp) {
            $params['since'] = $lastSyncTimestamp;
        }

        return $this->makeRequest('GET', $url, $params);
    }

    /**
     * Fetch records with pagination
     * 
     * @param string $endpoint
     * @param array $params
     * @param string $syncMode
     * @param string|null $lastSyncTimestamp
     * @param int $pageSize
     * @return \Generator
     */
    public function fetchRecordsPaginated(
        string $endpoint,
        array $params = [],
        string $syncMode = 'initial',
        ?string $lastSyncTimestamp = null,
        int $pageSize = 100
    ): \Generator {
        $page = 1;
        $hasMore = true;

        while ($hasMore) {
            $params['page'] = $page;
            $params['per_page'] = $pageSize;

            $response = $this->fetchRecords($endpoint, $params, $syncMode, $lastSyncTimestamp);

            if (empty($response['data'])) {
                break;
            }

            yield $response['data'];

            $hasMore = $response['has_more'] ?? false;
            $page++;

            // Safety limit: prevent infinite loops
            if ($page > 1000) {
                Log::warning('Zapier API pagination limit reached', [
                    'endpoint' => $endpoint,
                    'page' => $page,
                ]);
                break;
            }
        }
    }

    /**
     * Test API connection
     */
    public function testConnection(string $endpoint): bool
    {
        try {
            $url = $this->buildUrl($endpoint);
            $response = $this->makeRequest('GET', $url, ['test' => true]);
            return isset($response['status']) && $response['status'] === 'ok';
        } catch (\Exception $e) {
            Log::error('Zapier API connection test failed', [
                'endpoint' => $endpoint,
                'error' => $e->getMessage(),
            ]);
            return false;
        }
    }

    /**
     * Build full URL from endpoint
     */
    protected function buildUrl(string $endpoint): string
    {
        // If endpoint is already a full URL, return as is
        if (filter_var($endpoint, FILTER_VALIDATE_URL)) {
            return $endpoint;
        }

        // Otherwise, append to base URL
        return rtrim($this->baseUrl, '/') . '/' . ltrim($endpoint, '/');
    }

    /**
     * Make HTTP request with retry logic
     */
    protected function makeRequest(string $method, string $url, array $params = []): array
    {
        $attempt = 0;
        $lastException = null;

        while ($attempt < $this->maxRetries) {
            try {
                $response = Http::timeout($this->timeout)
                    ->withHeaders([
                        'Authorization' => 'Bearer ' . $this->apiKey,
                        'Accept' => 'application/json',
                        'Content-Type' => 'application/json',
                    ])
                    ->{strtolower($method)}($url, $params);

                if ($response->successful()) {
                    return $this->parseResponse($response->json());
                }

                // If 429 (rate limit), wait longer before retry
                if ($response->status() === 429) {
                    $waitTime = $response->header('Retry-After') ?? ($this->retryDelays[$attempt] * 2);
                    Log::warning('Zapier API rate limit hit', [
                        'url' => $url,
                        'retry_after' => $waitTime,
                    ]);
                    sleep($waitTime);
                    $attempt++;
                    continue;
                }

                // For other errors, throw exception
                $response->throw();

            } catch (\Illuminate\Http\Client\RequestException $e) {
                $lastException = $e;
                $attempt++;

                if ($attempt < $this->maxRetries) {
                    $delay = $this->retryDelays[$attempt - 1] ?? 5;
                    Log::warning('Zapier API request failed, retrying', [
                        'url' => $url,
                        'attempt' => $attempt,
                        'delay' => $delay,
                        'error' => $e->getMessage(),
                    ]);
                    sleep($delay);
                } else {
                    Log::error('Zapier API request failed after retries', [
                        'url' => $url,
                        'attempts' => $attempt,
                        'error' => $e->getMessage(),
                    ]);
                }
            } catch (\Exception $e) {
                Log::error('Zapier API unexpected error', [
                    'url' => $url,
                    'error' => $e->getMessage(),
                ]);
                throw $e;
            }
        }

        throw new \Exception('Zapier API request failed after ' . $this->maxRetries . ' attempts: ' . ($lastException ? $lastException->getMessage() : 'Unknown error'));
    }

    /**
     * Parse and normalize API response
     */
    protected function parseResponse($data): array
    {
        // Handle different response formats
        if (is_array($data)) {
            // If response is already an array of records
            if (isset($data[0]) && is_array($data[0])) {
                return [
                    'data' => $data,
                    'has_more' => false,
                    'next_cursor' => null,
                ];
            }

            // If response has 'data' key
            if (isset($data['data'])) {
                return [
                    'data' => $data['data'],
                    'has_more' => $data['has_more'] ?? false,
                    'next_cursor' => $data['next_cursor'] ?? $data['next_page'] ?? null,
                ];
            }

            // If response is a single record wrapped
            return [
                'data' => [$data],
                'has_more' => false,
                'next_cursor' => null,
            ];
        }

        return [
            'data' => [],
            'has_more' => false,
            'next_cursor' => null,
        ];
    }

    /**
     * Set timeout
     */
    public function setTimeout(int $timeout): self
    {
        $this->timeout = $timeout;
        return $this;
    }

    /**
     * Set max retries
     */
    public function setMaxRetries(int $maxRetries): self
    {
        $this->maxRetries = $maxRetries;
        return $this;
    }
}
