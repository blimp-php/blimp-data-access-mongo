<?php
namespace Blimp\DataAccess;

use Doctrine\Common\Cache\MemcacheCache;
use Doctrine\Common\Annotations\AnnotationReader;
use Doctrine\Common\Annotations\FileCacheReader;
use Doctrine\Common\Cache\ArrayCache;
use Doctrine\Common\EventManager;
use Gedmo\DoctrineExtensions;
use Gedmo\IpTraceable\IpTraceableListener;
use Gedmo\Timestampable\TimestampableListener;
use Pimple\Container;
use Pimple\ServiceProviderInterface;

class DoctrineServiceProvider implements ServiceProviderInterface {
    public function register(Container $api) {
        $api['dataaccess.doctrine.annotation.cache'] = __DIR__;

        $api['dataaccess.doctrine.annotation.reader'] = function ($api) {
            $annotationReader = new AnnotationReader();

            return new FileCacheReader(
                $annotationReader,
                $api['dataaccess.doctrine.annotation.cache'],
                $api['blimp.debug']
            );
        };

        $api['dataaccess.doctrine.event_manager'] = function ($api) {
            $annotation_reader = $api['dataaccess.doctrine.annotation.reader'];

            $evm = new EventManager();

            $timestampableListener = new TimestampableListener();
            $timestampableListener->setAnnotationReader($annotation_reader);
            $evm->addEventSubscriber($timestampableListener);

            $ipTraceableListener = new IpTraceableListener();
            $ipTraceableListener->setAnnotationReader($annotation_reader);
            $evm->addEventSubscriber($ipTraceableListener);

            return $evm;
        };

        $api['dataaccess.doctrine.cache.memcache.factory'] = $api->protect(function ($params) use ($api) {
            $memcacheHost = !empty($params['host']) ? $params['host'] : '%' . $api->getObjectManagerElementName('cache.memcache_host') . '%';
            $memcachePort = !empty($params['port']) || (isset($params['port']) && $params['port'] === 0) ? $params['port'] : '%' . $api->getObjectManagerElementName('cache.memcache_port') . '%';

            $cache = new MemcacheCache();

            $memcache = new \Memcache();
            $memcache->connect($memcacheHost, $memcachePort);

            $cache->setMemcache($memcache);

            $cache->setNamespace($params['namespace']);

            return $cache;
        });

        $api['dataaccess.doctrine.cache.memcache.factory'] = $api->protect(function ($params) use ($api) {
            $memcachedHost = !empty($params['host']) ? $params['host'] : '%' . $api->getObjectManagerElementName('cache.memcached_host') . '%';
            $memcachedPort = !empty($params['port']) ? $params['port'] : '%' . $api->getObjectManagerElementName('cache.memcached_port') . '%';

            $cache = new MemcacheCache();

            $memcached = new \Memcached();
            $memcached->addServer($memcachedHost, $memcachedPort);

            $cache->setMemcached($memcached);

            $cache->setNamespace($params['namespace']);

            return $cache;
        });

        $api['dataaccess.doctrine.cache.redis.factory'] = $api->protect(function ($params) use ($api) {
            $redisHost = !empty($params['host']) ? $params['host'] : '%' . $api->getObjectManagerElementName('cache.redis_host') . '%';
            $redisPort = !empty($params['port']) ? $params['port'] : '%' . $api->getObjectManagerElementName('cache.redis_port') . '%';

            $cache = new RedisCache();

            $redis = new \Redis();
            $redis->connect($redisHost, $redisPort);

            $cache->setRedis($redis);

            $cache->setNamespace($params['namespace']);

            return $cache;
        });

        $api['dataaccess.doctrine.cache.apc.factory'] = $api->protect(function ($params) use ($api) {
            $cache = new ApcCache();

            $cache->setNamespace($params['namespace']);

            return $cache;
        });

        $api['dataaccess.doctrine.cache.array.factory'] = $api->protect(function ($params) use ($api) {
            $cache = new ArrayCache();

            $cache->setNamespace($params['namespace']);

            return $cache;
        });

        $api['dataaccess.doctrine.cache.xcache.factory'] = $api->protect(function ($params) use ($api) {
            $cache = new XcacheCache();

            $cache->setNamespace($params['namespace']);

            return $cache;
        });

        $api['dataaccess.doctrine.cache.wincache.factory'] = $api->protect(function ($params) use ($api) {
            $cache = new WinCacheCache();

            $cache->setNamespace($params['namespace']);

            return $cache;
        });

        $api['dataaccess.doctrine.cache.zenddata.factory'] = $api->protect(function ($params) use ($api) {
            $cache = new ZendDataCache();

            $cache->setNamespace($params['namespace']);

            return $cache;
        });

        DoctrineExtensions::registerAnnotations();
    }
}