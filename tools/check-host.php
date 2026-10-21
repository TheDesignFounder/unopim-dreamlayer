<?php

$host = getenv('UNOPIM_HOST_ROOT') ?: getcwd();
if (! is_file($host.'/vendor/autoload.php')) {
    fwrite(STDERR, "Run from the UnoPIM project root after composer install, or set UNOPIM_HOST_ROOT.\n");
    exit(1);
}
require $host.'/vendor/autoload.php';

$requirements = [
    'composer references' => defined('Webkul\\AiAgent\\Chat\\ExtensionRegistry::COMPOSER_REFERENCES')
        && constant('Webkul\\AiAgent\\Chat\\ExtensionRegistry::COMPOSER_REFERENCES') >= 2,
    'result rendering' => class_exists('Webkul\\AiAgent\\Chat\\ResultRendererRegistry'),
    'tool decoration' => method_exists('Webkul\\AiAgent\\Chat\\ToolRegistry', 'decorate'),
    'follow-up image context' => property_exists('Webkul\\AiAgent\\Chat\\ChatContext', 'hasNewImages'),
    'saved result presentation' => class_exists('Webkul\\AiAgent\\Chat\\MessageDisplay'),
    'platform-aware embedding ranking' => method_exists('Webkul\\AiAgent\\Services\\EmbeddingSimilarityService', 'rankOrFail'),
];
foreach ($requirements as $name => $available) {
    echo ($available ? 'PASS: ' : 'MISSING: ').$name.PHP_EOL;
}
if (in_array(false, $requirements, true)) {
    fwrite(STDERR, "Upgrade to the supported UnoPIM baseline. See docs/CORE_PATCH.md; no files were changed.\n");
    exit(1);
}
echo "Required hooks are available. No core patch is needed for the documented merged baseline.\n";
