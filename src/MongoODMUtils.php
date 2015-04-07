<?php
namespace Blimp\DataAccess;

use Doctrine\Common\Collections\ArrayCollection;

class MongoODMUtils {
    protected $api;

    public function __construct($api) {
        $this->api = $api;
    }

    private function _pre_check($_securityDomain, $permission) {
        $can_doit = $this->api['security.permitions.check']($_securityDomain, $permission);
        $can_doit_self = $this->api['security.permitions.check']($_securityDomain, 'self_'.$permission);

        if(!$can_doit && !$can_doit_self) {
            $this->api['security.permission.denied']($_securityDomain.':'.$permission.',self_'.$permission);
        }

        if(!$can_doit) {
            if($user == null || !($can_doit = is_a($user, $_resourceClass, false)) || $id != $user->getId()) {
                $this->api['security.permission.denied']($_securityDomain.':'.$permission);
            }
        }

        return $can_doit;
    }

    private function _post_check($can_doit, $item, $user, $_securityDomain, $permission) {
        if(!$can_doit) {
            $owner = null;

            if(method_exists($item, 'getOwner')) {
                $owner = $item->getOwner();
            }

            if($owner == null || !is_a($owner, get_class($user), false) || $user->getId() != $owner->getId()) {
                $this->api['security.permission.denied']($_securityDomain.':'.$permission);
            }
        }
    }

    private function _get($_resourceClass, $id, $contentLang = null, $_parentResourceClass = null, $_parentIdField = null, $parent_id = null) {
        $dm = $this->api['dataaccess.mongoodm.documentmanager']();

        $query_builder = $dm->createQueryBuilder();
        $query_builder->eagerCursor(true);
        $query_builder->find($_resourceClass);

        $query_builder->field('_id')->equals($id);

        if($parent_id != null) {
            if ($_parentResourceClass == null) {
                throw new BlimpHttpException(Response::HTTP_INTERNAL_SERVER_ERROR, 'Parent resource class not specified');
            }

            if ($_parentIdField == null) {
                throw new BlimpHttpException(Response::HTTP_INTERNAL_SERVER_ERROR, 'Parent id field not specified');
            }

            $ref = $dm->getPartialReference($_parentResourceClass, $parent_id);

            $query_builder->field($_parentIdField)->references($ref);
        }

        if(!empty($contentLang)) {
            $this->api['dataaccess.doctrine.translatable.listener']->setTranslatableLocale($contentLang);
        }

        $query = $query_builder->getQuery();

        $item = $query->getSingleResult();

        if ($item == null) {
            throw new BlimpHttpException(Response::HTTP_NOT_FOUND, "Not found");
        }

        return $item;
    }

    public function get($_resourceClass, $id, $contentLang = null, $_securityDomain = null, $user = null, $_parentResourceClass = null, $_parentIdField = null, $parent_id = null) {
        $can_doit = $this->_pre_check($_securityDomain, 'get');

        $item = $this->_get($_resourceClass, $id, $contentLang, $_parentResourceClass, $_parentIdField, $parent_id);

        $this->_post_check($can_doit, $item, $user, $_securityDomain, 'get');

        return $item;
    }

    public function edit($patch, $data, $_resourceClass, $id, $contentLang = null, $_securityDomain = null, $user = null, $_parentResourceClass = null, $_parentIdField = null, $parent_id = null) {
        $can_doit = $this->_pre_check($_securityDomain, 'edit');

        $item = $this->_get($_resourceClass, $id, $contentLang, $_parentResourceClass, $_parentIdField, $parent_id);

        $this->_post_check($can_doit, $item, $user, $_securityDomain, 'edit');

        $api['dataaccess.mongoodm.utils']->convertToBlimpDocument($data, $item, $patch);

        if($contentLang !== null && method_exists($item, 'setTranslatableLocale')) {
            $item->setTranslatableLocale($contentLang);
        }

        $dm = $this->api['dataaccess.mongoodm.documentmanager']();

        $dm->persist($item);
        $dm->flush($item);

        return $item;
    }

    public function delete($_resourceClass, $id, $_securityDomain = null, $user = null, $_parentResourceClass = null, $_parentIdField = null, $parent_id = null) {
        $can_doit = $this->_pre_check($_securityDomain, 'delete');

        $item = $this->_get($_resourceClass, $id, null, $_parentResourceClass, $_parentIdField, $parent_id);

        $this->_post_check($can_doit, $item, $user, $_securityDomain, 'delete');

        $dm = $this->api['dataaccess.mongoodm.documentmanager']();

        $dm->remove($item);
        $dm->flush($item);

        return $item;
    }

