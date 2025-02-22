<?php

namespace Evence\Bundle\SoftDeleteableExtensionBundle\EventListener;

use Doctrine\Common\Annotations\AnnotationReader;
use Doctrine\Common\Annotations\Reader;
use Doctrine\Common\Proxy\Proxy;
use Doctrine\Common\Util\ClassUtils;
use Doctrine\ORM\EntityManager;
use Doctrine\ORM\Mapping\Annotation;
use Doctrine\ORM\Mapping\ClassMetadata;
use Doctrine\ORM\Mapping\ManyToMany;
use Doctrine\ORM\Mapping\ManyToOne;
use Doctrine\ORM\Mapping\OneToMany;
use Doctrine\ORM\Mapping\OneToOne;
use Doctrine\ORM\Mapping\ClassMetadataInfo;
use Evence\Bundle\SoftDeleteableExtensionBundle\Exception\OnSoftDeleteUnknownTypeException;
use Evence\Bundle\SoftDeleteableExtensionBundle\Mapping\Annotation\onSoftDelete;
use Evence\Bundle\SoftDeleteableExtensionBundle\Mapping\Annotation\onSoftDeleteSuccessor;
use Gedmo\Mapping\ExtensionMetadataFactory;
use Gedmo\Mapping\Driver\AttributeReader;
use Gedmo\SoftDeleteable\Event\PostSoftDeleteEventArgs;
use Gedmo\SoftDeleteable\Event\PreSoftDeleteEventArgs;
use Gedmo\SoftDeleteable\SoftDeleteableListener as GedmoSoftDeleteableListener;
use Symfony\Component\PropertyAccess\PropertyAccess;

/**
 * Soft delete listener class for onSoftDelete behaviour.
 *
 * @author Ruben Harms <info@rubenharms.nl>
 *
 * @link http://www.rubenharms.nl
 * @link https://www.github.com/RubenHarms
 */
class SoftDeleteListener
{
    /**
     * @var ?Reader|?AttributeReader
     */
    protected $reader;

    /**
     * @param ?Reader|?AttributeReader $reader
     */
    public function __construct($reader)
    {
        $this->reader = $reader;
    }

    private function getSoftDeleteAttribute(\ReflectionProperty $property, string $className): ?onSoftDelete
    {
        $attributes = $property->getAttributes($className);
        if (!empty($attributes)) {
            $attribute = $attributes[0];
            $arguments = $attribute->getArguments();

            return new onSoftDelete($arguments);
        }

        return (new AnnotationReader())->getPropertyAnnotation($property, onSoftDelete::class);
    }

