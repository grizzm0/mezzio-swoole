<?php

declare(strict_types=1);

namespace MezzioTest\Swoole;

use Mezzio\Swoole\Event\AfterReloadEvent;
use Mezzio\Swoole\Event\BeforeReloadEvent;
use Mezzio\Swoole\Event\ManagerStartEvent;
use Mezzio\Swoole\Event\ManagerStopEvent;
use Mezzio\Swoole\Event\RequestEvent;
use Mezzio\Swoole\Event\ServerShutdownEvent;
use Mezzio\Swoole\Event\ServerStartEvent;
use Mezzio\Swoole\Event\TaskEvent;
use Mezzio\Swoole\Event\TaskFinishEvent;
use Mezzio\Swoole\Event\WorkerErrorEvent;
use Mezzio\Swoole\Event\WorkerStartEvent;
use Mezzio\Swoole\Event\WorkerStopEvent;
use Mezzio\Swoole\Exception\InvalidArgumentException;
use Mezzio\Swoole\SwooleRequestHandlerRunner;
use PHPUnit\Framework\MockObject\MockObject;
use PHPUnit\Framework\TestCase;
use Psr\EventDispatcher\EventDispatcherInterface;
use Swoole\Http\Request as SwooleHttpRequest;
use Swoole\Http\Response as SwooleHttpResponse;
use Swoole\Http\Server as SwooleHttpServer;

use function random_int;

use const SWOOLE_BASE;
use const SWOOLE_PROCESS;

class SwooleRequestHandlerRunnerTest extends TestCase
{
    /** @var EventDispatcherInterface&MockObject */
    private EventDispatcherInterface $dispatcher;

    /** @var SwooleHttpServer&MockObject */
    private SwooleHttpServer $httpServer;

    private SwooleRequestHandlerRunner $runner;

    public function setUp(): void
    {
        $this->httpServer = $this->createMock(SwooleHttpServer::class);
        $this->dispatcher = $this->createMock(EventDispatcherInterface::class);

        $this->httpServer->expects($this->atLeastOnce())->method('getMasterPid')->willReturn(0);
        $this->httpServer->expects($this->atLeastOnce())->method('getManagerPid')->willReturn(0);

        $this->runner = new SwooleRequestHandlerRunner($this->httpServer, $this->dispatcher);
    }

    public function testConstructorRaisesExceptionWhenMasterPidIsNotZero(): void
    {
        /** @var SwooleHttpServer&MockObject $httpServer */
        $httpServer = $this->createMock(SwooleHttpServer::class);
        $httpServer->expects($this->once())->method('getMasterPid')->willReturn(1);
        $httpServer->expects($this->never())->method('getManagerPid');

        $this->expectException(InvalidArgumentException::class);
        $this->expectExceptionMessage('already been started');

        new SwooleRequestHandlerRunner($httpServer, $this->dispatcher);
    }

    public function testConstructorRaisesExceptionWhenManagerPidIsNotZero(): void
    {
        /** @var SwooleHttpServer&MockObject $httpServer */
        $httpServer = $this->createMock(SwooleHttpServer::class);
        $httpServer->expects($this->once())->method('getMasterPid')->willReturn(0);
        $httpServer->expects($this->once())->method('getManagerPid')->willReturn(1);

        $this->expectException(InvalidArgumentException::class);
        $this->expectExceptionMessage('already been started');
        new SwooleRequestHandlerRunner($httpServer, $this->dispatcher);
    }

