<?php

namespace DreamFactory\Core\Resources\System;

use DreamFactory\Core\Enums\AppTypes;
use DreamFactory\Core\Models\App as AppModel;
use DreamFactory\Core\Models\AppGroup as AppGroupModel;
use DreamFactory\Core\Models\Service as ServiceModel;
use DreamFactory\Core\Models\UserAppRole;
use DreamFactory\Core\User\Services\User;
use DreamFactory\Core\Utility\Session as SessionUtilities;
use DreamFactory\Library\Utility\ArrayUtils;
use DreamFactory\Library\Utility\Enums\Verbs;
use DreamFactory\Library\Utility\Scalar;
use DreamFactory\Core\Models\Config as SystemConfig;
use DreamFactory\Library\Utility\Inflector;

class Environment extends BaseSystemResource
{
    /**
     * @return array
     */
    protected function handleGET()
    {
        $result = [];

        $result['platform'] = [
            'version_current'   => '2.0.0',
            'version_latest'    => '2.0.0',
            'upgrade_available' => false,
            'is_hosted'         => false,
            'host'              => php_uname('n'),
        ];

        $login = static::getLoginApi();
        $apps = static::getApps();
        $groupedApps = ArrayUtils::get($apps, 0);
        $noGroupApps = ArrayUtils::get($apps, 1);

        $result['authentication'] = $login;
        $result['app_group'] = (count($groupedApps) > 0) ? $groupedApps : [];
        $result['no_group_app'] = (count($noGroupApps) > 0) ? $noGroupApps : [];

        /*
         * Most API calls return a resource array or a single resource,
         * If an array, shall we wrap it?, With what shall we wrap it?
         */
        $config = [
            'always_wrap_resources' => \Config::get('df.always_wrap_resources'),
            'resources_wrapper'     => \Config::get('df.resources_wrapper'),
            'db'                    => [
                /** The default number of records to return at once for database queries */
                'max_records_returned' => \Config::get('df.db.max_records_returned'),
                'time_format'          => \Config::get('df.db.time_format'),
                'date_format'          => \Config::get('df.db.date_format'),
                'datetime_format'      => \Config::get('df.db.datetime_format'),
                'timestamp_format'     => \Config::get('df.db.timestamp_format'),
            ],
        ];
        $result['config'] = $config;

        if (SessionUtilities::isSysAdmin()) {
            $result['server'] = [
                'server_os' => strtolower(php_uname('s')),
                'release'   => php_uname('r'),
                'version'   => php_uname('v'),
                'host'      => php_uname('n'),
                'machine'   => php_uname('m'),
            ];
            $result['php'] = static::getPhpInfo();
        }

        return $result;
    }

