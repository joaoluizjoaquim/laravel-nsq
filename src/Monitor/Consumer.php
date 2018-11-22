<?php

namespace Jiyis\Nsq\Monitor;


use Illuminate\Support\Arr;
use Illuminate\Support\Facades\Log;
use Jiyis\Nsq\Message\Packet;
use Swoole\Client;

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

        list($host, $port) = explode(':', $this->host);
        // connect nsq server
        Log::debug('connecting to nsq server '.$host.':'.$port);
        if (!$this->client->connect($host, $port, 3)) {
            throw new \Exception('connect nsq server failed.');
        }
        Log::debug('nsq server connected '.$host.':'.$port);
        // send magic to nsq server
        Log::debug('send magic to nsq server');
        $this->client->send(Packet::magic());

        // send identify params
        Log::debug('send identify params');
        $this->client->send(Packet::identify(Arr::get($this->config, 'identify')));

        // sub nsq topic and channel
        Log::debug('sub nsq topic and channel');
        $this->client->send(Packet::sub($this->topic, $this->channel));

        // tell nsq server to be ready accept {n} data
        Log::debug('tell nsq server to be ready accept {n} data');
        $this->client->send(Packet::rdy(Arr::get($this->config, 'options.rdy', 1)));
    }
}