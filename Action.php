<?php

namespace TypechoPlugin\Meting;

use Typecho\Common;
use Typecho\Widget;
use Utils\Helper;
use Widget\ActionInterface;
use Widget\Options;

if (!defined('__TYPECHO_ROOT_DIR__')) {
    exit;
}

class Action extends Widget implements ActionInterface
{
    private const SUPPORTED_SERVERS = array('netease', 'tencent', 'kugou', 'baidu', 'kuwo');
    private const SUPPORTED_TYPES = array('song', 'album', 'search', 'artist', 'playlist', 'lrc', 'url', 'pic');

    public function execute()
    {
    }

    public function action()
    {
        $this->on($this->request->is('do=update'))->update();
        $this->on($this->request->is('do=parse'))->shortcode();
        $this->on($this->request->isGet())->api();
    }

    private function check($server, $type, $id)
    {
        if (!in_array($server, self::SUPPORTED_SERVERS, true)) {
            return false;
        }
        if (!in_array($type, self::SUPPORTED_TYPES, true)) {
            return false;
        }
        if (empty($id)) {
            return false;
        }
        return true;
    }

    private function isDebug()
    {
        return defined('__TYPECHO_DEBUG__') && __TYPECHO_DEBUG__;
    }

    private function debugLog($server, $type, $id, $message)
    {
        if (!$this->isDebug()) {
            return;
        }

        error_log(sprintf(
            '[Meting] server=%s type=%s id=%s %s',
            (string) $server,
            (string) $type,
            (string) $id,
            $message
        ));
    }

    private function sendJson($data, $status = 200)
    {
        http_response_code($status);
        header('Content-Type: application/json; charset=UTF-8');
        echo json_encode($data, JSON_UNESCAPED_UNICODE | JSON_UNESCAPED_SLASHES);
    }

    private function decodeJson($payload, $server, $type, $id)
    {
        $data = json_decode($payload, true);
        if (!is_array($data)) {
            $this->debugLog($server, $type, $id, 'invalid JSON response');
            return array();
        }

        return $data;
    }

    private function buildAuth($salt, $server, $type, $id)
    {
        return md5($salt . $server . $type . $id . $salt);
    }

