<?php

namespace Jiyis\Nsq\Model;

use Illuminate\Support\Arr;
use Illuminate\Support\Facades\Log;
use Jiyis\Nsq\Adapter\TcpClient;
use Jiyis\Nsq\Exception\LookupException;
use Jiyis\Nsq\Message\Packet;
use Jiyis\Nsq\Monitor\AbstractMonitor;
use Socket\Raw\Factory;

class Nsqd extends AbstractMonitor
{
    private $nsq_driver_config;

    private $remote_address;

    private $hostname;

    private $broadcast_address;

    private $tcp_port;

    private $http_port;

    private $version;

    private $topic;

    private $channel;

    public function __construct(
        array $nsqDriverConfig, 
        array $config = [],
        string $topic = null,
        string $channel = null
    ) {
        $this->nsqDriverConfig = $nsqDriverConfig;
        if (empty($config)) {
            throw new \Exception("Required nsqd config");
        }
        $this->remote_address = $config['remote_address'];
        $this->hostname = $config['hostname'];
        $this->broadcast_address = $config['broadcast_address'];
        $this->tcp_port = $config['tcp_port'];
        $this->http_port = $config['http_port'];
        $this->version = $config['version'];
        $this->topic = $topic;
        $this->channel = $channel;
        $this->connect();
    }

    /**
     * @throws \Exception
     */
    public function connect()
    {
        $this->client = new TcpClient();

        // connect nsq server
        Log::debug('connecting to nsq server '.$this->getTcpAddress());
        $this->client->connect($this->broadcast_address, $this->tcp_port);
        Log::debug('nsq server connected '.$this->getTcpAddress());

        // send magic to nsq server
        Log::debug('send magic to nsq server');
        $this->client->send(Packet::magic());

        //TODO: add auth

        // send identify params
        Log::debug('send identify params');
        $this->client->send(Packet::identify(Arr::get($this->nsqDriverConfig, 'identify')));
    }

    public function sub()
    {
        // sub nsq topic and channel
        Log::debug('sub nsq topic and channel');
        $this->client->send(Packet::sub($this->topic, $this->channel));

        $this->sendReady(Arr::get($this->nsqDriverConfig, 'options.rdy', 1));

        $this->stats = $this->getStatsFromNsqdInstance();
    }

    // tell nsq server to be ready accept {n} data
    public function sendReady(int $count): void
    {
        Log::debug("tell nsq server to be ready accept $count data");
        $this->client->send(Packet::rdy($count));
    }

    public function getTcpAddress(): string
    {
        return $this->broadcast_address.':'.$this->tcp_port;
    }

    public function getHttpAddress(): string
    {
        return $this->broadcast_address.':'.$this->http_port;
    }

    public function getDepthMessages(): int
    {
        return isset($this->stats['depth']) ? $this->stats['depth'] : 0;
    }

    public function hasDepthMessages(): bool
    {
        return isset($this->stats['depth']) && $this->stats['depth'] > 0;
    }

    public function subDepthMessage(): void
    {
        if (!$this->hasDepthMessages()) {
            return;
        }
        $this->stats['depth'] = $this->stats['depth'] - 1;
    }

    public function getStatsFromNsqdInstance(): array
    {
        $factory = new Factory();
        $httpAddress = $this->getHttpAddress();
        $socket = $factory->createClient($httpAddress);
        $path = sprintf('/stats?format=json&topic=%s&channel=%s', urlencode($this->topic), urlencode($this->channel));
        $socket->write("GET $path HTTP/1.1\r\nHost: $httpAddress\r\nUser-Agent: Laravel-nsq driver \r\n\r\n");
        $payload = $socket->read(8192);
        $body = substr($payload, strrpos($payload, "\r\n"));
        $result = json_decode($body, true);
        if (!$result) {
            throw new LookupException("Error to connect nsq instance url $httpAddress");
        }
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
        $socket->close();
        return $channelStats;
    }
}