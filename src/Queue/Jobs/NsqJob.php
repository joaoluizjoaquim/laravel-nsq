<?php

namespace Jiyis\Nsq\Queue\Jobs;

use Illuminate\Container\Container;
use Illuminate\Contracts\Queue\Job as JobContract;
use Illuminate\Queue\Jobs\Job;
use Illuminate\Support\Arr;
use Illuminate\Support\Facades\Config;
use Illuminate\Support\Facades\Log;
use Jiyis\Nsq\Queue\NsqQueue;
use Jiyis\Nsq\Message\Packet;
use Jiyis\Nsq\Model\Nsqd;

class NsqJob extends Job implements JobContract
{

    /**
     * The nsq queue instance.
     *
     * @var NsqQueue
     */
    protected $nsqQueue;

    /**
     * The nsq raw job payload.
     *
     * @var string
     */
    protected $job;

    /**
     * The nsq top and channel name.
     *
     * @var string
     */
    protected $queue;

    /**
     * payload decode (job property)
     * @var array
     */
    protected $decoded;


    /**
     * NsqJob constructor.
     * @param Container $container
     * @param NsqQueue $nsqQueue
     * @param $job
     * @param $queue
     */
    public function __construct(
        Container $container,
        NsqQueue $nsqQueue,
        $job,
        $queue
    )
    {
        $this->container = $container;
        $this->job = $job;
        $this->nsqQueue = $nsqQueue;
        $this->queue = $queue;
        $this->decoded = $this->payload();
    }

    /**
     * Get the number of times the job has been attempted.
     *
     * @return int
     */
    public function attempts()
    {
        return Arr::get($this->decoded, 'attempts');
    }

    /**
     * Get the raw body string for the job.
     *
     * @return string
     */
    public function getRawBody()
    {
        return $this->job;
    }

    /**
     * Delete the job from the queue.
     * success handle job
     * @return void
     */
    public function delete()
    {
        parent::delete();

        $client = $this->getCurrentClient();
        // sending to client set success
        $client->send(Packet::fin($this->getJobId()));
        Log::debug("Process job success, send fin to nsq server.");

        $client->subDepthMessage();
    }


    /**
     * Re-queue a message
     * @param int $delay
     * @return mixed|void
     */
    public function release($delay = 0)
    {
        parent::release($delay);

        // re push job to nsq queue
        $this->getCurrentClient()->send(Packet::req($this->getJobId(), $delay));
    }

    /**
     * Get the job identifier.
     *
     * @return string
     */
    public function getJobId()
    {
        return Arr::get($this->decoded, 'id');
    }

    /**
     * get nsq client pool
     * @return NsqQueue|string
     */
    public function getQueue(): NsqQueue
    {
        return $this->nsqQueue;
    }

    public function getCurrentClient(): Nsqd
    {
        return $this->getQueue()->getCurrentClient();
    }

    /**
     * get nsq body message
     * @return mixed
     */
    public function getMessage()
    {
        return Arr::get($this->decoded, 'message');
    }

    public function maxTries()
    {
        return isset($this->payload()['maxTries']) ? $this->payload()['maxTries'] : 0;
    }
}
