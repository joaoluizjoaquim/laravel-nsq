<?php

namespace Jiyis\Nsq\Model;

use Illuminate\Support\Arr;
use Illuminate\Support\Facades\Log;
use Jiyis\Nsq\Adapter\TcpClient;
use Jiyis\Nsq\Exception\IdentifyException;
use Jiyis\Nsq\Exception\LookupException;
use Jiyis\Nsq\Exception\SubscribeException;
use Jiyis\Nsq\Message\Packet;
use Jiyis\Nsq\Message\Unpack;
use Jiyis\Nsq\Monitor\AbstractMonitor;

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
        $frame = Unpack::getFrame($this->receive());
        if (!Unpack::isOk($frame)) {
            throw new IdentifyException("Something is wrong to send IDENTIFY message to " . 
                $this->getTcpAddress() . 
                ". Response: ". json_encode($frame));
        }
    }

    public function sub()
    {
        // sub nsq topic and channel
        Log::debug('sub nsq topic and channel');
        $this->client->send(Packet::sub($this->topic, $this->channel));
        $frame = Unpack::getFrame($this->receive());
        if (!Unpack::isOk($frame)) {
            throw new SubscribeException("Something is wrong to send SUB message to " . 
                $this->getTcpAddress() . 
                ". Response: ". json_encode($frame));
        }
        
        $this->rdy(Arr::get($this->nsqDriverConfig, 'options.rdy', 1));

        $this->updateStats();
    }

    // tell nsq server to be ready accept {n} data
    public function rdy(int $count): void
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

    public function getTotalMessages(): int
    {
        return isset($this->stats['depth']) ? $this->stats['depth'] : 0;
    }

    public function hasMessagesToRead(): bool
    {
        return $this->getTotalMessages() > 0;
    }

    public function subDepthMessage(): void
    {
        if (!$this->hasMessagesToRead()) {
            return;
        }
        $this->stats['depth'] = $this->stats['depth'] - 1;
    }

    public function updateStats(): void
    {
        $this->stats = $this->getStatsFromNsqdInstance();
    }

    public function getTopic(): string
    {
        return $this->topic;
    }

    public function getChannel(): string
    {
        return $this->channel;
    }

    private function getStatsFromNsqdInstance(): array
    {
        $httpAddress = $this->getHttpAddress();

        $url = sprintf('http://%s/stats?format=json&topic=%s&channel=%s', $httpAddress, urlencode($this->topic), urlencode($this->channel));
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

    public function close()
    {
        // Log::debug('send cls packet to close nsq connection');
        // $this->client->send(Packet::cls());
        // $frame = Unpack::getFrame($this->receive());
        // if (!Unpack::isCloseWait($frame)) {
        //     Log::debug("Something is wrong to send CLS message to " .
        //         $this->getTcpAddress() .
        //         ". Response: ". json_encode($frame));
        // }
        // Log::debug("closed nsq connection successfully");
        $this->client->close();
    }

    public function isConnected(): bool
    {
        return $this->client->isConnected();
    }
}