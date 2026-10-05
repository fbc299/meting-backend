# Meting API（本机自建）— 接口文档

服务：`meting.service`（systemd user service）· 端口 **18090** · PHP 8.4 内置服务器（4 workers，IPv4/IPv6 双栈）
代码：metowolf/Meting `archive/php` 分支（`src/Meting.php`）+ 本目录 `index.php` 入口封装
启动：`systemctl --user {start|restart|status} meting`

## 端点（全部 GET，返回 `Content-Type: application/json; charset=utf-8`，CORS `*`）

| 功能 | URL |
|---|---|
| 搜索 | `/?type=search&source=<src>&name=<关键词>&s=<起始偏移>&n=<条数>` |
| 播放URL | `/?type=url&source=<src>&id=<id>&br=320` |
| 歌词 | `/?type=lyric&source=<src>&id=<id>` |
| 封面 | `/?type=pic&source=<src>&id=<id>&size=300` |
| 单曲详情 | `/?type=song&source=<src>&id=<id>` |
| 专辑 / 歌手 / 歌单 | `/?type=album\|artist\|playlist&source=<src>&id=<id>` |
| 服务自述 | `/` |

- `source` 也可写作 `server`；**`qq` 等同 `tencent`**。
- `<src>` 可选：`netease` / `tencent(qq)` / `kugou` / `kuwo` / `migu` / `baidu` / `xiami`
- 搜索分页：`s` 是 0 起偏移量，`n` 是每页条数（也接受 `page` / `limit` / `keyword` / `w`）
- 错误返回 `{"code":1,"message":"..."}` + HTTP 400/502。

## search 响应格式（2026-09-23 起每条含封面 `pic` 绝对 URL）

搜索返回 JSON 数组，每条结构：

```json
{
  "id": "…", "name": "…", "artist": ["…"], "album": "…",
  "pic": "https://…绝对封面URL…",   // 三源均追加; 上游无图时为空串 ""
  "pic_id": "…", "url_id": "…", "lyric_id": "…", "source": "…"
}
```

- `pic` 为**绝对 https URL**，App 在线列表封面 / 播放页海报 / 刮削直接消费；上游字段缺失或为空时回 `""`，**绝不返回残缺 URL**。
- 三源上游图字段（实测原始响应）：
  - `netease`：`al.picUrl`（`http://pN.music.126.net/…jpg`，已升级 https）
  - `migu`：`imgItems[{img,imgSizeType}]`，取最大 `imgSizeType` 的 `img`（https webp，实际 content-type image/jpeg）
  - `kuwo`：`web_albumpic_short`（相对路径 `120/xx/yy/id.jpg`）→ 拼 `https://img2.kuwo.cn/star/albumcover/300/<rest>`

## 实测可用性（2026-09-23，本机 curl）

| source | search | url | lyric |
|---|---|---|---|
| netease | ✅ | ✅（探测 200 / audio/mpeg） | ✅ |
| qq (tencent) | ✅ | ❌ 上游要求登录票据，见下 | ✅ |
| kugou | ✅ | ❌ 上游空 url | ✅ |
| kuwo（新实现） | ✅ | ✅（200 / audio/mpeg） | ✅ |
| migu（新实现） | ✅ | ✅（200 / audio/mpeg） | ✅ |
| baidu / xiami | ❌ 搜索 0 结果 | — | — |

**QQ 播放URL 说明**：`u.y.qq.com/...CgiGetVkey` 在无登录态时返回 `uin:""`、`purl:""`（已试 GET/POST、comm 块、多种 filename/guid/platform 组合，`c.y.qq.com/base/fcgi_music.fcg` 已 404）。要启用需提供自己的 QQ 音乐登录 Cookie：
`cp config.example.php config.php` 填 `qq_cookie`，或在 unit 里加 `Environment="METING_QQ_COOKIE=..."`，然后 `systemctl --user restart meting`。

## 实测样例

```bash
# 1) 搜索
curl -s 'http://127.0.0.1:18090/?type=search&source=netease&name=%E6%B5%B7%E9%98%94%E5%A4%A9%E7%A9%BA&s=0&n=5'
# [{"id":1357375695,"name":"海阔天空","artist":["Beyond"],"album":"华纳廿三周年纪念精选系列 - B﻿eyond","pic_id":"109951163984013003","url_id":1357375695,"lyric_id":1357375695,"source":"netease","pic":"https://p2.music.126.net/q6cm6Pk70YArijk1_QDoEg==/109951163984013003.jpg"}, ...]

# 2) 播放URL（用上一步的 id）
curl -s 'http://127.0.0.1:18090/?type=url&source=netease&id=1357375695&br=320'
# {"url":"http://m702.music.126.net/20260923063023/.../xxx.mp3?vuutv=...","size":481115,"br":320}

# 3) 歌词
curl -s 'http://127.0.0.1:18090/?type=lyric&source=netease&id=1357375695'
# {"lyric":"[00:00.00] 作词 : 黄家驹\n[00:01.00] 作曲 : 黄家驹\n...","tlyric":"..."}

# 4) QQ 音乐（搜索/歌词通，播放URL 需 Cookie）
curl -s 'http://127.0.0.1:18090/?type=search&source=qq&name=%E6%B5%B7%E9%98%94%E5%A4%A9%E7%A9%BA&s=0&n=5'
# [{"id":"001yS0N33yPm1B","name":"海阔天空","artist":["BEYOND"],"album":"乐与怒",...,"source":"tencent"}, ...]
curl -s 'http://127.0.0.1:18090/?type=lyric&source=qq&id=001yS0N33yPm1B'
# {"lyric":"[ti:海阔天空]\n[ar:BEYOND]\n..."}
curl -s 'http://127.0.0.1:18090/?type=url&source=qq&id=001yS0N33yPm1B&br=320'
# {"url":"","size":0,"br":-1,"message":"QQ音乐播放链接需要服务端登录 Cookie (METING_QQ_COOKIE / config.php); 搜索和歌词不受影响"}
```

