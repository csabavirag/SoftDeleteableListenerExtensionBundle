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
    public string $type;

    public function __construct(mixed $type = null)
    {
        if (is_string($type)) {
            $this->type = $type;
        } elseif (!empty($type['type'])) {
            $this->type = $type['type'];
        } else {
            $this->type = 'CASCADE';
        }
    }
}
