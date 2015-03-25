<?php
namespace Blimp\DataAccess;

use Doctrine\ODM\MongoDB\DocumentManager;
use Doctrine\ODM\MongoDB\Mapping\ClassMetadata;
use Doctrine\ODM\MongoDB\Id\AbstractIdGenerator;

class BlimpIdProvider extends AbstractIdGenerator {
    // protected $collection = null;
    // protected $key = null;

    // public function setCollection($collection) {
    //     $this->collection = $collection;
    // }

    // public function setKey($key) {
    //     $this->key = $key;
    // }

    public function generate(DocumentManager $dm, $document) {
        if(!empty($document->_custom_id)) {
            return $document->_custom_id;
        }

        return new \MongoId();
    }
}
