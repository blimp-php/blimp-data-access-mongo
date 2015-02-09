<?php
namespace Blimp\DataAccess\Rest;

use Blimp\Base\BlimpException;
use Pimple\Container;
use Symfony\Component\HttpFoundation\JsonResponse;
use Symfony\Component\HttpFoundation\Request;
use Symfony\Component\HttpFoundation\Response;

class MongoODMCollection {
    public function process(Container $api, Request $request, $_securityDomain = null, $_resourceClass = null, $_idField = null, $_idLowercase = true, $parent_id = null, $_parentIdField = null, $_parentResourceClass = null) {
        if ($_resourceClass == null) {
            throw new BlimpException(Response::HTTP_INTERNAL_SERVER_ERROR, 'Resource class not specified');
        }

        $token = null;
        if ($api->offsetExists('security')) {
            $token = $api['security']->getToken();
        }

        $dm = $api['dataaccess.mongoodm.documentmanager']();

        switch ($request->getMethod()) {
            case 'GET':
                $can_doit = $api['security.permitions.check']($_securityDomain, 'list');
                $can_doit_self = $api['security.permitions.check']($_securityDomain, 'self_list');

                if(!$can_doit && !$can_doit_self) {
                    $api['security.permission.denied']($_securityDomain.':list,self_list');
                }

                $limit_to_id = null;
                $limit_to_owner = null;

                if(!$can_doit) {
                    $user = $token !== null ? $token->getUser() : null;

                    if($user == null) {
                        $api['security.permission.denied']($_securityDomain.':list');
                    }

                    if(is_a($user, $_resourceClass, false)) {
                        $limit_to_id = $user->getId();
                    } else if(method_exists($_resourceClass, 'getOwner')) {
                        $limit_to_owner = $user;
                    } else {
                        $api['security.permission.denied']($_securityDomain.':list');
                    }
                }

                $query_builder = $dm->createQueryBuilder();
                $api['dataaccess.mongoodm.utils']->parseRequestToQuery($request, $query_builder);

                $query_builder->find($_resourceClass);

                if($limit_to_id != null) {
                    $query_builder->field('_id')->equals($limit_to_id);
                } else if($limit_to_owner != null) {
                    $query_builder->field('owner')->references($limit_to_owner);
                }

                if($parent_id != null) {
                    if ($_parentResourceClass == null) {
                        throw new BlimpException(Response::HTTP_INTERNAL_SERVER_ERROR, 'Parent resource class not specified');
                    }

                    if ($_parentIdField == null) {
                        throw new BlimpException(Response::HTTP_INTERNAL_SERVER_ERROR, 'Parent id field not specified');
                    }

                    $ref = $dm->getPartialReference($_parentResourceClass, $parent_id);

                    $query_builder->field($_parentIdField)->references($ref);
                }

                $query = $query_builder->getQuery();

                $cursor = $query->execute();

                $count = $cursor->count();

                if ($count == 0) {
                    throw new BlimpException(Response::HTTP_NO_CONTENT, "No content");
                }

                $elements = array();

                foreach ($cursor as $item) {
                    $elements[] = $api['dataaccess.mongoodm.utils']->toStdClass($item);
                }

                // TODO Links next and prev, both in $result->links and 'Links' header

                $result = new \stdclass();
                $result->elements = $elements;
                $result->count = $count;

                return $result;

                break;

            case 'POST':
                $can_create = $api['security.permitions.check']($_securityDomain, 'create');
                $can_create_self = $api['security.permitions.check']($_securityDomain, 'self_create');

                if(!$can_create && !$can_create_self) {
                    $api['security.permission.denied']($_securityDomain.':create,self_create');
                }

                $data = $request->attributes->get('data');

                $c = new \ReflectionClass($_resourceClass);
                $item = $c->newInstance();
                $api['dataaccess.mongoodm.utils']->convertToBlimpDocument($data, $item);

                $idProperty = new \ReflectionProperty($_resourceClass, 'id');
                $anot = $api['dataaccess.doctrine.annotation.reader']->getPropertyAnnotation($idProperty, '\Doctrine\ODM\MongoDB\Mapping\Annotations\Id');

                if (empty($anot->strategy) || strtoupper($anot->strategy) == 'NONE') {
                    $id = $item->getId();

                    if (empty($id) && !empty($_idField)) {
                        $id = $data[$_idField];
                        if ($_idLowercase) {
                            $id = strtolower($id);
                        }

                        $item->setId($id);
                    }

                    if (empty($id)) {
                        throw new BlimpException(Response::HTTP_INTERNAL_SERVER_ERROR, "Undefined Id", "Id strategy set to NONE and no Id provided");
                    } else {
                        $check = $dm->find($_resourceClass, $id);

                        if ($check != null) {
                            throw new BlimpException(Response::HTTP_CONFLICT, "Duplicate Id", "Id strategy set to NONE and provided Id already exists");
                        }
                    }
                }

                $has_owner = method_exists($_resourceClass, 'setOwner');
                if($has_owner) {
                    $user = $token !== null ? $token->getUser() : null;

                    if($user != null) {
                        if(!$can_create || $item->getOwner() == null) {
                            $item->setOwner($token->getUser());
                        }
                    }
                }

                $dm->persist($item);
                $dm->flush($item);

                $resource_uri = $request->getPathInfo() . '/' . $item->getId();

                $response = new JsonResponse((object) ["uri" => $resource_uri], Response::HTTP_CREATED);
                $response->headers->set('Location', $resource_uri);

                return $response;

                break;

            default:
                throw new BlimpException(Response::HTTP_METHOD_NOT_ALLOWED, "Method not allowed");
        }
    }
}
