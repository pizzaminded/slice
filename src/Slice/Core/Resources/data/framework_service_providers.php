<?php

/**
 * A complete list of services provided by framework. 
 */
return [
    Slice\Core\ServiceProvider\TopLevelServicesProvider::class,
    Slice\Router\ServiceProvider\RouterServiceProvider::class,
    Slice\TwigBridge\ServiceProvider\TwigServiceProvider::class,
    Slice\DoctrineDBALBridge\ServiceProvider\DoctrineDBALServiceProvider::class,
    Slice\Database\ServiceProvider\DatabaseServiceProvider::class,
   \Slice\Debug\ServiceProvider\DebugServiceProvider::class
];