    /**
     * @param PreSoftDeleteEventArgs $args
     *
     * @throws OnSoftDeleteUnknownTypeException
     * @throws \Exception
     */
    public function preSoftDelete(PreSoftDeleteEventArgs $args)
    {
        $em = $args->getObjectManager();
        $entity = $args->getObject();

        $entityReflection = new \ReflectionObject($entity);

        $namespaces = $em->getConfiguration()
            ->getMetadataDriverImpl()
            ->getAllClassNames();

        foreach ($namespaces as $namespace) {
            $reflectionClass = new \ReflectionClass($namespace);
            if ($reflectionClass->isAbstract()) {
                continue;
            }

            $meta = $em->getClassMetadata($namespace);
            foreach ($reflectionClass->getProperties() as $property) {
                /** @var onSoftDelete $onDelete */
                if ($onDelete = $this->getSoftDeleteAttribute($property, onSoftDelete::class)) {
                    $objects = null;
                    $manyToMany = null;
                    $manyToOne = null;
                    $oneToOne = null;

                    $associationMapping = null;
                    if (array_key_exists($property->getName(), $meta->getAssociationMappings())) {
                        $associationMapping = (object)$meta->getAssociationMapping($property->getName());
                    }

                    if (
                        ($manyToMany = $associationMapping && $associationMapping->type == ClassMetadataInfo::MANY_TO_MANY ? $associationMapping : null) ||
                        ($manyToOne = $associationMapping && $associationMapping->type == ClassMetadataInfo::MANY_TO_ONE ? $associationMapping : null) ||
                        ($oneToOne = $associationMapping && $associationMapping->type == ClassMetadataInfo::ONE_TO_ONE ? $associationMapping : null)
                    ) {
                        /** @var OneToOne|OneToMany|ManyToMany $relationship */
                        $relationship = $manyToOne ?: $manyToMany ?: $oneToOne;

                        $ns = null;
                        $nsOriginal = $relationship->targetEntity;
                        $nsFromRelativeToAbsolute = $entityReflection->getNamespaceName().'\\'.$relationship->targetEntity;
                        $nsFromRoot = '\\'.$relationship->targetEntity;
                        if (class_exists($nsOriginal)){
                            $ns = $nsOriginal;
                        } elseif (class_exists($nsFromRoot)){
                            $ns = $nsFromRoot;
                        } elseif (class_exists($nsFromRelativeToAbsolute)){
                            $ns = $nsFromRelativeToAbsolute;
                        }

                        if (!$this->isOnDeleteTypeSupported($onDelete, $relationship)) {
                            throw new \Exception(sprintf('%s is not supported for %s relationships', $onDelete->type, get_class($relationship)));
                        }

                        if (($manyToOne || $oneToOne) && $ns && $entity instanceof $ns) {
                            $objects = $em->getRepository($namespace)->findBy(array(
                                $property->name => $entity,
                            ));
                        } elseif ($manyToMany) {
                            // For ManyToMany relations, we only delete the relationship between
                            // two entities. This can be done on both side of the relation.
                            $allowMappedSide = get_class($entity) === $namespace;
                            $allowInversedSide = ($ns && $entity instanceof $ns);
                            if ($allowInversedSide) {
                                $mtmRelations = $em->getRepository($namespace)->createQueryBuilder('entity')
                                    ->innerJoin(sprintf('entity.%s', $property->name), 'mtm')
                                    ->addSelect('mtm')
                                    ->andWhere(sprintf(':entity MEMBER OF entity.%s', $property->name))
                                    ->setParameter('entity', $entity)
                                    ->getQuery()
                                    ->getResult();

                                foreach ($mtmRelations as $mtmRelation) {
                                    try {
                                        $propertyAccessor = PropertyAccess::createPropertyAccessor();
                                        $collection = $propertyAccessor->getValue($mtmRelation, $property->name);
                                        $collection->removeElement($entity);
                                    } catch (\Exception $e) {
                                        throw new \Exception(sprintf('No accessor found for %s in %s', $property->name, get_class($mtmRelation)));
                                    }
                                }
                            } elseif ($allowMappedSide) {
                                try {
                                    $propertyAccessor = PropertyAccess::createPropertyAccessor();
                                    $collection = $propertyAccessor->getValue($entity, $property->name);
                                    $collection->clear();
                                    continue;
                                } catch (\Exception $e) {
                                    throw new \Exception(sprintf('No accessor found for %s in %s', $property->name, get_class($entity)));
                                }
                            }
                        }
                    }

                    if ($objects) {
                        $reflectionClass = new \ReflectionClass($namespace);
                        if($this->reader){
                            $classAnnotation = $this->reader->getClassAnnotation($reflectionClass, \Gedmo\Mapping\Annotation\SoftDeleteable::class);
                            $softDelete = $classAnnotation instanceof \Gedmo\Mapping\Annotation\SoftDeleteable;
                            foreach ($objects as $object) {
                                $this->processOnDeleteOperation($object, $onDelete, $property, $meta, $softDelete, $args, ['fieldName' => $classAnnotation->fieldName]);
                            }
                        }else{
                            $attributes = $reflectionClass->getAttributes(\Gedmo\Mapping\Annotation\SoftDeleteable::class);
                            foreach ($attributes as $attribute) {
                                $arguments = $attribute->getArguments();
                                foreach ($objects as $object) {
                                    $softDelete = \Gedmo\Mapping\Annotation\SoftDeleteable::class == $attribute->getName();
                                    $this->processOnDeleteOperation($object, $onDelete, $property, $meta, $softDelete, $args, ['fieldName' => $arguments['fieldName']]);
                                }
                            }
                        }
                    }
                }
            }
        }
    }

    /**
     * @param $object
     * @param onSoftDelete $onDelete
     * @param \ReflectionProperty $property
     * @param ClassMetadata $meta
     * @param $softDelete
     * @param PreSoftDeleteEventArgs $args
     * @param $config
     * @throws OnSoftDeleteUnknownTypeException
     */
    protected function processOnDeleteOperation(
        $object,
        onSoftDelete $onDelete,
        \ReflectionProperty $property,
        ClassMetadata $meta,
        $softDelete,
        PreSoftDeleteEventArgs $args,
        $config
    ) {
        if (strtoupper($onDelete->type) === 'SET NULL') {
            $this->processOnDeleteSetNullOperation($object, $onDelete, $property, $meta, $softDelete, $args, $config);
        } elseif (strtoupper($onDelete->type) === 'CASCADE') {
            $this->processOnDeleteCascadeOperation($object, $onDelete, $property, $meta, $softDelete, $args, $config);
        } elseif (strtoupper($onDelete->type) === 'SUCCESSOR') {
            $this->processOnDeleteSuccessorOperation($object, $onDelete, $property, $meta, $softDelete, $args, $config);
        } else {
            throw new OnSoftDeleteUnknownTypeException($onDelete->type);
        }
    }

