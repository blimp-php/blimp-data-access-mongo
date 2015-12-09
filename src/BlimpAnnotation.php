<?php
namespace Blimp\DataAccess;

use Doctrine\Common\Annotations\Annotation;

/**
 * @Annotation
 * @Target("PROPERTY")
 */
final class BlimpAnnotation extends Annotation {
    /** @var string */
    public $return = 'default';

    /** @var string */
    public $hydrate = 'yes';

    /** @var boolean */
    public $file = false;

    /** @var string */
    public $bucket = '';
}
