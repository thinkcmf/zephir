<?php

/**
 * This file is part of the Zephir.
 *
 * (c) Phalcon Team <team@zephir-lang.com>
 *
 * For the full copyright and license information, please view
 * the LICENSE file that was distributed with this source code.
 */

declare(strict_types=1);

namespace Zephir\Console\Command;

use Monolog\Handler\StreamHandler;
use Psr\Log\LoggerInterface;
use \RecursiveIteratorIterator;
use \RecursiveDirectoryIterator;
use DirectoryIterator;
use Symfony\Component\Console\Input\ArrayInput;
use Symfony\Component\Console\Input\InputArgument;
use Symfony\Component\Console\Input\InputDefinition;
use Symfony\Component\Console\Input\InputInterface;
use Symfony\Component\Console\Input\InputOption;
use Symfony\Component\Console\Output\OutputInterface;
use Symfony\Component\Console\Style\SymfonyStyle;
use Zephir\Backends\BackendFactory;
use Zephir\BaseBackend;
use Zephir\Compiler;
use Zephir\Config;
use Zephir\Console\Application;
use Zephir\FileSystem\HardDisk;
use Zephir\Logger\Formatter\CompilerFormatter;
use Zephir\Parser\Parser;
use Zephir\Parser\Manager;
use Monolog\Logger;

/**
 * Generate Command
 *
 * Generates C code from the Zephir code without compiling it.
 */
final class CmfCommand extends AbstractCommand
{
    use DevelopmentModeAwareTrait;
    use ZflagsAwareTrait;

    private BaseBackend $backend;
    private Config $config;
    private LoggerInterface $logger;
    private $input;
    private $output;
    private $cwd;
    private $compiler;

    public function __construct()
    {
        parent::__construct();
    }

    protected function configure()
    {
        $this
            ->setName('cmf')
            ->setDescription('Generates C code from the Zephir code without compiling it')
            ->setDefinition($this->createDefinition())
            ->addOption('dev', null, InputOption::VALUE_NONE, 'Build the extension in development mode')
            ->addOption('no-dev', null, InputOption::VALUE_NONE, 'Build the extension in production mode')
            ->setHelp(sprintf('%s.', $this->getDescription()) . PHP_EOL . PHP_EOL . $this->getZflagsHelp());
    }

    protected function execute(InputInterface $input, OutputInterface $output)
    {
        $this->input  = $input;
        $this->output = $output;
        $this->cwd    = getcwd();
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

        return 0;
    }

    private function build($path)
    {
        echo "Compiling $path:\n";
        chdir($this->cwd);
        $config    = Config::fromServer();
        $namespace = $path;
        /**
         * Logger
         */
        $formatter = new CompilerFormatter($config);

        $consoleStdErrorHandler = new StreamHandler('php://stderr', Logger::WARNING, false);
        $consoleStdErrorHandler->setFormatter($formatter);

        $consoleStdOutHandler = new StreamHandler('php://stdout', Logger::INFO, false);
        $consoleStdOutHandler->setFormatter($formatter);

        $handlers = [
            $consoleStdErrorHandler,
            $consoleStdOutHandler,
        ];

        $rootPath = \Phar::running() ?: realpath(dirname(dirname(dirname(dirname(__FILE__)))));

        $dataZephirDir = $this->getZephirProjectDir($path);
        $disk          = new HardDisk($dataZephirDir . '.zephir');

        $parser          = new Parser();
        $logger          = new Logger('zephir', $handlers);
        $compilerFactory = new Compiler\CompilerFileFactory($config, $disk, $logger);
        $backend         = (new BackendFactory($config, $rootPath . '/kernels', $rootPath . '/templates'))
            ->createBackend();

        $compiler = new Compiler($config, $backend, new Manager($parser), $disk, $compilerFactory);
        $compiler->setPrototypesPath($rootPath . '/prototypes');
        $compiler->setOptimizersPath($rootPath . '/Library/Optimizers');
        $compiler->setTemplatesPath($rootPath . '/templates');
        $compiler->setLogger($logger);

        $this->backend  = $backend;
        $this->logger   = $logger;
        $this->config   = $config;
        $this->compiler = $compiler;

        $this->copyFiles($namespace);

        $this->config->set('namespace', $namespace);
        $this->config->set('namespace', $namespace);
        $this->config->set('name', $namespace);
        $this->config->set('extension-name', 'thinkcmf_' . $namespace);
        $this->config->set('nonexistent-class', false, 'warnings');
        $this->config->set('nonexistent-function', false, 'warnings');
        $this->config->set('author', 'ThinkCMF Team');

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

    }

