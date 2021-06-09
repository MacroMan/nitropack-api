<?php
namespace NitroPack;

use NitroPack\Exceptions\EmptyConfigException;
use NitroPack\Exceptions\NoConfigException;

class NitroPack {
    const VERSION = '0.19.2';
    const PAGECACHE_LOCK_EXPIRATION_TIME = 300; // in seconds
    private $dataDir;
    private $cachePath = array('data', 'pagecache');
    private $configFile = array('data', 'config.json');
    private $pageCacheLockFile = array('data', 'get_cache.lock');
    private $cachePathSuffix = NULL;
    private $configTTL; // In seconds

    private $siteId;
    private $siteSecret;
    private $userAgent; // Defaults to desktop Chrome

    private $url;
    private $config;
    private $device;
    public $pageCache; // TODO: consider better ways of protecting/providing this outside the class
    private $api;

    private static $cachePrefixes = array();
    private static $cookieFilters = array();

    public static function getRemoteAddr() {
        // IP check order is: CloudFlare, Proxy, Client IP
        $ipKeys = ["HTTP_X_FORWARDED_FOR", "HTTP_CF_CONNECTING_IP", "REMOTE_ADDR"];
        foreach ($ipKeys as $key) {
            if (!empty($_SERVER[$key])) {
                return $_SERVER[$key];
            }
        }

        return NULL;
    }

    public static function getCookies() {
        $cookies = array();

        foreach ($_COOKIE as $name=>$value) {
            if (is_array($value)) {
                foreach ($value as $k=>$v) {
                    $key = $name . "[$k]";
                    $cookies[$key] = $v;
                }
            } else {
                $cookies[$name] = $value;
            }
        }

        foreach (self::$cookieFilters as $cookieFilter) {
            call_user_func_array($cookieFilter, array(&$cookies));
        }

        return $cookies;
    }

    public static function addCookieFilter($callback) {
        if (is_callable($callback)) {
            if (!in_array($callback, self::$cookieFilters, true)) {
                self::$cookieFilters[] = $callback;
            }
        } else {
            throw new \RuntimeException("Non-callable callback passed to " . __FUNCTION__);
        }
    }

    public static function addCustomCachePrefix($prefix = "") {
        self::$cachePrefixes[] = $prefix;
    }

    public static function getCustomCachePrefix() {
        return implode("-", self::$cachePrefixes);
    }

    public static function wildcardToRegex($str, $delim = "/") {
        return implode(".*?", array_map(function($input) use ($delim) { return preg_quote($input, $delim); }, explode("*", $str)));
    }

    public function __construct($siteId, $siteSecret, $userAgent = NULL, $url = NULL, $dataDir = __DIR__) {
        $this->configTTL = 3600;
        $this->siteId = $siteId;
        $this->siteSecret = $siteSecret;
        $this->dataDir = $dataDir;
        if (empty($userAgent)) {
            $this->userAgent = !empty($_SERVER['HTTP_USER_AGENT']) ? $_SERVER['HTTP_USER_AGENT'] : 'Mozilla/5.0 (Macintosh; Intel Mac OS X 10_13_5) AppleWebKit/537.36 (KHTML, like Gecko) Chrome/67.0.3396.99 Safari/537.36';
        } else {
            $this->userAgent = $userAgent;
        }

        $this->loadConfig($siteId, $siteSecret);
        $this->device = new Device($this->userAgent);

        if(empty($url)) {
            $host = !empty($_SERVER['HTTP_HOST']) ? $_SERVER['HTTP_HOST'] : "example.com";
            $uri = !empty($_SERVER['REQUEST_URI']) ? $_SERVER['REQUEST_URI'] : "/";
            $url = $this->getScheme() . $host . $uri;
        }

        $queryStr = parse_url($url, PHP_URL_QUERY);

        if ($queryStr) {
            parse_str($queryStr, $queryParams);

            if ($queryParams) {
                if ($this->config->IgnoredParams) {
                    foreach ($this->config->IgnoredParams as $ignorePattern) {
                        $regex = "/^" . self::wildcardToRegex($ignorePattern) . "$/";
                        foreach($queryParams as $paramName => $paramValue) {
                            if (preg_match($regex, $paramName)) {
                                unset($queryParams[$paramName]);
                            }
                        }
                    }
                }

                ksort($queryParams);
                $url = str_replace($queryStr, http_build_query($queryParams), $url);
            }
        }

        $urlInfo = new Url\Url($url);
        $this->url = $urlInfo->getNormalized();

        $this->pageCache = new Pagecache($this->url, $this->userAgent, $this->supportedCookiesFilter(self::getCookies()), $this->config->PageCache->SupportedCookies, $this->isAJAXRequest());
        if ($this->isAJAXRequest() && $this->isAllowedAJAXUrl($this->url) && !empty($_SERVER["HTTP_REFERER"])) {
            $refererInfo = new Url\Url($_SERVER["HTTP_REFERER"]);
            $this->pageCache->setReferer($refererInfo->getNormalized());
        }

        $this->api = new Api($this->siteId, $siteSecret);

        $this->pageCache->setDataDir($this->getCacheDir());

        $this->useCompression = false;
    }

