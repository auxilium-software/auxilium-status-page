<?php

declare(strict_types=1);

use Auxilium\Controllers\MaintenanceController;
use Auxilium\Controllers\ServiceController;
use Auxilium\ServiceInteractions\SQLiteInteractions;
use Auxilium\TwigHandling\PageBuilder;
use Auxilium\Utilities\AdminAuthenticationUtilities;
use Auxilium\Utilities\ConfigurationUtilities;
use Auxilium\Utilities\NavigationUtilities;

require_once __DIR__ . '/../vendor/autoload.php';

AdminAuthenticationUtilities::RequireAuthentication();

$currentUser = AdminAuthenticationUtilities::CurrentUser();
$config      = ConfigurationUtilities::GetUserConfiguration();

$url = $_SERVER['REQUEST_URI'];
$urlComponents = explode("/", $url);
$maintenanceId = (int) ($urlComponents[array_key_last($urlComponents)]);

if ($maintenanceId <= 0)
{
    http_response_code(404);
    die();
}

$repository  = new MaintenanceController(new SQLiteInteractions());
$maintenance = $repository->GetMaintenance($maintenanceId);

if ($maintenance === null)
{
    http_response_code(404);
    die();
}

$errors = [];

$parseUtc = static function (string $value): string|false|null
{
    if ($value === '')
    {
        return null;
    }

    $parsed = DateTimeImmutable::createFromFormat('Y-m-d\TH:i', $value, new DateTimeZone('UTC'));

    return $parsed === false ? false : $parsed->format('Y-m-d H:i:s');
};

if ($_SERVER['REQUEST_METHOD'] === 'POST')
{
    $token  = $_POST['csrf_token'] ?? null;
    $action = (string) ($_POST['action'] ?? '');

    if (!AdminAuthenticationUtilities::ValidateToken(is_string($token) ? $token : null))
    {
        $errors[] = 'Your session expired. Please submit the form again.';
    }
    else
    {
        switch ($action)
        {
            case 'update':
                $title    = trim((string) ($_POST['title'] ?? ''));
                $body     = trim((string) ($_POST['body'] ?? ''));
                $affected = array_values((array) ($_POST['affected'] ?? []));

                $startsAtUtc = $parseUtc(trim((string) ($_POST['starts_at'] ?? '')));
                $endsAtUtc   = $parseUtc(trim((string) ($_POST['ends_at'] ?? '')));

                if ($title === '')
                {
                    $errors[] = 'A title is required.';
                }
                if ($body === '')
                {
                    $errors[] = 'A description is required.';
                }
                if (!is_string($startsAtUtc))
                {
                    $errors[] = 'A valid start time is required.';
                }
                if (!is_string($endsAtUtc))
                {
                    $errors[] = 'A valid end time is required.';
                }
                if (is_string($startsAtUtc) && is_string($endsAtUtc) && $endsAtUtc <= $startsAtUtc)
                {
                    $errors[] = 'The end time must be after the start time.';
                }
                foreach ($affected as $serviceKey)
                {
                    if (!array_key_exists($serviceKey, ServiceController::GetServices()))
                    {
                        $errors[] = "Unknown service: '" . $serviceKey . "'.";
                    }
                }

                if ($errors === [])
                {
                    $repository->UpdateDetails($maintenanceId, $title, $body, $startsAtUtc, $endsAtUtc);
                    $repository->SetAffectedServices($currentUser['userId'], $maintenanceId, $affected);
                    NavigationUtilities::Redirect(target: "/admin/maintenance/$maintenanceId");
                }
                break;

            case 'set_status':
                $status = (string) ($_POST['status'] ?? '');

                if (!in_array($status, MaintenanceController::STATUSES, true))
                {
                    $errors[] = 'Invalid status.';
                }
                else
                {
                    $repository->SetStatus($maintenanceId, $status);
                    NavigationUtilities::Redirect(target: "/admin/maintenance/$maintenanceId");
                }
                break;

            case 'post_update':
                $title = trim((string) ($_POST['update_title'] ?? ''));
                $body  = trim((string) ($_POST['update_body'] ?? ''));

                if ($title === '' || $body === '')
                {
                    $errors[] = 'A progress note needs both a title and a body.';
                }
                else
                {
                    $repository->PostUpdate($currentUser['userId'], $maintenanceId, $title, $body);
                    NavigationUtilities::Redirect(target: "/admin/maintenance/$maintenanceId");
                }
                break;

            default:
                $errors[] = 'Unknown action.';
        }
    }

    $maintenance = $repository->GetMaintenance($maintenanceId);
}

$toInput = static function (?string $utc): string
{
    if ($utc === null || $utc === '')
    {
        return '';
    }

    $dt = DateTimeImmutable::createFromFormat('Y-m-d H:i:s', $utc, new DateTimeZone('UTC'));

    return $dt === false ? '' : $dt->format('Y-m-d\TH:i');
};

PageBuilder::Render(
    template: '/VirtualPages/AdminPanelMaintenanceOverview.html.twig',
    variables: [
        'CsrfToken'      => AdminAuthenticationUtilities::Token(),
        'CurrentUser'    => $currentUser,
        'Services'       => ServiceController::GetServices(),
        'Statuses'       => MaintenanceController::STATUSES,

        'Maintenance'    => $maintenance,
        'AffectedKeys'   => $repository->GetAffectedServiceKeys($maintenanceId),
        'Updates'        => $repository->GetUpdates($maintenanceId),

        'StartsAtInput'  => $toInput($maintenance['starts_at_utc']),
        'EndsAtInput'    => $toInput($maintenance['ends_at_utc']),

        'Errors'         => $errors,
    ],
);
