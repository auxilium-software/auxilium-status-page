<?php

declare(strict_types=1);

namespace Auxilium\DataClasses;


final class ProbeResult
{
    public function __construct(
        public readonly bool    $reachable,
        public readonly int     $statusCode,
        public readonly float   $responseMs,
        public readonly ?string $body = null,
        public readonly ?string $error = null,
        public readonly ?int    $errorCode = null,
    )
    {
    }

    public function isHealthy(): bool
    {
        return $this->reachable && $this->statusCode >= 200 && $this->statusCode < 400;
    }
}
