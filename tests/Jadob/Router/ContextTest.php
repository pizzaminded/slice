<?php
declare(strict_types=1);

namespace Jadob\Router;

use PHPUnit\Framework\TestCase;

/**
 * @author  pizzaminded <mikolajczajkowsky@gmail.com>
 * @license MIT
 */
class ContextTest extends TestCase
{

    public function testBasicContextFeatures(): void
    {
        $context = new Context();

        $context->setSecure(true);
        $context->setPort(1234);
        $context->setHost('example.com');

        $this->assertTrue($context->isSecure());
        $this->assertEquals('example.com', $context->getHost());
        $this->assertEquals(1234, $context->getPort());
    }

    public function testCreatingContextObjectFromSuperglobalArrays(): void
    {
        $_SERVER['HTTP_HOST'] = 'my.domain.com';
        $_SERVER['HTTPS'] = true;
        $_SERVER['SERVER_PORT'] = 8001;


        $context = Context::fromGlobals();

        $this->assertTrue($context->isSecure());
        $this->assertEquals('my.domain.com', $context->getHost());
        $this->assertEquals(8001, $context->getPort());
    }

    public function testCheckingHttpHostHasAColon(): void
    {
        $_SERVER['HTTP_HOST'] = 'my.domain.com:8001';
        $_SERVER['HTTPS'] = true;
        $_SERVER['REQUEST_URI'] = '/';

        $context = Context::fromGlobals();

        $this->assertTrue($context->isSecure());
        $this->assertEquals('my.domain.com', $context->getHost());
        $this->assertEquals(8001, $context->getPort());
    }
}