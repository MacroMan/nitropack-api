<?php

namespace NitroPack\HttpClient;

use \Exception;
use NitroPack\Exceptions\ChunkSizeException;
use NitroPack\Exceptions\RedirectException;
use NitroPack\Exceptions\ResponseTooLargeException;
use NitroPack\Exceptions\SocketOpenException;
use NitroPack\Exceptions\SocketReadException;
use NitroPack\Exceptions\SocketReadTimedOutException;
use NitroPack\Exceptions\SocketTlsTimedOutException;
use NitroPack\Exceptions\SocketWriteException;
use NitroPack\Exceptions\URLEmptyException;
use NitroPack\Exceptions\URLInvalidException;
use NitroPack\Exceptions\URLUnsupportedProtocolException;
use NitroPack\Url\Url;

class HttpClient {
    public static $MAX_FREE_CONNECTIONS = 100;
    public static $REDIRECT_LIMIT = 20;
    public static $MISDIRECT_RETRIES = 3;
    public static $HOSTS_CACHE_TTL = 300; // 5 minutes in seconds
    public static $DEBUG = false;

    public static $connections = array();
    public static $secure_connections = array();
    public static $free_connections = array();
    public static $backtraces = array();

    private static $fetch_start_callback = NULL;
    private static $fetch_end_callback = NULL;
    private static $hosts_cache = array();
    private static $hosts_cache_expire = array();

    public static function reapDeadConnections() {
        $connectionsCount = 0;
        $connectionsRemovedCount = 0;
        foreach (self::$connections as $key => &$connections) {
            $connectionsCount += count($connections);
            foreach ($connections as $index => $con) {
                if (!self::_isConnectionValid($con, true)) {
                    $connectionsRemovedCount++;
                    self::_disconnect($con);
                    array_splice($connections, $index, 1);
                }
            }

            if (empty($connections)) {
                unset(self::$connections[$key]);
            }
        }
    }

    public static function _isConnectionValid($sock, $readRemainder = false) {
        $isValidStream = is_resource($sock) && get_resource_type($sock) != "Unknown";
        if ($isValidStream && $readRemainder) {
            $metaData = stream_get_meta_data($sock);
            $isBlocking = $metaData["blocked"];
            if ($isBlocking) {
                stream_set_blocking($sock, false);
            }

            $buffer = stream_get_contents($sock);
            if (strlen($buffer) && HttpClient::$DEBUG) {
                if (isset(HttpClient::$backtraces[(int)$sock])) {
                    file_put_contents("/tmp/" . (int)$sock . "_" . microtime(true) . ".nitro_backtrace_log", print_r(HttpClient::$backtraces[(int)$sock], true));
                } else {
                    file_put_contents("/tmp/" . (int)$sock . "_" . microtime(true) . ".nitro_log", $buffer);
                }
            }
            $oob = stream_socket_recvfrom($sock, 4096, STREAM_OOB);
            if (strlen($oob) && HttpClient::$DEBUG) {
                file_put_contents("/tmp/" . (int)$sock . "_" . microtime(true) . ".nitro_log_oob", $oob);
            }

            if ($isBlocking) {
                stream_set_blocking($sock, true);
            }
        }
        return $isValidStream && !feof($sock);
    }

    public static function _disconnect($sock) {
        if (isset(self::$secure_connections[(int)$sock])) {
            unset(self::$secure_connections[(int)$sock]);
        }

        $index = array_search($sock, self::$free_connections);
        if ($index !== false) {
            array_splice(self::$free_connections, $index, 1);
        }

        if (isset(HttpClient::$backtraces[(int)$sock])) {
            unset(HttpClient::$backtraces[(int)$sock]);
        }

        if (is_resource($sock)) {
            fclose($sock);
        }
    }

    public static function setFetchStartCallback($callback) {
        HttpClient::$fetch_start_callback = $callback;
    }

    public static function setFetchEndCallback($callback) {
        HttpClient::$fetch_end_callback = $callback;
    }

    public static function globalHostOverride($host, $ip) {
        if (is_array($ip)) {
            HttpClient::$hosts_cache[$host] = $ip;
        } else {
            HttpClient::$hosts_cache[$host] = [$ip];
        }
    }

    public $connection_reuse = true;
    public $host;
    public $port;
    public $scheme;
    public $URL;
    public $sock;
    public $connect_timeout;
    public $ssl_timeout;
    public $timeout;
    public $ssl_verify_peer = false;
    public $ssl_verify_peer_name = false;
    public $read_chunk_size = 8192;
    public $max_response_size;
    public $buffer = '';
    public $headers = array();
    public $post_data = "";
    public $request_headers = array();
    public $http_version = '1.1';
    public $status_code = -1;
    public $body = '';
    public $auto_deflate = true;
    public $accept_deflate = true;
    public $cookies = array();
    public $doNotDownload = false;
    public $debug = false;

    // Performance log
    public $initial_connection = 0;
    public $ssl_negotiation = 0;
    public $ssl_negotiation_start = 0;
    public $sent_request = 0;
    public $send_request_start = 0;
    public $ttfb = 0;
    public $received_data = 0;
    public $content_download = 0;
    public $content_download_start = 0;
    public $last_read = 0;
    public $last_write = 0;

