<?php

namespace TypechoPlugin\Meting;

use Typecho\Common;
use Typecho\Plugin\PluginInterface;
use Typecho\Widget\Helper\Form;
use Typecho\Widget\Helper\Form\Element\Text;
use Typecho\Widget\Helper\Form\Element\Radio;
use Typecho\Widget\Helper\Form\Element\Textarea;
use Utils\Helper;
use Widget\Options;
use Widget\Archive;

if (!defined('__TYPECHO_ROOT_DIR__')) {
    exit;
}

/**
 * 在 Typecho 中使用 APlayer 播放在线音乐吧～
 *
 * @package APlayer for Typecho | Meting
 * @author METO
 * @version 3.0.0
 * @dependence 14.10.10-*
 * @link https://github.com/mikusaa/Typecho-Plugin-APlayer
 *
 */

define('METING_VERSION', '3.0.0');

class Plugin implements PluginInterface
{
    /**
     * 激活插件方法,如果激活失败,直接抛出异常
     *
     * @access public
     * @return void
     * @throws \Typecho\Plugin\Exception
     */
    public static function activate()
    {
        self::installCheck();
        Helper::addAction('metingapi', Action::class);
        self::registerContentsHook('contentEx', [__CLASS__, 'playerReplace']);
        self::registerContentsHook('excerptEx', [__CLASS__, 'playerReplace']);
        \Typecho\Plugin::factory('Widget_Archive')->header = [__CLASS__, 'header'];
        \Typecho\Plugin::factory('Widget_Archive')->footer = [__CLASS__, 'footer'];
        \Typecho\Plugin::factory('admin/write-post.php')->bottom = [__CLASS__, 'addButton'];
        \Typecho\Plugin::factory('admin/write-page.php')->bottom = [__CLASS__, 'addButton'];
    }

    /**
     * 禁用插件方法,如果禁用失败,直接抛出异常
     *
     * @static
     * @access public
     * @return void
     * @throws \Typecho\Plugin\Exception
     */
    public static function deactivate()
    {
        Helper::removeAction("metingapi");
    }

    /**
     * 获取插件配置面板
     *
     * @access public
     * @param Form $form 配置面板
     * @return void
     */
    public static function config(Form $form)
    {
        $pluginOptions = Options::alloc()->plugin('Meting');

        $t = new Text(
            'theme',
            null,
            '#ad7a86',
            _t('播放器颜色'),
            _t('播放器默认的主题颜色，支持如 #372e21、#75c、red，该设定会被[Meting]标签中的theme属性覆盖，默认为 #ad7a86')
        );
        $form->addInput($t);
        $t = new Text(
            'height',
            null,
            '340px',
            _t('播放器列表最大高度'),
            _t('')
        );
        $form->addInput($t);
        $t = new Radio(
            'autoplay',
            array('true' => _t('是'),'false' => _t('否')),
            'false',
            _t('全局自动播放'),
            _t('')
        );
        $form->addInput($t);
        $t = new Radio(
            'order',
            array('list' => _t('列表'), 'random' => _t('随机')),
            'list',
            _t('全局播放模式'),
            _t('')
        );
        $form->addInput($t);
        $t = new Radio(
            'preload',
            array('auto' => _t('自动'),'none' => _t('不加载'),'metadata' => _t('加载元数据')),
            'auto',
            _t('预加载属性'),
            _t('')
        );
        $form->addInput($t);

        $list = array(
            'none' => _t('关闭'),
            'redis' => _t('Redis'),
            'memcached' => _t('Memcached'),
            'mysql' => _t('MySQL'),
            'sqlite' => _t('SQLite')
        );
        $t = new Radio(
            'cachetype',
            $list,
            'none',
            _t('缓存驱动'),
            _t('缓存歌曲解析信息，降低服务器压力')
        );
        $form->addInput($t);
        $t = new Text(
            'cachehost',
            null,
            '127.0.0.1',
            _t('缓存服务地址'),
            _t('通常为 localhost, 127.0.0.1')
        );
        $form->addInput($t);
        $t = new Text(
            'cacheport',
            null,
            '6379',
            _t('缓存服务端口'),
            _t('默认端口 memcache: 11211, Redis: 6379, Mysql: 3306')
        );
        $form->addInput($t);

        $t = new Radio(
            'bitrate',
            array('128' => _t('流畅品质 128K'),'192' => _t('清晰品质 192K'),'320' => _t('高品质 320K')),
            '192',
            _t('默认音质'),
            _t('')
        );
        $form->addInput($t);
        $t = new Text(
            'api',
            null,
            Common::url('action/metingapi', Helper::options()->index)."?server=:server&type=:type&id=:id&auth=:auth&r=:r",
            _t('* 云解析地址'),
            _t('示例：https://api.i-meto.com/meting/api?server=:server&type=:type&id=:id&r=:r')
        );
        $form->addInput($t);
        $t = new Text(
            'salt',
            null,
            $pluginOptions->salt ?: '',
            _t('* 接口保护'),
            _t('加盐保护 API 接口不被滥用，自动生成无需设置。')
        );
        $form->addInput($t);
        $t = new Textarea(
            'cookie',
            null,
            '',
            _t('* 网易云音乐 Cookie'),
            _t('如果您是网易云音乐的会员，可以将您的 cookie 填入此处来获取云盘等付费资源，听歌将不会计入下载次数。<br><b>如果不知道这是什么意思，忽略即可。</b>')
        );
        $form->addInput($t);

        echo '<a href="'.Common::url('action/metingapi', Helper::options()->index).'?do=update" target="_blank"><button class="btn" style="outline: 0">' . _t('检查并更新插件'). '</button></a>';
    }

