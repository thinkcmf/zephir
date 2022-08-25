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
use Monolog\Logger;
use Psr\Log\LoggerInterface;
use Symfony\Component\Console\Command\Command;
use Zephir\Backends\BackendFactory;
use Zephir\BaseBackend;
use Zephir\Compiler;
use Zephir\Config;
use Zephir\FileSystem\HardDisk;
use Zephir\Logger\Formatter\CompilerFormatter;
use Zephir\Parser\Manager;
use Zephir\Parser\Parser;
use \RecursiveIteratorIterator;
use \RecursiveDirectoryIterator;
use DirectoryIterator;

abstract class CmfAbstractCommand extends Command
{
    use RemoveOptionsTrait;

    protected BaseBackend $backend;
    protected Config $config;
    protected LoggerInterface $logger;
    protected $input;
    protected $output;
    protected $cwd;
    protected Compiler $compiler;

    public function __construct()
    {
        parent::__construct();
        $this->cwd = getcwd();
    }

    /**
     * @param bool $mergeArgs
     */
    public function mergeApplicationDefinition($mergeArgs = true)
    {
        parent::mergeApplicationDefinition($mergeArgs);

        $this->removeOptions(['dumpversion', 'version', 'vernum']);
    }

    public function initCompiler($namespace)
    {
        $path   = $namespace;
        $config = Config::fromServer();
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

        $rootPath        = \Phar::running() ?: realpath(dirname(dirname(dirname(dirname(__FILE__)))));
        $dataZephirDir   = $this->getZephirProjectDir($path);
        $disk            = new HardDisk($dataZephirDir . '.zephir');
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


        $this->config->set('namespace', $namespace);
        $this->config->set('namespace', $namespace);
        $this->config->set('name', $namespace);
        $this->config->set('extension-name', 'thinkcmf_' . $namespace);
        $this->config->set('nonexistent-class', false, 'warnings');
        $this->config->set('nonexistent-function', false, 'warnings');
        $this->config->set('author', 'ThinkCMF Team');
    }

    protected function getZephirProjectDir($namespace)
    {
        return $this->cwd . DIRECTORY_SEPARATOR . 'data' . DIRECTORY_SEPARATOR . 'zephir' . DIRECTORY_SEPARATOR . 'thinkcmf_' . $namespace . DIRECTORY_SEPARATOR;
    }

    protected function copyFiles($path)
    {
        $namespace     = $path;
        $dataZephirDir = $this->getZephirProjectDir($path);
        if ($path == 'plugins') {
            $path = 'public' . DIRECTORY_SEPARATOR . 'plugins';
        }

        $this->deleteDir($dataZephirDir);

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

        if (is_dir($dataZephirDir . DIRECTORY_SEPARATOR . $namespace)) {
            chdir($dataZephirDir);
            return true;
        }
        return false;

    }

    protected function deleteDir($dir)
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
    protected function recursiveProcess(string $src, string $dst, ?string $pattern = null, string $callback = 'copy'): bool
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

}