    // PreCache stuff
    public $processHandle = "";
    public $cancelled = false;

    private $ttfb_start_time = 0;

    private $end_of_chunks = false;
    private $chunk_remainder = 0;
    private $data_size = 0;

    private $prevUrl;
    private $redirects_count = 0;
    private $misdirect_retries = 0;
    private $oncomplete_callback = NULL;
    private $redirect_callback = NULL;
    private $data_callback = NULL;
    private $data_drain_file = NUll;
    private $body_stream = NULL;
    private $gzip_filter = NULL;
    private $is_gzipped = false;
    private $data_len;
    private $is_chunked;
    private $emptyRead;

    private $ignored_data = "";
    private $gzip_header = "";
    private $gzip_trailer = "";

    private $cookie_jar = "";

    private $isAsync;
    private $asyncQueue;
    private $follow_redirects;
    private $request_headers_string;
    private $has_redirect_header;
    private $config;
    private $state;
    private $hostsOverride;
    private $portsOverride;

    private $proxyAddr;
    private $proxyPort;
    private $proxyScheme;

    public function __construct($URL, $httpConfig = NULL) {
        $this->prevUrl = NULL;
        $this->setURL($URL);

        $this->connect_timeout = NULL;//in seconds
        $this->ssl_timeout = NULL;//in seconds
        $this->timeout = 5;//in seconds
        $this->max_response_size = 1024 * 1024 * 5;

        $this->config = $httpConfig ? $httpConfig : new HttpConfig();
        $this->cookie_jar = $this->config->getCookieJar();

        if ($this->cookie_jar && file_exists($this->cookie_jar)) {
            $this->cookies = json_decode(file_get_contents($this->cookie_jar), true);
        }

        if ($this->config->getReferer()) {
            $this->setHeader("Referer", $this->config->getReferer());
        }

        if ($this->config->getUserAgent()) {
            $this->setHeader('User-Agent', $this->config->getUserAgent());
        }

        $this->initBodyStream();

        $this->isAsync = false;
        $this->asyncQueue = array();
        $this->follow_redirects = true;
        $this->request_headers_string = "";
        $this->emptyRead = false;
        $this->state = HttpClientState::READY;
        $this->hostsOverride = array();
        $this->portsOverride = array();
    }

    public function __destruct() {
        if ($this->data_drain_file) {
            if (is_resource($this->data_drain_file)) {
                fclose($this->data_drain_file);
            }
        }

        if (is_resource($this->body_stream)) {
            fclose($this->body_stream);
        }

        if (!$this->connection_reuse) {
            $this->disconnect();
        }
    }

    public function getIgnoredData() {
        return $this->ignored_data;
    }

    public function getState() {
        return $this->state;
    }

    public function disconnect() {
        $this->state = HttpClientState::READY;
        self::_disconnect($this->sock);
    }

    public function abort() {
        $this->disconnect();
        if (!empty($this->asyncQueue)) {
            $this->asyncQueue = [];
        }
    }

    public function setURL($URL, $resetRedirects = true) {
        if ($resetRedirects) {
            $this->redirects_count = 0;
            $this->misdirect_retries = 0;
        }

        $this->URL = $URL;
        $this->parseURL();
    }

    public function setPostData($data) {
        $this->post_data = !empty($data) ? http_build_query($data) : "";
    }

    public function setVerifySSL($status) {
        $this->ssl_verify_peer = $status;
        $this->ssl_verify_peer_name = $status;
    }

    /**
     * Set a callback function which will be called while receiving data chunks
     * This callback will not be called while receiving headers - only for data after the headers
     * The callback receives 1 parameter - the received data
     * The callback is not expected to return anything
     * */
    public function setDataCallback($callback) {
        if (is_callable($callback)) {
            $this->data_callback = $callback;
        } else {
            $this->data_callback = NULL;
        }
    }

    /**
     * Set a callback function which will be called when following Location redirects automatically
     * The callback receives 1 parameter - the next URL
     * The callback is expected to return a URL. The returned URL will be used for the next request
     * */
    public function setRedirectCallback($callback) {
        if (is_callable($callback)) {
            $this->redirect_callback = $callback;
        } else {
            $this->redirect_callback = NULL;
        }
    }

    /**
     * Set a callback function which will be called when the final response (after following all redirects) has been received
     * The callback receives 1 parameter - the HttpClient object
     * The callback is not expected to return anything
     * */
    public function setOnCompleteCallback($callback) {
        if (is_callable($callback)) {
            $this->oncomplete_callback = $callback;
        } else {
            $this->oncomplete_callback = NULL;
        }
    }

    public function setDataDrainFile($file) {
        if (is_resource($this->data_drain_file)) {
            fclose($this->data_drain_file);
        }

        if (is_resource($this->body_stream)) {
            ftruncate($this->body_stream, 0);
            fclose($this->body_stream);
        }

        if (is_resource($file)) {
            $this->data_drain_file = $file;
            stream_set_blocking($this->data_drain_file, false);
        } else if (is_string($file)) {
            $dir = dirname($file);
            if (!is_dir($dir) && !@mkdir($dir, 0755, true)) {
                $this->data_drain_file = NULL;
                return;
            }
            $this->data_drain_file = fopen($file, "w");
            stream_set_blocking($this->data_drain_file, false);
        } else {
            $this->data_drain_file = NULL;
            $this->initBodyStream();
        }

        if ($this->data_drain_file) {
            $this->body_stream = $this->data_drain_file;
        }
    }

