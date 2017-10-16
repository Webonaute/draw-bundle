<?php

namespace Draw\DrawBundle\DependencyInjection;

use Draw\DrawBundle\EventListener\ViewResponseListener;
use Draw\DrawBundle\Request\RequestBodyParamConverter;
use Draw\DrawBundle\Serializer\Construction\DoctrineObjectConstructor;
use Symfony\Component\DependencyInjection\Compiler\CompilerPassInterface;
use Symfony\Component\DependencyInjection\ContainerBuilder;
use Symfony\Component\DependencyInjection\Reference;

class CompilerPass implements CompilerPassInterface
{

    /**
     * You can modify the container here before it is dumped to PHP code.
     *
     * @param ContainerBuilder $container
     *
     * @api
     */
    public function process(ContainerBuilder $container)
    {
        if ($container->hasDefinition('fos_rest.converter.request_body')) {
            $container->getDefinition('fos_rest.converter.request_body')
                ->setClass(RequestBodyParamConverter::class)
                ->addArgument($container->getDefinition('dms.filter'));
        }

        if ($container->hasDefinition('fos_rest.view_response_listener')) {
            $definition = $container->getDefinition('fos_rest.view_response_listener')
                ->setClass(ViewResponseListener::class);
        }

        $requestBodyConverter = $container->getDefinition("fos_rest.converter.request_body");
        $requestBodyConverter->addMethodCall(
            "setGroupHierarchy",
            [new Reference("draw.serializer.group_hierarchy")]
        );
    }
}