<?php

return [
    // Read-only mount of the Cerner MappingReport extracts (public/mr_data in legacy).
    'mr_data_path' => env('MR_DATA_PATH', '/var/www/mr_data'),

    // Directory (as seen by the Postgres container) holding Athena downloads.
    'vocab_data_path' => env('VOCAB_DATA_PATH', '/vocab_data'),

    // Hours after which a term claim is considered stale and can be taken over.
    'claim_ttl_hours' => (int) env('COMET_CLAIM_TTL_HOURS', 4),

    // Hybrid concept-search tuning (see App\Services\ConceptSearch).
    'search' => [
        'trigram_threshold' => (float) env('SEARCH_TRIGRAM_THRESHOLD', 0.25),
        'tsrank_scale' => (float) env('SEARCH_TSRANK_SCALE', 4.0),
        'tsrank_cap' => (float) env('SEARCH_TSRANK_CAP', 0.95),
    ],

    // Modernization milestones shown on the welcome screen. Status is one of
    // 'done' | 'in_progress' | 'planned'. Update as the rebuild progresses.
    'milestones' => [
        ['id' => 'M0', 'title' => 'Skeleton, Docker stack, SSO & RBAC', 'status' => 'done'],
        ['id' => 'M1', 'title' => 'Postgres schema, models, legacy migration, vocab loader', 'status' => 'done'],
        ['id' => 'M2', 'title' => 'Read paths — dashboards, mapping grid, domain views', 'status' => 'done'],
        ['id' => 'M3', 'title' => 'Mapping editor — concept search, guardrails, propagation, audit', 'status' => 'done'],
        ['id' => 'M4', 'title' => 'MappingReport import — queued job with run audit', 'status' => 'done'],
        ['id' => 'M5', 'title' => 'Review & approval workflow with snapshots', 'status' => 'done'],
        ['id' => 'M6', 'title' => 'Exports (STCM/exclusions), releases & user admin', 'status' => 'done'],
        ['id' => 'M7', 'title' => 'Vocabulary impact report & cutover parity', 'status' => 'done'],
        ['id' => 'P1', 'title' => 'Hybrid ranked search, hierarchy & concept browser', 'status' => 'done'],
        ['id' => 'P2', 'title' => 'Auto-mapper candidates, keyboard mapping mode, equivalence', 'status' => 'done'],
        ['id' => 'P3', 'title' => 'Canadian vocabulary converters (CIHI/Infoway)', 'status' => 'done'],
        ['id' => 'P4', 'title' => 'Team workflow — claims, review loop, dashboard', 'status' => 'done'],
    ],
];
