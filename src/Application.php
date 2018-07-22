<?php
namespace Misuzu;

use Carbon\Carbon;
use Misuzu\Config\ConfigManager;
use Misuzu\IO\Directory;
use Misuzu\IO\DirectoryDoesNotExistException;
use Misuzu\Users\Session;
use UnexpectedValueException;
use InvalidArgumentException;
use Swift_Mailer;
use Swift_NullTransport;
use Swift_SmtpTransport;
use Swift_SendmailTransport;

/**
 * Handles the set up procedures.
 * @package Misuzu
 */
class Application extends ApplicationBase
{
    /**
     * Whether the application is in debug mode, this should only be set in the constructor and never altered.
     * @var bool
     */
    private $debugMode = false;

    /**
     * Array of database connection names, first in the list is assumed to be the default.
     */
    private const DATABASE_CONNECTIONS = [
        'mysql-main',
    ];

    private const MAIL_TRANSPORT = [
        'null' => Swift_NullTransport::class,
        'smtp' => Swift_SmtpTransport::class,
        'sendmail' => Swift_SendmailTransport::class,
    ];

    /**
     * Active Session ID.
     * @var int
     */
    private $currentSessionId = 0;

    /**
     * Active User ID.
     * @var int
     */
    private $currentUserId = 0;

    /**
     * ConfigManager instance.
     * @var \Misuzu\Config\ConfigManager
     */
    private $configInstance = null;

    /**
     * TemplatingEngine instance.
     * @var \Misuzu\TemplateEngine
     */
    private $templatingInstance = null;

    private $mailerInstance = null;

    private $startupTime = 0;

    /**
     * Constructor, called by ApplicationBase::start() which also passes the arguments through.
     * @param null|string $configFile
     * @param bool        $debug
     */
    public function __construct(?string $configFile = null, bool $debug = false)
    {
        $this->startupTime = microtime(true);
        parent::__construct();
        $this->debugMode = $debug;
        $this->configInstance = new ConfigManager($configFile);

        ExceptionHandler::register(
            $debug,
            $this->configInstance->get('Exceptions', 'report_url', 'string', null),
            $this->configInstance->get('Exceptions', 'hash_key', 'string', null)
        );
    }

    public function getTimeSinceStart(): float
    {
        return microtime(true) - $this->startupTime;
    }

    /**
     * Gets instance of the config manager.
     * @return ConfigManager
     */
    public function getConfig(): ConfigManager
    {
        if (is_null($this->configInstance)) {
            throw new UnexpectedValueException(
                'Internal ConfigManager instance is null, how did you even manage to do this?'
            );
        }

        return $this->configInstance;
    }

    /**
     * Shuts the application down.
     */
    public function __destruct()
    {
        ExceptionHandler::unregister();
    }

    /**
     * Gets whether we're in debug mode or not.
     * @return bool
     */
    public function inDebugMode(): bool
    {
        return $this->debugMode;
    }

    /**
     * Gets a storage path.
     * @param string $path
     * @return string
     */
    public function getPath(string $path): string
    {
        if (!starts_with($path, '/') && substr($path, 1, 2) !== ':\\') {
            $path = __DIR__ . '/../' . $path;
        }

        return Directory::fixSlashes(rtrim($path, '/'));
    }

    /**
     * Gets a data storage path, with config storage path prefix.
     * @param string $append
     * @return Directory
     * @throws DirectoryDoesNotExistException
     * @throws IO\DirectoryExistsException
     */
    public function getStoragePath(string $append = ''): Directory
    {
        if (starts_with($append, '/')) {
            $path = $append;
        } else {
            $path = $this->getConfig()->get('Storage', 'path', 'string', __DIR__ . '/../store');

            if (!empty($append)) {
                $path .= '/' . $append;
            }
        }

        return Directory::createOrOpen($this->getPath($path));
    }

    /**
     * Gets a data store, with config overrides!
     * @param string $purpose
     * @return Directory
     * @throws DirectoryDoesNotExistException
     * @throws IO\DirectoryExistsException
     */
    public function getStore(string $purpose): Directory
    {
        $override_key = 'override_' . str_replace('/', '_', $purpose);

        if ($this->configInstance->contains('Storage', $override_key)) {
            try {
                return new Directory($this->configInstance->get('Storage', $override_key));
            } catch (DirectoryDoesNotExistException $ex) {
                // fall through and just get the default path.
            }
        }

        return $this->getStoragePath($purpose);
    }

