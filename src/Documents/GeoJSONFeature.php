<?php
namespace Blimp\DataAccess\Documents;

use Doctrine\ODM\MongoDB\Mapping\Annotations as ODM;

/** @ODM\Document
 * @Index(keys={"geometry"="2dsphere"})
*/
class GeoJSONFeature extends BlimpDocument {
    /** @ODM\String */
    private $type = 'Feature';

    /** @ODM\EmbedOne(targetDocument="GeoJSONGeometry") */
    private $geometry;

    /** @ODM\Hash */
    private $properties;

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