    public function supportedCookiesFilter($cookies) {
        $supportedCookies = array();
        foreach ($cookies as $cookieName=>$cookieValue) {
            foreach ($this->config->PageCache->SupportedCookies as $cookie) {
                if (preg_match('/^' . self::wildcardToRegex($cookie) . '$/', $cookieName)) {
                    $supportedCookies[$cookieName] = $cookieValue;
                }
            }
        }
        return $supportedCookies;
    }

    public function tagUrl($url, $tag) {
        if ($this->isAllowedUrl($url)) {
            return $this->api->tagUrl($url, $tag);
        } else {
            return false;
        }
    }

    public function setCachePathSuffix($suffix) {
        $this->cachePathSuffix = $suffix;
        $this->pageCache->setDataDir($this->getCacheDir());
    }

    public function enableCompression() {
        $this->pageCache->enableCompression();
    }

    public function disableCompression() {
        $this->pageCache->disableCompression();
    }

    public function getUrl() {
        return $this->url;
    }

    public function getApi() {
        return $this->api;
    }

    public function getSiteId() {
        return $this->siteId;
    }

    public function getConfig() {
        return $this->config;
    }

    public function getCacheDir() {
        $cachePath = $this->cachePath;
        array_unshift($cachePath, $this->dataDir);
        if ($this->cachePathSuffix) {
            $cachePath[] = $this->cachePathSuffix;
        }
        return Filesystem::getOsPath($cachePath);
    }

    public function hasCache($layout = 'default') {
        if ($this->hasLocalCache()) {
            return true;
        } else {
            return $this->hasRemoteCache($layout);
        }
    }

    public function hasLocalCache($checkIfRequestIsAllowed = true) {
        if (!$this->isAllowedUrl($this->url) || ($checkIfRequestIsAllowed && !$this->isAllowedRequest())) return false;
        $cacheRevision = !empty($this->config->RevisionHash) ? $this->config->RevisionHash : NULL;

        if (!$this->pageCache->getUseInvalidated()) {
            $ttl = $this->config->PageCache->ExpireTime;
        } else {
            $ttl = $this->config->PageCache->StaleExpireTime;
        }

        return $this->pageCache->hasCache() && !$this->pageCache->hasExpired($ttl, $cacheRevision);
    }

    public function hasRemoteCache($layout, $checkIfRequestIsAllowed = true) {
        if (!$this->isAllowedUrl($this->url) || ($checkIfRequestIsAllowed && !$this->isAllowedRequest()) || $this->isPageCacheLocked()) return false;
        $resp = $this->api->getCache($this->url, $this->userAgent, $this->supportedCookiesFilter(self::getCookies()), $this->isAJAXRequest(), $layout);

        if ($resp->getStatus() == Api\ResponseStatus::OK) {// We have cache response

            // Check for invalidated cache and delete it if such is found
            $this->pageCache->useInvalidated(true);
            if ($this->pageCache->hasCache()) {
                $path = $this->pageCache->getCachefilePath();
                Filesystem::deleteFile($path);
                Filesystem::deleteFile($path . ".gz");
                Filesystem::deleteFile($path . ".stale");
                Filesystem::deleteFile($path . ".stale.gz");
                if (Filesystem::isDirEmpty(dirname($path))) {
                    Filesystem::deleteDir(dirname($path));
                }
            }
            $this->pageCache->useInvalidated(false);
            // End of check

            $this->pageCache->setContent($resp->getBody());
            return true;
        } else {
            // The goal is to serve cache at all times even when it is slightly outdated. This approach should be ok because new cache has been requested and it should be ready soon
            if ($this->pageCache->hasCache()) {
                return true;
            } else {
                // Check for invalidated cache
                $this->pageCache->useInvalidated(true);
                if ($this->hasLocalCache(false)) {
                    return true;
                } else {
                    $this->pageCache->useInvalidated(false);
                }
            }

            return false;
        }
    }

