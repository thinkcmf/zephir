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

use Symfony\Component\Console\Input\InputArgument;
use Symfony\Component\Console\Input\InputInterface;
use Symfony\Component\Console\Output\OutputInterface;
use Zephir\Compiler;

/**
 * Stubs Command
 *
 * Generates stubs that can be used in a PHP IDE.
 */
final class CmfStubsCommand extends CmfAbstractCommand
{
    use ZflagsAwareTrait;

    protected function configure()
    {
        $this
            ->setName('stubs')
            ->setDescription('Generates stubs that can be used in a PHP IDE')
            ->addArgument('namespace', InputArgument::OPTIONAL, 'namespace', '')
            ->setHelp(sprintf('%s.', $this->getDescription()) . PHP_EOL . PHP_EOL . $this->getZflagsHelp());
    }

    protected function execute(InputInterface $input, OutputInterface $output)
    {
//        $io = new SymfonyStyle($input, $output);
//
//        try {
//            // TODO: Move all the stuff from the compiler
//            $this->compiler->stubs();
//        } catch (ExceptionInterface $e) {
//            $io->getErrorStyle()->error($e->getMessage());
//
//            return 1;
//        }

        $namespace = $input->getArgument('namespace');

        echo $namespace;

        if (empty($namespace)) {
            try {
                $this->stubs('app');
            } catch (\Exception $e) {
                echo $e->getMessage();
                print_r($e->getTraceAsString());
            }

            try {
                $this->stubs('api');
            } catch (\Exception $e) {
                echo $e->getMessage();
                print_r($e->getTraceAsString());
            }

            try {
                $this->stubs('plugins');
            } catch (\Exception $e) {
                echo $e->getMessage();
                print_r($e->getTraceAsString());
            }
        } else {
            try {
                $this->stubs($namespace);
            } catch (\Exception $e) {
                echo $e->getMessage();
                print_r($e->getTraceAsString());
            }

        }
        return 0;
    }

    private function stubs($namespace)
    {
        $this->initCompiler($namespace);
        $result = $this->copyFiles($namespace);
        if (!$result) {
            return;
        }

        $this->compiler->stubs();
    }
}
