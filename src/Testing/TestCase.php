<?php
namespace DreamFactory\Core\Testing;

use DreamFactory\Core\Enums\DataFormats;
use DreamFactory\Core\Exceptions\InternalServerErrorException;
use DreamFactory\Core\Models\Service;
use DreamFactory\Core\Utility\ServiceHandler;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Foundation\Testing\TestCase as LaravelTestCase;
use Artisan;
use DreamFactory\Core\Services\BaseRestService;

class TestCase extends LaravelTestCase
{
    /**
     * A flag to make sure that the stage() method gets to run one time only.
     *
     * @var bool
     */
    protected static $staged = false;

    /** @var string resource array wrapper */
    protected static $wrapper = null;

    /**
     * Provide the service id/name that you want to run
     * the test cases on.
     *
     * @var mixed null
     */
    protected $serviceId = null;

    /** @var BaseRestService null */
    protected $service = null;

    /**
     * Runs before every test class.
     */
    public static function setupBeforeClass()
    {
        echo "\n------------------------------------------------------------------------------\n";
        echo "Running test: " . get_called_class() . "\n";
        echo "------------------------------------------------------------------------------\n\n";

        static::$wrapper = \Config::get('df.resources_wrapper');
    }

    /**
     * Runs before every test.
     */
    public function setUp()
    {
        parent::setUp();

        Model::unguard(false);

        if (false === static::$staged) {
            $this->stage();
            static::$staged = true;
        }

        $this->setService();
    }

    /**
     * Sets up the service based on
     */
    protected function setService()
    {
        if (!empty($this->serviceId)) {
            if (is_numeric($this->serviceId)) {
                $this->service = static::getServiceById($this->serviceId);
            } else {
                $this->service = static::getService($this->serviceId);
            }
        }
    }

    /**
     * This method is used for staging the overall
     * test environment. Which usually covers things like
     * running database migrations and seeders.
     *
     * In order to override and run this method on a child
     * class, you must set the static::$staged property to
     * false in the respective child class.
     */
    public function stage()
    {
        Artisan::call('migrate');
        Artisan::call('db:seed');
    }

    /**
     * Creates the application.
     *
     * @return \Illuminate\Foundation\Application
     */
    public function createApplication()
    {
        $app = require __DIR__ . '/../../../../../bootstrap/app.php';

        $app->make('Illuminate\Contracts\Console\Kernel')->bootstrap();

        return $app;
    }

    /**
     * @param $verb
     * @param $url
     * @param $payload
     *
     * @return \Illuminate\Http\Response
     */
    protected function callWithPayload($verb, $url, $payload)
    {
        $rs = $this->call($verb, $url, [], [], [], [], $payload);

        return $rs;
    }

    /**
     * Checks to see if a service already exists
     *
     * @param string $serviceName
     *
     * @return bool
     */
    protected function serviceExists($serviceName)
    {
        return Service::whereName($serviceName)->exists();
    }

    /**
     * @param $name
     *
     * @return BaseRestService
     */
    public static function getService($name)
    {
        return ServiceHandler::getService($name);
    }

    /**
     * @param $id
     *
     * @return mixed
     */
    public static function getServiceById($id)
    {
        return ServiceHandler::getServiceById($id);
    }

    /**
     * @param       $verb
     * @param       $resource
     * @param array $query
     * @param null  $payload
     * @param array $header
     *
     * @return \DreamFactory\Core\Contracts\ServiceResponseInterface
     */
    protected function makeRequest($verb, $resource = null, $query = [], $payload = null, $header = [])
    {
        $request = new TestServiceRequest($verb, $query, $header);
        $request->setApiVersion('v1');

        if (!empty($payload)) {
            if (is_array($payload)) {
                $request->setContent($payload);
            } else {
                $request->setContent($payload, DataFormats::JSON);
            }
        }

        return $this->handleRequest($request, $resource);
    }

    /**
     * @param TestServiceRequest $request
     * @param null               $resource
     *
     * @return \DreamFactory\Core\Contracts\ServiceResponseInterface
     * @throws InternalServerErrorException
     * @throws \DreamFactory\Core\Exceptions\BadRequestException
     */
    protected function handleRequest(TestServiceRequest $request, $resource = null)
    {
        if (empty($this->service)) {
            throw new InternalServerErrorException('No service is setup to process request on. Please set the serviceId. It can be an Id or Name.');
        }

        return $this->service->handleRequest($request, $resource);
    }
}