    /**
     * 手动保存配置句柄
     * @param $config array 插件配置
     * @param $is_init bool 是否初始化
     */
    public static function configHandle($config, $is_init)
    {
        if (empty($config['api'])) {
            $config['api'] = Common::url('action/metingapi', Helper::options()->index)."?server=:server&type=:type&id=:id&auth=:auth&r=:r";
        }
        if (empty($config['salt'])) {
            $config['salt'] = Common::randString(32);
        }

        if (!$is_init) {
            if ($config['cachetype'] != 'none') {
                require_once 'driver/cache.interface.php';
                require_once 'driver/'.$config['cachetype'].'.class.php';
                try {
                    $cache = new \MetingCache(array(
                        'host' => $config['cachehost'],
                        'port' => $config['cacheport']
                    ));
                    $cache->install();
                    $cache->check();
                    $cache->flush();
                } catch (\Exception $e) {
                    throw new \Typecho\Plugin\Exception(_t($e->getMessage()));
                }
            }
        }

        Helper::configPlugin('Meting', $config);
    }

    public static function personalConfig(Form $form)
    {
    }

    private static $headerLoaded = false;
    private static $footerLoaded = false;

    private static function normalizeFactoryHandle($handle)
    {
        if (defined('__TYPECHO_CLASS_ALIASES__')) {
            $alias = array_search('\\' . ltrim($handle, '\\'), __TYPECHO_CLASS_ALIASES__, true);
            if (false !== $alias) {
                $handle = $alias;
            }
        }

        if (class_exists('Typecho\\Common')) {
            return Common::nativeClassName($handle);
        }

        return trim(str_replace('\\', '_', $handle), '_');
    }

    private static function registerContentsHook($component, $callback)
    {
        $targets = array('Widget_Abstract_Contents');
        if (class_exists('Widget\Base\Contents')) {
            $targets[] = 'Widget\Base\Contents';
        }

        $registered = array();
        foreach ($targets as $target) {
            $normalized = self::normalizeFactoryHandle($target);
            if (isset($registered[$normalized])) {
                continue;
            }

            \Typecho\Plugin::factory($target)->{$component} = $callback;
            $registered[$normalized] = true;
        }
    }

    public static function header()
    {
        if (self::$headerLoaded) {
            return;
        }
        self::$headerLoaded = true;

        $api = Options::alloc()->plugin('Meting')->api;
        $dir = Helper::options()->pluginUrl.'/Meting/assets';
        $ver = METING_VERSION;
        echo "<link rel=\"stylesheet\" href=\"{$dir}/APlayer.min.css?v={$ver}\">\n";
        echo "<script type=\"text/javascript\" src=\"{$dir}/APlayer.min.js?v={$ver}\"></script>\n";
        echo "<script>var meting_api=\"{$api}\";</script>";
    }

    public static function footer()
    {
        if (self::$footerLoaded) {
            return;
        }
        self::$footerLoaded = true;

        $dir = Helper::options()->pluginUrl.'/Meting/assets';
        $ver = METING_VERSION;
        echo "<script type=\"text/javascript\" src=\"{$dir}/Meting.min.js?v={$ver}\"></script>\n";
    }

    public static function playerReplace($data, $widget, $last)
    {
        $text = empty($last) ? $data : $last;
        if ($widget instanceof Archive) {
            $data = $text;
            $pattern = self::get_shortcode_regex(array('Meting'));
            $text = preg_replace_callback("/$pattern/", [__CLASS__, 'parseCallback'], $data);
        }
        return $text;
    }

    public static function parseCallback($matches)
    {
        $setting = self::shortcode_parse_atts(htmlspecialchars_decode($matches[3]));
        $matches[5] = htmlspecialchars_decode($matches[5]);
        $pattern = self::get_shortcode_regex(array('Music'));
        preg_match_all("/$pattern/", $matches[5], $all);
        if (sizeof($all[3])) {
            return self::parseMusic($all[3], $setting);
        }
    }

