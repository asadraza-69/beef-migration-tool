<?php

namespace Nudelsalat;

use Nudelsalat\Migrations\Loader;
use Nudelsalat\Migrations\Recorder;
use Nudelsalat\Migrations\Executor;
use Nudelsalat\Migrations\Routing\AllowAllMigrationRouter;
use Nudelsalat\Migrations\Routing\CallableMigrationRouter;
use Nudelsalat\Migrations\Routing\MigrationRouterInterface;
use Nudelsalat\Schema\SchemaEditorFactory;

class Bootstrap
{
    private Config $config;
    /** @var array<string, \PDO> */
    private array $pdoPool = [];
    private ?MigrationRouterInterface $migrationRouter = null;
    private Migrations\Events\EventDispatcher $dispatcher;
    
    private static ?Bootstrap $instance = null;

    /**
     * Get the singleton Bootstrap instance.
     * 
     * @return Bootstrap
     */
    public static function getInstance(): Bootstrap
    {
        if (self::$instance === null) {
            throw new \RuntimeException('Bootstrap not initialized. Call Bootstrap::setInstance() first.');
        }
        return self::$instance;
    }

    /**
     * Set the singleton Bootstrap instance.
     * 
     * @param Bootstrap $instance
     */
    public static function setInstance(Bootstrap $instance): void
    {
        self::$instance = $instance;
    }

    public function __construct(Config $config)
    {
        $this->config = $config;
        $this->dispatcher = new Migrations\Events\EventDispatcher();
    }

    public function getPdo(?string $alias = null): \PDO
    {
        $alias = $alias ?: $this->config->getDefaultDatabaseAlias();

        if (!isset($this->pdoPool[$alias])) {
            $db = $this->config->getDatabaseConfig($alias);
            $this->pdoPool[$alias] = new \PDO(
                $db['dsn'],
                $db['user'] ?? null,
                $db['pass'] ?? null,
                [\PDO::ATTR_ERRMODE => \PDO::ERRMODE_EXCEPTION]
            );
        }
        return $this->pdoPool[$alias];
    }

    public function createLoader(): Loader
    {
        $loader = new Loader();
        $appMigrations = [];
        foreach ($this->config->getApps() as $name => $paths) {
            $appMigrations[$name] = $paths['migrations'];
        }
        $loader->loadDisk($appMigrations);
        return $loader;
    }

    public function createRecorder(?string $alias = null): Recorder
    {
        return new Recorder($this->getPdo($alias));
    }

    public function createExecutor(?string $alias = null): Executor
    {
        $alias = $alias ?: $this->config->getDefaultDatabaseAlias();
        $pdo = $this->getPdo($alias);
        return new Executor(
            $pdo,
            $this->createLoader(),
            $this->createRecorder($alias),
            SchemaEditorFactory::create($pdo),
            $this->getDispatcher(),
            $alias,
            $this->getMigrationRouter()
        );
    }

    public function getDispatcher(): Migrations\Events\EventDispatcher
    {
        return $this->dispatcher;
    }

    public function getConfig(): Config
    {
        return $this->config;
    }

    public function getMigrationRouter(): MigrationRouterInterface
    {
        if ($this->migrationRouter !== null) {
            return $this->migrationRouter;
        }

        $definition = $this->config->getMigrationRouterDefinition();
        if ($definition === null) {
            return $this->migrationRouter = new AllowAllMigrationRouter();
        }

        if ($definition instanceof MigrationRouterInterface) {
            return $this->migrationRouter = $definition;
        }

        if (is_callable($definition)) {
            return $this->migrationRouter = new CallableMigrationRouter($definition);
        }

        if (is_string($definition) && class_exists($definition)) {
            $instance = new $definition();
            if (!$instance instanceof MigrationRouterInterface) {
                throw new \InvalidArgumentException("Migration router '{$definition}' must implement MigrationRouterInterface.");
            }
            return $this->migrationRouter = $instance;
        }

        throw new \InvalidArgumentException('Invalid migration router configuration.');
    }
}