    private function api()
    {
        // 参数检查
        $this->filterReferer();

        $server = trim((string) $this->request->get('server'));
        $type = trim((string) $this->request->get('type'));
        $id = trim((string) $this->request->get('id'));

        if (!$this->check($server, $type, $id)) {
            $this->debugLog($server, $type, $id, 'request rejected by whitelist check');
            $this->sendJson(array(), 403);
            return;
        }

        // 加载 Meting 模块
        if (!extension_loaded('Meting')) {
            include_once 'include/Meting.php';
        }
        $api = new \Metowolf\Meting($server);
        $api->format(true);
        $cookie = Options::alloc()->plugin('Meting')->cookie;
        if ($server == 'netease' && !empty($cookie)) {
            $api->cookie($cookie);
        }

        // 加载 Meting Cache 模块
        if (!extension_loaded('MetingCache')) {
            $cachetype = Options::alloc()->plugin('Meting')->cachetype;
            if ($cachetype != 'none') {
                $cachehost = Options::alloc()->plugin('Meting')->cachehost;
                $cacheport = Options::alloc()->plugin('Meting')->cacheport;
                include_once 'driver/cache.interface.php';
                include_once 'driver/'.$cachetype.'.class.php';
                $this->cache = new \MetingCache(array(
                    'host' => $cachehost,
                    'port' => $cacheport
                ));
            }
        }

        // auth 验证
        $EID = $server.$type.$id;
        $salt = Options::alloc()->plugin('Meting')->salt;

        if (!empty($salt)) {
            $auth1 = md5($salt.$EID.$salt);
            $auth2 = (string) $this->request->get('auth');
            if (strcmp($auth1, $auth2)) {
                $this->debugLog($server, $type, $id, 'auth mismatch');
                $this->sendJson(array(), 403);
                return;
            }
        }

        // 歌词解析
        if ($type == 'lrc') {
            $data = $this->cacheRead($EID);
            if (empty($data)) {
                $data = $api->lyric($id);
                $this->cacheWrite($EID, $data, 86400);
            }
            $data = $this->decodeJson($data, $server, $type, $id);
            header('Content-Type: text/plain; charset=UTF-8');
            if (empty($data)) {
                echo '[]';
                return;
            }

            if (!empty($data['tlyric']) && !empty($data['lyric'])) {
                echo $this->lrctran($data['lyric'], $data['tlyric']);
                return;
            }

            echo isset($data['lyric']) ? $data['lyric'] : '[]';
            return;
        }

        // 专辑图片解析
        if ($type == 'pic') {
            $data = $this->cacheRead($EID);
            if (empty($data)) {
                $data = $api->pic($id, 90);
                $this->cacheWrite($EID, $data, 86400);
            }
            $data = $this->decodeJson($data, $server, $type, $id);
            if (empty($data['url'])) {
                $this->debugLog($server, $type, $id, 'cover url missing');
                http_response_code(404);
                header('Content-Type: text/plain; charset=UTF-8');
                echo '[]';
                return;
            }

            $this->response->redirect($data['url']);
            return;
        }

        // 歌曲链接解析
        if ($type == 'url') {
            $data = $this->cacheRead($EID);
            if (empty($data)) {
                $rate = Options::alloc()->plugin('Meting')->bitrate;
                $data = $api->url($id, $rate);
                $this->cacheWrite($EID, $data, 1200);
            }
            $data = $this->decodeJson($data, $server, $type, $id);
            $url = isset($data['url']) ? $data['url'] : '';

            if ($server == 'netease') {
                $url = str_replace('://m7c.', '://m7.', $url);
                $url = str_replace('://m8c.', '://m8.', $url);
                $url = str_replace('http://m8.', 'https://m9.', $url);
                $url = str_replace('http://m7.', 'https://m9.', $url);
                $url = str_replace('http://m10.', 'https://m10.', $url);
            }

            if ($server == 'baidu') {
                $url = str_replace('http://zhangmenshiting.qianqian.com', 'https://gss3.baidu.com/y0s1hSulBw92lNKgpU_Z2jR7b2w6buu', $url);
            }

            if (empty($url)) {
                $this->debugLog($server, $type, $id, 'song url missing, using fallback');
                $url = 'https://coding.meting.api.i-meto.com/empty.mp3';
                if ($server == 'netease') {
                    $url = 'https://music.163.com/song/media/outer/url?id='.$id.'.mp3';
                }
            }
            $this->response->redirect($url);
            return;
        }

        // 其它类别解析
        if (in_array($type, array('song','album','search','artist','playlist'))) {
            $data = $this->cacheRead($EID);
            if (empty($data)) {
                $data = $api->$type($id);
                $this->cacheWrite($EID, $data, 7200);
            }
            $data = $this->decodeJson($data, $server, $type, $id);
            $url = Common::url('action/metingapi', Helper::options()->index);

            if (!is_array($data)) {
                $this->sendJson(array());
                return;
            }

            $music = array();
            foreach ($data as $vo) {
                if (!is_array($vo) || empty($vo['source']) || empty($vo['url_id'])) {
                    continue;
                }

                $artist = array();
                if (isset($vo['artist'])) {
                    $artist = is_array($vo['artist']) ? $vo['artist'] : array($vo['artist']);
                }

                $music[] = array(
                    'name'   => isset($vo['name']) ? $vo['name'] : '',
                    'artist' => implode(' / ', $artist),
                    'url'    => $url.'?server='.$vo['source'].'&type=url&id='.$vo['url_id'].'&auth='.$this->buildAuth($salt, $vo['source'], 'url', $vo['url_id']),
                    'cover'  => $url.'?server='.$vo['source'].'&type=pic&id='.$vo['pic_id'].'&auth='.$this->buildAuth($salt, $vo['source'], 'pic', $vo['pic_id']),
                    'lrc'    => $url.'?server='.$vo['source'].'&type=lrc&id='.$vo['lyric_id'].'&auth='.$this->buildAuth($salt, $vo['source'], 'lrc', $vo['lyric_id']),
                );
            }
            if (empty($music) && !empty($data)) {
                $this->debugLog($server, $type, $id, 'decoded list payload did not contain playable entries');
            }

            $this->sendJson($music);
            return;
        }
    }

