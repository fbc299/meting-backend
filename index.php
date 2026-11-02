<?php
/**
 * Meting 音乐聚合 API (PHP 原生, 无框架)
 * 基于 metowolf/Meting (archive/php 分支) 的 Metowolf\Meting 类
 *
 * 用法 (全部 GET):
 *   搜索    /?type=search&source=netease&name=海阔天空&s=0&n=5
 *   播放URL /?type=url&source=netease&id=<id>&br=320
 *   歌词    /?type=lyric&source=netease&id=<id>
 *   封面    /?type=pic&source=netease&id=<id>&size=300
 *   单曲详情/?type=song&source=netease&id=<id>
 *   专辑    /?type=album&source=netease&id=<id>
 *   歌手    /?type=artist&source=netease&id=<id>&n=50
 *   歌单    /?type=playlist&source=netease&id=<id>
 *
 * source 也可写作 server; qq = tencent
 */

error_reporting(E_ALL & ~E_NOTICE & ~E_DEPRECATED & ~E_WARNING);
ini_set('display_errors', '0');
ini_set('log_errors', '1');

require __DIR__ . '/src/Meting.php';

use Metowolf\Meting;

header('Access-Control-Allow-Origin: *');
header('Access-Control-Allow-Methods: GET, OPTIONS');
header('Access-Control-Allow-Headers: *');
header('Content-Type: application/json; charset=utf-8');
header('Cache-Control: no-store');

if (isset($_SERVER['REQUEST_METHOD']) && $_SERVER['REQUEST_METHOD'] === 'OPTIONS') {
    http_response_code(204);
    exit;
}

// 公开部署防护：每 IP 60 秒最多 300 次（5 req/s），超限 429。
// 300 是给「首次封面刮削」留的余量：整库逐首串行搜索约 90–150 req/min；仍能挡住滥用型刷接口。
$rateLimit = (int) getenv('METING_RATE_LIMIT');
rate_limit($rateLimit > 0 ? $rateLimit : 300, 60);

function respond($payload, $status = 200)
{
    http_response_code($status);
    echo json_encode($payload, JSON_UNESCAPED_UNICODE | JSON_UNESCAPED_SLASHES);
    exit;
}

function fail($message, $status = 400)
{
    respond(array('code' => 1, 'message' => $message), $status);
}

/**
 * 轻量按 IP 限流（滑动 60 秒窗口，计数落盘 + flock 原子，PHP 多 worker 共用）。
 * 公开部署下防止被当成免费音乐 API 刷：默认每 IP 60 秒最多 120 次请求。
 * 超限返回 429，客户端（App）应把它当作「稍后再试」而不是致命错误。
 */
function rate_limit($limit = 120, $windowSec = 60)
{
    $ip = isset($_SERVER['REMOTE_ADDR']) ? (string) $_SERVER['REMOTE_ADDR'] : 'unknown';
    $dir = __DIR__ . '/logs/ratelimit';
    if (!is_dir($dir)) {
        @mkdir($dir, 0700, true);
    }
    if (!is_dir($dir) || !is_writable($dir)) {
        return; // 目录不可写时不影响正常服务
    }
    $file = $dir . '/' . preg_replace('/[^0-9a-fA-F:.]/', '', $ip) . '.json';
    $now  = time();
    $fp   = @fopen($file, 'c+');
    if ($fp === false) {
        return;
    }
    try {
        if (!flock($fp, LOCK_EX)) {
            return;
        }
        $raw    = stream_get_contents($fp);
        $bucket = json_decode((string) $raw, true);
        if (!is_array($bucket) || !isset($bucket['start'], $bucket['count'])
            || ($now - (int) $bucket['start']) >= $windowSec) {
            $bucket = array('start' => $now, 'count' => 0);
        }
        $bucket['count'] = (int) $bucket['count'] + 1;
        $retry = max(1, $windowSec - ($now - (int) $bucket['start']));
        ftruncate($fp, 0);
        rewind($fp);
        fwrite($fp, json_encode($bucket));
        fflush($fp);
        flock($fp, LOCK_UN);
        if ((int) $bucket['count'] > $limit) {
            header('Retry-After: ' . $retry);
            respond(array(
                'code'    => 1,
                'message' => 'rate limit exceeded, retry after ' . $retry . 's',
            ), 429);
        }
    } finally {
        fclose($fp);
    }
}

