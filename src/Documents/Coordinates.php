<?php
namespace Blimp\DataAccess\Documents;

use Doctrine\ODM\MongoDB\Mapping\Annotations as ODM;

/** @ODM\EmbeddedDocument */
class Coordinates {
    /** @ODM\Float */
    protected $x;

    /** @ODM\Float */
    protected $y;

    public function setX($x) {
        $this->x = $x;
    }
    public function getX() {
        return $this->x;
    }

    public function setY($y) {
        $this->y = $y;
    }
    public function getY() {
        return $this->y;
    }
}
