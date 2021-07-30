<?php

use NitroPack\NitroPack;
use NitroPack\PurgeType;

require_once __DIR__ . '/vendor/autoload.php';

$nitro = new NitroPack('site_id', 'secret');

$nitro->purgeCache('https://example.com/', null, PurgeType::COMPLETE, 'TEST');
