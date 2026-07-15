<?php


declare(strict_types=1);

namespace Auxilium\Controllers;

use Auxilium\ServiceInteractions\SQLiteInteractions;
use DateTimeImmutable;
use DateTimeZone;

final class ServiceController
{
    public function __construct()
    {
    }

    public static function GetServices(): array
    {
        return [
            'portal' => 'Auxilium|3 Portal',
            'api'    => 'Auxilium|3 API',
        ];
    }
}
