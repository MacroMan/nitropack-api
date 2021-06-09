# Unofficial NitroPack API SDK

This repo is mainly based on https://nitropack.io/download/plugin/nitropack-php-sdk-limited-support with some areas tidied up and refactored to allow composer installation.

## Installation
`composer require macroman/nitropack-api`

## Usage

```
use NitroPack\NitroPack;
use NitroPack\PurgeType;

// Initialize API
$nitro = new NitroPack('site_id', 'secret');

// Clear entire cache
$nitro->purgeCache();

// Clear specific URL
$nitro->purgeCache('https://example.com/');

// Clear tagged pages
$nitro->purgeCache(null, 'tag-name');

// Clear cache leaving a reason (shows in WordPress settings page)
$nitro->purgeCache(null, null, PurgeType::COMPLETE, 'This is my reason');

// Add a tag to a URL
$nitro->tagUrl('https://example.com/', 'tag-name');

// Enable/disable compression
$nitro->enableCompression();
$nitro->disableCompression();
```

More functionality available, but not documented. Look at `NitroPack\NitroPack.php` for more information.
