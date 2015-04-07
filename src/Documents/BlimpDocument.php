<?php
namespace Blimp\DataAccess\Documents;

use Doctrine\ODM\MongoDB\Mapping\Annotations as ODM;
use Gedmo\Mapping\Annotation as Gedmo;

/** @ODM\MappedSuperclass */
class BlimpDocument {
    public $_custom_id;

    /** @ODM\Id */
    protected $id;

    /**
     * @ODM\ReferenceOne
     * @Gedmo\Blameable(on="create")
     */
    protected $owner;

    /**
     * @Gedmo\Timestampable(on="create")
     * @ODM\Date
     */
    protected $created;

    /**
     * @ODM\String
     * @Gedmo\Blameable(on="create")
     */
    protected $createdBy;

    /**
     * @Gedmo\Timestampable(on="update")
     * @ODM\Date
     */
    protected $updated;

    /**
     * @ODM\String
     * @Gedmo\Blameable
     */
    protected $updatedBy;

    public function setId($id) {
        if($this instanceof \Doctrine\ODM\MongoDB\Proxy\Proxy) {
            throw new \Exception("ID is immutable");
        }

        $this->id = null;
        $this->_custom_id = $id;
    }

    public function getId() {
        return $this->id != null ? $this->id : $this->_custom_id;
    }

    public function setOwner($owner) {
        $this->owner = $owner;
    }

    public function getOwner() {
        return $this->owner;
    }

    public function setCreated($created) {
        $this->created = $created;
    }

    public function getCreated() {
        return $this->created;
    }

    public function setCreatedBy($createdBy) {
        $this->createdBy = $createdBy;
    }

    public function getCreatedBy() {
        return $this->createdBy;
    }

    public function setUpdated($updated) {
        $this->updated = $updated;
    }

    public function getUpdated() {
        return $this->updated;
    }

    public function setUpdatedBy($updatedBy) {
        $this->updatedBy = $updatedBy;
    }

    public function getUpdatedBy() {
        return $this->updatedBy;
    }

    public function toStdClass($api, $level = 0) {
        return $api['dataaccess.mongoodm.utils']->toStdClass($this, $level);
    }
}
