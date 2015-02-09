<?php
namespace Blimp\DataAccess;

use Pimple\ServiceProviderInterface;
use Doctrine\Common\Persistence\Mapping\Driver\MappingDriverChain;
use Doctrine\MongoDB\Connection;
use Doctrine\ODM\MongoDB\Configuration;
use Doctrine\ODM\MongoDB\DocumentManager;
use Doctrine\ODM\MongoDB\Mapping\Driver\AnnotationDriver;
use Pimple\Container;
use Symfony\Component\Config\Definition\Builder\TreeBuilder;

class MongoODMServiceProvider implements ServiceProviderInterface {
    public function register(Container $api) {
        $api['dataaccess.mongoodm.utils'] = function($api) {
            return new MongoODMUtils($api);
        };

        $api['dataaccess.mongoodm.cache'] = __DIR__;

        $api['dataaccess.mongoodm.connection'] = $api->protect(function ($name = null) use ($api) {
            if ($name == null) {
                $name = $api['config']['mongoodm']['default_connection'];
            }

            $conn = null;
            try {
                $conn = $api['dataaccess.mongoodm.connection.' . $name];
            } catch (\InvalidArgumentException $e) {
                $connection = $api['config']['mongoodm']['connections'][$name];

                $conn = new Connection(isset($connection['server']) ? $connection['server'] : null, isset($connection['options']) ? $connection['options'] : array(), null, $api['dataaccess.doctrine.event_manager']);

                $api['dataaccess.mongoodm.connection.' . $name] = $conn;
            }

            return $conn;
        });

        $api['dataaccess.mongoodm.documentmanager'] = $api->protect(function ($name = null) use ($api) {
            if ($name == null) {
                $name = $api['config']['mongoodm']['default_document_manager'];
            }

            $dm = null;
            try {
                $dm = $api['dataaccess.mongoodm.documentmanager.' . $name];
            } catch (\InvalidArgumentException $e) {
                $documentManager = $api['config']['mongoodm']['document_managers'][$name];

                $connectionName = isset($documentManager['connection']) ? $documentManager['connection'] : $documentManager['name'];
                $defaultDatabase = isset($documentManager['database']) ? $documentManager['database'] : $api['config']['mongoodm']['default_database'];

                $mappingDriver = $api['dataaccess.mongoodm.mappingdriver']($name);
                $cacheDriver = $api['dataaccess.mongoodm.cachedriver']($name);

                $dm_config = new Configuration();

                $dm_config->setProxyDir($api['dataaccess.mongoodm.cache'] . $api['config']['mongoodm']['proxy_dir']);
                $dm_config->setProxyNamespace($api['config']['mongoodm']['proxy_namespace']);
                $dm_config->setAutoGenerateProxyClasses($api['config']['mongoodm']['auto_generate_proxy_classes']);

                $dm_config->setHydratorDir($api['dataaccess.mongoodm.cache'] . $api['config']['mongoodm']['hydrator_dir']);
                $dm_config->setHydratorNamespace($api['config']['mongoodm']['hydrator_namespace']);
                $dm_config->setAutoGenerateHydratorClasses($api['config']['mongoodm']['auto_generate_hydrator_classes']);

                $dm_config->setDefaultCommitOptions($api['config']['mongoodm']['default_commit_options']);

                $dm_config->setRetryConnect($documentManager['retry_connect']);
                $dm_config->setRetryQuery($documentManager['retry_query']);

                $dm_config->setDefaultDB($defaultDatabase);

                $dm_config->setMetadataDriverImpl($mappingDriver);
                $dm_config->setMetadataCacheImpl($cacheDriver);

                $enabledFilters = array();
                foreach ($documentManager['filters'] as $name => $filter) {
                    $parameters = isset($filter['parameters']) ? $filter['parameters'] : array();
                    $dm_config->addFilter($name, $filter['class'], $parameters);
                    if ($filter['enabled']) {
                        $enabledFilters[] = $name;
                    }
                }

                $connection = $api['dataaccess.mongoodm.connection']($connectionName);
                $evm = $api['dataaccess.doctrine.event_manager'];

                $dm = DocumentManager::create($connection, $dm_config, $evm);

                $filterCollection = $dm->getFilterCollection();
                foreach ($enabledFilters as $filter) {
                    $filterCollection->enable($filter);
                }

                $api['dataaccess.mongoodm.documentmanager.' . $name] = $dm;
            }

            return $dm;
        });

        $api['dataaccess.mongoodm.mappings'] = function() {
            return [];
        };

        $api['dataaccess.mongoodm.mappingdriver'] = $api->protect(function ($name = null) use ($api) {
            if ($name == null) {
                $name = $api['config']['mongoodm']['default_document_manager'];
            }

            $chain = null;
            try {
                $chain = $api['dataaccess.mongoodm.mappingdriver.' . $name];
            } catch (\InvalidArgumentException $e) {
                $drivers = [];

                if (!empty($api['dataaccess.mongoodm.mappings'])) {
                    foreach ($api['dataaccess.mongoodm.mappings'] as $mappingConfig) {
                        if(array_key_exists('document_manager', $mappingConfig)) {
                            if($mappingConfig['document_manager'] != $name) {
                                continue;
                            }

                            unset($mappingConfig['document_manager']);
                        } else {
                            if($name != $api['config']['mongoodm']['default_document_manager']) {
                                continue;
                            }
                        }

                        if (!$mappingConfig['dir'] || !$mappingConfig['prefix']) {
                            throw new \InvalidArgumentException('Mapping definitions require "dir" and "prefix" options.');
                        }

                        if (!in_array($mappingConfig['type'], array('xml', 'yml', 'annotation', 'php', 'staticphp'))) {
                            $mappingConfig['type'] = 'annotation';
                        }

                        if (is_dir($mappingConfig['dir'])) {
                            $drivers[$mappingConfig['type']][$mappingConfig['prefix']] = realpath($mappingConfig['dir']);
                        } else {
                            throw new \InvalidArgumentException(sprintf('Invalid Doctrine mapping path given. Cannot load Doctrine mapping "%s".', $mappingConfig['prefix']));
                        }
                    }
                }

                $chain = new MappingDriverChain();

                foreach ($drivers as $driverType => $driverPaths) {
                    $annotation_reader = $api['dataaccess.doctrine.annotation.reader'];

                    array_unshift($driverPaths, __DIR__ . '/Documents');

                    $annotationDriver = new AnnotationDriver(
                        $annotation_reader,
                        array_values($driverPaths)
                    );

                    foreach ($driverPaths as $prefix => $driverPath) {
                        $chain->addDriver($annotationDriver, $prefix);
                    }
                }

                $api['dataaccess.mongoodm.mappingdriver.' . $name] = $chain;
            }

            return $chain;
        });

        $api['dataaccess.mongoodm.cachedriver'] = $api->protect(function ($name = null) use ($api) {
            if ($name == null) {
                $name = $api['config']['mongoodm']['default_document_manager'];
            }

            $cache = null;
            try {
                $cache = $api['dataaccess.mongoodm.cachedriver.' . $name];
            } catch (\InvalidArgumentException $e) {
                $documentManager = $api['config']['mongoodm']['document_managers'][$name];
                $cacheDriver = $documentManager['metadata_cache_driver'];

                if ($cacheDriver == null) {
                    $cacheDriver = ['type' => 'array'];
                }

                if (!isset($cacheDriver['namespace'])) {
                    $env = _DIR_;
                    $hash = hash('sha256', $env);
                    $namespace = 'blimp_' . $name . '_' . $hash;
                    $cacheDriver['namespace'] = $namespace;
                }

                $cache = $api['dataaccess.doctrine.cache.' . $cacheDriver['type'] . '.factory']($cacheDriver);

                $api['dataaccess.mongoodm.cachedriver.' . $name] = $cache;
            }

            return $cache;
        });

        $api->extend('blimp.extend', function ($status, $api) {
            if($status) {
                if($api->offsetExists('config.cache.prepare')) {
                    $api->extend('config.cache.prepare', function ($config, $api) {
                        if (empty($config['mongoodm']['default_connection'])) {
                            $keys = array_keys($config['mongoodm']['connections']);
                            $config['mongoodm']['default_connection'] = reset($keys);
                        }

                        if (empty($config['mongoodm']['default_document_manager'])) {
                            $keys = array_keys($config['mongoodm']['document_managers']);
                            $config['mongoodm']['default_document_manager'] = reset($keys);
                        }

                        return $config;
                    });
                }

                if($api->offsetExists('security.permissions')) {
                    $api->extend('security.permissions', function ($permissions, $api) {
                        $permissions['data'] = $api['security.permission.factory']('data', ['create', 'list', 'get', 'self_get', 'edit', 'self_edit', 'delete']);

                        return $permissions;
                    });
                }

                if($api->offsetExists('config.root')) {
                    $api->extend('config.root', function ($root, $api) {
                        $tb = new TreeBuilder();
                        $rootNode = $tb->root('mongoodm');

                        $this->addMongoODMSection($rootNode);
                        $this->addDocumentManagersSection($rootNode);
                        $this->addConnectionsSection($rootNode);

                        $root->append($rootNode);

                        return $root;
                    });
                }
            }

            return $status;
        });

        AnnotationDriver::registerAnnotationClasses();
    }