    protected static function getApps()
    {
        if (SessionUtilities::isAuthenticated()) {
            $user = SessionUtilities::user();
            $defaultAppId = $user->default_app_id;

            if (SessionUtilities::isSysAdmin()) {
                $appGroups = AppGroupModel::with(
                    [
                        'app_by_app_to_app_group' => function ($q){
                            $q->whereIsActive(1)->whereNotIn('type', [AppTypes::NONE]);
                        }
                    ]
                )->get();
                $apps = AppModel::whereIsActive(1)->whereNotIn('type', [AppTypes::NONE])->get();
            } else {
                $userId = $user->id;
                $userAppRoles = UserAppRole::whereUserId($userId)->whereNotNull('role_id')->get(['app_id']);
                $appIds = [];
                foreach ($userAppRoles as $uar) {
                    $appIds[] = $uar->app_id;
                }
                $appIdsString = implode(',', $appIds);
                $appIdsString = (empty($appIdsString)) ? '-1' : $appIdsString;
                $typeString = implode(',', [AppTypes::NONE]);
                $typeString = (empty($typeString)) ? '-1' : $typeString;

                $appGroups = AppGroupModel::with(
                    [
                        'app_by_app_to_app_group' => function ($q) use ($appIdsString, $typeString){
                            $q->whereRaw("(app.id IN ($appIdsString) OR role_id > 0) AND is_active = 1 AND type NOT IN ($typeString)");
                        }
                    ]
                )->get();
                $apps =
                    AppModel::whereRaw("(app.id IN ($appIdsString) OR role_id > 0) AND is_active = 1 AND type NOT IN ($typeString)")
                        ->get();
            }
        } else {
            $appGroups = AppGroupModel::with(
                [
                    'app_by_app_to_app_group' => function ($q){
                        $q->where('role_id', '>', 0)
                            ->whereIsActive(1)
                            ->whereNotIn('type', [AppTypes::NONE]);
                    }
                ]
            )->get();
            $apps = AppModel::whereIsActive(1)
                ->where('role_id', '>', 0)
                ->whereNotIn('type', [AppTypes::NONE])
                ->get();
        }

        if (empty($defaultAppId)) {
            $systemConfig = SystemConfig::first(['default_app_id']);
            $defaultAppId = (!empty($systemConfig)) ? $systemConfig->default_app_id : null;
        }

        $inGroups = [];
        $groupedApps = [];
        $noGroupedApps = [];

        foreach ($appGroups as $appGroup) {
            $appArray = $appGroup->getRelation('app_by_app_to_app_group')->toArray();
            if (!empty($appArray)) {
                $appInfo = [];
                foreach ($appArray as $app) {
                    $inGroups[] = $app['id'];
                    $appInfo[] = static::makeAppInfo($app, $defaultAppId);
                }

                $groupedApps[] = [
                    'id'          => $appGroup->id,
                    'name'        => $appGroup->name,
                    'description' => $appGroup->description,
                    'app'         => $appInfo
                ];
            }
        }

        /** @type AppModel $app */
        foreach ($apps as $app) {
            if (!in_array($app->id, $inGroups)) {
                $noGroupedApps[] = static::makeAppInfo($app->toArray(), $defaultAppId);
            }
        }

        return [$groupedApps, $noGroupedApps];
    }

    protected static function makeAppInfo(array $app, $defaultAppId)
    {
        return [
            'id'                      => $app['id'],
            'name'                    => $app['name'],
            'description'             => $app['description'],
            'url'                     => $app['launch_url'],
            'is_default'              => ($defaultAppId === $app['id']) ? true : false,
            'allow_fullscreen_toggle' => $app['allow_fullscreen_toggle'],
            'requires_fullscreen'     => $app['requires_fullscreen'],
            'toggle_location'         => $app['toggle_location']
        ];
    }

    /**
     * @return array
     */
    protected static function getLoginApi()
    {
        $adminApi = [
            'path'    => 'system/admin/session',
            'verb'    => Verbs::POST,
            'payload' => [
                'email'       => 'string',
                'password'    => 'string',
                'remember_me' => 'bool'
            ]
        ];
        $userApi = [
            'path'    => 'user/session',
            'verb'    => Verbs::POST,
            'payload' => [
                'email'       => 'string',
                'password'    => 'string',
                'remember_me' => 'bool'
            ]
        ];

        if (class_exists(User::class)) {
            $oauth = static::getOAuthServices();
            $ldap = static::getAdLdapServices();

            return [
                'admin'  => $adminApi,
                'user'   => $userApi,
                'oauth'  => $oauth,
                'adldap' => $ldap
            ];
        }

        return ['admin' => $adminApi];
    }

    /**
     * @return array
     */
    protected static function getOAuthServices()
    {
        $oauth = ServiceModel::whereIn(
            'type',
            ['oauth_facebook', 'oauth_twitter', 'oauth_github', 'oauth_google']
        )->whereIsActive(1)->get(['id', 'name', 'type', 'label']);

        $services = [];

        foreach ($oauth as $o) {
            $config = $o->getConfigAttribute();
            $services[] = [
                'path'       => 'user/session?service=' . strtolower($o->name),
                'name'       => $o->name,
                'label'      => $o->label,
                'verb'       => [Verbs::GET, Verbs::POST],
                'type'       => $o->type,
                'icon_class' => $config['icon_class']
            ];
        }

        return $services;
    }

