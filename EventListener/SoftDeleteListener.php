<?php

namespace Evence\Bundle\SoftDeleteableExtensionBundle\EventListener;

use Doctrine\Common\Util\ClassUtils;
use Doctrine\ORM\EntityManagerInterface;
use Doctrine\ORM\Mapping\ClassMetadataInfo;
use Doctrine\Persistence\Proxy;
use Evence\Bundle\SoftDeleteableExtensionBundle\Exception\OnSoftDeleteUnknownTypeException;
use Evence\Bundle\SoftDeleteableExtensionBundle\Mapping\Annotation\onSoftDelete;
use Evence\Bundle\SoftDeleteableExtensionBundle\Mapping\Annotation\onSoftDeleteSuccessor;
use Gedmo\SoftDeleteable\Event\PostSoftDeleteEventArgs;
use Gedmo\SoftDeleteable\Event\PreSoftDeleteEventArgs;
use Gedmo\SoftDeleteable\SoftDeleteableListener as GedmoSoftDeleteableListener;
use Symfony\Component\PropertyAccess\PropertyAccess;

/**
 * Soft delete listener class for onSoftDelete behaviour.
 * Optimized for PHP 8.2+ with native attributes support.
 *
 * @author Ruben Harms <info@rubenharms.nl>
 *
 * @link http://www.rubenharms.nl
 * @link https://www.github.com/RubenHarms
 */
class SoftDeleteListener
{
    /**
     * Cache for entity metadata to avoid repeated reflection operations.
     * Structure: [entityClass => [propertyName => [onSoftDelete config, relationship config, isSoftDeletable, softDeleteFieldName]]]
     */
    private array $metadataCache = [];

    /**
     * Cache for successor property metadata.
     * Structure: [entityClass => propertyName]
     */
    private array $successorCache = [];

    /**
     * @param PreSoftDeleteEventArgs $args
     *
     * @throws OnSoftDeleteUnknownTypeException
     * @throws \Exception
     */
    public function preSoftDelete(PreSoftDeleteEventArgs $args): void
    {
        /** @var EntityManagerInterface */
        $em = $args->getObjectManager();
        $entity = $args->getObject();
        $entityClass = ClassUtils::getClass($entity);

        // Get all entity classes that might reference this entity
        $allClasses = $em->getConfiguration()
            ->getMetadataDriverImpl()
            ->getAllClassNames();

        foreach ($allClasses as $namespace) {
            // Skip abstract classes
            if (!$this->isConcreteClass($namespace)) {
                continue;
            }

            // Get cached or build metadata for this entity class
            $classMetadata = $this->getClassMetadata($em, $namespace);

            foreach ($classMetadata as $propertyName => $metadata) {
                $onDelete = $metadata['onSoftDelete'];
                $associationMapping = $metadata['associationMapping'];
                
                if (!$onDelete || !$associationMapping) {
                    continue;
                }

                $objects = null;
                $relationship = $associationMapping;

                // Check if this property references the entity being soft-deleted
                if ($this->isReferencingEntity($relationship, $entityClass, $entity, $namespace)) {
                    if (!$this->isOnDeleteTypeSupported($onDelete, $relationship)) {
                        throw new \Exception(sprintf(
                            '%s is not supported for %s relationships',
                            $onDelete->type,
                            $this->getRelationshipTypeName($relationship)
                        ));
                    }

                    // Handle ManyToOne and OneToOne relationships
                    if ($this->isManyToOneOrOneToOne($relationship)) {
                        $objects = $em->getRepository($namespace)->findBy([
                            $propertyName => $entity,
                        ]);
                    }
                    // Handle ManyToMany relationships
                    elseif ($this->isManyToMany($relationship)) {
                        $this->handleManyToManyRelationship($em, $namespace, $propertyName, $entity, $entityClass);
                    }
                }

                // Process the found objects
                if ($objects) {
                    foreach ($objects as $object) {
                        $this->processOnDeleteOperation(
                            $object,
                            $onDelete,
                            $propertyName,
                            $metadata['isSoftDeletable'],
                            $metadata['softDeleteFieldName'],
                            $args
                        );
                    }
                }
            }
        }
    }

