<?php
/**
 * Copyright © Magento, Inc. All rights reserved.
 * See COPYING.txt for license details.
 */
namespace Magento\Framework\Console;

use Magento\Framework\App\Filesystem\DirectoryList;
use Magento\Framework\Composer\ComposerJsonFinder;
use Symfony\Component\Console\Input\InputInterface;
use Symfony\Component\Console\Output\OutputInterface;
use Symfony\Component\Console\Output\ConsoleOutput;
use Symfony\Component\Console\Input\ArgvInput;
use Symfony\Component\Console\Application as SymfonyApplication;
use Magento\Framework\App\Bootstrap;
use Magento\Framework\Filesystem\Driver\File;
use Magento\Framework\Shell\ComplexParameter;
use Magento\Setup\Console\CompilerPreparation;
use \Magento\Framework\App\ProductMetadata;
use Magento\Framework\App\State;

/**
 * Magento 2 CLI Application. This is the hood for all command line tools supported by Magento
 *
 * {@inheritdoc}
 * @SuppressWarnings(PHPMD.CouplingBetweenObjects)
 */
class Cli extends SymfonyApplication
{
    /**
     * Name of input option
     */
    const INPUT_KEY_BOOTSTRAP = 'bootstrap';

    /**
     * Cli exit codes
     */
    const RETURN_SUCCESS = 0;
    const RETURN_FAILURE = 1;

    /** @var \Zend\ServiceManager\ServiceManager */
    private $serviceManager;

    /**
     * Initialization exception
     *
     * @var \Exception
     */
    private $initException;

    /**
     * @var \Magento\Framework\ObjectManagerInterface
     */
    private $objectManager;

    /**
     * @param string $name  application name
     * @param string $version application version
     * @SuppressWarnings(PHPMD.ExitExpression)
     */
    public function __construct($name = 'UNKNOWN', $version = 'UNKNOWN')
    {
        $this->serviceManager = \Zend\Mvc\Application::init(require BP . '/setup/config/application.config.php')
            ->getServiceManager();

        $bootstrapParam = new ComplexParameter(self::INPUT_KEY_BOOTSTRAP);
        $params = $bootstrapParam->mergeFromArgv($_SERVER, $_SERVER);
        $params[Bootstrap::PARAM_REQUIRE_MAINTENANCE] = null;
        $bootstrap = Bootstrap::create(BP, $params);
        $this->objectManager = $bootstrap->getObjectManager();

        if ($this->checkGenerationDirectoryAccess()) {
            $output = new ConsoleOutput();
            $output->writeln(
                '<error>Command line user does not have read and write permissions on var/generation directory.  Please'
                . ' address this issue before using Magento command line.</error>'
            );
            exit(0);
        }
        /**
         * Temporary workaround until the compiler is able to clear the generation directory
         * @todo remove after MAGETWO-44493 resolved
         */
        if (class_exists(CompilerPreparation::class)) {
            $compilerPreparation = new CompilerPreparation($this->serviceManager, new ArgvInput(), new File());
            $compilerPreparation->handleCompilerEnvironment();
        }

        if ($version == 'UNKNOWN') {
            $directoryList      = new DirectoryList(BP);
            $composerJsonFinder = new ComposerJsonFinder($directoryList);
            $productMetadata    = new ProductMetadata($composerJsonFinder);
            $version = $productMetadata->getVersion();
        }
        parent::__construct($name, $version);
    }

    /**
     * Check generation directory access.
     *
     * Skip and return true if production mode is enabled.
     *
     * @return bool
     */
    private function checkGenerationDirectoryAccess()
    {
        $generationDirectoryAccess = new GenerationDirectoryAccess($this->serviceManager);
        /** @var State $state */
        $state = $this->objectManager->create(State::class);

        return $state->getMode() !== State::MODE_PRODUCTION && !$generationDirectoryAccess->check();
    }

    /**
     * Process an error happened during initialization of commands, if any
     *
     * @param InputInterface $input
     * @param OutputInterface $output
     * @return int
     * @throws \Exception
     */
    public function doRun(InputInterface $input, OutputInterface $output)
    {
        $exitCode = parent::doRun($input, $output);
        if ($this->initException) {
            $output->writeln(
                "<error>We're sorry, an error occurred. Try clearing the cache and code generation directories. "
                . "By default, they are: var/cache, var/di, var/generation, and var/page_cache.</error>"
            );
            throw $this->initException;
        }
        return $exitCode;
    }

    /**
     * {@inheritdoc}
     */
    protected function getDefaultCommands()
    {
        return array_merge(parent::getDefaultCommands(), $this->getApplicationCommands());
    }

    /**
     * Gets application commands
     *
     * @return array
     */
    protected function getApplicationCommands()
    {
        $commands = [];
        try {
            // Specialized setup command list available before and after M2 install
            if (class_exists('Magento\Setup\Console\CommandList')
                && class_exists('Magento\Setup\Model\ObjectManagerProvider')
            ) {
                /** @var \Magento\Setup\Model\ObjectManagerProvider $omProvider */
                $omProvider = $this->serviceManager->get(\Magento\Setup\Model\ObjectManagerProvider::class);
                $omProvider->setObjectManager($this->objectManager);
                $setupCommandList = new \Magento\Setup\Console\CommandList($this->serviceManager);
                $commands = array_merge($commands, $setupCommandList->getCommands());
            }

            // Allowing instances of all modular commands only after M2 install
            if ($this->objectManager->get(\Magento\Framework\App\DeploymentConfig::class)->isAvailable()) {
                /** @var \Magento\Framework\Console\CommandListInterface $commandList */
                $commandList = $this->objectManager->create(\Magento\Framework\Console\CommandListInterface::class);
                $commands = array_merge($commands, $commandList->getCommands());
            }

            $commands = array_merge($commands, $this->getVendorCommands($this->objectManager));
        } catch (\Exception $e) {
            $this->initException = $e;
        }
        return $commands;
    }

    /**
     * Gets vendor commands
     *
     * @param \Magento\Framework\ObjectManagerInterface $objectManager
     * @return array
     */
    protected function getVendorCommands($objectManager)
    {
        $commands = [];
        foreach (CommandLocator::getCommands() as $commandListClass) {
            if (class_exists($commandListClass)) {
                $commands = array_merge(
                    $commands,
                    $objectManager->create($commandListClass)->getCommands()
                );
            }
        }
        return $commands;
    }
}
