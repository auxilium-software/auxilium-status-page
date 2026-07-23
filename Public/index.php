<?php

use Auxilium\TwigHandling\PageBuilder;

require_once __DIR__ . '/../vendor/autoload.php';

try {
    PageBuilder::Render(
        template: "/Pages/index.html.twig"
    );
} catch (Exception $e) {
    PageBuilder::RenderInternalSystemError($e);
}
