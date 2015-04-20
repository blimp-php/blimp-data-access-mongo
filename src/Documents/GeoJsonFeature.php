<?php
namespace Blimp\DataAccess\Documents;

use Doctrine\ODM\MongoDB\Mapping\Annotations as ODM;

/**
 * @ODM\Document
 * @ODM\Index(keys={"geometry"="2dsphere"})
*/
class GeoJsonFeature extends BlimpDocument {
    /** @ODM\String */
    protected $type = 'Feature';

    /** @ODM\EmbedOne(targetDocument="GeoJsonGeometry") */
    protected $geometry;

    /** @ODM\Hash */
    protected $properties;

    public function setId($id) {
        $this->id = $id;
    }

    public function getType() {
        return $this->type;
    }

    public function setGeometry($geometry) {
        $this->geometry = $geometry;
    }

    public function getGeometry() {
        return $this->geometry;
    }

    public function setProperties($properties) {
        $this->properties = $properties;
    }

    public function getProperties() {
        return $this->properties;
    }
}
