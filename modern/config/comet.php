<?php

return [
    // Read-only mount of the Cerner MappingReport extracts (public/mr_data in legacy).
    'mr_data_path' => env('MR_DATA_PATH', '/var/www/mr_data'),

    // Directory (as seen by the Postgres container) holding Athena downloads.
    'vocab_data_path' => env('VOCAB_DATA_PATH', '/vocab_data'),
];
