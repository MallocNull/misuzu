<?php
namespace Misuzu;

use Aitemu\RouteCollection;
use Misuzu\Config\ConfigManager;

class Application
{
    private static $instance = null;

    public static function getInstance(): Application
    {
        if (is_null(static::$instance) || !(static::$instance instanceof Application)) {
            throw new \Exception('Invalid instance type.');
        }

        return static::$instance;
    }

    public static function start(): Application
    {
        if (!is_null(static::$instance) || static::$instance instanceof Application) {
            throw new \Exception('An Application has already been set up.');
        }

        static::$instance = new Application;
        return static::getInstance();
    }

    private $router = null;
    private $templating = null;
    private $configuration = null;

    protected function __construct($config = null)
    {
        ExceptionHandler::register();

        $this->router = new RouteCollection;
        $this->templating = new TemplateEngine;
        $this->configuration = new ConfigManager($config);

        echo 'hello!';
    }

    public function __destruct()
    {
        ExceptionHandler::unregister();
    }

    public function debug(bool $mode): void
    {
        ExceptionHandler::debug($mode);

        if ($this->hasTemplating()) {
            $this->getTemplating()->debug($mode);
        }
    }

    public function hasRouter(): bool
    {
        return !is_null($this->router) && $this->router instanceof RouteCollection;
    }

    public function getRouter(): RouteCollection
    {
        if (!$this->hasRouter()) {
            throw new \Exception('No RouteCollection instance is available.');
        }

        return $this->router;
    }

    public function hasTemplating(): bool
    {
        return !is_null($this->templating) && $this->templating instanceof TemplateEngine;
    }

    public function getTemplating(): TemplateEngine
    {
        if (!$this->hasTemplating()) {
            throw new \Exception('No TemplateEngine instance is available.');
        }

        return $this->templating;
    }

    public function hasConfig(): bool
    {
        return !is_null($this->configuration) && $this->configuration instanceof ConfigManager;
    }

    public function getConfig(): ConfigManager
    {
        if (!$this->hasConfig()) {
            throw new \Exception('No ConfigManager instance is available.');
        }

        return $this->configuration;
    }
}