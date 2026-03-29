<?php
/**
 * Meting music framework (Typecho plugin PHP port)
 * https://github.com/metowolf/Meting
 *
 * This plugin keeps the original PHP integration shape while aligning its
 * provider logic with the local Meting provider implementations.
 */

namespace Metowolf;

class Meting
{
    const VERSION = '1.6.0';

    public $raw;
    public $data;
    public $info;
    public $error;
    public $status;
    public $temp = array();

    public $server;
    public $format = false;
    public $header = array();

    private $supported = array('netease', 'tencent', 'kugou', 'baidu', 'kuwo');

    public function __construct($value = 'netease')
    {
        $this->site($value);
    }

    public function site($value)
    {
        $this->server = in_array($value, $this->supported, true) ? $value : 'netease';
        $this->header = $this->curlset();
        $this->temp = array();

        return $this;
    }

    public function cookie($value)
    {
        if (!empty($value)) {
            $this->header['Cookie'] = $value;
        }

        return $this;
    }

    public function format($value = true)
    {
        $this->format = (bool) $value;

        return $this;
    }

    private function exec($api)
    {
        if (isset($api['encode'])) {
            $api = call_user_func(array($this, $api['encode']), $api);
        }

        if (($api['method'] ?? 'GET') === 'GET' && !empty($api['body'])) {
            $query = http_build_query($api['body']);
            $api['url'] .= (false === strpos($api['url'], '?') ? '?' : '&') . $query;
            $api['body'] = null;
        }

        $this->curl($api['url'], $api['body'] ?? null);

        if (!$this->format) {
            return $this->raw;
        }

        $data = $this->raw;

        if (isset($api['decode'])) {
            $data = call_user_func(array($this, $api['decode']), $data);
        }

        if (array_key_exists('format', $api)) {
            $data = $this->clean($data, $api['format']);
        }

        return $data;
    }

    private function curl($url, $payload = null, $headerOnly = 0)
    {
        $headers = array();
        foreach ($this->header as $key => $value) {
            $headers[] = $key . ': ' . $value;
        }

        if (is_string($payload) && !$this->hasHeader('Content-Type') && preg_match('/^\s*[\{\[]/', $payload)) {
            $headers[] = 'Content-Type: application/json;charset=UTF-8';
        }

        $curl = curl_init();
        if (!is_null($payload)) {
            curl_setopt($curl, CURLOPT_POST, 1);
            curl_setopt($curl, CURLOPT_POSTFIELDS, is_array($payload) ? http_build_query($payload) : $payload);
        }

        curl_setopt($curl, CURLOPT_HEADER, $headerOnly);
        curl_setopt($curl, CURLOPT_TIMEOUT, 20);
        curl_setopt($curl, CURLOPT_ENCODING, 'gzip');
        curl_setopt($curl, CURLOPT_IPRESOLVE, CURL_IPRESOLVE_V4);
        curl_setopt($curl, CURLOPT_RETURNTRANSFER, 1);
        curl_setopt($curl, CURLOPT_SSL_VERIFYPEER, 0);
        curl_setopt($curl, CURLOPT_CONNECTTIMEOUT, 10);
        curl_setopt($curl, CURLOPT_URL, $url);
        curl_setopt($curl, CURLOPT_HTTPHEADER, $headers);

        for ($i = 0; $i < 3; $i++) {
            $this->raw = curl_exec($curl);
            $this->info = curl_getinfo($curl);
            $this->error = curl_errno($curl);
            $this->status = $this->error ? curl_error($curl) : '';

            if (!$this->error) {
                break;
            }
        }

        curl_close($curl);

        return $this;
    }

    private function hasHeader($name)
    {
        foreach ($this->header as $key => $value) {
            if (0 === strcasecmp($key, $name)) {
                return true;
            }
        }

        return false;
    }

    private function pickup($array, $rule)
    {
        $parts = explode('.', $rule);
        foreach ($parts as $part) {
            if (!is_array($array) || !array_key_exists($part, $array)) {
                return array();
            }
            $array = $array[$part];
        }

        return $array;
    }

    private function clean($raw, $rule)
    {
        $raw = json_decode($raw, true);
        if (!is_array($raw)) {
            return json_encode(array());
        }

        if (!empty($rule)) {
            $raw = $this->pickup($raw, $rule);
        }

        if (!is_array($raw)) {
            return json_encode(array());
        }

        if ($this->isAssoc($raw)) {
            $raw = empty($raw) ? array() : array($raw);
        }

        if (!is_array($raw)) {
            return json_encode(array());
        }

        $result = array();
        foreach ($raw as $item) {
            if (is_array($item)) {
                $result[] = call_user_func(array($this, 'format_' . $this->server), $item);
            }
        }

        return json_encode($result);
    }

    private function isAssoc($array)
    {
        if (!is_array($array)) {
            return false;
        }

        return array_keys($array) !== range(0, count($array) - 1);
    }

