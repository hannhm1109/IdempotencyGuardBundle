<?php

declare(strict_types=1);

namespace Hanane\IdempotencyGuardBundle\Tests\Integration;

use PHPUnit\Framework\TestCase;
use Symfony\Component\Filesystem\Filesystem;
use Symfony\Component\Process\Process;

final class ConcurrencyTest extends TestCase
{
    public function testSeparatePhpProcessesCannotExecuteTheSameOperationSimultaneously(): void
    {
        $testRoot = sys_get_temp_dir().'/idempotency-guard-bundle-tests';
        $directory = $testRoot.'/concurrency-'.bin2hex(random_bytes(12));
        $files = new Filesystem();
        $files->mkdir($directory);
        $first = $this->worker($directory, 'first');
        try {
            $first->start();
            $deadline = microtime(true) + 10;
            while (!is_file($directory.'/entered')) {
                clearstatcache();
                if (!$first->isRunning()) {
                    self::fail('First worker exited early: '.$first->getErrorOutput());
                }
                if (microtime(true) > $deadline) {
                    self::fail('First worker never entered the controller.');
                }
                usleep(10000);
            }

            // The marker proves that A holds the lock inside the controller while B runs.
            $competitor = $this->worker($directory, 'competitor');
            $competitor->mustRun();
            $conflict = json_decode($competitor->getOutput(), true, flags: JSON_THROW_ON_ERROR);
            self::assertSame(409, $conflict['status']);
            self::assertSame('{"error":"IDEMPOTENCY_REQUEST_IN_PROGRESS"}', $conflict['body']);
            self::assertSame("executed\n", file_get_contents($directory.'/executions'));

            file_put_contents($directory.'/continue', 'finish');
            self::assertSame(0, $first->wait(), $first->getErrorOutput());
            $original = json_decode($first->getOutput(), true, flags: JSON_THROW_ON_ERROR);
            self::assertSame(201, $original['status']);

            $retry = $this->worker($directory, 'retry');
            $retry->mustRun();
            self::assertSame($original, json_decode($retry->getOutput(), true, flags: JSON_THROW_ON_ERROR));
            self::assertSame("executed\n", file_get_contents($directory.'/executions'));
        } finally {
            if ($first->isRunning()) {
                file_put_contents($directory.'/continue', 'finish');
                $first->stop();
            }
            // Verify the resolved target is beneath the named test root before recursive cleanup.
            $resolvedRoot = realpath($testRoot);
            $resolvedDirectory = realpath($directory);
            if ($resolvedRoot !== false && $resolvedDirectory !== false
                && str_starts_with($resolvedDirectory, $resolvedRoot.DIRECTORY_SEPARATOR)) {
                $files->remove($resolvedDirectory);
            }
        }
    }

    private function worker(string $directory, string $role): Process
    {
        return new Process([PHP_BINARY, dirname(__DIR__).'/Fixtures/concurrency_worker.php', $directory, $role], timeout: 20);
    }
}
