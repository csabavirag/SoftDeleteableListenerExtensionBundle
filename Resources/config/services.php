<?php

use Symfony\Component\DependencyInjection\Definition;
use Symfony\Component\DependencyInjection\Reference;
use Symfony\Component\DependencyInjection\ContainerInterface;

/** @var \Symfony\Component\DependencyInjection\ContainerBuilder $container */
$container->setDefinition('evence.softdeletale.listener.softdelete', new Definition('Evence\Bundle\SoftDeleteableExtensionBundle\EventListener\SoftDeleteListener', array(new Reference('annotation_reader', ContainerInterface::NULL_ON_INVALID_REFERENCE))))

->addTag('doctrine.event_listener', array(
    'event' => 'preSoftDelete',
));