/** 把类库返回的 JSON 字符串原样透出（转义改成可读形式） */
function passthru_json($raw, $extra = array())
{
    $decoded = json_decode((string) $raw, true);
    if ($decoded === null && json_last_error() !== JSON_ERROR_NONE) {
        // 上游返回非 JSON（HTML 报错页等）
        respond(array('code' => 1, 'message' => 'upstream returned non-json', 'raw' => substr((string) $raw, 0, 500)), 502);
    }
    if ($extra && is_array($decoded)) {
        $decoded = array_merge($decoded, $extra);
    }
    respond($decoded);
}

function qq_cookie()
{
    return meting_cookie('qq');
}

/**
 * P16 源 cookie 注入: 统一入口, 按源读环境变量或 config.php
 *   kugou  → METING_KUGOU_COOKIE / config.php $meting_config['kugou_cookie']  (酷狗 VIP, VIP 歌出整曲直链)
 *   netease→ METING_NETEASE_COOKIE / config.php $meting_config['netease_cookie'] (网易 VIP)
 * 不配置不影响搜索/歌词/封面, 只是对应源 VIP 歌出不了直链 (kugou 无 cookie 实测 url 为空)
 */
function meting_cookie($source)
{
    $env = 'METING_' . strtoupper($source) . '_COOKIE';
    $cookie = getenv($env);
    if ($cookie !== false && $cookie !== '') {
        return $cookie;
    }
    if (is_file(__DIR__ . '/config.php')) {
        $meting_config = array();
        include __DIR__ . '/config.php';
        $key = $source . '_cookie';
        if (!empty($meting_config[$key])) {
            return $meting_config[$key];
        }
    }
    return '';
}

$type   = isset($_GET['type']) ? strtolower(trim($_GET['type'])) : '';
$source = isset($_GET['source']) ? strtolower(trim($_GET['source']))
        : (isset($_GET['server']) ? strtolower(trim($_GET['server'])) : 'netease');

if ($source === 'qq' || $source === 'tencent_music' || $source === 'qqmusic') {
    $source = 'tencent';
}
$sources = array('netease', 'tencent', 'kugou', 'baidu', 'kuwo', 'xiami', 'migu');
if (!in_array($source, $sources, true)) {
    fail('unknown source, expect one of: ' . implode('/', $sources));
}

$id   = isset($_GET['id']) ? trim($_GET['id']) : '';
$name = isset($_GET['name']) ? trim($_GET['name'])
      : (isset($_GET['keyword']) ? trim($_GET['keyword'])
      : (isset($_GET['w']) ? trim($_GET['w']) : ''));
$br   = isset($_GET['br']) ? intval($_GET['br']) : 320;
$size = isset($_GET['size']) ? min(2000, max(50, intval($_GET['size']))) : 300;
// n 上限 50：无上限时 n=100000 会被原样转发给上游（实测 0.5s 返回，可被用来压上游/触发风控）
$n    = isset($_GET['n']) ? min(50, max(1, intval($_GET['n'])))
      : (isset($_GET['limit']) ? min(50, max(1, intval($_GET['limit']))) : 30);
$s    = isset($_GET['s']) ? max(0, intval($_GET['s'])) : (isset($_GET['offset']) ? max(0, intval($_GET['offset'])) : 0);
$page = isset($_GET['page']) ? max(1, intval($_GET['page'])) : 0;

// 首页/健康检查
if ($type === '' || $type === 'index' || $type === 'help') {
    respond(array(
        'code'    => 0,
        'service' => 'Meting API (self-hosted)',
        'sources' => $sources,
        'endpoints' => array(
            'search'   => '/?type=search&source=netease&name=<关键词>&s=0&n=5',
            'url'      => '/?type=url&source=netease&id=<id>&br=320',
            'lyric'    => '/?type=lyric&source=netease&id=<id>',
            'pic'      => '/?type=pic&source=netease&id=<id>&size=300',
            'song'     => '/?type=song&source=netease&id=<id>',
            'album'    => '/?type=album&source=netease&id=<id>',
            'artist'   => '/?type=artist&source=netease&id=<id>&n=50',
            'playlist' => '/?type=playlist&source=netease&id=<id>',
        ),
        'note' => 'source 亦可写作 server; qq 等同 tencent',
    ));
}

