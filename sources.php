<?php
/**
 * kuwo / migu 自定义源实现 (search / url / lyric)
 *
 * 逻辑翻译自 UnblockNeteaseMusic/server (参考克隆于 /tmp/unblock-ref, 只读):
 *   - src/provider/kuwo.js   : search -> search.kuwo.cn/r.s ; track -> antiserver convert_url
 *   - src/provider/migu.js   : track  -> app.c.nf.migu.cn strategy/listen-url (签名链过深, 改用等价的
 *                              MIGUM2.0/v2.0/content/listen-url, 同 host 同 headers, 实测可用)
 *   - migu search            : provider 里的 m.music.migu.cn/remoting/scr_search_tag 已 301 变 SPA(失效),
 *                              改用同族的 MIGUM2.0/v1.0/content/search_all.do (实测可用)
 *   - kuwo 歌词              : m.kuwo.cn/newh5/singles/songinfoandlrc (返回 lrclist, 本地拼 LRC)
 *   - migu 歌词              : listen-url 响应 songItem.lrcUrl 直取 LRC 文件
 *
 * 返回结构与 index.php / Meting format_* 对齐。
 */

define('SX_UA_PC', 'Mozilla/5.0 (Windows NT 10.0; Win64; x64) AppleWebKit/537.36 (KHTML, like Gecko) Chrome/120.0.0.0 Safari/537.36');
define('SX_UA_OKHTTP', 'okhttp/3.10.0');
define('SX_UA_MIGU', 'Mozilla/5.0 (Linux; Android 12) MiguMusic/5.0.1');
define('SX_UA_IPHONE', 'Mozilla/5.0 (iPhone; CPU iPhone OS 15_0 like Mac OS X) AppleWebKit/605.1.15 (KHTML, like Gecko) Mobile/15E148');

/** GET, 返回 ['status'=>int,'body'=>string,'ctype'=>string,'error'=>string] */
function sx_get($url, $headers = array(), $timeout = 15)
{
    $h = array();
    foreach ($headers as $k => $v) {
        $h[] = $k . ': ' . $v;
    }
    $ch = curl_init();
    curl_setopt_array($ch, array(
        CURLOPT_URL            => $url,
        CURLOPT_RETURNTRANSFER => true,
        CURLOPT_HEADER         => false,
        CURLOPT_TIMEOUT        => $timeout,
        CURLOPT_CONNECTTIMEOUT => 8,
        CURLOPT_ENCODING       => '',
        CURLOPT_SSL_VERIFYPEER => false,
        CURLOPT_SSL_VERIFYHOST => false,
        CURLOPT_FOLLOWLOCATION => true,
        CURLOPT_MAXREDIRS      => 5,
        CURLOPT_IPRESOLVE      => CURL_IPRESOLVE_V4,
        CURLOPT_HTTPHEADER     => $h,
    ));
    $body = curl_exec($ch);
    $info = curl_getinfo($ch);
    $err  = curl_error($ch);
    curl_close($ch);

    return array(
        'status' => (int) $info['http_code'],
        'body'   => $body === false ? '' : $body,
        'ctype'  => (string) $info['content_type'],
        'error'  => $err,
    );
}

/** 宽容解码: 去掉字符串里的控制字符 (上游 JSON 偶发裸控制符) */
function sx_json($body)
{
    $body = trim((string) $body);
    if ($body === '') {
        return null;
    }
    $data = json_decode($body, true);
    if (is_array($data)) {
        return $data;
    }
    $clean = preg_replace('/[\x00-\x08\x0b\x0c\x0e-\x1f]/', ' ', $body);
    $data  = json_decode($clean, true);

    return is_array($data) ? $data : null;
}

/** HEAD 取文件大小, 拿不到返回 0 */
function sx_size($url, $headers = array())
{
    $h = array();
    foreach ($headers as $k => $v) {
        $h[] = $k . ': ' . $v;
    }
    $ch = curl_init();
    curl_setopt_array($ch, array(
        CURLOPT_URL            => $url,
        CURLOPT_NOBODY         => true,
        CURLOPT_HEADER         => true,
        CURLOPT_RETURNTRANSFER => true,
        CURLOPT_TIMEOUT        => 10,
        CURLOPT_SSL_VERIFYPEER => false,
        CURLOPT_SSL_VERIFYHOST => false,
        CURLOPT_FOLLOWLOCATION => true,
        CURLOPT_IPRESOLVE      => CURL_IPRESOLVE_V4,
        CURLOPT_HTTPHEADER     => $h,
    ));
    curl_exec($ch);
    $len = (int) curl_getinfo($ch, CURLINFO_CONTENT_LENGTH_DOWNLOAD);
    curl_close($ch);

    return $len > 0 ? $len : 0;
}

