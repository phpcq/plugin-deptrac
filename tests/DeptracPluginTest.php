<?php

declare(strict_types=1);

namespace Phpcq\DeptracPluginTest;

use Phpcq\PluginApi\Version10\Configuration\Builder\BoolOptionBuilderInterface;
use Phpcq\PluginApi\Version10\Configuration\Builder\StringOptionBuilderInterface;
use Phpcq\PluginApi\Version10\Configuration\PluginConfigurationBuilderInterface;
use Phpcq\PluginApi\Version10\Configuration\PluginConfigurationInterface;
use Phpcq\PluginApi\Version10\DiagnosticsPluginInterface;
use Phpcq\PluginApi\Version10\EnvironmentInterface;
use Phpcq\PluginApi\Version10\ProjectConfigInterface;
use Phpcq\PluginApi\Version10\Task\PhpTaskBuilderInterface;
use Phpcq\PluginApi\Version10\Task\TaskFactoryInterface;
use Phpcq\PluginApi\Version10\Task\TaskInterface;
use PHPUnit\Framework\TestCase;

/**
 * @coversNothing
 */
final class DeptracPluginTest extends TestCase
{
    private function instantiate(): DiagnosticsPluginInterface
    {
        return include dirname(__DIR__) . '/src/deptrac.php';
    }

    public function testPluginName(): void
    {
        self::assertSame('deptrac', $this->instantiate()->getName());
    }

    public function testPluginDescribesAllConfigurationOptions(): void
    {
        $stringOptions = [];
        $boolOptions   = [];

        $configOptionsBuilder = $this->createStub(PluginConfigurationBuilderInterface::class);
        $configOptionsBuilder
            ->method('describeStringOption')
            ->willReturnCallback(function (string $name) use (&$stringOptions): StringOptionBuilderInterface {
                $stringOptions[] = $name;

                return $this->createStub(StringOptionBuilderInterface::class);
            });
        $configOptionsBuilder
            ->method('describeBoolOption')
            ->willReturnCallback(function (string $name) use (&$boolOptions): BoolOptionBuilderInterface {
                $boolOptions[] = $name;

                return $this->createStub(BoolOptionBuilderInterface::class);
            });

        $this->instantiate()->describeConfiguration($configOptionsBuilder);

        self::assertSame(['config-file', 'cache-file'], $stringOptions);
        self::assertSame(
            ['no-cache', 'fail-on-uncovered', 'report-uncovered', 'report-skipped'],
            $boolOptions
        );
    }

    public function testPluginCreatesExactlyOneDiagnosticTask(): void
    {
        [$tasks] = $this->runCreateDiagnosticTasks($this->createStub(PluginConfigurationInterface::class));

        self::assertCount(1, $tasks);
        self::assertInstanceOf(TaskInterface::class, $tasks[0]);
    }

    public function testDiagnosticTaskRunsComposerInstalledBinaryAsPhpProcess(): void
    {
        [, $toolName, $command] = $this->runCreateDiagnosticTasks(
            $this->createStub(PluginConfigurationInterface::class)
        );

        self::assertSame('deptrac', $toolName);
        self::assertSame('/installed-dir/vendor/bin/deptrac', $command[0]);
    }

    public function testDiagnosticTaskBuildsBaseArgumentsWithoutOptionalConfiguration(): void
    {
        // A bare config where has() returns false for every option.
        [, , $command] = $this->runCreateDiagnosticTasks(
            $this->createStub(PluginConfigurationInterface::class)
        );

        self::assertSame(
            [
                '/installed-dir/vendor/bin/deptrac',
                'analyse',
                '--no-progress',
                '--formatter=json',
                '--output=/tmp/deptrac-report.json',
            ],
            $command
        );
    }

    public function testDiagnosticTaskAppendsConfiguredArguments(): void
    {
        $config = $this->createStub(PluginConfigurationInterface::class);
        $config->method('has')->willReturn(true);
        $config->method('getBool')->willReturn(true);
        $config->method('getString')->willReturnMap([
            ['config-file', 'config/deptrac.yaml'],
            ['cache-file', 'var/deptrac.cache'],
        ]);

        [, , $command] = $this->runCreateDiagnosticTasks($config);

        self::assertContains('--config-file=config/deptrac.yaml', $command);
        self::assertContains('--no-cache', $command);
        self::assertContains('--cache-file=var/deptrac.cache', $command);
        self::assertContains('--fail-on-uncovered', $command);
        self::assertContains('--report-uncovered', $command);
        self::assertContains('--report-skipped', $command);
    }

    public function testDiagnosticTaskOmitsDisabledBooleanArguments(): void
    {
        $config = $this->createStub(PluginConfigurationInterface::class);
        // Options are present, but every boolean switch is turned off.
        $config->method('has')->willReturnMap([
            ['config-file', false],
            ['cache-file', false],
            ['no-cache', true],
            ['fail-on-uncovered', true],
            ['report-uncovered', true],
            ['report-skipped', true],
        ]);
        $config->method('getBool')->willReturn(false);

        [, , $command] = $this->runCreateDiagnosticTasks($config);

        self::assertNotContains('--no-cache', $command);
        self::assertNotContains('--fail-on-uncovered', $command);
        self::assertNotContains('--report-uncovered', $command);
        self::assertNotContains('--report-skipped', $command);
    }

    /**
     * Executes createDiagnosticTasks() with a stubbed environment and captures the process invocation.
     *
     * @return array{0: list<TaskInterface>, 1: string, 2: list<string>}
     */
    private function runCreateDiagnosticTasks(PluginConfigurationInterface $config): array
    {
        $toolName = '';
        $command  = [];

        $taskBuilder = $this->createStub(PhpTaskBuilderInterface::class);
        $taskBuilder->method('withWorkingDirectory')->willReturnSelf();
        $taskBuilder->method('withOutputTransformer')->willReturnSelf();
        $taskBuilder->method('build')->willReturn($this->createStub(TaskInterface::class));

        $taskFactory = $this->createStub(TaskFactoryInterface::class);
        $taskFactory
            ->method('buildPhpProcess')
            ->willReturnCallback(
                function (string $tool, array $cmd) use (&$toolName, &$command, $taskBuilder): PhpTaskBuilderInterface {
                    $toolName = $tool;
                    $command  = $cmd;

                    return $taskBuilder;
                }
            );

        $projectConfig = $this->createStub(ProjectConfigInterface::class);
        $projectConfig->method('getProjectRootPath')->willReturn('/project-root');

        $environment = $this->createStub(EnvironmentInterface::class);
        $environment->method('getProjectConfiguration')->willReturn($projectConfig);
        $environment->method('getTaskFactory')->willReturn($taskFactory);
        $environment->method('getInstalledDir')->willReturn('/installed-dir');
        $environment->method('getUniqueTempFile')->willReturn('/tmp/deptrac-report.json');

        $tasks = iterator_to_array($this->instantiate()->createDiagnosticTasks($config, $environment), false);

        return [$tasks, $toolName, $command];
    }
}