    private function addMongoODMSection($rootNode) {
        $rootNode
            ->children()
                ->scalarNode('proxy_namespace')->defaultValue('MongoDBODMProxies')->end()
                ->scalarNode('proxy_dir')->defaultValue('/Proxies')->end()
                ->scalarNode('auto_generate_proxy_classes')->defaultValue(true)->end()
                ->scalarNode('hydrator_namespace')->defaultValue('Hydrators')->end()
                ->scalarNode('hydrator_dir')->defaultValue('/Hydrators')->end()
                ->scalarNode('auto_generate_hydrator_classes')->defaultValue(true)->end()
                ->scalarNode('default_document_manager')->end()
                ->scalarNode('default_connection')->end()
                ->scalarNode('default_database')->defaultValue('default')->end()
                ->arrayNode('default_commit_options')
                    ->addDefaultsIfNotSet()
                    ->children()
                        ->booleanNode('j')->end()
                        ->scalarNode('timeout')->end()
                        ->scalarNode('w')->end()
                        ->scalarNode('wtimeout')->end()
                    ->end()
                ->end()
                ->variableNode('modules')->end()
            ->end()
        ;
    }

    private function addDocumentManagersSection($rootNode) {
        $rootNode
            ->fixXmlConfig('document_manager')
            ->children()
                ->arrayNode('document_managers')->useAttributeAsKey('id')
                    ->prototype('array')
                        ->treatNullLike(array())

                        ->fixXmlConfig('filter')
                        ->children()
                            ->scalarNode('connection')->end()
                            ->scalarNode('database')->end()
                            ->arrayNode('filters')
                                ->useAttributeAsKey('name')
                                ->prototype('array')
                                    ->fixXmlConfig('parameter')
                                    ->beforeNormalization()
                                        ->ifString()
                                        ->then(function ($v) {return array('class' => $v);})
                                    ->end()
                                    ->beforeNormalization()
                                        // The content of the XML node is returned as the "value" key so we need to rename it
                                        ->ifTrue(function ($v) {return is_array($v) && isset($v['value']);})
                                        ->then(function ($v) {
                                            $v['class'] = $v['value'];
                                            unset($v['value']);
                                            return $v;
                                        })
                                    ->end()
                                    ->children()
                                        ->scalarNode('class')->isRequired()->end()
                                        ->booleanNode('enabled')->defaultFalse()->end()
                                        ->arrayNode('parameters')
                                            ->treatNullLike(array())
                                            ->useAttributeAsKey('name')
                                            ->prototype('variable')
                                                ->beforeNormalization()
                                                    // Detect JSON object and array syntax (for XML)
                                                    ->ifTrue(function ($v) {return is_string($v) && (preg_match('/\[.*\]/', $v) || preg_match('/\{.*\}/', $v));})
                                                    // Decode objects to associative arrays for consistency with YAML
                                                    ->then(function ($v) {return json_decode($v, true);})
                                                ->end()
                                            ->end()
                                        ->end()
                                    ->end()
                                ->end()
                            ->end()
                            ->scalarNode('retry_connect')->defaultValue(0)->end()
                            ->scalarNode('retry_query')->defaultValue(0)->end()
                            ->arrayNode('metadata_cache_driver')
                                ->addDefaultsIfNotSet()
                                ->beforeNormalization()
                                    ->ifString()
                                        ->then(function ($v) {return array('type' => $v);})
                                    ->end()
                                ->children()
                                    ->scalarNode('type')->defaultValue('array')->end()
                                    ->scalarNode('host')->end()
                                    ->scalarNode('port')->end()
                                    ->scalarNode('id')->end()
                                    ->scalarNode('namespace')->end()
                                ->end()
                            ->end()
                        ->end()

                    ->end()
                ->end()
            ->end()
        ;
    }

