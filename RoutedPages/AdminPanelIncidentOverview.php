<?php

declare(strict_types=1);

use Auxilium\Controllers\IncidentController;
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
$incidentId = (int) ($urlComponents[array_key_last($urlComponents)]);

if ($incidentId <= 0)
{
    http_response_code(404);
    exit;
}

$repository = new IncidentController(new SQLiteInteractions());
$incident   = $repository->GetIncident($incidentId);

if ($incident === null)
{
    http_response_code(404);
    exit;
}

$errors = [];

$submitted = [
    'title'  => '',
    'body'   => '',
    'status' => $incident['status'],
];

if ($_SERVER['REQUEST_METHOD'] === 'POST')
{
    $token = $_POST['csrf_token'] ?? null;

    $submitted['title']  = trim((string) ($_POST['title'] ?? ''));
    $submitted['body']   = trim((string) ($_POST['body'] ?? ''));
    $submitted['status'] = (string) ($_POST['status'] ?? $incident['status']);

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
        $errors[] = 'An update description is required.';
    }

    if (!in_array($submitted['status'], IncidentController::STATUSES, true))
    {
        $errors[] = 'Invalid status.';
    }

    if ($errors === [])
    {
        $repository->PostUpdate(
            userId:     $currentUser['userId'],
            incidentId: $incidentId,
            status:     $submitted['status'],
            title:      $submitted['title'],
            bodyHtml:   $submitted['body'],
        );

        NavigationUtilities::Redirect(target: "/admin/incidents/$incidentId");
    }
}

PageBuilder::Render(
    template: '/VirtualPages/AdminPanelIncidentOverview.html.twig',
    variables: [
        'CsrfToken'    => AdminAuthenticationUtilities::Token(),
        'CurrentUser'  => $currentUser,
        'Services'     => ServiceController::GetServices(),
        'Statuses'     => IncidentController::STATUSES,

        'Incident'     => $incident,
        'Updates'      => $repository->GetUpdates($incidentId),
        'AffectedKeys' => $repository->GetAffectedServiceKeys($incidentId),

        'Errors'       => $errors,
        'Submitted'    => $submitted,
    ],
);