    /**
     * @return array
     */
    protected static function getAdLdapServices()
    {
        $ldap = ServiceModel::whereIn(
            'type',
            ['ldap', 'adldap']
        )->whereIsActive(1)->get(['name', 'type', 'label']);

        $services = [];

        foreach ($ldap as $l) {
            $services[] = [
                'path'    => 'user/session?service=' . strtolower($l->name),
                'name'    => $l->name,
                'label'   => $l->label,
                'verb'    => Verbs::POST,
                'payload' => [
                    'username'    => 'string',
                    'password'    => 'string',
                    'service'     => $l->name,
                    'remember_me' => 'bool'
                ]
            ];
        }

        return $services;
    }

    //Following codes are directly copied over from 1.x and is not functional.

//    protected function handleGET()
//    {
//        $_release = null;
//        $_phpInfo = $this->_getPhpInfo();
//
//        if ( false !== ( $_raw = file( static::LSB_RELEASE ) ) && !empty( $_raw ) )
//        {
//            $_release = array();
//
//            foreach ( $_raw as $_line )
//            {
//                $_fields = explode( '=', $_line );
//                $_release[str_replace( 'distrib_', null, strtolower( $_fields[0] ) )] = trim( $_fields[1], PHP_EOL . '"' );
//            }
//        }
//
//        $_response = array(
//            'php_info' => $_phpInfo,
//            'platform' => Config::getCurrentConfig(),
//            'release'  => $_release,
//            'server'   => array(
//                'server_os' => strtolower( php_uname( 's' ) ),
//                'uname'     => php_uname( 'a' ),
//            ),
//        );
//
//        array_multisort( $_response );
//
//        //	Cache configuration
//        Platform::storeSet( static::CACHE_KEY, $_response, static::CONFIG_CACHE_TTL );
//
//        $this->_response = $this->_response ? array_merge( $this->_response, $_response ) : $_response;
//        unset( $_response );
//
//        return $this->_response;
//    }

    /**
     * Parses the data coming back from phpinfo() call and returns in an array
     *
     * @return array
     */
    protected static function getPhpInfo()
    {
        $_html = null;
        $_info = array();
        $_pattern =
            '#(?:<h2>(?:<a name=".*?">)?(.*?)(?:</a>)?</h2>)|(?:<tr(?: class=".*?")?><t[hd](?: class=".*?")?>(.*?)\s*</t[hd]>(?:<t[hd](?: class=".*?")?>(.*?)\s*</t[hd]>(?:<t[hd](?: class=".*?")?>(.*?)\s*</t[hd]>)?)?</tr>)#s';

        \ob_start();
        @\phpinfo();
        $_html = \ob_get_contents();
        \ob_end_clean();

        if (preg_match_all($_pattern, $_html, $_matches, PREG_SET_ORDER)) {
            foreach ($_matches as $_match) {
                $_keys = array_keys($_info);
                $_lastKey = end($_keys);

                if (strlen($_match[1])) {
                    $_info[$_match[1]] = array();
                } elseif (isset($_match[3])) {
                    $_info[$_lastKey][$_match[2]] = isset($_match[4]) ? array($_match[3], $_match[4]) : $_match[3];
                } else {
                    $_info[$_lastKey][] = $_match[2];
                }

                unset($_keys, $_match);
            }
        }

        return static::cleanPhpInfo($_info);
    }

    /**
     * @param array $info
     *
     * @param bool  $recursive
     *
     * @return array
     */
    protected static function cleanPhpInfo($info, $recursive = false)
    {
        static $_excludeKeys = array('directive', 'variable',);

        $_clean = array();

        //  Remove images and move nested args to root
        if (!$recursive && isset($info[0], $info[0][0]) && is_array($info[0])) {
            $info['general'] = array();

            foreach ($info[0] as $_key => $_value) {
                if (is_numeric($_key) || in_array(strtolower($_key), $_excludeKeys)) {
                    continue;
                }

                $info['general'][$_key] = $_value;
                unset($info[0][$_key]);
            }

            unset($info[0]);
        }

        foreach ($info as $_key => $_value) {
            if (in_array(strtolower($_key), $_excludeKeys)) {
                continue;
            }

            $_key = strtolower(str_replace(' ', '_', $_key));

            if (is_array($_value) && 2 == count($_value) && isset($_value[0], $_value[1])) {
                $_v1 = ArrayUtils::get($_value, 0);

                if ($_v1 == '<i>no value</i>') {
                    $_v1 = null;
                }

                if (Scalar::in(strtolower($_v1), 'on', 'off', '0', '1')) {
                    $_v1 = ArrayUtils::getBool($_value, 0);
                }

                $_value = $_v1;
            }

            if (is_array($_value)) {
                $_value = static::cleanPhpInfo($_value, true);
            }

            $_clean[$_key] = $_value;
        }

        return $_clean;
    }

