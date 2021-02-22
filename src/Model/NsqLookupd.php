<?php

namespace Jiyis\Nsq\Model;

use \Exception;
use Illuminate\Support\Arr;
use Illuminate\Support\Facades\Log;
use Jiyis\Nsq\Exception\LookupException;

class NsqLookupd
{
    /**
     * Hosts to connect to
     *
     * @var array
     */
    private $hosts;

    /**
     * @var array
     */
    private $nsqDriverConfig;

    /**
     * Connection timeout, in seconds
     *
     * @var float
     */
    private $connectionTimeout;

    /**
     * Response timeout, in seconds
     *
     * @var float
     */
    private $responseTimeout;

    /**
     * Constructor
     *
     * @param array $hosts Will default to localhost
     * @param float $connectionTimeout
     * @param float $responseTimeout
     */
    public function __construct(array $config = [], $connectionTimeout = 1.0, $responseTimeout = 2.0)
    {
        if (empty($config)) {
            throw new \Exception("Required NSQ config");
        }
        //TODO: validate required params nsqlookup host and port
        $hosts = Arr::get($config, 'connection.nsqlookup_url');
        if (!$hosts) {
            throw new LookupException('required connection.nsqlookup_url config in nsq.php');
        }
        $this->hosts = $hosts;
        $this->nsqDriverConfig = $config;
        $this->connectionTimeout = $connectionTimeout;
        $this->responseTimeout = $responseTimeout;
    }

    /**
     * Lookup hosts for a given topic
     * @param string $topic
     * @return array
     */
    public function lookup(string $topic, string $channel)
    {
        $nsqdList = new NsqdList();

        foreach ($this->hosts as $hostUrl) {
            $url = sprintf('http://%s/lookup?topic=%s', $hostUrl, urlencode($topic));

            $ch = curl_init($url);
            $options = [
                CURLOPT_RETURNTRANSFER => true,
                CURLOPT_HEADER         => false,
                CURLOPT_FOLLOWLOCATION => false,
                CURLOPT_ENCODING       => '',
                CURLOPT_USERAGENT      => 'nsq swoole client',
                CURLOPT_CONNECTTIMEOUT => $this->connectionTimeout,
                CURLOPT_TIMEOUT        => $this->responseTimeout,
                CURLOPT_FAILONERROR    => true
            ];
            curl_setopt_array($ch, $options);
            $resultStr = curl_exec($ch);
            if (!curl_error($ch) && curl_getinfo($ch, CURLINFO_HTTP_CODE) == '200') {
                $result = json_decode($resultStr, true);
                if (!$result) {
                    throw new LookupException("Error to parse nsqd response $hostUrl. Payload: $payload");
                }
                if (isset($result['message']) && $result['message'] == 'TOPIC_NOT_FOUND') {
                    throw new LookupException("Topic $topic not found in nsqdlookup $hostUrl");
                }
                $producers = [];
                Log::info($result);
                if (isset($result['data']['producers'])) {
                    //0.3.8
                    $producers = $result['data']['producers'];
                } elseif (isset($result['producers'])) {
                    //>=1.0.0
                    $producers = $result['producers'];
                }
                if (empty($producers)) {
                    throw new LookupException("None producer for topic $topic in nsqdlookup $hostUrl found");
                }
                foreach ($producers as $prod) {
                    $nsqd = new Nsqd(
                        $this->nsqDriverConfig,
                        $prod,
                        $topic,
                        $channel
                    );
                    $nsqd->sub();
                    $nsqdList->add($nsqd);
                }
                curl_close($ch);
            } elseif (curl_getinfo($ch, CURLINFO_HTTP_CODE) == '404') {
                Log::info("Topic not found at URL: $url");
                curl_close($ch);
                continue;
            } else {
                $err = curl_error($ch);
                Log::error($err . $resultStr);
                curl_close($ch);
                throw new LookupException($err, -1);
            }
        }
        return $nsqdList;
    }

    /**
     * Lookup hosts for a given topic
     * @param string $topic
     * @return array
     */
    public function nodes()
    {
        $factory = new Factory();
        $nsqdList = new NsqdList();

        foreach ($this->hosts as $hostUrl) {
            $socket = null;
            try {
                $socket = $factory->createClient($hostUrl, $this->responseTimeout);

                $path = '/nodes';
                $socket->write("GET $path HTTP/1.1\r\nHost: $hostUrl\r\nUser-Agent: Laravel-nsq driver \r\n\r\n");
                $payload = $socket->read(8192);
                $body = substr($payload, strrpos($payload, "{"));
                $result = json_decode($body, true);
                if (!$result) {
                    throw new LookupException("Error to connect NsqLookup url $hostUrl");
                }
                $producers = [];
                if (isset($result['data']['producers'])) {
                    //0.3.8
                    $producers = $result['data']['producers'];
                } elseif (isset($result['producers'])) {
                    //>=1.0.0
                    $producers = $result['producers'];
                }
                foreach ($producers as $producer) {
                    $nsqd = new Nsqd(
                        $this->nsqDriverConfig,
                        $producer
                    );
                    $nsqdList->add($nsqd);
                }
            } catch (Exception $e) {
                throw new LookupException($e->getMessage());
            } finally {
                if ($socket) {
                    $socket->close();
                }
            }
        }
        return $nsqdList;
    }
}