/** 递归找 abslist (r.s 在不同参数下返回顶层对象或 content[].xxx 嵌套) */
function sx_pick_abslist($node, &$best)
{
    if (!is_array($node)) {
        return;
    }
    if (isset($node['abslist']) && is_array($node['abslist'])) {
        if ($best === null || count($node['abslist']) > count($best)) {
            $best = $node['abslist'];
        }
    }
    foreach ($node as $v) {
        if (is_array($v)) {
            sx_pick_abslist($v, $best);
        }
    }
}

/* ------------------------------------------------------------------ search */

/** 把相对/绝对图地址规整成 https 绝对 URL; 拿不到完整 URL 返回空串 (绝不返回残缺 URL) */
function sx_abs_pic($url)
{
    $url = trim((string) $url);
    if ($url === '') {
        return '';
    }
    if (strpos($url, '//') === 0) {
        $url = 'https:' . $url;
    } elseif (strpos($url, 'http://') === 0) {
        $url = 'https://' . substr($url, 7);
    }
    return (strpos($url, 'https://') === 0) ? $url : '';
}

/** kuwo 封面: search.kuwo.cn/r.s 歌曲条目只有 web_albumpic_short (形如 120/xx/yy/id.jpg, 相对) */
function sx_kuwo_pic($short)
{
    $short = trim((string) $short);
    if ($short === '') {
        return '';
    }
    if (strpos($short, 'http') === 0) {
        return sx_abs_pic($short);
    }
    $rest = strpos($short, '/') === false ? '' : substr($short, strpos($short, '/') + 1);
    return $rest === '' ? '' : 'https://img2.kuwo.cn/star/albumcover/300/' . $rest;
}

/** migu 封面: search_all.do 歌曲条目的 imgItems[{img,imgSizeType}] 里挑最大尺寸的绝对 URL */
function sx_migu_pic($imgItems)
{
    if (!is_array($imgItems) || !$imgItems) {
        return '';
    }
    $best = '';
    $bestSize = -1;
    foreach ($imgItems as $it) {
        if (!is_array($it) || empty($it['img'])) {
            continue;
        }
        $abs = sx_abs_pic($it['img']);
        if ($abs === '') {
            continue;
        }
        $sz = isset($it['imgSizeType']) && is_numeric($it['imgSizeType']) ? (int) $it['imgSizeType'] : -1;
        if ($sz > $bestSize) {
            $bestSize = $sz;
            $best = $abs;
        }
    }

    return $best;
}

function sx_search($source, $keyword, $offset, $limit)
{
    return $source === 'kuwo' ? sx_kuwo_search($keyword, $offset, $limit) : sx_migu_search($keyword, $offset, $limit);
}