// kuwo / migu 的 search/url/lyric 走本目录 sources.php (翻译自 UnblockNeteaseMusic provider)
require_once __DIR__ . '/sources.php';
if (in_array($source, array('kuwo', 'migu'), true) && in_array($type, array('search', 'url', 'lyric'), true)) {
    try {
        switch ($type) {
            case 'search':
                if ($name === '') {
                    fail('missing param: name');
                }
                respond(sx_search($source, $name, $s, $n));

            case 'url':
                if ($id === '') {
                    fail('missing param: id');
                }
                if ($br <= 0 || $br > 999) {
                    $br = 320;
                }
                respond(sx_url($source, $id, $br));

            case 'lyric':
                if ($id === '') {
                    fail('missing param: id');
                }
                respond(sx_lyric($source, $id));
        }
    } catch (Exception $e) {
        $msg = $e->getMessage();
        // 上游「空结果」不是故障：按 200 + 空数组返回，避免客户端把空结果当网络错误
        if (stripos($msg, 'empty result') !== false) {
            respond(array());
        }
        fail($msg, 502);
    }
}
if ($source === 'migu') {
    fail('migu 目前仅支持 type=search/url/lyric');
}

$meting = new Meting($source);
$meting->format(true);
if ($source === 'tencent') {
    $cookie = qq_cookie();
    if ($cookie !== '') {
        $meting->cookie($cookie);
    }
}
// P16: kugou / netease 也支持 cookie 注入 (VIP 整曲直链); kugou 无 cookie 时 VIP 歌 url 为空
if (in_array($source, array('kugou', 'netease'), true)) {
    $cookie = meting_cookie($source);
    if ($cookie !== '') {
        $meting->cookie($cookie);
    }
}

switch ($type) {
    case 'search':
        if ($name === '') {
            fail('missing param: name');
        }
        if ($page === 0) {
            $page = (int) floor($s / $n) + 1;
        }
        passthru_json($meting->search($name, array('limit' => $n, 'page' => $page)));
        break;

    case 'url':
        if ($id === '') {
            fail('missing param: id');
        }
        if ($br <= 0 || $br > 999) {
            $br = 320;
        }
        $extra = array();
        $raw   = $meting->url($id, $br);
        $check = json_decode((string) $raw, true);
        if (is_array($check) && empty($check['url'])) {
            if ($source === 'tencent' && qq_cookie() === '') {
                $extra['message'] = 'QQ音乐播放链接需要服务端登录 Cookie (METING_QQ_COOKIE / config.php); 搜索和歌词不受影响';
            } else {
                $extra['message'] = 'no playable url from upstream (版权/地区/登录限制)';
            }
        }
        passthru_json($raw, $extra);
        break;

    case 'lyric':
        if ($id === '') {
            fail('missing param: id');
        }
        passthru_json($meting->lyric($id));
        break;

    case 'pic':
    case 'cover':
        if ($id === '') {
            fail('missing param: id');
        }
        passthru_json($meting->pic($id, $size));
        break;

    case 'song':
    case 'detail':
        if ($id === '') {
            fail('missing param: id');
        }
        passthru_json($meting->song($id));
        break;

    case 'album':
        if ($id === '') {
            fail('missing param: id');
        }
        passthru_json($meting->album($id));
        break;

    case 'artist':
        if ($id === '') {
            fail('missing param: id');
        }
        passthru_json($meting->artist($id, $n));
        break;

    case 'playlist':
        if ($id === '') {
            fail('missing param: id');
        }
        passthru_json($meting->playlist($id));
        break;

    default:
        fail('unknown type: ' . $type . ' (expect search/url/lyric/pic/song/album/artist/playlist)');
}
