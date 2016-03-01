<?php

namespace Draw\DrawBundle\Serializer;

use JMS\Serializer\EventDispatcher\ObjectEvent;
use Symfony\Component\DependencyInjection\ContainerInterface;
use Doctrine\Common\Util\ClassUtils;
use Symfony\Component\Routing\Generator\UrlGeneratorInterface;

class SelfLinkEventListener
{
    private $entitiesRoutes = array();

    /**
     * @var ContainerInterface
     */
    private $container;

    /**
     * @var bool
     */
    private $addClass = true;

    public function __construct(ContainerInterface $container, $entitiesRoutes = array())
    {
        $this->container = $container;
        $this->entitiesRoutes = $entitiesRoutes;
    }

    /**
     * If we must add the class attribute on serialization
     *
     * @param $addClass
     */
    public function setAddClass($addClass)
    {
        $this->addClass = $addClass;
    }

    public function onPostSerialize(ObjectEvent $objectEvent)
    {
        $visitor = $objectEvent->getVisitor();
        $object = $objectEvent->getObject();
        try {
            $class = ClassUtils::getClass($objectEvent->getObject());
            if($this->addClass) {
                $visitor->addData('_class', $class);
            }
            foreach ($this->entitiesRoutes as $entityClass => $routeName) {
                switch (true) {
                    case $class == $entityClass:
                    case is_subclass_of($class, $entityClass);
                        $visitor->addData(
                            '_href',
                            $this->container->get("router")->generate(
                                $routeName,
                                array('id' => $this->container->get("property_accessor")->getValue($object, 'id')),
                                UrlGeneratorInterface::ABSOLUTE_URL
                            )
                        );
                        break;
                }
            }
        } catch (\InvalidArgumentException $e) {

        }
    }
}