    /**
     * Get or build metadata cache for a class.
     */
    private function getClassMetadata(EntityManagerInterface $em, string $className): array
    {
        if (isset($this->metadataCache[$className])) {
            return $this->metadataCache[$className];
        }

        $this->metadataCache[$className] = [];
        $doctrineMetadata = $em->getClassMetadata($className);
        $reflectionClass = $doctrineMetadata->getReflectionClass();

        // Check if this class is soft deletable
        $classAttributes = $reflectionClass->getAttributes(\Gedmo\Mapping\Annotation\SoftDeleteable::class);
        $isSoftDeletable = !empty($classAttributes);
        $softDeleteFieldName = $isSoftDeletable ? ($classAttributes[0]->getArguments()['fieldName'] ?? 'deletedAt') : null;

        // Iterate through properties once and cache all metadata
        foreach ($reflectionClass->getProperties() as $property) {
            $propertyName = $property->getName();
            
            // Get onSoftDelete attribute
            $onSoftDeleteAttrs = $property->getAttributes(onSoftDelete::class);
            $onDelete = null;
            if (!empty($onSoftDeleteAttrs)) {
                $arguments = $onSoftDeleteAttrs[0]->getArguments();
                $onDelete = new onSoftDelete($arguments);
            }

            // Get association mapping if exists
            $associationMapping = null;
            if ($doctrineMetadata->hasAssociation($propertyName)) {
                $associationMapping = (object)$doctrineMetadata->getAssociationMapping($propertyName);
            }

            $this->metadataCache[$className][$propertyName] = [
                'onSoftDelete' => $onDelete,
                'associationMapping' => $associationMapping,
                'isSoftDeletable' => $isSoftDeletable,
                'softDeleteFieldName' => $softDeleteFieldName,
            ];
        }

        return $this->metadataCache[$className];
    }

    /**
     * Get successor property for a class (cached).
     */
    private function getSuccessorProperty(string $className): string
    {
        if (isset($this->successorCache[$className])) {
            return $this->successorCache[$className];
        }

        $reflectionClass = new \ReflectionClass($className);
        $successors = [];

        foreach ($reflectionClass->getProperties() as $property) {
            $attributes = $property->getAttributes(onSoftDeleteSuccessor::class);
            if (!empty($attributes)) {
                $successors[] = $property->getName();
            }
        }

        if (count($successors) > 1) {
            throw new \Exception('Only one property of deleted entity can be marked as successor.');
        } elseif (empty($successors)) {
            throw new \Exception('One property of deleted entity must be marked as successor.');
        }

        $this->successorCache[$className] = $successors[0];
        return $successors[0];
    }

    /**
     * Check if a class is concrete (not abstract).
     */
    private function isConcreteClass(string $className): bool
    {
        static $cache = [];
        
        if (!isset($cache[$className])) {
            $cache[$className] = !(new \ReflectionClass($className))->isAbstract();
        }
        
        return $cache[$className];
    }

    /**
     * Check if a relationship is referencing the given entity.
     */
    private function isReferencingEntity($relationship, string $entityClass, object $entity, string $owningClass): bool
    {
        $targetEntity = $relationship->targetEntity;
        
        // Resolve target entity class name
        $targetClass = $this->resolveTargetEntity($targetEntity, $owningClass);
        
        return $targetClass && is_a($entityClass, $targetClass, true);
    }

    /**
     * Resolve target entity class name from various formats.
     */
    private function resolveTargetEntity(string $targetEntity, string $owningClass): ?string
    {
        static $cache = [];
        $cacheKey = $targetEntity . '|' . $owningClass;
        
        if (isset($cache[$cacheKey])) {
            return $cache[$cacheKey];
        }

        // Try as-is
        if (class_exists($targetEntity)) {
            return $cache[$cacheKey] = $targetEntity;
        }

        // Try with leading backslash
        $withLeadingSlash = '\\' . ltrim($targetEntity, '\\');
        if (class_exists($withLeadingSlash)) {
            return $cache[$cacheKey] = $withLeadingSlash;
        }

        // Try relative to owning class namespace
        $owningNamespace = (new \ReflectionClass($owningClass))->getNamespaceName();
        $relativeClass = $owningNamespace . '\\' . $targetEntity;
        if (class_exists($relativeClass)) {
            return $cache[$cacheKey] = $relativeClass;
        }

        return $cache[$cacheKey] = null;
    }

