<?php

namespace Slice\Security\Auth\Event;

use Slice\EventListener\AbstractEvent;
use Slice\EventListener\Event\AfterRouterEvent;
use Slice\Router\Route;
use Slice\Router\Router;
use Slice\Security\Auth\AuthenticationManager;
use Symfony\Component\HttpFoundation\RedirectResponse;
use Symfony\Component\HttpFoundation\Request;

/**
 * Class AuthListener
 * @package Slice\Security\Auth\Event
 * @author pizzaminded <miki@appvende.net>
 * @license MIT
 */
class AuthListener extends AbstractEvent
{

    /**
     * @var Request
     */
    protected $request;

    /**
     * @var AuthenticationManager
     */
    protected $manager;

    /**
     * @var array
     */
    protected $config;

    /**
     * @var Router
     */
    protected $router;

    /**
     * AuthListener constructor.
     * @param Request $request
     * @param AuthenticationManager $manager
     */
    public function __construct(Request $request, AuthenticationManager $manager, $config, Router $router)
    {
        $this->request = $request;
        $this->manager = $manager;
        $this->config = $config;
        $this->router = $router;
    }

    /**
     * @return bool
     */
    public function isEventStoppingPropagation()
    {
        return true;
    }

    /**
     * @param Route $route
     * @return null|void
     * @throws \InvalidArgumentException
     * @throws \Slice\Router\Exception\RouteNotFoundException
     */
    public function onAfterRouterAction(AfterRouterEvent $event)
    {

        $route = $event->getRoute();

        if(
            $this->request->getMethod() !== 'POST' //not POST request
            || $route->getName() !== $this->config['login_path'] //route does not match
            || $this->manager->getUserStorage()->getUser() !== null //user is logged in
        ) {
            return;
        }

        $this->manager->handleRequest($this->request);

        $redirectUri = $this->router->generateRoute($this->config['redirect_path']);

        $event->setResponse(new RedirectResponse($redirectUri));


    }
}