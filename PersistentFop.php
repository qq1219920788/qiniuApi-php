<?php
namespace qiniuApi;

use qiniuApi\Http\Error;
use qiniuApi\Http\Request;
use qiniuApi\Http\Response;

/**
 * 持久化处理类,该类用于主动触发异步持久化操作.
 *
 * @link http://developer.qiniu.com/docs/v6/api/reference/fop/pfop/pfop.html
 */
final class PersistentFop
{
    private $SDK_VER = '7.2.7';

    private $API_HOST = 'api.qiniu.com';
    /**
     * @var 账号管理密钥对，Auth对象
     */
    private $auth;

    /*
     * @var 配置对象，Config 对象
     * */
    private $config;


    public function __construct($auth, $config = null)
    {
        $this->auth = $auth;
        if ($config == null) {
            $this->config = new Config();
        } else {
            $this->config = $config;
        }
    }

    /**
     * 对资源文件进行异步持久化处理
     * @param $bucket     资源所在空间
     * @param $key        待处理的源文件
     * @param $fops       string|array  待处理的pfop操作，多个pfop操作以array的形式传入。
     *                    eg. avthumb/mp3/ab/192k, vframe/jpg/offset/7/w/480/h/360
     * @param $pipeline   资源处理队列
     * @param $notify_url 处理结果通知地址
     * @param $force      是否强制执行一次新的指令
     *
     *
     * @return array 返回持久化处理的persistentId, 和返回的错误。
     *
     * @link http://developer.qiniu.com/docs/v6/api/reference/fop/
     */
    public function execute($bucket, $key, $fops, $pipeline = null, $notify_url = null, $force = false)
    {
        if (is_array($fops)) {
            $fops = implode(';', $fops);
        }
        $params = array('bucket' => $bucket, 'key' => $key, 'fops' => $fops);
        $this->setWithoutEmpty($params, 'pipeline', $pipeline);
        $this->setWithoutEmpty($params, 'notifyURL', $notify_url);
        if ($force) {
            $params['force'] = 1;
        }
        $data = http_build_query($params);
        $scheme = "http://";
        if ($this->config->useHTTPS === true) {
            $scheme = "https://";
        }
        $url = $scheme . $API_HOST . '/pfop/';
        $headers = $this->auth->authorization($url, $data, 'application/x-www-form-urlencoded');
        $headers['Content-Type'] = 'application/x-www-form-urlencoded';
        $response = Client::post($url, $data, $headers);
        if (!$response->ok()) {
            return array(null, new Error($url, $response));
        }
        $r = $response->json();
        $id = $r['persistentId'];
        return array($id, null);
    }

    public static function post($url, $body, array $headers = array())
    {
        $request = new Request('POST', $url, $headers, $body);
        return self::sendRequest($request);
    }

    public function setWithoutEmpty(&$array, $key, $value)
    {
        if (!empty($value)) {
            $array[$key] = $value;
        }
        return $array;
    }

    private static function userAgent()
    {
        $sdkInfo = "QiniuPHP/" . $SDK_VER;

        $systemInfo = php_uname("s");
        $machineInfo = php_uname("m");

        $envInfo = "($systemInfo/$machineInfo)";

        $phpVer = phpversion();

        $ua = "$sdkInfo $envInfo PHP/$phpVer";
        return $ua;
    }

    private static function parseHeaders($raw)
    {
        $headers = array();
        $headerLines = explode("\r\n", $raw);
        foreach ($headerLines as $line) {
            $headerLine = trim($line);
            $kv = explode(':', $headerLine);
            if (count($kv) > 1) {
                $kv[0] =self::ucwordsHyphen($kv[0]);
                $headers[$kv[0]] = trim($kv[1]);
            }
        }
        return $headers;
    }

    public static function sendRequest($request)
    {
        $t1 = microtime(true);
        $ch = curl_init();
        $options = array(
            CURLOPT_USERAGENT => self::userAgent(),
            CURLOPT_RETURNTRANSFER => true,
            CURLOPT_SSL_VERIFYPEER => false,
            CURLOPT_SSL_VERIFYHOST => false,
            CURLOPT_HEADER => true,
            CURLOPT_NOBODY => false,
            CURLOPT_CUSTOMREQUEST => $request->method,
            CURLOPT_URL => $request->url,
        );

        // Handle open_basedir & safe mode
        if (!ini_get('safe_mode') && !ini_get('open_basedir')) {
            $options[CURLOPT_FOLLOWLOCATION] = true;
        }

        if (!empty($request->headers)) {
            $headers = array();
            foreach ($request->headers as $key => $val) {
                array_push($headers, "$key: $val");
            }
            $options[CURLOPT_HTTPHEADER] = $headers;
        }
        curl_setopt($ch, CURLOPT_HTTPHEADER, array('Expect:'));

        if (!empty($request->body)) {
            $options[CURLOPT_POSTFIELDS] = $request->body;
        }
        curl_setopt_array($ch, $options);
        $result = curl_exec($ch);
        $t2 = microtime(true);
        $duration = round($t2 - $t1, 3);
        $ret = curl_errno($ch);
        if ($ret !== 0) {
            $r = new Response(-1, $duration, array(), null, curl_error($ch));
            curl_close($ch);
            return $r;
        }
        $code = curl_getinfo($ch, CURLINFO_HTTP_CODE);
        $header_size = curl_getinfo($ch, CURLINFO_HEADER_SIZE);
        $headers = self::parseHeaders(substr($result, 0, $header_size));
        $body = substr($result, $header_size);
        curl_close($ch);
        return new Response($code, $duration, $headers, $body, null);
    }
}