    public function invalidateCache($url = NULL, $tag = NULL, $reason = NULL) {
        return $this->purgeCache($url, $tag, PurgeType::INVALIDATE | PurgeType::PAGECACHE_ONLY, $reason);
    }

    public function clearPageCache($reason = NULL) {
        return $this->purgeCache(NULL, NULL, PurgeType::PAGECACHE_ONLY, $reason);
    }

    public function purgeCache($url = NULL, $tag = NULL, $purgeType = PurgeType::COMPLETE, $reason = NULL) {
        @set_time_limit(0);
        $this->lockPageCache(); // Set the page cache lock, expires after self::PAGECACHE_LOCK_EXPIRATION_TIME seconds

        try {
            $invalidate = !!($purgeType & PurgeType::INVALIDATE);
            $pageCacheOnly = !!($purgeType & PurgeType::PAGECACHE_ONLY);

            if ($url || $tag) {
                $localResult = true;
                $apiResult = true;
                if ($url) {
                    if (is_array($url)) {
                        foreach ($url as $urlLink) {
                            if ($invalidate) {
                                $localResult &= $this->invalidateLocalUrlCache($urlLink);
                            } else {
                                $localResult &= $this->purgeLocalUrlCache($urlLink);
                            }
                        }
                        $apiResult &= $this->api->purgeCache($url, false, $reason);
                    } else {
                        if ($invalidate) {
                            $localResult &= $this->invalidateLocalUrlCache($url);
                        } else {
                            $localResult &= $this->purgeLocalUrlCache($url);
                        }
                        $apiResult &= $this->api->purgeCache($url, false, $reason);
                    }
                }

                if ($tag) {
                    $attemptsLeft = 10;
                    $purgedUrls = array();
                    do {
                        $hadError = false;

                        try {
                            $purgedUrls = $this->api->purgeCacheByTag($tag, $reason);

                            foreach ($purgedUrls as $url) {
                                if ($invalidate) {
                                    $localResult &= $this->invalidateLocalUrlCache($url);
                                } else {
                                    $localResult &= $this->purgeLocalUrlCache($url);
                                }
                            }
                        } catch (\Exception $e) {
                            $hadError = true;
                            $attemptsLeft--;
                            sleep(3);
                        }
                    } while (($hadError && $attemptsLeft > 0) || count($purgedUrls) > 0);
                }
            } else {
                if ($invalidate) {
                    $localResult = $this->invalidateLocalCache();
                    $apiResult = $this->api->purgeCache(NULL, $pageCacheOnly, $reason); // delete only page cache
                } else {
                    $staleCacheDir = $this->purgeLocalCache(true);

                    // Call the cache purge method
                    $apiResult = $this->api->purgeCache(NULL, $pageCacheOnly, $reason);

                    // Finally, delete the files of the stale directory
                    Filesystem::deleteDir($staleCacheDir);

                    $localResult = true; // We do not care if $staleCacheDir was not deleted successfully
                }
            }

            $this->unlockPageCache(); // Purge cache is done, we can now unlock
        } catch (\Exception $e) {
            $this->unlockPageCache(); // Purge cache had an error, so just unlock

            throw $e;
        }

        return $apiResult && $localResult;
    }

    public function purgeLocalCache($quick = false) {
        $staleCacheDir = $this->getCacheDir() . '.stale.' . md5(microtime(true));
        $this->purgeProxyCache();
        $this->config->LastFetch = 0;
        $this->setConfig($this->config);

        // Rename cache files directory
        if (Filesystem::fileExists($this->getCacheDir()) && !Filesystem::rename($this->getCacheDir(), $staleCacheDir)) {
            throw new \Exception("No write permissions to rename the directory: " . $this->getCacheDir());
        }

        // Create a new empty directory
        if (!Filesystem::createDir($this->getCacheDir())) {
            throw new \Exception("No write permissions to create the directory: " . $this->getCacheDir());
        }

        if (!$quick) {
            // Finally, delete the files of the stale directory
            Filesystem::deleteDir($staleCacheDir);
        }

        return $staleCacheDir;
    }

