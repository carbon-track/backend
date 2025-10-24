<?php

declare(strict_types=1);

<?php

declare(strict_types=1);

$path = __DIR__ . '/admin_ai_commands.json';

if (!is_file($path) || !is_readable($path)) {
    return [
        'navigationTargets' => [],
        'quickActions' => [],
        'managementActions' => [],
    ];
}

$contents = file_get_contents($path);

if ($contents === false) {
    return [
        'navigationTargets' => [],
        'quickActions' => [],
        'managementActions' => [],
    ];
}

return json_decode($contents, true, 512, JSON_THROW_ON_ERROR);