    public function getApiDocInfo()
    {
        $path = '/' . $this->getServiceName() . '/' . $this->getFullPathName();
        $eventPath = $this->getServiceName() . '.' . $this->getFullPathName('.');
        $name = Inflector::camelize($this->name);
        $plural = Inflector::pluralize($name);
        $words = str_replace('_', ' ', $this->name);
        $pluralWords = Inflector::pluralize($words);
//        $alwaysWrap = \Config::get('df.always_wrap_resources', false);
        $wrapper = \Config::get('df.resources_wrapper', 'resource');

        $apis = [
            [
                'path'        => $path,
                'operations'  => [
                    [
                        'method'           => 'GET',
                        'summary'          => 'get' . $plural . '() - Retrieve one or more ' . $pluralWords . '.',
                        'nickname'         => 'get' . $plural,
                        'type'             => $plural . 'Response',
                        'event_name'       => $eventPath . '.list',
                        'consumes'         => ['application/json', 'application/xml', 'text/csv'],
                        'produces'         => ['application/json', 'application/xml', 'text/csv'],
                        'parameters'       => [
                            [
                                'name'          => 'ids',
                                'description'   => 'Comma-delimited list of the identifiers of the records to retrieve.',
                                'allowMultiple' => true,
                                'type'          => 'string',
                                'paramType'     => 'query',
                                'required'      => false,
                            ],
                            [
                                'name'          => 'filter',
                                'description'   => 'SQL-like filter to limit the records to retrieve.',
                                'allowMultiple' => false,
                                'type'          => 'string',
                                'paramType'     => 'query',
                                'required'      => false,
                            ],
                            [
                                'name'          => 'limit',
                                'description'   => 'Set to limit the filter results.',
                                'allowMultiple' => false,
                                'type'          => 'integer',
                                'format'        => 'int32',
                                'paramType'     => 'query',
                                'required'      => false,
                            ],
                            [
                                'name'          => 'order',
                                'description'   => 'SQL-like order containing field and direction for filter results.',
                                'allowMultiple' => false,
                                'type'          => 'string',
                                'paramType'     => 'query',
                                'required'      => false,
                            ],
                            [
                                'name'          => 'offset',
                                'description'   => 'Set to offset the filter results to a particular record count.',
                                'allowMultiple' => false,
                                'type'          => 'integer',
                                'format'        => 'int32',
                                'paramType'     => 'query',
                                'required'      => false,
                            ],
                            [
                                'name'          => 'fields',
                                'description'   => 'Comma-delimited list of field names to retrieve for each record.',
                                'allowMultiple' => true,
                                'type'          => 'string',
                                'paramType'     => 'query',
                                'required'      => false,
                            ],
                            [
                                'name'          => 'related',
                                'description'   => 'Comma-delimited list of related names to retrieve for each record.',
                                'allowMultiple' => true,
                                'type'          => 'string',
                                'paramType'     => 'query',
                                'required'      => false,
                            ],
                            [
                                'name'          => 'include_count',
                                'description'   => 'Include the total number of filter results in returned metadata.',
                                'allowMultiple' => false,
                                'type'          => 'boolean',
                                'paramType'     => 'query',
                                'required'      => false,
                            ],
                            [
                                'name'          => 'include_schema',
                                'description'   => 'Include the schema of the table queried in returned metadata.',
                                'allowMultiple' => false,
                                'type'          => 'boolean',
                                'paramType'     => 'query',
                                'required'      => false,
                            ],
                            [
                                'name'          => 'file',
                                'description'   => 'Download the results of the request as a file.',
                                'allowMultiple' => false,
                                'type'          => 'string',
                                'paramType'     => 'query',
                                'required'      => false,
                            ],
                        ],
                        'responseMessages' => [
                            [
                                'message' => 'Bad Request - Request does not have a valid format, all required parameters, etc.',
                                'code'    => 400,
                            ],
                            [
                                'message' => 'Unauthorized Access - No currently valid session available.',
                                'code'    => 401,
                            ],
                            [
                                'message' => 'System Error - Specific reason is included in the error message.',
                                'code'    => 500,
                            ],
                        ],
                        'notes'            =>
                            'Use the \'ids\' or \'filter\' parameter to limit records that are returned. ' .
                            'By default, all records up to the maximum are returned. <br>' .
                            'Use the \'fields\' and \'related\' parameters to limit properties returned for each record. ' .
                            'By default, all fields and no relations are returned for each record. <br>' .
                            'Alternatively, to retrieve by record, a large list of ids, or a complicated filter, ' .
                            'use the POST request with X-HTTP-METHOD = GET header and post records or ids.',
                    ],
                    [
                        'method'           => 'POST',
                        'summary'          => 'create' . $plural . '() - Create one or more ' . $pluralWords . '.',
                        'nickname'         => 'create' . $plural,
                        'type'             => $plural . 'Response',
                        'event_name'       => $eventPath . '.create',
                        'consumes'         => ['application/json', 'application/xml', 'text/csv'],
                        'produces'         => ['application/json', 'application/xml', 'text/csv'],
                        'parameters'       => [
                            [
                                'name'          => 'body',
                                'description'   => 'Data containing name-value pairs of records to create.',
                                'allowMultiple' => false,
                                'type'          => $plural . 'Request',
                                'paramType'     => 'body',
                                'required'      => true,
                            ],
                            [
                                'name'          => 'fields',
                                'description'   => 'Comma-delimited list of field names to return for each record affected.',
                                'allowMultiple' => true,
                                'type'          => 'string',
                                'paramType'     => 'query',
                                'required'      => false,
                            ],
                            [
                                'name'          => 'related',
                                'description'   => 'Comma-delimited list of related names to return for each record affected.',
                                'allowMultiple' => true,
                                'type'          => 'string',
                                'paramType'     => 'query',
                                'required'      => false,
                            ],
                            [
                                'name'          => 'X-HTTP-METHOD',
                                'description'   => 'Override request using POST to tunnel other http request, such as DELETE.',
                                'enum'          => ['GET', 'PUT', 'PATCH', 'DELETE'],
                                'allowMultiple' => false,
                                'type'          => 'string',
                                'paramType'     => 'header',
                                'required'      => false,
                            ],
                        ],
                        'responseMessages' => [
                            [
                                'message' => 'Bad Request - Request does not have a valid format, all required parameters, etc.',
                                'code'    => 400,
                            ],
                            [
                                'message' => 'Unauthorized Access - No currently valid session available.',
                                'code'    => 401,
                            ],
                            [
                                'message' => 'System Error - Specific reason is included in the error message.',
                                'code'    => 500,
                            ],
                        ],
                        'notes'            =>
                            'Post data should be a single record or an array of records (shown). ' .
                            'By default, only the id property of the record affected is returned on success, ' .
                            'use \'fields\' and \'related\' to return more info.',
                    ],
                    [
                        'method'           => 'PATCH',
                        'summary'          => 'update' . $plural . '() - Update one or more ' . $pluralWords . '.',
                        'nickname'         => 'update' . $plural,
                        'type'             => $plural . 'Response',
                        'event_name'       => $eventPath . '.update',
                        'consumes'         => ['application/json', 'application/xml', 'text/csv'],
                        'produces'         => ['application/json', 'application/xml', 'text/csv'],
                        'parameters'       => [
                            [
                                'name'          => 'body',
                                'description'   => 'Data containing name-value pairs of records to update.',
                                'allowMultiple' => false,
                                'type'          => $plural . 'Request',
                                'paramType'     => 'body',
                                'required'      => true,
                            ],
                            [
                                'name'          => 'fields',
                                'description'   => 'Comma-delimited list of field names to return for each record affected.',
                                'allowMultiple' => true,
                                'type'          => 'string',
                                'paramType'     => 'query',
                                'required'      => false,
                            ],
                            [
                                'name'          => 'related',
                                'description'   => 'Comma-delimited list of related names to return for each record affected.',
                                'allowMultiple' => true,
                                'type'          => 'string',
                                'paramType'     => 'query',
                                'required'      => false,
                            ],
                        ],
                        'responseMessages' => [
                            [
                                'message' => 'Bad Request - Request does not have a valid format, all required parameters, etc.',
                                'code'    => 400,
                            ],
                            [
                                'message' => 'Unauthorized Access - No currently valid session available.',
                                'code'    => 401,
                            ],
                            [
                                'message' => 'System Error - Specific reason is included in the error message.',
                                'code'    => 500,
                            ],
                        ],
                        'notes'            =>
                            'Post data should be a single record or an array of records (shown). ' .
                            'By default, only the id property of the record is returned on success, ' .
                            'use \'fields\' and \'related\' to return more info.',
                    ],
                    [
                        'method'           => 'DELETE',
                        'summary'          => 'delete' . $plural . '() - Delete one or more ' . $pluralWords . '.',
                        'nickname'         => 'delete' . $plural,
                        'type'             => $plural . 'Response',
                        'event_name'       => $eventPath . '.delete',
                        'parameters'       => [
                            [
                                'name'          => 'ids',
                                'description'   => 'Comma-delimited list of the identifiers of the records to delete.',
                                'allowMultiple' => true,
                                'type'          => 'string',
                                'paramType'     => 'query',
                                'required'      => false,
                            ],
                            [
                                'name'          => 'force',
                                'description'   => 'Set force to true to delete all records in this table, otherwise \'ids\' parameter is required.',
                                'allowMultiple' => false,
                                'type'          => 'boolean',
                                'paramType'     => 'query',
                                'required'      => false,
                                'default'       => false,
                            ],
                            [
                                'name'          => 'fields',
                                'description'   => 'Comma-delimited list of field names to return for each record affected.',
                                'allowMultiple' => true,
                                'type'          => 'string',
                                'paramType'     => 'query',
                                'required'      => false,
                            ],
                            [
                                'name'          => 'related',
                                'description'   => 'Comma-delimited list of related names to return for each record affected.',
                                'allowMultiple' => true,
                                'type'          => 'string',
                                'paramType'     => 'query',
                                'required'      => false,
                            ],
                        ],
                        'responseMessages' => [
                            [
                                'message' => 'Bad Request - Request does not have a valid format, all required parameters, etc.',
                                'code'    => 400,
                            ],
                            [
                                'message' => 'Unauthorized Access - No currently valid session available.',
                                'code'    => 401,
                            ],
                            [
                                'message' => 'System Error - Specific reason is included in the error message.',
                                'code'    => 500,
                            ],
                        ],
                        'notes'            =>
                            'By default, only the id property of the record deleted is returned on success. ' .
                            'Use \'fields\' and \'related\' to return more properties of the deleted records. <br>' .
                            'Alternatively, to delete by record or a large list of ids, ' .
                            'use the POST request with X-HTTP-METHOD = DELETE header and post records or ids.',
                    ],
                ],
                'description' => "Operations for $words administration.",
            ],
            [
                'path'        => $path . '/{id}',
                'operations'  => [
                    [
                        'method'           => 'GET',
                        'summary'          => 'get' . $name . '() - Retrieve one ' . $words . '.',
                        'nickname'         => 'get' . $name,
                        'type'             => $name . 'Response',
                        'event_name'       => $eventPath . '.read',
                        'parameters'       => [
                            [
                                'name'          => 'id',
                                'description'   => 'Identifier of the record to retrieve.',
                                'allowMultiple' => false,
                                'type'          => 'string',
                                'paramType'     => 'path',
                                'required'      => true,
                            ],
                            [
                                'name'          => 'fields',
                                'description'   => 'Comma-delimited list of field names to return.',
                                'allowMultiple' => true,
                                'type'          => 'string',
                                'paramType'     => 'query',
                                'required'      => false,
                            ],
                            [
                                'name'          => 'related',
                                'description'   => 'Comma-delimited list of related records to return.',
                                'allowMultiple' => true,
                                'type'          => 'string',
                                'paramType'     => 'query',
                                'required'      => false,
                            ],
                        ],
                        'responseMessages' => [
                            [
                                'message' => 'Bad Request - Request does not have a valid format, all required parameters, etc.',
                                'code'    => 400,
                            ],
                            [
                                'message' => 'Unauthorized Access - No currently valid session available.',
                                'code'    => 401,
                            ],
                            [
                                'message' => 'System Error - Specific reason is included in the error message.',
                                'code'    => 500,
                            ],
                        ],
                        'notes'            => 'Use the \'fields\' and/or \'related\' parameter to limit properties that are returned. By default, all fields and no relations are returned.',
                    ],
                    [
                        'method'           => 'PATCH',
                        'summary'          => 'update' . $name . '() - Update one ' . $words . '.',
                        'nickname'         => 'update' . $name,
                        'type'             => $name . 'Response',
                        'event_name'       => $eventPath . '.update',
                        'parameters'       => [
                            [
                                'name'          => 'id',
                                'description'   => 'Identifier of the record to update.',
                                'allowMultiple' => false,
                                'type'          => 'string',
                                'paramType'     => 'path',
                                'required'      => true,
                            ],
                            [
                                'name'          => 'body',
                                'description'   => 'Data containing name-value pairs of fields to update.',
                                'allowMultiple' => false,
                                'type'          => $name . 'Request',
                                'paramType'     => 'body',
                                'required'      => true,
                            ],
                            [
                                'name'          => 'fields',
                                'description'   => 'Comma-delimited list of field names to return.',
                                'allowMultiple' => true,
                                'type'          => 'string',
                                'paramType'     => 'query',
                                'required'      => false,
                            ],
                            [
                                'name'          => 'related',
                                'description'   => 'Comma-delimited list of related records to return.',
                                'allowMultiple' => true,
                                'type'          => 'string',
                                'paramType'     => 'query',
                                'required'      => false,
                            ],
                        ],
                        'responseMessages' => [
                            [
                                'message' => 'Bad Request - Request does not have a valid format, all required parameters, etc.',
                                'code'    => 400,
                            ],
                            [
                                'message' => 'Unauthorized Access - No currently valid session available.',
                                'code'    => 401,
                            ],
                            [
                                'message' => 'System Error - Specific reason is included in the error message.',
                                'code'    => 500,
                            ],
                        ],
                        'notes'            =>
                            'Post data should be an array of fields to update for a single record. <br>' .
                            'By default, only the id is returned. Use the \'fields\' and/or \'related\' parameter to return more properties.',
                    ],
                    [
                        'method'           => 'DELETE',
                        'summary'          => 'delete' . $name . '() - Delete one ' . $words . '.',
                        'nickname'         => 'delete' . $name,
                        'type'             => $name . 'Response',
                        'event_name'       => $eventPath . '.delete',
                        'parameters'       => [
                            [
                                'name'          => 'id',
                                'description'   => 'Identifier of the record to delete.',
                                'allowMultiple' => false,
                                'type'          => 'string',
                                'paramType'     => 'path',
                                'required'      => true,
                            ],
                            [
                                'name'          => 'fields',
                                'description'   => 'Comma-delimited list of field names to return.',
                                'allowMultiple' => true,
                                'type'          => 'string',
                                'paramType'     => 'query',
                                'required'      => false,
                            ],
                            [
                                'name'          => 'related',
                                'description'   => 'Comma-delimited list of related records to return.',
                                'allowMultiple' => true,
                                'type'          => 'string',
                                'paramType'     => 'query',
                                'required'      => false,
                            ],
                        ],
                        'responseMessages' => [
                            [
                                'message' => 'Bad Request - Request does not have a valid format, all required parameters, etc.',
                                'code'    => 400,
                            ],
                            [
                                'message' => 'Unauthorized Access - No currently valid session available.',
                                'code'    => 401,
                            ],
                            [
                                'message' => 'System Error - Specific reason is included in the error message.',
                                'code'    => 500,
                            ],
                        ],
                        'notes'            => 'By default, only the id is returned. Use the \'fields\' and/or \'related\' parameter to return deleted properties.',
                    ],
                ],
                'description' => "Operations for individual $words administration.",
            ],
        ];

        $models = [
            $plural . 'Request'  => [
                'id'         => $plural . 'Request',
                'properties' => [
                    $wrapper => [
                        'type'        => 'array',
                        'description' => 'Array of system records.',
                        'items'       => [
                            '$ref' => $name . 'Request',
                        ],
                    ],
                    'ids'    => [
                        'type'        => 'array',
                        'description' => 'Array of system record identifiers, used for batch GET, PUT, PATCH, and DELETE.',
                        'items'       => [
                            'type'   => 'integer',
                            'format' => 'int32',
                        ],
                    ],
                ],
            ],
            $plural . 'Response' => [
                'id'         => $plural . 'Response',
                'properties' => [
                    $wrapper => [
                        'type'        => 'array',
                        'description' => 'Array of system records.',
                        'items'       => [
                            '$ref' => $name . 'Response',
                        ],
                    ],
                    'meta'   => [
                        'type'        => 'Metadata',
                        'description' => 'Array of metadata returned for GET requests.',
                    ],
                ],
            ],
            'Metadata'           => [
                'id'         => 'Metadata',
                'properties' => [
                    'schema' => [
                        'type'        => 'Array',
                        'description' => 'Array of table schema.',
                        'items'       => [
                            'type' => 'string',
                        ],
                    ],
                    'count'  => [
                        'type'        => 'integer',
                        'format'      => 'int32',
                        'description' => 'Record count returned for GET requests.',
                    ],
                ],
            ],
        ];

        return ['apis' => $apis, 'models' => $models];
    }