    public function testRunRegistersExpectedHttpServerListenersAndStartsServerWhenInBaseMode(): void
    {
        $this->httpServer->mode = SWOOLE_BASE;
        $this->httpServer
            ->expects($this->exactly(10))
            ->method('on')
            ->willReturnMap([
                ['managerstart', [$this->runner, 'onManagerStart'], true],
                ['managerstop', [$this->runner, 'onManagerStop'], true],
                ['workerstart', [$this->runner, 'onWorkerStart'], true],
                ['workerstop', [$this->runner, 'onWorkerStop'], true],
                ['workererror', [$this->runner, 'onWorkerError'], true],
                ['request', [$this->runner, 'onRequest'], true],
                ['beforereload', [$this->runner, 'onBeforeReload'], true],
                ['afterreload', [$this->runner, 'onAfterReload'], true],
                ['task', [$this->runner, 'onTask'], true],
                ['finish', [$this->runner, 'onTaskFinish'], true],
            ]);

        $this->httpServer
            ->expects($this->once())
            ->method('start');

        $this->runner->run();
    }

    public function testRunRegistersExpectedHttpServerListenersAndStartsServerWhenInProcessMode(): void
    {
        $this->httpServer->mode = SWOOLE_PROCESS;
        $this->httpServer
            ->expects($this->exactly(12))
            ->method('on')
            ->willReturnMap([
                ['start', [$this->runner, 'onStart'], true],
                ['shutdown', [$this->runner, 'onShutdown'], true],
                ['managerstart', [$this->runner, 'onManagerStart'], true],
                ['managerstop', [$this->runner, 'onManagerStop'], true],
                ['workerstart', [$this->runner, 'onWorkerStart'], true],
                ['workerstop', [$this->runner, 'onWorkerStop'], true],
                ['workererror', [$this->runner, 'onWorkerError'], true],
                ['request', [$this->runner, 'onRequest'], true],
                ['beforereload', [$this->runner, 'onBeforeReload'], true],
                ['afterreload', [$this->runner, 'onAfterReload'], true],
                ['task', [$this->runner, 'onTask'], true],
                ['finish', [$this->runner, 'onTaskFinish'], true],
            ]);

        $this->httpServer
            ->expects($this->once())
            ->method('start');

        $this->runner->run();
    }

    public function testOnStartDispatchesServerStartEvent(): void
    {
        $this->dispatcher
            ->expects($this->once())
            ->method('dispatch')
            ->with($this->equalTo(new ServerStartEvent($this->httpServer)));

        $this->runner->onStart($this->httpServer);
    }

    public function testOnManagerStartDispatchesManagerStartEvent(): void
    {
        $this->dispatcher
            ->expects($this->once())
            ->method('dispatch')
            ->with($this->equalTo(new ManagerStartEvent($this->httpServer)));

        $this->runner->onManagerStart($this->httpServer);
    }

    public function testOnManagerStopDispatchesManagerStopEvent(): void
    {
        $this->dispatcher
            ->expects($this->once())
            ->method('dispatch')
            ->with($this->equalTo(new ManagerStopEvent($this->httpServer)));

        $this->runner->onManagerStop($this->httpServer);
    }

    public function testOnWorkerStartDispatchesWorkerStartEvent(): void
    {
        $workerId = random_int(1, 4);
        $this->dispatcher
            ->expects($this->once())
            ->method('dispatch')
            ->with($this->equalTo(new WorkerStartEvent($this->httpServer, $workerId)));

        $this->runner->onWorkerStart($this->httpServer, $workerId);
    }

    public function testOnWorkerStopDispatchesWorkerStopEvent(): void
    {
        $workerId = random_int(1, 4);
        $this->dispatcher
            ->expects($this->once())
            ->method('dispatch')
            ->with($this->equalTo(new WorkerStopEvent($this->httpServer, $workerId)));

        $this->runner->onWorkerStop($this->httpServer, $workerId);
    }

    public function testOnWorkerErrorDispatchesWorkerErrorEvent(): void
    {
        $workerId = random_int(1, 4);
        $exitCode = random_int(1, 127);
        $signal   = random_int(1, 7);
        $this->dispatcher
            ->expects($this->once())
            ->method('dispatch')
            ->with($this->equalTo(new WorkerErrorEvent($this->httpServer, $workerId, $exitCode, $signal)));

        $this->runner->onWorkerError($this->httpServer, $workerId, $exitCode, $signal);
    }

