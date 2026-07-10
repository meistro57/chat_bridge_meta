<?php

namespace App\Http\Controllers;

use App\Models\Conversation;
use App\Models\Message;
use App\Services\MetaBridge\MetaBridgeSearchService;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\Log;

class McpController extends Controller
{
    /**
     * Handle the MCP JSON-RPC request.
     */
    public function handle(Request $request)
    {
        $payload = $request->json()->all();

        // Handle batch requests
        if (array_is_list($payload)) {
            $responses = array_map(fn ($req) => $this->processRequest($req), $payload);

            return response()->json($responses);
        }

        return response()->json($this->processRequest($payload));
    }

    /**
     * Process a single JSON-RPC request.
     */
    protected function processRequest(array $request)
    {
        $id = $request['id'] ?? null;
        $method = $request['method'] ?? null;
        $params = $request['params'] ?? [];

        if (! $method) {
            return $this->error($id, -32600, 'Invalid Request');
        }

        try {
            switch ($method) {
                case 'initialize':
                    return $this->initialize($id, $params);
                case 'tools/list':
                    return $this->listTools($id);
                case 'tools/call':
                    return $this->callTool($id, $params);
                default:
                    return $this->error($id, -32601, 'Method not found');
            }
        } catch (\Exception $e) {
            Log::error('MCP Error', ['error' => $e->getMessage(), 'method' => $method]);

            return $this->error($id, -32603, 'Internal error: '.$e->getMessage());
        }
    }

    /**
     * Handle the initialize method.
     */
    protected function initialize($id, array $params)
    {
        return [
            'jsonrpc' => '2.0',
            'id' => $id,
            'result' => [
                'protocolVersion' => '2024-11-05',
                'capabilities' => [
                    'tools' => (object) [],
                ],
                'serverInfo' => [
                    'name' => 'chat-bridge',
                    'version' => '1.0.0',
                ],
            ],
        ];
    }

    /**
     * Handle the tools/list method.
     */
    protected function listTools($id)
    {
        return [
            'jsonrpc' => '2.0',
            'id' => $id,
            'result' => [
                'tools' => [
                    [
                        'name' => 'search_chats',
                        'description' => 'Search conversations/messages by keyword, returns matches with context',
                        'inputSchema' => [
                            'type' => 'object',
                            'properties' => [
                                'keyword' => ['type' => 'string'],
                            ],
                            'required' => ['keyword'],
                        ],
                    ],
                    [
                        'name' => 'recent_chats',
                        'description' => 'Returns N most recent conversations with metadata',
                        'inputSchema' => [
                            'type' => 'object',
                            'properties' => [
                                'limit' => ['type' => 'integer', 'default' => 10],
                            ],
                        ],
                    ],
                    [
                        'name' => 'get_conversation',
                        'description' => 'Returns full message history for a given conversation ID',
                        'inputSchema' => [
                            'type' => 'object',
                            'properties' => [
                                'conversation_id' => ['type' => 'string'],
                            ],
                            'required' => ['conversation_id'],
                        ],
                    ],
                    [
                        'name' => 'get_stats',
                        'description' => 'Returns counts of conversations, messages, embeddings',
                        'inputSchema' => [
                            'type' => 'object',
                            'properties' => [],
                        ],
                    ],
                    [
                        'name' => 'search_vectoreology_findings',
                        'description' => 'Search Vectoreologist topology findings (clusters, bridges, moats, anomalies) mined from the meta-bridge consciousness-literature corpus. Filter by type, anomaly flag, and/or min confidence, with an optional keyword match against subject/reasoning_chain.',
                        'inputSchema' => [
                            'type' => 'object',
                            'properties' => [
                                'query' => ['type' => 'string', 'description' => 'Keyword to match against subject/reasoning_chain (optional)'],
                                'limit' => ['type' => 'integer', 'default' => 10],
                                'type' => ['type' => 'string', 'description' => "e.g. 'cluster_analysis', 'density_anomaly'"],
                                'is_anomaly' => ['type' => 'boolean'],
                                'min_confidence' => ['type' => 'number'],
                            ],
                        ],
                    ],
                ],
            ],
        ];
    }

