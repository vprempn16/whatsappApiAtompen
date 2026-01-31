<?php

namespace App\Services;

use Illuminate\Pagination\LengthAwarePaginator;

class ListResponseService
{
    /**
     * Format paginated results into a standard API response.
     *
     * @param LengthAwarePaginator $paginator
     * @param callable|null $transformCallback Optional transformation for each item
     * @return array
     */
    public static function format(LengthAwarePaginator $paginator, callable $transformCallback = null): array
    {
        $collection = $paginator->getCollection();

        if ($transformCallback) {
            $collection = $collection->map($transformCallback);
        }

	return [
		'details' => $collection->values(),
		'meta' => [
			'total'        => $paginator->total(),
			'per_page'     => $paginator->perPage(),
			'current_page' => $paginator->currentPage(),
			'last_page'    => $paginator->lastPage(),
		]
	];
    }
}
