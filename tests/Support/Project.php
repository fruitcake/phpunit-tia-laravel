<?php

declare(strict_types=1);

namespace Fruitcake\PhpUnitTia\Laravel\Tests\Support;

use RuntimeException;
use Symfony\Component\Process\Process;

/**
 * A copy of tests/Fixtures/app in its own git repository, run with the real
 * PHPUnit binary and a coverage driver, as Pest's TIA feature tests do.
 *
 * The fixture shares this package's vendor/ through a symlink, so it runs
 * against the working copy of the package.
 */
final class Project
{
    private const string FIXTURE = __DIR__.'/../Fixtures/app';

    private const array GIT_ENV = [
        'GIT_AUTHOR_NAME' => 'Test',
        'GIT_AUTHOR_EMAIL' => 'test@example.com',
        'GIT_COMMITTER_NAME' => 'Test',
        'GIT_COMMITTER_EMAIL' => 'test@example.com',
        'GIT_CONFIG_NOSYSTEM' => '1',
    ];

    private function __construct(public readonly string $path) {}

    /**
     * A committed copy of the fixture on `main`, without a graph yet.
     */
    public static function make(): self
    {
        $project = new self(self::temporaryDirectory());

        self::copy(self::FIXTURE, $project->path);
        symlink(dirname(__DIR__, 2).'/vendor', $project->path.'/vendor');

        $project->git('init', '-q', '-b', 'main');
        $project->commit('Initial commit');

        return $project;
    }

    /**
     * An independent copy of this project, graph and git history included.
     */
    public function clone(): self
    {
        $clone = new self(self::temporaryDirectory());

        self::run(['cp', '-a', $this->path.'/.', $clone->path]);

        return $clone;
    }

    public function write(string $relative, string $contents): void
    {
        $path = $this->path.'/'.$relative;

        if (! is_dir(dirname($path))) {
            mkdir(dirname($path), 0755, true);
        }

        file_put_contents($path, $contents);
    }

    public function commit(string $message): void
    {
        $this->git('add', '-A');
        $this->git('commit', '-q', '--allow-empty', '-m', $message);
    }

    /**
     * @param  array<string, string>  $environment
     */
    public function phpunit(array $environment = []): Result
    {
        $junit = $this->path.'/storage/junit.xml';
        @unlink($junit);

        $process = new Process(
            [
                PHP_BINARY,
                '-d', 'pcov.enabled=1',
                '-d', 'pcov.directory='.$this->path,
                '-d', 'xdebug.mode=coverage',
                $this->path.'/vendor/bin/phpunit',
                '--log-junit', $junit,
            ],
            $this->path,
            [...self::GIT_ENV, 'PARATEST' => false, 'PHPUNIT_TIA' => false, 'PHPUNIT_TIA_FRESH' => false, ...$environment],
        );

        $process->setTimeout(120.0);
        $process->run();

        $output = $process->getOutput().$process->getErrorOutput();

        if (! is_file($junit)) {
            throw new RuntimeException("PHPUnit wrote no JUnit log:\n".$output);
        }

        return Result::fromJunit((string) file_get_contents($junit), $output, (int) $process->getExitCode());
    }

    public function destroy(): void
    {
        self::run(['rm', '-rf', $this->path]);
    }

    public static function coverageDriverAvailable(): bool
    {
        return extension_loaded('pcov') || extension_loaded('xdebug');
    }

    private function git(string ...$arguments): void
    {
        self::run(['git', ...$arguments], $this->path);
    }

    /**
     * @param  list<string>  $command
     */
    private static function run(array $command, ?string $cwd = null): void
    {
        $process = new Process($command, $cwd, self::GIT_ENV);
        $process->mustRun();
    }

    private static function copy(string $from, string $to): void
    {
        self::run(['cp', '-R', $from.'/.', $to]);
    }

    private static function temporaryDirectory(): string
    {
        $path = sys_get_temp_dir().'/phpunit-tia-laravel-'.bin2hex(random_bytes(6));
        mkdir($path, 0755, true);

        return (string) realpath($path);
    }
}
