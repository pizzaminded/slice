<?php

namespace Jadob\Bridge\Doctrine\ORM\ServiceProvider;

use Doctrine\DBAL\Tools\Console\Helper\ConnectionHelper;
use Doctrine\ORM\Mapping\Driver\AnnotationDriver;
use Doctrine\Common\Annotations\AnnotationReader;
use Doctrine\Common\Annotations\CachedReader;
use Doctrine\Common\Cache\ArrayCache;
use Doctrine\Common\EventManager;
use Doctrine\ORM\Configuration;
use Doctrine\ORM\EntityManager;
use Doctrine\ORM\Tools\Console\ConsoleRunner;
use Doctrine\ORM\Tools\Console\Helper\EntityManagerHelper;
use Jadob\Container\Container;
use Jadob\Container\ServiceProvider\ServiceProviderInterface;
use Jadob\Core\BootstrapInterface;
use Psr\Container\ContainerInterface;
use Symfony\Component\Console\Application;
use Symfony\Component\Console\Helper\HelperSet;

/**
 * Class DoctrineORMProvider
 * @package Jadob\Bridge\Doctrine\ORM\ServiceProvider
 * @author pizzaminded <miki@appvende.net>
 * @license MIT
 */
class DoctrineORMProvider implements ServiceProviderInterface
{

    /**
     * {@inheritdoc}
     */
    public function getConfigNode()
    {
        return 'doctrine_orm';
    }

    /**
     * {@inheritdoc}
     * @throws \ReflectionException
     */
    public function register($config)
    {
        /**
         * Entity paths must be defined, otherwise there is no sense to load rest of ORM
         */
        if (!isset($config['managers'])) {
            throw new \RuntimeException('There is no "managers" section in config.doctrine_orm node.');
        }

        /**
         * Add cache to ORM.
         * @TODO: add some cache types
         */
        if (isset($config['cache'])) {

            if (!isset($config['cache']['type'])) {
                throw new \RuntimeException('Cache type is not provided.');
            }

            $cacheConfig = $config['cache'];


//@TODO: find some better way for this as this is freaking necessary
//            switch (strtolower($cacheConfig['type'])) {
//                case 'array':
//                default:
//
//                    break;
//            }
        }

        $cache = new ArrayCache();

        $this->registerAnnotations();

        $services = [];

        foreach ($config['managers'] as $managerName => $managerConfig) {

            $services['doctrine.orm.' . $managerName] = function (ContainerInterface $container) use ($cache, $managerName, $managerConfig) {

                $isDevMode = !$container->get('kernel')->isProduction();
                $cacheDir = $container->get(BootstrapInterface::class)->getCacheDir()
                    . '/'
                    . $container->get('kernel')->getEnv()
                    . '/doctrine';

                /**
                 * Paths should be relative, beginning from project root dir.
                 * Rest of path will be concatenated below.
                 */
                $entityPaths = [];

                /**
                 * Entity paths must be defined, otherwise there is no sense to load rest of ORM
                 */
                if (!isset($managerConfig['entity_paths'])) {
                    throw new \RuntimeException('Entity paths section in ' . $managerName . ' are not defined');
                }

                foreach ($managerConfig['entity_paths'] as $path) {
                    //@TODO: trim beginning slash from any $path if present
                    $entityPaths[] = $container->get(BootstrapInterface::class)->getRootDir() . '/' . $path;
                }

                $configuration = new Configuration();
                $configuration->setMetadataCacheImpl($isDevMode ? new ArrayCache() : $cache);
                $configuration->setHydrationCacheImpl($isDevMode ? new ArrayCache() : $cache);
                $configuration->setQueryCacheImpl($isDevMode ? new ArrayCache() : $cache);
                $configuration->setMetadataDriverImpl(
                    new AnnotationDriver(
                        new CachedReader(new AnnotationReader(), $cache),
                        $entityPaths
                    )
                );

                $configuration->setProxyNamespace('Doctrine\ORM\Proxies');
                $configuration->setProxyDir($cacheDir . '/Doctrine/ORM/Proxies');
                $configuration->setAutoGenerateProxyClasses(true);

                /**
                 * Build EntityManager
                 */
                return EntityManager::create(
                    $container->get('doctrine.dbal.' . $managerName),
                    $configuration,
                    $container->get(EventManager::class)
                );

            };
        }

        return $services;
    }

    /**
     * {@inheritdoc}
     */
    public function onContainerBuild(Container $container, $config)
    {
        /**
         * @TODO: how about providing multiple database console command by providing additional argument
         * (eg. --conn=<connection_name>)
         */
        if ($container->has('console')) {
            /** @var Application $console */
            $console = $container->get('console');

            //@TODO: maybe we should add db helper set in DoctrineDBALBridge?
            $helperSet = new HelperSet([
                'db' => new ConnectionHelper($container->get('doctrine.dbal.default')),
                'em' => new EntityManagerHelper($container->get('doctrine.orm.default'))
            ]);

            $console->setHelperSet($helperSet);

            ConsoleRunner::addCommands($console);
        }
    }

    /**
     * @throws \ReflectionException
     */
    protected function registerAnnotations()
    {
        $configurationClassDirectory = \dirname((new \ReflectionClass(Configuration::class))->getFileName());
        require_once $configurationClassDirectory . '/Mapping/Driver/DoctrineAnnotations.php';
    }
}