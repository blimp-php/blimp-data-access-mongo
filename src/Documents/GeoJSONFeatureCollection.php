<?php
namespace Blimp\DataAccess\Documents;

use Doctrine\ODM\MongoDB\Mapping\Annotations as ODM;

/** @ODM\Document */
class GeoJSONFeatureCollection extends BlimpDocument {
    /** @ODM\String */
    private $type = 'FeatureCollection';

    /** @ODM\EmbedMany(targetDocument="GeoJSONFeature") */
    private $features;

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