    public function fetchConfig() {
        $fetcher = new Api\RemoteConfigFetcher($this->siteId, $this->siteSecret);
        $configContents = $fetcher->get(); // this can throw in case of http errors or validation failures
        $config = json_decode($configContents);
        if ($config) {
            $config->SDKVersion = NitroPack::VERSION;
            $config->LastFetch = time();

            $this->setConfig($config);
            return true;
        } else {
            throw new EmptyConfigException("Config response was empty");
        }
    }

    public function setConfig($config) {
        $file = $this->getConfigFile();
        if (Filesystem::createDir(dirname($file))) {
            if (Filesystem::filePutContents($file, json_encode($config))) {
                return true;
            } else {
                throw new StorageException(sprintf("Config file %s cannot be saved to disk", $file));
            }
        } else {
            throw new StorageException(sprintf("Storage directory %s cannot be created", dirname($file)));
        }
    }

    public function purgeProxyCache($url = NULL) {
        if (!empty($this->config->CacheIntegrations)) {
            if (!empty($this->config->CacheIntegrations->Varnish)) {
                if ($url) {
                    $varnish = new Integrations\Varnish($this->config->CacheIntegrations->Varnish->Servers, $this->config->CacheIntegrations->Varnish->PurgeSingleMethod);
                    $varnish->purge($url);
                } else {
                    $varnish = new Integrations\Varnish($this->config->CacheIntegrations->Varnish->Servers, $this->config->CacheIntegrations->Varnish->PurgeAllMethod);
                    $varnish->purge($this->config->CacheIntegrations->Varnish->PurgeAllUrl);
                }
            }

            //if (!empty($this->config->CacheIntegrations->LiteSpeed) && php_sapi_name() !== "cli") {
            //    if ($url) {
            //        $urlObj = new Url\Url($url);
            //        $liteSpeedPath = $urlObj->getPath();
            //        if ($urlObj->getQuery()) {
            //            $liteSpeedPath .= "?" . $urlObj->getQuery();
            //        }
            //        header("X-LiteSpeed-Purge: $liteSpeedPath", false);
            //    } else {
            //        header("X-LiteSpeed-Purge: *", false);
            //    }
            //}
        }
    }

    public function isAllowedUrl($url) {
        if (strpos($url, 'sucurianticache=') !== false) return false;

        if ($this->config->EnabledURLs->Status) {
            if (!empty($this->config->EnabledURLs->URLs)) {
                foreach ($this->config->EnabledURLs->URLs as $enabledUrl) {
                    $enabledUrlModified = preg_replace("/^(https?:)?\/\//", "*", $enabledUrl);

                    if (preg_match('/^' . self::wildcardToRegex($enabledUrlModified) . '$/', $url)) {
                        return true;
                    }
                }

                return false;
            }
        } else if ($this->config->DisabledURLs->Status) {
            if (!empty($this->config->DisabledURLs->URLs)) {
                foreach ($this->config->DisabledURLs->URLs as $disabledUrl) {
                    $disabledUrlModified = preg_replace("/^(https?:)?\/\//", "*", $disabledUrl);

                    if (preg_match('/^' . self::wildcardToRegex($disabledUrlModified) . '$/', $url)) {
                        return false; // don't cache disabled URLs
                    }
                }
            }
        }

        return true;
    }

