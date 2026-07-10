<?php

namespace App\Services\MetaBridge;

use App\Services\AI\EmbeddingService;
use App\Services\Qdrant\QdrantConnector;
use App\Services\Qdrant\Requests\ScrollPointsRequest;
use App\Services\Qdrant\Requests\SearchPointsRequest;
use Illuminate\Support\Facades\Cache;
use Illuminate\Support\Facades\Log;

/**
 * Read-only vector search against the meta-bridge Qdrant collections
 * (mb_claims, mb_chunks, mb_sources, meta_reflections, misfit_reports).
 *
 * Meta Bridge (github.com/meistro57/meta-bridge) and MisfitCrew own ingestion
 * into these collections. Chat Bridge only ever searches them — it never
 * writes, upserts, or deletes here. Query vectors are generated with the same
 * gemini-embedding-001 model meta-bridge uses (see EmbeddingService +
 * config/services.php: openrouter.embedding_model), so they land in the
 * same 3072-dim vector space.
 */
class MetaBridgeSearchService
{
    protected QdrantConnector $qdrant;

    public function __construct(protected EmbeddingService $embeddingService)
    {
        $this->qdrant = new QdrantConnector(
            host: (string) config('services.qdrant.host', 'localhost'),
            port: (int) config('services.qdrant.port', 6333),
        );
    }

    /**
     * Search extracted claims (canonical statements distilled from the corpus).
     *
     * @return array<int, array{score: float|null, payload: array<string, mixed>}>
     */
    public function searchClaims(string $query, int $limit = 10, ?float $scoreThreshold = null): array
    {
        return $this->search(
            collection: (string) config('services.meta_bridge.collection_claims', 'mb_claims'),
            query: $query,
            limit: $limit,
            scoreThreshold: $scoreThreshold,
        );
    }

    /**
     * Search raw source-text chunks (paragraph/chapter-level excerpts).
     *
     * @return array<int, array{score: float|null, payload: array<string, mixed>}>
     */
    public function searchChunks(string $query, int $limit = 10, ?float $scoreThreshold = null): array
    {
        return $this->search(
            collection: (string) config('services.meta_bridge.collection_chunks', 'mb_chunks'),
            query: $query,
            limit: $limit,
            scoreThreshold: $scoreThreshold,
        );
    }

    /**
     * Search book/source-level records (title, author, tradition metadata).
     *
     * @return array<int, array{score: float|null, payload: array<string, mixed>}>
     */
    public function searchSources(string $query, int $limit = 10, ?float $scoreThreshold = null): array
    {
        return $this->search(
            collection: (string) config('services.meta_bridge.collection_sources', 'mb_sources'),
            query: $query,
            limit: $limit,
            scoreThreshold: $scoreThreshold,
        );
    }

    /**
     * Search synthesized cross-source reflections. This collection uses named
     * vectors (summary_vec, claims_vec); defaults to summary_vec for general
     * topical search.
     *
     * @return array<int, array{score: float|null, payload: array<string, mixed>}>
     */
    public function searchReflections(string $query, int $limit = 10, ?float $scoreThreshold = null): array
    {
        return $this->search(
            collection: (string) config('services.meta_bridge.collection_reflections', 'meta_reflections'),
            query: $query,
            limit: $limit,
            scoreThreshold: $scoreThreshold,
            vectorName: (string) config('services.meta_bridge.reflection_vector', 'summary_vec'),
        );
    }

    /**
     * Search MisfitCrew's synthesized cross-source pattern reports. Like
     * meta_reflections, this collection uses named vectors (summary_vec,
     * claims_vec); defaults to summary_vec for general topical search.
     *
     * @return array<int, array{score: float|null, payload: array<string, mixed>}>
     */
    public function searchMisfitReports(string $query, int $limit = 10, ?float $scoreThreshold = null): array
    {
        return $this->search(
            collection: (string) config('services.meta_bridge.collection_misfit_reports', 'misfit_reports'),
            query: $query,
            limit: $limit,
            scoreThreshold: $scoreThreshold,
            vectorName: (string) config('services.meta_bridge.misfit_reports_vector', 'summary_vec'),
        );
    }

