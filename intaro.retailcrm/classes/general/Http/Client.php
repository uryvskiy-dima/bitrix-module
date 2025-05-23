<?php

/**
 * @category RetailCRM
 * @package  RetailCRM\Http
 * @author   RetailCRM <integration@retailcrm.ru>
 * @license  MIT
 * @link     http://retailcrm.ru
 * @see      http://retailcrm.ru/docs
 */

namespace RetailCrm\Http;

use Intaro\RetailCrm\Component\Constants;
use RetailCrm\Exception\CurlException;
use RetailCrm\Exception\InvalidJsonException;
use RetailCrm\Response\ApiResponse;

/**
 * Class Client
 *
 * @category RetailCRM
 * @package RetailCRM\Http
 */
class Client
{
    const METHOD_GET = 'GET';
    const METHOD_POST = 'POST';

    protected $url;
    protected $defaultParameters;
    protected $retry;

    /**
     * Client constructor.
     *
     * @param string $url               api url
     * @param array  $defaultParameters array of parameters
     *
     * @throws \InvalidArgumentException
     */
    public function __construct($url, array $defaultParameters = [])
    {
        if (false === stripos($url, 'https://')) {
            throw new \InvalidArgumentException(
                'API schema requires HTTPS protocol'
            );
        }

        $this->url = $url;
        $this->defaultParameters = $defaultParameters;
        $this->retry = 0;
        $this->curlErrors = [
            CURLE_COULDNT_RESOLVE_PROXY,
            CURLE_COULDNT_RESOLVE_HOST,
            CURLE_COULDNT_CONNECT,
            CURLE_OPERATION_TIMEOUTED,
            CURLE_HTTP_POST_ERROR,
            CURLE_SSL_CONNECT_ERROR,
            CURLE_SEND_ERROR,
            CURLE_RECV_ERROR,
        ];
    }

    /**
     * Make HTTP request
     *
     * @param string $path       request url
     * @param string $method     (default: 'GET')
     * @param array  $parameters (default: array())
     *
     * @SuppressWarnings(PHPMD.ExcessiveParameterList)
     *
     * @throws \InvalidArgumentException
     * @throws CurlException
     * @throws InvalidJsonException
     *
     * @return ApiResponse
     */
    public function makeRequest(
        $path,
        $method,
        array $parameters = []
    ) {
        $allowedMethods = [self::METHOD_GET, self::METHOD_POST];

        if (!in_array($method, $allowedMethods, false)) {
            throw new \InvalidArgumentException(
                sprintf(
                    'Method "%s" is not valid. Allowed methods are %s',
                    $method,
                    implode(', ', $allowedMethods)
                )
            );
        }

        $parameters = self::METHOD_GET === $method
            ? array_merge($this->defaultParameters, $parameters, [
                'cms_source' => 'Bitrix',
                'cms_version' => SM_VERSION,
                'php_version' => function_exists('phpversion') ? phpversion() : '',
                'module_version' => Constants::MODULE_VERSION,
            ])
            : $parameters = array_merge($this->defaultParameters, $parameters);

        $url = $this->url . $path;

        if (self::METHOD_GET === $method && count($parameters)) {
            $url .= '?' . http_build_query($parameters, '', '&');
        }

        $curlHandler = curl_init();
        curl_setopt($curlHandler, CURLOPT_URL, $url);
        curl_setopt($curlHandler, CURLOPT_RETURNTRANSFER, 1);
        curl_setopt($curlHandler, CURLOPT_FAILONERROR, false);
        curl_setopt($curlHandler, CURLOPT_SSL_VERIFYPEER, false);
        curl_setopt($curlHandler, CURLOPT_SSL_VERIFYHOST, false);
        curl_setopt($curlHandler, CURLOPT_TIMEOUT, 30);
        curl_setopt($curlHandler, CURLOPT_CONNECTTIMEOUT, 30);

        if (self::METHOD_POST === $method) {
            curl_setopt($curlHandler, CURLOPT_POST, true);
            curl_setopt($curlHandler, CURLOPT_POSTFIELDS, $parameters);
        }

        $responseBody = curl_exec($curlHandler);
        $statusCode = curl_getinfo($curlHandler, CURLINFO_HTTP_CODE);
        $errno = curl_errno($curlHandler);
        $error = curl_error($curlHandler);

        curl_close($curlHandler);

        if (
            $errno
            && in_array($errno, $this->curlErrors, false)
            && $this->retry < 3
        ) {
            $errno = null;
            $error = null;
            ++$this->retry;
            $this->makeRequest($path, $method, $parameters);
        }

        if ($errno) {
            throw new CurlException($error, $errno);
        }

        return new ApiResponse($statusCode, $responseBody);
    }

    /**
     * Retry connect
     *
     * @return int
     */
    public function getRetry()
    {
        return $this->retry;
    }
}
