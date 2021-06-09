<?php
namespace NitroPack\HttpClient;

class HttpConfig {
    private $cookieJar;
    private $referer;
    private $userAgent;

    public function __construct() {
        $this->cookieJar = NULL;
        $this->referer = NULL;
        $this->userAgent = NULL;
    }

    public function setCookieJar($cookieJar = NULL) {
        $this->cookieJar = $cookieJar;
    }

    public function getCookieJar() {
        return $this->cookieJar;
    }

    public function setReferer($referer = NULL) {
        $this->referer = $referer;
    }

    public function getReferer() {
        return $this->referer;
    }

    public function setUserAgent($userAgent = NULL) {
        $this->userAgent = $userAgent;
    }

    public function getUserAgent() {
        return $this->userAgent;
    }
}
