<?php

declare(strict_types=1);

use Auxilium\ServiceInteractions\SQLiteInteractions;
use Auxilium\Utilities\ConfigurationUtilities;

require_once __DIR__ . '/../../../vendor/autoload.php';

$windowInDays = (int)(ConfigurationUtilities::GetUserConfiguration()["UserInterfaceSettings"]["WindowInDays"] ?? throw new Exception("Config element (Settings->WindowInDays) not found"));
$degradedMs = (int)(ConfigurationUtilities::GetUserConfiguration()["UserInterfaceSettings"]["DegradedResponseMsThreshold"] ?? throw new Exception("Config element (Settings->DegradedResponseMsThreshold) not found"));

$services = [
    'portal' => 'Portal',
    'api'    => 'API',
];

$db = new SQLiteInteractions();

$nowUtc   = new DateTimeImmutable('now', new DateTimeZone('UTC'));
$sinceUtc = $nowUtc->modify('-' . ($windowInDays - 1) . ' days')->format('Y-m-d 00:00:00');

$latestRows = $db->query_read(
    "SELECT sc.service_key, sc.is_healthy, sc.response_time_in_ms
       FROM service_checks sc
       JOIN (SELECT service_key, MAX(id) AS max_id FROM service_checks GROUP BY service_key) latest ON latest.max_id = sc.id;
");

$latest = [];
foreach ($latestRows as $row)
{
    $latest[$row['service_key']] = $row;
}

$dailyRows = $db->query_read(
    "SELECT service_key,
                date(checked_at_utc) AS day,
                COUNT(*)             AS total,
                SUM(is_healthy)      AS healthy
            FROM service_checks
            WHERE checked_at_utc >= :since
            GROUP BY service_key, date(checked_at_utc)
            ORDER BY day ASC",
    [
        ':since' => $sinceUtc,
    ]
);

$daysByService = [];
$windowTotals  = [];
foreach ($dailyRows as $row)
{
    $key     = $row['service_key'];
    $total   = (int) $row['total'];
    $healthy = (int) $row['healthy'];

    $status = $healthy === $total ? 'up' : ($healthy === 0 ? 'down' : 'degraded');

    $daysByService[$key][] = [
        'date'   => $row['day'],
        'status' => $status,
        'uptime' => $total > 0 ? round($healthy / $total * 100, 2) : 0.0,
    ];

    $windowTotals[$key]['total']   = ($windowTotals[$key]['total']   ?? 0) + $total;
    $windowTotals[$key]['healthy'] = ($windowTotals[$key]['healthy'] ?? 0) + $healthy;
}

$monitors = [];
foreach ($services as $key => $prettyName)
{
    $current = 'nodata';
    if (isset($latest[$key]))
    {
        $isHealthy = (int) $latest[$key]['is_healthy'] === 1;
        $ms        = (float) $latest[$key]['response_time_in_ms'];
        $current   = !$isHealthy ? 'down' : ($ms > $degradedMs ? 'degraded' : 'up');
    }

    $totals = $windowTotals[$key] ?? ['total' => 0, 'healthy' => 0];
    $uptime = $totals['total'] > 0
        ? round($totals['healthy'] / $totals['total'] * 100, 2)
        : null;

    $monitors[$key] = [
        'prettyName'    => $prettyName,
        'currentStatus' => $current,
        'uptimeOverWindowInDays' => $uptime,
        'days'          => $daysByService[$key] ?? [],
    ];
}

$payload = [
    'generatedAt' => $nowUtc->format('Y-m-d\TH:i:s\Z'),
    'timezone'    => 'UTC',
    'windowDays' => $windowInDays,
    'monitors'    => $monitors,
    'incidents'   => [],
    'maintenance' => [
        'upcoming'   => [],
        'historical' => [],
    ],
];

header('Content-Type: application/json; charset=utf-8');
echo json_encode($payload, JSON_THROW_ON_ERROR);
