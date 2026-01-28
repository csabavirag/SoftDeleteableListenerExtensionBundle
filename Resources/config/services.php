<?php

use Symfony\Component\DependencyInjection\Loader\Configurator\ContainerConfigurator;

return static function (ContainerConfigurator $containerConfigurator): void {
    $services = $containerConfigurator->services();

    $services->set(\Evence\Bundle\SoftDeleteableExtensionBundle\EventListener\SoftDeleteListener::class)
        ->tag('doctrine.event_listener', ['event' => 'preSoftDelete']);
};