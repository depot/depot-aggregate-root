<?php

namespace Depot\AggregateRoot\Instantiation;

use Depot\AggregateRoot\Error\AggregateRootNotSupported;

class NamedConstructorInstantiator implements Instantiator
{
    /**
     * @var string
     */
    private $staticConstructorMethodName;

    /**
     * @var string
     */
    private $supportedObjectType;

    public function __construct(
        $staticConstructorMethod = 'instantiateAggregateForReconstitution',
        $supportedObjectType = null
    ) {
        $this->staticConstructorMethodName = $staticConstructorMethod;
        $this->supportedObjectType = $supportedObjectType;
    }

    /**
     * {@inheritdoc}
     */
    public function instantiateForReconstitution($className)
    {
        $this->assertStaticMethodCallExists($className);

        $staticMethodCall = $this->getStaticMethodCall($className);

        $object = call_user_func($staticMethodCall);

        $this->assertObjectIsSupported($object);

        return $object;
    }

    private function assertStaticMethodCallExists($className)
    {
        if (method_exists($className, $this->staticConstructorMethodName)) {
            return;
        }

        throw AggregateRootNotSupported::becauseClassDoesNotHaveAnExpectedStaticMethod(
            $className,
            $this->staticConstructorMethodName
        );
    }

    private function getStaticMethodCall($className)
    {
        return sprintf('%s::%s', $className, $this->staticConstructorMethodName);
    }

    private function assertObjectIsSupported($object)
    {
        if (is_null($this->supportedObjectType)) {
            return;
        }

        if ($object instanceof $this->supportedObjectClass) {
            return;
        }

        throw AggregateRootNotSupported::becauseObjectHasAnUnexpectedType(
            $object,
            $this->supportedObjectType
        );
    }
}
