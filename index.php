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
    $cookie = getenv('METING_QQ_COOKIE');
    if ($cookie !== false && $cookie !== '') {
        return $cookie;
    }
    $file = __DIR__ . '/config.php';
    if (is_file($file)) {
        $meting_config = array();
        include $file;
        if (!empty($meting_config['qq_cookie'])) {
            return $meting_config['qq_cookie'];
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
$size = isset($_GET['size']) ? intval($_GET['size']) : 300;
$n    = isset($_GET['n']) ? max(1, intval($_GET['n'])) : (isset($_GET['limit']) ? max(1, intval($_GET['limit'])) : 30);
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
        fail($e->getMessage(), 502);
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
