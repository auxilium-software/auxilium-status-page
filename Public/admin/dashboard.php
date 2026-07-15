<?php

declare(strict_types=1);

use Auxilium\Controllers\StatusController;
use Auxilium\ServiceInteractions\SQLiteInteractions;
use Auxilium\TwigHandling\PageBuilder;
use Auxilium\Utilities\AdminAuthenticationUtilities;
use Auxilium\Utilities\ConfigurationUtilities;

require_once __DIR__ . '/../../vendor/autoload.php';

AdminAuthenticationUtilities::RequireAuthentication();

$config     = ConfigurationUtilities::GetUserConfiguration();
$degradedMs = (int)($config['UserInterfaceSettings']['DegradedResponseMsThreshold'] ?? throw new Exception("Config value UserInterfaceSettings->DegradedResponseMsThreshold is not set or is missing"));

$repository = new StatusController(new SQLiteInteractions());

$lastCheckUtc = $repository->GetLastCheckTimeUtc();

$pollerIsStale = $lastCheckUtc === null || (time() - strtotime($lastCheckUtc . ' UTC')) > 300;

PageBuilder::Render(
    template: '/Pages/admin/dashboard.html.twig',
    variables: [
        'CsrfToken'          => AdminAuthenticationUtilities::Token(),
        'CurrentUser'        => AdminAuthenticationUtilities::CurrentUser(),

        'ServiceStates'      => $repository->GetCurrentServiceStates($degradedMs),
        'OngoingIncidents'   => $repository->GetOngoingIncidents(),
        'ResolvedIncidents'  => $repository->GetRecentResolvedIncidents(5),
        'ActiveMaintenance'  => $repository->GetActiveMaintenance(),

        'PollerIsStale'      => $pollerIsStale,
        'LastCheckUtc'       => $lastCheckUtc,
        'CheckCount'         => $repository->GetCheckCount(),
    ],
);
