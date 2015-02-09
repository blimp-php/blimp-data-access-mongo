<?php
namespace Blimp\DataAccess;

use Doctrine\Common\Collections\ArrayCollection;

class MongoODMUtils {
    protected $api;

    public function __construct($api) {
        $this->api = $api;
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
    public function toStdClass($doc) {
        $dm = $this->api['dataaccess.mongoodm.documentmanager']();

        $cmf = $dm->getMetadataFactory();
        $class = $cmf->getMetadataFor(get_class($doc));

        $document = new \stdClass();

        foreach ($class->fieldMappings as $fieldMapping) {
            if($fieldMapping['isOwningSide']) {
                $key = $fieldMapping['fieldName'];

                $getter = new \ReflectionMethod($doc, 'get' . ucfirst($key));

                $doc_value = $getter->invoke($doc);
                $value = $this->formatValue($doc_value, $fieldMapping);

                if ($value !== null) {
                    $document->$key = $value;
                }
            }
        }

        return $document;
    }

    public function formatValue($value, $fieldMapping) {
        if ($value === null) {
            return null;
        } else if ($fieldMapping['type'] == 'one') {
            return $this->toStdClass($value);
        } else if($fieldMapping['type'] == 'many') {
            $prop = array();
            foreach ($value as $v) {
                $prop[] = $this->formatValue($v, ['type' => 'one']);
            }
            return $prop;
        } else {
            return $value;
        }
    }

    public function convertToBlimpDocument($data, &$item, $patch =  true) {
        $dm = $this->api['dataaccess.mongoodm.documentmanager']();

        $cmf = $dm->getMetadataFactory();
        $class = $cmf->getMetadataFor(get_class($item));

        foreach ($class->fieldMappings as $fieldMapping) {
            $key = $fieldMapping['fieldName'];

            if(in_array($key, ['created', 'updated'])) {
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
                    if($fieldMapping['isOwningSide'] && $fieldMapping['isCascadePersist']) {
                        $c = new \ReflectionClass($fieldMapping['targetDocument']);

                        if($fieldMapping['type'] == 'one') {
                            if($current_value == null) {
                                $current_value = $c->newInstance();
                            }

                            $this->convertToBlimpDocument($value, $current_value, $patch);
                        } else {
                            $current_value = new ArrayCollection();

                            foreach ($value as $v) {
                                $new_value = null;
                                if(!$fieldMapping['embedded'] && array_key_exists('id', $data)) {
                                    $new_value = $dm->find($fieldMapping['targetDocument'], $data['id']);
                                }

                                if($new_value == null) {
                                    $new_value = $c->newInstance();
                                }

                                $this->convertToBlimpDocument($v, $new_value, $patch);

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
