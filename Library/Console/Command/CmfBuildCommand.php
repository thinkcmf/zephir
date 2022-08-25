<?php

/**
 * (c) ThinkCMF Team <catman@thinkcmf.com>
 *
 * For the full copyright and license information, please view
 * the LICENSE file that was distributed with this source code.
 */

declare(strict_types=1);

namespace Zephir\Console\Command;


use Symfony\Component\Console\Input\InputArgument;
use Symfony\Component\Console\Input\InputDefinition;
use Symfony\Component\Console\Input\InputInterface;
use Symfony\Component\Console\Input\InputOption;
use Symfony\Component\Console\Output\OutputInterface;

/**
 * Generate Command
 *
 * Generates C code from the Zephir code without compiling it.
 */
final class CmfBuildCommand extends CmfAbstractCommand
{
    use DevelopmentModeAwareTrait;
    use ZflagsAwareTrait;

    protected function configure()
    {
        $this
            ->setName('build')
            ->setDescription('Generates/Compiles/Installs a ThinkCMF PHP extension')
            ->addArgument('namespace', InputArgument::OPTIONAL, 'namespace', '')
            ->setHelp(sprintf('%s.', $this->getDescription()) . PHP_EOL . PHP_EOL . $this->getZflagsHelp());
    }

    protected function execute(InputInterface $input, OutputInterface $output)
    {
        $this->input  = $input;
        $this->output = $output;
        $namespace    = $input->getArgument('namespace');

        if (empty($namespace)) {
            try {
                $this->build('app');
            } catch (\Exception $e) {
                echo $e->getMessage();
                print_r($e->getTraceAsString());
            }

            try {
                $this->build('api');
            } catch (\Exception $e) {
                echo $e->getMessage();
                print_r($e->getTraceAsString());
            }

            try {
                $this->build('plugins');
            } catch (\Exception $e) {
                echo $e->getMessage();
                print_r($e->getTraceAsString());
            }
        } else {
            try {
                $this->build($namespace);
            } catch (\Exception $e) {
                echo $e->getMessage();
                print_r($e->getTraceAsString());
            }
        }

        return 0;
    }

    private function build($namespace)
    {
        echo "Compiling $namespace:\n";
        chdir($this->cwd);
        $this->initCompiler($namespace);

        $result = $this->copyFiles($namespace);

        if (!$result) {
            return;
        }

        $this->compiler->generate(true);
        $this->compiler->compile();
        $this->compiler->install();

        $namespace     = $this->config->get('namespace');
        $extensionName = $this->config->get('extension-name');
        if (empty($extensionName) || !is_string($extensionName)) {
            $extensionName = $namespace;
        }

        if (!extension_loaded($extensionName)) {
            echo sprintf('Add "extension=%s.so" to your php.ini' . "\n", $extensionName);
        }
        echo " Installed\n";

    }



    protected function getDefaultInputDefinition()
    {
        return new InputDefinition([
            new InputArgument('command', InputArgument::REQUIRED, 'The command to execute'),
            new InputOption('--help', '-h', InputOption::VALUE_NONE, 'Display help for the given command. When no command is given display help for the <info>list</info> command'),
            new InputOption('--quiet', '-q', InputOption::VALUE_NONE, 'Do not output any message'),
            new InputOption('--verbose', '-v|vv|vvv', InputOption::VALUE_NONE, 'Increase the verbosity of messages: 1 for normal output, 2 for more verbose output and 3 for debug'),
            new InputOption('--version', '-V', InputOption::VALUE_NONE, 'Display this application version'),
            new InputOption('--ansi', '', InputOption::VALUE_NEGATABLE, 'Force (or disable --no-ansi) ANSI output', null),
            new InputOption('--no-interaction', '-n', InputOption::VALUE_NONE, 'Do not ask any interactive question'),
        ]);
    }
}
