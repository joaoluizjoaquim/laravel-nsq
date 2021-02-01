<?php

namespace Jiyis\Nsq\Monitor;

use Illuminate\Support\Arr;
use Jiyis\Nsq\Adapter\TcpClient;
use Jiyis\Nsq\Message\Packet;

class Producer extends AbstractMonitor
{

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

    public function __construct($host, array $config)
    {
        $this->host = $host;
        $this->config = $config;
        $this->connect();

    }

    public function connect()
    {
        $this->client = new TcpClient();

        list($host, $port) = explode(':', $this->host);
    
        $this->client->connect($host, $port);

        $this->client->send(Packet::magic());

        $this->client->send(Packet::identify(Arr::get($this->config, 'identify')));

        $this->client->recv();

    }

}