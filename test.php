<?php

use NitroPack\NitroPack;
use NitroPack\PurgeType;

require_once __DIR__ . '/vendor/autoload.php';

$nitro = new NitroPack('site_id', 'secret');

$nitro->purgeCache('https://trippsremovals.co.uk/', null, PurgeType::COMPLETE, 'TEST');