    /**
     * Starts a user session.
     * @param int $userId
     * @param string $sessionKey
     */
    public function startSession(int $userId, string $sessionKey): void
    {
        $dbc = Database::connection();

        $findSession = $dbc->prepare('
            SELECT `session_id`, `expires_on`
            FROM `msz_sessions`
            WHERE `user_id` = :user_id
            AND `session_key` = :session_key
        ');
        $findSession->bindValue('user_id', $userId);
        $findSession->bindValue('session_key', $sessionKey);
        $sessionData = $findSession->execute() ? $findSession->fetch() : false;

        if ($sessionData) {
            $expiresOn = new Carbon($sessionData['expires_on']);

            if ($expiresOn->isPast()) {
                $deleteSession = $dbc->prepare('
                    DELETE FROM `msz_sessions`
                    WHERE `session_id` = :session_id
                ');
                $deleteSession->bindValue('session_id', $sessionData['session_id']);
                $deleteSession->execute();
            } else {
                $this->currentSessionId = (int)$sessionData['session_id'];
                $this->currentUserId = $userId;
            }
        }
    }

    public function hasActiveSession(): bool
    {
        return $this->getSessionId() > 0;
    }

    public function getSessionId(): int
    {
        return $this->currentSessionId;
    }

    public function getUserId(): int
    {
        return $this->currentUserId;
    }

    /**
     * Sets up the database module.
     */
    public function startDatabase(): void
    {
        if (Database::hasInstance()) {
            throw new UnexpectedValueException('Database has already been started.');
        }

        new Database($this->configInstance, self::DATABASE_CONNECTIONS[0]);
    }

    /**
     * Sets up the caching stuff.
     */
    public function startCache(): void
    {
        if (Cache::hasInstance()) {
            throw new UnexpectedValueException('Cache has already been started.');
        }

        new Cache(
            $this->configInstance->get('Cache', 'host', 'string', null),
            $this->configInstance->get('Cache', 'port', 'int', null),
            $this->configInstance->get('Cache', 'database', 'int', null),
            $this->configInstance->get('Cache', 'password', 'string', null),
            $this->configInstance->get('Cache', 'prefix', 'string', '')
        );
    }

    /**
     * Sets up the templating engine module.
     */
    public function startTemplating(): void
    {
        if (!is_null($this->templatingInstance)) {
            throw new UnexpectedValueException('Templating module has already been started.');
        }

        $this->templatingInstance = new TemplateEngine;
        $this->templatingInstance->debug($this->debugMode);

        $this->templatingInstance->var('globals', [
            'site_name' => $this->configInstance->get('Site', 'name', 'string', 'Flashii'),
            'site_description' => $this->configInstance->get('Site', 'description'),
            'site_twitter' => $this->configInstance->get('Site', 'twitter'),
            'site_url' => $this->configInstance->get('Site', 'url'),
        ]);

        $this->templatingInstance->addFilter('json_decode');
        $this->templatingInstance->addFilter('byte_symbol');
        $this->templatingInstance->addFilter('html_link');
        $this->templatingInstance->addFilter('html_colour');
        $this->templatingInstance->addFilter('url_construct');
        $this->templatingInstance->addFilter('country_name', 'get_country_name');
        $this->templatingInstance->addFilter('first_paragraph');
        $this->templatingInstance->addFilter('colour_get_css');
        $this->templatingInstance->addFilter('colour_get_css_contrast');
        $this->templatingInstance->addFilter('colour_get_inherit');
        $this->templatingInstance->addFilter('colour_get_red');
        $this->templatingInstance->addFilter('colour_get_green');
        $this->templatingInstance->addFilter('colour_get_blue');
        $this->templatingInstance->addFilter('parse_line');
        $this->templatingInstance->addFilter('parse_text');
        $this->templatingInstance->addFilter('asset_url');

        $this->templatingInstance->addFunction('git_commit_hash');
        $this->templatingInstance->addFunction('git_branch');
        $this->templatingInstance->addFunction('csrf_token', 'tmp_csrf_token');
        $this->templatingInstance->addFunction('perms_check');

        $this->templatingInstance->var('app', $this);
    }

    /**
     * Gets an instance of the templating engine.
     * @return TemplateEngine
     */
    public function getTemplating(): TemplateEngine
    {
        if (is_null($this->templatingInstance)) {
            throw new UnexpectedValueException('Internal templating engine instance is null, did you run startDatabase yet?');
        }

        return $this->templatingInstance;
    }

    public function startMailer(): void
    {
        if (!empty($this->mailerInstance)) {
            return;
        }

        if ($this->configInstance->contains('Mail')) {
            $method = strtolower($this->configInstance->get('Mail', 'method'));
        }

        if (empty($method) || !array_key_exists($method, self::MAIL_TRANSPORT)) {
            $method = 'null';
        }

        $class = self::MAIL_TRANSPORT[$method];
        $transport = new $class;

        switch ($method) {
            case 'sendmail':
                if ($this->configInstance->contains('Mail', 'command')) {
                    $transport->setCommand(
                        $this->configInstance->get('Mail', 'command')
                    );
                }
                break;

            case 'smtp':
                $transport->setHost($this->configInstance->get('Mail', 'host'));
                $transport->setPort($this->configInstance->get('Mail', 'port', 'int', 25));

                if ($this->configInstance->contains('Mail', 'encryption')) {
                    $transport->setEncryption($this->configInstance->get('Mail', 'encryption'));
                }

                if ($this->configInstance->contains('Mail', 'username')) {
                    $transport->setUsername($this->configInstance->get('Mail', 'username'));
                }

                if ($this->configInstance->contains('Mail', 'password')) {
                    $transport->setPassword($this->configInstance->get('Mail', 'password'));
                }
                break;
        }

        $this->mailerInstance = new Swift_Mailer($transport);
    }

    public function getMailer(): Swift_Mailer
    {
        if (empty($this->mailerInstance)) {
            $this->startMailer();
        }

        return $this->mailerInstance;
    }

    public static function mailer(): Swift_Mailer
    {
        return self::getInstance()->getMailer();
    }
}