    private function getZephirProjectDir($namespace)
    {
        return $this->cwd . DIRECTORY_SEPARATOR . 'data' . DIRECTORY_SEPARATOR . 'zephir' . DIRECTORY_SEPARATOR . 'thinkcmf_' . $namespace . DIRECTORY_SEPARATOR;
    }

    private function copyFiles($path)
    {
        $dataZephirDir = $this->getZephirProjectDir($path);
        if ($path == 'plugins') {
            $path = 'public' . DIRECTORY_SEPARATOR . 'plugins';
        }

        $this->deleteDir($dataZephirDir);
//        echo $dataZephirDir;
        /**
         * Pre compile all files.
         */
        $iterator = new RecursiveIteratorIterator(
            new RecursiveDirectoryIterator($path),
            RecursiveIteratorIterator::SELF_FIRST
        );

        foreach ($iterator as $item) {
            if (!$item->isDir()) {
                $filePath = $item->getPathname();
                if (preg_match('#\.zep$#', $filePath)) {
                    $targetFilePath = trim(preg_replace("/^public/", '', $filePath), '/\\');
                    $dirname        = dirname($dataZephirDir . $targetFilePath);
                    if (!file_exists($dirname)) {
                        mkdir($dirname, 0777, true);
                    }

                    if (!copy($filePath, $dataZephirDir . $targetFilePath)) {

                    }
                }
            }
        }


        $kernelDir = $dataZephirDir . 'ext' . DIRECTORY_SEPARATOR . 'kernel';
        if (!is_dir($kernelDir)) {
            mkdir($kernelDir, 0777, true);
        }
        // Copy the latest kernel files
        $this->recursiveProcess($this->backend->getInternalKernelPath(), $kernelDir);
        // Dump initial configuration on project creation
        file_put_contents($dataZephirDir . 'config.json', $this->config);
        chdir($dataZephirDir);
    }

    private function deleteDir($dir)
    {
        if (is_dir($dir)) {
            if ($dp = opendir($dir)) {
                while (($file = readdir($dp)) != false) {
                    if ($file != '.' && $file != '..') {
                        $file = $dir . DIRECTORY_SEPARATOR . $file;
                        if (is_dir($file)) {
//                            echo "deleting dir:" . $file . "\n";
                            $this->deleteDir($file);
                        } else {
                            try {
//                                echo "deleting file:" . $file . "\n";
                                unlink($file);
                            } catch (\Exception $e) {

                            }
                        }
                    }
                }
                if (readdir($dp) == false) {
                    closedir($dp);
                    rmdir($dir);
                }
            } else {
                echo 'Not permission' . "\n";
            }

        }
    }

    protected function createDefinition(): InputDefinition
    {
        return new InputDefinition(
            [
                new InputOption(
                    'backend',
                    null,
                    InputOption::VALUE_REQUIRED,
                    'Used backend to generate extension',
                    'ZendEngine3'
                ),
            ]
        );
    }

    /**
     * Copies the base kernel to the extension destination.
     *
     * @param string      $src
     * @param string      $dst
     * @param string|null $pattern
     * @param string      $callback
     *
     * @return bool
     */
    private function recursiveProcess(string $src, string $dst, ?string $pattern = null, string $callback = 'copy'): bool
    {
        $success  = true;
        $iterator = new DirectoryIterator($src);

        foreach ($iterator as $item) {
            $pathName = $item->getPathname();
            if (!is_readable($pathName)) {
                $this->logger->error('File is not readable :' . $pathName);
                continue;
            }

            $fileName = $item->getFileName();

            if ($item->isDir()) {
                if ('.' != $fileName && '..' != $fileName && '.libs' != $fileName) {
                    if (!is_dir($dst . DIRECTORY_SEPARATOR . $fileName)) {
                        mkdir($dst . DIRECTORY_SEPARATOR . $fileName, 0755, true);
                    }
                    $this->recursiveProcess($pathName, $dst . DIRECTORY_SEPARATOR . $fileName, $pattern, $callback);
                }
            } elseif ($pattern === null || 1 === preg_match($pattern, $fileName)) {
                $path    = $dst . DIRECTORY_SEPARATOR . $fileName;
                $success = $success && $callback($pathName, $path);
            }
        }

        return $success;
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
