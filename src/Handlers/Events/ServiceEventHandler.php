<?php
namespace DreamFactory\Rave\Handlers\Events;

use DreamFactory\Library\Utility\ArrayUtils;
use DreamFactory\Rave\Contracts\ServiceRequestInterface;
use DreamFactory\Rave\Contracts\ServiceResponseInterface;
use DreamFactory\Rave\Exceptions\InternalServerErrorException;
use DreamFactory\Rave\Models\EventScript;
use DreamFactory\Rave\Scripting\ScriptEngineManager;
use Illuminate\Contracts\Events\Dispatcher;
use DreamFactory\Rave\Events\ResourcePreProcess;
use DreamFactory\Rave\Events\ResourcePostProcess;
use DreamFactory\Rave\Events\ServicePreProcess;
use DreamFactory\Rave\Events\ServicePostProcess;
use \Log;

class ServiceEventHandler
{
    /**
     * Register the listeners for the subscriber.
     *
     * @param  Dispatcher $events
     *
     * @return array
     */
    public function subscribe( $events )
    {
        $events->listen( 'DreamFactory\Rave\Events\ServicePreProcess', 'DreamFactory\Rave\Handlers\Events\ServiceEventHandler@onServicePreProcess' );
        $events->listen( 'DreamFactory\Rave\Events\ServicePostProcess', 'DreamFactory\Rave\Handlers\Events\ServiceEventHandler@onServicePostProcess' );
        $events->listen( 'DreamFactory\Rave\Events\ResourcePreProcess', 'DreamFactory\Rave\Handlers\Events\ServiceEventHandler@onResourcePreProcess' );
        $events->listen( 'DreamFactory\Rave\Events\ResourcePostProcess', 'DreamFactory\Rave\Handlers\Events\ServiceEventHandler@onResourcePostProcess' );
    }

    /**
     * Handle service pre-process events.
     *
     * @param  ServicePreProcess $event
     *
     * @return bool
     */
    public function onServicePreProcess( $event )
    {
        $name = $event->service . '.' . strtolower( $event->request->getMethod() ) . '.pre_process';
        Log::debug( 'Service event: ' . $name );

        return $this->onPreProcess( $name, $event );
    }

    /**
     * Handle service post-process events.
     *
     * @param  ServicePostProcess $event
     *
     * @return bool
     */
    public function onServicePostProcess( $event )
    {
        $name = $event->service . '.' . strtolower( $event->request->getMethod() ) . '.post_process';
        Log::debug( 'Service event: ' . $name );

        return $this->onPostProcess( $name, $event );
    }

    /**
     * Handle resource pre-process events.
     *
     * @param  ResourcePreProcess $event
     *
     * @return bool
     */
    public function onResourcePreProcess( $event )
    {
        $name = $event->service . '.' . $event->resourcePath . '.' . strtolower( $event->request->getMethod() ) . '.pre_process';
        Log::debug( 'Resource event: ' . $name );

        return $this->onPreProcess( $name, $event );
    }

    /**
     * Handle resource post-process events.
     *
     * @param  ResourcePostProcess $event
     *
     * @return bool
     */
    public function onResourcePostProcess( $event )
    {
        $name = $event->service . '.' . $event->resourcePath . '.' . strtolower( $event->request->getMethod() ) . '.post_process';
        Log::debug( 'Resource event: ' . $name );

        return $this->onPostProcess( $name, $event );
    }

    /**
     * Handle pre-process events.
     *
     * @param string                               $name
     * @param ServicePreProcess|ResourcePreProcess $event
     *
     * @return bool
     */
    public function onPreProcess( $name, $event )
    {
        $data = [
            'request'  => $event->request->toArray(),
            'resource' => $event->resource
        ];

        if ( null !== $result = $this->handleEventScript( $name, $data ) )
        {
            // request only
            $event->request->mergeFromArray( ArrayUtils::get( $result, 'request', [ ] ) );
            if ( ArrayUtils::get( $result, 'stop_propagation', false ) )
            {
                Log::info( '  * Propagation stopped by script.' );

                return false;
            }
        }

        return true;
    }

    /**
     * Handle post-process events.
     *
     * @param string                                 $name
     * @param ServicePostProcess|ResourcePostProcess $event
     *
     * @return bool
     */
    public function onPostProcess( $name, $event )
    {
        $data = [
            'request'  => $event->request->toArray(),
            'resource' => $event->resource,
            'response' => $event->response
        ];

        if ( null !== $result = $this->handleEventScript( $name, $data ) )
        {
            // response only
            if ( $event->response instanceof ServiceResponseInterface )
            {
                $event->response->mergeFromArray( ArrayUtils::get( $result, 'response', [ ] ) );
            }
            else
            {
                $event->response = ArrayUtils::get( $result, 'response', [ ] );
            }
            if ( ArrayUtils::get( $result, 'stop_propagation', false ) )
            {
                Log::info( '  * Propagation stopped by script.' );

                return false;
            }
        }

        return true;
    }

    /**
     * @param string $name
     * @param array  $event
     *
     * @return bool|null
     * @throws InternalServerErrorException
     * @throws \DreamFactory\Rave\Events\Exceptions\ScriptException
     */
    protected function handleEventScript( $name, &$event )
    {
        $model = EventScript::whereName( $name )->first();
        if ( !empty( $model ) )
        {
            $output = null;

            $result = ScriptEngineManager::runScript(
                $model->content,
                $name,
                $model->getEngineAttribute(),
                ArrayUtils::clean( $model->config ),
                $event,
                $output
            );

            //  Bail on errors...
            if ( is_array( $result ) && ( isset( $result['error'] ) || isset( $result['exception'] ) ) )
            {
                throw new InternalServerErrorException( ArrayUtils::get( $result, 'exception', ArrayUtils::get( $result, 'error' ) ) );
            }

            //  The script runner should return an array
            if ( !is_array( $result ) || !isset( $result['__tag__'] ) )
            {
                Log::error( '  * Script did not return an array: ' . print_r( $result, true ) );
            }

            if ( !empty( $output ) )
            {
                Log::info( '  * Script "' . $name . '" output:' . PHP_EOL . $output . PHP_EOL );
            }

            return $result;
        }

        return null;
    }
}
