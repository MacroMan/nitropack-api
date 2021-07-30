<?php

namespace NitroPack;

use NitroPack\Api\Response;
use NitroPack\Exceptions\VariationCookieException;
use NitroPack\Exceptions\WebhookException;
use \NitroPack\Website as Website;
use stdClass;

/**
 * Class Api
 */
class Api {
    private $cache;
    private $tagger;
    private $url;
    private $allowedWebhooks = array('config', 'cache_clear', 'cache_ready', 'sitemap');

    /**
     * Constructor
     *
     * @param string $siteId
     * @param string $siteSecret
     */
    public function __construct(string $siteId, string $siteSecret) {
        $this->cache = new Api\Cache($siteId, $siteSecret);
        $this->tagger = new Api\Tagger($siteId, $siteSecret);
        $this->url = new Api\Url($siteId, $siteSecret);
        $this->stats = new Api\Stats($siteId, $siteSecret);
        $this->webhook = new Api\Webhook($siteId, $siteSecret);
        $this->warmup = new Api\Warmup($siteId, $siteSecret);
        $this->integration = new Api\Integration($siteId, $siteSecret);
        $this->variation_cookie = new Api\VariationCookie($siteId, $siteSecret);
    }

    /**
     * @param string $url
     * @param string $userAgent
     * @param array $cookies
     * @param bool $isAjax
     * @param string $layout
     *
     * @return \NitroPack\Api\Response
     */
    public function getCache(string $url, string $userAgent, array $cookies, bool $isAjax, string $layout): Response
    {
        return $this->cache->get($url, $userAgent, $cookies, $isAjax, $layout);
    }

    /**
     * @return array
     */
    public function getLastCachePurge(): array
    {
        return $this->cache->getLastPurge();
    }

    /**
     * @param string|null $url
     * @param bool $pagecacheOnly
     * @param string|null $reason
     *
     * @return bool
     */
    public function purgeCache(?string $url = NULL, bool $pagecacheOnly = false, ?string $reason = NULL): bool
    {
        return $this->cache->purge($url, $pagecacheOnly, $reason);
    }

    /**
     * @param string $tag
     * @param string|null $reason
     *
     * @return array
     */
    public function purgeCacheByTag(string $tag, ?string $reason = NULL): array
    {
        return $this->cache->purgeByTag($tag, $reason);
    }

    /**
     * @param int $page
     * @param int $limit
     * @param string|null $search
     * @param string|null $deviceType
     * @param int|null $status
     *
     * @return array
     */
    public function getUrls(int $page = 1, int $limit = 250, ?string $search = NULL, ?string $deviceType = NULL, ?int $status = NULL): array
    {
        return $this->url->get($page, $limit, $search, $deviceType, $status);
    }

    /**
     * @param string|null $search
     * @param string|null $deviceType
     * @param int|null $status
     *
     * @return int
     */
    public function getUrlsCount(?string $search = NULL, ?string $deviceType = NULL, ?int $status = NULL): int
    {
        $resp = $this->url->count($search, $deviceType, $status);
        return (int)$resp["count"];
    }

    /**
     * @param int $page
     * @param int $limit
     * @param int|null $priority
     *
     * @return array
     */
    public function getPendingUrls(int $page = 1, int $limit = 250, int $priority = NULL): array
    {
        return $this->url->getpending($page, $limit, $priority);
    }

    /**
     * @param int|null $priority
     *
     * @return int
     */
    public function getPendingUrlsCount(int $priority = NULL): int
    {
        $resp = $this->url->pendingCount($priority);
        return (int)$resp["count"];
    }

    /**
     * @param string $url
     * @param string $tag
     *
     * @return bool
     */
    public function tagUrl(string $url, string $tag): bool
    {
        $resp = @json_decode($this->tagger->tag($url, $tag)->getBody());
        if ($resp && !$resp->success) {
            $msg = $resp->error ? $resp->error : "Unable to tag URL";
            throw new \RuntimeException($msg);
        }

        return true;
    }

    /**
     * @param string $url
     * @param string $tag
     *
     * @return mixed
     */
    public function untagUrl(string $url, string $tag) {
        $resp = json_decode($this->tagger->remove($url, $tag)->getBody(), true);
        return $resp['removed'];
    }

    /**
     * @param string $tag
     * @param int $page
     * @param int $limit
     *
     * @return array
     */
    public function getTaggedUrls(string $tag, int $page = 1, int $limit = 250): array
    {
        return $this->tagger->getUrls($tag, $page, $limit);
    }

    /**
     * @param string $tag
     *
     * @return int
     */
    public function getTaggedUrlsCount(string $tag): int
    {
        $resp = $this->tagger->getUrlsCount($tag);
        return (int)$resp["count"];
    }

    /**
     * @param string|null $url
     * @param int $page
     * @param int $limit
     *
     * @return array
     */
    public function getTags(string $url = NULL, int $page = 1, int $limit = 250): array
    {
        return $this->tagger->getTags($url, $page, $limit);
    }

    /**
     * @return array
     */
    public function getSavings(): array
    {
        return $this->stats->getSavings();
    }

