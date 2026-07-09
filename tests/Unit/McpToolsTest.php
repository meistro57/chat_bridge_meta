<?php

namespace Tests\Unit;

use App\Http\Controllers\Api\McpController;
use App\Services\AI\Tools\McpTools;
use App\Services\MetaBridge\MetaBridgeSearchService;
use Illuminate\Http\JsonResponse;
use Illuminate\Http\Request;
use Mockery;
use PHPUnit\Framework\TestCase;

class McpToolsTest extends TestCase
{
    protected function tearDown(): void
    {
        Mockery::close();

        parent::tearDown();
    }

    protected function makeTools(?McpController $controller = null, ?MetaBridgeSearchService $metaBridgeSearch = null): McpTools
    {
        return new McpTools(
            $controller ?? Mockery::mock(McpController::class),
            $metaBridgeSearch ?? Mockery::mock(MetaBridgeSearchService::class)
        );
    }

    public function test_search_conversations_normalizes_collection_results(): void
    {
        $controller = Mockery::mock(McpController::class);
        $controller->shouldReceive('search')
            ->once()
            ->with(Mockery::on(function (Request $request): bool {
                return $request->query('keyword') === 'match';
            }))
            ->andReturn(collect([
                ['id' => 1, 'content' => 'match'],
            ]));

        $tools = $this->makeTools($controller);
        $tool = $tools->getAllTools()->firstWhere('name', 'search_conversations');

        $result = $tool->execute(['keyword' => 'match']);

        $this->assertSame([
            ['id' => 1, 'content' => 'match'],
        ], $result);
    }

    public function test_get_recent_chats_sends_limit_as_query_parameter(): void
    {
        $controller = Mockery::mock(McpController::class);
        $controller->shouldReceive('recentChats')
            ->once()
            ->with(Mockery::on(function (Request $request): bool {
                return (int) $request->query('limit') === 7;
            }))
            ->andReturn(collect([
                ['id' => 'abc-123', 'status' => 'completed'],
            ]));

        $tools = $this->makeTools($controller);
        $tool = $tools->getAllTools()->firstWhere('name', 'get_recent_chats');

        $result = $tool->execute(['limit' => 7]);

        $this->assertSame([
            ['id' => 'abc-123', 'status' => 'completed'],
        ], $result);
    }

    public function test_get_conversation_schema_uses_string_identifier(): void
    {
        $tools = $this->makeTools();

        $tool = $tools->getAllTools()->firstWhere('name', 'get_conversation');

        $this->assertSame('string', $tool->parameters['properties']['conversation_id']['type']);
    }

    public function test_get_mcp_stats_normalizes_json_response_results(): void
    {
        $controller = Mockery::mock(McpController::class);
        $controller->shouldReceive('stats')
            ->once()
            ->andReturn(new JsonResponse([
                'conversations_count' => 10,
                'messages_count' => 25,
            ]));

        $tools = $this->makeTools($controller);
        $tool = $tools->getAllTools()->firstWhere('name', 'get_mcp_stats');

        $result = $tool->execute([]);

        $this->assertSame([
            'conversations_count' => 10,
            'messages_count' => 25,
        ], $result);
    }

    public function test_search_meta_bridge_defaults_to_claims_collection(): void
    {
        $metaBridgeSearch = Mockery::mock(MetaBridgeSearchService::class);
        $metaBridgeSearch->shouldReceive('searchClaims')
            ->once()
            ->with('nonphysical focus', 5)
            ->andReturn([
                ['score' => 0.81, 'payload' => ['canonical_statement' => 'Reality is a construct of focused attention.']],
            ]);

        $tools = $this->makeTools(null, $metaBridgeSearch);
        $tool = $tools->getAllTools()->firstWhere('name', 'search_meta_bridge');

        $result = $tool->execute(['query' => 'nonphysical focus']);

        $this->assertSame('claims', $result['collection']);
        $this->assertCount(1, $result['results']);
    }

    public function test_search_meta_bridge_routes_to_requested_collection_and_caps_limit(): void
    {
        $metaBridgeSearch = Mockery::mock(MetaBridgeSearchService::class);
        $metaBridgeSearch->shouldReceive('searchReflections')
            ->once()
            ->with('law of one density', 20)
            ->andReturn([]);

        $tools = $this->makeTools(null, $metaBridgeSearch);
        $tool = $tools->getAllTools()->firstWhere('name', 'search_meta_bridge');

        $result = $tool->execute([
            'query' => 'law of one density',
            'collection' => 'reflections',
            'limit' => 999,
        ]);

        $this->assertSame('reflections', $result['collection']);
        $this->assertSame([], $result['results']);
    }

    public function test_search_meta_bridge_requires_a_query(): void
    {
        $tools = $this->makeTools();
        $tool = $tools->getAllTools()->firstWhere('name', 'search_meta_bridge');

        $result = $tool->execute(['query' => '   ']);

        $this->assertArrayHasKey('error', $result);
    }
}