    private function shortcode()
    {
        $url = $this->request->get('data');
        $url = trim($url);
        if (empty($url)) {
            return;
        }
        $server = 'netease';
        $id = '';
        $type = '';
        if (strpos($url, '163.com') !== false) {
            $server = 'netease';
            if (preg_match('/playlist\?id=(\d+)/i', $url, $id)) {
                list($id, $type) = array($id[1],'playlist');
            } elseif (preg_match('/toplist\?id=(\d+)/i', $url, $id)) {
                list($id, $type) = array($id[1],'playlist');
            } elseif (preg_match('/album\?id=(\d+)/i', $url, $id)) {
                list($id, $type) = array($id[1],'album');
            } elseif (preg_match('/song\?id=(\d+)/i', $url, $id)) {
                list($id, $type) = array($id[1],'song');
            } elseif (preg_match('/artist\?id=(\d+)/i', $url, $id)) {
                list($id, $type) = array($id[1],'artist');
            }
        } elseif (strpos($url, 'qq.com') !== false) {
            $server = 'tencent';
            if (preg_match('/playsquare\/([^\.]*)/i', $url, $id)) {
                list($id, $type) = array($id[1],'playlist');
            } elseif (preg_match('/playlist\/([^\.]*)/i', $url, $id)) {
                list($id, $type) = array($id[1],'playlist');
            } elseif (preg_match('/album\/([^\.]*)/i', $url, $id)) {
                list($id, $type) = array($id[1],'album');
            } elseif (preg_match('/song\/([^\.]*)/i', $url, $id)) {
                list($id, $type) = array($id[1],'song');
            } elseif (preg_match('/singer\/([^\.]*)/i', $url, $id)) {
                list($id, $type) = array($id[1],'artist');
            }
        } elseif (strpos($url, 'kugou.com') !== false) {
            $server = 'kugou';
            if (preg_match('/special\/single\/(\d+)/i', $url, $id)) {
                list($id, $type) = array($id[1],'playlist');
            } elseif (preg_match('/#hash\=(\w+)/i', $url, $id)) {
                list($id, $type) = array($id[1],'song');
            } elseif (preg_match('/album\/[single\/]*(\d+)/i', $url, $id)) {
                list($id, $type) = array($id[1],'album');
            } elseif (preg_match('/singer\/[home\/]*(\d+)/i', $url, $id)) {
                list($id, $type) = array($id[1],'artist');
            }
        } elseif (strpos($url, 'baidu.com') !== false) {
            $server = 'baidu';
            if (preg_match('/songlist\/(\d+)/i', $url, $id)) {
                list($id, $type) = array($id[1],'playlist');
            } elseif (preg_match('/album\/(\d+)/i', $url, $id)) {
                list($id, $type) = array($id[1],'album');
            } elseif (preg_match('/song\/(\d+)/i', $url, $id)) {
                list($id, $type) = array($id[1],'song');
            } elseif (preg_match('/artist\/(\d+)/i', $url, $id)) {
                list($id, $type) = array($id[1],'artist');
            }
        } elseif (strpos($url, 'kuwo.cn') !== false) {
            $server = 'kuwo';
            if (preg_match('/playlist_detail\/(\d+)/i', $url, $id)) {
                list($id, $type) = array($id[1], 'playlist');
            } elseif (preg_match('/play_detail\/(\d+)/i', $url, $id)) {
                list($id, $type) = array($id[1], 'song');
            } elseif (preg_match('/album_detail\/(\d+)/i', $url, $id)) {
                list($id, $type) = array($id[1], 'album');
            } elseif (preg_match('/singer_detail\/(\d+)/i', $url, $id)) {
                list($id, $type) = array($id[1], 'artist');
            }
        } else {
            die("[Meting]\n[Music title=\"歌曲名\" author=\"歌手\" url=\"{$url}\" pic=\"图片文件URL\" lrc=\"歌词文件URL\"/]\n[/Meting]\n");
            return;
        }
        if (is_array($id)) {
            $id = '';
        }
        die("[Meting]\n[Music server=\"{$server}\" id=\"{$id}\" type=\"{$type}\"/]\n[/Meting]\n");

    }

    private function lrctrim($lyrics)
    {
        $result = "";
        $lyrics = explode("\n", $lyrics);
        $data = array();
        foreach ($lyrics as $key => $lyric) {
            preg_match('/\[(\d{2}):(\d{2}[\.:]?\d*)]/', $lyric, $lrcTimes);
            $lrcText = preg_replace('/\[(\d{2}):(\d{2}[\.:]?\d*)]/', '', $lyric);
            if (empty($lrcTimes)) {
                continue;
            }
            $lrcTimes = intval($lrcTimes[1]) * 60000 + intval(floatval($lrcTimes[2]) * 1000);
            $lrcText = preg_replace('/\s\s+/', ' ', $lrcText);
            $lrcText = trim($lrcText);
            $data[] = array($lrcTimes, $key, $lrcText);
        }
        sort($data);
        return $data;
    }

    private function lrctran($lyric, $tlyric)
    {
        $lyric = $this->lrctrim($lyric);
        $tlyric = $this->lrctrim($tlyric);
        $len1 = count($lyric);
        $len2 = count($tlyric);
        $result = "";
        for ($i=0,$j=0; $i<$len1&&$j<$len2; $i++) {
            while ($lyric[$i][0]>$tlyric[$j][0]&&$j+1<$len2) {
                $j++;
            }
            if ($lyric[$i][0] == $tlyric[$j][0]) {
                $tlyric[$j][2] = str_replace('/', '', $tlyric[$j][2]);
                if (!empty($tlyric[$j][2])) {
                    $lyric[$i][2] .= " ({$tlyric[$j][2]})";
                }
                $j++;
            }
        }
        for ($i=0; $i<$len1; $i++) {
            $t = $lyric[$i][0];
            $result .= sprintf("[%02d:%02d.%03d]%s\n", $t/60000, $t%60000/1000, $t%1000, $lyric[$i][2]);
        }
        return $result;
    }