function sx_kuwo_search($keyword, $offset, $limit)
{
    // provider/kuwo.js: search.kuwo.cn/r.s (pn/rn 实测生效)
    $kw   = rawurlencode(str_replace(' - ', ' ', $keyword));
    $page = (int) floor($offset / $limit) + 1;
    $url  = 'http://search.kuwo.cn/r.s?&correct=1&vipver=1&stype=comprehensive&encoding=utf8'
          . '&rformat=json&mobi=1&show_copyright_off=1&searchapi=6'
          . '&rn=' . $limit . '&pn=' . $page . '&all=' . $kw;

    $resp = sx_get($url, array(
        'User-Agent' => SX_UA_PC,
        'Referer'    => 'http://www.kuwo.cn/',
    ));
    if ($resp['status'] !== 200) {
        throw new Exception('kuwo search upstream http ' . $resp['status']);
    }
    $json = sx_json($resp['body']);
    if ($json === null) {
        throw new Exception('kuwo search upstream non-json');
    }
    $abslist = null;
    sx_pick_abslist($json, $abslist);
    if (!$abslist) {
        throw new Exception('kuwo search empty result');
    }

    $list = array();
    foreach ($abslist as $item) {
        if (!is_array($item) || empty($item['MUSICRID'])) {
            continue;
        }
        $id = preg_replace('/^MUSIC_/', '', (string) $item['MUSICRID']);
        if ($id === '') {
            continue;
        }
        $list[] = array(
            'id'        => $id,
            'name'      => isset($item['SONGNAME']) ? $item['SONGNAME'] : (isset($item['NAME']) ? $item['NAME'] : ''),
            'artist'    => array_filter(explode('&', isset($item['ARTIST']) ? $item['ARTIST'] : '')),
            'album'     => isset($item['ALBUM']) ? $item['ALBUM'] : '',
            'pic'       => sx_kuwo_pic(isset($item['web_albumpic_short']) ? $item['web_albumpic_short'] : ''),
            'pic_id'    => $id,
            'url_id'    => $id,
            'lyric_id'  => $id,
            'source'    => 'kuwo',
        );
    }

    return sx_slice($list, $offset, $limit);
}

function sx_migu_search($keyword, $offset, $limit)
{
    // provider/migu.js 的 m.music.migu.cn 已失效; 同族 MIGUM2.0/v1.0/content/search_all.do
    $switch = rawurlencode('{"song":1,"album":0,"singer":0,"tagSong":0,"mvSong":0,"songlist":0,"bestShow":1}');
    $page   = (int) floor($offset / $limit) + 1;
    $url    = 'https://pd.musicapp.migu.cn/MIGUM2.0/v1.0/content/search_all.do'
            . '?ua=Android_migu&version=5.0.1'
            . '&pageNo=' . $page . '&pageSize=' . $limit
            . '&text=' . rawurlencode($keyword)
            . '&isCopyright=1&sort=1&searchSwitch=' . $switch;

    $resp = sx_get($url, sx_migu_headers());
    if ($resp['status'] !== 200) {
        throw new Exception('migu search upstream http ' . $resp['status']);
    }
    $json = sx_json($resp['body']);
    if ($json === null) {
        throw new Exception('migu search upstream non-json');
    }
    if (!isset($json['code']) || (string) $json['code'] !== '000000') {
        throw new Exception('migu search upstream code ' . (isset($json['code']) ? $json['code'] : '?')
            . (isset($json['info']) ? ' ' . $json['info'] : ''));
    }
    $result = isset($json['songResultData']['result']) ? $json['songResultData']['result'] : array();
    if (!is_array($result) || !$result) {
        throw new Exception('migu search empty result');
    }

    $list = array();
    foreach ($result as $item) {
        if (!is_array($item) || empty($item['id'])) {
            continue;
        }
        $artists = array();
        if (isset($item['singers']) && is_array($item['singers'])) {
            foreach ($item['singers'] as $singer) {
                if (isset($singer['name'])) {
                    $artists[] = $singer['name'];
                }
            }
        }
        $list[] = array(
            'id'        => (string) $item['id'],
            'name'      => isset($item['name']) ? $item['name'] : '',
            'artist'    => $artists,
            'album'     => isset($item['albums'][0]['name']) ? $item['albums'][0]['name'] : '',
            'pic'       => sx_migu_pic(isset($item['imgItems']) ? $item['imgItems'] : array()),
            'pic_id'    => isset($item['contentId']) ? (string) $item['contentId'] : '',
            'url_id'    => (string) $item['id'],
            'lyric_id'  => (string) $item['id'],
            'source'    => 'migu',
        );
    }

    return sx_slice($list, $offset, $limit);
}

/** 服务端已按 page 返回时, 再按页内偏移裁剪 */
function sx_slice($list, $offset, $limit)
{
    $inPage = $offset % max(1, $limit);
    if ($inPage > 0 && $inPage < count($list)) {
        $list = array_slice($list, $inPage);
    }

    return array_slice($list, 0, $limit);
}

/* --------------------------------------------------------------------- url */

function sx_url($source, $id, $br)
{
    return $source === 'kuwo' ? sx_kuwo_url($id, $br) : sx_migu_url($id, $br);
}