    public function search($keyword, $option = null)
    {
        $option = is_array($option) ? $option : array();

        switch ($this->server) {
            case 'netease':
                $api = array(
                    'method' => 'POST',
                    'url'    => 'http://music.163.com/api/cloudsearch/pc',
                    'body'   => array(
                        's'      => $keyword,
                        'type'   => isset($option['type']) ? $option['type'] : 1,
                        'limit'  => isset($option['limit']) ? $option['limit'] : 30,
                        'total'  => 'true',
                        'offset' => isset($option['page'], $option['limit']) ? ($option['page'] - 1) * $option['limit'] : 0,
                    ),
                    'encode' => 'netease_eapi',
                    'format' => 'result.songs',
                );
                break;
            case 'tencent':
                $api = array(
                    'method' => 'GET',
                    'url'    => 'https://c.y.qq.com/soso/fcgi-bin/client_search_cp',
                    'body'   => array(
                        'format'   => 'json',
                        'p'        => isset($option['page']) ? $option['page'] : 1,
                        'n'        => isset($option['limit']) ? $option['limit'] : 30,
                        'w'        => $keyword,
                        'aggr'     => 1,
                        'lossless' => 1,
                        'cr'       => 1,
                        'new_json' => 1,
                    ),
                    'format' => 'data.song.list',
                );
                break;
            case 'kugou':
                $api = array(
                    'method' => 'GET',
                    'url'    => 'http://mobilecdn.kugou.com/api/v3/search/song',
                    'body'   => array(
                        'api_ver'   => 1,
                        'area_code' => 1,
                        'correct'   => 1,
                        'pagesize'  => isset($option['limit']) ? $option['limit'] : 30,
                        'plat'      => 2,
                        'tag'       => 1,
                        'sver'      => 5,
                        'showtype'  => 10,
                        'page'      => isset($option['page']) ? $option['page'] : 1,
                        'keyword'   => $keyword,
                        'version'   => 8990,
                    ),
                    'format' => 'data.info',
                );
                break;
            case 'baidu':
                $api = array(
                    'method' => 'GET',
                    'url'    => 'http://musicapi.taihe.com/v1/restserver/ting',
                    'body'   => array(
                        'from'      => 'qianqianmini',
                        'method'    => 'baidu.ting.search.merge',
                        'isNew'     => 1,
                        'platform'  => 'darwin',
                        'page_no'   => isset($option['page']) ? $option['page'] : 1,
                        'query'     => $keyword,
                        'version'   => '11.2.1',
                        'page_size' => isset($option['limit']) ? $option['limit'] : 30,
                    ),
                    'format' => 'result.song_info.song_list',
                );
                break;
            case 'kuwo':
                $api = array(
                    'method' => 'GET',
                    'url'    => 'http://www.kuwo.cn/api/www/search/searchMusicBykeyWord',
                    'body'   => array(
                        'key'         => $keyword,
                        'pn'          => isset($option['page']) ? $option['page'] : 1,
                        'rn'          => isset($option['limit']) ? $option['limit'] : 30,
                        'httpsStatus' => 1,
                    ),
                    'format' => 'data.list',
                );
                break;
            default:
                return json_encode(array());
        }

        return $this->exec($api);
    }

    public function song($id)
    {
        switch ($this->server) {
            case 'netease':
                $api = array(
                    'method' => 'POST',
                    'url'    => 'http://music.163.com/api/v3/song/detail/',
                    'body'   => array(
                        'c' => '[{"id":' . $id . ',"v":0}]',
                    ),
                    'encode' => 'netease_eapi',
                    'format' => 'songs',
                );
                break;
            case 'tencent':
                $api = array(
                    'method' => 'GET',
                    'url'    => 'https://c.y.qq.com/v8/fcg-bin/fcg_play_single_song.fcg',
                    'body'   => array(
                        'songmid'  => $id,
                        'platform' => 'yqq',
                        'format'   => 'json',
                    ),
                    'format' => 'data',
                );
                break;
            case 'kugou':
                $api = array(
                    'method' => 'POST',
                    'url'    => 'http://m.kugou.com/app/i/getSongInfo.php',
                    'body'   => array(
                        'cmd'  => 'playInfo',
                        'hash' => $id,
                        'from' => 'mkugou',
                    ),
                    'format' => '',
                );
                break;
            case 'baidu':
                $api = array(
                    'method' => 'GET',
                    'url'    => 'http://musicapi.taihe.com/v1/restserver/ting',
                    'body'   => array(
                        'from'     => 'qianqianmini',
                        'method'   => 'baidu.ting.song.getInfos',
                        'songid'   => $id,
                        'res'      => 1,
                        'platform' => 'darwin',
                        'version'  => '1.0.0',
                    ),
                    'encode' => 'baidu_AESCBC',
                    'format' => 'songinfo',
                );
                break;
            case 'kuwo':
                $api = array(
                    'method' => 'GET',
                    'url'    => 'http://www.kuwo.cn/api/www/music/musicInfo',
                    'body'   => array(
                        'mid'         => $id,
                        'httpsStatus' => 1,
                    ),
                    'format' => 'data',
                );
                break;
            default:
                return json_encode(array());
        }

        return $this->exec($api);
    }

    public function album($id)
    {
        switch ($this->server) {
            case 'netease':
                $api = array(
                    'method' => 'POST',
                    'url'    => 'http://music.163.com/api/v1/album/' . $id,
                    'body'   => array(
                        'total'         => 'true',
                        'offset'        => '0',
                        'id'            => $id,
                        'limit'         => '1000',
                        'ext'           => 'true',
                        'private_cloud' => 'true',
                    ),
                    'encode' => 'netease_eapi',
                    'format' => 'songs',
                );
                break;
            case 'tencent':
                $api = array(
                    'method' => 'GET',
                    'url'    => 'https://c.y.qq.com/v8/fcg-bin/fcg_v8_album_detail_cp.fcg',
                    'body'   => array(
                        'albummid' => $id,
                        'platform' => 'mac',
                        'format'   => 'json',
                        'newsong'  => 1,
                    ),
                    'format' => 'data.getSongInfo',
                );
                break;
            case 'kugou':
                $api = array(
                    'method' => 'GET',
                    'url'    => 'http://mobilecdn.kugou.com/api/v3/album/song',
                    'body'   => array(
                        'albumid'   => $id,
                        'area_code' => 1,
                        'plat'      => 2,
                        'page'      => 1,
                        'pagesize'  => -1,
                        'version'   => 8990,
                    ),
                    'format' => 'data.info',
                );
                break;
            case 'baidu':
                $api = array(
                    'method' => 'GET',
                    'url'    => 'http://musicapi.taihe.com/v1/restserver/ting',
                    'body'   => array(
                        'from'     => 'qianqianmini',
                        'method'   => 'baidu.ting.album.getAlbumInfo',
                        'album_id' => $id,
                        'platform' => 'darwin',
                        'version'  => '11.2.1',
                    ),
                    'format' => 'songlist',
                );
                break;
            case 'kuwo':
                $api = array(
                    'method' => 'GET',
                    'url'    => 'http://www.kuwo.cn/api/www/album/albumInfo',
                    'body'   => array(
                        'albumId'     => $id,
                        'pn'          => 1,
                        'rn'          => 1000,
                        'httpsStatus' => 1,
                    ),
                    'format' => 'data.musicList',
                );
                break;
            default:
                return json_encode(array());
        }

        return $this->exec($api);
    }

