<?php
declare(strict_types=1);

namespace wise\agent\command;

use think\console\Command;
use think\console\Input;
use think\console\input\Option;
use think\console\Output;

/**
 * Wise-Agent initialization command
 *
 * Publishes config and migration files to the application.
 *
 * Usage:
 *   php think wise-agent:publish [--force]
 */
class Publish extends Command
{
    protected function configure()
    {
        $this->setName('wise-agent:publish')
            ->setDescription('Publish config and migration files of wise-admin/wise-agent package')
            ->addOption('force', 'f', Option::VALUE_NONE, 'Force overwrite existing config and migration files');
    }

    protected function execute(Input $input, Output $output): int
    {
        $force = $input->getOption('force');

        $output->writeln('');
        $output->writeln('<info>========================================</info>');
        $output->writeln('<info>wiseadmin/wise-agent - Initialization</info>');
        $output->writeln('<info>========================================</info>');
        $output->writeln('');

        // 1. Publish config file
        $this->publishConfig($output, $force);

        // 2. Copy migration files
        $migrationsPublished = $this->publishMigrations($output, $force);

        // 3. Prompt to run migrations
        $output->writeln('');
        if ($migrationsPublished) {
            $output->writeln('<info>Please run the following command to execute migrations:</info>');
            $output->writeln('<comment>  php think migrate:run</comment>');
        }
        $output->writeln('');
        $output->writeln('<info>Wise-Agent initialized successfully!</info>');

        return 0;
    }

    /**
     * Publish config file
     */
    protected function publishConfig(Output $output, bool $force): void
    {
        $source = dirname(__DIR__, 2) . '/config/wise-agent.php';
        $target = $this->app->getConfigPath() . 'wise-agent.php';

        if (file_exists($target) && !$force) {
            $output->writeln("<comment>Config file already exists at {$target}. Use --force to overwrite.</comment>");
            return;
        }

        $targetDir = dirname($target);
        if (!is_dir($targetDir)) {
            mkdir($targetDir, 0755, true);
        }

        if (copy($source, $target)) {
            $output->writeln("<info>Config published to: {$target}</info>");
        } else {
            $output->writeln("<error>Failed to publish config to: {$target}</error>");
        }
    }

    /**
     * Copy migration files to application database/migrations directory
     */
    protected function publishMigrations(Output $output, bool $force = false): bool
    {
        $sourceDir = dirname(__DIR__, 2) . '/database/migrations';
        $targetDir = $this->app->getRootPath() . 'database' . DIRECTORY_SEPARATOR . 'migrations';

        if (!is_dir($sourceDir)) {
            return false;
        }

        if (!is_dir($targetDir)) {
            mkdir($targetDir, 0755, true);
        }

        $copied = 0;
        $files = glob($sourceDir . DIRECTORY_SEPARATOR . '*.php') ?: [];
        foreach ($files as $file) {
            $name = basename($file);
            $dest = $targetDir . DIRECTORY_SEPARATOR . $name;
            if (file_exists($dest) && !$force) {
                $output->writeln("  - [skip] {$name}");
                continue;
            }
            if (file_exists($dest) && $force) {
                $output->writeln("  - [overwrite] {$name}");
            }
            if (copy($file, $dest)) {
                $output->writeln("  - <info>[ok]</info> {$name}");
                $copied++;
            } else {
                $output->writeln("  - <error>[fail]</error> {$name}");
            }
        }

        if ($copied > 0) {
            $output->writeln("<info>Migration files published: {$copied} file(s) to {$targetDir}</info>");
            return true;
        }

        return false;
    }
}
