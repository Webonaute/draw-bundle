<?php

namespace Draw\DrawBundle\Request;

use DMS\Bundle\FilterBundle\Service\Filter;
use Draw\DrawBundle\PropertyAccess\DynamicArrayObject;
use Draw\DrawBundle\Request\Exception\RequestValidationException;
use Draw\DrawBundle\Serializer\GroupHierarchy;
use FOS\RestBundle\Context\Context;
use FOS\RestBundle\Request\RequestBodyParamConverter as BaseRequestBodyParamConverter;
use FOS\RestBundle\Serializer\Serializer;
use JMS\Serializer\Exception\Exception as JMSSerializerException;
use JMS\Serializer\Exception\UnsupportedFormatException;
use Sensio\Bundle\FrameworkExtraBundle\Configuration\ParamConverter;
use Symfony\Component\HttpFoundation\Request;
use Symfony\Component\HttpKernel\Exception\BadRequestHttpException;
use Symfony\Component\HttpKernel\Exception\UnsupportedMediaTypeHttpException;
use Symfony\Component\OptionsResolver\OptionsResolver;
use Symfony\Component\PropertyAccess\PropertyAccess;
use Symfony\Component\Serializer\Exception\ExceptionInterface as SymfonySerializerException;
use Symfony\Component\Validator\Validator\ValidatorInterface;


class RequestBodyParamConverter extends BaseRequestBodyParamConverter
{
    /**
     * @var GroupHierarchy
     */
    protected $groupHierarchy;

    /**
     * @var Serializer
     */
    protected $serializer;

    /**
     * @var array
     */
    protected $context = [];

    /**
     * @var ValidatorInterface
     */
    protected $validator;

    /**
     * The name of the argument on which the ConstraintViolationList will be set.
     *
     * @var null|string
     */
    protected $validationErrorsArgument;

    /**
     * The filtering service, which will filter object data before validation
     *
     * @var FilterInterface
     */
    protected $filterService;

    /**
     * @param Serializer $serializer
     * @param array|null $groups An array of groups to be used in the serialization context
     * @param string|null $version A version string to be used in the serialization context
     * @param ValidatorInterface $validator
     * @param string|null $validationErrorsArgument
     * @param FilterInterface $filterService
     *
     * @throws \InvalidArgumentException
     */
    public function __construct(
        Serializer $serializer,
        $groups = null,
        $version = null,
        ValidatorInterface $validator = null,
        $validationErrorsArgument = null,
        Filter $filterService
    ) {
        $this->serializer = $serializer;

        if (!empty($groups)) {
            $this->context['groups'] = (array)$groups;
        }

        if (!empty($version)) {
            $this->context['version'] = $version;
        }

        if (null !== $validator && null === $validationErrorsArgument) {
            throw new \InvalidArgumentException('"$validationErrorsArgument" cannot be null when using the validator');
        }

        $this->validator = $validator;
        $this->validationErrorsArgument = $validationErrorsArgument;
        $this->filterService = $filterService;
    }


    public function setGroupHierarchy(GroupHierarchy $groupHierarchy)
    {
        $this->groupHierarchy = $groupHierarchy;
    }

    public function apply(Request $request, ParamConverter $configuration)
    {
        $options = (array)$configuration->getOptions();

        if (isset($options['propertiesMap'])) {
            $content = new DynamicArrayObject(json_decode($request->getContent(), true));

            $propertyAccessor = PropertyAccess::createPropertyAccessor();

            $attributes = (object)$request->attributes->all();
            foreach ($options['propertiesMap'] as $target => $source) {
                $propertyAccessor->setValue(
                    $content,
                    $target,
                    $propertyAccessor->getValue($attributes, $source)
                );
            }

            $property = new \ReflectionProperty(get_class($request), 'content');
            $property->setAccessible(true);
            $property->setValue($request, json_encode($content->getArrayCopy()));
        }



        $options = (array)$configuration->getOptions();

        if (isset($options['deserializationContext']) && is_array($options['deserializationContext'])) {
            $arrayContext = array_merge($this->context, $options['deserializationContext']);
        } else {
            $arrayContext = $this->context;
        }
        $this->configureContext($context = new Context(), $arrayContext);

        try {
            $object = $this->serializer->deserialize(
                $request->getContent(),
                $configuration->getClass(),
                $request->getContentType(),
                $context
            );
        } catch (UnsupportedFormatException $e) {
            return $this->throwException(new UnsupportedMediaTypeHttpException($e->getMessage(), $e), $configuration);
        } catch (JMSSerializerException $e) {
            return $this->throwException(new BadRequestHttpException($e->getMessage(), $e), $configuration);
        } catch (SymfonySerializerException $e) {
            return $this->throwException(new BadRequestHttpException($e->getMessage(), $e), $configuration);
        }

        $request->attributes->set($configuration->getName(), $object);

        if (null !== $this->validator) {
            $validatorOptions = $this->getValidatorOptions($options);

            $errors = $this->validator->validate($object, null, $validatorOptions['groups']);

            $request->attributes->set(
                $this->validationErrorsArgument,
                $errors
            );
        }


        if ($this->validationErrorsArgument && $request->attributes->has($this->validationErrorsArgument)) {
            if (count($errors = $request->attributes->get($this->validationErrorsArgument))) {
                $this->convertValidationErrorsToException($errors);
            }
        }

        $this->filterService->filterEntity($object);

        return true;
    }

    public function configureContext(Context $context, array $options)
    {
        if (!isset($options['groups'])) {
            $options['groups'] = ['Default'];
        }

        $options['groups'] = $this->groupHierarchy->getReachableGroups($options['groups']);

        parent::configureContext($context, $options);

        if (isset($options['attributes'])) {
            foreach ($options['attributes'] as $attribute => $value) {
                $context->setAttribute($attribute, $value);
            }
        }

        return $context;
    }

    protected function convertValidationErrorsToException($errors)
    {
        $exception = new RequestValidationException();
        $exception->setViolationList($errors);
        throw $exception;
    }
    /**
     * Throws an exception or return false if a ParamConverter is optional.
     */
    private function throwException(\Exception $exception, ParamConverter $configuration)
    {
        if ($configuration->isOptional()) {
            return false;
        }

        throw $exception;
    }

    /**
     * @param array $options
     *
     * @return array
     */
    private function getValidatorOptions(array $options)
    {
        $resolver = new OptionsResolver();
        $resolver->setDefaults([
            'groups' => null,
            'traverse' => false,
            'deep' => false,
        ]);

        return $resolver->resolve(isset($options['validator']) ? $options['validator'] : []);
    }

}