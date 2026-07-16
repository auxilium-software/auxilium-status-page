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

$toIso = static function (?string $utc): ?string {
    if ($utc === null || $utc === '') {
        return null;
    }

    $dt = DateTimeImmutable::createFromFormat('Y-m-d H:i:s', $utc, new DateTimeZone('UTC'));

    return $dt === false ? null : $dt->format('Y-m-d\TH:i:s\Z');
};

$inClause = static function (array $ids, string $prefix = 'id'): array {
    $placeholders = [];
    $params = [];

    foreach (array_values($ids) as $index => $id) {
        $placeholder = ":{$prefix}{$index}";
        $placeholders[] = $placeholder;
        $params[$placeholder] = (int)$id;
    }

    return [implode(', ', $placeholders), $params];
};

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
    "SELECT service_key, date(checked_at_utc) AS day, COUNT(*) AS total, SUM(is_healthy) AS healthy
            FROM service_checks
            WHERE checked_at_utc >= :since
            GROUP BY service_key, date(checked_at_utc)
            ORDER BY day;",
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

$incidentRows = $db->query_read(
    "SELECT id, title_text, impact, status, started_at_utc, resolved_at_utc
            FROM incidents
            WHERE resolved_at_utc IS NULL OR started_at_utc  >= :since OR resolved_at_utc >= :since
            ORDER BY started_at_utc DESC;",
    [
        ':since' => $sinceUtc,
    ]
);

$incidentIds = array_map(static fn(array $r): int => (int)$r['id'], $incidentRows);
$updatesByIncident = [];
$affectedByIncident = [];

if ($incidentIds !== []) {
    [$clause, $params] = $inClause($incidentIds);

    $updateRows = $db->query_read(
        "SELECT incident_id, status, title_text, body_html, created_at_utc
                FROM incident_updates
                WHERE incident_id IN ($clause)
                ORDER BY created_at_utc DESC, id DESC;",
        $params
    );

    foreach ($updateRows as $update) {
        $updatesByIncident[(int)$update['incident_id']][] = [
            'postedAt' => $toIso($update['created_at_utc']),
            'status' => $update['status'],
            'title' => $update['title_text'],
            'body' => $update['body_html'],
        ];
    }

    $affectedRows = $db->query_read(
        "SELECT incident_id, service_key
                FROM incident_affected_services
                WHERE incident_id IN ($clause);",
        $params
    );

    foreach ($affectedRows as $affected) {
        $affectedByIncident[(int)$affected['incident_id']][] = $affected['service_key'];
    }
}

$incidents = [];
foreach ($incidentRows as $row) {
    $id = (int)$row['id'];

    $incidents[] = [
        'id' => $id,
        'title' => $row['title_text'],
        'impact' => $row['impact'],
        'status' => $row['status'],
        'startedAt' => $toIso($row['started_at_utc']),
        'resolvedAt' => $toIso($row['resolved_at_utc']),
        'affected' => $affectedByIncident[$id] ?? [],
        'updates' => $updatesByIncident[$id] ?? [],
    ];
}

$upcomingRows = $db->query_read(
    "SELECT id, title_text, body_html, status, starts_at_utc, ends_at_utc
            FROM maintenance
            WHERE status IN ('scheduled', 'in_progress')
            ORDER BY starts_at_utc;"
);

$historicalRows = $db->query_read(
    "SELECT id, title_text, body_html, status, starts_at_utc, ends_at_utc
            FROM maintenance
            WHERE status = 'completed' AND ends_at_utc >= :since
            ORDER BY ends_at_utc DESC",
    [
        ':since' => $sinceUtc,
    ]
);

$maintenanceIds = array_merge(
    array_map(static fn(array $r): int => (int)$r['id'], $upcomingRows),
    array_map(static fn(array $r): int => (int)$r['id'], $historicalRows),
);

$affectedByMaintenance = [];

if ($maintenanceIds !== []) {
    [$clause, $params] = $inClause($maintenanceIds);

    $affectedRows = $db->query_read(
        "SELECT maintenance_id, service_key
                FROM maintenance_affected_services
                WHERE maintenance_id IN ($clause);",
        $params
    );

    foreach ($affectedRows as $affected) {
        $affectedByMaintenance[(int)$affected['maintenance_id']][] = $affected['service_key'];
    }
}

$buildMaintenance = static function (array $row) use ($toIso, $affectedByMaintenance): array {
    $id = (int)$row['id'];

    return [
        'id' => $id,
        'title' => $row['title_text'],
        'body' => $row['body_html'],
        'status' => $row['status'],
        'startsAt' => $toIso($row['starts_at_utc']),
        'endsAt' => $toIso($row['ends_at_utc']),
        'affected' => $affectedByMaintenance[$id] ?? [],
    ];
};

$maintenance = [
    'upcoming' => array_map($buildMaintenance, $upcomingRows),
    'historical' => array_map($buildMaintenance, $historicalRows),
];

header('Content-Type: application/json; charset=utf-8');
echo json_encode([
    'generatedAt' => $nowUtc->format('Y-m-d\TH:i:s\Z'),
    'timezone'    => 'UTC',
    'windowDays' => $windowInDays,
    'monitors'    => $monitors,
    'incidents' => $incidents,
    'maintenance' => $maintenance,
], JSON_PRETTY_PRINT|JSON_THROW_ON_ERROR);