    public function artist($id, $limit = 50)
    {
        switch ($this->server) {
            case 'netease':
                $api = array(
                    'method' => 'POST',
                    'url'    => 'http://music.163.com/api/v1/artist/' . $id,
                    'body'   => array(
                        'ext'           => 'true',
                        'private_cloud' => 'true',
                        'top'           => $limit,
                        'id'            => $id,
                    ),
                    'encode' => 'netease_eapi',
                    'format' => 'hotSongs',
                );
                break;
            case 'tencent':
                $api = array(
                    'method' => 'GET',
                    'url'    => 'https://c.y.qq.com/v8/fcg-bin/fcg_v8_singer_track_cp.fcg',
                    'body'   => array(
                        'singermid' => $id,
                        'begin'     => 0,
                        'num'       => $limit,
                        'order'     => 'listen',
                        'platform'  => 'mac',
                        'newsong'   => 1,
                    ),
                    'format' => 'data.list',
                );
                break;
            case 'kugou':
                $api = array(
                    'method' => 'GET',
                    'url'    => 'http://mobilecdn.kugou.com/api/v3/singer/song',
                    'body'   => array(
                        'singerid'  => $id,
                        'area_code' => 1,
                        'page'      => 1,
                        'plat'      => 0,
                        'pagesize'  => $limit,
                        'version'   => 8990,
                    ),
                    'format' => 'data.info',
                );
                break;
            case 'baidu':
                $api = array(
                    'method' => 'GET',
                    'url'    => 'http://musicapi.taihe.com/v1/restserver/ting',
                    'body'   => array(
                        'from'     => 'qianqianmini',
                        'method'   => 'baidu.ting.artist.getSongList',
                        'artistid' => $id,
                        'limits'   => $limit,
                        'platform' => 'darwin',
                        'offset'   => 0,
                        'tinguid'  => 0,
                        'version'  => '11.2.1',
                    ),
                    'format' => 'songlist',
                );
                break;
            case 'kuwo':
                $api = array(
                    'method' => 'GET',
                    'url'    => 'http://www.kuwo.cn/api/www/artist/artistMusic',
                    'body'   => array(
                        'artistid'    => $id,
                        'pn'          => 1,
                        'rn'          => $limit,
                        'httpsStatus' => 1,
                    ),
                    'format' => 'data.list',
                );
                break;
            default:
                return json_encode(array());
        }

        return $this->exec($api);
    }

    public function playlist($id)
    {
        switch ($this->server) {
            case 'netease':
                $api = array(
                    'method' => 'POST',
                    'url'    => 'http://music.163.com/api/v6/playlist/detail',
                    'body'   => array(
                        's'  => '0',
                        'id' => $id,
                        'n'  => '1000',
                        't'  => '0',
                    ),
                    'encode' => 'netease_eapi',
                    'format' => 'playlist.tracks',
                );
                break;
            case 'tencent':
                $api = array(
                    'method' => 'GET',
                    'url'    => 'https://c.y.qq.com/v8/fcg-bin/fcg_v8_playlist_cp.fcg',
                    'body'   => array(
                        'id'       => $id,
                        'format'   => 'json',
                        'newsong'  => 1,
                        'platform' => 'jqspaframe.json',
                    ),
                    'format' => 'data.cdlist.0.songlist',
                );
                break;
            case 'kugou':
                $api = array(
                    'method' => 'GET',
                    'url'    => 'http://mobilecdn.kugou.com/api/v3/special/song',
                    'body'   => array(
                        'specialid' => $id,
                        'area_code' => 1,
                        'page'      => 1,
                        'plat'      => 2,
                        'pagesize'  => -1,
                        'version'   => 8990,
                    ),
                    'format' => 'data.info',
                );
                break;
            case 'baidu':
                $api = array(
                    'method' => 'GET',
                    'url'    => 'http://musicapi.taihe.com/v1/restserver/ting',
                    'body'   => array(
                        'from'     => 'qianqianmini',
                        'method'   => 'baidu.ting.diy.gedanInfo',
                        'listid'   => $id,
                        'platform' => 'darwin',
                        'version'  => '11.2.1',
                    ),
                    'format' => 'content',
                );
                break;
            case 'kuwo':
                $api = array(
                    'method' => 'GET',
                    'url'    => 'http://www.kuwo.cn/api/www/playlist/playListInfo',
                    'body'   => array(
                        'pid'         => $id,
                        'pn'          => 1,
                        'rn'          => 1000,
                        'httpsStatus' => 1,
                    ),
                    'format' => 'data.musicList',
                );
                break;
            default:
                return json_encode(array());
        }

        return $this->exec($api);
    }

