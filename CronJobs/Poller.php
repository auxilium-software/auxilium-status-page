<?php


use Auxilium\ServiceInteractions\CurlInteractions;
use Auxilium\ServiceInteractions\SQLiteInteractions;
use Auxilium\Utilities\ConfigurationUtilities;

require_once __DIR__ . '/../vendor/autoload.php';

$db = new SQLiteInteractions();

$portalHealthUrl = ConfigurationUtilities::GetUserConfiguration()["HealthUrls"]["Portal"];
$apiHealthUrl = ConfigurationUtilities::GetUserConfiguration()["HealthUrls"]["API"];

$portalResult = CurlInteractions::Probe($portalHealthUrl, timeoutSeconds: 10);
$apiResult = CurlInteractions::Probe($apiHealthUrl, timeoutSeconds: 10);

$checkedAtUtc = (new DateTime("now", new DateTimeZone("UTC")))->format(format: "Y-m-d H:i:s");

$db->query_insert(
    query: "
INSERT INTO service_checks (service_key, checked_at_utc, is_healthy, response_time_in_ms, status_code, error_code, error_message)
VALUES (:service_key, :checked_at_utc, :is_healthy, :response_time_in_ms, :status_code, :error_code, :error_message);
",
    params: [
        ":service_key" => "portal",
        ":checked_at_utc" => $checkedAtUtc,
        ":is_healthy" => (int)$portalResult->isHealthy(),
        ":response_time_in_ms" => $portalResult->responseMs,
        ":status_code" => $portalResult->statusCode,
        ":error_code" => $portalResult->errorCode,
        ":error_message" => $portalResult->error,
    ]
);
$db->query_insert(
    query: "
INSERT INTO service_checks (service_key, checked_at_utc, is_healthy, response_time_in_ms, status_code, error_code, error_message)
VALUES (:service_key, :checked_at_utc, :is_healthy, :response_time_in_ms, :status_code, :error_code, :error_message);
",
    params: [
        ":service_key" => "api",
        ":checked_at_utc" => $checkedAtUtc,
        ":is_healthy" => (int)$apiResult->isHealthy(),
        ":response_time_in_ms" => $apiResult->responseMs,
        ":status_code" => $apiResult->statusCode,
        ":error_code" => $apiResult->errorCode,
        ":error_message" => $apiResult->error,
    ]
);

die();