    /**
     * Handle the tools/call method.
     */
    protected function callTool($id, array $params)
    {
        $name = $params['name'] ?? null;
        $arguments = $params['arguments'] ?? [];

        if (! $name) {
            return $this->error($id, -32602, 'Invalid params: name is required');
        }

        $result = match ($name) {
            'search_chats' => $this->searchChats($arguments),
            'recent_chats' => $this->recentChats($arguments),
            'get_conversation' => $this->getConversation($arguments),
            'get_stats' => $this->getStats(),
            'search_vectoreology_findings' => $this->searchVectoreologyFindings($arguments),
            default => null
        };

        if ($result === null) {
            return $this->error($id, -32601, 'Tool not found: '.$name);
        }

        return [
            'jsonrpc' => '2.0',
            'id' => $id,
            'result' => [
                'content' => [
                    [
                        'type' => 'text',
                        'text' => json_encode($result, JSON_PRETTY_PRINT),
                    ],
                ],
            ],
        ];
    }

    // --- Tool Implementations ---

    protected function searchChats(array $args)
    {
        $keyword = $args['keyword'] ?? '';
        if (! $keyword) {
            return ['error' => 'Keyword is required'];
        }

        return Message::where('content', 'like', "%{$keyword}%")
            ->with(['conversation', 'persona'])
            ->latest()
            ->limit(10)
            ->get()
            ->map(fn ($m) => [
                'id' => $m->id,
                'conversation_id' => $m->conversation_id,
                'role' => $m->role,
                'content' => $m->content,
                'persona' => $m->persona?->name,
                'created_at' => $m->created_at->toDateTimeString(),
            ]);
    }

    protected function recentChats(array $args)
    {
        $limit = $args['limit'] ?? 10;

        return Conversation::latest()
            ->limit($limit)
            ->get()
            ->map(fn ($c) => [
                'id' => $c->id,
                'persona_a' => $c->personaA?->name,
                'persona_b' => $c->personaB?->name,
                'status' => $c->status,
                'created_at' => $c->created_at->toDateTimeString(),
            ]);
    }

    protected function getConversation(array $args)
    {
        $id = $args['conversation_id'] ?? null;
        if (! $id) {
            return ['error' => 'conversation_id is required'];
        }

        $conversation = Conversation::with('messages.persona')->find($id);
        if (! $conversation) {
            return ['error' => 'Conversation not found'];
        }

        return [
            'id' => $conversation->id,
            'persona_a' => $conversation->personaA?->name,
            'persona_b' => $conversation->personaB?->name,
            'messages' => $conversation->messages->map(fn ($m) => [
                'role' => $m->role,
                'content' => $m->content,
                'persona' => $m->persona?->name,
                'created_at' => $m->created_at->toDateTimeString(),
            ]),
        ];
    }

    protected function getStats()
    {
        return [
            'conversations_count' => Conversation::count(),
            'messages_count' => Message::count(),
            'embeddings_count' => Message::whereNotNull('embedding')->count(),
        ];
    }

    protected function searchVectoreologyFindings(array $args)
    {
        $service = app(MetaBridgeSearchService::class);

        return $service->searchVectoreologyFindings(
            query: (string) ($args['query'] ?? ''),
            limit: (int) ($args['limit'] ?? 10),
            type: isset($args['type']) ? (string) $args['type'] : null,
            isAnomaly: array_key_exists('is_anomaly', $args) ? (bool) $args['is_anomaly'] : null,
            minConfidence: isset($args['min_confidence']) ? (float) $args['min_confidence'] : null,
        );
    }

    // --- Helpers ---

    protected function error($id, int $code, string $message)
    {
        return [
            'jsonrpc' => '2.0',
            'id' => $id,
            'error' => [
                'code' => $code,
                'message' => $message,
            ],
        ];
    }
}
