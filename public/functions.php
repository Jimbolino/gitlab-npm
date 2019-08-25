<?php

function handleProxy($url)
{
    // https://stackoverflow.com/a/23078568
    function fn_CURLOPT_WRITEFUNCTION($ch, $str)
    {
        $len = strlen($str);
        echo($str);
        return $len;
    }

    function fn_CURLOPT_HEADERFUNCTION($ch, $str)
    {
        $len = strlen($str);
        header($str);
        return $len;
    }

    $ch = curl_init(); // init curl resource
    curl_setopt($ch, CURLOPT_URL, $url);
    curl_setopt($ch, CURLOPT_RETURNTRANSFER, false); // a true curl_exec return content
    curl_setopt($ch, CURLOPT_HTTPHEADER, ['PRIVATE-TOKEN: ' . getenv('API_KEY')]);
    curl_setopt($ch, CURLOPT_HEADER, false); // true Return the HTTP headers in string, no good with CURLOPT_HEADERFUNCTION
    curl_setopt($ch, CURLOPT_HEADERFUNCTION, 'fn_CURLOPT_HEADERFUNCTION'); // handle received headers
    curl_setopt($ch, CURLOPT_WRITEFUNCTION, 'fn_CURLOPT_WRITEFUNCTION'); // called every CURLOPT_BUFFERSIZE

    if (!curl_exec($ch)) {
        http_response_code(500);
        echo 'something went wrong: '.curl_error($ch);
    }

    curl_close($ch); // close curl resource
    die();
}

function handleOverview()
{
    $data = [
        'db_name' => 'registry',
        'doc_count' => 0,
    ];
    outputJson($data);
}

function handleSinglePackage($packageName)
{
    $client = getClient();
    $repos = $client->repositories();
    $allProjects = getAllProjects($client);
    foreach ($allProjects as $project) {
        if (($package = loadData($project, $repos)) && ($package_name = getPackageName($project, $repos))) {
            if ($packageName === $package_name) {
                $latestTag = reset($package); // TODO: newest should not always be the "latest"
                $return = [
                    'name' => $packageName,
                    'description' => @$latestTag['description'],
                    'dist-tags' => ['latest' => $latestTag['version']],
                    'versions' => $package,
                ];
                outputJson($return);
                break;
            }
        }
    }

    header('Location: https://registry.npmjs.org/'.$packageName); // redirect everything i dont know to npmjs.org
    die();
}

/**
 * @return Gitlab\Client
 */
function getClient()
{
    return Gitlab\Client::create(getenv('ENDPOINT'))->authenticate(getenv('API_KEY'), Gitlab\Client::AUTH_URL_TOKEN);
}

function getAllProjects($client)
{
    $projects = $client->projects();

    // Load projects
    $all_projects = [];

    // We have to get all accessible projects
    for ($page = 1; count($p = $projects->all(['page' => $page, 'per_page' => 100])); $page++) {
        foreach ($p as $project) {
            $all_projects[] = $project;
        }
    }
    return $all_projects;
}

function getMaxMtime($allProjects)
{
    $mtime = 0;
    foreach ($allProjects as $project) {
        $mtime = max($mtime, strtotime($project['last_activity_at']));
    }

    return $mtime;
}

function handlePackageList()
{
    $client = getClient();
    $allProjects = getAllProjects($client);
    $repos = $client->repositories();

    // Regenerate packages_file is needed
    if (!file_exists(PACKAGES_FILE) || filemtime(PACKAGES_FILE) < getMaxMtime($allProjects)) {
        $packages = [];
        foreach ($allProjects as $project) {
            if (($package = loadData($project, $repos)) && ($package_name = getPackageName($project, $repos))) {
                $packages[$package_name] = $package;
            }
        }

        $data = json_encode([
            'packages' => array_filter($packages),
        ], JSON_PRETTY_PRINT);

        file_put_contents(PACKAGES_FILE, $data);
        @chmod(0777, PACKAGES_FILE);
    }

    outputFile(PACKAGES_FILE);
}



/**
 * @param $file
 * Output a json file, sending max-age header, then dies
 */
function outputFile($file)
{
    $mtime = filemtime($file);

    header('Content-Type: application/json');
    header('Last-Modified: ' . gmdate('r', $mtime));
    header('Cache-Control: max-age=0');

    if (!empty($_SERVER['HTTP_IF_MODIFIED_SINCE']) && ($since = strtotime($_SERVER['HTTP_IF_MODIFIED_SINCE'])) && $since >= $mtime) {
        header('HTTP/1.0 304 Not Modified');
    } else {
        readfile($file);
    }
    die();
}

function outputJson($data)
{
    header('Content-Type: application/json');
    header('Cache-Control: max-age=0');
    echo json_encode($data, JSON_PRETTY_PRINT);
    die();
}

/**
 * Retrieves some information about a project's package.json
 *
 * @param array $project
 * @param string $ref commit id
 * @param Gitlab\Api\Repositories $repos
 * @return array|false
 */
function fetchPackage($project, $ref, $repos)
{
    try {
        $c = $repos->getFile($project['id'], 'package.json', $ref);

        if (!isset($c['content'])) {
            return false;
        }

        return json_decode(base64_decode($c['content']), true);
    } catch (Gitlab\Exception\RuntimeException $e) {
        return false;
    }
}

/**
 * Retrieves some information about a project for a specific ref
 *
 * @param array $project
 * @param array $ref
 * @param Gitlab\Api\Repositories $repos
 * @return array   [$version => ['name' => $name, 'version' => $version, 'source' => [...]]]
 */
