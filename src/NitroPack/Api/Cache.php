<?php
namespace NitroPack\Api;

use NitroPack\HttpClient\HttpClientMulti;
use \NitroPack\NitroPack;

class Cache extends SignedBase {
    protected $secret;

    public function __construct($siteId, $siteSecret) {
        parent::__construct($siteId, $siteSecret);
        $this->secret = $siteSecret;
    }

    public function get($url, $userAgent, $cookies, $isAjax, $layout = 'default', $remoteAddr = NULL) {
        $path = 'cache/get/' . $this->siteId . '/' . $layout;
        $remoteAddr = $remoteAddr ? $remoteAddr : NitroPack::getRemoteAddr();
        $headers = array(
            'X-Nitro-Url' => $url,
            'X-Nitro-Visitor-Addr' => $remoteAddr,
            'User-Agent' => $userAgent
        );

        $customCachePrefix = NitroPack::getCustomCachePrefix();
        if ($customCachePrefix) {
            $headers['X-Nitro-Cache-Prefix'] = $customCachePrefix;
        }

        if ($isAjax) {
            $headers['X-Nitro-Ajax'] = 1;
        }

        if ($this->isCacheWarmupRequest()) {
            $headers['X-Nitro-Priority'] = 'LOW';
        }

        $httpResponse = $this->makeRequest($path, $headers, $cookies);
        $status = ResponseStatus::getStatus($httpResponse->getStatusCode());
        $body = $httpResponse->getBody();
        $response = new Response($status, $body);
        return $response;
    }

    public function getLastPurge() {
        $path = 'cache/getlastpurge/' . $this->siteId;

        $httpResponse = $this->makeRequest($path);
        $status = ResponseStatus::getStatus($httpResponse->getStatusCode());
        switch ($status) {
        case ResponseStatus::OK:
            return json_decode($httpResponse->getBody(), true);
        default:
            $this->throwException($httpResponse, 'Error while getting information about the last cache purge: %s');
        }
    }

    public function purge($url = NULL, $pagecacheOnly = false, $reason = NULL) {
        $path = 'cache/purge/' . $this->siteId;

        if (is_array($url)) {
            $chunkSize = min(25, (int)ceil(count($url) * 0.2)); // 20% of the number of URLs up to 25 simultaneous requests
            $requests = array();
            $cache = $this;
            $retries = new \SplObjectStorage();
            $httpMulti = new HttpClientMulti();

            $httpMulti->onSuccess(function($client) use ($path, &$url, &$requests, $httpMulti, $cache, $reason) {
                if ($url) {
                    $_url = array_shift($url);
                    $params = array();
                    $params["url"] = $_url;

                    if ($reason) {
                        $params["reason"] = $reason;
                    }

                    $httpClient = $cache->makeRequestAsync($path, array(), array(), 'POST', $params);
                    $httpMulti->push($httpClient);
                    $requests[] = $httpClient;
                }
            });

            $httpMulti->onError(function($client, $exception) use ($httpMulti, $retries) {
                if ($exception instanceof \NitroPack\SocketReadTimedOutException) {
                    return;
                }

                if (!$retries->offsetExists($client)) {
                    $clientRetries = 0;
                } else {
                    $clientRetries = $retries->offsetGet($client);
                }

                if ($clientRetries < 5) {
                    $retries->offsetSet($client, $clientRetries + 1);
                    $client->replay();
                    $httpMulti->push($client);
                }
            });

            while ($url && count($httpMulti->getClients()) < $chunkSize) {
                $_url = array_shift($url);
                $params = array();
                $params["url"] = $_url;

                if ($reason) {
                    $params["reason"] = $reason;
                }

                $httpClient = $this->makeRequestAsync($path, array(), array(), 'POST', $params);
                $httpMulti->push($httpClient);
                $requests[] = $httpClient;
            }

            $res = $httpMulti->readAll(); // This blocks untill all requests have finished

            foreach ($res[1] as $failedRequest) {
                $exception = $failedRequest[1];

                if ($exception instanceof \NitroPack\SocketReadTimedOutException) {
                    continue; // Ignore read timeouts
                } else {
                    throw $exception;
                }
            }

            foreach ($res[0] as $httpResponse) {
                $status = ResponseStatus::getStatus($httpResponse->getStatusCode());
                if ($status !== ResponseStatus::OK) {
                    $this->throwException($httpResponse, 'Error while purging cache: %s');
                }
            }

            return true;
        } else {
            $params = array();
            if ($url) {
                $params["url"] = $url;
            }

            if ($pagecacheOnly) {
                $params["pagecache_only"] = true;
            }

            if ($reason) {
                $params["reason"] = $reason;
            }

            try {
                $httpResponse = $this->makeRequest($path, array(), array(), 'POST', $params);
            } catch (\NitroPack\SocketReadTimedOutException $e) {
                return true;
            }

            $status = ResponseStatus::getStatus($httpResponse->getStatusCode());
            switch ($status) {
            case ResponseStatus::OK:
                return true;
            default:
                $this->throwException($httpResponse, 'Error while purging cache: %s');
            }
        }

        return false;
    }

    public function purgeByTag($tag, $reason = NULL) {
        $path = 'cache/purge/' . $this->siteId;

        $params = array();

        $params["tag"] = $tag;

        if ($reason) {
            $params["reason"] = $reason;
        }

        try {
            $httpResponse = $this->makeRequest($path, array(), array(), 'POST', $params);
        } catch (\NitroPack\SocketReadTimedOutException $e) {
            throw new \RuntimeException(sprintf('Timeout while purging cache by tag: %s', $tag));
        }

        $status = ResponseStatus::getStatus($httpResponse->getStatusCode());
        switch ($status) {
        case ResponseStatus::OK:
            $body = json_decode($httpResponse->getBody(), true);

            return isset($body['purged_urls']) ? $body['purged_urls'] : array();
        default:
            $this->throwException($httpResponse, 'Error while purging cache by tag: %s');
        }

        return array();
    }

    private function isCacheWarmupRequest() {
        return isset($_SERVER['HTTP_X_NITRO_WARMUP']);
    }
}
