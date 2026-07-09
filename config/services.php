<?php

return [

    /*
    |--------------------------------------------------------------------------
    | Third Party Services
    |--------------------------------------------------------------------------
    |
    | This file is for storing the credentials for third party services such
    | as Mailgun, Postmark, AWS and more. This file provides the de facto
    | location for this type of information, allowing packages to have
    | a conventional file to locate the various service credentials.
    |
    */

    'postmark' => [
        'key' => env('POSTMARK_API_KEY'),
    ],

    'resend' => [
        'key' => env('RESEND_API_KEY'),
    ],

    'ses' => [
        'key' => env('AWS_ACCESS_KEY_ID'),
        'secret' => env('AWS_SECRET_ACCESS_KEY'),
        'region' => env('AWS_DEFAULT_REGION', 'us-east-1'),
    ],

    'slack' => [
        'notifications' => [
            'bot_user_oauth_token' => env('SLACK_BOT_USER_OAUTH_TOKEN'),
            'channel' => env('SLACK_BOT_USER_DEFAULT_CHANNEL'),
        ],
    ],

    'openai' => [
        'key' => env('OPENAI_API_KEY'),
        'model' => env('OPENAI_MODEL', 'gpt-4o-mini'),
    ],

    'anthropic' => [
        'key' => env('ANTHROPIC_API_KEY'),
        'model' => env('ANTHROPIC_MODEL', 'claude-sonnet-4-5-20250929'),
    ],

    'deepseek' => [
        'key' => env('DEEPSEEK_API_KEY'),
        'model' => env('DEEPSEEK_MODEL', 'deepseek-chat'),
    ],

    'openrouter' => [
        'key' => env('OPENROUTER_API_KEY'),
        'model' => env('OPENROUTER_MODEL', 'openai/gpt-4o-mini'),
        'app_name' => env('OPENROUTER_APP_NAME', 'Chat Bridge'),
        'referer' => env('OPENROUTER_REFERER', 'https://github.com/meistro57/chat_bridge'),
        'embedding_model' => env('OPENROUTER_EMBEDDING_MODEL', 'google/gemini-embedding-001'),
    ],

    'gemini' => [
        'key' => env('GEMINI_API_KEY'),
        'model' => env('GEMINI_MODEL', 'gemini-2.5-flash'),
    ],

    'bedrock' => [
        'access_key_id' => env('AWS_ACCESS_KEY_ID'),
        'secret_access_key' => env('AWS_SECRET_ACCESS_KEY'),
        'session_token' => env('AWS_SESSION_TOKEN'),
        'region' => env('AWS_DEFAULT_REGION', 'us-east-1'),
        'model' => env('BEDROCK_MODEL', 'anthropic.claude-3-7-sonnet-20250219-v1:0'),
        'runtime_base_url' => env('BEDROCK_RUNTIME_BASE_URL'),
    ],

    'ollama' => [
        'host' => env('OLLAMA_HOST', 'http://localhost:11434'),
        'model' => env('OLLAMA_MODEL', 'llama3.1'),
    ],

    'lmstudio' => [
        'base_url' => env('LMSTUDIO_BASE_URL', 'http://localhost:1234/v1'),
        'model' => env('LMSTUDIO_MODEL', 'local-model'),
    ],

    'codex' => [
        'home' => env('CODEX_HOME', base_path('.codex')),
        'log_recent_minutes' => env('CODEX_LOG_RECENT_MINUTES', 120),
    ],

    'qdrant' => [
        'host' => env('QDRANT_HOST', 'localhost'),
        'port' => env('QDRANT_PORT', 6333),
        'enabled' => env('QDRANT_ENABLED', true),
    ],

    /*
    |--------------------------------------------------------------------------
    | Embedding Vector Size
    |--------------------------------------------------------------------------
    |
    | Single source of truth for the embedding vector dimension used across
    | RagService (chat_messages collection) and the zero-vector fallback in
    | EmbeddingService. Must match whatever the configured embedding model
    | actually returns. 3072 = gemini-embedding-001, the standard shared with
    | meta-bridge and FrontPocket.
    |
    */

    'embedding_dimension' => (int) env('EMBEDDING_VECTOR_SIZE', 3072),

    /*
    |--------------------------------------------------------------------------
    | Meta Bridge — Read-Only Cross-Collection RAG
    |--------------------------------------------------------------------------
    |
    | Meta Bridge (github.com/meistro57/meta-bridge) ingests consciousness
    | literature into its own Qdrant collections on this same Qdrant instance.
    | Chat Bridge never writes to these — it only searches them via
    | MetaBridgeSearchService, using the same gemini-embedding-001 vector
    | space so query vectors land in the right neighborhood.
    |
    */

    'meta_bridge' => [
        'collection_claims' => env('MB_QDRANT_COLLECTION_CLAIMS', 'mb_claims'),
        'collection_chunks' => env('MB_QDRANT_COLLECTION_CHUNKS', 'mb_chunks'),
        'collection_sources' => env('MB_QDRANT_COLLECTION_SOURCES', 'mb_sources'),
        'collection_reflections' => env('MB_QDRANT_COLLECTION_REFLECTIONS', 'meta_reflections'),
        'collection_misfit_reports' => env('MB_QDRANT_COLLECTION_MISFIT_REPORTS', 'misfit_reports'),
        // meta_reflections and misfit_reports both use named vectors (summary_vec,
        // claims_vec); mb_claims/mb_chunks/mb_sources use a single unnamed vector.
        'reflection_vector' => env('MB_QDRANT_REFLECTION_VECTOR', 'summary_vec'),
        'misfit_reports_vector' => env('MB_QDRANT_MISFIT_REPORTS_VECTOR', 'summary_vec'),
        'score_threshold' => (float) env('MB_QDRANT_SCORE_THRESHOLD', 0.5),
    ],

    'chat_bridge' => [
        'token' => env('CHAT_BRIDGE_TOKEN'),
        'history_limit' => env('CHAT_BRIDGE_HISTORY_LIMIT', 120),
    ],

];
