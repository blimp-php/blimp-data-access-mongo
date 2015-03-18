<?php
namespace Blimp\DataAccess\Documents;

use Doctrine\ODM\MongoDB\Mapping\Annotations as ODM;

/** @ODM\EmbeddedDocument */
class GeoJsonGeometry {
    /** @ODM\String */
    private $type;

    /** @ODM\Collection */
    private $coordinates = array();

    public function setType($type) {
        $this->type = $type;
    }
    public function getType() {
        return $this->type;
    }

    public function setCoordinates($coordinates) {
        $this->coordinates = $coordinates;
    }

    public function getCoordinates() {
        return $this->coordinates;
    }
}