function sx_kuwo_url($id, $br)
{
    $rid = (stripos($id, 'MUSIC_') === 0) ? $id : 'MUSIC_' . $id;
    // provider/kuwo.js: antiserver convert_url (flac 上游 refuse, 只有 mp3)
    $url = 'http://antiserver.kuwo.cn/anti.s?type=convert_url&format=mp3&response=url&rid=' . rawurlencode($rid);

    $resp = sx_get($url, array(
        'User-Agent' => SX_UA_OKHTTP,
        'Referer'    => 'http://www.kuwo.cn/',
    ));
    $body = trim($resp['body']);
    if (!preg_match('#https?://[^\s"\']+#', $body, $m)) {
        throw new Exception('kuwo url upstream no playable url (' . substr($body, 0, 80) . ')');
    }
    $play = $m[0];
    $head = array(
        'User-Agent' => SX_UA_OKHTTP,
        'Referer'    => 'http://www.kuwo.cn/',
    );

    return array(
        'url'  => $play,
        'size' => sx_size($play, $head),
        'br'   => 128, // antiserver 只吐 128k mp3
    );
}

function sx_migu_url($id, $br)
{
    $contentId = sx_migu_content_id($id);
    // 质量档: PQ=标准 HQ=高清 SQ=无损 (provider/migu.js 的 ZQ24 在本接口上恒 201007)
    if ($br >= 999) {
        $tones = array('SQ', 'HQ', 'PQ');
    } elseif ($br >= 320) {
        $tones = array('HQ', 'PQ');
    } else {
        $tones = array('PQ', 'HQ');
    }
    $headers = sx_migu_headers();

    foreach ($tones as $tone) {
        $url  = 'https://app.c.nf.migu.cn/MIGUM2.0/v2.0/content/listen-url'
              . '?copyrightId=' . rawurlencode($id)
              . '&contentId=' . rawurlencode($contentId)
              . '&netType=01&toneFlag=' . $tone . '&resourceType=2';
        $resp = sx_get($url, $headers);
        $json = sx_json($resp['body']);
        if ($json === null || !isset($json['code']) || (string) $json['code'] !== '000000') {
            continue;
        }
        $data = isset($json['data']) && is_array($json['data']) ? $json['data'] : array();
        $play = isset($data['url']) ? trim((string) $data['url']) : '';
        if ($play === '' || strpos($play, 'http') !== 0) {
            continue;
        }
        $format = isset($data['formatType']) ? (string) $data['formatType'] : $tone;

        return array(
            'url'    => $play,
            'size'   => sx_size($play, $headers),
            'br'     => sx_migu_br($format),
            'format' => $format,
        );
    }

    throw new Exception('migu url upstream no playable url for id ' . $id);
}

function sx_migu_br($format)
{
    switch (strtoupper($format)) {
        case 'SQ':
        case 'ZQ24':
        case 'ZQ':
            return 999;
        case 'HQ':
            return 320;
        default:
            return 128;
    }
}

/** migu 的 listen-url 必须带 contentId; 用 resourceinfo 换取 */
function sx_migu_content_id($id)
{
    $url  = 'https://app.c.nf.migu.cn/MIGUM2.0/v1.0/content/resourceinfo.do'
          . '?needSimple=01&resourceId=' . rawurlencode($id) . '&resourceType=2';
    $resp = sx_get($url, sx_migu_headers());
    $json = sx_json($resp['body']);
    if ($json === null || !isset($json['resource'][0]['contentId']) || $json['resource'][0]['contentId'] === '') {
        throw new Exception('migu contentId lookup failed for id ' . $id);
    }

    return (string) $json['resource'][0]['contentId'];
}

/* ------------------------------------------------------------------ lyric */

function sx_lyric($source, $id)
{
    return $source === 'kuwo' ? sx_kuwo_lyric($id) : sx_migu_lyric($id);
}

