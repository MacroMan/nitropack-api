<?php
namespace NitroPack\Api;

use NitroPack\HttpClient\HttpClient;

class Base {
    protected $baseUrl = 'https://api.getnitropack.com/';
    protected $siteId;

    public function __construct($siteId) {
        $this->siteId = $siteId;

        if (defined('NITROPACK_API_BASE_URL')) {
            $this->baseUrl = NITROPACK_API_BASE_URL;
        }
    }

    protected function makeRequest($path, $headers = array(), $cookies = array(), $type = 'GET', $bodyData=array(), $async = false, $verifySSL = false) {
        $http = new HttpClient($this->baseUrl . $path); // HttpClient keeps a cache of the opened connections, so creating a new instance every time is not an issue
        $http->connect_timeout = 3; // in seconds
        $http->ssl_timeout = 3; // in seconds
        $http->timeout = 30; // in seconds

        foreach ($headers as $name => $value) {
            $http->setHeader($name, $value);
        }

        foreach ($cookies as $name => $value) {
            $http->setCookie($name, $value);
        }

        if (in_array($type, array('POST', 'PUT'))) {
            $http->setPostData($bodyData);
        }

        $http->setVerifySSL($verifySSL);

        if ($async) {
            $http->fetch(true, $type, $async);
        } else {
            $retries = 1;
            while ($retries--) {
                try {
                    $http->fetch(true, $type, $async);
                    if ($http->getStatusCode() < 500) break;
                } catch (\Exception $e) {
                    if ($retries == 0) throw $e;
                }

                if ($retries > 0) {
                    usleep(500000);
                }
            }
        }

        return $http;
    }

    protected function makeRequestAsync($path, $headers = array(), $cookies = array(), $type = 'GET', $bodyData=array(), $verifySSL = false) {
        return $this->makeRequest($path, $headers, $cookies, $type, $bodyData, true, $verifySSL);
    }

    protected function throwException($httpResponse, $template) {
        try {
            $err = json_decode($httpResponse->getBody(), true);
            $errorMessage = isset($err['error']) ? $err['error'] : 'Unknown';
        } catch (\Exception $e) {
            $errorMessage = 'Unknown';
        }

        if ($errorMessage == 'Unknown') { // Fallback to known HTTP errors
            $statusCode = $httpResponse->getStatusCode();
            switch ($statusCode) {
            case ResponseStatus::BAD_REQUEST:
                $errorMessage = "Bad Request";
                break;
            case ResponseStatus::FORBIDDEN:
                $errorMessage = "Forbidden";
                break;
            case ResponseStatus::NOT_FOUND:
                $errorMessage = "Not Found";
                break;
            case ResponseStatus::RUNTIME_ERROR:
                $errorMessage = "Runtime Error";
                break;
            case ResponseStatus::SERVICE_UNAVAILABLE:
                $errorMessage = "Service Unavailable";
                break;
            default:
                $errorMessage = 'Unknown';
                break;
            }
        }

        throw new \RuntimeException(sprintf($template, $errorMessage));
    }
}