    public function isAllowedRequest($allowServiceRequests = false) {
        if (($this->isAJAXRequest() && !$this->isAllowedAJAX()) || !($this->isRequestMethod("GET") || $this->isRequestMethod("HEAD"))) {// TODO: Allow URLs which match a pattern in the AJAX URL whitelist
            return false; // don't cache ajax or not GET requests
        }

        if (!$allowServiceRequests && isset($_SERVER["HTTP_X_NITROPACK_REQUEST"])) { // Skip requests coming from NitroPack
            return false;
        }

        if (isset($_GET["nonitro"])) { // Skip requests having ?nonitro
            return false;
        }

        if (!$this->isAllowedBrowser()) {
            return false;
        }

        if (isset($this->config->ExcludedCookies) && $this->config->ExcludedCookies->Status) {
            foreach ($this->config->ExcludedCookies->Cookies as $cookieExclude) {
                foreach (self::getCookies() as $cookieName => $cookieValue) {
                    if (preg_match('/^' . self::wildcardToRegex($cookieExclude->name) . '$/', $cookieName)) {
                        if (count($cookieExclude->values) == 0) {
                            return false; // no excluded cookie values entered, reject all values
                        } else {
                            foreach ($cookieExclude->values as $val) {
                                if (preg_match('/^' . self::wildcardToRegex($val) . '$/', $cookieValue)) {
                                    return false;
                                }
                            }
                        }
                    }
                }
            }
        }

        return true;
    }

    public function isAllowedBrowser() {
        if (empty($_SERVER["HTTP_USER_AGENT"])) return true;

        if (preg_match("~MSIE|Internet Explorer~i", $_SERVER["HTTP_USER_AGENT"]) || strpos($_SERVER["HTTP_USER_AGENT"], "Trident/7.0; rv:11.0") !== false) { // Skip IE
            return false;
        }

        return true;
    }

    public function getScheme() {
        return $this->isSecure() ? 'https://' : 'http://';
    }

    public function isSecure() {
        return (!empty($_SERVER['HTTP_X_FORWARDED_PROTO']) && in_array("https", array_map("strtolower", array_map("trim", explode(",", $_SERVER['HTTP_X_FORWARDED_PROTO']))))) ||
            (!empty($_SERVER['HTTPS']) && $_SERVER['HTTPS'] !== 'off') ||
            (!empty($_SERVER['SERVER_PORT']) && $_SERVER['SERVER_PORT'] == 443) ||
            (!empty($_SERVER['HTTP_SSL_FLAG']) && $_SERVER['HTTP_SSL_FLAG'] == 'SSL');
    }

    public function isAJAXRequest() {
        return !empty($_SERVER['HTTP_X_REQUESTED_WITH']) && strtolower($_SERVER['HTTP_X_REQUESTED_WITH']) == 'xmlhttprequest';
    }

    public function isRequestMethod($method) {
        return empty($_SERVER['REQUEST_METHOD']) || $_SERVER['REQUEST_METHOD'] == $method;
    }

    public function isAllowedAJAX() {
        if (!$this->pageCache->getParent()) return false;
        if (!$this->pageCache->getParent()->hasCache() || $this->pageCache->getParent()->hasExpired($this->config->PageCache->ExpireTime)) return false;
        return true;
    }

    public function isAllowedAJAXUrl($url) {
        if ($this->config->AjaxURLs->Status) {
            if (!empty($this->config->AjaxURLs->URLs)) {
                foreach ($this->config->AjaxURLs->URLs as $ajaxUrl) {
                    $ajaxUrlModified = preg_replace("/^(https?:)?\/\//", "*", $ajaxUrl);
                    if (preg_match('/^' . self::wildcardToRegex($ajaxUrlModified) . '$/', $url)) {
                        return true;
                    }
                }
                return false;
            }
        }
        return false;
    }

    public function isCacheAllowed() {
        return $this->isAllowedRequest() && $this->isAllowedUrl($this->url);
    }

    public function purgeLocalUrlCache($url) {
        $this->purgeProxyCache($url);
        $localResult = true;
        $cacheDir = $this->getCacheDir();
        $knownDeviceTypes = Device::getKnownTypes();
        foreach ($knownDeviceTypes as $deviceType) {
            $urlDir = PageCache::getUrlDir($cacheDir . "/" . $deviceType, $url);
            $invalidatedUrlDir = PageCache::getUrlDir($cacheDir . "/" . $deviceType, $url, true);
            $localResult &= Filesystem::deleteDir($urlDir);
            $localResult &= Filesystem::deleteDir($invalidatedUrlDir);
        }
        return $localResult;
    }