    public function parseRequestToQuery($request, $query_builder) {
        $query_builder->eagerCursor(true);

        $order_builder = array();
        $pageStartIndex = -1;

        foreach ($request->query as $key => $value) {
            if ($key == "fields" || $key == "embed") {
                continue;
            }

            if ($key == "pageSize") {
                $query_builder->limit($value);

                continue;
            }

            if ($key == "pageStartIndex") {
                $query_builder->skip($value);

                continue;
            }

            if ($key == "orderBy") {
                $parts = explode(',', $value);

                foreach ($parts as $part) {
                    if (strlen($part) > 0) {
                        $dir = 'asc';

                        $signal = substr($part, 0, 1);

                        if ($signal == '-') {
                            $dir = 'desc';
                            $part = substr($part, 1);
                        } else if ($signal == '+') {
                            $part = substr($part, 1);
                        }

                        if (strlen($part) > 0) {
                            $order_builder[$part] = $dir;
                        }
                    }
                }

                continue;
            }

            if (strlen($value) > 3 && strpos($value, '/') !== false) {
                $bar_count = 0;

                $parts = explode('/', $value);

                $command = '';
                $expression = '';
                $options = '';

                foreach ($parts as $part) {
                    if ($bar_count == 0) {
                        $command = $part;
                        ++$bar_count;
                    } else if ($bar_count == 1) {
                        $expression .= $part;

                        if (strlen($expression) == 0 || substr($expression, strlen($expression) - 1) != '\\') {
                            ++$bar_count;
                        } else {
                            if (strlen($expression) > 0) {
                                $expression = substr($expression, 0, strlen($expression) - 1) . '/';
                            }
                        }
                    } else if ($bar_count == 2) {
                        $options = $part;
                        ++$bar_count;
                    } else {
                        ++$bar_count;
                    }
                }

                if ($command == "m") {
                    if ($bar_count == 3) {
                        $query_builder->field($key)->equals(new \MongoRegex('/' . $expression . '/' . $options));

                        continue;
                    }
                } else if ($command == "n") {
                    if ($bar_count == 2) {
                        if (is_numeric($expression)) {
                            $float_expression = floatval($expression);
                            $int_expression = intval($expression);

                            if ($float_expression != $int_expression) {
                                $query_builder->field($key)->equals($float_expression);
                            } else {
                                $query_builder->field($key)->equals($int_expression);
                            }

                            continue;
                        } else {
                            $boolean_expression = strtolower($expression);

                            if ($boolean_expression == "true" || $boolean_expression == "false") {
                                $query_builder->field($key)->equals($boolean_expression == "true");

                                continue;
                            }
                        }

                        continue;
                    }
                } else {
                    try {
                        $method = new \ReflectionMethod($query_builder, $command);

                        $query_builder->field($key);

                        if ($options == "n") {
                            if (is_numeric($expression)) {
                                $float_expression = floatval($expression);
                                $int_expression = intval($expression);

                                if ($float_expression != $int_expression) {
                                    $method->invoke($query_builder, $float_expression);
                                } else {
                                    $method->invoke($query_builder, $int_expression);
                                }

                                continue;
                            } else {
                                $boolean_expression = strtolower($expression);

                                if ($boolean_expression == "true" || $boolean_expression == "false") {
                                    $method->invoke($query_builder, $boolean_expression == "true");

                                    continue;
                                }
                            }
                        }

                        $method->invoke($query_builder, $expression);

                        continue;
                    } catch (\ReflectionException $e) {
                    }
                }
            }

            $query_builder->field($key)->equals($value);
        }

        $query_builder->sort($order_builder);

        return $query_builder;
    }

    /**
     * Convert Doctrine\ODM Document to plain simple stdClass
     *  https://gist.github.com/ajaxray/94b27439ba9c3840d420
     *
     * @return \stdClass
     */
    public function toStdClass($doc, $level = 0) {
        $dm = $this->api['dataaccess.mongoodm.documentmanager']();

        $cmf = $dm->getMetadataFactory();
        $class = $cmf->getMetadataFor(get_class($doc));

        $document = new \stdClass();

        foreach ($class->fieldMappings as $fieldMapping) {
            if($fieldMapping['isOwningSide'] && empty($fieldMapping['inversedBy'])) {
                $key = $fieldMapping['fieldName'];

                $getter = new \ReflectionMethod($doc, 'get' . ucfirst($key));

                $doc_value = $getter->invoke($doc);
                $value = $this->formatValue($doc_value, $fieldMapping, $level);

                if ($value !== null) {
                    $document->$key = $value;
                }
            }
        }

        return $document;
    }

