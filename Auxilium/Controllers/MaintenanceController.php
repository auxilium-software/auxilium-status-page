<?php

declare(strict_types=1);

namespace Auxilium\Controllers;

use Auxilium\ServiceInteractions\SQLiteInteractions;
use DateTimeImmutable;
use DateTimeZone;
use InvalidArgumentException;

final class MaintenanceController
{
    public const STATUSES = ['scheduled', 'in_progress', 'completed', 'cancelled'];

    public function __construct(
        private readonly SQLiteInteractions $db,
    ) {}

    private static function NowUtc(): string
    {
        return (new DateTimeImmutable('now', new DateTimeZone('UTC')))->format('Y-m-d H:i:s');
    }

    public function CreateMaintenance(
        int    $userId,
        string $title,
        string $bodyHtml,
        string $status,
        string $startsAtUtc,
        string $endsAtUtc,
        array  $affectedServiceKeys,
    ): int
    {
        $this->GuardStatus($status);

        $now = self::NowUtc();

        return $this->db->transaction(
            function (SQLiteInteractions $db) use (
                $userId, $title, $bodyHtml, $status, $startsAtUtc, $endsAtUtc, $affectedServiceKeys, $now
            ): int
            {
                $maintenanceId = $db->query_insert(
                    "INSERT INTO maintenance (created_at_utc, created_by_user_id, title_text, body_html, status, starts_at_utc, ends_at_utc)
                            VALUES (:created_at, :user_id, :title, :body, :status, :starts_at, :ends_at);",
                    [
                        ':created_at' => $now,
                        ':user_id'    => $userId,
                        ':title'      => $title,
                        ':body'       => $bodyHtml,
                        ':status'     => $status,
                        ':starts_at'  => $startsAtUtc,
                        ':ends_at'    => $endsAtUtc,
                    ]
                );

                $this->InsertAffected($db, $userId, $maintenanceId, $affectedServiceKeys, $now);

                return $maintenanceId;
            }
        );
    }

    public function UpdateDetails(
        int    $maintenanceId,
        string $title,
        string $bodyHtml,
        string $startsAtUtc,
        string $endsAtUtc,
    ): void
    {
        $this->db->query_update(
            "UPDATE maintenance
                    SET title_text  = :title, body_html = :body, starts_at_utc = :starts_at, ends_at_utc   = :ends_at
                    WHERE id = :id;",
            [
                ':title'     => $title,
                ':body'      => $bodyHtml,
                ':starts_at' => $startsAtUtc,
                ':ends_at'   => $endsAtUtc,
                ':id'        => $maintenanceId,
            ]
        );
    }

    public function SetStatus(int $maintenanceId, string $status): void
    {
        $this->GuardStatus($status);

        $this->db->query_update(
            "UPDATE maintenance SET status = :status WHERE id = :id;",
            [
                ':status' => $status,
                ':id' => $maintenanceId,
            ]
        );
    }

    public function GetMaintenance(int $maintenanceId): ?array
    {
        return $this->db->query_read_one(
            "SELECT m.*, u.display_name AS created_by
                    FROM maintenance m
                    JOIN admin_users u ON u.id = m.created_by_user_id
                    WHERE m.id = :id;",
            [
                ':id' => $maintenanceId,
            ]
        );
    }

    public function GetRecentPastMaintenance(int $limit = 5): array
    {
        return $this->db->query_read(
            "SELECT m.id, m.title_text, m.status, m.starts_at_utc, m.ends_at_utc
                    FROM maintenance m
                    WHERE m.status IN ('completed', 'cancelled')
                    ORDER BY m.ends_at_utc DESC
                    LIMIT :limit;",
            [
                ':limit' => $limit,
            ]
        );
    }

    public function GetAffectedServiceKeys(int $maintenanceId): array
    {
        $rows = $this->db->query_read(
            "SELECT service_key FROM maintenance_affected_services WHERE maintenance_id = :id;",
            [
                ':id' => $maintenanceId,
            ]
        );

        return array_column($rows, 'service_key');
    }

    public function SetAffectedServices(int $userId, int $maintenanceId, array $serviceKeys): void
    {
        $now = self::NowUtc();

        $this->db->transaction(
            function (SQLiteInteractions $db) use ($userId, $maintenanceId, $serviceKeys, $now): void
            {
                $db->query_update(
                    "DELETE FROM maintenance_affected_services WHERE maintenance_id = :id;",
                    [
                        ':id' => $maintenanceId,
                    ]
                );

                $this->InsertAffected($db, $userId, $maintenanceId, $serviceKeys, $now);
            }
        );
    }

    public function PostUpdate(int $userId, int $maintenanceId, string $title, string $bodyHtml): int
    {
        return $this->db->query_insert(
            "INSERT INTO maintenance_updates
                    (created_at_utc, created_by_user_id, maintenance_id, title_text, body_html)
                    VALUES (:created_at, :user_id, :maintenance_id, :title, :body);",
            [
                ':created_at'     => self::NowUtc(),
                ':user_id'        => $userId,
                ':maintenance_id' => $maintenanceId,
                ':title'          => $title,
                ':body'           => $bodyHtml,
            ]
        );
    }

    public function GetUpdates(int $maintenanceId): array
    {
        return $this->db->query_read(
            "SELECT mu.*, u.display_name AS created_by
                    FROM maintenance_updates mu
                    JOIN admin_users u ON u.id = mu.created_by_user_id
                    WHERE mu.maintenance_id = :id
                    ORDER BY mu.created_at_utc DESC, mu.id DESC;",
            [
                ':id' => $maintenanceId,
            ]
        );
    }

    private function InsertAffected(
        SQLiteInteractions $db,
        int    $userId,
        int    $maintenanceId,
        array  $serviceKeys,
        string $now,
    ): void
    {
        foreach ($serviceKeys as $serviceKey)
        {
            $db->query_insert(
                "INSERT INTO maintenance_affected_services
                        (created_at_utc, created_by_user_id, maintenance_id, service_key)
                        VALUES (:created_at, :user_id, :maintenance_id, :service_key)",
                [
                    ':created_at'     => $now,
                    ':user_id'        => $userId,
                    ':maintenance_id' => $maintenanceId,
                    ':service_key'    => $serviceKey,
                ]
            );
        }
    }

    private function GuardStatus(string $status): void
    {
        if (!in_array($status, self::STATUSES, true))
        {
            throw new InvalidArgumentException("Invalid maintenance status: '$status'");
        }
    }
}