    public function invalidateLocalUrlCache($url) {
        $this->purgeProxyCache($url);
        $localResult = true;
        $cacheDir = $this->getCacheDir();
        $knownDeviceTypes = Device::getKnownTypes();
        foreach ($knownDeviceTypes as $deviceType) {
            $urlDir = PageCache::getUrlDir($cacheDir . "/" . $deviceType, $url);
            $urlDirInvalid = PageCache::getUrlDir($cacheDir . "/" . $deviceType, $url, true);

            $this->invalidateDir($urlDir, $urlDirInvalid);
        }
        return $localResult;
    }

    public function invalidateLocalCache() {
        $this->purgeProxyCache();
        $this->config->LastFetch = 0;
        $this->setConfig($this->config);

        $cacheDir = $this->getCacheDir();
        $knownDeviceTypes = Device::getKnownTypes();
        foreach ($knownDeviceTypes as $deviceType) {
            $deviceTypeDir = $cacheDir . "/" . $deviceType;
            Filesystem::dirForeach($deviceTypeDir, function($urlDir) {
                if (substr($urlDir, -2) !== "_i") {
                    $this->invalidateDir($urlDir, $urlDir . "_i");
                }
            });
        }
        return true;
    }

    private function invalidateDir($urlDir, $urlDirInvalid) {
        if (Filesystem::fileExists($urlDirInvalid)) {
            Filesystem::dirForeach($urlDir, function($file) use ($urlDirInvalid) {
                Filesystem::rename($file, $urlDirInvalid . "/" . basename($file));
            });

            Filesystem::deleteDir($urlDir);
        } else {
            Filesystem::rename($urlDir, $urlDirInvalid);
        }
        Filesystem::touch($urlDirInvalid);
    }

    public function integrationUrl($widget, $version = null) {
        $integration = new IntegrationUrl($widget, $this->siteId, $this->siteSecret, $version);

        return $integration->getUrl();
    }

    public function embedJsUrl() {
        $embedjs = new Url\Embedjs();

        return $embedjs->getUrl();
    }

    private function loadConfig() {
        $file = $this->getConfigFile();

        $config = array();
        if (Filesystem::fileExists($file) || $this->fetchConfig()) {
            $config = json_decode(Filesystem::fileGetContents($file));
            if (empty($config->SDKVersion) || $config->SDKVersion !== NitroPack::VERSION || empty($config->LastFetch) || time() - $config->LastFetch >= $this->configTTL) {
                if ($this->fetchConfig()) {
                    $config = json_decode(Filesystem::fileGetContents($file));
                } else {
                    throw new NoConfigException("Can't load config file");
                }
            }
            $this->config = $config;
        } else {
            throw new NoConfigException("Can't load config file");
        }
    }

    private function getConfigFile() {
        $configFile = $this->configFile;

        $filename = array_pop($configFile);

        $filename = $this->siteId . '-' . $filename;

        array_push($configFile, $filename);
        array_unshift($configFile, $this->dataDir);

        return Filesystem::getOsPath($configFile);
    }

    private function lockPageCache() {
        $filename = $this->getPageCacheLockFilename();

        if (Filesystem::fileExists($filename)) {
            $sem = 1 + (int)Filesystem::fileGetContents($filename);
        } else {
            $sem = 1;
        }

        return !!Filesystem::filePutContents($filename, $sem);
    }

    private function unlockPageCache() {
        $filename = $this->getPageCacheLockFilename();

        if (Filesystem::fileExists($filename)) {
            $sem = (int)Filesystem::fileGetContents($filename);

            $sem--;

            if ($sem <= 0) {
                return !!Filesystem::deleteFile($filename);
            } else {
                return !!Filesystem::filePutContents($filename, $sem);
            }
        }

        return false;
    }

    private function isPageCacheLocked() {
        $filename = $this->getPageCacheLockFilename();

        if (!Filesystem::fileExists($filename)) {
            return false;
        } else {
            if (time() - Filesystem::fileMTime($filename) <= self::PAGECACHE_LOCK_EXPIRATION_TIME) {
                return true;
            } else {
                Filesystem::deleteFile($filename);

                return false;
            }
        }

        // We should never get here, so consider this a default return value in case of future changes
        return false;
    }

    private function getPageCacheLockFilename() {
        $pageCacheLockFile = $this->pageCacheLockFile;
        array_unshift($pageCacheLockFile, $this->dataDir);
        return Filesystem::getOsPath($pageCacheLockFile);
    }
}


