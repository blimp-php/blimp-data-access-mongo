<?php
namespace Blimp\DataAccess\Documents;

use Doctrine\ODM\MongoDB\Mapping\Annotations as ODM;

/** @ODM\Document */
class GeoJsonFeatureCollection extends BlimpDocument {
    /** @ODM\String */
    protected $type = 'FeatureCollection';

    /** @ODM\EmbedMany(targetDocument="GeoJsonFeature") */
    protected $features;

    public function getType() {
        return $this->type;
    }

    public function setFeatures($features) {
        $this->features = $features;
    }

    public function getFeatures() {
        return $this->features;
    }
}
