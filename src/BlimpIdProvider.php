<?php
namespace Blimp\DataAccess;

use Doctrine\ODM\MongoDB\DocumentManager;
use Doctrine\ODM\MongoDB\Mapping\ClassMetadata;
use Doctrine\ODM\MongoDB\Id\AbstractIdGenerator;

class BlimpIdProvider extends AbstractIdGenerator {
    public function generate(DocumentManager $dm, $document) {
        if(!empty($document->_custom_id)) {
            return $document->_custom_id;
        }

        return new \MongoId();
    }
}
