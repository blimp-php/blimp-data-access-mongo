<?php
namespace Blimp\DataAccess\Rest;

use Blimp\Http\BlimpHttpException;
use Pimple\Container;
use Symfony\Component\HttpFoundation\JsonResponse;
use Symfony\Component\HttpFoundation\Request;
use Symfony\Component\HttpFoundation\Response;

class MongoODMCollection {
    public function process(Container $api, Request $request, $_securityDomain = null, $_resourceClass = null, $_idField = null, $_idLowercase = true, $parent_id = null, $_parentIdField = null, $_parentResourceClass = null) {
        if ($_resourceClass == null) {
            throw new BlimpHttpException(Response::HTTP_INTERNAL_SERVER_ERROR, 'Resource class not specified');
        }

        if ($_idField == null) {
            $_idField = 'id';
        }

        $token = null;
        if ($api->offsetExists('security')) {
            $token = $api['security']->getToken();
        }
        $user = $token !== null ? $token->getUser() : null;

        $dm = $api['dataaccess.mongoodm.documentmanager']();

        switch ($request->getMethod()) {
            case 'GET':
                $contentLang = $api['http.utils']->guessContentLang($request->query->get('locale'), $request->getLanguages());

                $result = $api['dataaccess.mongoodm.utils']->search($_resourceClass, $request->query, $contentLang, $_securityDomain, $user, $_parentResourceClass, $_parentIdField, $parent_id);

                // TODO Links next and prev, both in $result->links and 'Links' header

                return $api['dataaccess.mongoodm.utils']->toStdClass($result);

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

                if (!empty($anot->strategy) && !empty($anot->options) && !empty($anot->options['class']) && strtoupper($anot->strategy) === 'CUSTOM' && $anot->options['class'] === '\Blimp\DataAccess\BlimpIdProvider') {
                    $id = $data[$_idField];
                    if ($_idLowercase) {
                        $id = strtolower($id);
                    }

                    if (empty($id)) {
                        throw new BlimpHttpException(Response::HTTP_INTERNAL_SERVER_ERROR, "Undefined Id", "Id strategy delegated to BlimpIdProvider and no Id provided");
                    } else {
                        // TODO catch exception instead of preventive check
                        $check = $dm->find($_resourceClass, $id);

                        if ($check != null) {
                            throw new BlimpHttpException(Response::HTTP_CONFLICT, "Duplicate Id", "Id strategy delegated to BlimpIdProvider and provided Id already exists");
                        }
                    }

                    $item->setId($id);
                }

                if(method_exists($item, 'setTranslatableLocale')) {
                    $contentLang = $request->headers->get('Content-Language');

                    if(empty($contentLang)) {
                        // TODO get it from somewhere
                        $contentLang = 'pt-PT';
                    }

                    // TODO Reject if unsupported language

                    $item->setTranslatableLocale($contentLang);
                }

                $dm->persist($item);
                $dm->flush($item);

                $resource_uri = $request->getPathInfo() . '/' . $item->getId();

                $response = new JsonResponse((object) ["uri" => $resource_uri], Response::HTTP_CREATED);
                $response->headers->set('Location', $resource_uri);

                return $response;

                break;

            default:
                throw new BlimpHttpException(Response::HTTP_METHOD_NOT_ALLOWED, "Method not allowed");
        }
    }
}
