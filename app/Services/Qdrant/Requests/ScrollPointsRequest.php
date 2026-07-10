<?php

namespace App\Services\Qdrant\Requests;

use Saloon\Contracts\Body\HasBody;
use Saloon\Enums\Method;
use Saloon\Http\Request;
use Saloon\Traits\Body\HasJsonBody;

class ScrollPointsRequest extends Request implements HasBody
{
    use HasJsonBody;

    protected Method $method = Method::POST;

    public function __construct(
        protected string $collectionName,
        protected int $limit = 100,
        protected ?array $filter = null,
        protected ?string $orderBy = null,
    ) {}

    public function resolveEndpoint(): string
    {
        return "/collections/{$this->collectionName}/points/scroll";
    }

    protected function defaultBody(): array
    {
        $body = [
            'limit' => $this->limit,
            'with_payload' => true,
            'with_vector' => false,
        ];

        if ($this->filter) {
            $body['filter'] = $this->filter;
        }

        if ($this->orderBy) {
            $body['order_by'] = ['key' => $this->orderBy, 'direction' => 'desc'];
        }

        return $body;
    }
}
