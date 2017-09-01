<?php

namespace Draw\DrawBundle\Serializer\Construction;

use Doctrine\Common\Annotations\AnnotationReader;
use Doctrine\Common\Persistence\ManagerRegistry;
use Doctrine\ORM\EntityManager;
use JMS\Serializer\Construction\ObjectConstructorInterface;
use JMS\Serializer\VisitorInterface;
use JMS\Serializer\Metadata\ClassMetadata;
use JMS\Serializer\DeserializationContext;
use Symfony\Bridge\Doctrine\Validator\Constraints\UniqueEntity;
use Symfony\Component\Validator\Validator\ValidatorInterface;

/**
 * Doctrine object constructor for new (or existing) objects during deserialization.
 */
class DoctrineObjectConstructor implements ObjectConstructorInterface
{
    private $managerRegistry;
    private $fallbackConstructor;
    private $validator;
    private $annotationReader;

    /**
     * Constructor.
     *
     * @param ManagerRegistry $managerRegistry Manager registry
     * @param ObjectConstructorInterface $fallbackConstructor Fallback object constructor
     * @param ValidatorInterface $validator
     * @param AnnotationReader $annotationReader
     */
    public function __construct(
        ManagerRegistry $managerRegistry,
        ObjectConstructorInterface $fallbackConstructor,
        ValidatorInterface $validator,
        AnnotationReader $annotationReader
    ) {
        $this->managerRegistry = $managerRegistry;
        $this->fallbackConstructor = $fallbackConstructor;
        $this->validator = $validator;
        $this->annotationReader = $annotationReader;
    }

    /**
     * {@inheritdoc}
     */
    public function construct(
        VisitorInterface $visitor,
        ClassMetadata $metadata,
        $data,
        array $type,
        DeserializationContext $context
    ) {
        // Locate possible ObjectManager
        $objectManager = $this->managerRegistry->getManagerForClass($metadata->name);

        if (!$objectManager) {
            // No ObjectManager found, proceed with normal deserialization
            return $this->fallbackConstructor->construct($visitor, $metadata, $data, $type, $context);
        }

        //If the object is not found we relay on the fallback constructor
        if (is_null($object = $this->loadObject($metadata->name, $data, $context))) {
            return $this->fallbackConstructor->construct($visitor, $metadata, $data, $type, $context);
        }

        return $object;
    }

    private function loadObject($class, $data, DeserializationContext $context)
    {
        $objectManager = $this->managerRegistry->getManagerForClass($class);
        $classMetadataFactory = $objectManager->getMetadataFactory();

        if ($classMetadataFactory->isTransient($class)) {
            return null;
        }

        if(!is_array($data)) {
            return null;
        }

        $classMetadata = $objectManager->getClassMetadata($class);
        $identifierList = array();

        foreach ($classMetadata->getIdentifierFieldNames() as $name) {
            if (!isset($data[$name])) {

                $lookupByUniqueEntity = $this->annotationReader->getClassAnnotation(
                    new \ReflectionClass($class),
                    'Draw\DrawBundle\Annotation\LookupByUniqueEntity'
                );
                if ($lookupByUniqueEntity !== null) {
                    $validatorMetadata = $this->validator->getMetadataFor($class);
                    $constraints = $validatorMetadata->getConstraints();

                    // If some identifier field is not set in received data, we can still reliably identify object if it has
                    // UniqueEntity constraint.
                    // Extract all UniqueEntity constraints check if all fields required by this constraint are set
                    foreach ($constraints as $index => $constraint) {
                        if ($constraint instanceof UniqueEntity) {
                            if (is_string($constraint->fields)) {
                                $fields[] = $constraint->fields;
                            } else {
                                $fields = $constraint->fields;
                            }
                            foreach ($fields as $name) {
                                if (!isset($data[$name])) {
                                    // We don't have some field in provided data to reliably use this constraint
                                    unset($constraints[$index]);
                                    break;
                                }
                            }
                        } else {
                            // Unset non UniqueEntity constraints
                            unset($constraints[$index]);
                        }
                    }

                    // Only those UniqueEntity constraints, which we have all necessary fields for, should be left here
                    if (count($constraints) === 0) {
                        return null;
                    }

                    // Try to find object based on remaining UniqueEntity constraints
                    foreach ($constraints as $constraint) {
                        $uniqueFieldsList = [];
                        foreach ($constraint->fields as $fieldName) {
                            $uniqueFieldsList[$fieldName] = $data[$fieldName];
                        }
                        $object = $objectManager->getRepository($class)->findOneBy($uniqueFieldsList);
                        // If object is found, no need to check other constraints, just return it
                        if ($object !== null) {
                            return $object;
                        }

                    }
                }

                // If we couldn't find object using Unique constraints, we can't reliably identify object
                return null;
            }

            if ($classMetadata->hasAssociation($name)) {
                $data[$name] = $this->loadObject($classMetadata->getAssociationTargetClass($name), $data[$name], $context);
            }

            $identifierList[$name] = $data[$name];
        }

        return $objectManager->find($class, $identifierList);
    }
}