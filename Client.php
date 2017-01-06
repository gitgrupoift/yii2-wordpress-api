<?php

namespace drsdre\WordpressApi;

use yii\helpers\Json;
use yii\authclient\OAuthToken;
use yii\base\InvalidConfigException;

/**
 * Client for communicating with a Wordpress Rest API interface (standard included from Wordpress 4.7 on).
 *
 * Authentication can either be done using:
 * - oAuth1 plugin, see drsdre\WordpressApi\Oauth1.php
 * - Basic Authentication with user/password (not recommended for live) see https://github.com/WP-API/Basic-Auth.
 * See http://v2.wp-api.org/guide/authentication/
 *
 * @see    http://v2.wp-api.org/
 *
 * @author Andre Schuurman <andre.schuurman+yii2-wordpress-api@gmail.com>
 */
class Client extends \yii\base\Object {

	/**
	 * @var string API endpoint (default production)
	 */
	public $endpoint = '';

	/**
	 * @var string API client_key
	 */
	public $client_key;

	/**
	 * @var string API client_secret
	 */
	public $client_secret;

	/**
	 * @var string API access token
	 */
	public $access_token;

	// For development only

	/**
	 * @var string email
	 */
	public $username;

	/**
	 * @var string email
	 */
	public $password;

	/**
	 * @var integer $result_total_records
	 */
	public $result_total_records;

	/**
	 * @var integer $result_total_pages
	 */
	public $result_total_pages;

	/**
	 * @var array $result_allow_methods
	 */
	public $result_allow_methods;

	/**
	 * @var int $max_retry_attempts retry attempts if possible
	 */
	public $max_retry_attempts = 5;

	/**
	 * @var int $retry_attempts retry attempts if possible
	 */
	public $retries = 0;
	/**
	 * @var yii\httpclient\Request $request
	 */
	protected $request;
	/**
	 * @var yii\httpclient\Response $response
	 */
	protected $response;
	/**
	 * @var OAuth1 $client
	 */
	private $client;

	/**
	 * Initialize object
	 *
	 * @throws InvalidConfigException
	 */
	public function init() {
		if ( empty( $this->endpoint ) ) {
			throw new InvalidConfigException( 'Specify valid endpoint.' );
		}

		if ( empty( $this->client_key ) && empty( $this->client_secret ) || empty( $this->access_token ) ) {
			if ( empty( $this->username ) || empty( $this->password ) ) {
				throw new InvalidConfigException(
					'Either specify client_key, client_secret & access_token for Oauth1 [production] ' .
					'or username and password for basic auth [development only].' );
			}

			$this->client = new yii\httpclient\Client( [
				'baseUrl'        => $this->endpoint,
				'requestConfig'  => [
					'format' => yii\httpclient\Client::FORMAT_JSON,
				],
				'responseConfig' => [
					'format' => yii\httpclient\Client::FORMAT_JSON,
				],
			] );
		} else {
			// Create your OAuthToken
			$token = new OAuthToken();
			$token->setParams( $this->access_token );

			// Start a WordpressAuth session
			$this->client = new Oauth1( [
				'accessToken'    => $token,
				'consumerKey'    => $this->client_key,
				'consumerSecret' => $this->client_secret,
				'apiBaseUrl'     => $this->endpoint,
			] );

			// Use the client apiBaseUrl as endpoint
			$this->endpoint = $this->client->apiBaseUrl;
		}
	}

	// API Interface Methods

	/**
	 * Get data using entity url
	 *
	 * @param string $entity_url
	 * @param string $context view or edit
	 * @param int|null $page
	 * @param int $page_length
	 *
	 * @return self
	 */
	public function getData(
		$entity_url,
		$context = 'view',
		$page = null,
		$page_length = 10
	) {
		// Set query data
		$data = [
			'context'  => $context,
			'per_page' => $page_length,
		];

		if ( ! is_null( $page ) ) {
			$data['page'] = $page;
		}

		$this->request =
			$this->createAuthenticatedRequest()
			     ->setMethod( 'get' )
			     ->setUrl( str_replace( $this->endpoint . '/', '', $entity_url ) )// Strip endpoint url from url param
			     ->setData( $data )
		;

		$this->executeRequest();

		return $this;
	}

	/**
	 * Update with entity url
	 *
	 * @param string $entity_url
	 * @param string $context view or edit
	 * @param array $data
	 *
	 * @return self
	 */
	public function updateData(
		$entity_url,
		$context = 'view',
		array $data
	) {
		// Set Set query data
		$data['context'] = $context;

		$this->request =
			$this->createAuthenticatedRequest()
			     ->setMethod( 'put' )
			     ->setUrl( str_replace( $this->endpoint . '/', '', $entity_url ) )// Strip endpoint url from url param
			     ->setData( $data )
		;

		$this->executeRequest();

		return $this;
	}

	/**
	 * Add data with entity url
	 *
	 * @param string $entity_url
	 * @param string $context view or edit
	 * @param array $data
	 *
	 * @return self
	 */
	public function addData(
		$entity_url,
		$context = 'view',
		array $data
	) {
		// Set context
		$data['context'] = $context;

		$this->request =
			$this->createAuthenticatedRequest()
			     ->setMethod( 'post' )
			     ->setUrl( str_replace( $this->endpoint . '/', '', $entity_url ) )// Strip endpoint url from url param
			     ->setData( $data )
		;

		$this->executeRequest();

		return $this;
	}

