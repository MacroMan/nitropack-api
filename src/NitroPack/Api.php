<?php

namespace NitroPack;

use NitroPack\Exceptions\VariationCookieException;
use \NitroPack\Website as Website;

class Api {
    private $cache;
    private $tagger;
    private $url;
    private $allowedWebhooks = array('config', 'cache_clear', 'cache_ready', 'sitemap');

    public function __construct($siteId, $siteSecret) {
        $this->cache = new Api\Cache($siteId, $siteSecret);
        $this->tagger = new Api\Tagger($siteId, $siteSecret);
        $this->url = new Api\Url($siteId, $siteSecret);
        $this->stats = new Api\Stats($siteId, $siteSecret);
        $this->webhook = new Api\Webhook($siteId, $siteSecret);
        $this->warmup = new Api\Warmup($siteId, $siteSecret);
        $this->integration = new Api\Integration($siteId, $siteSecret);
        $this->variation_cookie = new Api\VariationCookie($siteId, $siteSecret);
    }

    public function getCache($url, $userAgent, $cookies, $isAjax, $layout) {
        return $this->cache->get($url, $userAgent, $cookies, $isAjax, $layout);
    }

    public function getLastCachePurge() {
        return $this->cache->getLastPurge();
    }

    public function purgeCache($url = NULL, $pagecacheOnly = false, $reason = NULL) {
        return $this->cache->purge($url, $pagecacheOnly, $reason);
    }

    public function purgeCacheByTag($tag, $reason = NULL) {
        return $this->cache->purgeByTag($tag, $reason);
    }

    public function getUrls($page = 1, $limit = 250, $search = NULL, $deviceType = NULL, $status = NULL) {
        return $this->url->get($page, $limit, $search, $deviceType, $status);
    }

    public function getUrlsCount($search = NULL, $deviceType = NULL, $status = NULL) {
        $resp = $this->url->count($search, $deviceType, $status);
        return (int)$resp["count"];
    }

    public function getPendingUrls($page = 1, $limit = 250, $priority = NULL) {
        return $this->url->getpending($page, $limit, $priority);
    }

    public function getPendingUrlsCount($priority = NULL) {
        $resp = $this->url->pendingCount($priority);
        return (int)$resp["count"];
    }

    public function tagUrl($url, $tag) {
        $resp = @json_decode($this->tagger->tag($url, $tag)->getBody());
        if ($resp && !$resp->success) {
            $msg = $resp->error ? $resp->error : "Unable to tag URL";
            throw new \RuntimeException($msg);
        }

        return true;
    }

    public function untagUrl($url, $tag) {
        $resp = json_decode($this->tagger->remove($url, $tag)->getBody(), true);
        return $resp['removed'];
    }

    public function getTaggedUrls($tag, $page = 1, $limit = 250) {
        return $this->tagger->getUrls($tag, $page, $limit);
    }

    public function getTaggedUrlsCount($tag) {
        $resp = $this->tagger->getUrlsCount($tag);
        return (int)$resp["count"];
    }

    public function getTags($url = NULL, $page = 1, $limit = 250) {
        return $this->tagger->getTags($url, $page, $limit);
    }

    public function getSavings() {
        return $this->stats->getSavings();
    }

    public function getDiskUsage() {
        return $this->stats->getDiskUsage();
    }

    public function getRequestUsage() {
        return $this->stats->getRequestUsage();
    }

    public function resetSavingsStats() {
        return $this->stats->resetSavings();
    }

    public function enableWarmup() {
        return $this->warmup->enable();
    }

    public function disableWarmup() {
        return $this->warmup->disable();
    }

    public function resetWarmup() {
        return $this->warmup->reset();
    }

    public function setWarmupSitemap($url) {
        return $this->warmup->setSitemap($url);
    }

    public function unsetWarmupSitemap() {
        return $this->warmup->setSitemap(NULL);
    }

    public function setWarmupHomepage($url) {
        return $this->warmup->setHomepage($url);
    }

    public function unsetWarmupHomepage() {
        return $this->warmup->setHomepage(NULL);
    }

    public function estimateWarmup($id = NULL, $urls = NULL) {
        $resp = $this->warmup->estimate($id, $urls);
        if ($id) {
            return (int)$resp["count"];
        } else {
            return $resp["id"];
        }
    }

    public function runWarmup($urls = NULL, $force = false) {
        return $this->warmup->run($urls, $force);
    }

    public function getWarmupStats() {
        return $this->warmup->stats();
    }

    public function unsetWebhook($type) {
        if (!in_array($type, $this->allowedWebhooks)) {
            throw new WebhookException("The webhook type '$type' is not supported!");
        }

        try {
            $this->webhook->set($type, null);
        } catch (\RuntimeException $e) {
            throw new WebhookException($e->getMessage());
        }
    }

    public function setWebhook($type, Url\Url $url) {
        if (!in_array($type, $this->allowedWebhooks)) {
            throw new WebhookException("The webhook type '$type' is not supported!");
        }

        if (!filter_var($url->getUrl(), FILTER_VALIDATE_URL)) {
            throw new WebhookException(sprintf("The webhook URL '%s' is invalid!", $url->getUrl()));
        }

        try {
            $this->webhook->set($type, $url->getUrl());
        } catch (\RuntimeException $e) {
            throw new WebhookException($e->getMessage());
        }
    }

    public function getWebhook($type) {
        if (!in_array($type, $this->allowedWebhooks)) {
            throw new WebhookException("The webhook type '$type' is not supported!");
        }

        try {
            $resp = $this->webhook->get($type);
            return $resp["url"];
        } catch (\RuntimeException $e) {
            throw new WebhookException($e->getMessage());
        }
    }

    public function setVariationCookie($name, $values = array(), $group = null) {
        if (!is_array($values) && !is_string($values)) {
            throw new VariationCookieException("The provided values is not an array or a string.");
        } else if (is_array($values)) {
            foreach ($values as $value) {
                if (!is_string($value)) {
                    throw new VariationCookieException("A non-string cookie value has been detected.");
                }
            }
        }

        if (!is_string($name) || trim($name) == "") {
            throw new VariationCookieException("The provided cookie name is not a string or is empty.");
        }

        if (null !== $group && !filter_var($group, FILTER_VALIDATE_INT)) {
            throw new VariationCookieException("The provided group is not null, and it is not a positive integer.");
        }

        try {
            return $this->variation_cookie->set($name, $values, $group);
        } catch (\RuntimeException $e) {
            throw new VariationCookieException($e->getMessage());
        }
    }

    public function unsetVariationCookie($name) {
        if (!is_string($name) || trim($name) == "") {
            throw new VariationCookieException("The provided cookie name is not a string or is empty.");
        }

        try {
            return $this->variation_cookie->delete($name);
        } catch (\RuntimeException $e) {
            throw new VariationCookieException($e->getMessage());
        }
    }

    public function getVariationCookies() {
        try {
            return $this->variation_cookie->get();
        } catch (\RuntimeException $e) {
            throw new VariationCookieException($e->getMessage());
        }
    }

    public function createWebsite(Website $website) {
        return $this->integration->create($website);
    }

    public function updateWebsite(Website $website) {
        return $this->integration->update($website);
    }

    public function removeWebsite($apikey) {
        return $this->integration->remove($apikey);
    }

    // Get a single website via API key
    public function getWebsiteByAPIKey($apikey) {
        return $this->integration->readByAPIKey($apikey);
    }

    // Get all websites, along with a corresponding pagination
    public function getWebsitesPaginated($page, $limit = 250) {
        return $this->integration->readPaginated($page, $limit);
    }
}
