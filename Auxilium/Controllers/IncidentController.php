<?php

declare(strict_types=1);

namespace Auxilium\Controllers;

use Auxilium\ServiceInteractions\SQLiteInteractions;
use DateTimeImmutable;
use DateTimeZone;
use InvalidArgumentException;

final class IncidentController
{
    public const IMPACTS  = ['none', 'minor', 'major', 'critical'];
    public const STATUSES = ['investigating', 'identified', 'monitoring', 'resolved'];

    public function __construct(
        private readonly SQLiteInteractions $db,
    ) {}

    private static function NowUtc(): string
    {
        return (new DateTimeImmutable('now', new DateTimeZone('UTC')))->format('Y-m-d H:i:s');
    }

    public function CreateIncident(
        int     $userId,
        string  $title,
        string  $bodyHtml,
        string  $impact,
        string  $status,
        array   $affectedServiceKeys,
        ?string $startedAtUtc = null,
    ): int
    {
        $this->GuardImpact($impact);
        $this->GuardStatus($status);

        $now       = self::NowUtc();
        $startedAt = $startedAtUtc ?? $now;

        // Declaring an incident that is already resolved is legitimate (writing up
        // something short after the fact), so honour that rather than forbidding it.
        $resolvedAt = $status === 'resolved' ? $now : null;

        return $this->db->transaction(
            function (SQLiteInteractions $db) use (
                $userId, $title, $bodyHtml, $impact, $status,
                $affectedServiceKeys, $now, $startedAt, $resolvedAt
            ): int
            {
                $incidentId = $db->query_insert(
                    "INSERT INTO incidents (created_at_utc, created_by_user_id, title_text, body_text, impact, status, started_at_utc, resolved_at_utc)
                            VALUES (:created_at, :user_id, :title, :body, :impact, :status, :started_at, :resolved_at);",
                    [
                        ':created_at'  => $now,
                        ':user_id'     => $userId,
                        ':title'       => $title,
                        ':body'        => $bodyHtml,
                        ':impact'      => $impact,
                        ':status'      => $status,
                        ':started_at'  => $startedAt,
                        ':resolved_at' => $resolvedAt,
                    ]
                );

                foreach ($affectedServiceKeys as $serviceKey)
                {
                    $db->query_insert(
                        "INSERT INTO incident_affected_services (created_at_utc, created_by_user_id, incident_id, service_key)
                                VALUES (:created_at, :user_id, :incident_id, :service_key);",
                        [
                            ':created_at'  => $now,
                            ':user_id'     => $userId,
                            ':incident_id' => $incidentId,
                            ':service_key' => $serviceKey,
                        ]
                    );
                }

                // The opening body becomes the first entry in the thread, so the
                // public timeline reads as a complete narrative from the start.
                $db->query_insert(
                    "INSERT INTO incident_updates
                        (created_at_utc, created_by_user_id, incident_id, status, title_text, body_text)
                     VALUES (:created_at, :user_id, :incident_id, :status, :title, :body);",
                    [
                        ':created_at'  => $now,
                        ':user_id'     => $userId,
                        ':incident_id' => $incidentId,
                        ':status'      => $status,
                        ':title'       => $title,
                        ':body'        => $bodyHtml,
                    ]
                );

                return $incidentId;
            }
        );
    }

    public function PostUpdate(
        int    $userId,
        int    $incidentId,
        string $status,
        string $title,
        string $bodyHtml,
    ): int
    {
        $this->GuardStatus($status);

        $now = self::NowUtc();

        return $this->db->transaction(
            function (SQLiteInteractions $db) use ($userId, $incidentId, $status, $title, $bodyHtml, $now): int
            {
                $updateId = $db->query_insert(
                    "INSERT INTO incident_updates (created_at_utc, created_by_user_id, incident_id, status, title_text, body_text)
                            VALUES (:created_at, :user_id, :incident_id, :status, :title, :body);",
                    [
                        ':created_at'  => $now,
                        ':user_id'     => $userId,
                        ':incident_id' => $incidentId,
                        ':status'      => $status,
                        ':title'       => $title,
                        ':body'        => $bodyHtml,
                    ]
                );

                // Reopening a resolved incident clears resolved_at_utc again -
                // "we thought it was fixed, it wasn't" is a real thing that happens.
                $db->query_update(
                    "UPDATE incidents
                            SET status = :status,
                            resolved_at_utc = CASE
                                WHEN :status = 'resolved' THEN COALESCE(resolved_at_utc, :now)
                                ELSE NULL
                            END
                            WHERE id = :incident_id;",
                    [
                        ':status'      => $status,
                        ':now'         => $now,
                        ':incident_id' => $incidentId,
                    ]
                );

                return $updateId;
            }
        );
    }

    public function GetIncident(int $incidentId): ?array
    {
        return $this->db->query_read_one(
            "SELECT i.*, u.display_name AS created_by
                    FROM incidents i
                    JOIN admin_users u ON u.id = i.created_by_user_id
                    WHERE i.id = :id;",
            [
                ':id' => $incidentId,
            ]
        );
    }

    public function GetUpdates(int $incidentId): array
    {
        return $this->db->query_read(
            "SELECT iu.*, u.display_name AS created_by
                    FROM incident_updates iu
                    JOIN admin_users u ON u.id = iu.created_by_user_id
                    WHERE iu.incident_id = :id
                    ORDER BY iu.created_at_utc DESC, iu.id DESC;",
            [
                ':id' => $incidentId,
            ]
        );
    }

    public function GetAffectedServiceKeys(int $incidentId): array
    {
        $rows = $this->db->query_read(
            "SELECT service_key FROM incident_affected_services WHERE incident_id = :id;",
            [
                ':id' => $incidentId,
            ]
        );

        return array_column($rows, 'service_key');
    }

    public function SetAffectedServices(int $userId, int $incidentId, array $serviceKeys): void
    {
        $now = self::NowUtc();

        $this->db->transaction(
            function (SQLiteInteractions $db) use ($userId, $incidentId, $serviceKeys, $now): void
            {
                $db->query_update(
                    "DELETE FROM incident_affected_services WHERE incident_id = :id;",
                    [
                        ':id' => $incidentId,
                    ]
                );

                foreach ($serviceKeys as $serviceKey)
                {
                    $db->query_insert(
                        "INSERT INTO incident_affected_services (created_at_utc, created_by_user_id, incident_id, service_key)
                                VALUES (:created_at, :user_id, :incident_id, :service_key);",
                        [
                            ':created_at'  => $now,
                            ':user_id'     => $userId,
                            ':incident_id' => $incidentId,
                            ':service_key' => $serviceKey,
                        ]
                    );
                }
            }
        );
    }

    private function GuardImpact(string $impact): void
    {
        if (!in_array($impact, self::IMPACTS, true))
        {
            throw new InvalidArgumentException("Invalid impact: '$impact'");
        }
    }

    private function GuardStatus(string $status): void
    {
        if (!in_array($status, self::STATUSES, true))
        {
            throw new InvalidArgumentException("Invalid status: '$status'");
        }
    }
}
