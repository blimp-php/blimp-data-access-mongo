<?php
namespace Blimp\DataAccess\Documents;

use Doctrine\ODM\MongoDB\Mapping\Annotations as ODM;

/** @ODM\EmbeddedDocument */
class GeoJsonGeometry {
    /** @ODM\String */
    protected $type;

    /** @ODM\Collection */
    protected $coordinates = array();

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