    public static function parseMusic($matches, $setting)
    {
        $str = "";
        foreach ($matches as $vo) {
            $t = self::shortcode_parse_atts(htmlspecialchars_decode($vo));
            $player = array(
                'theme' => Options::alloc()->plugin('Meting')->theme ?: 'red',
                'preload' => Options::alloc()->plugin('Meting')->preload ?: 'auto',
                'autoplay' => Options::alloc()->plugin('Meting')->autoplay ?: 'false',
                'list-max-height' => Options::alloc()->plugin('Meting')->height ?: '340px',
                'order' => Options::alloc()->plugin('Meting')->order ?: 'list',
            );
            if (isset($t['server'])) {
                if (!isset($t['type'], $t['id'])) {
                    continue;
                }
                if (!in_array($t['server'], array('netease', 'tencent', 'kugou', 'baidu', 'kuwo'), true)) {
                    continue;
                }
                if (!in_array($t['type'], array('search', 'album', 'playlist', 'artist', 'song'), true)) {
                    continue;
                }
                $data = $t;

                $salt = Options::alloc()->plugin('Meting')->salt;
                $auth = md5($salt.$data['server'].$data['type'].$data['id'].$salt);

                $str .= "<meting-js server=\"{$data['server']}\" type=\"{$data['type']}\" id=\"{$data['id']}\" auth=\"{$auth}\"";
                if (is_array($setting)) {
                    foreach ($setting as $key => $vo) {
                        $attr = strtolower(preg_replace('/([A-Z])/', '-$1', $key));
                        $player[$attr] = $vo;
                    }
                }
                foreach ($player as $key => $vo) {
                    $str .= " {$key}=\"{$vo}\"";
                }
                $str .= "></meting-js>\n";
            } else {
                $data = $t;

                $str .= "<meting-js name=\"{$data['title']}\" artist=\"{$data['author']}\" url=\"{$data['url']}\" cover=\"{$data['pic']}\" lrc=\"{$data['lrc']}\"";
                if (is_array($setting)) {
                    foreach ($setting as $key => $vo) {
                        $attr = strtolower(preg_replace('/([A-Z])/', '-$1', $key));
                        $player[$attr] = $vo;
                    }
                }
                foreach ($player as $key => $vo) {
                    $str .= " {$key}=\"{$vo}\"";
                }
                $str .= "></meting-js>\n";
            }
        }
        return $str;
    }

    public static function addButton()
    {
        $url = Common::url('action/metingapi', Helper::options()->index).'?do=parse';
        $dir = Helper::options()->pluginUrl.'/Meting/assets/editer.js?v='.METING_VERSION;
        echo "<script>var murl='{$url}';</script>
              <script type=\"text/javascript\" src=\"{$dir}\"></script>";
    }

    # https://github.com/WordPress/WordPress/blob/master/wp-includes/shortcodes.php#L508
    private static function shortcode_parse_atts($text)
    {
        $atts = array();
        $pattern = '/([\w-]+)\s*=\s*"([^"]*)"(?:\s|$)|([\w-]+)\s*=\s*\'([^\']*)\'(?:\s|$)|([\w-]+)\s*=\s*([^\s\'"]+)(?:\s|$)|"([^"]*)"(?:\s|$)|(\S+)(?:\s|$)/';
        $text = preg_replace("/[\x{00a0}\x{200b}]+/u", " ", $text);
        if (preg_match_all($pattern, $text, $match, PREG_SET_ORDER)) {
            foreach ($match as $m) {
                if (!empty($m[1])) {
                    $atts[strtolower($m[1])] = stripcslashes($m[2]);
                } elseif (!empty($m[3])) {
                    $atts[strtolower($m[3])] = stripcslashes($m[4]);
                } elseif (!empty($m[5])) {
                    $atts[strtolower($m[5])] = stripcslashes($m[6]);
                } elseif (isset($m[7]) && strlen($m[7])) {
                    $atts[] = stripcslashes($m[7]);
                } elseif (isset($m[8])) {
                    $atts[] = stripcslashes($m[8]);
                }
            }
            foreach ($atts as &$value) {
                if (false !== strpos($value, '<')) {
                    if (1 !== preg_match('/^[^<]*+(?:<[^>]*+>[^<]*+)*+$/', $value)) {
                        $value = '';
                    }
                }
            }
        } else {
            $atts = ltrim($text);
        }
        return $atts;
    }

    # https://github.com/WordPress/WordPress/blob/master/wp-includes/shortcodes.php#L254
    private static function get_shortcode_regex($tagnames = null)
    {
        $tagregexp = join('|', array_map('preg_quote', $tagnames));
        return '\[(\[?)('.$tagregexp.')(?![\w-])([^\]\/]*(?:\/(?!\])[^\]\/]*)*?)(?:(\/)\]|\](?:([^\[]*+(?:\[(?!\/\2\])[^\[]*+)*+)\[\/\2\])?)(\]?)';
    }

    public static function installCheck()
    {
        if (!extension_loaded('curl')) {
            throw new \Typecho\Plugin\Exception(_t('缺少 cURL 拓展'));
        }
        if (!(extension_loaded('openssl') || extension_loaded('mcrypt'))) {
            throw new \Typecho\Plugin\Exception(_t('缺少 openssl/mcrypt 拓展'));
        }
    }
}
