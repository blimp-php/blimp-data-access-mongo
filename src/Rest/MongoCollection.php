<?php
namespace Blimp\DataAccess\Rest;

use Blimp\Http\BlimpHttpException;
use Pimple\Container;
use Symfony\Component\HttpFoundation\JsonResponse;
use Symfony\Component\HttpFoundation\Request;
use Symfony\Component\HttpFoundation\Response;
use Doctrine\MongoDB\Query\Builder;

class MongoCollection {
    public function process(Container $api, Request $request, $class, $_securityDomain = null, $_resourceClass = null, $_idField = null, $_idLowercase = true, $parent_id = null, $_parentIdField = null, $_parentResourceClass = null) {
        if ($_resourceClass == null) {
            $_resourceClass = $class;
        }

        $token = null;
        if ($api->offsetExists('security')) {
            $token = $api['security']->getToken();
        }

        $collection = $api['dataaccess.mongoodm.connection']()->selectCollection($api['config']['mongoodm']['default_database'], $_resourceClass);

        switch ($request->getMethod()) {
            case 'GET':
                $can_doit = $api['security.permitions.check']($_securityDomain, 'list');
                $can_doit_self = $api['security.permitions.check']($_securityDomain, 'self_list');

                if(!$can_doit && !$can_doit_self) {
                    $api['security.permission.denied']($_securityDomain.':list,self_list');
                }

                $limit_to_owner = null;

                if(!$can_doit) {
                    $user = $token !== null ? $token->getUsername() : null;

                    if($user == null) {
                        $api['security.permission.denied']($_securityDomain.':list');
                    }

                    $limit_to_owner = $user;
                }

                $query_builder = new Builder($collection);
                $api['dataaccess.mongoodm.utils']->parseRequestToQuery($request, $query_builder);

                if($limit_to_owner != null) {
                    $query_builder->field('owner')->equals($limit_to_owner);
                }

                if($parent_id != null) {
                    if ($_parentIdField == null) {
                        throw new BlimpHttpException(Response::HTTP_INTERNAL_SERVER_ERROR, 'Parent id field not specified');
                    }

                    $query_builder->field($_parentIdField)->equals($parent_id);
                }

                $query = $query_builder->getQuery();

                $cursor = $query->execute();

                $count = $cursor->count();

                if ($count == 0) {
                    throw new BlimpHttpException(Response::HTTP_NO_CONTENT, "No content");
                }

                $elements = array();

                foreach ($cursor as $item) {
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

                    $elements[] = $item;
                }

                // TODO Links next and prev, both in $result->links and 'Links' header

                $result = new \stdclass();
                $result->elements = $elements;
                $result->count = $count;

                return $result;

                break;

            case 'POST':
                $can_create = $api['security.permitions.check']($_securityDomain, 'create');

                if(!$can_create && !$can_create_self) {
                    $api['security.permission.denied']($_securityDomain.':create,self_create');
                }

                $data = $request->attributes->get('data');

                unset($data['id']);
                unset($data['_id']);
                unset($data['owner']);
                unset($data['created']);
                unset($data['updated']);

                $user = $token !== null ? $token->getUsername() : null;

                if($user != null) {
                    $data['owner'] = $user;
                }

                $data['created'] = $data['updated'] = new \MongoDate();

                $collection->insert($data);

                $resource_uri = $request->getPathInfo() . '/' . $data['_id'];

                $response = new JsonResponse((object) ["uri" => $resource_uri], Response::HTTP_CREATED);
                $response->headers->set('Location', $resource_uri);

                return $response;

                break;

            default:
                throw new BlimpHttpException(Response::HTTP_METHOD_NOT_ALLOWED, "Method not allowed");
        }
    }
}
