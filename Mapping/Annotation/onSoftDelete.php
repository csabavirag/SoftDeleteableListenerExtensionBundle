<?php

namespace Evence\Bundle\SoftDeleteableExtensionBundle\Mapping\Annotation;

use Attribute;
use Doctrine\Common\Annotations\Annotation;

/**
 * onSoftDelete annotation for onSoftDelete behavioral extension.
 *
 * @Annotation
 * @Target("PROPERTY")
 *
 * @author Ruben Harms <info@rubenharms.nl>
 * @license MIT License (http://www.opensource.org/licenses/mit-license.php)
 */
#[Attribute(Attribute::TARGET_PROPERTY)]
final class onSoftDelete // extends Annotation
{
    /** @var string @Required */
    public $type;

    public function __construct(
        ?string $type = null
    ) {
        $this->type = $type;
    }
}
