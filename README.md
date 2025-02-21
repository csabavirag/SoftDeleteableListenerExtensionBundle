# SoftDeleteableListenerExtensionBundle

Extensions to Gedmo's softDeleteable listener which has had this issue reported since 2012 : https://github.com/Atlantic18/DoctrineExtensions/issues/505.

Provides the `onSoftDelete` functionality to an association of a doctrine entity. This functionality behaves like the SQL `onDelete` function  (when the owner side is deleted). *It will prevent Doctrine errors when a reference is soft-deleted.*

**Cascade delete the entity**

To (soft-)delete an entity when its parent record is soft-deleted :

```
 @Evence\onSoftDelete(type="CASCADE")
```

**Set reference to null (instead of deleting the entity)**

```
 @Evence\onSoftDelete(type="SET NULL")
```

**Replace reference by some property marked as successor (must be of same entity class)**

```
 @Evence\onSoftDelete(type="SUCCESSOR")
```

## Entity example

``` php
<?php

namespace AppBundle\Entity;

use Doctrine\ORM\Mapping as ORM;
use Gedmo\Mapping\Annotation as Gedmo;
use Evence\Bundle\SoftDeleteableExtensionBundle\Mapping\Annotation as Evence;

/*
 * @ORM\Entity(repositoryClass="AppBundle\Entity\AdvertisementRepository")
 * @Gedmo\SoftDeleteable(fieldName="deletedAt")
 */
class Advertisement
{

    ...

    /**
     * @ORM\ManyToOne(targetEntity="AppBundle\Entity\Shop")
     * @ORM\JoinColumn(name="shop_id", referencedColumnName="id")
     * @Evence\onSoftDelete(type="CASCADE")
     */
    private $shop;

    ...
}
```

## Install

**Install with composer:**
```
composer require evence/soft-deleteable-extension-bundle
```

Add the bundle to `app/AppKernel.php`:

``` php
# app/AppKernel.php

$bundles = array(
    ...
    new Evence\Bundle\SoftDeleteableExtensionBundle\EvenceSoftDeleteableExtensionBundle(),
);
```

**Configure with Attributes**
Override the service configuration, otherwise Attributes are interfering with the annotation_reader service definition of `SoftDeleteListener`

``` yaml
# config/packages/stof_doctrine_extensions.yaml
...
services:
    gedmo.mapping.driver.attribute:
        class: Gedmo\Mapping\Driver\AttributeReader

...
# Decorate Evence\SoftDeleteableExtensionBundle SoftDeleteListener to use the AttributeReader

    evence.softdeletale.listener.softdelete:
        class: Evence\Bundle\SoftDeleteableExtensionBundle\EventListener\SoftDeleteListener
        arguments:
            - '@gedmo.mapping.driver.attribute'
        tags:
            - { name: doctrine.event_listener, event: 'preSoftDelete' }
        public: true
```