	/**
	 * Delete data with entity url
	 *
	 * @param string $entity_url
	 * @param string $context
	 * @param array $data
	 *
	 * @return self
	 */
	public function deleteData(
		$entity_url,
		$context = 'view',
		array $data
	) {
		// Set context
		$data['context'] = $context;

		$this->request =
			$this->createAuthenticatedRequest()
			     ->setMethod( 'delete' )
			     ->setUrl( str_replace( $this->endpoint . '/', '', $entity_url ) ) // Strip endpoint url from url param
			     ->setData( $data )
		;

		$this->executeRequest();

		return $this;
	}

	// API Data response methods

	/**
	 * Return content as array
	 *
	 * @return array
	 * @throws Exception
	 */
	public function asArray() {
		if ( isset( $this->response->content ) ) {
			return Json::decode( $this->response->content, true );
		}
	}

	/**
	 * Return content as object
	 *
	 * @return \stdClass
	 * @throws Exception
	 */
	public function asObject() {
		if ( isset( $this->response->content ) ) {
			return Json::decode( $this->response->content, false );
		}
	}

	/**
	 * Get the raw content object
	 *
	 * @return yii\httpclient\Response
	 */
	public function asRaw() {
		return $this->response->content;
	}

	/**
	 * Create authenticated request
	 *
	 * @return yii\httpclient\Request
	 */
	protected function createAuthenticatedRequest() {
		if ( is_a( $this->client, 'drsdre\WordpressApi\Oauth1' ) ) {
			// oAuth1 request
			$request = $this->client
				->createApiRequest();
		} else {
			// Basic authentication request
			$request = $this->client
				->createRequest();

			// Use Basic Authentication
			$request->setHeaders( [
				'Authorization' => 'Basic ' . base64_encode( $this->username . ':' . $this->password ),
			] );
			/*->addHeaders([
					'X-Auth-Key' => $this->key,
					'X-Auth-Email' => $this->email,
					'content-type' => 'application/json'
				]);
			*/
		}

		return $request;
	}

	/**
	 * Parse API response
	 *
	 * @throws Exception on failure
	 * @throws \yii\httpclient\Exception
	 * @throws \Exception
	 * @return \stdClass
	 */
	protected function executeRequest() {

		$this->retries = 0;

		do {
			try {
				// Do request
				$this->response = $this->request->send();

				// Check for response status code
				if ( ! $this->response->isOk ) {
					switch ( $this->response->statusCode ) {
						case 304:
							throw new Exception(
								'Not Modified.'
							);
						case 400:
							throw new Exception(
								'Bad Request: request ' . $this->request->getFullUrl() . ' was invalid.',
								Exception::FAIL
							);
						case 401:
							// Handle {"code":"json_oauth1_nonce_already_used","message":"Invalid nonce - nonce has already been used","data":{"status":401}}
							$result_content = Json::decode( $this->response->content, false );
							if ( isset( $result_content->code ) && $result_content->code == 'json_oauth1_nonce_already_used' ) {
								throw new Exception(
									( isset( $result_content->message ) ? $result_content->message : $this->response->content ) .
									' ' . $this->request->getFullUrl(),
									Exception::RETRY
								);
							} else {
								throw new Exception(
									'Unauthorized: user does not have permission to access ' . $this->request->getFullUrl(),
									Exception::FAIL
								);
							}
						case 403:
							throw new Exception(
								'Forbidden: request not authenticated accessing ' . $this->request->getFullUrl(),
								Exception::FAIL
							);
						case 404:
							throw new Exception(
								'No data found: route ' . $this->request->getFullUrl() . 'does not exist.',
								Exception::FAIL
							);
						case 405:
							throw new Exception(
								'Method Not Allowed: incorrect HTTP method ' . $this->request->getMethod() . ' provided.',
								Exception::FAIL
							);
						case 415:
							throw new Exception(
								'Unsupported Media Type (incorrect HTTP method ' . $this->request->getMethod() . ' provided).',
								Exception::FAIL
							);
						case 429:
							throw new Exception(
								'Too many requests: client is rate limited.',
								Exception::WAIT_RETRY
							);
						case 500:
							$content = $this->asArray();
							// Check if specific error code have been returned
							if ( isset( $content['code'] ) && $content['code'] == 'term_exists' ) {
								throw new Exception(
									isset( $content['message'] ) ? $content['message'] : 'Internal server error.',
									Exception::ITEM_EXISTS
								);
							} else {
								throw new Exception(
									'Internal server error.',
									Exception::FAIL
								);
							}

						case 502:
							throw new Exception(
								'Bad Gateway error: server has an issue.',
								Exception::RETRY
							);
						default:
							throw new Exception(
								'Unknown code ' . $this->response->statusCode . ' for URL ' . $this->request->getFullUrl()
							);
					}
				}
				$request_success = true;
			} catch ( Exception $e ) {
				// Retry if exception can be retried
				if ( $e->getCode() == Exception::RETRY ) {
					//
					$request_success = false;
					$this->retries ++;
					if ( $this->retries <= $this->max_retry_attempts ) {
						throw $e;
					}
				} else {
					// Retrow Exception
					throw $e;
				}
			} catch ( \yii\httpclient\Exception $e ) {
				// Retry on 'if fopen(): SSL: Connection reset by peer'
				if ( $e->getCode() == 2 && $this->retries <= $this->max_retry_attempts ) {
					$request_success = false;
					$this->retries ++;
					if ( $this->retries <= $this->max_retry_attempts ) {
						throw $e;
					}
				} else {
					// Retrow Exception
					throw $e;
				}
			}

			// Retry until request is successful or max attempts has been reached
		} while ( $request_success === false && $this->retries <= $this->max_retry_attempts );

		// Update result information (for paging)
		$this->result_total_records = $this->response->getHeaders()['X-WP-Total'];
		$this->result_total_pages   = $this->response->getHeaders()['X-WP-TotalPages'];
		$this->result_allow_methods = $this->response->getHeaders()['allow'];
	}
}