    /**
     * Search Vectoreologist's topology findings (clusters, bridges, moats,
     * anomalies) mined from meta-bridge Qdrant collections.
     *
     * Unlike the collections above, vectoreology_findings stores vectors as a
     * dim-1 placeholder — it was never meant to be embedding-searched. So this
     * filters by payload (type / is_anomaly / min confidence) via Qdrant scroll,
     * then does an in-memory case-insensitive match of $query against the
     * `subject` and `reasoning_chain` payload fields. Pass an empty $query to
     * skip keyword filtering and just browse by type/anomaly/confidence.
     *
     * @param  string  $query  Keyword to match against subject/reasoning_chain (empty = no keyword filter)
     * @param  string|null  $type  Filter by finding type, e.g. 'cluster_analysis', 'density_anomaly'
     * @param  bool|null  $isAnomaly  Filter to anomaly-flagged findings only (true) or non-anomalies (false)
     * @param  float|null  $minConfidence  Minimum confidence score (0-1)
     * @return array<int, array{score: null, payload: array<string, mixed>}>
     */
    public function searchVectoreologyFindings(
        string $query = '',
        int $limit = 10,
        ?string $type = null,
        ?bool $isAnomaly = null,
        ?float $minConfidence = null,
    ): array {
        $collection = (string) config('services.meta_bridge.collection_vectoreology_findings', 'vectoreology_findings');

        $cacheKey = $this->buildCacheKey('vectoreology_findings', [
            'query' => $query,
            'limit' => $limit,
            'type' => $type,
            'is_anomaly' => $isAnomaly,
            'min_confidence' => $minConfidence,
        ]);

        return $this->remember($cacheKey, function () use ($collection, $query, $limit, $type, $isAnomaly, $minConfidence): array {
            $must = [];

            if ($type !== null) {
                $must[] = ['key' => 'type', 'match' => ['value' => $type]];
            }
            if ($isAnomaly !== null) {
                $must[] = ['key' => 'is_anomaly', 'match' => ['value' => $isAnomaly]];
            }
            if ($minConfidence !== null) {
                $must[] = ['key' => 'confidence', 'range' => ['gte' => $minConfidence]];
            }

            $filter = $must === [] ? null : ['must' => $must];

            try {
                $response = $this->qdrant->send(
                    new ScrollPointsRequest(
                        collectionName: $collection,
                        // Pull a wider batch than $limit since keyword matching happens
                        // client-side after this; Qdrant itself can't text-search these fields.
                        limit: max($limit * 10, 200),
                        filter: $filter,
                    )
                );

                if (! $response->successful()) {
                    Log::warning('Vectoreology findings scroll failed', [
                        'collection' => $collection,
                        'status' => $response->status(),
                        'body' => $response->body(),
                    ]);

                    return [];
                }

                $points = $response->json('result.points', []);

                if (! is_array($points)) {
                    return [];
                }

                $needle = trim(mb_strtolower($query));

                $matches = array_values(array_filter(
                    array_map(
                        static fn (array $point): array => is_array($point['payload'] ?? null) ? $point['payload'] : [],
                        array_filter($points, static fn ($point): bool => is_array($point))
                    ),
                    function (array $payload) use ($needle): bool {
                        if ($needle === '') {
                            return true;
                        }

                        $haystack = mb_strtolower(
                            (string) ($payload['subject'] ?? '').' '.(string) ($payload['reasoning_chain'] ?? '')
                        );

                        return str_contains($haystack, $needle);
                    }
                ));

                // Higher-confidence findings first among matches.
                usort($matches, static fn (array $a, array $b): int => ($b['confidence'] ?? 0) <=> ($a['confidence'] ?? 0));

                return array_map(
                    static fn (array $payload): array => ['score' => null, 'payload' => $payload],
                    array_slice($matches, 0, $limit)
                );
            } catch (\Throwable $exception) {
                Log::warning('Vectoreology findings search threw an exception', [
                    'collection' => $collection,
                    'error' => $exception->getMessage(),
                ]);

                return [];
            }
        });
    }