    public function setCookie($name, $value, $domain = null) {
        if (!$domain && $this->host) {
            $domain = $this->host;
        }

        if ($domain) {
            if (empty($this->cookies[$domain])) {
                $this->cookies[$domain] = array();
            }
            $this->cookies[$domain][$name] = $value;
        }

        if ($this->cookie_jar) {
            file_put_contents($this->cookie_jar, json_encode($this->cookies));
        }
    }

    public function removeCookie($name, $domain = null) {
        if (!$domain && $this->host) {
            $domain = $this->host;
        }

        if ($domain) {
            if (!empty($this->cookies[$domain][$name])) {
                unset($this->cookies[$domain][$name]);
            }
        }

        if ($this->cookie_jar) {
            file_put_contents($this->cookie_jar, json_encode($this->cookies));
        }
    }

    public function clearCookies($domain) {
        if (isset($this->cookies[$domain])) {
            unset($this->cookies[$domain]);
        }

        if ($this->cookie_jar) {
            file_put_contents($this->cookie_jar, json_encode($this->cookies));
        }
    }

    public function parseURL() {
        if (!empty($this->URL)) {
            $urlInfo = new Url($this->URL);
            if ($this->prevUrl) {
                $baseUrl = new Url($this->prevUrl);
                $urlInfo->setBaseUrl($baseUrl);
                $normalized = $urlInfo->getNormalized(true, false); // When following relative redirects the previous URL must be taken into account when building the new URL
                $urlInfo = new Url($normalized);
            }

            $this->scheme = $urlInfo->getScheme();
            $this->host = $urlInfo->getHost();
            $this->port = $urlInfo->getPort();

            if (!$this->host) {
                throw new URLInvalidException($this->URL . ' - Invalid URL');
            }

            if ($this->scheme) {
                $this->scheme = strtolower($this->scheme);
            }

            if (!in_array($this->scheme, array('http', 'https'))) {
                throw new URLUnsupportedProtocolException($this->URL . ' - Unsupported protocol');
            }

            if (!empty($this->portsOverride[$this->host])) {
                $this->port = $this->portsOverride[$this->host];
            } else if (!$this->port) {
                $this->port = $this->scheme == 'https' ? 443 : 80;
            }

            $this->addr = $this->gethostbyname($this->host);

            $this->URL = $urlInfo->getNormalized(true, false);
            $this->prevUrl = $this->URL;
            $this->path = preg_replace("~^https?://[^/]+~", "", $this->URL); // This must be the normalized string after the domain, including the query params
        } else {
            throw new URLEmptyException('URL is empty');
        }
    }

    public function gethostbyname($host, $isRetry = false) {
        if (!empty($this->hostsOverride[$host])) {
            return $this->hostsOverride[$host];
        }

        if (empty(HttpClient::$hosts_cache[$host]) || (!empty(HttpClient::$hosts_cache_expire[$host]) && microtime(true) - HttpClient::$hosts_cache_expire[$host] > HttpClient::$HOSTS_CACHE_TTL)) {
            $ips = gethostbynamel($host);

            if ($ips === false) {
                HttpClient::$hosts_cache[$host] = [$host];
            } else {
                HttpClient::$hosts_cache[$host] = $ips;
            }
            HttpClient::$hosts_cache_expire[$host] = microtime(true);
        }

        if ($isRetry) {
            array_shift(HttpClient::$hosts_cache[$host]);
        }

        return reset(HttpClient::$hosts_cache[$host]);
    }

    public function setProxy($proxy = NULL) {
        if ($proxy) {
            $url = new Url($proxy);
            $this->proxyScheme = strtolower($url->getScheme());
            $this->proxyAddr = $this->gethostbyname($url->getHost());
            $this->proxyPort = $url->getPort();
        } else {
            $this->proxyScheme = NULL;
            $this->proxyAddr = NULL;
            $this->proxyPort = NULL;
        }
    }

    public function hostOverride($host, $dest) {
        $parts = explode(":", $dest);

        $ip = $parts[0];
        $port = !empty($parts[1]) ? $parts[1] : null;

        $this->hostsOverride[$host] = $ip;
        if ($port) {
            $this->portsOverride[$host] = $port;
        }

        if ($this->host == $host) {
            $this->addr = $ip;
            if ($port) {
                $this->port = $port;
            }
        }
    }


    public function replay() {
        $this->fetch($this->follow_redirects, $this->http_method, $this->isAsync);
    }