    public function url($id, $br = 320)
    {
        $this->temp['br'] = $br;

        switch ($this->server) {
            case 'netease':
                $api = array(
                    'method' => 'POST',
                    'url'    => 'http://music.163.com/api/song/enhance/player/url',
                    'body'   => array(
                        'ids' => array($id),
                        'br'  => intval($br) * 1000,
                    ),
                    'encode' => 'netease_eapi',
                    'decode' => 'netease_url',
                );
                break;
            case 'tencent':
                $api = array(
                    'method' => 'GET',
                    'url'    => 'https://c.y.qq.com/v8/fcg-bin/fcg_play_single_song.fcg',
                    'body'   => array(
                        'songmid'  => $id,
                        'platform' => 'yqq',
                        'format'   => 'json',
                    ),
                    'decode' => 'tencent_url',
                );
                break;
            case 'kugou':
                $cookie = $this->parseCookie(isset($this->header['Cookie']) ? $this->header['Cookie'] : '');
                $hasToken = !empty($cookie['t']) && !empty($cookie['KugooID']);

                if ($hasToken) {
                    $now = intval(microtime(true) * 1000);
                    $params = array(
                        'srcappid'   => '2919',
                        'clientver'  => '20000',
                        'clienttime' => strval($now),
                        'mid'        => isset($cookie['mid']) ? $cookie['mid'] : (isset($cookie['kg_mid']) ? $cookie['kg_mid'] : ''),
                        'uuid'       => isset($cookie['uuid']) ? $cookie['uuid'] : (isset($cookie['mid']) ? $cookie['mid'] : (isset($cookie['kg_mid']) ? $cookie['kg_mid'] : '')),
                        'dfid'       => isset($cookie['dfid']) ? $cookie['dfid'] : (isset($cookie['kg_dfid']) ? $cookie['kg_dfid'] : ''),
                        'appid'      => '1014',
                        'platid'     => '4',
                        'hash'       => $id,
                        'token'      => isset($cookie['t']) ? $cookie['t'] : '',
                        'userid'     => isset($cookie['KugooID']) ? $cookie['KugooID'] : '',
                    );

                    $api = array(
                        'method' => 'GET',
                        'url'    => $this->buildKugouSonginfoUrl($params),
                        'body'   => null,
                        'decode' => 'kugou_url_new',
                    );
                } else {
                    $api = array(
                        'method' => 'POST',
                        'url'    => 'http://media.store.kugou.com/v1/get_res_privilege',
                        'body'   => json_encode(array(
                            'relate'    => 1,
                            'userid'    => '0',
                            'vip'       => 0,
                            'appid'     => 1000,
                            'token'     => '',
                            'behavior'  => 'download',
                            'area_code' => '1',
                            'clientver' => '8990',
                            'resource'  => array(
                                array(
                                    'id'   => 0,
                                    'type' => 'audio',
                                    'hash' => $id,
                                ),
                            ),
                        )),
                        'decode' => 'kugou_url_legacy',
                    );
                }
                break;
            case 'baidu':
                $api = array(
                    'method' => 'GET',
                    'url'    => 'http://musicapi.taihe.com/v1/restserver/ting',
                    'body'   => array(
                        'from'     => 'qianqianmini',
                        'method'   => 'baidu.ting.song.getInfos',
                        'songid'   => $id,
                        'res'      => 1,
                        'platform' => 'darwin',
                        'version'  => '1.0.0',
                    ),
                    'encode' => 'baidu_AESCBC',
                    'decode' => 'baidu_url',
                );
                break;
            case 'kuwo':
                $api = array(
                    'method' => 'GET',
                    'url'    => 'http://www.kuwo.cn/api/v1/www/music/playUrl',
                    'body'   => array(
                        'mid'         => $id,
                        'type'        => 'music',
                        'httpsStatus' => 1,
                    ),
                    'decode' => 'kuwo_url',
                );
                break;
            default:
                return json_encode(array('url' => '', 'size' => 0, 'br' => -1));
        }

        return $this->exec($api);
    }

    public function lyric($id)
    {
        switch ($this->server) {
            case 'netease':
                $api = array(
                    'method' => 'POST',
                    'url'    => 'http://music.163.com/api/song/lyric',
                    'body'   => array(
                        'id' => $id,
                        'os' => 'linux',
                        'lv' => -1,
                        'kv' => -1,
                        'tv' => -1,
                    ),
                    'encode' => 'netease_eapi',
                    'decode' => 'netease_lyric',
                );
                break;
            case 'tencent':
                $api = array(
                    'method' => 'GET',
                    'url'    => 'https://c.y.qq.com/lyric/fcgi-bin/fcg_query_lyric_new.fcg',
                    'body'   => array(
                        'songmid' => $id,
                        'g_tk'    => '5381',
                    ),
                    'decode' => 'tencent_lyric',
                );
                break;
            case 'kugou':
                $api = array(
                    'method' => 'GET',
                    'url'    => 'http://krcs.kugou.com/search',
                    'body'   => array(
                        'keyword' => '%20-%20',
                        'ver'     => 1,
                        'hash'    => $id,
                        'client'  => 'mobi',
                        'man'     => 'yes',
                    ),
                    'decode' => 'kugou_lyric',
                );
                break;
            case 'baidu':
                $api = array(
                    'method' => 'GET',
                    'url'    => 'http://musicapi.taihe.com/v1/restserver/ting',
                    'body'   => array(
                        'from'     => 'qianqianmini',
                        'method'   => 'baidu.ting.song.lry',
                        'songid'   => $id,
                        'platform' => 'darwin',
                        'version'  => '1.0.0',
                    ),
                    'decode' => 'baidu_lyric',
                );
                break;
            case 'kuwo':
                $api = array(
                    'method' => 'GET',
                    'url'    => 'http://m.kuwo.cn/newh5/singles/songinfoandlrc',
                    'body'   => array(
                        'musicId'     => $id,
                        'httpsStatus' => 1,
                    ),
                    'decode' => 'kuwo_lyric',
                );
                break;
            default:
                return json_encode(array('lyric' => '', 'tlyric' => ''));
        }

        return $this->exec($api);
    }

    public function pic($id, $size = 300)
    {
        switch ($this->server) {
            case 'netease':
                return json_encode(array(
                    'url' => 'https://p3.music.126.net/' . $this->netease_encryptId($id) . '/' . $id . '.jpg?param=' . $size . 'y' . $size,
                ));
            case 'tencent':
                return json_encode(array(
                    'url' => 'https://y.gtimg.cn/music/photo_new/T002R' . $size . 'x' . $size . 'M000' . $id . '.jpg?max_age=2592000',
                ));
            case 'kugou':
                return $this->dynamicPicFromSong($id, 'kugou');
            case 'baidu':
                return $this->dynamicPicFromSong($id, 'baidu');
            case 'kuwo':
                return $this->dynamicPicFromSong($id, 'kuwo');
            default:
                return json_encode(array('url' => ''));
        }
    }

