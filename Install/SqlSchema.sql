BEGIN TRANSACTION;
DROP TABLE IF EXISTS "admin_users";
CREATE TABLE "admin_users" (
	"id"	INTEGER,
	"created_at_utc"	TEXT NOT NULL,
	"username"	TEXT NOT NULL UNIQUE,
	"password_hash"	TEXT NOT NULL,
	"display_name"	TEXT NOT NULL,
	"is_active"	INTEGER NOT NULL DEFAULT 1 CHECK("is_active" IN (0, 1)),
	PRIMARY KEY("id")
);
DROP TABLE IF EXISTS "incident_affected_services";
CREATE TABLE "incident_affected_services" (
	"id"	INTEGER,
	"created_at_utc"	TEXT NOT NULL,
	"created_by_user_id"	INTEGER NOT NULL,
	"incident_id"	INTEGER NOT NULL,
	"service_key"	TEXT NOT NULL,
	PRIMARY KEY("id"),
	UNIQUE("incident_id","service_key"),
	FOREIGN KEY("created_by_user_id") REFERENCES "admin_users"("id"),
	FOREIGN KEY("incident_id") REFERENCES "incidents"("id") ON DELETE CASCADE
);
DROP TABLE IF EXISTS "incident_updates";
CREATE TABLE "incident_updates" (
	"id"	INTEGER,
	"created_at_utc"	TEXT NOT NULL,
	"created_by_user_id"	INTEGER NOT NULL,
	"incident_id"	INTEGER NOT NULL,
	"status"	TEXT NOT NULL CHECK("status" IN ('investigating', 'identified', 'monitoring', 'resolved')),
	"title_text"	TEXT NOT NULL,
	"body_html"	TEXT NOT NULL,
	PRIMARY KEY("id"),
	FOREIGN KEY("created_by_user_id") REFERENCES "admin_users"("id"),
	FOREIGN KEY("incident_id") REFERENCES "incidents"("id") ON DELETE CASCADE
);
DROP TABLE IF EXISTS "incidents";
CREATE TABLE "incidents" (
	"id"	INTEGER,
	"created_at_utc"	TEXT NOT NULL,
	"created_by_user_id"	INTEGER NOT NULL,
	"title_text"	TEXT NOT NULL,
	"body_html"	TEXT NOT NULL,
	"impact"	TEXT NOT NULL CHECK("impact" IN ('none', 'minor', 'major', 'critical')),
	"status"	TEXT NOT NULL CHECK("status" IN ('investigating', 'identified', 'monitoring', 'resolved')),
	"started_at_utc"	TEXT NOT NULL,
	"resolved_at_utc"	TEXT,
	PRIMARY KEY("id"),
	FOREIGN KEY("created_by_user_id") REFERENCES "admin_users"("id")
);
DROP TABLE IF EXISTS "maintenance";
CREATE TABLE "maintenance" (
	"id"	INTEGER,
	"created_at_utc"	TEXT NOT NULL,
	"created_by_user_id"	INTEGER NOT NULL,
	"title_text"	TEXT NOT NULL,
	"body_html"	TEXT NOT NULL,
	"status"	TEXT NOT NULL CHECK("status" IN ('scheduled', 'in_progress', 'completed', 'cancelled')),
	"starts_at_utc"	TEXT NOT NULL,
	"ends_at_utc"	TEXT NOT NULL,
	PRIMARY KEY("id"),
	FOREIGN KEY("created_by_user_id") REFERENCES "admin_users"("id"),
	CHECK("ends_at_utc" > "starts_at_utc")
);
DROP TABLE IF EXISTS "maintenance_affected_services";
CREATE TABLE "maintenance_affected_services" (
	"id"	INTEGER,
	"created_at_utc"	TEXT NOT NULL,
	"created_by_user_id"	INTEGER NOT NULL,
	"maintenance_id"	INTEGER NOT NULL,
	"service_key"	TEXT NOT NULL,
	PRIMARY KEY("id"),
	UNIQUE("maintenance_id","service_key"),
	FOREIGN KEY("created_by_user_id") REFERENCES "admin_users"("id"),
	FOREIGN KEY("maintenance_id") REFERENCES "maintenance"("id") ON DELETE CASCADE
);
DROP TABLE IF EXISTS "maintenance_updates";
CREATE TABLE "maintenance_updates" (
	"id"	INTEGER,
	"created_at_utc"	TEXT NOT NULL,
	"created_by_user_id"	INTEGER NOT NULL,
	"maintenance_id"	INTEGER NOT NULL,
	"title_text"	TEXT NOT NULL,
	"body_html"	TEXT NOT NULL,
	PRIMARY KEY("id"),
	FOREIGN KEY("created_by_user_id") REFERENCES "admin_users"("id"),
	FOREIGN KEY("maintenance_id") REFERENCES "maintenance"("id") ON DELETE CASCADE
);
DROP TABLE IF EXISTS "service_checks";
CREATE TABLE "service_checks" (
	"id"	INTEGER,
	"service_key"	TEXT NOT NULL,
	"checked_at_utc"	TEXT NOT NULL,
	"is_healthy"	INTEGER NOT NULL CHECK("is_healthy" IN (0, 1)),
	"response_time_in_ms"	REAL NOT NULL,
	"status_code"	INTEGER NOT NULL,
	"error_code"	INTEGER,
	"error_message"	TEXT,
	PRIMARY KEY("id")
);
DROP INDEX IF EXISTS "ix_incident_updates_incident";
CREATE INDEX "ix_incident_updates_incident" ON "incident_updates" (
	"incident_id",
	"created_at_utc"
);
DROP INDEX IF EXISTS "ix_incidents_started";
CREATE INDEX "ix_incidents_started" ON "incidents" (
	"started_at_utc"	DESC
);
DROP INDEX IF EXISTS "ix_maintenance_starts";
CREATE INDEX "ix_maintenance_starts" ON "maintenance" (
	"starts_at_utc"	DESC
);
DROP INDEX IF EXISTS "ix_maintenance_updates_maintenance";
CREATE INDEX "ix_maintenance_updates_maintenance" ON "maintenance_updates" (
	"maintenance_id",
	"created_at_utc"
);
DROP INDEX IF EXISTS "ix_service_checks_key_time";
CREATE INDEX "ix_service_checks_key_time" ON "service_checks" (
	"service_key",
	"checked_at_utc"
);
COMMIT;
