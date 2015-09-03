<?php

namespace Blimp\DataAccess;

use Doctrine\Common\Collections\ArrayCollection;
use Symfony\Component\HttpFoundation\Response;
use Blimp\Http\BlimpHttpException;

class MongoODMUtils
{
    protected $api;

    public function __construct($api)
    {
        $this->api = $api;
    }

    private function _pre_check($_securityDomain, $permission, $user, $_resourceClass, $id = null)
    {
        $can_doit = $this->api['security.permitions.check']($_securityDomain, $permission);
        $can_doit_self = $this->api['security.permitions.check']($_securityDomain, 'self_'.$permission);

        if (!$can_doit && !$can_doit_self) {
            $this->api['security.permission.denied']($_securityDomain.':'.$permission.',self_'.$permission);
        }

        $limit_to_id = null;
        $limit_to_owner = null;

        if (!$can_doit) {
            if ($user == null) {
                $this->api['security.permission.denied']($_securityDomain.':'.$permission);
            }

            if (is_a($user, $_resourceClass, false)) {
                $limit_to_id = $user->getId();

                if ($id != $limit_to_id) {
                    $this->api['security.permission.denied']($_securityDomain.':'.$permission);
                }
            } elseif (method_exists($_resourceClass, 'getOwner')) {
                $limit_to_owner = $user;
            } else {
                $this->api['security.permission.denied']($_securityDomain.':'.$permission);
            }
        }

        if (!empty($limit_to_id)) {
            return ['id' => $limit_to_id, 'owner' => null];
        } elseif (!empty($limit_to_owner)) {
            return ['id' => null, 'owner' => $limit_to_owner];
        }

        return $can_doit;
    }

    private function _post_check($can_doit, $_securityDomain, $permission, $user, $item)
    {
        if (!$can_doit) {
            $owner = null;

            if (method_exists($item, 'getOwner')) {
                $owner = $item->getOwner();
            }

            if ($owner == null || !is_a($owner, get_class($user), false) || $user->getId() != $owner->getId()) {
                $this->api['security.permission.denied']($_securityDomain.':'.$permission);
            }
        }
    }

    private function _get($_resourceClass, $query = null, $id = null, $owner = null, $contentLang = null, $_parentResourceClass = null, $_parentIdField = null, $parent_id = null)
    {
        $dm = $this->api['dataaccess.mongoodm.documentmanager']();

        $cmf = $dm->getMetadataFactory();
        $class = $cmf->getMetadataFor($_resourceClass);

        $query_builder = $dm->createQueryBuilder();
        $query_builder->eagerCursor(true);
        $query_builder->find($_resourceClass);

        $fields_to_get = array();
        $fields_to_embed = array();

        $map = array();
        $reduce = array();

        if (!empty($query)) {
            $this->parseRequestToQuery($_resourceClass, $query, $query_builder, $map, $reduce, $fields_to_get, $fields_to_embed);
        }

        if (!empty($id)) {
            if(is_array($id)) {
                foreach ($id as $key => $value) {
                    if($key === 'id') {
                        $key = '_id';
                    }

                    $query_builder->field($key)->equals($value);
                }
            } else {
                $query_builder->field('_id')->equals($id);
            }
        }

        if (!empty($owner)) {
            $query_builder->field('owner')->references($owner);
        }

        if (!empty($parent_id)) {
            if (empty($_parentResourceClass)) {
                throw new BlimpHttpException(Response::HTTP_INTERNAL_SERVER_ERROR, 'Parent resource class not specified');
            }

            if (empty($_parentIdField)) {
                throw new BlimpHttpException(Response::HTTP_INTERNAL_SERVER_ERROR, 'Parent id field not specified');
            }

            $ref = $dm->getPartialReference($_parentResourceClass, $parent_id);

            $query_builder->field($_parentIdField)->references($ref);
        }

        if (!empty($contentLang)) {
            $this->api['dataaccess.doctrine.translatable.listener']->setTranslatableLocale($contentLang);
        }

        $m_query = $query_builder->getQuery();

        $cursor = $m_query->execute();

        $count = $cursor->count(false);

        $elements = array();

        $result = array();

        if (!empty($map) || !empty($reduce)) {
            $aggregation = array();

            foreach ($cursor as $item) {
                $values = &$elements;

                unset($c);
                $c = null;

                if (!empty($map)) {
                    $lookup = '';
                    foreach ($map as $fields) {
                        foreach ($fields as $field) {
                            if (!isset($item['_id'][$field['field_key']])) {
                                continue 2;
                            }

                            $item['_id'][$field['field_key']] = $this->_hydrate($class, $field, $item['_id'], $lookup);
                        }

                        if ($c !== null) {
                            if (empty($c['values'])) {
                                $c['values'] = array();
                            }

                            $values = &$c['values'];
                        }

                        if (empty($aggregation[$lookup])) {
                            $aggregation[$lookup] = array();
                            $values[] = &$aggregation[$lookup];
                        }

                        $c = &$aggregation[$lookup];
                        foreach ($fields as $field) {
                            $field = $field['field'];

                            $c[$field] = $item['_id'][$field];
                        }
                    }
                } else {
                    $c = array();
                    $values[] = &$c;
                }

                foreach ($reduce as $var) {
                    if (!isset($item['value'][$var['field_key']]) || is_numeric($item['value'][$var['field_key']]) && is_nan($item['value'][$var['field_key']])) {
                        continue;
                    }

                    $c[$var['field_key']] = $this->_hydrate($class, $var, $item['value']);
                }
            }
        } else {
            $result['count'] = $count;

            foreach ($cursor as $item) {
                $elements[] = $item;
            }
        }

        // TODO Links next and prev, both in $result->links and 'Links' header

        $result['elements'] = $elements;

        $result['_meta'] = [
            'fields_to_get' => $fields_to_get,
            'fields_to_embed' => $fields_to_embed,
            'map' => $map,
            'reduce' => $reduce,
        ];

        return $result;
    }

