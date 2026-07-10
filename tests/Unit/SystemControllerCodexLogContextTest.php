<?php

namespace Tests\Unit;

use App\Http\Controllers\Admin\SystemController;
use App\Services\System\EnvFileService;
use Illuminate\Support\Facades\File;
use Tests\TestCase;

class SystemControllerCodexLogContextTest extends TestCase
{
    public function test_codex_log_helpers_use_newest_laravel_log_file(): void
    {
        $logDir = storage_path('logs');
        File::ensureDirectoryExists($logDir);

        $olderPath = $logDir.'/laravel-old-test.log';
        $newerPath = $logDir.'/laravel-new-test.log';

        // Relative to "now" rather than a hardcoded date: a fixed calendar date
        // ages out of the log_recent_minutes window as real time moves forward.
        $logTimestamp = now()->subMinutes(5)->format('Y-m-d H:i:s');

        file_put_contents($olderPath, "[{$logTimestamp}] local.ERROR: old-log-error\n");
        file_put_contents($newerPath, "[{$logTimestamp}] local.ERROR: newest-log-error\n");

        // storage/logs/laravel.log is written to continuously by the rest of the
        // suite running in this same process, so its mtime is always "now" too.
        // Give the fixture we want picked a clear, unambiguous future mtime so
        // resolveLatestLaravelLogPath() can't race it against real log writes.
        touch($olderPath, time() - 120);
        touch($newerPath, time() + 3600);

        config()->set('services.codex.log_recent_minutes', 100000);

        try {
            $controller = new SystemController($this->mock(EnvFileService::class));
            $errors = $this->invokePrivate($controller, 'getRecentErrors', 20);
            $tail = $this->invokePrivate($controller, 'getLogTail', 50);

            $this->assertStringContainsString('Source: laravel-new-test.log', $errors);
            $this->assertStringContainsString('newest-log-error', $errors);
            $this->assertStringNotContainsString('old-log-error', $errors);

            $this->assertStringContainsString('Source: laravel-new-test.log', $tail);
            $this->assertStringContainsString('newest-log-error', $tail);
        } finally {
            // try/finally (not a trailing unlink) so a failed assertion above can
            // never leave these fixtures behind to break later tests in this file.
            @unlink($olderPath);
            @unlink($newerPath);
        }
    }

    public function test_codex_recent_errors_filter_to_recent_window(): void
    {
        $logDir = storage_path('logs');
        File::ensureDirectoryExists($logDir);
        $path = $logDir.'/laravel-window-test.log';

        $oldTimestamp = now()->subHours(4)->format('Y-m-d H:i:s');
        $recentTimestamp = now()->subMinutes(5)->format('Y-m-d H:i:s');

        file_put_contents($path, implode("\n", [
            "[{$oldTimestamp}] local.ERROR: stale error should not be used",
            "[{$recentTimestamp}] local.ERROR: fresh error should be used",
        ]));
        // Same mtime-race issue as the test above: give this fixture an
        // unambiguous future mtime so it's reliably picked as "newest".
        touch($path, time() + 3600);

        config()->set('services.codex.log_recent_minutes', 60);

        try {
            $controller = new SystemController($this->mock(EnvFileService::class));
            $errors = $this->invokePrivate($controller, 'getRecentErrors', 20);

            $this->assertStringContainsString('fresh error should be used', $errors);
            $this->assertStringNotContainsString('stale error should not be used', $errors);
        } finally {
            @unlink($path);
        }
    }

    private function invokePrivate(object $target, string $method, mixed ...$arguments): mixed
    {
        $reflection = new \ReflectionMethod($target, $method);
        $reflection->setAccessible(true);

        return $reflection->invokeArgs($target, $arguments);
    }
}