    public function fetch($follow_redirects = true, $method = "GET", $isAsync = false) {
        $this->state = HttpClientState::INIT;
        $this->follow_redirects = $follow_redirects;
        $this->isAsync = $isAsync;

        // Disable accept_deflate in case the Litespeed extension is installed - there is a known issue with stream_filter_append
        if (in_array('litespeed', get_loaded_extensions())) {
            $this->accept_deflate = false;
        }

        if ($this->data_drain_file) {
            ftruncate($this->data_drain_file, 0);
            fseek($this->data_drain_file, 0, SEEK_SET);
        }

        ftruncate($this->body_stream, 0);
        fseek($this->body_stream, 0, SEEK_SET);
        if ($this->gzip_filter) {
            stream_filter_remove($this->gzip_filter);
            $this->gzip_filter = NULL;
        }

        $this->body = NULL;//because of PHP's memory management
        $this->body = '';
        $this->buffer = '';
        $this->ignored_data = "";
        $this->gzip_header = "";
        $this->gzip_trailer = "";
        $this->end_of_chunks = false;
        $this->chunk_remainder = 0;
        $this->data_size = 0;
        $this->data_len = $this->max_response_size;
        $this->is_gzipped = false;
        $this->is_chunked = false;
        $this->status_code = -1;
        $this->headers = array();
        $this->has_redirect_header = false;
        $this->emptyRead = false;
        $this->request_headers_string = "";

        //  Performance log
        $this->initial_connection = 0;
        $this->ssl_negotiation = 0;
        $this->ssl_negotiation_start = 0;
        $this->sent_request = 0;
        $this->send_request_start = 0;
        $this->ttfb = 0;
        $this->received_data = 0;
        $this->content_download = 0;
        $this->content_download_start = 0;
        $this->last_read = 0;
        $this->last_write = 0;

        $this->http_method = strtoupper($method);

        $this->requestLoop();
    }

    private function requestLoop() {
        if ($this->isAsync) {
            $this->asyncQueue = array();
            $this->asyncQueue[] = array($this, 'connect');
            $this->asyncQueue[] = array($this, 'enableSSL');
            $this->asyncQueue[] = array($this, 'sendRequest');
            $this->asyncQueue[] = array($this, 'download');
            $this->asyncQueue[] = array($this, 'onDownload');
        } else {
            if (HttpClient::$fetch_start_callback) {
                call_user_func(HttpClient::$fetch_start_callback, $this->URL, false);
            }
            $this->connect();
            $this->enableSSL();
            $this->sendRequest();
            $this->download();
            $this->onDownload();
            if (HttpClient::$fetch_end_callback) {
                call_user_func(HttpClient::$fetch_end_callback, $this->URL, false);
            }
        }
    }

    private function isConnectionValid() {
        return self::_isConnectionValid($this->sock);
    }

    public function asyncLoop() {
        if (empty($this->asyncQueue)) return true;

        if (HttpClient::$fetch_start_callback) {
            call_user_func(HttpClient::$fetch_start_callback, $this->URL, true);
        }
        $func = reset($this->asyncQueue);
        if (call_user_func($func) === true) {
            array_shift($this->asyncQueue);
        }
        if (HttpClient::$fetch_end_callback) {
            call_user_func(HttpClient::$fetch_end_callback, $this->URL, true);
        }

        return empty($this->asyncQueue);
    }

    private function onDownload() {
        $this->freeConnection();
        $this->state = HttpClientState::READY;

        if ($this->status_code == 421 && ++$this->misdirect_retries < self::$MISDIRECT_RETRIES) { // retry with a differect connection
            $this->disconnect();
            $this->replay();
            return false;
        } else if ($this->follow_redirects && !empty($this->headers['location'])) {
            if (++$this->redirects_count > self::$REDIRECT_LIMIT) {
                throw new RedirectException("Too many redirects");
            }

            if ($this->redirect_callback) {
                $this->setURL(call_user_func($this->redirect_callback, $this->headers['location']), false);
            } else {
                $this->setURL($this->headers['location'], false);
            }

            $this->fetch(true, $this->http_method, $this->isAsync);
            return false;
        } else {
            if ($this->doNotDownload) { // There is potentially more unread data coming from the remote end of this socket. Must disconnect, otherwise a subsequent request will read an invalid response
                $this->disconnect();
            }

            if ($this->data_drain_file) {
                stream_set_blocking($this->data_drain_file, true);
                fflush($this->data_drain_file);
                stream_set_blocking($this->data_drain_file, false);
            }

            if ($this->oncomplete_callback) {
                call_user_func($this->oncomplete_callback, $this);
            }
        }

        return true;
    }

    public function setHeader($header, $value) {
        $this->request_headers[strtolower($header)] = $value;
    }

    public function removeHeader($header) {
        $header = strtolower($header);
        if (isset($this->request_headers[$header])) {
            unset($this->request_headers[$header]);
        }
    }

    public function getHeaders($preserveMultiples = false) {
        if ($preserveMultiples) {
            return $this->headers;
        } else {
            $noMultiples = array();
            foreach ($this->headers as $name => $value) {
                if (is_array($value)) {
                    $noMultiples[$name] = end($value);
                } else {
                    $noMultiples[$name] = $value;
                }
            }

            return $noMultiples;
        }
    }

    public function getBody() {
        rewind($this->body_stream);
        return stream_get_contents($this->body_stream);
    }

    public function getStatusCode() {
        return $this->status_code;
    }

    public function getConfig() {
        return $this->config;
    }