    /*
    public function getApiDocInfo()
    {
        $path = '/' . $this->getServiceName() . '/' . $this->getFullPathName();
        $eventPath = $this->getServiceName() . '.' . $this->getFullPathName( '.' );

        return [

            //-------------------------------------------------------------------------
            //	APIs
            //-------------------------------------------------------------------------

            'apis'   => [
                [
                    'path'        => $path,
                    'operations'  => [
                        [
                            'method'     => 'GET',
                            'summary'    => 'getEnvironment() - Retrieve environment information.',
                            'nickname'   => 'getEnvironment',
                            'type'       => 'EnvironmentResponse',
                            'event_name' => $eventPath . '.read',
                            'notes'      => 'The retrieved information describes the container/machine on which the DSP resides.',
                        ],
                    ],
                    'description' => 'Operations for system configuration options.',
                ],
            ],
            //-------------------------------------------------------------------------
            //	Models
            //-------------------------------------------------------------------------

            'models' => [
                'ServerSection'       => [
                    'id'         => 'ServerSection',
                    'properties' => [
                        'server_os' => [
                            'type' => 'string',
                        ],
                        'uname'     => [
                            'type' => 'string',
                        ],
                    ],
                ],
                'ReleaseSection'      => [
                    'id'         => 'ReleaseSection',
                    'properties' => [
                        'id'          => [
                            'type' => 'string',
                        ],
                        'release'     => [
                            'type' => 'string',
                        ],
                        'codename'    => [
                            'type' => 'string',
                        ],
                        'description' => [
                            'type' => 'string',
                        ],
                    ],
                ],
                'PlatformSection'     => [
                    'id'         => 'PlatformSection',
                    'properties' => [
                        'is_hosted'           => [
                            'type' => 'boolean',
                        ],
                        'is_private'          => [
                            'type' => 'boolean',
                        ],
                        'dsp_version_current' => [
                            'type' => 'string',
                        ],
                        'dsp_version_latest'  => [
                            'type' => 'string',
                        ],
                        'upgrade_available'   => [
                            'type' => 'boolean',
                        ],
                    ],
                ],
                'PhpInfoSection'      => [
                    'id'         => 'PhpInfoSection',
                    'properties' => [
                        'name' => [
                            'type'  => 'array',
                            'items' => [
                                'type' => 'string',
                            ],
                        ],
                    ],
                ],
                'EnvironmentResponse' => [
                    'id'         => 'EnvironmentResponse',
                    'properties' => [
                        'server'   => [
                            'type' => 'ServerSection',
                        ],
                        'release'  => [
                            'type' => 'ReleaseSection',
                        ],
                        'platform' => [
                            'type' => 'PlatformSection',
                        ],
                        'php_info' => [
                            'type'  => 'array',
                            'items' => [
                                '$ref' => 'PhpInfoSection',
                            ],
                        ],
                    ],
                ],
            ]
        ];
    }
    */
}