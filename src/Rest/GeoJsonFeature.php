<?php
namespace Blimp\DataAccess\Rest;

use Blimp\Http\BlimpHttpException;
use Pimple\Container;
use Symfony\Component\HttpFoundation\Request;
use Symfony\Component\HttpFoundation\Response;

class GeoJsonFeature {
    public function process(Container $api, Request $request, $id, $_securityDomain = null, $_resourceClass = null, $_geometryField = null, $parent_id = null, $_parentIdField = null, $_parentResourceClass = null) {
        if ($_resourceClass == null) {
            throw new BlimpHttpException(Response::HTTP_INTERNAL_SERVER_ERROR, 'Resource class not specified');
        }

        if ($_geometryField == null) {
            $_geometryField = 'location';
        }

        $token = null;
        if ($api->offsetExists('security')) {
            $token = $api['security']->getToken();
        }
        $user = $token !== null ? $token->getUser() : null;

        switch ($request->getMethod()) {
            case 'GET':
                $contentLang = $api['http.utils']->guessContentLang($request->query->get('locale'), $request->getLanguages());

                $result = $api['dataaccess.mongoodm.utils']->get($_resourceClass, $id, $request->query, $contentLang, $_securityDomain, $user, $_parentResourceClass, $_parentIdField, $parent_id);

                $id = $result['id'];
                unset($result['id']);

                $geometry = $result[$_geometryField];
                unset($result[$_geometryField]);

                return [
                    "type" => "Feature",
                    'id' => $id,
                    'geometry' => $geometry,
                    'properties' => $result
                ];

                break;

            default:
                throw new BlimpHttpException(Response::HTTP_METHOD_NOT_ALLOWED, "Method not allowed");
        }
    }
}
