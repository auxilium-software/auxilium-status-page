<?php

namespace Auxilium\TwigHandling;

use Auxilium\TwigHandling\Extensions\CommonFilters;
use Auxilium\TwigHandling\Extensions\CommonFunctions;
use Auxilium\Utilities\ConfigurationUtilities;
use Auxilium\Utilities\LocalisationUtilities;
use JetBrains\PhpStorm\NoReturn;
use Throwable;
use Twig\Environment;
use Twig\Loader\FilesystemLoader;

final class PageBuilder
{
    private Environment $twig;

    public function __construct(bool $useAuth)
    {
        $loader = new FilesystemLoader(__DIR__ . '/../../Templates');
        $this->twig = new Environment($loader, [
            'debug' => true,
            'cache' => false,
        ]);


        $this->twig->addGlobal('style_options', []);
        $this->twig->addGlobal('_INLINE_NODE_EXPANDED_', false);
        $this->twig->addGlobal('_INLINE_NODE_NEW_TAB_', false);

        $this->twig->addGlobal('_INSTANCE_NAME_', ConfigurationUtilities::GetUserConfiguration()["SystemSettings"]["InstanceName"]);
        $this->twig->addGlobal('_LOGO_PATH_', "AuxiliumLogo.png");
        $this->twig->addGlobal('_LOGO_CONTRAST_PATH_', "AuxiliumLogo-White.png");
        $this->twig->addGlobal('_SELECTED_LANGUAGE_', LocalisationUtilities::GetActiveLocale());


        $this->twig->addExtension(new CommonFilters());
        $this->twig->addExtension(new CommonFunctions());
    }

    #[NoReturn]
    public static function AutoRender(array $variables = []): void
    {
        self::Render(
            template: self::guessTargetTwigFile(),
            variables: $variables,
            useAuth: true
        );
    }

    #[NoReturn]
    public static function Render(string $template, array $variables = [], bool $useAuth = true): void
    {
        $builder = new self($useAuth);
        echo $builder->twig->render($template, $variables);
        exit();
    }

    /**
     * Determines the Twig template path based on the current REQUEST_URI.
     *
     * @throws \RuntimeException If the template file does not exist.
     */
    private static function guessTargetTwigFile(): string
    {
        $uri = parse_url($_SERVER['REQUEST_URI'], PHP_URL_PATH);
        $uri = preg_replace('/[^a-zA-Z0-9\/_\-.]/', '', $uri); // Sanitize

        $twigFile = str_replace('.php', '.html.twig', $uri);

        if (!str_ends_with($twigFile, '.html.twig')) {
            $twigFile .= 'index.html.twig';
        }

        $fullPath = __DIR__ . '/../../Templates/Pages' . $twigFile;

        if (!file_exists($fullPath)) {
            throw new \RuntimeException("Template not found: {$twigFile}");
        }

        return 'Pages' . $twigFile;
    }

    #[NoReturn]
    public static function AutoRenderUnsafe(array $variables = []): void
    {
        self::Render(
            template: self::guessTargetTwigFile(),
            variables: $variables,
            useAuth: false
        );
    }

    #[NoReturn]
    public static function OfflineRender(string $template, array $variables = []): void
    {
        $loader = new FilesystemLoader(__DIR__ . '/../../Templates');
        $twig = new Environment($loader, [
            'debug' => true,
            'cache' => false,
        ]);
        $twig->addGlobal('style_options', []);
        $twig->addGlobal('_INLINE_NODE_EXPANDED_', false);
        $twig->addGlobal('_INLINE_NODE_NEW_TAB_', false);
        $twig->addGlobal('_SELECTED_LANGUAGE_', LocalisationUtilities::GetActiveLocale());
        $twig->addGlobal('_SYSTEM_BULLETIN_', []);
        $twig->addGlobal('_INSTANCE_NAME_', ConfigurationUtilities::GetUserConfiguration()["SystemSettings"]["InstanceName"]);
        $twig->addGlobal('_LOGO_CONTRAST_PATH_', "");
        $twig->addExtension(new CommonFilters());
        // $twig->addExtension(new CommonFunctions());
        echo $twig->render($template, $variables);
        die();
    }

    #[NoReturn]
    public static function RenderInternalSystemError(Throwable $ex): void
    {
        http_response_code(500);
        throw $ex;
    }

    #[NoReturn]
    public static function Render404(): void
    {
        http_response_code(404);
        self::Render(
            template: '/ErrorPages/404.html.twig',
            variables: [],
            useAuth: false
        );
    }
}