    public function connect() {
        $this->state = HttpClientState::CONNECT;
        BEGIN_CONNECT:
        $addr = $this->proxyAddr ? $this->proxyAddr : $this->addr;
        $port = $this->proxyPort ? $this->proxyPort : $this->port;
        $reuseKey = implode(':', array($addr, $port));
        if (isset(self::$connections[$reuseKey])) {
            foreach (self::$connections[$reuseKey] as $sock) {
                if (!in_array($sock, HttpClient::$free_connections)) continue;

                $this->sock = $sock;
                if ($this->isConnectionValid()) {// check if the connection is still alive
                    $this->acquireConnection();
                    return true;
                } else {
                    $this->disconnect(); // Remove the inactive connection
                }
            }
        }

        if (stripos(ini_get('disable_functions'), 'stream_socket_client') !== FALSE) {
            throw new \RuntimeException("stream_socket_client is disabled.");
        }

        $ctxOptions = array(
            "ssl" => array(
                "verify_peer" => $this->ssl_verify_peer,
                "verify_peer_name" => $this->ssl_verify_peer_name,
                "allow_self_signed" => true,
                "SNI_enabled" => true,
                "peer_name" => $this->host
            )
        );

        if (version_compare(PHP_VERSION, '5.6.0', '<')) {
            $ctxOptions["ssl"]["SNI_server_name"] = $this->host;
        }

        $ctx = stream_context_create($ctxOptions);

        $errno = $errorMessage = NULL;
        if (!$this->initial_connection) {
            $this->initial_connection = microtime(true);
        }

        $timeout = $this->connect_timeout ? $this->connect_timeout : $this->timeout;

        $addrBackup = $addr;

        if ($this->isAsync) {
            $this->sock = @stream_socket_client("tcp://$addr:$port", $errno, $errorMessage, $timeout, STREAM_CLIENT_CONNECT | STREAM_CLIENT_ASYNC_CONNECT, $ctx);
            if (!$this->sock && $errno === 0) {
                if (microtime(true) - $this->initial_connection > $timeout) {
                    $errorMessage = "Connection timed out";
                } else {
                    return false;
                }
            }
        } else {
            $this->sock = @stream_socket_client("tcp://$addr:$port", $errno, $errorMessage, $timeout, STREAM_CLIENT_CONNECT, $ctx);
        }

        $this->initial_connection = microtime(true) - $this->initial_connection;

        if($this->sock === false) {
            $this->addr = $this->gethostbyname($this->host, true);
            if ($this->addr) {
                if ($this->isAsync) {
                    return false;
                } else {
                    goto BEGIN_CONNECT;
                }
            } else {
                throw new SocketOpenException('Unable to open socket to: ' . $this->host ." ($addrBackup) on port " . $this->port . "($errorMessage)");
            }
        }

        stream_set_blocking($this->sock, false);

        if ($this->connection_reuse) {
            if (!isset(self::$connections[$reuseKey])) {
                self::$connections[$reuseKey] = array();
            }
            self::$connections[$reuseKey][] = $this->sock;
        }

        HttpClient::$secure_connections[(int)$this->sock] = false;
        $this->acquireConnection();
        return true;
    }

    public function enableSSL() {
        $this->state = HttpClientState::SSL_HANDSHAKE;
        if ($this->isSecure()) return true;
        $this->logConnectionUsage();

        $crypto_method = STREAM_CRYPTO_METHOD_TLS_CLIENT;

        if (defined('STREAM_CRYPTO_METHOD_TLSv1_2_CLIENT')) {
            $crypto_method |= STREAM_CRYPTO_METHOD_TLSv1_2_CLIENT;
            $crypto_method |= STREAM_CRYPTO_METHOD_TLSv1_1_CLIENT;
        }

        //set_error_handler(array($this, 'error_sink'));
        $scheme = $this->proxyScheme ? $this->proxyScheme : $this->scheme;
        if ($scheme == 'https') {
            if (!$this->ssl_negotiation_start) {
                $this->ssl_negotiation_start = microtime(true);
            }

            stream_set_blocking($this->sock, !$this->isAsync);
            $result = @stream_socket_enable_crypto($this->sock, true, $crypto_method);
            stream_set_blocking($this->sock, false);
            //restore_error_handler();

            if ($result === true) {
                $this->ssl_negotiation = microtime(true) - $this->ssl_negotiation_start;
                HttpClient::$secure_connections[(int)$this->sock] = true;
                return true;
            } else if ($result === false) {
                $this->disconnect();
                throw new SocketOpenException('Unable to establish secure connection to: ' . $this->host .' on port ' . $this->port);
            } else {
                $timeout = $this->ssl_timeout ? $this->ssl_timeout : $this->timeout;
                if (microtime(true) - $this->ssl_negotiation_start >= $timeout) {
                    $this->disconnect();
                    throw new SocketTlsTimedOutException($this->URL . " - SSL negotiation timed out.");
                }
            }
        } else {
            return true;
        }
    }

    private function checkWriteTimeout() {
        if ((microtime(true) - $this->last_write) > $this->timeout) {
            $this->disconnect();
            throw new SocketWriteException($this->URL . ' - Writing to socket timed out');
        }
    }

