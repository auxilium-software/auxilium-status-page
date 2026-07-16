<?php

use Auxilium\TwigHandling\PageBuilder;

require_once __DIR__ . '/../vendor/autoload.php';


register_shutdown_function(function () {
    $error = error_get_last();

    if ($error === null || !in_array($error['type'], [E_ERROR, E_PARSE, E_CORE_ERROR, E_COMPILE_ERROR], true)) {
        return;
    }

    PageBuilder::OfflineRender(
        template: '/ErrorPages/InternalSystemErrorErrorPage.html.twig',
        variables: [],
    );
});


// Get path without query string
$path = parse_url($_SERVER['REQUEST_URI'], PHP_URL_PATH);
$publicDir = __DIR__ . '/../Public';

// 1. Serve root index
if ($path === '/') {
    require_once "$publicDir/index.php";
    return true;
}

// 2. Serve public PHP files like /about → /Public/about.php
$phpFile = "$publicDir$path.php";
if (is_file($phpFile)) {
    require_once $phpFile;
    return true;
}

// 3. Serve static files directly (e.g., CSS, JS, images)
$rawFile = "$publicDir$path";
if (is_file($rawFile)) {
    return false; // Let the web server serve this
}

// 4. Custom regex routes
$routes = [
    "#^/admin/incidents/.+#"    => __DIR__ . '/../RoutedPages/AdminPanelIncidentOverview.php',
    "#^/admin/maintenance/.+#"  => __DIR__ . '/../RoutedPages/AdminPanelMaintenanceOverview.php',
];

foreach ($routes as $pattern => $file)
{
    if (preg_match($pattern, $path))
    {
        require_once $file;
        return true;
    }
}


PageBuilder::Render404();
