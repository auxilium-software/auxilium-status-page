<?php


declare(strict_types=1);

namespace Auxilium\Controllers;

use Auxilium\ServiceInteractions\SQLiteInteractions;
use DateTimeImmutable;
use DateTimeZone;

final class StatusController
{
    public function __construct(
        private readonly SQLiteInteractions $db,
    )
    {
    }

    private static function NowUtc(): string
    {
        return (new DateTimeImmutable('now', new DateTimeZone('UTC')))->format('Y-m-d H:i:s');
    }

    public function GetCurrentServiceStates(int $degradedMs): array
    {
        $rows = $this->db->query_read(
            "SELECT sc.service_key, sc.checked_at_utc, sc.is_healthy, sc.response_time_in_ms, sc.status_code, sc.error_message
                FROM service_checks sc
                JOIN (SELECT service_key, MAX(id) AS max_id FROM service_checks
                GROUP BY service_key) latest
                ON latest.max_id = sc.id;"
        );

        $latest = [];
        foreach ($rows as $row) {
            $latest[$row['service_key']] = $row;
        }

        $states = [];
        foreach (ServiceController::GetServices() as $key => $prettyName) {
            $row = $latest[$key] ?? null;

            if ($row === null) {
                $states[] = [
                    'serviceKey' => $key,
                    'prettyName' => $prettyName,
                    'status' => 'nodata',
                    'checkedAtUtc' => null,
                    'responseMs' => null,
                    'statusCode' => null,
                    'errorMessage' => null,
                    'isStale' => true,
                ];
                continue;
            }

            $isHealthy = (int)$row['is_healthy'] === 1;
            $ms = (float)$row['response_time_in_ms'];

            // if the check is older than 5 minutes ago, it's safe to say that the poller cronjob has stopped
            $isStale = (time() - strtotime($row['checked_at_utc'] . ' UTC')) > 300;

            $states[] = [
                'serviceKey' => $key,
                'prettyName' => $prettyName,
                'status' => !$isHealthy ? 'down' : ($ms > $degradedMs ? 'degraded' : 'up'),
                'checkedAtUtc' => $row['checked_at_utc'],
                'responseMs' => round($ms),
                'statusCode' => (int)$row['status_code'],
                'errorMessage' => $row['error_message'],
                'isStale' => $isStale,
            ];
        }

        return $states;
    }

    public function GetOngoingIncidents(): array
    {
        return $this->db->query_read(
            "SELECT i.id, i.title_text, i.impact, i.status, i.started_at_utc, u.display_name AS created_by,
                    (SELECT COUNT(*) FROM incident_updates iu WHERE iu.incident_id = i.id) AS update_count,
                    (SELECT GROUP_CONCAT(ias.service_key, ', ')  FROM incident_affected_services ias WHERE ias.incident_id = i.id) AS affected_keys
                    FROM incidents i
                    JOIN admin_users u ON u.id = i.created_by_user_id
                    WHERE i.resolved_at_utc IS NULL
                    ORDER BY i.started_at_utc DESC;"
        );
    }

    public function GetRecentResolvedIncidents(int $limit = 5): array
    {
        return $this->db->query_read(
            "SELECT i.id, i.title_text, i.impact, i.status, i.started_at_utc, i.resolved_at_utc, u.display_name AS created_by
                    FROM incidents i
                    JOIN admin_users u ON u.id = i.created_by_user_id
                    WHERE i.resolved_at_utc IS NOT NULL
                    ORDER BY i.resolved_at_utc DESC
                    LIMIT :limit;",
            [
                ':limit' => $limit,
            ]
        );
    }

    public function GetActiveMaintenance(): array
    {
        return $this->db->query_read(
            "SELECT m.id, m.title_text, m.status, m.starts_at_utc, m.ends_at_utc, u.display_name AS created_by,
                    (SELECT GROUP_CONCAT(mas.service_key, ', ') FROM maintenance_affected_services mas WHERE mas.maintenance_id = m.id) AS affected_keys
                    FROM maintenance m
                    JOIN admin_users u ON u.id = m.created_by_user_id
                    WHERE m.status IN ('scheduled', 'in_progress')
                    ORDER BY m.starts_at_utc;"
        );
    }

    public function GetLastCheckTimeUtc(): ?string
    {
        return $this->db->query_scalar("SELECT MAX(checked_at_utc) FROM service_checks;");
    }

    public function GetCheckCount(): int
    {
        return (int)$this->db->query_scalar("SELECT COUNT(*) FROM service_checks;");
    }
}