    private function _buildFieldPath($s_class, $key, &$keys_many)
    {
        $field_path = 'this';
        $fieldMapping = ['type' => 'one', 'targetDocument' => $s_class->name, 'simple' => true];
        $expand_array = false;

        if (!empty($key)) {
            $dm = $this->api['dataaccess.mongoodm.documentmanager']();

            $parts = explode('.', $key);

            foreach ($parts as $i => $field) {
                $bp = explode('[', $field);

                $field = array_shift($bp);
                $end = array_pop($bp);

                $expand_array = $end === ']';

                $fieldMapping = $s_class->getFieldMapping($field);

                $field .= implode('[', $bp);

                if ($expand_array) {
                    $inc = strtr($field_path.'_'.$field, '.[]', '___');
                    $keys_many[] = $field_path.'.'.$field;
                    $field_path .= '.'.$field.'['.$inc.'_idx]';
                } else {
                    $field_path .= '.'.$field.($end != null ? '['.$end : '');
                }

                if (!empty($fieldMapping['targetDocument'])) {
                    $s_class = $dm->getClassMetadata($fieldMapping['targetDocument']);
                }
            }
        } else {
            $field_path .= '._id';
        }

        return ['path' => $field_path, 'mapping' => $fieldMapping, 'expand_array' => $expand_array];
    }

    private function _buildFieldValue($field_path, $commands)
    {
        $field_value = $field_path;

        if (!empty($commands)) {
            foreach ($commands as $command) {
                $params = explode(' ', $command);
                $command = array_shift($params);

                switch ($command) {
                    case 'replace':
                        $field_value .= '.replace('.implode(', ', $params).')';
                        break;

                    case 'upper':
                        $field_value .= '.toUpperCase()';
                        break;

                    case 'lower':
                        $field_value .= '.toLowerCase()';
                        break;

                    case 'trim':
                        $field_value .= '.trim()';
                        break;

                    case 'substr':
                        $field_value .= '.substring('.implode(', ', $params).')';
                        break;

                    case 'size':
                        $field_value .= '.length';
                        break;

                    case 'slice':
                        $field_value .= '.slice('.implode(', ', $params).')';
                        break;

                    case 'format':
                        $field_value = 'formatDate('.$field_value.', '.implode(', ', $params).')';
                        break;

                    case 'diff':
                        if (empty($params) || $params[0] == 'now') {
                            $params[0] = 'new Date()';
                        } else {
                            $params[0] = 'new Date('.$params[0].')';
                        }

                        $field_value = 'diffDate('.$field_value.', '.implode(', ', $params).')';
                        break;

                    case 'round':
                    case 'floor':
                    case 'ceil':
                        if (empty($params[0])) {
                            $params[0] = 0;
                        }

                        $multi = '1';
                        $multi = str_pad($multi, intval($params[0]) + 1, '0');

                        $field_value = '(Math.'.$command.'('.$field_value.' * '.$multi.') / '.$multi.')';
                        break;

                    default:
                        # code...
                        break;
                }
            }
        }

        return $field_value;
    }

    private function _hydrate($s_class, $field_info, $values, &$lookup = '')
    {
        $dm = $this->api['dataaccess.mongoodm.documentmanager']();

        $field = $field_info['field_key'];
        $fieldMapping = $field_info['field_mapping'];
        $expand_array = $field_info['expand_array'];
        $is_array = isset($field_info['operator']) && $field_info['operator'] === 'aggregate';

        if ((is_array($values[$field]) || is_object($values[$field])) && in_array($fieldMapping['type'], ['one', 'many'])) {
            if ($is_array || (!$expand_array && $fieldMapping['type'] == 'many')) {
                $references = $values[$field];
            } else {
                $references = [$values[$field]];
            }

            $className = $fieldMapping['targetDocument'];
            $targetMetadata = $dm->getClassMetadata($className);

            $return = array();

            if (isset($fieldMapping['embedded']) && $fieldMapping['embedded']) {
                $id = '';
                foreach ($references as $reference) {
                    $obj = $targetMetadata->newInstance();

                    $embeddedData = $dm->getHydratorFactory()->hydrate($obj, $reference, []);
                    $id .= $targetMetadata->identifier && isset($embeddedData[$targetMetadata->identifier]) ? $embeddedData[$targetMetadata->identifier] : hash('md5', serialize($embeddedData));
                    $id .= '§';

                    $return[] = $obj;
                }

                $lookup .= $field.'|'.$id.'|';
            } else {
                $id = '';

                foreach ($references as $reference) {
                    if (isset($fieldMapping['simple']) && $fieldMapping['simple']) {
                        $mongoId = $reference;
                    } else {
                        if (!is_array($reference)) {
                            $mongoId = $reference->getId();
                        } else {
                            $mongoId = $reference['$id'];
                        }
                    }

                    $s_id = $targetMetadata->getPHPIdentifierValue($mongoId);

                    $return[] = $dm->getReference($className, $s_id);

                    $id .= $s_id.'§';
                }

                $lookup .= $field.'|'.$id.'|';
            }

            if ($is_array || (!$expand_array && $fieldMapping['type'] == 'many')) {
                return $return;
            } else {
                return $return[0];
            }
        } else {
            $v = $values[$field];

            if (is_array($v)) {
                $lookup .= $field.'|'.hash('md5', serialize($v)).'|';
            } else {
                $lookup .= $field.'|'.$values[$field].'|';
            }

            return $v;
        }
    }

