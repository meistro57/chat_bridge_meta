<?php

namespace Tests\Unit;

use App\Services\AI\EmbeddingService;
use App\Services\MetaBridge\MetaBridgeSearchService;
use Illuminate\Support\Facades\Cache;
use Mockery;
use Saloon\Http\Faking\MockClient;
use Saloon\Http\Faking\MockResponse;
use Tests\TestCase;

class MetaBridgeSearchServiceTest extends TestCase
{
    protected function setUp(): void
    {
        parent::setUp();

        // Use the array store so this test doesn't depend on a live Redis
        // connection, while still exercising the exact same Cache::remember
        // contract the 'redis' store default uses in production.
        config(['services.meta_bridge.cache_store' => 'array']);
        Cache::store('array')->flush();
    }

    protected function tearDown(): void
    {
        // QdrantConnector is constructed fresh inside the service (not
        // injectable), so we register the mock globally per-test and must
        // tear it down or it leaks into unrelated tests.
        MockClient::destroyGlobal();
        Mockery::close();

        parent::tearDown();
    }

    public function test_search_claims_caches_repeated_identical_queries(): void
    {
        config(['services.meta_bridge.cache_enabled' => true]);

        $embeddingService = Mockery::mock(EmbeddingService::class);
        $embeddingService->shouldReceive('getEmbedding')
            ->once()
            ->with('law of one density')
            ->andReturn([0.1, 0.2, 0.3]);
        $this->app->instance(EmbeddingService::class, $embeddingService);

        $mockClient = MockClient::global([
            '*/collections/mb_claims/points/search' => MockResponse::make([
                'result' => [
                    ['score' => 0.9, 'payload' => ['canonical_statement' => 'Reality is a construct of focused attention.']],
                ],
            ], 200),
        ]);

        $service = app(MetaBridgeSearchService::class);

        $first = $service->searchClaims('law of one density', 5);
        $second = $service->searchClaims('law of one density', 5);

        $this->assertSame($first, $second);
        $this->assertCount(1, $first);
        $mockClient->assertSentCount(1);
    }

    public function test_cache_disabled_hits_qdrant_every_time(): void
    {
        config(['services.meta_bridge.cache_enabled' => false]);

        $embeddingService = Mockery::mock(EmbeddingService::class);
        $embeddingService->shouldReceive('getEmbedding')
            ->twice()
            ->with('nonphysical focus')
            ->andReturn([0.4, 0.5, 0.6]);
        $this->app->instance(EmbeddingService::class, $embeddingService);

        $mockClient = MockClient::global([
            '*/collections/mb_claims/points/search' => MockResponse::make(['result' => []], 200),
        ]);

        $service = app(MetaBridgeSearchService::class);

        $service->searchClaims('nonphysical focus', 5);
        $service->searchClaims('nonphysical focus', 5);

        $mockClient->assertSentCount(2);
    }

    public function test_different_limits_are_not_served_from_the_same_cache_entry(): void
    {
        config(['services.meta_bridge.cache_enabled' => true]);

        $embeddingService = Mockery::mock(EmbeddingService::class);
        $embeddingService->shouldReceive('getEmbedding')
            ->twice()
            ->with('gnostic archons')
            ->andReturn([0.7, 0.8, 0.9]);
        $this->app->instance(EmbeddingService::class, $embeddingService);

        $mockClient = MockClient::global([
            '*/collections/mb_chunks/points/search' => MockResponse::make(['result' => []], 200),
        ]);

        $service = app(MetaBridgeSearchService::class);

        $service->searchChunks('gnostic archons', 5);
        $service->searchChunks('gnostic archons', 10);

        $mockClient->assertSentCount(2);
    }

    public function test_vectoreology_findings_scroll_is_cached(): void
    {
        config(['services.meta_bridge.cache_enabled' => true]);

        $mockClient = MockClient::global([
            '*/collections/vectoreology_findings/points/scroll' => MockResponse::make([
                'result' => [
                    'points' => [
                        ['id' => 1, 'payload' => ['subject' => 'density anomaly', 'reasoning_chain' => 'high density cluster', 'confidence' => 0.9]],
                    ],
                ],
            ], 200),
        ]);

        $service = app(MetaBridgeSearchService::class);

        $first = $service->searchVectoreologyFindings('density', 5, isAnomaly: true);
        $second = $service->searchVectoreologyFindings('density', 5, isAnomaly: true);

        $this->assertSame($first, $second);
        $mockClient->assertSentCount(1);
    }

    public function test_cache_failure_degrades_to_direct_search_instead_of_breaking(): void
    {
        config([
            'services.meta_bridge.cache_enabled' => true,
            // Point at a cache store name that isn't configured at all, so
            // Cache::store() throws and remember() must fall back gracefully.
            'services.meta_bridge.cache_store' => 'definitely-not-a-real-store',
        ]);

        $embeddingService = Mockery::mock(EmbeddingService::class);
        $embeddingService->shouldReceive('getEmbedding')
            ->once()
            ->with('seth material')
            ->andReturn([0.1, 0.1, 0.1]);
        $this->app->instance(EmbeddingService::class, $embeddingService);

        $mockClient = MockClient::global([
            '*/collections/mb_sources/points/search' => MockResponse::make([
                'result' => [['score' => 0.8, 'payload' => ['title' => 'Seth Speaks']]],
            ], 200),
        ]);

        $service = app(MetaBridgeSearchService::class);
        $results = $service->searchSources('seth material', 5);

        $this->assertCount(1, $results);
        $mockClient->assertSentCount(1);
    }
}
