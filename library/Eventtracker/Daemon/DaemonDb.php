<?php

namespace Icinga\Module\Eventtracker\Daemon;

use Evenement\EventEmitterTrait;
use Exception;
use gipfl\DbMigration\Migrations;
use gipfl\IcingaCliDaemon\DbResourceConfigWatch;
use gipfl\IcingaCliDaemon\RetryUnless;
use gipfl\ZfDb\Adapter\Adapter as ZfDb;
use Icinga\Application\Icinga;
use Icinga\Module\Eventtracker\Db\ZfDbConnectionFactory;
use Icinga\Module\Eventtracker\Modifier\Settings;
use React\EventLoop\LoopInterface;
use React\Promise\Deferred;
use RuntimeException;
use SplObjectStorage;
use function React\Promise\reject;
use function React\Promise\resolve;

class DaemonDb
{
    use EventEmitterTrait;

    const TABLE_NAME = 'daemon_info';

    /** @var LoopInterface */
    private $loop;

    /** @var ZfDb */
    protected $db;

    /** @var DaemonProcessDetails */
    protected $details;

    /** @var DbBasedComponent[] */
    protected $registeredComponents = [];

    /** @var DbResourceConfigWatch|null */
    protected $configWatch;

    /** @var array|null */
    protected $dbConfig;

    /** @var RetryUnless|null */
    protected $pendingReconnection;

    /** @var Deferred|null */
    protected $pendingDisconnect;

    /** @var \React\EventLoop\TimerInterface */
    protected $refreshTimer;

    /** @var \React\EventLoop\TimerInterface */
    protected $schemaCheckTimer;

    /** @var int */
    protected $startupSchemaVersion;

    public function __construct(DaemonProcessDetails $details, $dbConfig = null)
    {
        $this->details = $details;
        $this->dbConfig = $dbConfig;
    }

    public function register(DbBasedComponent $component)
    {
        $this->registeredComponents[] = $component;

        return $this;
    }

    public function setConfigWatch(DbResourceConfigWatch $configWatch)
    {
        $this->configWatch = $configWatch;
        $configWatch->notify(function ($config) {
            $this->disconnect()->then(function () use ($config) {
                return $this->onNewConfig($config);
            });
        });
        if ($this->loop) {
            $configWatch->run($this->loop);
        }

        return $this;
    }

    public function run(LoopInterface $loop)
    {
        $this->loop = $loop;
        $this->connect();
        $this->refreshTimer = $loop->addPeriodicTimer(3, function () {
            $this->refreshMyState();
        });
        $this->schemaCheckTimer = $loop->addPeriodicTimer(15, function () {
            $this->checkDbSchema();
        });
        if ($this->configWatch) {
            $this->configWatch->run($this->loop);
        }
    }

    protected function onNewConfig($config)
    {
        if ($config === null) {
            if ($this->dbConfig === null) {
                Logger::error('DB configuration is not valid');
            } else {
                Logger::error('DB configuration is no longer valid');
            }
            $this->emitStatus('no configuration');
            $this->dbConfig = $config;

            return resolve();
        } else {
            $this->emitStatus('configuration loaded');
            $this->dbConfig = $config;

            return $this->establishConnection($config);
        }
    }

    protected function establishConnection($config)
    {
        if ($this->db !== null) {
            Logger::error('Trying to establish a connection while being connected');
            return reject();
        }
        $callback = function () use ($config) {
            $this->reallyEstablishConnection($config);
        };
        $onSuccess = function () {
            $this->pendingReconnection = null;
            $this->onConnected();
        };
        if ($this->pendingReconnection) {
            $this->pendingReconnection->reset();
            $this->pendingReconnection = null;
        }
        $this->emitStatus('connecting');

        return $this->pendingReconnection = RetryUnless::succeeding($callback)
            ->setInterval(0.2)
            ->slowDownAfter(10, 10)
            ->run($this->loop)
            ->then($onSuccess)
            ;
    }

    protected function reallyEstablishConnection($config)
    {
        $db = ZfDbConnectionFactory::connection(Settings::fromSerialization($config));
        $db->getConnection();
        $migrations = new Migrations($db, Icinga::app()->getModuleManager()->getModuleDir(
            'eventtracker',
            '/schema'
        ));
        if (! $migrations->hasSchema()) {
            $this->emitStatus('no schema', 'error');
            throw new RuntimeException('DB has no schema');
        }
        $this->wipeOrphanedInstances($db);
        if ($this->hasAnyOtherActiveInstance($db)) {
            $this->emitStatus('locked by other instance', 'error');
            throw new RuntimeException('DB is locked by a running daemon instance');
        }
        $this->startupSchemaVersion = $migrations->getLastMigrationNumber();
        $this->details->set('schema_version', $this->startupSchemaVersion);

        $this->db = $db;
        $this->loop->futureTick(function () {
            $this->refreshMyState();
        });

        return $db;
    }