    public function search($_resourceClass, $query, $contentLang = null, $_securityDomain = null, $user = null, $_parentResourceClass = null, $_parentIdField = null, $parent_id = null)
    {
        $can_doit = $this->_pre_check($_securityDomain, 'list', $user, $_resourceClass);

        $result = $this->_get($_resourceClass, $query, $can_doit['id'], $can_doit['owner'], $contentLang, $_parentResourceClass, $_parentIdField, $parent_id);

        if (isset($result['count']) && $result['count'] === 0) {
            throw new BlimpHttpException(Response::HTTP_NO_CONTENT, 'No content');
        }

        return $this->toStdClass($result);
    }

    public function get($_resourceClass, $id, $query, $contentLang = null, $_securityDomain = null, $user = null, $_parentResourceClass = null, $_parentIdField = null, $parent_id = null)
    {
        $can_doit = $this->_pre_check($_securityDomain, 'get', $user, $_resourceClass, $id);

        $result = $this->_get($_resourceClass, $query, $id, null, $contentLang, $_parentResourceClass, $_parentIdField, $parent_id);

        if ($result['count'] === 0) {
            throw new BlimpHttpException(Response::HTTP_NOT_FOUND, 'Not found');
        }

        $item = $result['elements'][0];

        $this->_post_check($can_doit, $_securityDomain, 'get', $user, $item);

        $res = $this->toStdClass($result);

        return $res['elements'][0];
    }

    public function edit($patch, $data, $files, $_resourceClass, $id, $contentLang = null, $_securityDomain = null, $user = null, $_parentResourceClass = null, $_parentIdField = null, $parent_id = null)
    {
        $can_doit = $this->_pre_check($_securityDomain, 'edit', $user, $_resourceClass, $id);

        $result = $this->_get($_resourceClass, null, $id, null, $contentLang, $_parentResourceClass, $_parentIdField, $parent_id);

        // TODO: Allow the configuration if it's a store (allow direct PUT of non-existing resources)
        if ($result['count'] !== 0) {
            $item = $result['elements'][0];
            // throw new BlimpHttpException(Response::HTTP_NOT_FOUND, 'Not found');

            $this->_post_check($can_doit, $_securityDomain, 'edit', $user, $item);
        } else {
            $c = new \ReflectionClass($_resourceClass);
            $item = $c->newInstance();
            $item->setId($id);
        }

        $this->convertToBlimpDocument($data, $item, $patch, $files);

        if ($contentLang !== null && method_exists($item, 'setTranslatableLocale')) {
            $item->setTranslatableLocale($contentLang);
        }

        $dm = $this->api['dataaccess.mongoodm.documentmanager']();

        $dm->persist($item);
        $dm->flush($item);

        return $this->toStdClass($item);
    }

    public function delete($_resourceClass, $id, $_securityDomain = null, $user = null, $_parentResourceClass = null, $_parentIdField = null, $parent_id = null)
    {
        $can_doit = $this->_pre_check($_securityDomain, 'delete', $user, $_resourceClass, $id);

        $result = $this->_get($_resourceClass, null, $id, null, null, $_parentResourceClass, $_parentIdField, $parent_id);

        if ($result['count'] === 0) {
            throw new BlimpHttpException(Response::HTTP_NOT_FOUND, 'Not found');
        }

        $item = $result['elements'][0];

        $this->_post_check($can_doit, $_securityDomain, 'delete', $user, $item);

        $dm = $this->api['dataaccess.mongoodm.documentmanager']();

        $dm->remove($item);
        $dm->flush($item);

        return $this->toStdClass($item);
    }