    /**
     * @param $object
     * @param onSoftDelete $onDelete
     * @param \ReflectionProperty $property
     * @param ClassMetadata $meta
     * @param $softDelete
     * @param PreSoftDeleteEventArgs $args
     * @param $config
     */
    protected function processOnDeleteSetNullOperation(
        $object,
        onSoftDelete $onDelete,
        \ReflectionProperty $property,
        ClassMetadata $meta,
        $softDelete,
        PreSoftDeleteEventArgs $args,
        $config
    ) {
        $reflProp = $meta->getReflectionProperty($property->name);
        $oldValue = $reflProp->getValue($object);

        $reflProp->setValue($object, null);
        $args->getObjectManager()->persist($object);

        $args->getObjectManager()->getUnitOfWork()->propertyChanged($object, $property->name, $oldValue, null);
        $args->getObjectManager()->getUnitOfWork()->scheduleExtraUpdate($object, array(
            $property->name => array($oldValue, null),
        ));
    }

    /**
     * @param $object
     * @param onSoftDelete $onDelete
     * @param \ReflectionProperty $property
     * @param ClassMetadata $meta
     * @param $softDelete
     * @param PreSoftDeleteEventArgs $args
     * @param $config
     * @throws \Exception
     */
    protected function processOnDeleteSuccessorOperation(
        $object,
        onSoftDelete $onDelete,
        \ReflectionProperty $property,
        ClassMetadata $meta,
        $softDelete,
        PreSoftDeleteEventArgs $args,
        $config
    ) {
        $reflProp = $meta->getReflectionProperty($property->name);
        $oldValue = $reflProp->getValue($object);

        $reader = new AnnotationReader();
        $reflectionClass = new \ReflectionClass(ClassUtils::getClass($oldValue));
        $successors = [];
        foreach ($reflectionClass->getProperties() as $propertyOfOldValueObject) {
            if ($reader->getPropertyAnnotation($propertyOfOldValueObject, onSoftDeleteSuccessor::class)) {
                $successors[] = $propertyOfOldValueObject;
            }
        }

        if (count($successors) > 1) {
            throw new \Exception('Only one property of deleted entity can be marked as successor.');
        } elseif (empty($successors)) {
            throw new \Exception('One property of deleted entity must be marked as successor.');
        }

        $successors[0]->setAccessible(true);

        if ($oldValue instanceof Proxy) {
            $oldValue->__load();
        }

        $newValue = $successors[0]->getValue($oldValue);
        $reflProp->setValue($object, $newValue);
        $args->getObjectManager()->persist($object);

        $args->getObjectManager()->getUnitOfWork()->propertyChanged($object, $property->name, $oldValue, $newValue);
        $args->getObjectManager()->getUnitOfWork()->scheduleExtraUpdate($object, array(
            $property->name => array($oldValue, $newValue),
        ));
    }

    /**
     * @param $object
     * @param onSoftDelete $onDelete
     * @param \ReflectionProperty $property
     * @param ClassMetadata $meta
     * @param $softDelete
     * @param PreSoftDeleteEventArgs $args
     * @param $config
     */
    protected function processOnDeleteCascadeOperation(
        $object,
        onSoftDelete $onDelete,
        \ReflectionProperty $property,
        ClassMetadata $meta,
        $softDelete,
        PreSoftDeleteEventArgs $args,
        $config
    ) {
        if ($softDelete) {
            $this->softDeleteCascade($args->getObjectManager(), $config, $object);
        } else {
            $args->getObjectManager()->remove($object);
        }
    }

    /**
     * @param EntityManager $em
     * @param $config
     * @param $object
     */
    protected function softDeleteCascade($em, $config, $object)
    {
        $meta = $em->getClassMetadata(get_class($object));
        $reflProp = $meta->getReflectionProperty($config['fieldName']);
        $oldValue = $reflProp->getValue($object);
        if ($oldValue instanceof \Datetime) {
            return;
        }

        //trigger event to check next level
        $em->getEventManager()->dispatchEvent(
            GedmoSoftDeleteableListener::PRE_SOFT_DELETE,
            new PreSoftDeleteEventArgs($object, $em)
        );

        $date = new \DateTime();
        $reflProp->setValue($object, $date);

        $uow = $em->getUnitOfWork();
        $uow->propertyChanged($object, $config['fieldName'], $oldValue, $date);
        $uow->scheduleExtraUpdate($object, array(
            $config['fieldName'] => array($oldValue, $date),
        ));

        $em->getEventManager()->dispatchEvent(
            GedmoSoftDeleteableListener::POST_SOFT_DELETE,
            new PostSoftDeleteEventArgs($object, $em)
        );
    }

    /**
     * @param onSoftDelete $onDelete
     * @param Annotation $relationship
     * @return bool
     */
    protected function isOnDeleteTypeSupported(onSoftDelete $onDelete, $relationship)
    {
        if (strtoupper($onDelete->type) === 'SET NULL' && ($relationship instanceof ManyToMany || (property_exists($relationship, 'type') && $relationship->type === ClassMetadataInfo::MANY_TO_MANY))) {
            return false;
        }

        return true;
    }
}