## 新增源 kuwo / migu（本目录 `sources.php`，翻译自 UnblockNeteaseMusic `provider/{kuwo,migu}.js`）

`index.php` 对 `source=kuwo|migu` 且 `type=search|url|lyric` 走 `sources.php`（`sx_*` 函数），其余 type 行为不变（migu 其它 type 返回 `{"code":1,...}`）。参数与全局一致：`s` 偏移 / `n` 条数生效。

### kuwo 上游（实测 2026-09-23）

| 端点 | 上游 URL |
|---|---|
| search | `http://search.kuwo.cn/r.s?&correct=1&vipver=1&stype=comprehensive&encoding=utf8&rformat=json&mobi=1&show_copyright_off=1&searchapi=6&rn=<n>&pn=<page>&all=<kw>`（UA 任意浏览器 + `Referer: http://www.kuwo.cn/`；`pn/rn` 生效） |
| url | `http://antiserver.kuwo.cn/anti.s?type=convert_url&format=mp3&response=url&rid=MUSIC_<id>`（UA `okhttp/3.10.0` + `Referer: http://www.kuwo.cn/`，返回纯文本直链） |
| lyric | `https://m.kuwo.cn/newh5/singles/songinfoandlrc?musicId=<id>` → `data.lrclist[{time,lineLyric}]`，本地拼 `[mm:ss.xx]`；`http://m.kuwo.cn` 会间歇性“音乐查询失败”，代码里按 https→www→m 依次兜底 |

```bash
curl -s 'http://127.0.0.1:18090/?type=search&source=kuwo&name=%E6%B5%B7%E9%98%94%E5%A4%A9%E7%A9%BA&s=0&n=3'
# [{"id":"5886682","name":"海阔天空","artist":["BEYOND"],"album":"乐与怒","pic":"https://img2.kuwo.cn/star/albumcover/300/28/42/1776247091.jpg","pic_id":"5886682","url_id":"5886682","lyric_id":"5886682","source":"kuwo"},{"id":"19528080","name":"海阔天空","artist":["黄家驹"],"album":"","pic":"","pic_id":"19528080",…

curl -s 'http://127.0.0.1:18090/?type=url&source=kuwo&id=5886682&br=320'
# {"url":"https://kw-bj.kuwo.cn/663fd810826c221b041a6fd8d86e7fa2/6ab33330/nf/resource/n1/69/32/588957081.mp3","size":181521,"br":128}
# 直链: curl -sI -L -A 'okhttp/3.10.0' -H 'Referer: http://www.kuwo.cn/' → HTTP/1.1 200 OK / Content-Type: audio/mpeg / Content-Length: 181521
# 非 VIP 曲目为完整文件: id=549955189 → {"url":"https://kw-lv.kuwo.cn/acf618a2d354ef96701a10388df10e87/6ab33362/resource/30106/trackmedia/M500002eepAE19mEZv.mp3","size":4133834,"br":128}（GET 实测 4133834 / audio/mpeg / 200）

curl -s 'http://127.0.0.1:18090/?type=lyric&source=kuwo&id=5886682'
# {"lyric":"[00:00.00]\n海阔天空 - BEYOND\n[00:07.25]\n词：黄家驹\n[00:13.30]\n曲：黄家驹\n[00:19.34]\n今天我\n[00:22.99]\n寒夜里看雪飘过\n[00:26.01]\n怀着冷却了的心窝飘远方\n[00:31.85]\n风雨里追赶\n[00:34.92]\n雾里分不清影踪\n[00:38.00]\n天空海阔你与我\n[00:41.11]\n可会变\n[00:42.35]\n谁没在变\n[00:44.52]\n多少次\n[00:47.84]\n迎着冷眼与嘲笑\n[00:50.87]\n从没有放弃过心中的理想\n[00…

```

### migu 上游（实测 2026-09-23）

