<?php
/*!
* Hybridauth
* https://hybridauth.github.io | https://github.com/hybridauth/hybridauth
*  (c) 2017 Hybridauth authors | https://hybridauth.github.io/license.html
*/

namespace Hybridauth;

use Hybridauth\Exception\InvalidArgumentException;
use Hybridauth\Exception\UnexpectedValueException;
use Hybridauth\Exception\UnexpectedApiResponseException;
use Hybridauth\Storage\StorageInterface;
use Hybridauth\Storage\Session;
use Hybridauth\Logger\LoggerInterface;
use Hybridauth\Logger\Logger;
use Hybridauth\HttpClient\HttpClientInterface;
use Hybridauth\HttpClient\Curl as HttpClient;
use Hybridauth\Deprecated\DeprecatedHybridauthTrait;

/**
 * Hybridauth\Hybridauth
 *
 * For ease of use of multiple providers, Hybridauth implements the class Hybridauth\Hybridauth,
 * a sort of factory/façade which acts as an unified interface or entry point, and it expects a
 * configuration array containing the list of providers you want to use, their respective credentials
 * and authorized callback.
 */
class Hybridauth
{
    /**
    * Hybridauth config.
    *
    * @var array
    */
    protected $config;

    /**
    * Storage.
    *
    * @var StorageInterface
    */
    protected $storage;

    /**
    * HttpClient.
    *
    * @var HttpClientInterface
    */
    protected $httpClient;

    /**
    * Logger.
    *
    * @var LoggerInterface
    */
    protected $logger;

    /**
    * @param array|string        $config     Array with configuration or Path to PHP file that will return array
    * @param HttpClientInterface $httpClient
    * @param StorageInterface    $storage
    * @param LoggerInterface     $logger
    *
    * @throws InvalidArgumentException
    */
    public function __construct(
        $config = [],
        HttpClientInterface $httpClient = null,
        StorageInterface $storage = null,
        LoggerInterface $logger = null
    ) {
        if (is_string($config) && file_exists($config)) {
            $config = include $config;
        } elseif (! is_array($config)) {
            throw new InvalidArgumentException('Hybriauth config does not exist on the given path.');
        }

        $this->config = $config + [
            'debug_mode'   => Logger::NONE,
            'debug_file'   => '',
            'curl_options' => null,
            'providers'    => []
        ];
        $this->storage = $storage;
        $this->logger = $logger;
        $this->httpClient = $httpClient;
    }

    /**
    * Instantiate the given provider and authentication or authorization protocol.
    *
    * If user not authenticated yet, the user will be redirected to the authorization Service
    * to authorize the application.
    *
    * @param string $name adapter's name (case insensitive)
    *
    * @return Adapter\AdapterInterface
    */
    public function authenticate($name)
    {
        $this->logger->info(sprintf('Hybridauth::authenticate(%s)', $name));

        $adapter = $this->getAdapter($name);

        $adapter->authenticate();

        return $adapter;
    }

    /**
    * Returns a new instance of a provider's adapter by name
    *
    * @param string $name adapter's name (case insensitive)
    *
    * @return Adapter\AdapterInterface
    */
    public function getAdapter($name)
    {
        $config = $this->getProviderConfig($name);

        $adapter = "Hybridauth\\Provider\\$name";

        return new $adapter($config, $this->httpClient, $this->storage, $this->logger);
    }

    /**
    * Get provider config by name.
    *
    * @param string $name adapter's name (case insensitive)
    *
    * @throws Exception\UnexpectedValueException
    * @throws Exception\InvalidArgumentException
    *
    * @return array
    */
    protected function getProviderConfig($name)
    {
        $name = strtolower($name);

        $providersConfig = array_change_key_case($this->config['providers'], CASE_LOWER);

        if (! isset($providersConfig[$name])) {
            throw new InvalidArgumentException('Unknown Provider.');
        }

        if (! $providersConfig[$name]['enabled']) {
            throw new UnexpectedValueException('Disabled Provider.');
        }

        $config = $providersConfig[$name];

        if (! isset($config['callback']) && isset($this->config['callback'])) {
            $config['callback'] = $this->config['callback'];
        }

        return $config;
    }

    /**
    * Returns a boolean of whether the user is connected with a provider
    *
    * @param string $name adapter's name (case insensitive)
    *
    * @return boolean
    */
    public static function isConnectedWith($name)
    {
        return $this->getAdapter($name)->isConnected();
    }

    /**
    * Returns a list of currently connected adapters names
    *
    * @return array
    */
    public static function getConnectedProviders()
    {
        $providers = [];

        foreach ($this->config['providers'] as $name => $_) {
            if ($this->isConnectedWith($name)) {
                $providers[] = $name;
            }
        }

        return $providers;
    }

    /**
    * Returns a list of new instances of currently connected adapters
    *
    * @return array
    */
    public static function getConnectedAdapters()
    {
        $adapters = [];

        foreach ($this->config['providers'] as $name => $_) {
            if ($this->isConnectedWith($name)) {
                $adapters[$name] = $this->getAdapter($name);
            }
        }

        return $adapters;
    }

    /**
    * Disconnect all currently connected adapters at once
    */
    public static function disconnectAllAdapters()
    {
        foreach ($this->config['providers'] as $name => $_) {
            $adapter = $this->getAdapter($name);

            if ($adapter->isConnected()) {
                $adapter->disconnect();
            }
        }
    }
}