function sx_kuwo_lyric($id)
{
    // 酷我 H5: 返回 lrclist(time 秒 + lineLyric), 本地拼标准 LRC
    // 实测: http://m.kuwo.cn 会间歇性返回 "音乐查询失败", https 与 www 域名稳定, 逐个兜底
    $musicId = rawurlencode(preg_replace('/^MUSIC_/i', '', $id));
    $urls    = array(
        'https://m.kuwo.cn/newh5/singles/songinfoandlrc?musicId=' . $musicId,
        'http://www.kuwo.cn/newh5/singles/songinfoandlrc?musicId=' . $musicId,
        'http://m.kuwo.cn/newh5/singles/songinfoandlrc?musicId=' . $musicId,
    );
    $last = 'non-json';
    foreach ($urls as $url) {
        $resp = sx_get($url, array(
            'User-Agent' => SX_UA_IPHONE,
            'Referer'    => 'http://m.kuwo.cn/',
        ));
        $json = sx_json($resp['body']);
        if ($json === null || !isset($json['data']) || !is_array($json['data'])) {
            if ($json !== null && isset($json['msg'])) {
                $last = $json['msg'];
            }
            continue;
        }
        $data = $json['data'];
        return sx_kuwo_lyric_build($data);
    }

    throw new Exception('kuwo lyric upstream: ' . $last);
}

function sx_kuwo_lyric_build($data)
{

    if (isset($data['lrc']) && is_string($data['lrc']) && $data['lrc'] !== '') {
        return array('lyric' => $data['lrc']);
    }
    if (empty($data['lrclist']) || !is_array($data['lrclist'])) {
        throw new Exception('kuwo lyric empty');
    }

    $lines = array();
    foreach ($data['lrclist'] as $row) {
        $text = isset($row['lineLyric']) ? (string) $row['lineLyric'] : '';
        $time = isset($row['time']) ? (float) $row['time'] : 0;
        $lines[] = sprintf('[%02d:%02d.%02d]', (int) floor($time / 60), (int) floor($time % 60), (int) round(($time - floor($time)) * 100));
        $lines[] = $text;
    }
    $lrc = implode("\n", $lines);
    if (!preg_match('/\[\d{2}:\d{2}\.\d{2}\]/', $lrc)) {
        throw new Exception('kuwo lyric format invalid');
    }

    return array('lyric' => $lrc);
}

function sx_migu_lyric($id)
{
    // provider/migu.js 没有 lyric; listen-url 响应里带 songItem.lrcUrl (LRC 文件直链)
    $contentId = sx_migu_content_id($id);
    $headers   = sx_migu_headers();
    $url       = 'https://app.c.nf.migu.cn/MIGUM2.0/v2.0/content/listen-url'
               . '?copyrightId=' . rawurlencode($id)
               . '&contentId=' . rawurlencode($contentId)
               . '&netType=01&toneFlag=PQ&resourceType=2';
    $resp      = sx_get($url, $headers);
    $json      = sx_json($resp['body']);
    if ($json === null || !isset($json['data']['songItem'])) {
        throw new Exception('migu lyric: listen-url failed');
    }
    $song = $json['data']['songItem'];
    $lrcUrl = '';
    if (isset($song['lrcUrl']) && $song['lrcUrl'] !== '') {
        $lrcUrl = $song['lrcUrl'];
    } elseif (isset($song['mrcUrl']) && $song['mrcUrl'] !== '') {
        $lrcUrl = $song['mrcUrl'];
    }
    if ($lrcUrl === '') {
        throw new Exception('migu lyric: upstream has no lrcUrl');
    }

    $body = sx_get($lrcUrl, array('User-Agent' => SX_UA_PC, 'Referer' => 'https://music.migu.cn/'));
    $text = trim($body['body']);
    if ($text === '') {
        throw new Exception('migu lyric: lrc fetch empty');
    }
    // 兜底: 有些 lrc 文件其实是 JSON
    if ($text[0] === '{') {
        $j = sx_json($text);
        if ($j !== null) {
            foreach (array('lyric', 'lrc', 'lyrics') as $k) {
                if (isset($j[$k]) && is_string($j[$k]) && $j[$k] !== '') {
                    $text = $j[$k];
                    break;
                }
            }
        }
    }
    if (!preg_match('/\[\d{1,2}:\d{2}(\.\d+)?\]/', $text)) {
        throw new Exception('migu lyric: upstream not LRC');
    }

    return array('lyric' => $text);
}

/* ---------------------------------------------------------------- helpers */

function sx_migu_headers()
{
    return array(
        'origin'    => 'http://music.migu.cn/',
        'referer'   => 'http://m.music.migu.cn/v3/',
        'channel'   => '0146921',
        'User-Agent' => SX_UA_MIGU,
    );
}
