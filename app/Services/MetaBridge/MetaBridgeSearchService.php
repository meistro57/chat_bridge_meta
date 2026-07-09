<?php

namespace App\Services\MetaBridge;

use App\Services\AI\EmbeddingService;
use App\Services\Qdrant\QdrantConnector;
use App\Services\Qdrant\Requests\SearchPointsRequest;
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
     * @return array<int, array{score: float|null, payload: array<string, mixed>}>
     */
    protected function search(
        string $collection,
        string $query,
        int $limit,
        ?float $scoreThreshold,
        ?string $vectorName = null,
    ): array {
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
                    scoreThreshold: $scoreThreshold ?? (float) config('services.meta_bridge.score_threshold', 0.5),
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
    }
}
