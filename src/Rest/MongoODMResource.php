<?php
namespace Blimp\DataAccess\Rest;

use Blimp\Http\BlimpHttpException;
use Pimple\Container;
use Symfony\Component\HttpFoundation\Request;
use Symfony\Component\HttpFoundation\Response;

class MongoODMResource {
    public function process(Container $api, Request $request, $id, $_securityDomain = null, $_resourceClass = null, $parent_id = null, $_parentIdField = null, $_parentResourceClass = null) {
        if ($_resourceClass == null) {
            throw new BlimpHttpException(Response::HTTP_INTERNAL_SERVER_ERROR, 'Resource class not specified');
        }

        $token = null;
        if ($api->offsetExists('security')) {
            $token = $api['security']->getToken();
        }
        $user = $token !== null ? $token->getUser() : null;

        switch ($request->getMethod()) {
            case 'GET':
                $contentLang = $api['http.utils']->guessContentLang($request->query->get('locale'), $request->getLanguages());

                $result = $api['dataaccess.mongoodm.utils']->get($_resourceClass, $id, $contentLang, $_securityDomain, $user, $_parentResourceClass, $_parentIdField, $parent_id);

                return $result->toStdClass($api);

                break;

            case 'PUT':
            case 'PATCH':
                $contentLang = $request->headers->get('Content-Language');

                if(empty($contentLang)) {
                    // TODO get it from somewhere
                    $contentLang = 'pt-PT';
                }

                // TODO get it from somewhere
                if(!in_array($contentLang, ['pt-PT', 'en-US', 'en'])) {
                    throw new BlimpHttpException(Response::HTTP_BAD_REQUEST, 'Content language not supported', ["requested" => $contentLang, "available" => ['pt-PT', 'en-US', 'en']]);
                    break;
                }

                $result = $api['dataaccess.mongoodm.utils']->edit($request->getMethod() == 'PATCH', $request->attributes->get('data'), $_resourceClass, $id, $contentLang, $user, $_parentResourceClass, $_parentIdField, $parent_id);

                return $result->toStdClass($api);

                break;

            case 'DELETE':
                $result = $api['dataaccess.mongoodm.utils']->delete($_resourceClass, $id, $user, $_parentResourceClass, $_parentIdField, $parent_id);

                return $result->toStdClass($api);

                break;

            default:
                throw new BlimpHttpException(Response::HTTP_METHOD_NOT_ALLOWED, "Method not allowed");
        }
    }
}
