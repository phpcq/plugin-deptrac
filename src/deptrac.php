<?php

declare(strict_types=1);

use Phpcq\PluginApi\Version10\Configuration\PluginConfigurationBuilderInterface;
use Phpcq\PluginApi\Version10\Configuration\PluginConfigurationInterface;
use Phpcq\PluginApi\Version10\DiagnosticsPluginInterface;
use Phpcq\PluginApi\Version10\EnvironmentInterface;
use Phpcq\PluginApi\Version10\Output\OutputInterface;
use Phpcq\PluginApi\Version10\Output\OutputTransformerFactoryInterface;
use Phpcq\PluginApi\Version10\Output\OutputTransformerInterface;
use Phpcq\PluginApi\Version10\Report\TaskReportInterface;

return new class implements DiagnosticsPluginInterface {
    public function getName(): string
    {
        return 'deptrac';
    }

    public function describeConfiguration(PluginConfigurationBuilderInterface $configOptionsBuilder): void
    {
        $configOptionsBuilder
            ->describeStringOption('config-file', 'Path to the config file (Default deptrac.yaml)');

        $configOptionsBuilder->describeStringOption('cache-file', 'Path to the cache file');

        $configOptionsBuilder
            ->describeBoolOption('no-cache', 'Disable caching mechanisms (wins over cache-file)')
            ->withDefaultValue(false);

        $configOptionsBuilder
            ->describeBoolOption('fail-on-uncovered', 'Fails if any uncovered dependency is found')
            ->withDefaultValue(false);

        $configOptionsBuilder
            ->describeBoolOption('report-uncovered', 'Report uncovered dependencies')
            ->withDefaultValue(false);

        $configOptionsBuilder
            ->describeBoolOption('report-skipped', 'Report skipped violations')
            ->withDefaultValue(false);
    }

    public function createDiagnosticTasks(
        PluginConfigurationInterface $config,
        EnvironmentInterface $environment
    ): iterable {
        $projectRoot = $environment->getProjectConfiguration()->getProjectRootPath();
        $outputFile  = $environment->getUniqueTempFile($this, 'report.json');
        $arguments   = [
            'analyse',
            '--no-progress',
            '--formatter=json',
            '--output=' . $outputFile,
        ];

        if ($config->has('config-file')) {
            $arguments[] = '--config-file=' . $config->getString('config-file');
        }

        if ($config->has('no-cache') && $config->getBool('no-cache')) {
            $arguments[] = '--no-cache';
        }

        if ($config->has('cache-file')) {
            $arguments[] = '--cache-file=' . $config->getString('cache-file');
        }

        if ($config->has('fail-on-uncovered') && $config->getBool('fail-on-uncovered')) {
            $arguments[] = '--fail-on-uncovered';
        }

        if ($config->has('report-uncovered') && $config->getBool('report-uncovered')) {
            $arguments[] = '--report-uncovered';
        }

        if ($config->has('report-skipped') && $config->getBool('report-skipped')) {
            $arguments[] = '--report-skipped';
        }

        yield $environment->getTaskFactory()
            ->buildRunPhar('deptrac', $arguments)
            ->withWorkingDirectory($projectRoot)
            ->withOutputTransformer($this->createOutputTransformer($projectRoot, $outputFile))
            ->build();
    }

    private function createOutputTransformer(
        string $rootDir,
        string $tempFile
    ): OutputTransformerFactoryInterface {
        return new class ($rootDir, $tempFile) implements OutputTransformerFactoryInterface {
            /** @var string */
            private $rootDir;
            /** @var string */
            private $tempFile;

            public function __construct(string $rootDir, string $tempFile)
            {
                $this->rootDir = $rootDir;
                $this->tempFile = $tempFile;
            }

            public function createFor(TaskReportInterface $report): OutputTransformerInterface
            {
                return new class ($report, $this->rootDir, $this->tempFile) implements OutputTransformerInterface {
                    /** @var TaskReportInterface $report */
                    private $report;
                    /** @var string */
                    private $rootDir;
                    /** @var string */
                    private $buffer = '';
                    /** @var string */
                    private $errors = '';
                    /** @var string */
                    private $tempFile;

                    public function __construct(TaskReportInterface $report, string $rootDir, string $tempFile)
                    {
                        $this->report = $report;
                        $this->rootDir = $rootDir;
                        $this->tempFile = $tempFile;
                    }

                    public function write(string $data, int $channel): void
                    {
                        switch ($channel) {
                            case OutputInterface::CHANNEL_STDOUT:
                                $this->buffer .= $data;
                                break;
                            case OutputInterface::CHANNEL_STDERR:
                                $this->errors .= $data;
                        }
                    }

                    public function finish(int $exitCode): void
                    {
                        if ($this->errors) {
                            $this->report
                                ->addAttachment('error.log')
                                ->fromString($this->errors)
                                ->setMimeType('text/plain');
                        }

                        if ($this->buffer) {
                            $this->report
                                ->addAttachment('output.log')
                                ->fromString($this->buffer)
                                ->setMimeType('text/plain');
                        }

                        $this->report
                            ->addAttachment('report.json')
                            ->fromFile($this->tempFile)
                            ->setMimeType('text/json');

                        if (!file_exists($this->tempFile)) {
                            $this->report->close(TaskReportInterface::STATUS_FAILED);
                            return;
                        }

                        $report = json_decode(file_get_contents($this->tempFile), true);
                        if (!is_array($report)) {
                            $this->report->close(TaskReportInterface::STATUS_FAILED);
                            return;
                        }

                        foreach ($report['files'] as $file => $data) {
                            $added = [];

                            foreach ($data['messages'] as $message) {
                                $severity = $this->getSeverity($message['type']);
                                if ($severity === null) {
                                    continue;
                                }

                                if (in_array($message, $added, true)) {
                                    continue;
                                }

                                $added[]  = $message;
                                $exitCode = 1;

                                [$class] = explode(' ', $message['message'], 2);

                                $this->report
                                    ->addDiagnostic($severity, $message['message'])
                                    ->forClass($class)
                                    ->forFile($this->stripRootDir($file))
                                    ->forRange($message['line']);
                            }
                        }

                        $this->report->close(
                            $exitCode === 0 ? TaskReportInterface::STATUS_PASSED : TaskReportInterface::STATUS_FAILED
                        );
                    }

                    private function getSeverity(string $type): ?string
                    {
                        switch ($type) {
                            case 'error':
                                return TaskReportInterface::SEVERITY_MAJOR;

                            case 'warning':
                                return TaskReportInterface::SEVERITY_MINOR;
                        }

                        return null;
                    }

                    private function stripRootDir(string $content): string
                    {
                        return str_replace($this->rootDir . '/', '', $content);
                    }
                };
            }
        };
    }
};
