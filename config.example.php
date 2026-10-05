<?php
/**
 * Meting API 本地配置（不会被 git pull 覆盖，已在 .gitignore 中忽略）
 *
 * QQ音乐(tencent) 播放链接需要登录票据：
 *   浏览器登录 y.qq.com -> F12 -> Network -> 任一请求 -> Request Headers
 *   -> 复制整个 Cookie 值（至少含 uin=QQ号 和 qm_keyst=...）
 *   填到下面 qq_cookie，重启服务：systemctl --user restart meting
 *   注意：Cookie 含账号票据，勿外传。
 * 也可以不改本文件，改用环境变量 METING_QQ_COOKIE（systemd unit 里）。
 */
$meting_config = array(
    'qq_cookie' => '',
);
