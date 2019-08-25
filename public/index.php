<?php

ini_set('display_errors', 'On');

require_once __DIR__ . '/../vendor/autoload.php';
require_once 'functions.php';

$cacheFolder = __DIR__ . '/../cache/';
define('PACKAGES_FILE', $cacheFolder . 'packages.json');

Dotenv\Dotenv::create(__DIR__.'/../')->load();

define('PORT', intval(getenv('PORT')));

$validMethods = ['ssh', 'http'];
if (getenv('METHOD') && in_array(getenv('METHOD'), $validMethods)) {
    define('METHOD', getenv('METHOD'));
} else {
    define('METHOD', 'ssh');
}

clearCacheOnConfigChange($cacheFolder, __DIR__.'/../.env', PACKAGES_FILE);
//clearCache($cacheFolder); // when debugging always clear cache

$parts = array_filter(explode('/', trim($_SERVER['REQUEST_URI'], '/')));

if (empty($_SERVER['REQUEST_URI'])) {
    die('something went wrong');
}
if ($_SERVER['REQUEST_URI'] === '/') {
    handleOverview();
}
if ($parts[0] === 'packages.json') {
    handlePackageList();
}

if (!empty($parts[1])) {
    if (empty($_GET['path'])) {
        http_response_code(500);
        die('invalid request');
    }
    handleProxy(getenv('ENDPOINT').$_GET['path']);
} else {
    handleSinglePackage(trim($_SERVER['REQUEST_URI'], '/'));
}