    private function update()
    {
        $hasLogin = \Widget\User::alloc()->hasLogin();
        if (!$hasLogin) {
            die('Forbidden!');
        }
        $isAdmin = \Widget\User::alloc()->pass('administrator', true);
        if (!$isAdmin) {
            die('Forbidden!');
        }

        header("Content-Type: text/plain; charset=UTF-8");

        $shasum = $this->curl('https://raw.githubusercontent.com/mikusaa/Typecho-Plugin-APlayer/master/shasum.txt');

        echo "获取最新特征库...\n";
        echo $shasum."\n\n";

        $shasum = explode("\n", $shasum);
        array_pop($shasum);

        echo "开始检查本地文件...\n";

        foreach ($shasum as $remote) {
            list($remote_sha256, $filename) = explode('  ', $remote);
            if (!file_exists(__DIR__.'/'.$filename) ||
                !hash_equals(hash('sha256', file_get_contents(__DIR__.'/'.$filename)), $remote_sha256)) {
                echo "下载     ".$filename;
                $url = 'https://raw.githubusercontent.com/mikusaa/Typecho-Plugin-APlayer/master'.substr($filename, 1);

                if (file_put_contents(__DIR__.'/'.$filename, $this->curl($url))) {
                    echo " (OK)\n";
                } else {
                    die("\n下载失败，错误信息: $url\n");
                }
            } else {
                echo "无需更新  ".$filename."\n";
            }
        }

        echo "\n\n如果插件出现错误，建议禁用再启用一次插件完成升级。";
        die();
    }

    private function curl($url)
    {
        $curl = curl_init();
        curl_setopt($curl, CURLOPT_URL, $url);
        curl_setopt($curl, CURLOPT_RETURNTRANSFER, 1);
        curl_setopt($curl, CURLOPT_CONNECTTIMEOUT, 10);
        curl_setopt($curl, CURLOPT_TIMEOUT, 10);
        curl_setopt($curl, CURLOPT_REFERER, $url);
        $result = curl_exec($curl);
        curl_close($curl);
        return $result;
    }

    private function cacheWrite($k, $v, $t)
    {
        if (!isset($this->cache)) {
            return;
        }
        return $this->cache->set($k, $v, $t);
    }

    private function cacheRead($k)
    {
        if (!isset($this->cache)) {
            return false;
        }
        return $this->cache->get($k);
    }

    private function getCurrentOriginParts()
    {
        $scheme = (!empty($_SERVER['HTTPS']) && strtolower((string) $_SERVER['HTTPS']) !== 'off') ? 'https' : 'http';
        $host = isset($_SERVER['HTTP_HOST']) ? (string) $_SERVER['HTTP_HOST'] : '';
        $parts = parse_url($scheme . '://' . $host);

        return array(
            'scheme' => isset($parts['scheme']) ? strtolower((string) $parts['scheme']) : $scheme,
            'host'   => isset($parts['host']) ? strtolower((string) $parts['host']) : strtolower((string) $_SERVER['SERVER_NAME']),
            'port'   => isset($parts['port']) ? intval($parts['port']) : intval(isset($_SERVER['SERVER_PORT']) ? $_SERVER['SERVER_PORT'] : ($scheme === 'https' ? 443 : 80)),
        );
    }

    private function isSameOriginReferer($referer)
    {
        $refererParts = parse_url((string) $referer);
        if (empty($refererParts['host'])) {
            return false;
        }

        $current = $this->getCurrentOriginParts();
        $refererScheme = isset($refererParts['scheme']) ? strtolower((string) $refererParts['scheme']) : $current['scheme'];
        $refererHost = strtolower((string) $refererParts['host']);
        $refererPort = isset($refererParts['port']) ? intval($refererParts['port']) : ($refererScheme === 'https' ? 443 : 80);

        return $refererScheme === $current['scheme']
            && $refererHost === $current['host']
            && $refererPort === $current['port'];
    }

    private function filterReferer()
    {
        $salt = Options::alloc()->plugin('Meting')->salt;
        if (empty($salt)) {
            header("Access-Control-Allow-Origin: *");
            header("Access-Control-Allow-Headers: Origin, X-Requested-With, Content-Type, Accept, Connection, User-Agent, Cookie");
            return;
        }
        if (isset($_SERVER['HTTP_REFERER']) && !$this->isSameOriginReferer($_SERVER['HTTP_REFERER'])) {
            $this->debugLog('', '', '', 'referer rejected: ' . $_SERVER['HTTP_REFERER']);
            http_response_code(403);
            header('Content-Type: application/json; charset=UTF-8');
            die('[]');
        }
    }
}
