<?php
/**
 * Meting API 本地配置（不会被 git pull 覆盖，已在 .gitignore 中忽略）
 *
 * 播放直链类源需要登录票据 (cookie)，搜索/歌词/封面不受影响:
 *
 * QQ音乐(tencent): 浏览器登录 y.qq.com -> F12 -> Network -> 任一请求
 *   -> Request Headers -> 复制整个 Cookie 值（至少含 uin=QQ号 和 qm_keyst=...）
 *   填到 qq_cookie
 *
 * P16 酷狗(kugou) VIP 整曲: 浏览器登录 m.kugou.com -> F12 -> 任一请求
 *   -> Request Headers -> 复制整个 Cookie 值（须含 VIP 有效凭证）
 *   填到 kugou_cookie
 *
 * P16 网易云(netease) VIP 整曲: 浏览器登录 music.163.com -> F12 -> 复制 Cookie
 *   填到 netease_cookie（网易风控严，cookie 寿命短，失效后歌会回落到试听）
 *
 * 注意：Cookie 含账号票据，勿外传。
 * 也可以不改本文件，改用环境变量 METING_QQ_COOKIE / METING_KUGOU_COOKIE /
 * METING_NETEASE_COOKIE（systemd unit 里 Environment= 行），改完重启:
 *   systemctl --user restart meting
 */
$meting_config = array(
    'qq_cookie' => '',
    'kugou_cookie' => '',
    'netease_cookie' => '',
);
