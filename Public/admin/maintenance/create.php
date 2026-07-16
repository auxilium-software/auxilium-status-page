<?php

declare(strict_types=1);

use Auxilium\Controllers\MaintenanceController;
use Auxilium\Controllers\ServiceController;
use Auxilium\ServiceInteractions\SQLiteInteractions;
use Auxilium\TwigHandling\PageBuilder;
use Auxilium\Utilities\AdminAuthenticationUtilities;
use Auxilium\Utilities\ConfigurationUtilities;
use Auxilium\Utilities\NavigationUtilities;

require_once __DIR__ . '/../../../vendor/autoload.php';

AdminAuthenticationUtilities::RequireAuthentication();

$currentUser = AdminAuthenticationUtilities::CurrentUser();
$config      = ConfigurationUtilities::GetUserConfiguration();

$repository = new MaintenanceController(new SQLiteInteractions());

$errors = [];

$submitted = [
    'title'    => '',
    'body'     => '',
    'startsAt' => '',
    'endsAt'   => '',
    'affected' => [],
];

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
    $token = $_POST['csrf_token'] ?? null;

    $submitted['title']    = trim((string) ($_POST['title'] ?? ''));
    $submitted['body']     = trim((string) ($_POST['body'] ?? ''));
    $submitted['startsAt'] = trim((string) ($_POST['starts_at'] ?? ''));
    $submitted['endsAt']   = trim((string) ($_POST['ends_at'] ?? ''));
    $submitted['affected'] = array_values((array) ($_POST['affected'] ?? []));

    if (!AdminAuthenticationUtilities::ValidateToken(is_string($token) ? $token : null))
    {
        $errors[] = 'Your session expired. Please submit the form again.';
    }

    if ($submitted['title'] === '')
    {
        $errors[] = 'A title is required.';
    }

    if ($submitted['body'] === '')
    {
        $errors[] = 'A description is required.';
    }

    $startsAtUtc = $parseUtc($submitted['startsAt']);
    $endsAtUtc   = $parseUtc($submitted['endsAt']);

    if ($startsAtUtc === null || $submitted['startsAt'] === '')
    {
        $errors[] = 'A start time is required.';
    }
    elseif ($startsAtUtc === false)
    {
        $errors[] = 'Invalid start time.';
    }

    if ($endsAtUtc === null || $submitted['endsAt'] === '')
    {
        $errors[] = 'An end time is required.';
    }
    elseif ($endsAtUtc === false)
    {
        $errors[] = 'Invalid end time.';
    }

    if (is_string($startsAtUtc) && is_string($endsAtUtc) && $endsAtUtc <= $startsAtUtc)
    {
        $errors[] = 'The end time must be after the start time.';
    }

    if ($submitted['affected'] === [])
    {
        $errors[] = 'Select at least one affected service.';
    }

    foreach ($submitted['affected'] as $serviceKey)
    {
        if (!array_key_exists($serviceKey, ServiceController::GetServices()))
        {
            $errors[] = "Unknown service: '" . $serviceKey . "'.";
        }
    }

    if ($errors === [])
    {
        $maintenanceId = $repository->CreateMaintenance(
            userId:              $currentUser['userId'],
            title:               $submitted['title'],
            bodyHtml:            $submitted['body'],
            status:              'scheduled',
            startsAtUtc:         $startsAtUtc,
            endsAtUtc:           $endsAtUtc,
            affectedServiceKeys: $submitted['affected'],
        );

        NavigationUtilities::Redirect(target: "/admin/maintenance/$maintenanceId");
    }
}

PageBuilder::Render(
    template: '/Pages/admin/maintenance/create.html.twig',
    variables: [
        'CsrfToken'   => AdminAuthenticationUtilities::Token(),
        'CurrentUser' => $currentUser,
        'Services'    => ServiceController::GetServices(),
        'Errors'      => $errors,
        'Submitted'   => $submitted,
    ],
);