    /**
     * @return array<int, array{score: float|null, payload: array<string, mixed>}>
     */
    protected function search(
        string $collection,
        string $query,
        int $limit,
        ?float $scoreThreshold,
        ?string $vectorName = null,
    ): array {
        $resolvedThreshold = $scoreThreshold ?? (float) config('services.meta_bridge.score_threshold', 0.5);

        $cacheKey = $this->buildCacheKey($collection, [
            'query' => $query,
            'limit' => $limit,
            'score_threshold' => $resolvedThreshold,
            'vector_name' => $vectorName,
        ]);

        return $this->remember($cacheKey, function () use ($collection, $query, $limit, $resolvedThreshold, $vectorName): array {
            $embedding = $this->embeddingService->getEmbedding($query);

            if (! $embedding) {
                Log::warning('Meta Bridge search: failed to generate query embedding', [
                    'collection' => $collection,
                ]);

                return [];
            }

            try {
                $response = $this->qdrant->send(
                    new SearchPointsRequest(
                        collectionName: $collection,
                        vector: $embedding,
                        limit: $limit,
                        scoreThreshold: $resolvedThreshold,
                        vectorName: $vectorName,
                    )
                );

                if (! $response->successful()) {
                    Log::warning('Meta Bridge search failed', [
                        'collection' => $collection,
                        'status' => $response->status(),
                        'body' => $response->body(),
                    ]);

                    return [];
                }

                $results = $response->json('result', []);

                if (! is_array($results)) {
                    return [];
                }

                return array_values(array_map(
                    static fn (array $point): array => [
                        'score' => isset($point['score']) ? (float) $point['score'] : null,
                        'payload' => is_array($point['payload'] ?? null) ? $point['payload'] : [],
                    ],
                    array_filter($results, static fn ($point): bool => is_array($point))
                ));
            } catch (\Throwable $exception) {
                Log::warning('Meta Bridge search threw an exception', [
                    'collection' => $collection,
                    'error' => $exception->getMessage(),
                ]);

                return [];
            }
        });
    }

    /**
     * Build a deterministic Redis cache key for a search call, scoped by
     * collection and every parameter that affects the result set.
     *
     * @param  array<string, mixed>  $params
     */
    protected function buildCacheKey(string $collection, array $params): string
    {
        ksort($params);
        $normalized = array_map(
            static fn ($value) => is_string($value) ? trim(mb_strtolower($value)) : $value,
            $params
        );

        return 'meta_bridge_search:'.$collection.':'.md5(json_encode($normalized, JSON_UNESCAPED_SLASHES));
    }

    /**
     * Cache a search callback's result in Redis (or whatever
     * services.meta_bridge.cache_store points at). Repeated identical
     * agent queries — common across turns in the same conversation — skip
     * the embedding call and the Qdrant round trip entirely on a hit.
     *
     * Caching can be disabled via MB_QDRANT_CACHE_ENABLED=false, and any
     * cache-layer failure (e.g. Redis unreachable) degrades to calling the
     * search directly rather than breaking the tool call.
     *
     * @param  \Closure(): array<int, array<string, mixed>>  $callback
     * @return array<int, array<string, mixed>>
     */
    protected function remember(string $cacheKey, \Closure $callback): array
    {
        if (! (bool) config('services.meta_bridge.cache_enabled', true)) {
            return $callback();
        }

        $store = (string) config('services.meta_bridge.cache_store', 'redis');
        $ttlSeconds = max(1, (int) config('services.meta_bridge.cache_ttl_seconds', 300));

        try {
            return Cache::store($store)->remember($cacheKey, $ttlSeconds, $callback);
        } catch (\Throwable $exception) {
            Log::warning('Meta Bridge search cache unavailable; querying directly', [
                'cache_store' => $store,
                'error' => $exception->getMessage(),
            ]);

            return $callback();
        }
    }
}