    private function dynamicPicFromSong($id, $server)
    {
        $format = $this->format;
        $this->format(false);
        $song = $this->song($id);
        $this->format($format);

        $song = json_decode($song, true);
        $url = '';

        if ($server === 'kugou' && is_array($song)) {
            $url = isset($song['imgUrl']) ? $song['imgUrl'] : '';
            $url = str_replace('{size}', '400', $url);
        } elseif ($server === 'baidu' && isset($song['songinfo'])) {
            $url = !empty($song['songinfo']['pic_radio']) ? $song['songinfo']['pic_radio'] : (isset($song['songinfo']['pic_small']) ? $song['songinfo']['pic_small'] : '');
        } elseif ($server === 'kuwo' && isset($song['data'])) {
            $url = !empty($song['data']['pic']) ? $song['data']['pic'] : (isset($song['data']['albumpic']) ? $song['data']['albumpic'] : '');
        }

        return json_encode(array('url' => $url));
    }

    private function curlset()
    {
        switch ($this->server) {
            case 'netease':
                $timestamp = strval(intval(microtime(true) * 1000));
                $deviceId = $this->getRandomHex(16);
                return array(
                    'Referer'          => 'music.163.com',
                    'Cookie'           => 'osver=android; appver=8.7.01; os=android; deviceId=' . $deviceId . '; channel=netease; requestId=' . $timestamp . '_' . str_pad(strval(mt_rand(0, 999)), 4, '0', STR_PAD_LEFT) . '; __remember_me=true',
                    'User-Agent'       => 'Mozilla/5.0 (Linux; Android 11; M2007J3SC Build/RKQ1.200826.002; wv) AppleWebKit/537.36 (KHTML, like Gecko) Version/4.0 Chrome/77.0.3865.120 MQQBrowser/6.2 TBS/045714 Mobile Safari/537.36 NeteaseMusic/8.7.01',
                    'Accept'           => '*/*',
                    'Accept-Language'  => 'zh-CN,zh;q=0.9,en-US;q=0.8,en;q=0.7',
                    'Connection'       => 'keep-alive',
                    'Content-Type'     => 'application/x-www-form-urlencoded',
                );
            case 'tencent':
                return array(
                    'Referer'          => 'http://y.qq.com',
                    'Cookie'           => 'pgv_pvi=22038528; pgv_si=s3156287488; pgv_pvid=5535248600; yplayer_open=1; ts_last=y.qq.com/portal/player.html; ts_uid=4847550686; yq_index=0; qqmusic_fromtag=66; player_exist=1',
                    'User-Agent'       => 'QQ%E9%9F%B3%E4%B9%90/54409 CFNetwork/901.1 Darwin/17.6.0 (x86_64)',
                    'Accept'           => '*/*',
                    'Accept-Language'  => 'zh-CN,zh;q=0.8,gl;q=0.6,zh-TW;q=0.4',
                    'Connection'       => 'keep-alive',
                    'Content-Type'     => 'application/x-www-form-urlencoded',
                );
            case 'kugou':
                return array(
                    'User-Agent'       => 'IPhone-8990-searchSong',
                    'UNI-UserAgent'    => 'iOS11.4-Phone8990-1009-0-WiFi',
                );
            case 'baidu':
                return array(
                    'Cookie'           => 'BAIDUID=' . $this->getRandomHex(32) . ':FG=1',
                    'User-Agent'       => 'Mozilla/5.0 (Macintosh; Intel Mac OS X 10_13_6) AppleWebKit/537.36 (KHTML, like Gecko) baidu-music/1.2.1 Chrome/66.0.3359.181 Electron/3.0.5 Safari/537.36',
                    'Accept'           => '*/*',
                    'Content-Type'     => 'application/json;charset=UTF-8',
                    'Accept-Language'  => 'zh-CN',
                );
            case 'kuwo':
                return $this->getKuwoHeaders();
        }

        return array();
    }

    private function getKuwoHeaders()
    {
        $userAgent = 'Mozilla/5.0 (Windows NT 10.0; WOW64) AppleWebKit/537.36 (KHTML, like Gecko) Chrome/91.0.4472.77 Safari/537.36';
        $headers = array(
            'Host'             => 'www.kuwo.cn',
            'Referer'          => 'http://www.kuwo.cn/',
            'User-Agent'       => $userAgent,
            'Accept'           => 'application/json, text/plain, */*',
            'Accept-Language'  => 'zh-CN,zh;q=0.9,en;q=0.8',
            'Connection'       => 'keep-alive',
        );

        $curl = curl_init();
        curl_setopt($curl, CURLOPT_URL, 'http://www.kuwo.cn/');
        curl_setopt($curl, CURLOPT_RETURNTRANSFER, 1);
        curl_setopt($curl, CURLOPT_HEADER, 1);
        curl_setopt($curl, CURLOPT_TIMEOUT, 10);
        curl_setopt($curl, CURLOPT_CONNECTTIMEOUT, 5);
        curl_setopt($curl, CURLOPT_IPRESOLVE, CURL_IPRESOLVE_V4);
        curl_setopt($curl, CURLOPT_ENCODING, 'gzip');
        curl_setopt($curl, CURLOPT_SSL_VERIFYPEER, 0);
        curl_setopt($curl, CURLOPT_HTTPHEADER, array(
            'Referer: http://www.kuwo.cn/',
            'User-Agent: ' . $userAgent,
            'Accept: text/html,application/xhtml+xml,application/xml;q=0.9,*/*;q=0.8',
            'Connection: keep-alive',
        ));
        $response = curl_exec($curl);
        curl_close($curl);

        if (is_string($response) && preg_match('/^Set-Cookie:\s*kw_token=([^;]+)/mi', $response, $match)) {
            $headers['Cookie'] = 'kw_token=' . $match[1];
            $headers['csrf'] = $match[1];
        }

        return $headers;
    }

