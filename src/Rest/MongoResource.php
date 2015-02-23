<?php
namespace Blimp\DataAccess\Rest;

use Blimp\Http\BlimpHttpException;
use Pimple\Container;
use Symfony\Component\HttpFoundation\Request;
use Symfony\Component\HttpFoundation\Response;
use Doctrine\MongoDB\Query\Builder;

class MongoResource {
    public function process(Container $api, Request $request, $class, $id, $_securityDomain = null, $_resourceClass = null, $parent_id = null, $_parentIdField = null, $_parentResourceClass = null) {
        if ($_resourceClass == null) {
            $_resourceClass = $class;
        }

        $token = $api['security']->getToken();

        $collection = $api['dataaccess.mongoodm.connection']()->selectCollection($api['config']['mongoodm']['default_database'], $_resourceClass);

        if(\MongoId::isvalid($id)) {
            $m_id = new \MongoId($id);
        } else {
            $m_id = $id;
        }

        switch ($request->getMethod()) {
            case 'GET':
                $can_doit = $api['security.permitions.check']($_securityDomain, 'get');
                $can_doit_self = $api['security.permitions.check']($_securityDomain, 'self_get');

                if(!$can_doit && !$can_doit_self) {
                    $api['security.permission.denied']($_securityDomain.':get,self_get');
                }

                if(!$can_doit) {
                    $user = $token !== null ? $token->getUsername() : null;

                    if($user == null) {
                        $api['security.permission.denied']($_securityDomain.':get');
                    }
                }

                $query_builder = new Builder($collection);
                $query_builder->field('_id')->equals($m_id);

                if($parent_id != null) {
                    if ($_parentIdField == null) {
                        throw new BlimpHttpException(Response::HTTP_INTERNAL_SERVER_ERROR, 'Parent id field not specified');
                    }

                    $query_builder->field($_parentIdField)->equals($parent_id);
                }

                $query = $query_builder->getQuery();

                $item = $query->getSingleResult();

                if ($item == null) {
                    throw new BlimpHttpException(Response::HTTP_NOT_FOUND, "Not found");
                }

                if(!$can_doit) {
                    $owner = null;

                    if(array_key_exists('owner', $item)) {
                        $owner = $item['owner'];
                    }

                    $user = $token->getUsername();

                    if($owner == null || $user != $owner) {
                        $api['security.permission.denied']($_securityDomain.':get');
                    }
                }

                $item['id'] = $item['_id']->{'$id'};
                unset($item['_id']);

                if(array_key_exists('created', $item)) {
                    $value = $item['created'];
                    if($value instanceof \MongoDate) {
                        $item['created'] = new \DateTime('@' . $value->sec);
                    }
                }

                if(array_key_exists('updated', $item)) {
                    $value = $item['updated'];
                    if($value instanceof \MongoDate) {
                        $item['updated'] = new \DateTime('@' . $value->sec);
                    }
                }

                $result = $item;

                return $result;

                break;

            case 'PUT':
            case 'PATCH':
                $can_doit = $api['security.permitions.check']($_securityDomain, 'edit');
                $can_doit_self = $api['security.permitions.check']($_securityDomain, 'self_edit');

                if(!$can_doit && !$can_doit_self) {
                    $api['security.permission.denied']($_securityDomain.':edit,self_edit');
                }

                if(!$can_doit) {
                    $user = $token !== null ? $token->getUser() : null;

                    if($user == null) {
                        $api['security.permission.denied']($_securityDomain.':edit');
                    }
                }

                $data = $request->attributes->get('data');

                $query_builder = new Builder($collection);
                $query_builder->field('_id')->equals($m_id);

                $query = $query_builder->getQuery();

                $item = $query->getSingleResult();

                if ($item == null) {
                    throw new BlimpHttpException(Response::HTTP_NOT_FOUND, "Not found");
                }

                if(!$can_doit) {
                    $owner = null;

                    if(array_key_exists('owner', $item)) {
                        $owner = $item['owner'];
                    }

                    $user = $token->getUsername();

                    if($owner == null || $user != $owner) {
                        $api['security.permission.denied']($_securityDomain.':edit');
                    }
                }

                unset($data['id']);
                unset($data['_id']);
                unset($data['owner']);
                unset($data['created']);
                unset($data['updated']);

                $data['owner'] = $item['owner'];
                $data['created'] = $item['created'];

                $data['updated'] = new \MongoDate();

                $collection->update(['_id' => $m_id], $data);

                $data['id'] = $id;
                unset($data['_id']);

                if(array_key_exists('created', $data)) {
                    $value = $data['created'];
                    if($value instanceof \MongoDate) {
                        $data['created'] = new \DateTime('@' . $value->sec);
                    }
                }

                if(array_key_exists('updated', $data)) {
                    $value = $data['updated'];
                    if($value instanceof \MongoDate) {
                        $data['updated'] = new \DateTime('@' . $value->sec);
                    }
                }

                $result = $data;

                return $result;

                break;

            case 'DELETE':
                $can_doit = $api['security.permitions.check']($_securityDomain, 'delete');
                $can_doit_self = $api['security.permitions.check']($_securityDomain, 'self_delete');

                if(!$can_doit && !$can_doit_self) {
                    $api['security.permission.denied']($_securityDomain.':delete,self_delete');
                }

                if(!$can_doit) {
                    $user = $token !== null ? $token->getUser() : null;

                    if($user == null) {
                        $api['security.permission.denied']($_securityDomain.':delete');
                    }
                }

                $query_builder = new Builder($collection);
                $query_builder->field('_id')->equals($m_id);

                $query = $query_builder->getQuery();

                $item = $query->getSingleResult();

                if ($item == null) {
                    throw new BlimpHttpException(Response::HTTP_NOT_FOUND, "Not found");
                }

                if(!$can_doit) {
                    $owner = null;

                    if(array_key_exists('owner', $item)) {
                        $owner = $item['owner'];
                    }

                    $user = $token->getUsername();

                    if($owner == null || $user != $owner) {
                        $api['security.permission.denied']($_securityDomain.':delete');
                    }
                }

                $collection->remove(['_id' => $m_id]);

                unset($item['id']);
                unset($item['_id']);

                if(array_key_exists('created', $item)) {
                    $value = $item['created'];
                    if($value instanceof \MongoDate) {
                        $item['created'] = new \DateTime('@' . $value->sec);
                    }
                }

                if(array_key_exists('updated', $item)) {
                    $value = $item['updated'];
                    if($value instanceof \MongoDate) {
                        $item['updated'] = new \DateTime('@' . $value->sec);
                    }
                }

                $item['deleted'] = new \DateTime();

                $result = $item;

                return $result;

                break;

            default:
                throw new BlimpHttpException(Response::HTTP_METHOD_NOT_ALLOWED, "Method not allowed");
        }
    }
}
