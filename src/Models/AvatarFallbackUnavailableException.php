<?php

declare(strict_types=1);

namespace CarbonTrack\Models;

class AvatarFallbackUnavailableException extends \RuntimeException
{
    public function __construct()
    {
        parent::__construct('Cannot disable avatar without an active default avatar for fallback');
    }
}