    private function getRandomHex($length)
    {
        if (function_exists('random_bytes')) {
            return substr(bin2hex(random_bytes((int) ceil($length / 2))), 0, $length);
        }

        if (function_exists('openssl_random_pseudo_bytes')) {
            return substr(bin2hex(openssl_random_pseudo_bytes((int) ceil($length / 2))), 0, $length);
        }

        $buffer = '';
        while (strlen($buffer) < $length) {
            $buffer .= dechex(mt_rand(0, 15));
        }

        return substr($buffer, 0, $length);
    }

    private function netease_eapi($api)
    {
        $key = 'e82ckenh8dichen8';
        $text = json_encode($api['body']);
        $url = preg_replace('/https?:\/\/[^\/]+/', '', $api['url']);
        $message = 'nobody' . $url . 'use' . $text . 'md5forencrypt';
        $digest = md5($message);
        $data = $url . '-36cd479b6b5-' . $text . '-36cd479b6b5-' . $digest;

        $encrypted = openssl_encrypt($data, 'aes-128-ecb', $key, OPENSSL_RAW_DATA);
        $api['url'] = str_replace('/api/', '/eapi/', $api['url']);
        $api['body'] = array(
            'params' => strtoupper(bin2hex($encrypted)),
        );

        return $api;
    }

    private function baidu_AESCBC($api)
    {
        $key = 'DBEECF8C50FD160E';
        $vi = '1231021386755796';
        $data = 'songid=' . $api['body']['songid'] . '&ts=' . intval(microtime(true) * 1000);
        $api['body']['e'] = openssl_encrypt($data, 'aes-128-cbc', $key, 0, $vi);

        return $api;
    }

    private function parseCookie($cookieStr)
    {
        $cookies = array();
        if (empty($cookieStr)) {
            return $cookies;
        }

        foreach (explode(';', $cookieStr) as $pair) {
            $parts = explode('=', $pair, 2);
            if (count($parts) === 2) {
                $cookies[trim($parts[0])] = trim($parts[1]);
            }
        }

        return $cookies;
    }

    private function buildKugouSonginfoUrl($params)
    {
        $signature = $this->kugouSignature($params);
        $query = array();
        foreach ($params as $key => $value) {
            $query[] = $key . '=' . rawurlencode($value);
        }

        return 'https://wwwapi.kugou.com/play/songinfo?' . implode('&', $query) . '&signature=' . $signature;
    }

    private function kugouSignature($params)
    {
        $secret = 'NVPh5oo715z5DIWAeQlhMDsWXXQV4hwt';
        ksort($params);
        $pairs = array();
        foreach ($params as $key => $value) {
            $pairs[] = $key . '=' . $value;
        }

        return md5($secret . implode('', $pairs) . $secret);
    }

    private function decodeHtmlEntities($text)
    {
        return html_entity_decode($text, ENT_QUOTES | ENT_HTML5, 'UTF-8');
    }

    private function netease_encryptId($id)
    {
        $magic = str_split('3go8&$8*3*3h0k(2)2');
        $songId = str_split($id);
        for ($i = 0; $i < count($songId); $i++) {
            $songId[$i] = chr(ord($songId[$i]) ^ ord($magic[$i % count($magic)]));
        }
        $result = base64_encode(md5(implode('', $songId), true));
        return str_replace(array('/', '+'), array('_', '-'), $result);
    }

    private function netease_url($result)
    {
        $data = json_decode($result, true);
        $item = isset($data['data'][0]) ? $data['data'][0] : array();

        if (!empty($item['uf']['url'])) {
            $item['url'] = $item['uf']['url'];
        }

        if (!empty($item['url'])) {
            return json_encode(array(
                'url'  => $item['url'],
                'size' => isset($item['size']) ? $item['size'] : 0,
                'br'   => isset($item['br']) ? $item['br'] / 1000 : -1,
            ));
        }

        return json_encode(array('url' => '', 'size' => 0, 'br' => -1));
    }

    private function tencent_url($result)
    {
        $data = json_decode($result, true);
        if (empty($data['data'][0])) {
            return json_encode(array('url' => '', 'size' => 0, 'br' => -1));
        }

        $qualityMap = array(
            array('size_flac', 999, 'F000', 'flac'),
            array('size_320mp3', 320, 'M800', 'mp3'),
            array('size_192aac', 192, 'C600', 'm4a'),
            array('size_128mp3', 128, 'M500', 'mp3'),
            array('size_96aac', 96, 'C400', 'm4a'),
            array('size_48aac', 48, 'C200', 'm4a'),
            array('size_24aac', 24, 'C100', 'm4a'),
        );

        $guid = mt_rand(1000000000, 9999999999);
        $cookie = isset($this->header['Cookie']) ? $this->header['Cookie'] : '';
        preg_match('/uin=(\d+)/', $cookie, $uinMatch);
        $uin = isset($uinMatch[1]) ? $uinMatch[1] : '0';
        $song = $data['data'][0];

        $payload = array(
            'req_0' => array(
                'module' => 'vkey.GetVkeyServer',
                'method' => 'CgiGetVkey',
                'param'  => array(
                    'guid'      => strval($guid),
                    'songmid'   => array(),
                    'filename'  => array(),
                    'songtype'  => array(),
                    'uin'       => $uin,
                    'loginflag' => 1,
                    'platform'  => '20',
                ),
            ),
        );

        foreach ($qualityMap as $quality) {
            list($sizeKey, $br, $prefix, $ext) = $quality;
            $payload['req_0']['param']['songmid'][] = $song['mid'];
            $payload['req_0']['param']['filename'][] = $prefix . $song['file']['media_mid'] . '.' . $ext;
            $payload['req_0']['param']['songtype'][] = $song['type'];
        }

        $api = array(
            'method' => 'GET',
            'url'    => 'https://u.y.qq.com/cgi-bin/musicu.fcg',
            'body'   => array(
                'format'      => 'json',
                'platform'    => 'yqq.json',
                'needNewCode' => 0,
                'data'        => json_encode($payload),
            ),
        );
        $response = json_decode($this->exec($api), true);
        $vkeys = isset($response['req_0']['data']['midurlinfo']) ? $response['req_0']['data']['midurlinfo'] : array();
        $sip = isset($response['req_0']['data']['sip'][0]) ? $response['req_0']['data']['sip'][0] : '';

        foreach ($qualityMap as $index => $quality) {
            list($sizeKey, $br) = $quality;
            if (!empty($song['file'][$sizeKey]) && $br <= $this->temp['br']) {
                if (!empty($vkeys[$index]['vkey']) && !empty($vkeys[$index]['purl'])) {
                    return json_encode(array(
                        'url'  => $sip . $vkeys[$index]['purl'],
                        'size' => $song['file'][$sizeKey],
                        'br'   => $br,
                    ));
                }
            }
        }

        return json_encode(array('url' => '', 'size' => 0, 'br' => -1));
    }

