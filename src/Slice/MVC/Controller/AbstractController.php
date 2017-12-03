<?php

namespace Slice\MVC\Controller;

use Slice\Container\Container;
use Symfony\Component\HttpFoundation\RedirectResponse;
use Symfony\Component\HttpFoundation\Response;

/**
 * Class AbstractController
 * @package Slice\MVC\Controller
 * @author pizzaminded <miki@appvende.net>
 * @license MIT
 */
abstract class AbstractController
{

    /**
     * @var Container
     */
    private $container;

    /**
     * AbstractController constructor.
     * @param Container $container
     */
    public function __construct(Container $container)
    {
        $this->container = $container;
    }

    /**
     * @return Container
     */
    public function getContainer()
    {
        return $this->container;
    }

    /**
     * @param $service
     * @return mixed
     * @throws \Slice\Container\Exception\ContainerException
     */
    public function get($service)
    {
        return $this->getContainer()->get($service);
    }

    /**
     * @param $templateName
     * @param array $data
     * @param int $status
     * @param array $headers
     * @return Response
     * @throws \InvalidArgumentException
     */
    public function renderTemplateResponse($templateName, $data = [], $status = 200, $headers = [])
    {
        $output = $this->get('twig')->render($templateName, $data);
        return new Response($output, $status, $headers);
    }

    /**
     * @param $name
     * @param $params
     * @param bool $full
     * @return RedirectResponse
     * @throws \InvalidArgumentException
     */
    public function redirectToRoute($name, $params, $full = false)
    {
        return new RedirectResponse($this->get('router')->generateRoute($name, $params, $full));
    }

}