    /**
     * Check if relationship is ManyToOne or OneToOne.
     */
    private function isManyToOneOrOneToOne($relationship): bool
    {
        return in_array(
            $relationship->type,
            [ClassMetadataInfo::MANY_TO_ONE, ClassMetadataInfo::ONE_TO_ONE],
            true
        );
    }

    /**
     * Check if relationship is ManyToMany.
     */
    private function isManyToMany($relationship): bool
    {
        return $relationship->type === ClassMetadataInfo::MANY_TO_MANY;
    }

    /**
     * Get human-readable relationship type name.
     */
    private function getRelationshipTypeName($relationship): string
    {
        return match ($relationship->type) {
            ClassMetadataInfo::MANY_TO_ONE => 'ManyToOne',
            ClassMetadataInfo::ONE_TO_ONE => 'OneToOne',
            ClassMetadataInfo::MANY_TO_MANY => 'ManyToMany',
            ClassMetadataInfo::ONE_TO_MANY => 'OneToMany',
            default => 'Unknown',
        };
    }

    /**
     * Handle ManyToMany relationship cleanup.
     */
    private function handleManyToManyRelationship(
        EntityManagerInterface $em,
        string $namespace,
        string $propertyName,
        object $entity,
        string $entityClass
    ): void {
        $allowMappedSide = get_class($entity) === $namespace;
        $allowInversedSide = is_a($entityClass, $namespace, true);

        if ($allowInversedSide) {
            $mtmRelations = $em->getRepository($namespace)
                ->createQueryBuilder('entity')
                ->innerJoin(sprintf('entity.%s', $propertyName), 'mtm')
                ->addSelect('mtm')
                ->andWhere(sprintf(':entity MEMBER OF entity.%s', $propertyName))
                ->setParameter('entity', $entity)
                ->getQuery()
                ->getResult();

            $propertyAccessor = PropertyAccess::createPropertyAccessor();
            foreach ($mtmRelations as $mtmRelation) {
                try {
                    $collection = $propertyAccessor->getValue($mtmRelation, $propertyName);
                    $collection->removeElement($entity);
                } catch (\Exception $e) {
                    throw new \Exception(sprintf(
                        'No accessor found for %s in %s',
                        $propertyName,
                        get_class($mtmRelation)
                    ));
                }
            }
        } elseif ($allowMappedSide) {
            $propertyAccessor = PropertyAccess::createPropertyAccessor();
            try {
                $collection = $propertyAccessor->getValue($entity, $propertyName);
                $collection->clear();
            } catch (\Exception $e) {
                throw new \Exception(sprintf(
                    'No accessor found for %s in %s',
                    $propertyName,
                    get_class($entity)
                ));
            }
        }
    }

    /**
     * Process the onDelete operation.
     *
     * @throws OnSoftDeleteUnknownTypeException
     */
    protected function processOnDeleteOperation(
        object $object,
        onSoftDelete $onDelete,
        string $propertyName,
        bool $isSoftDeletable,
        ?string $softDeleteFieldName,
        PreSoftDeleteEventArgs $args
    ): void {
        $type = strtoupper($onDelete->type);

        match ($type) {
            'SET NULL' => $this->processOnDeleteSetNullOperation($object, $propertyName, $args),
            'CASCADE' => $this->processOnDeleteCascadeOperation($object, $isSoftDeletable, $softDeleteFieldName, $args),
            'SUCCESSOR' => $this->processOnDeleteSuccessorOperation($object, $propertyName, $args),
            default => throw new OnSoftDeleteUnknownTypeException($onDelete->type),
        };
    }

