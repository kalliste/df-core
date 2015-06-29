<?php

namespace DreamFactory\Core\Resources\System;

use DreamFactory\Core\Components\ApiDocManager;

/**
 * Class BroadcastEvent
 *
 * @package DreamFactory\Core\Resources
 */
class BroadcastEvent extends BaseEvent
{
    //*************************************************************************
    //	Constants
    //*************************************************************************

    /**
     * Resource tag for dealing with event scripts
     */
    const RESOURCE_NAME = 'broadcast';

    //*************************************************************************
    //	Methods
    //*************************************************************************

    protected function getEventMap()
    {
        return ApiDocManager::getBroadcastEventMap();
    }
}