    private function addConnectionsSection($rootNode) {
        $rootNode
            ->fixXmlConfig('connection')
            ->children()
                ->arrayNode('connections')
                    ->useAttributeAsKey('id')
                    ->prototype('array')
                        ->performNoDeepMerging()
                        ->children()
                            ->scalarNode('server')->end()
                            ->arrayNode('options')
                                ->performNoDeepMerging()
                                ->children()
                                    ->enumNode('authMechanism')
                                        ->values(array('MONGODB-CR', 'X509', 'PLAIN', 'GSSAPI'))
                                    ->end()
                                    ->booleanNode('connect')->end()
                                    ->scalarNode('connectTimeoutMS')->end()
                                    ->scalarNode('db')->end()
                                    ->booleanNode('journal')->end()
                                    ->scalarNode('password')
                                        ->validate()->ifNull()->thenUnset()->end()
                                    ->end()
                                    ->enumNode('readPreference')
                                        ->values(array('primary', 'primaryPreferred', 'secondary', 'secondaryPreferred', 'nearest'))
                                    ->end()
                                    ->arrayNode('readPreferenceTags')
                                        ->performNoDeepMerging()
                                        ->prototype('array')
                                            ->beforeNormalization()
                                                // Handle readPreferenceTag XML nodes
                                                ->ifTrue(function ($v) {return isset($v['readPreferenceTag']);})
                                                ->then(function ($v) {
                                                    // Equivalent of fixXmlConfig() for inner node
                                                    if (isset($v['readPreferenceTag']['name'])) {
                                                        $v['readPreferenceTag'] = array($v['readPreferenceTag']);
                                                    }
                                                    return $v['readPreferenceTag'];
                                                })
                                            ->end()
                                            ->useAttributeAsKey('name')
                                            ->prototype('scalar')
                                            ->end()
                                        ->end()
                                    ->end()
                                    ->scalarNode('replicaSet')->end()
                                    ->scalarNode('socketTimeoutMS')->end()
                                    ->booleanNode('ssl')->end()
                                    ->scalarNode('username')
                                        ->validate()->ifNull()->thenUnset()->end()
                                    ->end()
                                    ->scalarNode('w')->end()
                                    ->scalarNode('wTimeoutMS')->end()
                                    ->end()
                                ->validate()
                                    ->ifTrue(function ($v) {return count($v['readPreferenceTags']) === 0;})
                                    ->then(function ($v) {
                                        unset($v['readPreferenceTags']);
                                        return $v;
                                    })
                                ->end()
                            ->end()
                        ->end()
                    ->end()
                ->end()
            ->end()
        ;
    }
}