    protected function checkDbSchema()
    {
        if ($this->db === null) {
            return;
        }

        if ($this->schemaIsOutdated()) {
            $this->emit('schemaChange', [
                $this->getStartupSchemaVersion(),
                $this->getDbSchemaVersion()
            ]);
        }
    }

    protected function schemaIsOutdated()
    {
        return $this->getStartupSchemaVersion() < $this->getDbSchemaVersion();
    }

    protected function getStartupSchemaVersion()
    {
        return $this->startupSchemaVersion;
    }

    protected function getDbSchemaVersion()
    {
        if ($this->db === null) {
            throw new RuntimeException(
                'Cannot determine DB schema version without an established DB connection'
            );
        }
        $migrations = new Migrations($this->db);

        return  $migrations->getLastMigrationNumber();
    }

    protected function onConnected()
    {
        $this->emitStatus('connected');
        Logger::info('Connected to the database');
        foreach ($this->registeredComponents as $component) {
            $component->initDb($this->db);
        }
    }

    /**
     * @return \React\Promise\PromiseInterface
     */
    protected function reconnect()
    {
        return $this->disconnect()->then(function () {
            return $this->connect();
        }, function (Exception $e) {
            Logger::error('Disconnect failed. This should never happen: ' . $e->getMessage());
            exit(1);
        });
    }

    /**
     * @return \React\Promise\ExtendedPromiseInterface
     */
    public function connect()
    {
        if ($this->db === null) {
            if ($this->dbConfig) {
                return $this->establishConnection($this->dbConfig);
            }
        }

        return resolve();
    }

    protected function stopRegisteredComponents()
    {
        $pending = new Deferred();
        $pendingComponents = new SplObjectStorage();
        foreach ($this->registeredComponents as $component) {
            $pendingComponents->attach($component);
            $resolve = function () use ($pendingComponents, $component, $pending) {
                $pendingComponents->detach($component);
                if ($pendingComponents->count() === 0) {
                    $pending->resolve();
                }
            };
            // TODO: What should we do in case they don't?
            $component->stopDb()->then($resolve);
        }

        return $pending->promise();
    }

    /**
     * @return \React\Promise\ExtendedPromiseInterface
     */
    public function disconnect()
    {
        if (! $this->db) {
            return resolve();
        }
        if ($this->pendingDisconnect) {
            return $this->pendingDisconnect->promise();
        }

        $this->eventuallySetStopped();
        $this->pendingDisconnect = $this->stopRegisteredComponents();

        try {
            if ($this->db) {
                $this->db->closeConnection();
            }
        } catch (Exception $e) {
            Logger::error('Failed to disconnect: ' . $e->getMessage());
        }

        return $this->pendingDisconnect->promise()->then(function () {
            $this->db = null;
            $this->pendingDisconnect = null;
        });
    }

    protected function emitStatus($message, $level = 'info')
    {
        $this->emit('state', [$message, $level]);

        return $this;
    }

    protected function hasAnyOtherActiveInstance(ZfDb $db)
    {
        return (int) $db->fetchOne(
            $db->select()
                ->from(self::TABLE_NAME, 'COUNT(*)')
                ->where('ts_stopped IS NULL')
        ) > 0;
    }

    protected function wipeOrphanedInstances(ZfDb $db)
    {
        $db->delete(self::TABLE_NAME, 'ts_stopped IS NOT NULL');
        $db->delete(self::TABLE_NAME, $db->quoteInto(
            'instance_uuid_hex = ?',
            $this->details->getInstanceUuid()
        ));
        $count = $db->delete(
            self::TABLE_NAME,
            'ts_stopped IS NULL AND ts_last_update < ' . (
                DaemonUtil::timestampWithMilliseconds() - (60 * 1000)
            )
        );
        if ($count > 1) {
            Logger::error("Removed $count orphaned daemon instance(s) from DB");
        }
    }

    protected function refreshMyState()
    {
        if ($this->db === null || $this->pendingReconnection || $this->pendingDisconnect) {
            return;
        }
        try {
            $updated = $this->db->update(
                self::TABLE_NAME,
                $this->details->getPropertiesToUpdate(),
                $this->db->quoteInto('instance_uuid_hex = ?', $this->details->getInstanceUuid())
            );

            if (! $updated) {
                $this->db->insert(
                    self::TABLE_NAME,
                    $this->details->getPropertiesToInsert()
                );
            }
        } catch (Exception $e) {
            Logger::error($e->getMessage());
            $this->reconnect();
        }
    }

    protected function eventuallySetStopped()
    {
        try {
            if (! $this->db) {
                return;
            }
            $this->db->update(
                self::TABLE_NAME,
                ['ts_stopped' => DaemonUtil::timestampWithMilliseconds()],
                $this->db->quoteInto('instance_uuid_hex = ?', $this->details->getInstanceUuid())
            );
        } catch (Exception $e) {
            Logger::error('Failed to update daemon info (setting ts_stopped): ' . $e->getMessage());
        }
    }
}
