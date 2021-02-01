<?php

namespace Jiyis\Nsq\Adapter;

use Illuminate\Support\Arr;
use Illuminate\Support\Facades\Config;
use Illuminate\Support\Facades\Log;
use Jiyis\Nsq\Model\NsqLookupd;
use Jiyis\Nsq\Model\NsqdList;
use Jiyis\Nsq\Monitor\Producer;

class NsqClientManager
{

    /**
     * nsq config
     * @var array
     */
    protected $config;

    /**
     * nsq tcp sub client pool
     * @var
     */
    protected $nsqdList;

    /**
     * nsq tcp pub client pool
     * @var
     */
    protected $producerPool = [];

    /**
     * nsq tcp pub client
     * @var
     */
    protected $client;

    /**
     * nsq consumer job
     * @var
     */
    protected $consumerJob = null;

    /**
     * nsq topic name
     * @var
     */
    protected $topic = null;

    /**
     * nsq channel name
     * @var
     */
    protected $channel = null;

    /**
     * connect time
     * @var
     */
    protected $connectTime;


    /**
     * NsqClientManager constructor.
     * @param array $config
     * @throws \Exception
     */
    public function __construct(array $config)
    {
        $this->config = $config;
        $this->connect();
    }

    /**
     * reflect job, get topic and channel
     * @throws \ReflectionException
     */
    public function reflectionJob()
    {
        $this->consumerJob = app(Config::get('consumer_job'));
        $reflect = new \ReflectionClass($this->consumerJob);

        if (!$reflect->hasProperty('topic') || !$reflect->hasProperty('channel')) {
            throw new \Exception("Topic and channel fields are required in " . Config::get('consumer_job') . " class");
        }
        $this->topic = $reflect->getProperty('topic')->getValue($this->consumerJob);
        $this->channel = $reflect->getProperty('channel')->getValue($this->consumerJob);
    }

    /**
     * Create a new clustered nsq connection.
     * @throws \Exception
     * @return mixed
     */
    public function connect()
    {
        $this->connectTime = time();
        $lookup = new NsqLookupd($this->config);
        /**
         * if consumer_job not null, then the command is sub
         */
        if (Config::get('consumer_job')) {
            $this->reflectionJob();
            $this->nsqdList = $lookup->lookup($this->topic, $this->channel);
            return;
        }
        $hosts = Arr::get($this->config, 'connection.nsqd_url', ['127.0.0.1:4150']);
        foreach ($hosts as $item) {
            $producer = new Producer($item, $this->config);
            $this->producerPool[$item] = $producer;
        }
    }

    /**
     * get nsq pub client pool
     * @return mixed
     */
    public function getProducerPool()
    {
        return $this->producerPool;
    }

    /**
     * get nsq sub client pool
     * @return mixed
     */
    public function getNsqdList(): NsqdList
    {
        return $this->nsqdList;
    }

    /**
     * get nsq job
     * @return mixed
     */
    public function getJob()
    {
        return $this->consumerJob;
    }

    /**
     * get nsq job
     * @return mixed
     */
    public function getConfig()
    {
        return $this->config;
    }

    /**
     * get connect time
     * @return int
     */
    public function getConnectTime()
    {
        return $this->connectTime;
    }
}
