<?php

namespace Evence\Bundle\SoftDeleteableExtensionBundle\Mapping\Annotation;

use Attribute;

/**
 * onSoftDeleteSuccessor attribute for onSoftDelete behavioral extension.
 *
 * @author Ruben Harms <info@rubenharms.nl>
 * @license MIT License (http://www.opensource.org/licenses/mit-license.php)
 */
#[Attribute(Attribute::TARGET_PROPERTY)]
final class onSoftDeleteSuccessor
{
}