    /**
     * @return array
     */
    public function getDiskUsage(): array
    {
        return $this->stats->getDiskUsage();
    }

    /**
     * @return array
     */
    public function getRequestUsage(): array
    {
        return $this->stats->getRequestUsage();
    }

    /**
     * @return bool
     */
    public function resetSavingsStats(): bool
    {
        return $this->stats->resetSavings();
    }

    /**
     * @return bool
     */
    public function enableWarmup(): bool
    {
        return $this->warmup->enable();
    }

    /**
     * @return bool
     */
    public function disableWarmup(): bool
    {
        return $this->warmup->disable();
    }

    /**
     * @return bool
     */
    public function resetWarmup(): bool {
        return $this->warmup->reset();
    }

    /**
     * @param string $url
     *
     * @return bool
     */
    public function setWarmupSitemap(string $url): bool
    {
        return $this->warmup->setSitemap($url);
    }

    /**
     * @return bool
     */
    public function unsetWarmupSitemap(): bool {
        return $this->warmup->setSitemap(NULL);
    }

    /**
     * @param string $url
     *
     * @return bool
     */
    public function setWarmupHomepage(string $url): bool
    {
        return $this->warmup->setHomepage($url);
    }

    /**
     * @return bool
     */
    public function unsetWarmupHomepage(): bool
    {
        return $this->warmup->setHomepage(NULL);
    }

    /**
     * @param string|null $id
     * @param string|null $urls
     *
     * @return int|string
     */
    public function estimateWarmup(?string $id = NULL, ?string $urls = NULL)
    {
        $resp = $this->warmup->estimate($id, $urls);
        if ($id) {
            return (int)$resp["count"];
        } else {
            return $resp["id"];
        }
    }

    /**
     * @param array|null $urls
     * @param bool $force
     *
     * @return bool
     */
    public function runWarmup(?array $urls = NULL, bool $force = false): bool
    {
        return $this->warmup->run($urls, $force);
    }

    /**
     * @return array
     */
    public function getWarmupStats(): array
    {
        return $this->warmup->stats();
    }

    /**
     * @param string $type
     *
     * @return void
     * @throws \NitroPack\Exceptions\WebhookException
     */
    public function unsetWebhook(string $type): void
    {
        if (!in_array($type, $this->allowedWebhooks)) {
            throw new WebhookException("The webhook type '$type' is not supported!");
        }

        try {
            $this->webhook->set($type, null);
        } catch (\RuntimeException $e) {
            throw new WebhookException($e->getMessage());
        }
    }

    /**
     * @param string $type
     * @param \NitroPack\Url\Url $url
     *
     * @throws \NitroPack\Exceptions\WebhookException
     */
    public function setWebhook(string $type, Url\Url $url): void
    {
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

    /**
     * @param string $type
     *
     * @return string
     * @throws \NitroPack\Exceptions\WebhookException
     */
    public function getWebhook(string $type): string {
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

    /**
     * @param string $name
     * @param array $values
     * @param String|null $group
     *
     * @return bool
     * @throws \NitroPack\Exceptions\VariationCookieException
     */
    public function setVariationCookie(string $name, array $values = array(), ?String $group = null): bool
    {
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

    /**
     * @param string $name
     *
     * @return bool
     * @throws \NitroPack\Exceptions\VariationCookieException
     */
    public function unsetVariationCookie(string $name): bool {
        if (!is_string($name) || trim($name) == "") {
            throw new VariationCookieException("The provided cookie name is not a string or is empty.");
        }

        try {
            return $this->variation_cookie->delete($name);
        } catch (\RuntimeException $e) {
            throw new VariationCookieException($e->getMessage());
        }
    }

    /**
     * @return array
     * @throws \NitroPack\Exceptions\VariationCookieException
     */
    public function getVariationCookies(): array
    {
        try {
            return $this->variation_cookie->get();
        } catch (\RuntimeException $e) {
            throw new VariationCookieException($e->getMessage());
        }
    }

    /**
     * @param \NitroPack\Website $website
     *
     * @return \NitroPack\Website
     * @throws \Exception
     */
    public function createWebsite(Website $website): Website {
        return $this->integration->create($website);
    }

    /**
     * @param \NitroPack\Website $website
     *
     * @return \NitroPack\Website
     */
    public function updateWebsite(Website $website): Website {
        return $this->integration->update($website);
    }

    /**
     * @param string $apikey
     *
     * @return bool
     * @throws \Exception
     */
    public function removeWebsite(string $apikey): bool
    {
        return $this->integration->remove($apikey);
    }

    /**
     * Get a single website via API key
     *
     * @param string $apikey
     *
     * @return \NitroPack\Website
     * @throws \Exception
     */
    public function getWebsiteByAPIKey(string $apikey): Website {
        return $this->integration->readByAPIKey($apikey);
    }

    /**
     * Get all websites, along with a corresponding pagination
     *
     * @param int $page
     * @param int $limit
     *
     * @return \stdClass
     * @throws \Exception
     */
    public function getWebsitesPaginated(int $page, int $limit = 250): stdClass {
        return $this->integration->readPaginated($page, $limit);
    }
}
