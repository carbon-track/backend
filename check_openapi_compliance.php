<?php

declare(strict_types=1);

fwrite(
    STDOUT,
    "check_openapi_compliance.php is deprecated.\n" .
    "Use backend/enhanced_openapi_check.php for exact /api/v1 runtime alignment, handler validation, and catch-all exclusion.\n\n"
);

require __DIR__ . '/enhanced_openapi_check.php';