| 端点 | 上游 URL |
|---|---|
| search | `https://pd.musicapp.migu.cn/MIGUM2.0/v1.0/content/search_all.do?ua=Android_migu&version=5.0.1&pageNo=<page>&pageSize=<n>&text=<kw>&isCopyright=1&sort=1&searchSwitch={"song":1,...}`（header `channel: 0146921` / `referer: http://m.music.migu.cn/v3/`；provider 里的 `m.music.migu.cn/remoting/scr_search_tag` 已 301→SPA 失效，故改同族新接口） |
| url | 先 `https://app.c.nf.migu.cn/MIGUM2.0/v1.0/content/resourceinfo.do?needSimple=01&resourceId=<id>&resourceType=2` 取 `contentId`（listen-url 必须带，缺失会报“歌曲下线”），再 `https://app.c.nf.migu.cn/MIGUM2.0/v2.0/content/listen-url?copyrightId=<id>&contentId=<cid>&netType=01&toneFlag=<PQ\|HQ\|SQ>&resourceType=2` → `data.url` |
| lyric | 复用上面 listen-url 响应的 `songItem.lrcUrl`（LRC 文件直链）GET 即得；`v3/api/music/audioPlayer/getLyric` 已 301→SPA 失效 |

```bash
curl -s 'http://127.0.0.1:18090/?type=search&source=migu&name=%E6%B5%B7%E9%98%94%E5%A4%A9%E7%A9%BA&s=0&n=3'
# [{"id":"1135162566","name":"海阔天空","artist":["Beyond"],"album":"Beyond 24K Mastersonic Compilation","pic":"https://d.musicapp.migu.cn/data/oss/resource/00/2f/ya/bl.webp","pic_id":"600913000009337537","url_id":"1135162566","lyric_id":"1135162566","source":"migu"},{"id":"1125868659","name":"海阔天空 (大马版28/05/93) (Live)","artist":["Beyond"],"album":"Words & Music Final Live …

curl -s 'http://127.0.0.1:18090/?type=url&source=migu&id=1135162566&br=320'
# {"url":"https://freetyst.nf.migu.cn/public/product9th/product45/2022/05/0916/2020%E5%B9%B405%E6%9C%8825%E6%97%A518%E7%82%B902%E5%88%86%E7%B4%A7%E6%80%A5%E5%86%85%E5%AE%B9%E5%87%86%E5%85%A5%E5%8D%8E%E7%BA%B36%E9%A6%96289273/%E6%A0%87%E6%B8%85%E9%AB%98%E6%B8%85/MP3_128_16_Stero/6005752DXKE161237.mp3?c…
# 直链: curl -sI -L → HTTP/2 200 / content-type: audio/mpeg / content-length: 3833315（GET 实测同尺寸）

curl -s 'http://127.0.0.1:18090/?type=lyric&source=migu&id=1135162566'
# {"lyric":"[00:03.00]歌曲名 海阔天空\r\n[00:04.00]歌手名 Beyond\r\n[00:05.00]作词：黄家驹\r\n[00:06.00]作曲：黄家驹\r\n[00:19.90]今天我寒夜里看雪飘过\r\n[00:26.29]怀着冷却了的心窝飘远方\r\n[00:32.22]风雨里追赶雾里分不清影踪\r\n[00:38.51]天空海阔你与我可会变谁没在变\r\n[00:44.74]多少次迎着冷眼与嘲笑\r\n[00:51.13]从没有放弃过心中的理想\r\n[00:57.07]一刹那恍惚若有所失的感觉\r\n[01:03.10]不知不觉已变淡心里爱谁明白我\r\n…
```

**限制（实测）**
- kuwo `br` 恒 128：antiserver 只给 mp3（`format=flac|mp3` 上游回 `refuse request!`）；**VIP/受限曲目只吐 181521 字节（约 11 秒试听片段，`convert_url`/`convert_url3`/`p2p` 变体都是同一文件）**，非 VIP 曲目为完整文件（如 4.1 MB）。
- migu 无损未给：`br=999` 会依次试 `SQ→HQ→PQ`，SQ/HQ 响应 `code=000000` 但 `url` 为空，最终落回 `PQ`（128k mp3，响应 `format` 字段如实标 `PQ`、`br=128`）。
- kuwo 歌词个别 id 三个域名都回 `音乐查询失败`（上游歌词库缺条目），此时返回 `{"code":1,"message":"kuwo lyric upstream: 音乐查询失败"}` + 502。
- baidu / xiami 仍未修复（Meting 自带实现已失效）。


## 注意

- 搜索结果 `id` 与 source 绑定：网易云是数字 id，QQ 是 `001...` 形式的 mid，不能跨源复用。
- 播放 URL 是短时效签名链接（约几十秒~分钟级），**用前现取，不要缓存**。
- 外网访问：`http://<IPv6地址>:18090/`（本机 iptables INPUT 为 ACCEPT，Linger=yes 开机自启）。
- PHP 错误日志：`/vol1/fbc299/meting/logs/php-error.log`；服务日志 `journalctl --user -u meting`（当前用户无 journal 读权限时用前者的错误日志）。