    public function sendRequest() {
        $this->state = HttpClientState::SEND_REQUEST;
        if (!strlen($this->request_headers_string)) {
            $this->request_headers_string = $this->getRequestHeaders();
        }
        $this->logConnectionUsage();

        //stream_set_blocking($this->sock, false);

        if ($this->send_request_start == 0) {
            $this->send_request_start = microtime(true);
            $this->last_write = $this->send_request_start;
        }

        do {
            if ($this->isAsync) { // Check if resource is available for writing, otherwise we may get errno=11 Resource temporarily unavailable
                $read = $except = NULL;
                $write = array($this->sock);
                stream_select($read, $write, $except, 0, 2000); // 2ms microtimeout
                if (empty($write)) {
                    $this->checkWriteTimeout();
                    break;
                }
            }
            $wrote = @fwrite($this->sock, $this->request_headers_string);

            if ($wrote === false) {
                $this->disconnect();
                throw new SocketWriteException($this->URL . ' - Cannot write to socket');
            } else if ($wrote === 0) {
                $this->checkWriteTimeout();
            } else {
                $this->last_write = microtime(true);
            }
            fflush($this->sock);

            $this->request_headers_string = substr($this->request_headers_string, $wrote);
        } while(!$this->isAsync && $this->request_headers_string);//we want to loop to happen if we are not in async mode, otherwise do only one iteration at a time

        if (!strlen($this->request_headers_string)) {
            $this->ttfb_start_time = microtime(true);
            $this->sent_request = $this->ttfb_start_time - $this->send_request_start;
            //stream_set_blocking($this->sock, true);
            return true;
        }

        return false;
    }

    public function download() {
        $this->state = HttpClientState::DOWNLOAD;
        if ($this->last_read === 0) {
            //stream_set_blocking($this->sock, false);

            $this->content_download_start = microtime(true);
            $this->last_read = $this->content_download_start;
        }
        $this->logConnectionUsage();

        do {
            if ($this->is_chunked) {
                $chunk = $this->read_chunk_size;
            } else {
                $chunk = min(($this->data_len - $this->data_size), $this->read_chunk_size);
            }

            if (!$this->isAsync && $this->emptyRead) {
                $write = $except = NULL;
                $read = array($this->sock);
                if (defined("NITROPACK_USE_MICROTIMEOUT") && NITROPACK_USE_MICROTIMEOUT) {
                    stream_select($read, $write, $except, 0, NITROPACK_USE_MICROTIMEOUT); // If the last fread was empty use syscall instead of busy waiting for data. This frees up the CPU.
                } else {
                    stream_select($read, $write, $except, $this->timeout); // If the last fread was empty use syscall instead of busy waiting for data. This frees up the CPU.
                }
            }

            $data = @fread($this->sock, $chunk);

            if ($data === false) {
                if (!$this->isConnectionValid()) {
                    $this->disconnect();
                }
                throw new SocketReadException($this->URL . " - Failed reading data from socket");
            } else if (strlen($data)) {
                $this->last_read = microtime(true);
                if ($this->ttfb === 0) {
                    $this->ttfb = microtime(true) - $this->ttfb_start_time;
                }
                $this->emptyRead = false;

                $this->data_size += strlen($data);
                $this->received_data += strlen($data);

                if ($this->headers && !$this->is_chunked) {
                    $this->processData($data);

                    if ($this->data_callback) {
                        $this->data_callback($data);
                    }
                } else {
                    $this->buffer .= $data;
                }

                if ($this->data_size > $this->max_response_size) {
                    $this->disconnect();
                    throw new ResponseTooLargeException($this->URL . ' - Response data exceeds the limit of ' . $this->max_response_size . ' bytes');
                }

                if (!$this->headers && $this->extractHeaders()) {
                    if ($this->http_method == 'HEAD') break;

                    if ($this->doNotDownload && !$this->follow_redirects) {
                        break;
                    }

                    foreach ($this->headers as $name => $value) {
                        switch ($name) {
                        case 'content-length':
                            $this->data_len = (int)$value;

                            if ($this->data_len > $this->max_response_size) {
                                $this->disconnect();
                                throw new ResponseTooLargeException($this->URL . ' - Response data exceeds the limit of ' . $this->max_response_size . ' bytes');
                            }
                            break;
                        case 'content-encoding':
                            if (strtolower($value) == 'gzip') {
                                $this->is_gzipped = true;

                                if ($this->auto_deflate) {
                                    $this->gzip_filter = stream_filter_append($this->body_stream, "zlib.inflate", STREAM_FILTER_WRITE);
                                }
                            }
                            break;
                        case 'transfer-encoding':
                            if (strtolower($value) != 'identity') {
                                $this->is_chunked = true;
                            }
                        }

                        if ($name == 'location') {
                            $this->has_redirect_header = true;
                            break 2;
                        }
                    }

                    if ($this->doNotDownload && $this->follow_redirects && !$this->has_redirect_header) {
                        break;
                    }

                    if (strlen($this->buffer) && !$this->is_chunked) {
                        $this->processData($this->buffer);

                        $this->buffer = NULL;
                        $this->buffer = "";
                    }
                }

                if ($this->is_chunked && !$this->end_of_chunks) {
                    $this->parseChunks();
                }
            } else {
                $this->emptyRead = true;
                if ((microtime(true) - $this->last_read) > $this->timeout) {
                    $this->disconnect();
                    throw new SocketReadTimedOutException("Reading data from the remote host timed out. Total read data before timeout was {$this->received_data} bytes");
                }
            }
        } while (!$this->isAsync && $this->data_size < $this->data_len && !$this->hasStreamEnded());

        if ($this->data_size == $this->data_len || ($this->is_chunked && $this->hasStreamEnded()) || $this->has_redirect_header || ($this->headers && $this->http_method == "HEAD")) {
            $this->content_download = microtime(true) - $this->content_download_start;

            $this->buffer = NULL;
            $this->buffer = '';
            //stream_set_blocking($this->sock, true);

            $isKeepAlive = false;
            $maxRequests = 1;
            foreach ($this->getHeaders() as $name => $value) {
                if ($name == 'connection') {
                    $params = array_map('strtolower', array_map('trim', explode(',', $value)));
                    $isKeepAlive = in_array('keep-alive', $params);
                } else if ($name == 'keep-alive') {
                    $params = array_map('trim', explode(',', $value));
                    foreach ($params as $param) {
                        list($paramName, $paramVal) = explode('=', $param);
                        if (strtolower($paramName) == 'max') {
                            $maxRequests = (int)$paramVal - 1;
                        }
                    }
                }
            }

            if (!$isKeepAlive || !$maxRequests || !$this->connection_reuse) {
                $this->disconnect();
            }

            return true;
        }

        return false;
    }

