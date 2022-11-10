<?php

namespace Drupal\crossref_api_client;

use Drupal\Core\Cache\CacheBackendInterface;
use Drupal\Core\Config\ConfigFactory;
use Drupal\Core\Http\ClientFactory;
use Drupal\Core\Messenger\Messenger;
use GuzzleHttp\Exception\ClientException;

/**
 * Provides methods for connecting to the Crossref API.
 */
class Client {

  /**
   * The base URI of the Crossref API.
   */
  const BASE_URI = 'https://api.crossref.org';

  /**
   * @var \Guzzlehttp\Client $guzzleClient
   */
  protected $guzzleClient;

  /**
   * @var \Drupal\Core\Config\ImmutableConfig $config
   */
  protected $config;

  /**
   * @var \Drupal\Core\Cache\CacheBackendInterface $cache
   */
  protected $cache;

  /**
   * @var \Drupal\Core\Messenger\Messenger $messenger
   */
  protected $messenger;

  /**
   * Constructs a new Client object.
   *
   * @param \Drupal\Core\Http\ClientFactory $client_factory
   *   The Guzzle HTTP Client factory service.
   * @param \Drupal\Core\Config\ConfigFactory $config_factory
   *   The config.factory service.
   * @param \Drupal\Core\Cache\CacheBackendInterface $cache
   *   The cache.crossref_responses cache bin.
   * @param \Drupal\Core\Messenger\Messenger $messenger
   *   The Drupal messenger service.
   */
  public function __construct(ClientFactory $client_factory, ConfigFactory $config_factory, CacheBackendInterface $cache, Messenger $messenger) {
    $this->guzzleClient = $client_factory->fromOptions(['base_uri' => self::BASE_URI]);
    $this->config = $config_factory->get('crossref_api_client.settings');
    $this->cache = $cache;
    $this->messenger = $messenger;
  }

  /**
   * Sends a request to the API.
   *
   * Crossref recommends that all parameters be URL-encoded, especially DOIs
   * which frequently have invalid characters. All values in the $parameters
   * array will be URL-encoded. Calling functions should allow this method to
   * replace parameters in the endpoint path rather than doing it themselves.
   * This way parameters can be URL-encoded or have other transformations
   * performed on them.
   *
   * Responses are cached for one day.
   *
   * @param string $ndpoint
   *   The endpoint path to be queried. The endpoint should be written exactly
   *   as shown in the API documentation, for example '/works/{doi}', including
   *   a leading slash.
   * @param string[] $parameters
   *   An array of parameters to be replaced in the path. Array keys should
   *   exactly match the string to be replaced in the path, for example if the
   *   path is "/works/{doi}", then the $parameters array should contain
   *   "['{doi}' => $doi]".
   *
   * @return string
   *   The body of the response.
   *
   * @throws GuzzleHttp\Exception\TransferException
   */
  protected function request(string $endpoint, $parameters = []) {
    foreach ($parameters as $key => $param) {
      $parameters[$key] = urlencode($param);
    }
    $request_path = strtr($endpoint, $parameters);

    if ($cache = $this->cache->get($request_path)) {
      return $cache->data;
    }

    $options = [];
    if ($email = $this->config->get('email')) {
      $options['query']['mailto'] = $email;
    }
    if ($token = $this->config->get('token')) {
      $options['headers']['Crossref-Plus-API-Token'] = 'Bearer ' . $token;
    }

    $response = $this->guzzleClient->get($request_path, $options);
    $body = $response->getBody()->getContents();
    $this->cache->set($request_path, $body, time() + 86400);
    return $body;
  }

  /**
   * Makes a request to the /works/{doi} endpoint.
   *
   * @param string $doi
   *   The DOI to be queried.
   *
   * @return array
   *   The decoded JSON response from the API.
   *
   * @throws \GuzzleHttp\Exception\ClientException
   *   Re-throws the Guzzle exception so that calling code can react to it.
   */
  public function worksDoi(string $doi) {
    try {
      $response = $this->request('/works/{doi}', ['{doi}' => $doi]);
    }
    catch (ClientException $e) {
      $this->messenger->addWarning('The requested DOI could not be found in Crossref.');
      throw $e;
    }
    return json_decode($response);
  }

}

