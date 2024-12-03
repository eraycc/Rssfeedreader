<?php
// getrss

// 设置响应头
header('Content-Type: text/xml; charset=utf-8');
header('Access-Control-Allow-Origin: *'); // 建议修改为具体的域名
header('Access-Control-Allow-Methods: GET');

// 配置项
define('CACHE_DIR', __DIR__ . '/cache/'); // 缓存目录
define('RATE_LIMIT_DIR', __DIR__ . '/ratelimit/'); // 频率限制目录
define('CACHE_TIME', 43200); // 缓存时间：12小时
define('RATE_LIMIT', 30); // 频率限制：每分钟30次
define('RATE_LIMIT_WINDOW', 60); // 频率限制窗口：60秒

include __DIR__ . '/allowhosts.php';

// 确保缓存目录存在
if (!file_exists(CACHE_DIR)) {
    mkdir(CACHE_DIR, 0777, true);
}
if (!file_exists(RATE_LIMIT_DIR)) {
    mkdir(RATE_LIMIT_DIR, 0777, true);
}

// 错误响应函数
function returnError($message) {
    $error = '<?xml version="1.0" encoding="UTF-8"?>';
    $error .= '<rss version="2.0">';
    $error .= '<channel>';
    $error .= '<title>获取RSS错误</title>';
    $error .= '<link>#</link>';
    $error .= '<description>' . htmlspecialchars($message) . '</description>';
    $error .= '<item>';
    $error .= '<title>出错辣，' . htmlspecialchars($message) . '</title>';
    $error .= '<link>#</link>';
    $error .= '<description>' . htmlspecialchars($message) . '</description>';
    $error .= '<pubDate>' . date(DATE_RSS, time()) . '</pubDate>';
    $error .= '</item>';
    $error .= '</channel>';
    $error .= '</rss>';
    die($error);
}

// 尝试获取用户的真实IP地址
function getUserIP() {
    $client = $_SERVER['HTTP_CLIENT_IP'] ?? '';
    $forward = $_SERVER['HTTP_X_FORWARDED_FOR'] ?? '';
    $remote = $_SERVER['REMOTE_ADDR'] ?? '';

    if (!empty($forward)) {
        $forward = explode(',', $forward);
        $forward = trim(array_shift($forward));
    }

    if (filter_var($forward, FILTER_VALIDATE_IP)) {
        $ip = $forward;
    } elseif (filter_var($client, FILTER_VALIDATE_IP)) {
        $ip = $client;
    } else {
        $ip = $remote;
    }

    return $ip;
}

// 频率限制检查
function checkRateLimit($ip) {
    $rateFile = RATE_LIMIT_DIR . md5($ip) . '.txt';
    $currentTime = time();
    
    // 读取或初始化请求记录
    if (file_exists($rateFile)) {
        $requests = json_decode(file_get_contents($rateFile), true);
    } else {
        $requests = array();
    }
    
    // 清理旧的请求记录
    $requests = array_filter($requests, function($timestamp) use ($currentTime) {
        return $timestamp > ($currentTime - RATE_LIMIT_WINDOW);
    });
    
    // 检查是否超过限制
    if (count($requests) >= RATE_LIMIT) {
        return false;
    }
    
    // 添加新的请求记录
    $requests[] = $currentTime;
    file_put_contents($rateFile, json_encode($requests));
    return true;
}

// 缓存处理函数
function getCache($url) {
    $cacheFile = CACHE_DIR . md5($url) . '.xml';
    if (file_exists($cacheFile) && (time() - filemtime($cacheFile) < CACHE_TIME)) {
        return file_get_contents($cacheFile);
    }
    return false;
}

function saveCache($url, $content) {
    $cacheFile = CACHE_DIR . md5($url) . '.xml';
    file_put_contents($cacheFile, $content);
}

// 获取和验证URL参数
$rssUrl = isset($_GET['rssurl']) ? $_GET['rssurl'] : '';

if (empty($rssUrl)) {
    returnError('RSS URL is required');
}

if (!filter_var($rssUrl, FILTER_VALIDATE_URL)) {
    returnError('Invalid URL format');
}

// 解析URL并验证主机
$urlParts = parse_url($rssUrl);
if (!isset($urlParts['host'])) {
    returnError('Invalid URL');
}

if (!in_array($urlParts['host'], $allowedHosts)) {
    returnError('Host not allowed: ' . htmlspecialchars($urlParts['host']));
}

// 检查频率限制
$userIP = getUserIP();
if (!checkRateLimit($userIP)) {
    returnError('Rate limit exceeded. Please try again later.');
}

// 检查缓存
$cachedContent = getCache($rssUrl);
if ($cachedContent !== false) {
    echo $cachedContent;
    exit;
}

// 设置请求选项
$options = array(
    'http' => array(
        'method' => 'GET',
        'header' => array(
            'User-Agent: Mozilla/5.0 (Windows NT 6.1; WOW64; rv:53.0) Gecko/20100101 Firefox/53.0'
        ),
        'timeout' => 18
    ),
    'ssl' => array(
        'verify_peer' => true,
        'verify_peer_name' => true
    )
);

// 创建上下文并获取内容
$context = stream_context_create($options);

try {
    $content = @file_get_contents($rssUrl, false, $context);
    
    if ($content === false) {
        $error = error_get_last();
        //returnError('Failed to fetch RSS: ' . $error['message']);
        returnError('Failed to fetch RSS content');
    }

    // 验证XML内容
    $xml = @simplexml_load_string($content);
    if ($xml === false) {
        returnError('Invalid XML content');
    }

    // 保存缓存
    saveCache($rssUrl, $content);

    // 输出内容
    echo $content;

} catch (Exception $e) {
    //returnError('Error: ' . $e->getMessage());
    returnError('Failed to Seed Request');
}

// 清理过期的频率限制记录
function cleanupRateLimitFiles() {
    $files = glob(RATE_LIMIT_DIR . '*.txt');
    $currentTime = time();
    foreach ($files as $file) {
        if ($currentTime - filemtime($file) > RATE_LIMIT_WINDOW) {
            @unlink($file);
        }
    }
}

// 清理过期的缓存文件
function cleanupCacheFiles() {
    $files = glob(CACHE_DIR . '*.xml');
    $currentTime = time();
    foreach ($files as $file) {
        if ($currentTime - filemtime($file) > CACHE_TIME) {
            @unlink($file);
        }
    }
}

// 随机执行清理（概率为1%）
if (rand(1, 100) === 1) {
    cleanupRateLimitFiles();
    cleanupCacheFiles();
}
?>