    private function processData($data) {
        if ($this->is_gzipped) {
            $headerLen = strlen($this->gzip_header);
            if ($headerLen < 10) {
                /* Start looking for the GZIP header. Sometimes there is data preceding the gzipped data, which must be ignored */
                if ($headerLen == 0) {
                    $headerStart = strpos($data, chr(0x1F) . chr(0x8B));
                    if ($headerStart !== false) {
                        if ($headerStart > 0) {
                            $this->ignored_data .= substr($data, 0, $headerStart);
                            $data = substr($data, $headerStart);
                        }
                    } else if (substr($data, -1) == chr(0x1F)) {
                        $this->ignored_data .= substr($data, 0, -1);
                        $data = substr($data, -1);
                    } else {
                        $this->ignored_data .= $data;
                        return;
                    }
                } else if ($headerLen == 1) {
                    if ($data[0] != chr(0x8B)) {
                        $this->ignored_data .= $data;
                        $this->gzip_header = "";
                        return;
                    }
                }
                /* End looking for gzip header */

                $this->gzip_header .= $data;
            } else {
                $this->gzip_trailer .= $data;
            }

            if (strlen($this->gzip_header) > 10) {
                $this->gzip_trailer = substr($this->gzip_header, 10);
                $this->gzip_header = substr($this->gzip_header, 0, 10);
            }

            if (strlen($this->gzip_trailer) > 8) {
                fwrite($this->body_stream, substr($this->gzip_trailer, 0, -8));
                $this->gzip_trailer = substr($this->gzip_trailer, -8);
            }
        } else {
            fwrite($this->body_stream, $data);
        }
    }

    private function hasStreamEnded() {
        return $this->end_of_chunks && strpos($this->buffer, "\r\n\r\n") !== false;
    }

    private function parseChunks() {
        while(strlen($this->buffer)) {
            if (!$this->chunk_remainder) {
                $chunk_header_end = strpos($this->buffer, "\r\n");

                if ($chunk_header_end !== false) {
                    $chunk_header_str = substr($this->buffer, 0, $chunk_header_end);
                    $chunk_parts = explode(";", $chunk_header_str);
                    $chunk_size = hexdec(trim($chunk_parts[0]));

                    if ($chunk_size == 0) {
                        $this->end_of_chunks = true;
                        break;
                    }

                    if (!is_int($chunk_size)) {
                        $this->disconnect();
                        throw new ChunkSizeException($this->URL . " - Chunk size is not an integer");
                    }

                    $this->buffer = strlen($this->buffer) > $chunk_header_end + 2 ? substr($this->buffer, $chunk_header_end+2) : "";
                    $this->chunk_remainder = $chunk_size + 2;
                } else {
                    break;
                }
            } else {
                if ($this->buffer) {
                    $data = substr($this->buffer, 0, $this->chunk_remainder);
                    $read_len = strlen($data);
                    if ($this->chunk_remainder > 2) {
                        if ($read_len == $this->chunk_remainder) {
                            $data = substr($data, 0, -2); // Chunk data includes the \r\n, so strip the it
                        } else if ($read_len == $this->chunk_remainder - 1) {
                            $data = substr($data, 0, -1); // Chunk data includes the \r char but not the \n char, so strip only the \r
                        }

                        $this->processData($data);

                        if ($this->data_callback) {
                            $this->data_callback($data);
                        }
                    }

                    $this->chunk_remainder -= $read_len;
                    $this->buffer = strlen($this->buffer) > $read_len ? substr($this->buffer, $read_len) : "";
                }
            }
        }
    }