    public function parseRequestToQuery($_resourceClass, $query, $query_builder, &$map = null, &$reduce = null, &$fields_to_get = null, &$fields_to_embed = null)
    {
        $dm = $this->api['dataaccess.mongoodm.documentmanager']();

        static $commands_translate = [
            'nin' => 'notIn',
            'eq' => 'equals',
            'ne' => 'notEqual',
        ];

        if ($_resourceClass !== null) {
            $cmf = $dm->getMetadataFactory();
            $class = $cmf->getMetadataFor($_resourceClass);
        }

        $order_builder = array();
        $pageStartIndex = -1;

        $keys = array();
        $keys_many = array();

        $fields = array();
        $fields_zero = array();

        foreach ($query as $key => $value) {
            if ($key == 'id') {
                $key = '_id';
            }

            if ($key == 'fields') {
                $ftg = explode(',', $value);

                foreach ($ftg as $path) {
                    $sub_ftg = explode('.', $path);
                    $sub_field = array_shift($sub_ftg);

                    if (!isset($fields_to_get[$sub_field])) {
                        $fields_to_get[$sub_field] = [];
                    }

                    $fields_to_get[$sub_field][] = $sub_ftg;
                }

                continue;
            }

            if ($key == 'embed' && $fields_to_embed !== null) {
                $fte = explode(',', $value);

                foreach ($fte as $path) {
                    $sub_fte = explode('.', $path);
                    $sub_field = array_shift($sub_fte);

                    if (!isset($fields_to_embed[$sub_field])) {
                        $fields_to_embed[$sub_field] = [];
                    }

                    $fields_to_embed[$sub_field][] = $sub_fte;
                }

                continue;
            }

            if ($key == 'map' && $map !== null) {
                $map = explode('>', $value);
                foreach ($map as $key => $val) {
                    $subvals = explode(',', $val);

                    foreach ($subvals as $subkey => $field) {
                        $commands = explode('|', $field);

                        $field = array_shift($commands);

                        $field_info = $this->_buildFieldPath($class, $field, $keys_many);
                        $field_value = $this->_buildFieldValue($field_info['path'], $commands);

                        $subvals[$subkey] = [
                            'field' => $field,
                            'commands' => $commands,

                            'expand_array' => $field_info['expand_array'],
                            'field_path' => $field_info['path'],
                            'field_mapping' => $field_info['mapping'],
                            'field_key' => $field,
                            'field_value' => $field_value,
                        ];

                        $keys[] = '"'.$field.'" : '.$field_value;
                    }

                    $map[$key] = $subvals;
                }

                continue;
            }

            if ($key == 'reduce' && $reduce !== null) {
                $reduce = explode(',', $value);

                foreach ($reduce as $key => $val) {
                    $parts = explode('(', trim($val));

                    $operator = $parts[0];
                    $field = '';

                    if (count($parts) > 1) {
                        $field = substr($parts[1], 0, strlen($parts[1]) - 1);
                    }

                    $field_info = $this->_buildFieldPath($class, $field, $keys_many);
                    $field_access = $field_info['path'];

                    $field_key = $field.'_'.$operator;

                    $reduce[$key] = [
                        'operator' => $operator,
                        'field' => $field,

                        'expand_array' => $field_info['expand_array'],
                        'field_path' => $field_access,
                        'field_mapping' => $field_info['mapping'],
                        'field_key' => $field_key,
                    ];

                    if ($operator == 'count') {
                        $fields[] = '"'.$field_key.'" : ('.$field_access.' !== null ? 1 : 0)';
                    } elseif ($operator == 'avg') {
                        $fields[] = '"'.$field_key.'_count" : ('.$field_access.' !== null ? 1 : 0)';
                        $fields[] = '"'.$field_key.'_sum" : ('.$field_access.' !== null ? '.$field_access.' : 0)';
                        $fields_zero[] = '"'.$field_key.'_count" : 0';
                        $fields_zero[] = '"'.$field_key.'_sum" : 0';
                    } else {
                        $fields[] = '"'.$field_key.'" : '.$field_access;
                    }

                    if ($operator == 'aggregate') {
                        $fields_zero[] = '"'.$field_key.'" : []';
                    } elseif ($operator == 'count' || $operator == 'sum') {
                        $fields_zero[] = '"'.$field_key.'" : 0';
                    } elseif ($operator == 'concat') {
                        $fields_zero[] = '"'.$field_key.'" : ""';
                    } else {
                        $fields_zero[] = '"'.$field_key.'" : null';
                    }
                }

                continue;
            }

            if ($key == 'limit') {
                $query_builder->limit($value);

                continue;
            }

            if ($key == 'offset') {
                $query_builder->skip($value);

                continue;
            }

            if ($key == 'orderBy') {
                $parts = explode(',', $value);

                foreach ($parts as $part) {
                    if (strlen($part) > 0) {
                        $dir = 'asc';

                        $signal = substr($part, 0, 1);

                        if ($signal == '-') {
                            $dir = 'desc';
                            $part = substr($part, 1);
                        } elseif ($signal == '+') {
                            $part = substr($part, 1);
                        }

                        if (strlen($part) > 0) {
                            $order_builder[$part] = $dir;
                        }
                    }
                }

                continue;
            }

            if ($key == 'search') {
                $exp = $query_builder->expr();

                $exp->operator('$text', [ '$search' => $value ]);

                $query_builder->addAnd($exp);

                continue;
            }

            if (is_array($value)) {
                $values = $value;
            } else {
                $values = [$value];
            }

            foreach ($values as $mv) {
                if (strlen($mv) > 1 && strpos($mv, '/') !== false) {
                    $bar_count = 0;

                    $parts = explode('/', $mv);

                    $command = '';
                    $expression = '';
                    $options = '';

                    foreach ($parts as $part) {
                        if ($bar_count == 0) {
                            $command = $part;
                            ++$bar_count;
                        } elseif ($bar_count == 1) {
                            $expression .= $part;

                            if (strlen($expression) == 0 || substr($expression, strlen($expression) - 1) != '\\') {
                                ++$bar_count;
                            } else {
                                if (strlen($expression) > 0) {
                                    $expression = substr($expression, 0, strlen($expression) - 1).'/';
                                }
                            }
                        } elseif ($bar_count == 2) {
                            $options = $part;
                            ++$bar_count;
                        } else {
                            ++$bar_count;
                        }
                    }

                    if ($command == 'm') {
                        if ($bar_count == 3) {
                            $query_builder->field($key)->equals(new \MongoRegex('/'.$expression.'/'.$options));

                            continue;
                        }
                    } elseif ($command == 'n') {
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

                                if ($boolean_expression == 'true' || $boolean_expression == 'false') {
                                    $query_builder->field($key)->equals($boolean_expression == 'true');

                                    continue;
                                }
                            }

                            continue;
                        }
                    } elseif ($command == 'exists') {
                        if ($bar_count == 2) {
                            $boolean_expression = strtolower($expression);

                            if ($boolean_expression == 'true' || $boolean_expression == 'false') {
                                $query_builder->field($key)->exists($boolean_expression == 'true');

                                continue;
                            }
                        }
                    } elseif ($command == 'near') {
                        $pairs = explode('|', $expression);
                        $coordinates = array_map(function ($pair) { return array_map(function ($v) { return floatval($v);}, explode(',', $pair)); }, $pairs);

                        $query_near = array('type' => 'Point');
                        $query_near['coordinates'] = array_shift($coordinates);
                        if (!empty($coordinates)) {
                            $query_near['$maxDistance'] = array_shift($coordinates)[0];
                        }
                        if (!empty($coordinates)) {
                            $query_near['$minDistance'] = array_shift($coordinates)[0];
                        }

                        $query_builder->field($key)->near($query_near);

                        continue;
                    } elseif ($command == 'inside') {
                        $pairs = explode('|', $expression);
                        $coordinates = array_map(function ($pair) { return array_map(function ($v) { return floatval($v);}, explode(',', $pair)); }, $pairs);

                        $query_builder->field($key)->geoWithin(
                          [
                            'type' => 'Polygon',
                            'coordinates' => [$coordinates],
                          ]
                        );

                        continue;
                    } else {
                        $final_value = array($expression);

                        $parts = explode('.', $key);

                        $s_class = $class;
                        foreach ($parts as $i => $field) {
                            $bp = explode('[', $field);

                            $field = array_shift($bp);

                            $fieldMapping = $s_class->getFieldMapping($field);

                            if (!empty($fieldMapping['targetDocument'])) {
                                $s_class = $dm->getClassMetadata($fieldMapping['targetDocument']);
                            }
                        }

                        $array_expression = $command == 'in' || $command == 'nin';
                        if ($array_expression) {
                            $final_value = explode(',', $expression);
                        }

                        if ($fieldMapping['type'] === 'one' || $fieldMapping['type'] === 'many') {
                            foreach ($final_value as $fk => $fv) {
                                if (!empty($fv)) {
                                    $final_value[$fk] = $dm->createDBRef($dm->getReference($fieldMapping['targetDocument'], $fv), $fieldMapping)['$id'];
                                }
                            }
                        } else if ($options == 'n') {
                            foreach ($final_value as $fk => $fv) {
                                if (is_numeric($fv)) {
                                    $float_expression = floatval($fv);
                                    $int_expression = intval($fv);

                                    if ($float_expression != $int_expression) {
                                        $final_value[$fk] = $float_expression;
                                    } else {
                                        $final_value[$fk] = $int_expression;
                                    }
                                } else {
                                    $boolean_expression = strtolower($fv);

                                    if ($boolean_expression == 'true' || $boolean_expression == 'false') {
                                        $final_value[$fk] = ($boolean_expression == 'true');
                                    }
                                }
                            }
                        }

                        try {
                            if (!empty($commands_translate[$command])) {
                                $command = $commands_translate[$command];
                            }

                            if (is_array($value)) {
                                $exp = $query_builder->expr();

                                if ($fieldMapping['type'] === 'one' || $fieldMapping['type'] === 'many') {
                                    $exp->field($key.'.$id');
                                } else {
                                    $exp->field($key);
                                }

                                $method = new \ReflectionMethod($exp, $command);
                                $method->invoke($exp, $array_expression ? $final_value : $final_value[0]);

                                $query_builder->addAnd($exp);
                            } else {
                                if ($fieldMapping['type'] === 'one' || $fieldMapping['type'] === 'many') {
                                    $query_builder->field($key.'.$id');
                                } else {
                                    $query_builder->field($key);
                                }

                                $method = new \ReflectionMethod($query_builder, $command);
                                $method->invoke($query_builder, $array_expression ? $final_value : $final_value[0]);
                            }

                            continue;
                        } catch (\ReflectionException $e) {
                            throw new BlimpHttpException(Response::HTTP_BAD_REQUEST, 'Invalid query command: '.$key.'='.$mv);
                        }
                    }
                }

                if (isset($class)) {
                    try {
                        $fieldMapping = $class->getFieldMapping($key);

                        if ($fieldMapping['type'] === 'one' || $fieldMapping['type'] === 'many') {
                            $ref = $dm->getReference($fieldMapping['targetDocument'], $mv);
                            $query_builder->field($key)->references($ref);

                            continue;
                        }
                    } catch (\Exception $e) {
                    }
                }

                $query_builder->field($key)->equals($mv);
            }
        }

        if (!empty($map) || !empty($reduce)) {
            try {
                $f_map = 'function() { try { ';

                $used = array();
                $to_close = '';
                foreach ($keys_many as $key) {
                    if (!empty($used[$key])) {
                        continue;
                    }

                    $used[$key] = 1;

                    $inc = strtr($key, '.[]', '___');
                    $f_map .= 'for (var '.$inc.'_idx = 0; '.$inc.'_idx != '.$key.'.length; ++'.$inc.'_idx) { ';

                    $to_close .= '}';
                }

                $f_map .= 'try { emit({';
                if (!empty($map)) {
                    $f_map .= implode(', ', $keys);
                } else {
                    $f_map .= '"__all_records_placeholder__" : 1';
                }
                $f_map .= '}, {';
                $f_map .= implode(', ', $fields);
                $f_map .= '}); } catch (err) { }';

                $f_map .= $to_close;

                $f_map .= '} catch (type_err) { } }';

                $f_reduce = 'function(key, values) { var reduced = { ';
                $f_reduce .= implode(', ', $fields_zero);
                $f_reduce .= '}; ';
                $f_reduce .= 'values.forEach( function(value) { ';

                foreach ($reduce as $field_meta) {
                    $operator = $field_meta['operator'];
                    $field = $field_meta['field_key'];

                    if ($operator == 'aggregate') {
                        $f_reduce .= 'reduced["'.$field.'"] = reduced["'.$field.'"].concat(value["'.$field.'"])';
                    } elseif ($operator == 'first') {
                        $f_reduce .= 'if(reduced["'.$field.'"] === null) { reduced["'.$field.'"] = value["'.$field.'"]; } ';
                    } elseif ($operator == 'last') {
                        $f_reduce .= 'reduced["'.$field.'"] = value["'.$field.'"];';
                    } elseif ($operator == 'min') {
                        $f_reduce .= 'if(reduced["'.$field.'"] === null) { reduced["'.$field.'"] = value["'.$field.'"]; } else { ';
                        $f_reduce .= 'reduced["'.$field.'"] = Math.min(reduced["'.$field.'"], value["'.$field.'"]); }';
                    } elseif ($operator == 'max') {
                        $f_reduce .= 'if(reduced["'.$field.'"] === null) { reduced["'.$field.'"] = value["'.$field.'"]; } else { ';
                        $f_reduce .= 'reduced["'.$field.'"] = Math.max(reduced["'.$field.'"], value["'.$field.'"]); }';
                    } elseif ($operator == 'avg') {
                        $f_reduce .= 'reduced["'.$field.'_count"] += value["'.$field.'_count"]; ';
                        $f_reduce .= 'reduced["'.$field.'_sum"] += value["'.$field.'_sum"]; ';
                    } elseif ($operator == 'sum' || $operator == 'count' || $operator == 'concat') {
                        $f_reduce .= 'reduced["'.$field.'"] += value["'.$field.'"]; ';
                    }
                }
                $f_reduce .= '}); return reduced; }';

                $f_finalize = 'function(key, reduced) { ';
                foreach ($reduce as $field_meta) {
                    $operator = $field_meta['operator'];
                    $field = $field_meta['field_key'];

                    if ($operator == 'avg') {
                        $f_finalize .= 'if (reduced["'.$field.'_count"] > 0) {';
                        $f_finalize .= 'reduced["'.$field.'"] = reduced["'.$field.'_sum"] / reduced["'.$field.'_count"]; ';
                        $f_finalize .= 'delete reduced["'.$field.'_count"]; ';
                        $f_finalize .= 'delete reduced["'.$field.'_sum"]; ';
                        $f_finalize .= '}';
                    }
                }
                $f_finalize .= 'return reduced; }';

                $query_builder->map($f_map);
                $query_builder->reduce($f_reduce);
                $query_builder->finalize($f_finalize);
            } catch (\Doctrine\ODM\MongoDB\Mapping\MappingException $e) {
                throw new BlimpHttpException(Response::HTTP_BAD_REQUEST, $e->getMessage(), null, $e);
            }
        }

        $query_builder->sort($order_builder);

        return $query_builder;
    }

    private function _toArray($arr, $level = 0, $to_get = array(), $to_embed = array())
    {
        $array = array();

        if (isset($arr['_meta'])) {
            $to_get = $arr['_meta']['fields_to_get'];
            $to_embed = $arr['_meta']['fields_to_embed'];
        }

        foreach ($arr as $key => $value) {
            if ($key === '_meta') {
                continue;
            }

            if (is_array($value) || is_object($value)) {
                $array[$key] = $this->toStdClass($value, $level, $to_get, $to_embed);
            } else {
                $array[$key] = $value;
            }
        }

        return $array;
    }
    /**
     * Convert Doctrine\ODM Document to plain simple stdClass
     *  https://gist.github.com/ajaxray/94b27439ba9c3840d420.
     *
     * @return \stdClass
     */
    public function toStdClass($doc, $level = 0, $to_get = array(), $to_embed = array())
    {
        if (is_array($doc)) {
            return $this->_toArray($doc, $level, $to_get, $to_embed);
        }

        if (is_a($doc, 'MongoDate')) {
            return $this->formatValue(\Doctrine\ODM\MongoDB\Types\DateType::getDateTime($doc), ['type' => 'date'], $level, $to_embed);
        }

        $dm = $this->api['dataaccess.mongoodm.documentmanager']();

        $cmf = $dm->getMetadataFactory();
        $class = $cmf->getMetadataFor(get_class($doc));

        $document = [];

        $fields = $class->getFieldNames();

        $ftg = !empty($to_get);
        $ftg_super_wildcard = $ftg && ($to_get === true || array_key_exists('**', $to_get));
        $ftg_wildcard = $ftg && ($ftg_super_wildcard || array_key_exists('*', $to_get));

        $fte = !empty($to_embed);
        $fte_super_wildcard = $fte && ($to_embed === true || array_key_exists('**', $to_embed));
        $fte_wildcard = $fte && ($fte_super_wildcard || array_key_exists('*', $to_embed));

        foreach ($fields as $key) {
            $fieldMapping = $class->getFieldMapping($key);

            $embedded_field = $fieldMapping['type'] === 'one' || $fieldMapping['type'] === 'many';

            $get_it = !$ftg && !$embedded_field
                        || $key === 'id'
                        || $ftg && (!$embedded_field && $ftg_wildcard || is_array($to_get) && array_key_exists($key, $to_get));

            $embed_it = $fte && $embedded_field && ($fte_wildcard || array_key_exists($key, $to_embed));

            $is_file = false;

            if (!$ftg) {
                $prop = new \ReflectionProperty($doc, $key);
                $prop->setAccessible(true);

                $propertyAnnotations = $this->api['dataaccess.doctrine.annotation.reader']->getPropertyAnnotations($prop);

                foreach ($propertyAnnotations as $anot) {
                    if ($anot instanceof \Blimp\DataAccess\BlimpAnnotation) {
                        if ($anot->return !== 'default') {
                            if ($anot->return === 'yes') {
                                $get_it = true;
                            } elseif ($anot->return === 'no') {
                                $get_it = false;
                            } elseif ($anot->return === 'direct') {
                                $get_it = $level == 0;
                            } elseif ($anot->return === 'reference') {
                                $get_it = $level > 0;
                            } elseif ($anot->return === 'never') {
                                continue 2;
                            } else {
                                $equals = true;
                                $lt = false;
                                $gt = false;

                                $val = $anot->return;

                                $len = strlen($val);

                                if ($len > 1) {
                                    $equals = $anot->return[0] === '=';
                                    $lt = $anot->return[0] === '<';
                                    $gt = $anot->return[0] === '>';

                                    if ($equals || $lt || $gt) {
                                        $val = substr($anot->return, 1);

                                        if ($len > 2) {
                                            if ($anot->return[1] === '=') {
                                                $equals = true;
                                                $val = substr($anot->return, 2);
                                            }
                                        }
                                    }
                                }

                                $val = intval($val) - 1;
                                $get_it = $equals && $val == $level || $lt && $val > $level || $gt && $val < $level;
                            }
                        }

                        $is_file = $anot->file;
                    }
                }
            }

            if ($get_it || $embed_it) {
                $fields_to_get = [];
                if ($ftg) {
                    $sub_ftgs = [];
                    if (is_array($to_get)) {
                        if (array_key_exists($key, $to_get)) {
                            $sub_ftgs = array_merge($sub_ftgs, $to_get[$key]);
                        }

                        if (array_key_exists('*', $to_get)) {
                            $sub_ftgs = array_merge($sub_ftgs, $to_get['*']);
                        }
                    }

                    if ($ftg_super_wildcard) {
                        $sub_ftgs = array_merge($sub_ftgs, [['**']]);
                    }

                    foreach ($sub_ftgs as $sub_ftg) {
                        if (!empty($sub_ftg)) {
                            $sub_field = array_shift($sub_ftg);

                            if (!isset($fields_to_get[$sub_field])) {
                                $fields_to_get[$sub_field] = [];
                            }

                            $fields_to_get[$sub_field][] = $sub_ftg;
                        }
                    }
                }

                $fields_to_embed = [];
                if ($fte) {
                    if ($fte_super_wildcard) {
                        $fields_to_embed = true;
                    } else {
                        $sub_ftes = [];
                        if (array_key_exists($key, $to_embed)) {
                            $sub_ftes = $to_embed[$key];
                        } elseif ($fte_wildcard) {
                            $sub_ftes = $to_embed['*'];
                        }

                        foreach ($sub_ftes as $sub_fte) {
                            $sub_field = array_shift($sub_fte);

                            if (!isset($fields_to_embed[$sub_field])) {
                                $fields_to_embed[$sub_field] = [];
                            }

                            $fields_to_embed[$sub_field][] = $sub_fte;
                        }
                    }
                }

                $getter = new \ReflectionMethod($doc, 'get'.ucfirst($key));

                $doc_value = $getter->invoke($doc);

                $value = $this->formatValue($doc_value, $fieldMapping, $level, $fields_to_get, $fields_to_embed, $is_file);

                if ($value !== null) {
                    $document[$key] = $value;
                }
            }
        }

        return $document;
    }

    private function formatValue($value, $fieldMapping, $level, $to_get, $to_embed, $is_file)
    {
        if ($value === null) {
            return;
        } else if ($fieldMapping['type'] == 'one') {
            if (method_exists($value, 'toStdClass')) {
                return $value->toStdClass($this->api, $level + 1, $to_get, $to_embed);
            }

            return $this->toStdClass($value, $level + 1, $to_get, $to_embed);
        } else if ($fieldMapping['type'] == 'many') {
            $prop = array();
            foreach ($value as $v) {
                $prop[] = $this->formatValue($v, ['type' => 'one'], $level, $to_get, $to_embed, $is_file);
            }

            return $prop;
        } else if ($fieldMapping['type'] == 'date') {
            if (!empty($this->api['dataaccess.mongoodm.date_format'])) {
                return $value->format($this->api['dataaccess.mongoodm.date_format']);
            }

            return $value;
        } else if ($is_file) {
            if ($fieldMapping['type'] == 'collection') {
                $ret_val = [];

                if(is_array($value)) {
                    foreach ($value as $file) {
                        if(!empty($file['originalName'])) {
                            $ret_val[] = $file['originalName'];
                        }
                    }
                }

                return $ret_val;
            } else if ($fieldMapping['type'] == 'hash') {
                $ret_val = null;

                $ret_val = $value['originalName'];

                return $ret_val;
            }

            return $value;
        } else {
            return $value;
        }
    }

    public function convertToBlimpDocument($data, &$item, $patch = true, $files = null)
    {
        $dm = $this->api['dataaccess.mongoodm.documentmanager']();

        $cmf = $dm->getMetadataFactory();
        $class = $cmf->getMetadataFor(get_class($item));

        foreach ($class->fieldMappings as $fieldMapping) {
            $key = $fieldMapping['fieldName'];

            if (in_array($key, ['id', 'created', 'createdBy', 'updated', 'updatedBy'])) {
                continue;
            }

            $value = null;

            if (array_key_exists($key, $data)) {
                $value = $data[$key];
            } elseif ($patch) {
                continue;
            }

            if (!in_array($fieldMapping['type'], ['one', 'many']) || $fieldMapping['isOwningSide']) {
                $setter = new \ReflectionMethod($item, 'set'.ucfirst($key));
                $getter = new \ReflectionMethod($item, 'get'.ucfirst($key));
            } else {
                continue;
            }

            $is_file = false;
            $bucket = null;

            $prop = new \ReflectionProperty($item, $key);
            $prop->setAccessible(true);

            $propertyAnnotations = $this->api['dataaccess.doctrine.annotation.reader']->getPropertyAnnotations($prop);

            foreach ($propertyAnnotations as $anot) {
                if ($anot instanceof \Blimp\DataAccess\BlimpAnnotation) {
                    $is_file = $anot->file;
                    $bucket = $anot->bucket;

                    if(empty($bucket)) {
                        $bucket = get_class($item);
                    }

                    break;
                }
            }

            $current_value = null;

            if ($is_file || $value !== null) {
                $current_value = $getter->invoke($item);

                if (in_array($fieldMapping['type'], ['one', 'many'])) {
                    if ($fieldMapping['isOwningSide']) {
                        $c = new \ReflectionClass($fieldMapping['targetDocument']);

                        if ($fieldMapping['type'] == 'one') {
                            $is_ref = false;

                            if (!empty($fieldMapping['embedded']) && $fieldMapping['embedded']) {
                                // it's an embedded value, replace it
                                $current_value = $c->newInstance();
                            } else {
                                // it's a reference
                                $current_id = $current_value != null ? $current_value->getId() : null;
                                $new_id = is_scalar($value) ? $value : (!empty($value['id']) ? $value['id'] : null);

                                if ($fieldMapping['isCascadePersist']) {
                                    // the new/modified value will be persisted

                                    if ($current_id != $new_id) {
                                        // it's a new/different value
                                        $current_value = $c->newInstance();
                                    }
                                } elseif ($new_id !== null) {
                                    // just keep the reference
                                    $is_ref = true;

                                    if (\MongoId::isValid($new_id)) {
                                        $current_value = $dm->getPartialReference($fieldMapping['targetDocument'], new \MongoId($new_id));
                                    } else {
                                        $current_value = $dm->getPartialReference($fieldMapping['targetDocument'], $new_id);
                                    }
                                } else {
                                    // clear the field
                                    $current_value = null;
                                }
                            }

                            if ($current_value !== null && !$is_ref) {
                                $this->convertToBlimpDocument($value, $current_value, $patch);
                            }
                        } else {
                            $current_value = new ArrayCollection();

                            foreach ($value as $v) {
                                $new_value = null;
                                $is_ref = false;

                                if ($fieldMapping['embedded']) {
                                    // it's an embedded value, replace it
                                    $new_value = $c->newInstance();
                                } else {
                                    // it's a reference
                                    $new_id = is_scalar($value) ? $value : (!empty($value['id']) ? $value['id'] : null);

                                    if ($fieldMapping['isCascadePersist']) {
                                        // the value will be persisted
                                        $new_value = $c->newInstance();
                                    } elseif ($new_id !== null) {
                                        // just keep the reference
                                        $is_ref = true;

                                        if (\MongoId::isValid($new_id)) {
                                            $new_value = $dm->getPartialReference($fieldMapping['targetDocument'], new \MongoId($new_id));
                                        } else {
                                            $new_value = $dm->getPartialReference($fieldMapping['targetDocument'], $new_id);
                                        }
                                    }
                                }

                                if ($new_value !== null && !$is_ref) {
                                    $this->convertToBlimpDocument($v, $new_value, $patch);
                                }

                                $current_value->add($new_value);
                            }
                        }
                    }
                } elseif ($fieldMapping['type'] == 'collection') {
                    $current_value = [];

                    if(!empty($value)) {
                        foreach ($value as $v) {
                            $current_value[] = $v;
                        }
                    }

                    if($is_file && !empty($files)) {
                        $uploaded_media = $files->get($key);

                        if (!empty($uploaded_media)) {
                            foreach ($uploaded_media as $uploadedFile) {
                                $file = $this->api['media.store']($uploadedFile, $bucket);
                                $current_value[] = $file;
                            }
                        }
                    }
                } elseif ($fieldMapping['type'] == 'date') {
                    if (!empty($this->api['dataaccess.mongoodm.date_format'])) {
                        $current_value = \DateTime::createFromFormat($this->api['dataaccess.mongoodm.date_format'], $value);
                        if ($current_value === false) {
                            throw new BlimpHttpException(Response::HTTP_BAD_REQUEST, $value.' is not a valid datetime ('.$this->api['dataaccess.mongoodm.date_format'].')');
                        }
                    }
                } elseif ($fieldMapping['type'] == 'hash') {
                    $current_value = $value;

                    if($is_file && !empty($files)) {
                        $uploadedFile = $files->get($key);

                        if (!empty($uploadedFile)) {
                            $file = $this->api['media.store']($uploadedFile, $bucket);
                            $current_value = $file;
                        }
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
