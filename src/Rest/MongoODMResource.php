<?php
namespace Blimp\DataAccess\Rest;

use Blimp\Base\BlimpException;
use Pimple\Container;
use Symfony\Component\HttpFoundation\Request;
use Symfony\Component\HttpFoundation\Response;

class MongoODMResource {
    public function process(Container $api, Request $request, $id, $_securityDomain = null, $_resourceClass = null, $parent_id = null, $_parentIdField = null, $_parentResourceClass = null) {
        if ($_resourceClass == null) {
            throw new BlimpException(Response::HTTP_INTERNAL_SERVER_ERROR, 'Resource class not specified');
        }

        $token = $api['security']->getToken();

        $dm = $api['dataaccess.mongoodm.documentmanager']();

        switch ($request->getMethod()) {
            case 'GET':
                $can_doit = $api['security.permitions.check']($_securityDomain, 'get');
                $can_doit_self = $api['security.permitions.check']($_securityDomain, 'self_get');

                if(!$can_doit && !$can_doit_self) {
                    $api['security.permission.denied']($_securityDomain.':get,self_get');
                }

                if(!$can_doit) {
                    $user = $token !== null ? $token->getUser() : null;

                    if($user == null || !($can_doit = is_a($user, $_resourceClass, false)) || $id != $user->getId()) {
                        $api['security.permission.denied']($_securityDomain.':get');
                    }
                }

                $query_builder = $dm->createQueryBuilder();
                $query_builder->eagerCursor(true);
                $query_builder->find($_resourceClass);

                $query_builder->field('_id')->equals($id);

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

                $item = $query->getSingleResult();

                if ($item == null) {
                    throw new BlimpException(Response::HTTP_NOT_FOUND, "Not found");
                }

                if(!$can_doit) {
                    $owner = null;

                    if(method_exists($item, 'getOwner')) {
                        $owner = $item->getOwner();
                    }

                    $user = $token->getUser();

                    if($owner == null || !is_a($owner, get_class($user), false) || $user->getId() != $owner->getId()) {
                        $api['security.permission.denied']($_securityDomain.':get');
                    }
                }

                $result = $api['dataaccess.mongoodm.utils']->toStdClass($item);

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

                    if($user == null || !($can_doit = is_a($user, $_resourceClass, false)) || $id != $user->getId()) {
                        $api['security.permission.denied']($_securityDomain.':edit');
                    }
                }

                $data = $request->attributes->get('data');

                $query_builder = $dm->createQueryBuilder();
                $query_builder->eagerCursor(true);
                $query_builder->find($_resourceClass);

                $query_builder->field('_id')->equals($id);

                $query = $query_builder->getQuery();

                $item = $query->getSingleResult();

                if ($item == null) {
                    throw new BlimpException(Response::HTTP_NOT_FOUND, "Not found");
                }

                if(!$can_doit) {
                    $owner = null;

                    if(method_exists($item, 'getOwner')) {
                        $owner = $item->getOwner();
                    }

                    $user = $token->getUser();

                    if($owner == null || !is_a($owner, get_class($user), false) || $user->getId() != $owner->getId()) {
                        $api['security.permission.denied']($_securityDomain.':edit');
                    }
                }

                $api['dataaccess.mongoodm.utils']->convertToBlimpDocument($data, $item, $request->getMethod() == 'PATCH');

                $dm->persist($item);
                $dm->flush($item);

                $result = $api['dataaccess.mongoodm.utils']->toStdClass($item);

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

                    if($user == null || !($can_doit = is_a($user, $_resourceClass, false)) || $id != $user->getId()) {
                        $api['security.permission.denied']($_securityDomain.':delete');
                    }
                }

                $data = $request->attributes->get('data');

                $query_builder = $dm->createQueryBuilder();
                $query_builder->eagerCursor(false);
                $query_builder->find($_resourceClass);

                $query_builder->field('_id')->equals($id);

                $query = $query_builder->getQuery();

                $item = $query->getSingleResult();

                if ($item == null) {
                    throw new BlimpException(Response::HTTP_NOT_FOUND, "Not found");
                }

                if(!$can_doit) {
                    $owner = null;

                    if(method_exists($item, 'getOwner')) {
                        $owner = $item->getOwner();
                    }

                    $user = $token->getUser();

                    if($owner == null || !is_a($owner, get_class($user), false) || $user->getId() != $owner->getId()) {
                        $api['security.permission.denied']($_securityDomain.':delete');
                    }
                }

                $dm->remove($item);
                $dm->flush($item);

                $result = $api['dataaccess.mongoodm.utils']->toStdClass($item);

                return $result;

                break;

            default:
                throw new BlimpException(Response::HTTP_METHOD_NOT_ALLOWED, "Method not allowed");
        }
    }
}