    private function tencent_lyric($result)
    {
        $jsonStr = substr($result, 18, -1);
        $data = json_decode($jsonStr, true);

        return json_encode(array(
            'lyric'  => !empty($data['lyric']) ? $this->decodeHtmlEntities(base64_decode($data['lyric'])) : '',
            'tlyric' => !empty($data['trans']) ? $this->decodeHtmlEntities(base64_decode($data['trans'])) : '',
        ));
    }

    private function kugou_url_new($result)
    {
        $json = json_decode($result, true);
        $data = isset($json['data']) ? $json['data'] : array();
        if (empty($data['encode_album_audio_id'])) {
            return json_encode(array('url' => '', 'size' => 0, 'br' => -1));
        }

        try {
            $cookie = $this->parseCookie(isset($this->header['Cookie']) ? $this->header['Cookie'] : '');
            $now = intval(microtime(true) * 1000);
            $params = array(
                'srcappid'            => '2919',
                'clientver'           => '20000',
                'clienttime'          => strval($now),
                'mid'                 => isset($cookie['mid']) ? $cookie['mid'] : (isset($cookie['kg_mid']) ? $cookie['kg_mid'] : ''),
                'uuid'                => isset($cookie['uuid']) ? $cookie['uuid'] : (isset($cookie['mid']) ? $cookie['mid'] : (isset($cookie['kg_mid']) ? $cookie['kg_mid'] : '')),
                'dfid'                => isset($cookie['dfid']) ? $cookie['dfid'] : (isset($cookie['kg_dfid']) ? $cookie['kg_dfid'] : ''),
                'appid'               => '1014',
                'platid'              => '4',
                'encode_album_audio_id' => $data['encode_album_audio_id'],
                'token'               => isset($cookie['t']) ? $cookie['t'] : '',
                'userid'              => isset($cookie['KugooID']) ? $cookie['KugooID'] : '',
            );
            $api = array(
                'method' => 'GET',
                'url'    => $this->buildKugouSonginfoUrl($params),
                'body'   => null,
            );
            $response = json_decode($this->exec($api), true);
            $detail = isset($response['data']) ? $response['data'] : array();
            if (!empty($detail)) {
                return json_encode(array(
                    'url'  => !empty($detail['play_url']) ? $detail['play_url'] : (isset($detail['play_backup_url']) ? $detail['play_backup_url'] : ''),
                    'size' => isset($detail['filesize']) ? $detail['filesize'] : 0,
                    'br'   => isset($detail['bitrate']) ? $detail['bitrate'] : -1,
                ));
            }
        } catch (\Throwable $e) {
        }

        return json_encode(array('url' => '', 'size' => 0, 'br' => -1));
    }

    private function kugou_url_legacy($result)
    {
        $data = json_decode($result, true);
        if (empty($data['data'][0]['relate_goods'])) {
            return json_encode(array('url' => '', 'size' => 0, 'br' => -1));
        }

        $maxBr = 0;
        $picked = null;
        foreach ($data['data'][0]['relate_goods'] as $item) {
            $bitrate = isset($item['info']['bitrate']) ? $item['info']['bitrate'] : 0;
            if ($bitrate <= $this->temp['br'] && $bitrate > $maxBr) {
                $api = array(
                    'method' => 'GET',
                    'url'    => 'http://trackercdn.kugou.com/i/v2/',
                    'body'   => array(
                        'hash'     => $item['hash'],
                        'key'      => md5($item['hash'] . 'kgcloudv2'),
                        'pid'      => 3,
                        'behavior' => 'play',
                        'cmd'      => '25',
                        'version'  => 8990,
                    ),
                );
                $response = json_decode($this->exec($api), true);
                $url = isset($response['url']) ? $response['url'] : '';
                if (is_array($url)) {
                    $url = isset($url[0]) ? $url[0] : '';
                }
                if (!empty($url)) {
                    $maxBr = isset($response['bitRate']) ? $response['bitRate'] / 1000 : $bitrate;
                    $picked = array(
                        'url'  => $url,
                        'size' => isset($response['fileSize']) ? $response['fileSize'] : 0,
                        'br'   => $maxBr,
                    );
                }
            }
        }

        return json_encode($picked ?: array('url' => '', 'size' => 0, 'br' => -1));
    }

    private function kugou_lyric($result)
    {
        $data = json_decode($result, true);
        if (empty($data['candidates'][0])) {
            return json_encode(array('lyric' => '', 'tlyric' => ''));
        }

        $api = array(
            'method' => 'GET',
            'url'    => 'http://lyrics.kugou.com/download',
            'body'   => array(
                'charset'   => 'utf8',
                'accesskey' => $data['candidates'][0]['accesskey'],
                'id'        => $data['candidates'][0]['id'],
                'client'    => 'mobi',
                'fmt'       => 'lrc',
                'ver'       => 1,
            ),
        );
        $response = json_decode($this->exec($api), true);

        return json_encode(array(
            'lyric'  => !empty($response['content']) ? base64_decode($response['content']) : '',
            'tlyric' => '',
        ));
    }

