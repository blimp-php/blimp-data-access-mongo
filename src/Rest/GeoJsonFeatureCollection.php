<?php
namespace Blimp\DataAccess\Rest;

use Blimp\Http\BlimpHttpException;
use Pimple\Container;
use Symfony\Component\HttpFoundation\JsonResponse;
use Symfony\Component\HttpFoundation\Request;
use Symfony\Component\HttpFoundation\Response;

class GeoJsonFeatureCollection {
    public function process(Container $api, Request $request, $_securityDomain = null, $_resourceClass = null, $_geometryField = null, $parent_id = null, $_parentIdField = null, $_parentResourceClass = null) {
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

                $result = $api['dataaccess.mongoodm.utils']->search($_resourceClass, $request->query, $contentLang, $_securityDomain, $user, $_parentResourceClass, $_parentIdField, $parent_id);

                $features = [];
                foreach ($result['elements'] as $value) {
                    if(!empty($value[$_geometryField])) {
                        $id = $value['id'];
                        unset($value['id']);

                        $geometry = $value[$_geometryField];
                        unset($value[$_geometryField]);

                        $feature = [
                            "type" => "Feature",
                            'id' => $id,
                            'geometry' => $geometry,
                            'properties' => $value
                        ];

                        $features[] = $feature;
                    }
                }

                return ["type" => "FeatureCollection", "features" => $features];

                break;

            default:
                throw new BlimpHttpException(Response::HTTP_METHOD_NOT_ALLOWED, "Method not allowed");
        }
    }
}
