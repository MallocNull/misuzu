<?php
use Misuzu\Database;
use Misuzu\Twig;

define('MSZ_TPL_FILE_EXT', '.twig');
define('MSZ_TPL_VARS_STORE', '_msz_tpl_vars');

function tpl_init(array $options = []): void
{
    $options = array_merge([
        'cache' => false,
        'strict_variables' => true,
        'auto_reload' => false,
        'debug' => false,
    ], $options);

    $GLOBALS[MSZ_TPL_VARS_STORE] = [];

    $loader = new Twig_Loader_Filesystem;
    $twig = new Twig($loader, $options);
    $twig->addExtension(new Twig_Extensions_Extension_Date);
}

function tpl_var(string $key, $value): void
{
    $GLOBALS[MSZ_TPL_VARS_STORE][$key] = $value;
}

function tpl_vars(array $vars): void
{
    $GLOBALS[MSZ_TPL_VARS_STORE] = array_merge($GLOBALS[MSZ_TPL_VARS_STORE], $vars);
}

function tpl_add_path(string $path): void
{
    Twig::instance()->getLoader()->addPath($path);
}

function tpl_sanitise_path(string $path): string
{
    // if the .twig extension if already present just assume that the path is already correct
    if (ends_with($path, MSZ_TPL_FILE_EXT)) {
        return $path;
    }

    return str_replace('.', '/', $path) . MSZ_TPL_FILE_EXT;
}

function tpl_add_function(string $name, bool $isFilter = false, callable $callable = null): void
{
    $twig = Twig::instance();

    if ($isFilter) {
        $twig->addFilter(new Twig_SimpleFilter($name, $callable === null ? $name : $callable));
    } else {
        $twig->addFunction(new Twig_SimpleFunction($name, $callable === null ? $name : $callable));
    }
}

function tpl_exists(string $path): bool
{
    return Twig::instance()->getLoader()->exists(tpl_sanitise_path($path));
}

function tpl_render(string $path, array $vars = []): string
{
    $twig = Twig::instance();

    if ($twig->isDebug()) {
        tpl_var('query_count', Database::queryCount());
    }

    $path = tpl_sanitise_path($path);

    if (count($vars)) {
        tpl_vars($vars);
    }

    return $twig->render($path, $GLOBALS[MSZ_TPL_VARS_STORE]);
}