    private function formatValue($value, $fieldMapping, $level) {
        if($level > 2) {
            return null;
        }

        if ($value === null) {
            return null;
        } else if ($fieldMapping['type'] == 'one') {
            if(method_exists($value, 'toStdClass')) {
                return $value->toStdClass($this->api, $level+1);
            }

            return $this->toStdClass($value, $level+1);
        } else if($fieldMapping['type'] == 'many') {
            $prop = array();
            foreach ($value as $v) {
                $prop[] = $this->formatValue($v, ['type' => 'one'], $level);
            }
            return $prop;
        } else {
            return $value;
        }
    }

    public function convertToBlimpDocument($data, &$item, $patch = true) {
        $dm = $this->api['dataaccess.mongoodm.documentmanager']();

        $cmf = $dm->getMetadataFactory();
        $class = $cmf->getMetadataFor(get_class($item));

        foreach ($class->fieldMappings as $fieldMapping) {
            $key = $fieldMapping['fieldName'];

            if(in_array($key, ['id', 'created', 'createdBy', 'updated', 'updatedBy'])) {
                continue;
            }

            $value = null;

            if(array_key_exists($key, $data)) {
                $value = $data[$key];
            } else if($patch) {
                continue;
            }

            $setter = new \ReflectionMethod($item, 'set' . ucfirst($key));
            $getter = new \ReflectionMethod($item, 'get' . ucfirst($key));

            $current_value = null;

            if($value !== null) {
                $current_value = $getter->invoke($item);

                if(in_array($fieldMapping['type'], ['one', 'many'])) {
                    if($fieldMapping['isOwningSide']) {
                        $c = new \ReflectionClass($fieldMapping['targetDocument']);

                        if($fieldMapping['type'] == 'one') {
                            $is_ref = false;

                            if($fieldMapping['embedded']) {
                                // it's an embedded value, replace it
                                $current_value = $c->newInstance();
                            } else {
                                // it's a reference
                                $current_id = $current_value != null ? $current_value->getId() : null;
                                $new_id = is_scalar($value) ? $value : (!empty($value['id']) ? $value['id'] : null);

                                if($fieldMapping['isCascadePersist']) {
                                    // the new/modified value will be persisted

                                    if($current_id != $new_id) {
                                        // it's a new/different value
                                        $current_value = $c->newInstance();
                                    }
                                } else if($new_id !== null) {
                                    // just keep the reference
                                    $is_ref = true;

                                    if(\MongoId::isValid($new_id)) {
                                        $current_value = $dm->getPartialReference($fieldMapping['targetDocument'], new \MongoId($new_id));
                                    } else {
                                        $current_value = $dm->getPartialReference($fieldMapping['targetDocument'], $new_id);
                                    }
                                } else {
                                    // clear the field
                                    $current_value = null;
                                }
                            }

                            if($current_value !== null && !$is_ref) {
                                $this->convertToBlimpDocument($value, $current_value, $patch);
                            }
                        } else {
                            $current_value = new ArrayCollection();

                            foreach ($value as $v) {
                                $new_value = null;
                                $is_ref = false;

                                if($fieldMapping['embedded']) {
                                    // it's an embedded value, replace it
                                    $new_value = $c->newInstance();
                                } else {
                                    // it's a reference
                                    $new_id = is_scalar($value) ? $value : (!empty($value['id']) ? $value['id'] : null);

                                    if($fieldMapping['isCascadePersist']) {
                                        // the value will be persisted
                                        $new_value = $c->newInstance();
                                    } else if($new_id !== null) {
                                        // just keep the reference
                                        $is_ref = true;

                                        if(\MongoId::isValid($new_id)) {
                                            $new_value = $dm->getPartialReference($fieldMapping['targetDocument'], new \MongoId($new_id));
                                        } else {
                                            $new_value = $dm->getPartialReference($fieldMapping['targetDocument'], $new_id);
                                        }
                                    }
                                }

                                if($new_value !== null && !$is_ref) {
                                    $this->convertToBlimpDocument($v, $new_value, $patch);
                                }

                                $current_value->add($new_value);
                            }
                        }
                    }
                } else if($fieldMapping['type'] == 'collection') {
                    $current_value = [];

                    foreach ($value as $v) {
                        $current_value[] = $v;
                    }
                } else {
                    $current_value = $value;
                }
            }

            $setter->invoke($item, $current_value);
        }

        return $item;
    }
}