    public function extractHeaders() {
        if ($this->headers) return true;

        $headers_end = strpos($this->buffer, "\r\n\r\n");

        if ($headers_end) {
            $headers_str = substr($this->buffer, 0, $headers_end);
            $this->buffer = strlen($this->buffer) > $headers_end + 4 ? substr($this->buffer, $headers_end+4) : "";
            $this->data_size = strlen($this->buffer);
            preg_match_all('/^(.*)/mi', $headers_str, $headers);
            foreach ($headers[1] as $i=>$header) {
                $parts = explode(": ", trim($header));

                if ($i == 0) {
                    $name = array_shift($parts);// First one should not be lowercased because it is the status line, for example: HTTP/1.1 200 OK
                } else {
                    $name = strtolower(array_shift($parts));
                }

                $value = implode(": ", $parts);

                if (isset($this->headers[$name])) {
                    if (!is_array($this->headers[$name])) { // Convert to array, because we need to have more than one values for this header. This is a BC breaking change, but it must be done
                        $currentValue = $this->headers[$name];
                        $this->headers[$name] = array($currentValue);
                    }
                    $this->headers[$name][] = $value;
                } else {
                    $this->headers[$name] = $value;
                }

                if ($name == "set-cookie") {
                    $cookie_parts = explode("; ", $value);
                    $cookie_domain = $this->host;
                    $cookie_name = "";
                    $cookie_value = "";
                    $cookie_exp_time = 0;

                    foreach ($cookie_parts as $i=>$part) {
                        $part_exploded = explode("=", $part);
                        $key = array_shift($part_exploded);
                        $part_value = implode("=", $part_exploded);

                        if ($i == 0) {
                            $cookie_name = $key;
                            $cookie_value = $part_value;
                        } else {
                            switch (strtolower($key)) {
                            case "domain":
                                $cookie_domain = $part_value;
                                break;
                            case "expires":
                                $cookie_exp_time = @strtotime($part_value);
                                break;
                            }
                        }
                    }


                    if (strlen($cookie_name) && strlen($cookie_value)) {
                        if ($cookie_exp_time > 0 && $cookie_exp_time < time()) {
                            $this->removeCookie($cookie_name, $cookie_domain);
                        } else {
                            $this->setCookie($cookie_name, $cookie_value, $cookie_domain);
                        }
                    }
                }
            }

            $statusline_keys = array_keys($this->headers);
            $statusline = $statusline_keys[0];

            if (preg_match('/HTTP\/([\d\.]+)\s(\d{3})/', $statusline, $matches)) {
                $this->http_version = (float)$matches[1];
                $this->status_code = (int)$matches[2];
            } else {
                $this->headers = array();
                return false;
            }

            if ($this->debug) {
                var_dump($this->headers);
            }

            return true;
        }

        return false;
    }

    public function getRequestHeaders() {
        $headers = array();
        $headers[] = $this->http_method . " " . $this->path . " HTTP/1.1";
        $headers[] = "host: " . $this->host;

        if ($this->accept_deflate) {
            $headers[] = "accept-encoding: gzip";
        }

        if ($this->connection_reuse) {
            $headers[] = "connection: keep-alive";
        }

        $cookies_combined = array();
        foreach ($this->cookies as $domain=>$cookies) {
            if (preg_match("/".preg_quote(ltrim($domain, "."))."$/", $this->host)) {
                foreach ($cookies as $name=>$value) {
                    if (is_array($value)) {
                        foreach ($value as $k=>$v) {
                            $key = $name . "[$k]";
                            $cookies_combined[] = $key."=".$v;
                        }
                    } else {
                        $cookies_combined[] = $name."=".$value;
                    }
                }
            }
        }

        if (!empty($cookies_combined)) {
            $headers[] = "cookie: " . implode("; ", $cookies_combined);
        }

        if (!empty($this->request_headers)) {
            foreach ($this->request_headers as $name => $value) {
                $headers[] =  $name . ": " . $value;
            }
        }

        if ($this->post_data && $this->http_method == "POST") {
            $headers[] = "content-type: application/x-www-form-urlencoded";
            $headers[] = "content-length: " . strlen($this->post_data);
            if ($this->debug) {
                var_dump($headers);
            }
            return implode("\r\n", $headers) . "\r\n\r\n" . $this->post_data;
        } else {
            if ($this->debug) {
                var_dump(implode("\r\n", $headers));
            }
            return implode("\r\n", $headers) . "\r\n\r\n";
        }
    }

    /*
     * This function only makes sense if called right after asyncLoop()
     * It will let you know whether the last read operation had eny data or it was empty
     * If it was empty you can consider using stream_select() on the socket for this object.
     */

    public function wasEmptyRead() {
        return $this->emptyRead;
    }

    private function initBodyStream() {
        $max_memory = 1024 * 1024 * 5;
        $this->body_stream = fopen("php://temp/maxmemory:$max_memory", "w+");
    }

    private function isSecure() {
        return HttpClient::$secure_connections[(int)$this->sock];
    }

    private function acquireConnection() {
        $this->logConnectionUsage();
        if ($this->connection_reuse) {
            $index = array_search($this->sock, HttpClient::$free_connections);
            if ($index !== false) {
                array_splice(HttpClient::$free_connections, $index, 1);
            }
        }
        return true;
    }

    private function freeConnection() {
        if ($this->connection_reuse) {
            HttpClient::$free_connections[] = $this->sock;
            if (count(HttpClient::$free_connections) > HttpClient::$MAX_FREE_CONNECTIONS) {
                self::_disconnect(array_shift(HttpClient::$free_connections));
            }
        }
    }

    private function logConnectionUsage() {
        HttpClient::$backtraces[(int)$this->sock] = [
            "url" => $this->URL,
            "backtrace" => debug_backtrace(DEBUG_BACKTRACE_IGNORE_ARGS)
        ];
    }

    private function error_sink($errno, $errstr) {}
}
