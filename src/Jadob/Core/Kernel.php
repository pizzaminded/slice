<?php

namespace Jadob\Core;

use Jadob\Container\Container;
use Jadob\Container\ContainerBuilder;
use Jadob\Core\Exception\KernelException;
use Jadob\Debug\ErrorHandler\HandlerFactory;
use Jadob\EventListener\Event\AfterControllerEvent;
use Jadob\EventListener\Event\BeforeControllerEvent;
use Jadob\EventListener\Event\Type\AfterControllerEventListenerInterface;
use Jadob\EventListener\Event\Type\BeforeControllerEventListenerInterface;
use Jadob\EventListener\EventListener;
use Monolog\Handler\StreamHandler;
use Monolog\Logger;
use Psr\Container\ContainerInterface;
use Psr\Log\LoggerInterface;
use Symfony\Component\HttpFoundation\Request;
use Symfony\Component\HttpFoundation\Response;

/**
 * Class Kernel
 * @package Jadob\Core
 * @author pizzaminded <miki@appvende.net>
 * @license MIT
 */
class Kernel
{

    /**
     * semver formatted framework version
     * @see https://semver.org/
     * @var string
     */
    public const VERSION = '0.0.61';

    /**
     * @var string
     */
    public const EXPERIMENTAL_FEATURES_ENV = 'JADOB_ENABLE_EXPERIMENTAL_FEATURES';

    /**
     * @var array
     */
    private $config;

    /**
     * @var string (dev/prod)
     */
    private $env;

    /**
     * @var Container
     */
    private $container;

    /**
     * @var BootstrapInterface
     */
    private $bootstrap;

    /**
     * @var EventListener
     */
    protected $eventListener;

    /**
     * @var ContainerBuilder
     */
    protected $containerBuilder;

    /**
     * @var LoggerInterface
     */
    protected $logger;

    /**
     * @var StreamHandler
     */
    protected $fileStreamHandler;

    /**
     * @param string $env
     * @param BootstrapInterface $bootstrap
     * @throws KernelException
     * @throws \Exception
     */
    public function __construct($env, BootstrapInterface $bootstrap)
    {

        if (!\in_array($env, ['dev', 'prod'], true)) {
            throw new KernelException('Invalid environment passed to application kernel (expected: dev|prod, ' . $env . ' given)');
        }

        $env = strtolower($env);
        $this->env = $env;
        $this->bootstrap = $bootstrap;
        $this->logger = $this->initializeLogger();

        if((int)getenv('JADOB_ENABLE_EXPERIMENTAL_FEATURES') === 1) {
            $this->logger->info('JADOB_ENABLE_EXPERIMENTAL_FEATURES flag exists. please double-test your app because new features may be unstable.');
        }

        $errorHandler = HandlerFactory::factory($env, $this->logger);
        $errorHandler->registerErrorHandler();
        $errorHandler->registerExceptionHandler();

        $this->eventListener = new EventListener();

        $this->config = include $this->bootstrap->getConfigDir() . '/config.php';

        $this->addEvents();


    }

    /**
     * @param Request $request
     * @return Response
     * @throws KernelException
     * @throws \Jadob\Container\Exception\ContainerException
     * @throws \Jadob\Container\Exception\ServiceNotFoundException
     * @throws \Jadob\Router\Exception\RouteNotFoundException
     * @throws \ReflectionException
     */
    public function execute(Request $request)
    {

        $builder = $this->getContainerBuilder();
        $builder->add('request', $request);

        $this->container = $builder->build($this->config);

        $dispatcher = new Dispatcher($this->container);

        $beforeControllerEvent = new BeforeControllerEvent($request);

        $this->eventListener->dispatchEvent($beforeControllerEvent);

        $beforeControllerEventResponse = $beforeControllerEvent->getResponse();

        if ($beforeControllerEventResponse !== null) {
            $this->logger->debug('Received response from event listener, controller from route is not executed');
            return $beforeControllerEventResponse->prepare($request);
        }

        $response = $dispatcher->executeRequest($request);

        $afterControllerEvent = new AfterControllerEvent($response);

        $this->eventListener->dispatchEvent($afterControllerEvent);

        if ($afterControllerEvent->getResponse() !== null) {
            return $afterControllerEvent->getResponse()->prepare($request);
        }

        return $response->prepare($request);
    }

    /**
     * @return Container
     */
    public function getContainer()
    {
        return $this->container;
    }

    /**
     * @param ContainerInterface $container
     * @return Kernel
     */
    public function setContainer(ContainerInterface $container)
    {
        $this->container = $container;
        return $this;
    }


    /**
     * @return bool
     */
    public function isProduction()
    {
        return $this->env === 'prod';
    }

    /**
     * @return string
     */
    public function getEnv()
    {
        return $this->env;
    }

    protected function addEvents()
    {
        $this->eventListener->addEvent(
            BeforeControllerEvent::class,
            BeforeControllerEventListenerInterface::class,
            'onBeforeControllerEvent');

        $this->eventListener->addEvent(
            AfterControllerEvent::class,
            AfterControllerEventListenerInterface::class,
            'onAfterControllerEvent'
        );
    }

    /**
     * @return ContainerBuilder
     */
    public function getContainerBuilder(): ContainerBuilder
    {
        if ($this->containerBuilder === null) {
            /** @var array $services */
            $services = include $this->bootstrap->getConfigDir() . '/services.php';

            $containerBuilder = new ContainerBuilder();
            $containerBuilder->add('event.listener', $this->eventListener);
            $containerBuilder->add(BootstrapInterface::class, $this->bootstrap);
            $containerBuilder->add('kernel', $this);
            /** @TODO: how about creating an 'logger' service pointing to this.logger? */
            $containerBuilder->add(LoggerInterface::class, $this->logger);
            $containerBuilder->add('logger.handler.default', $this->fileStreamHandler);
            $containerBuilder->setServiceProviders($this->bootstrap->getServiceProviders());

            foreach ($services as $serviceName => $serviceObject) {
                $containerBuilder->add($serviceName, $serviceObject);
            }

            $this->containerBuilder = $containerBuilder;
        }

        return $this->containerBuilder;
    }

    /**
     * @return array
     */
    public function getConfig(): array
    {
        return $this->config;
    }

    /**
     * @param array $config
     * @return Kernel
     */
    public function setConfig(array $config): Kernel
    {
        $this->config = $config;
        return $this;
    }

    /**
     * Creates and preconfigures a monolog instance.
     * @throws \Exception
     * @return Logger
     */
    public function initializeLogger()
    {
        $logger = new Logger('app');

        $logLevel = Logger::DEBUG;
        if ($this->env === 'prod') {
            $logLevel = Logger::INFO;
        }

        $this->fileStreamHandler = $fileStreamHandler = new StreamHandler(
            $this->bootstrap->getLogsDir() . '/' . $this->env . '.log',
            $logLevel
        );

        $logger->pushHandler($fileStreamHandler);

        return $logger;
    }

}
