<?php
return [
    'name' => 'MaterCMS',
    'timezone' => 'Asia/Almaty',
    'db_path' => __DIR__ . '/data/matercms.sqlite',
    'cors_origin' => '*',
    // auto: pretty URLs on Apache/LiteSpeed, direct index.php?path=... on nginx/other servers.
    'api_route_mode' => 'auto',
    'upload_max_mb' => 100,
    'form_upload_max_mb' => 20,
    'revision_limit' => 20,
    'revision_group_seconds' => 30,
    'autosave_delay_ms' => 1100,
];