    /**
     * Process SET NULL operation.
     */
    protected function processOnDeleteSetNullOperation(
        object $object,
        string $propertyName,
        PreSoftDeleteEventArgs $args
    ): void {
        $em = $args->getObjectManager();
        $meta = $em->getClassMetadata(get_class($object));
        $reflProp = $meta->getReflectionProperty($propertyName);
        $oldValue = $reflProp->getValue($object);

        $reflProp->setValue($object, null);
        $em->persist($object);

        $em->getUnitOfWork()->propertyChanged($object, $propertyName, $oldValue, null);
        $em->getUnitOfWork()->scheduleExtraUpdate($object, [
            $propertyName => [$oldValue, null],
        ]);
    }

    /**
     * Process SUCCESSOR operation.
     */
    protected function processOnDeleteSuccessorOperation(
        object $object,
        string $propertyName,
        PreSoftDeleteEventArgs $args
    ): void {
        $em = $args->getObjectManager();
        $meta = $em->getClassMetadata(get_class($object));
        $reflProp = $meta->getReflectionProperty($propertyName);
        $oldValue = $reflProp->getValue($object);

        // Load proxy if needed
        if ($oldValue instanceof Proxy) {
            $oldValue->__load();
        }

        // Get successor property (cached)
        $oldValueClass = ClassUtils::getClass($oldValue);
        $successorPropertyName = $this->getSuccessorProperty($oldValueClass);

        // Get new value from successor property
        $successorMeta = $em->getClassMetadata($oldValueClass);
        $successorReflProp = $successorMeta->getReflectionProperty($successorPropertyName);
        $newValue = $successorReflProp->getValue($oldValue);

        $reflProp->setValue($object, $newValue);
        $em->persist($object);

        $em->getUnitOfWork()->propertyChanged($object, $propertyName, $oldValue, $newValue);
        $em->getUnitOfWork()->scheduleExtraUpdate($object, [
            $propertyName => [$oldValue, $newValue],
        ]);
    }

    /**
     * Process CASCADE operation.
     */
    protected function processOnDeleteCascadeOperation(
        object $object,
        bool $isSoftDeletable,
        ?string $softDeleteFieldName,
        PreSoftDeleteEventArgs $args
    ): void {
        $em = $args->getObjectManager();

        if ($isSoftDeletable && $softDeleteFieldName) {
            $this->softDeleteCascade($em, $softDeleteFieldName, $object);
        } else {
            $em->remove($object);
        }
    }

    /**
     * Perform soft delete cascade.
     */
    protected function softDeleteCascade(
        EntityManagerInterface $em,
        string $fieldName,
        object $object
    ): void {
        $meta = $em->getClassMetadata(get_class($object));
        $reflProp = $meta->getReflectionProperty($fieldName);
        $oldValue = $reflProp->getValue($object);

        // Skip if already soft deleted
        if ($oldValue instanceof \DateTime) {
            return;
        }

        // Trigger pre-soft-delete event to check next level
        $em->getEventManager()->dispatchEvent(
            GedmoSoftDeleteableListener::PRE_SOFT_DELETE,
            new PreSoftDeleteEventArgs($object, $em)
        );

        $date = new \DateTime();
        $reflProp->setValue($object, $date);

        $uow = $em->getUnitOfWork();
        $uow->propertyChanged($object, $fieldName, $oldValue, $date);
        $uow->scheduleExtraUpdate($object, [
            $fieldName => [$oldValue, $date],
        ]);

        $em->getEventManager()->dispatchEvent(
            GedmoSoftDeleteableListener::POST_SOFT_DELETE,
            new PostSoftDeleteEventArgs($object, $em)
        );
    }

    /**
     * Check if onDelete type is supported for the relationship.
     */
    protected function isOnDeleteTypeSupported(onSoftDelete $onDelete, $relationship): bool
    {
        // SET NULL is not supported for ManyToMany relationships
        if (strtoupper($onDelete->type) === 'SET NULL' && $this->isManyToMany($relationship)) {
            return false;
        }

        return true;
    }

    /**
     * Clear the metadata cache (useful for testing or long-running processes).
     */
    public function clearCache(): void
    {
        $this->metadataCache = [];
        $this->successorCache = [];
    }
}
