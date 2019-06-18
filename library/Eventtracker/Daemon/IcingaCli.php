<?php

namespace Icinga\Module\Eventtracker\Daemon;

use Evenement\EventEmitterTrait;
use gipfl\Protocol\JsonRpc\Connection;
use React\EventLoop\LoopInterface;
use React\Promise\Deferred;

class IcingaCli
{
    use EventEmitterTrait;

    /** @var IcingaCliRunner */
    protected $runner;

    /** @var Connection|null */
    protected $rpc;

    protected $arguments = [];

    public function __construct(IcingaCliRunner $runner = null)
    {
        if ($runner === null) {
            $runner = IcingaCliRunner::forArgv();
        }
        $this->runner = $runner;
        $this->init();
    }

    protected function init()
    {
        // Override this if you want.
    }

    public function setArguments($arguments)
    {
        $this->arguments = $arguments;

        return $this;
    }

    public function getArguments()
    {
        return $this->arguments;
    }

    public function run(LoopInterface $loop)
    {
        $process = $this->runner->command($this->getArguments());
        $canceller = function () use ($process) {
            // TODO: first soft, then hard
            $process->terminate();
        };
        $deferred = new Deferred($canceller);

        $process->on('exit', function ($exitCode, $termSignal) use ($deferred) {
            $state = new FinishedProcessState($exitCode, $termSignal);
            if ($state->succeeded()) {
                $deferred->resolve();
            } else {
                $deferred->reject($state);
            }
        });
        $process->start($loop);
        $this->emit('start', [$process]);

        return $deferred->promise();
    }
}
