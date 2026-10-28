<?php

namespace App\Http\Resources\V1;

use Illuminate\Http\Request;
use Illuminate\Http\Resources\Json\ResourceCollection;
use Illuminate\Pagination\AbstractPaginator;

/**
 * Collection envelope: { message, data: [...], meta: {...} }.
 * Paginators yield the four-field pagination meta; plain collections yield { total }.
 */
class ApiCollection extends ResourceCollection
{
    public function __construct(mixed $resource, string $collects, private readonly string $message)
    {
        $this->collects = $collects;

        parent::__construct($resource);
    }

    /** @return array<string, mixed> */
    public function with(Request $request): array
    {
        return ['message' => $this->message];
    }

    /**
     * @param  array<string, mixed>  $paginated
     * @param  array<string, mixed>  $default
     * @return array<string, mixed>
     */
    public function paginationInformation(Request $request, array $paginated, array $default): array
    {
        return [
            'meta' => [
                'current_page' => $paginated['current_page'],
                'per_page' => $paginated['per_page'],
                'total' => $paginated['total'],
                'last_page' => $paginated['last_page'],
            ],
        ];
    }

    /** @return array<string, mixed> */
    public function toArray(Request $request): array
    {
        return ['data' => $this->collection];
    }

    public function toResponse($request)
    {
        $response = parent::toResponse($request);

        if (! $this->resource instanceof AbstractPaginator) {
            $payload = $response->getData(true);
            $payload['meta'] = ['total' => count($payload['data'] ?? [])];
            $response->setData($payload);
        }

        return $response;
    }
}