    public function testOnRequestDispatchesRequestEvent(): void
    {
        $request  = $this->createMock(SwooleHttpRequest::class);
        $response = $this->createMock(SwooleHttpResponse::class);
        $this->dispatcher
            ->expects($this->once())
            ->method('dispatch')
            ->with($this->equalTo(new RequestEvent($request, $response)));

        $this->runner->onRequest($request, $response);
    }

    public function testOnShutdownDispatchesServerShutdownEvent(): void
    {
        $this->dispatcher
            ->expects($this->once())
            ->method('dispatch')
            ->with($this->equalTo(new ServerShutdownEvent($this->httpServer)));

        $this->runner->onShutdown($this->httpServer);
    }

    public function testOnBeforeReloadDispatchesBeforeReloadEvent(): void
    {
        $this->dispatcher
            ->expects($this->once())
            ->method('dispatch')
            ->with($this->equalTo(new BeforeReloadEvent($this->httpServer)));

        $this->runner->onBeforeReload($this->httpServer);
    }

    public function testOnAfterReloadDispatchesAfterReloadEvent(): void
    {
        $this->dispatcher
            ->expects($this->once())
            ->method('dispatch')
            ->with($this->equalTo(new AfterReloadEvent($this->httpServer)));

        $this->runner->onAfterReload($this->httpServer);
    }

    public function testOnTaskFinishDispatchesTaskFinishEvent(): void
    {
        $this->dispatcher
            ->expects($this->once())
            ->method('dispatch')
            ->with($this->equalTo(new TaskFinishEvent($this->httpServer, 1, 'computed value')));

        $this->runner->onTaskFinish($this->httpServer, 1, 'computed value');
    }

    public function testOnTaskDispatchesTaskEventUsingStandardArguments(): void
    {
        $expected = 'computed';

        $server = $this->httpServer;
        $server
            ->expects($this->once())
            ->method('finish')
            ->with($expected);

        $this->dispatcher
            ->expects($this->once())
            ->method('dispatch')
            ->with($this->callback(static function (TaskEvent $event) use ($server, $expected): bool {
                if (
                    $event->getServer() !== $server
                    || $event->getTaskId() !== 1
                    || $event->getWorkerId() !== 10
                    || $event->getData() !== ['values', 'to', 'process']
                ) {
                    return false;
                }

                $event->setReturnValue($expected);

                return true;
            }))
            ->willReturnArgument(0);

        $this->runner->onTask($this->httpServer, 1, 10, ['values', 'to', 'process']);
    }

    /**
     * When task coroutines are enabled, a task object is provided instead.
     */
    public function testOnTaskDispatchesTaskEventUsingTaskObject(): void
    {
        $expected = 'computed';

        $server = $this->httpServer;
        $server
            ->expects($this->once())
            ->method('finish')
            ->with($expected);

        $this->dispatcher
            ->expects($this->once())
            ->method('dispatch')
            ->with($this->callback(static function (TaskEvent $event) use ($server, $expected): bool {
                if (
                    $event->getServer() !== $server
                    || $event->getTaskId() !== 1
                    || $event->getWorkerId() !== 10
                    || $event->getData() !== ['values', 'to', 'process']
                ) {
                    return false;
                }

                $event->setReturnValue($expected);

                return true;
            }))
            ->willReturnArgument(0);

        $task = new class ($server) {
            public int $id = 1;

            // phpcs:ignore
            public int $worker_id = 10;

            public array $data = ['values', 'to', 'process'];

            private SwooleHttpServer $server;

            public function __construct(SwooleHttpServer $server)
            {
                $this->server = $server;
            }

            public function finish(string $returnValue): void
            {
                $this->server->finish($returnValue);
            }
        };

        $this->runner->onTask($this->httpServer, $task);
    }
}
