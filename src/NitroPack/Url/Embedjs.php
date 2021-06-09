<?php
namespace NitroPack\Url;

use NitroPack\Url as BaseUrl;

class Embedjs extends BaseUrl {
    const EMBEDJS_BASE = 'https://nitropack.io/asset/js';

    public function __construct() {
        parent::__construct(self::EMBEDJS_BASE . "/embed.js");
    }
}
