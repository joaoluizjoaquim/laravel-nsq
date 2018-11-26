<?php

namespace Jiyis\Nsq\Monitor;


use Illuminate\Support\Arr;
use Illuminate\Support\Facades\Log;
use Jiyis\Nsq\Message\Packet;
use Swoole\Client;
use Jiyis\Nsq\Exception\LookupException;

class Consumer extends AbstractMonitor
{

    /**
     * Subscribe topic
     *
     * @var string
     */
    protected $topic;

    /**
     * Subscribe channel
     *
     * @var string
     */
    protected $channel;

    /**
     * Nsqd config
     *
     * @var string
     */
    protected $config;

    /**
     * Nsqd host
     *
     * @var string
     */
    protected $host;

    protected $stats;

    /**
     * Consumer constructor.
     * @param $host
     * @param array $config
     * @param $topic
     * @param $channel
     * @throws \Exception
     */
    public function __construct($host, array $config, $topic, $channel)
    {
        $this->host = $host;
        $this->config = $config;
        $this->topic = $topic;
        $this->channel = $channel;
        $this->connect();
        $this->stats = $this->getStatsFromNsqdInstance();
    }

    /**
     * @throws \Exception
     */
    public function connect()
    {
        // init swoole client
        $this->client = new Client(SWOOLE_SOCK_TCP);

        // set swoole tcp client config
        $this->client->set(Arr::get($this->config, 'client.options'));

        $host = $this->host['host'];
        $port = $this->host['tcp_port'];
        // connect nsq server
        Log::debug('connecting to nsq server '.$this->getTcpAddress());
        if (!$this->client->connect($host, $port, 3)) {
            throw new \Exception('connect nsq server failed.');
        }
        Log::debug('nsq server connected '.$this->getTcpAddress());
        // send magic to nsq server
        Log::debug('send magic to nsq server');
        $this->client->send(Packet::magic());

        // send identify params
        Log::debug('send identify params');
        $this->client->send(Packet::identify(Arr::get($this->config, 'identify')));

        // sub nsq topic and channel
        Log::debug('sub nsq topic and channel');
        $this->client->send(Packet::sub($this->topic, $this->channel));

        $this->sendReady(Arr::get($this->config, 'options.rdy', 1));
    }

    // tell nsq server to be ready accept {n} data
    public function sendReady(int $count): void
    {
        Log::debug("tell nsq server to be ready accept $count data");
        $this->client->send(Packet::rdy($count));
    }

    public function getTcpAddress()
    {
        return $this->host['host'].':'.$this->host['tcp_port'];
    }

    public function getHttpAddress()
    {
        return $this->host['host'].':'.$this->host['tcp_port'];
    }

    public function getStatsFromNsqdInstance(): array
    {
        $host = $this->host['host'].':'.$this->host['http_port'];
        $url = sprintf('http://%s/stats?format=json&topic=%s&channel=%s', $host, urlencode($this->topic), urlencode($this->channel));

        $ch = curl_init($url);
        $options = [
            CURLOPT_RETURNTRANSFER => true,
            CURLOPT_HEADER         => false,
            CURLOPT_FOLLOWLOCATION => false,
            CURLOPT_ENCODING       => '',
            CURLOPT_USERAGENT      => 'nsq swoole client',
            CURLOPT_CONNECTTIMEOUT => 5,
            CURLOPT_TIMEOUT        => 6,
            CURLOPT_FAILONERROR    => true
        ];

        curl_setopt_array($ch, $options);
        if (!$resultStr = curl_exec($ch)) {
            throw new LookupException('Error talking to nsqd via ' . $url);
        }

        if (!curl_error($ch) && curl_getinfo($ch, CURLINFO_HTTP_CODE) == '200') {
            $result = json_decode($resultStr, true);
            $channelStats = [];
            foreach($result['topics'] as $topicItem) {
                if ($topicItem['topic_name'] != $this->topic) continue;

                foreach($topicItem['channels'] as $channelItem) {
                    if ($channelItem['channel_name'] != $this->channel) continue;

                    $channelStats = $channelItem;
                    break;
                }
                break;
            }
            curl_close($ch);
            return $channelStats;
        } else {
            $err = curl_error($ch);
            Log::error($err . $resultStr);
            curl_close($ch);
            throw new LookupException($err, -1);
        }
    }

    public function getDepthMessages()
    {
        return $this->stats['depth'];
    }

    public function hasDepthMessages(): bool
    {
        return isset($this->stats['depth']) && $this->stats['depth'] > 0;
    }
}