    private function baidu_url($result)
    {
        $data = json_decode($result, true);
        $best = null;
        $max = 0;

        if (!empty($data['songurl']['url'])) {
            foreach ($data['songurl']['url'] as $item) {
                if ($item['file_bitrate'] <= $this->temp['br'] && $item['file_bitrate'] > $max) {
                    $max = $item['file_bitrate'];
                    $best = array(
                        'url' => $item['file_link'],
                        'br'  => $item['file_bitrate'],
                    );
                }
            }
        }

        return json_encode($best ?: array('url' => '', 'br' => -1));
    }

    private function baidu_lyric($result)
    {
        $data = json_decode($result, true);
        return json_encode(array(
            'lyric'  => isset($data['lrcContent']) ? $data['lrcContent'] : '',
            'tlyric' => '',
        ));
    }

    private function kuwo_url($result)
    {
        $data = json_decode($result, true);
        if (isset($data['code']) && 200 == $data['code'] && !empty($data['data']['url'])) {
            return json_encode(array(
                'url' => $data['data']['url'],
                'br'  => 128,
            ));
        }

        return json_encode(array('url' => '', 'br' => -1));
    }

    private function kuwo_lyric($result)
    {
        $data = json_decode($result, true);
        $lyric = '';
        if (!empty($data['data']['lrclist']) && is_array($data['data']['lrclist'])) {
            foreach ($data['data']['lrclist'] as $item) {
                $time = isset($item['time']) ? floatval($item['time']) : 0;
                $min = str_pad(strval(floor($time / 60)), 2, '0', STR_PAD_LEFT);
                $sec = str_pad(strval(floor(fmod($time, 60))), 2, '0', STR_PAD_LEFT);
                $msec = str_pad(strval((int) round(fmod($time, 1) * 100)), 2, '0', STR_PAD_LEFT);
                $lyric .= '[' . $min . ':' . $sec . '.' . $msec . ']' . $item['lineLyric'] . "\n";
            }
        }

        return json_encode(array(
            'lyric'  => $lyric,
            'tlyric' => '',
        ));
    }

    private function netease_lyric($result)
    {
        $data = json_decode($result, true);
        return json_encode(array(
            'lyric'  => isset($data['lrc']['lyric']) ? $data['lrc']['lyric'] : '',
            'tlyric' => isset($data['tlyric']['lyric']) ? $data['tlyric']['lyric'] : '',
        ));
    }

    private function format_netease($data)
    {
        $result = array(
            'id'       => $data['id'],
            'name'     => $data['name'],
            'artist'   => array(),
            'album'    => isset($data['al']['name']) ? $data['al']['name'] : '',
            'pic_id'   => isset($data['al']['pic_str']) ? $data['al']['pic_str'] : (isset($data['al']['pic']) ? $data['al']['pic'] : ''),
            'url_id'   => $data['id'],
            'lyric_id' => $data['id'],
            'source'   => 'netease',
        );

        if (!empty($data['al']['picUrl']) && preg_match('/\/(\d+)\./', $data['al']['picUrl'], $match)) {
            $result['pic_id'] = $match[1];
        }

        if (!empty($data['ar']) && is_array($data['ar'])) {
            foreach ($data['ar'] as $artist) {
                $result['artist'][] = $artist['name'];
            }
        }

        return $result;
    }

    private function format_tencent($data)
    {
        if (isset($data['musicData'])) {
            $data = $data['musicData'];
        }

        $result = array(
            'id'       => $data['mid'],
            'name'     => $data['name'],
            'artist'   => array(),
            'album'    => trim(isset($data['album']['title']) ? $data['album']['title'] : ''),
            'pic_id'   => isset($data['album']['mid']) ? $data['album']['mid'] : '',
            'url_id'   => $data['mid'],
            'lyric_id' => $data['mid'],
            'source'   => 'tencent',
        );

        if (!empty($data['singer']) && is_array($data['singer'])) {
            foreach ($data['singer'] as $artist) {
                $result['artist'][] = $artist['name'];
            }
        }

        return $result;
    }

    private function format_kugou($data)
    {
        $filename = isset($data['filename']) ? $data['filename'] : (isset($data['fileName']) ? $data['fileName'] : '');
        $result = array(
            'id'       => isset($data['hash']) ? $data['hash'] : '',
            'name'     => isset($data['songName']) ? $data['songName'] : $filename,
            'artist'   => array(),
            'album'    => isset($data['album_name']) ? $data['album_name'] : '',
            'url_id'   => !empty($data['encode_album_audio_id']) ? $data['encode_album_audio_id'] : (isset($data['hash']) ? $data['hash'] : ''),
            'pic_id'   => isset($data['hash']) ? $data['hash'] : '',
            'lyric_id' => isset($data['hash']) ? $data['hash'] : '',
            'source'   => 'kugou',
        );

        if (!empty($data['authors']) && is_array($data['authors'])) {
            foreach ($data['authors'] as $author) {
                if (!empty($author['author_name'])) {
                    $result['artist'][] = $author['author_name'];
                }
            }
        } elseif (!empty($filename)) {
            $parts = explode(' - ', $filename, 2);
            if (count($parts) === 2) {
                $result['artist'] = explode('、', $parts[0]);
                $result['name'] = $parts[1];
            }
        }

        return $result;
    }

    private function format_baidu($data)
    {
        return array(
            'id'       => $data['song_id'],
            'name'     => $data['title'],
            'artist'   => !empty($data['author']) ? explode(',', $data['author']) : array(),
            'album'    => isset($data['album_title']) ? $data['album_title'] : '',
            'pic_id'   => $data['song_id'],
            'url_id'   => $data['song_id'],
            'lyric_id' => $data['song_id'],
            'source'   => 'baidu',
        );
    }

    private function format_kuwo($data)
    {
        return array(
            'id'       => $data['rid'],
            'name'     => $data['name'],
            'artist'   => !empty($data['artist']) ? explode('&', $data['artist']) : array(),
            'album'    => isset($data['album']) ? $data['album'] : '',
            'pic_id'   => $data['rid'],
            'url_id'   => $data['rid'],
            'lyric_id' => $data['rid'],
            'source'   => 'kuwo',
        );
    }
}