function fetchRef($project, $ref, $repos)
{
    static $ref_cache = [];

    $ref_key = md5(serialize($project) . serialize($ref));

    if (isset($ref_cache[$ref_key])) {
        return $ref_cache[$ref_key];
    }
    $version = checkVersion($ref['name']);
    $data = fetchPackage($project, $ref['commit']['id'], $repos);

    if ($data !== false) {
        $data['version'] = $version;
        $url = $project[METHOD . '_url_to_repo'];
        if (METHOD == 'ssh' && PORT != '') {
            $url = 'ssh://' . strstr($project['ssh_url_to_repo'], ':', true);
            $url .= ':' . PORT . '/' . $project['path_with_namespace'];
        }
        $data['repository'] = [
            'type' => 'git',
            'url' => $url,
            'reference' => $ref['commit']['id'],
        ];

        $path = '/projects/' . $project['id'] . '/repository/archive.tgz?sha=' . $ref['commit']['id'];
        $data['dist']['tarball'] = $_SERVER['REQUEST_SCHEME'] . '://' . $_SERVER['HTTP_HOST'] . '/gitlab/proxy?path=' . urlencode($path);

        $ref_cache[$ref_key] = [$version => $data];
        return $ref_cache[$ref_key];
    }
    $ref_cache[$ref_key] = [];

    return $ref_cache[$ref_key];
}

/**
 * Transforms v1.0.0 -> 1.0.0
 * @param $tagOrBranch
 * @return string
 */
function checkVersion($tagOrBranch)
{
    if (validateVersion($tagOrBranch)) {
        return $tagOrBranch;
    }
    $trimmed = ltrim($tagOrBranch, 'v');
    if (validateVersion($trimmed)) {
        return $trimmed;
    }

    return $tagOrBranch;
}

/**
 * Validates if we are using semver
 * @param $tagOrBranch
 * @return false|int
 */
function validateVersion($tagOrBranch)
{
    // official regex from: https://semver.org/#is-there-a-suggested-regular-expression-regex-to-check-a-semver-string
    $regex = '/^(?P<major>0|[1-9]\d*)\.(?P<minor>0|[1-9]\d*)\.(?P<patch>0|[1-9]\d*)(?:-(?P<prerelease>(?:0|[1-9]\d*|\d*[a-zA-Z-][0-9a-zA-Z-]*)(?:\.(?:0|[1-9]\d*|\d*[a-zA-Z-][0-9a-zA-Z-]*))*))?(?:\+(?P<buildmetadata>[0-9a-zA-Z-]+(?:\.[0-9a-zA-Z-]+)*))?$/';
    return preg_match($regex, $tagOrBranch);
}


/**
 * Retrieves some information about a project for all refs
 * @param array $project
 * @param Gitlab\Api\Repositories $repos
 * @return array   Same as $fetch_ref, but for all refs
 */
function fetchRefs($project, $repos)
{
    $return = [];
    try {
        $refs = $repos->tags($project['id']);

        if (getenv('INCLUDE_BRANCHES')) { // branches are optional
            $branches = $repos->branches($project['id']);
            $refs = array_merge($refs, $branches);
        }

        foreach ($refs as $ref) {
            foreach (fetchRef($project, $ref, $repos) as $version => $data) {
                if (validateVersion($version)) {
                    $return[$version] = $data;
                }
            }
        }
    } catch (Gitlab\Exception\RuntimeException $e) {
        // The repo has no commits — skipping it.
    }

    return $return;
};

/**
 * Caching layer on top of $fetch_refs
 * Uses last_activity_at from the $project array, so no invalidation is needed
 *
 * @param array $project
 * @param Gitlab\Api\Repositories $repos
 * @return array Same as $fetch_refs
 */
function loadData($project, $repos)
{
    $file = __DIR__ . "/../cache/{$project['path_with_namespace']}.json";
    $mtime = strtotime($project['last_activity_at']);

    if (!is_dir(dirname($file))) {
        mkdir(dirname($file), 0777, true);
    }

    if (file_exists($file) && filemtime($file) >= $mtime) {
        if (filesize($file) > 0) {
            return json_decode(file_get_contents($file), true);
        } else {
            return false;
        }
    } elseif ($data = fetchRefs($project, $repos)) {
        file_put_contents($file, json_encode($data, JSON_PRETTY_PRINT));
        touch($file, $mtime);
        @chmod(0777, $file);

        return $data;
    } else {
        $f = fopen($file, 'w');
        fclose($f);
        touch($file, $mtime);
        @chmod(0777, $file);

        return false;
    }
}

/**
 * Determine the name to use for the package.
 *
 * @param array $project
 * @param Gitlab\Api\Repositories $repos
 * @return string The name of the project
 */
function getPackageName($project, $repos)
{
    $ref = fetchRef($project, $repos->branch($project['id'], $project['default_branch']), $repos);
    $first = reset($ref);
    if (!empty($first['name'])) {
        return $first['name'];
    }

    return false;
    //return $project['path_with_namespace'];
}

/**
 * Clear the cache folder if the .env is newer than the packages.json file
 * @param string $cache_folder
 * @param string $config_file
 * @param string $packages_file
 * @return bool
 */
function clearCacheOnConfigChange($cache_folder, $config_file, $packages_file)
{
    if (!is_dir($cache_folder)) {
        die('cache folder: '.$cache_folder.' does not exist');
    }
    if (!is_writable($cache_folder)) {
        die('cache folder: '.$cache_folder.' is not writable');
    }
    if (!file_exists($packages_file)) {
        return false;
    }
    if (filemtime($config_file) < filemtime($packages_file)) {
        return false;
    }

    return clearCache($cache_folder);
}

function clearCache($cacheFolder)
{
    if (!is_dir($cacheFolder) || strlen($cacheFolder) < 20) {
        die('clear_cache_on_config_change safety check failed');
    }
    shell_exec('rm -rf '.$cacheFolder.'/*');
    return true;
}
