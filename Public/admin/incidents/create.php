<?php

declare(strict_types=1);

use Auxilium\Controllers\IncidentController;
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

$repository = new IncidentController(new SQLiteInteractions());

$errors = [];

$submitted = [
    'title'       => '',
    'body'        => '',
    'impact'      => 'minor',
    'status'      => 'investigating',
    'affected'    => [],
    'startedAt'   => '',
];

if ($_SERVER['REQUEST_METHOD'] === 'POST')
{
    $token = $_POST['csrf_token'] ?? null;

    $submitted['title']     = trim((string) ($_POST['title'] ?? ''));
    $submitted['body']      = trim((string) ($_POST['body'] ?? ''));
    $submitted['impact']    = (string) ($_POST['impact'] ?? 'minor');
    $submitted['status']    = (string) ($_POST['status'] ?? 'investigating');
    $submitted['affected']  = array_values((array) ($_POST['affected'] ?? []));
    $submitted['startedAt'] = trim((string) ($_POST['started_at'] ?? ''));

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

    if (!in_array($submitted['impact'], IncidentController::IMPACTS, true))
    {
        $errors[] = 'Invalid impact level.';
    }

    if (!in_array($submitted['status'], IncidentController::STATUSES, true))
    {
        $errors[] = 'Invalid status.';
    }

    if ($submitted['affected'] === [])
    {
        $errors[] = 'Select at least one affected service.';
    }

    // Only accept service keys we actually know about - never trust the POST body.
    foreach ($submitted['affected'] as $serviceKey)
    {
        if (!array_key_exists($serviceKey, ServiceController::GetServices()))
        {
            $errors[] = "Unknown service: '" . $serviceKey . "'.";
        }
    }

    $startedAtUtc = null;

    if ($submitted['startedAt'] !== '')
    {
        $parsed = DateTimeImmutable::createFromFormat(
            'Y-m-d\TH:i',
            $submitted['startedAt'],
            new DateTimeZone('UTC')
        );

        if ($parsed === false)
        {
            $errors[] = 'Invalid start time.';
        }
        else
        {
            $startedAtUtc = $parsed->format('Y-m-d H:i:s');
        }
    }

    if ($errors === [])
    {
        $incidentId = $repository->CreateIncident(
            userId:              $currentUser['userId'],
            title:               $submitted['title'],
            bodyHtml:            $submitted['body'],
            impact:              $submitted['impact'],
            status:              $submitted['status'],
            affectedServiceKeys: $submitted['affected'],
            startedAtUtc:        $startedAtUtc,
        );

        NavigationUtilities::Redirect(target: "/admin/incidents/$incidentId");
    }
}

PageBuilder::Render(
    template: '/Pages/admin/incidents/create.html.twig',
    variables: [
        'CsrfToken'   => AdminAuthenticationUtilities::Token(),
        'CurrentUser' => $currentUser,
        'Services'    => ServiceController::GetServices(),
        'Impacts'     => IncidentController::IMPACTS,
        'Statuses'    => IncidentController::STATUSES,
        'Errors'      => $errors,
        'Submitted'   => $submitted,
    ],
);
