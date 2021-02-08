<?php

namespace Jiyis\Nsq\Queue;

use Illuminate\Contracts\Queue\Queue as QueueContract;
use Illuminate\Queue\Queue;
use Illuminate\Support\Str;
use Illuminate\Support\Facades\Config;
use Illuminate\Support\Facades\Log;
use Jiyis\Nsq\Adapter\NsqClientManager;
use Jiyis\Nsq\Exception\FrameException;
use Jiyis\Nsq\Exception\PublishException;
use Jiyis\Nsq\Message\Packet;
use Jiyis\Nsq\Message\Unpack;
use Jiyis\Nsq\Model\Nsqd;
use Jiyis\Nsq\Model\NsqdList;
use Jiyis\Nsq\Queue\Jobs\NsqJob;
use Socket\Raw\Exception as SocketRawException;
use Exception;

class NsqQueue extends Queue implements QueueContract
{

    const PUB_ONE = 1;
    const PUB_TWO = 2;
    const PUB_QUORUM = 5;

    /**
     * nsq tcp client pool
     * @var NsqClientManager
     */
    protected $clientManager;


    /**
     * current nsq tcp client
     * @var Nsqd
     */
    protected $currentClient;

    /**
     * nsq consumer job
     * @var
     */
    protected $consumerJob;

    /**
     * The expiration time of a job.
     *
     * @var int|null
     */
    protected $retryAfter = 60;

    /**
     * nsq pub number
     * @var
     */
    protected $pubSuccessCount;


    /**
     * NsqQueue constructor.
     * @param NsqClientManager $client
     * @param $consumerJob
     * @param int $retryAfter
     */
    public function __construct(NsqClientManager $client, $consumerJob, $retryAfter = 60)
    {
        $this->clientManager = $client;
        $this->consumerJob = $consumerJob;
        $this->retryAfter = $retryAfter;
    }

    /**
     * @param null $queueName
     * @return int
     */
    public function size($queueName = null): int
    {
        return $this->getNsqdList()->size();
    }

    /**
     * Push a new job onto the queue.
     *
     * @param  string $job
     * @param  mixed $data
     * @param  string $queue
     * @return mixed
     */
    public function push($job, $data = '', $queue = null)
    {
        return $this->pushRaw($this->createNsqPayload($job, $data), $queue);
    }

    /**
     * Push a raw payload onto the queue.
     *
     * @param  string $payload
     * @param  string $queue
     * @param  array $options
     * @return mixed
     */
    public function pushRaw($payload, $queue = null, array $options = [])
    {
        $payload = json_decode($payload, true);
        $data = $payload['data'];
        $job = unserialize($payload['job']);
        if (empty($data)) {
            $data = unserialize($payload['job'])->payload;
        }

        return $this->publishTo(Config::get('nsq.options.cl', 1))->publish($job->topic, json_encode($data));
    }

    /**
     * @param \DateTime|int $delay
     * @param string $job
     * @param string $data
     * @param null $queue
     * @return mixed
     */
    public function later($delay, $job, $data = '', $queue = null)
    {
        return $this->pushRaw($this->createNsqPayload($job, $data), $queue, ['delay' => $this->secondsUntil($delay)]);
    }

    /**
     * Pop the next job off of the queue.
     *
     * @param null $queue
     * @return \Illuminate\Contracts\Queue\Job|NsqJob|null
     */
    public function pop($queue = null): ?NsqJob
    {
        try {
            $response = null;
            foreach ($this->getNsqdList()->orderByDepthMessagesDesc() as $client) {
                $nsqdInstance = $client->getTcpAddress();
                $this->currentClient = $client;

                if (!$this->currentClient->hasMessagesToRead()) {
                    Log::debug("$nsqdInstance has no message in depth stats cache, continue");
                    continue;
                }

                $data = $this->currentClient->receive();

                // if no message return null
                if (!$data) {
                    Log::debug("$nsqdInstance has no message, continue");
                    continue;
                }

                // unpack message
                $frame = Unpack::getFrame($data);
                if (Unpack::isHeartbeat($frame)) {
                    Log::debug("$nsqdInstance: sending heartbeat ".json_encode($frame));
                    $this->currentClient->send(Packet::nop());
                } elseif (Unpack::isOk($frame)) {
                    Log::debug("$nsqdInstance frame ok ".json_encode($frame));
                } elseif (Unpack::isError($frame)) {
                    Log::error("$nsqdInstance error in frame received ".json_encode($frame));
                } elseif (Unpack::isMessage($frame)) {
                    $rawBody = $this->adapterNsqPayload($this->consumerJob, $frame);
                    return new NsqJob($this->container, $this, $rawBody, $queue);
                } else {
                    Log::debug($nsqdInstance.' not recognized frame. '.json_encode($frame));
                }
            }
            $this->refreshClient();
        } catch (SocketRawException $e) {
            if (Str::contains($e->getMessage(), ['Broken pipe', 'Socket operation failed'])) {
                throw new SocketRawException("Lost connection. Source error message: ".$e->getMessage());
            }
            throw $e;
        }
        
        return $response;
    }

    /**
     * refresh nsq client form nsqlookupd result
     */
    private function refreshClient()
    {
        if (!$this->getNsqdList()->isWithoutMessages()) {
            return;
        }
        $this->getNsqdList()->close();
        $this->clientManager->connect();
        Log::debug("refresh nsq client success.");
    }

    /**
     * pub to nsqd
     * @param $job
     * @param $data
     * @return string
     */
    protected function createNsqPayload($job, $data)
    {
        return json_encode([
            'data' => $data,
            'job'  => serialize($job)
        ]);
    }

    /**
     * adapter nsq queue job body type
     * @param $job
     * @param array $data
     * @return string
     * @throws \Exception
     */
    protected function adapterNsqPayload($job, array $data)
    {
        $message = $data['message'];

        $payload = json_encode(array_merge(
            [
                'displayName' => $this->getDisplayName($job),
                'job'         => 'Illuminate\Queue\CallQueuedHandler@call',
                'maxTries'    => isset($job->tries) ? $job->tries : null,
                'timeout'     => isset($job->timeout) ? $job->timeout : null,
                'message'     => $message,
                'data'        => [
                    'commandName' => get_class($job),
                    'command'     => serialize(clone $job),
                ],
            ],
            [
                'attempts' => $data['attempts'],
                'id'       => $data['id'],
            ]
        ));

        if (JSON_ERROR_NONE !== json_last_error()) {
            throw new \Exception(
                'Unable to JSON encode payload. Error code: ' . json_last_error()
            );
        }

        return $payload;
    }

    /**
     * Get the connection for the queue.
     * @return mixed
     */
    public function getCurrentClient(): Nsqd
    {
        return $this->currentClient;
    }

    private function getNsqdList(): NsqdList
    {
        return $this->clientManager->getNsqdList();
    }

    private function getProducerPool()
    {
        return $this->clientManager->getProducerPool();
    }

    /**
     * Define nsqd hosts to publish to
     *
     * We'll remember these hosts for any subsequent publish() call, so you
     * only need to call this once to publish
     *
     * @param int $cl      Consistency level - basically how many `nsqd`
     *                     nodes we need to respond to consider a publish successful
     *                     The default value is nsqphp::PUB_ONE
     *
     * @throws \InvalidArgumentException If bad CL provided
     * @throws \InvalidArgumentException If we cannot achieve the desired CL
     *      (eg: if you ask for PUB_TWO but only supply one node)
     *
     * @return $this
     */
    public function publishTo($cl = self::PUB_ONE)
    {

        $producerPoolSize = count($this->getProducerPool());

        switch ($cl) {
            case self::PUB_ONE:
            case self::PUB_TWO:
                $this->pubSuccessCount = $cl;
                break;

            case self::PUB_QUORUM:
                $this->pubSuccessCount = ceil($producerPoolSize / 2) + 1;
                break;

            default:
                throw new FrameException('Invalid consistency level');
                break;
        }

        if ($this->pubSuccessCount > $producerPoolSize) {
            throw new PublishException(
                sprintf('Cannot achieve desired consistency level with %s nodes', $producerPoolSize)
            );
        }

        return $this;
    }

    /**
     * Publish message
     *
     * @param string $topic     A valid topic name: [.a-zA-Z0-9_-] and 1 < length < 32
     * @param string|array $msg array: multiple messages
     * @param int $tries        Retry times
     *
     * @throws PublishException If we don't get "OK" back from server
     *      (for the specified number of hosts - as directed by `publishTo`)
     *
     * @return $this
     */
    public function publish($topic, $msg, $tries = 1)
    {
        $producerPool = $this->getProducerPool();
        // pick a random
        shuffle($producerPool);

        $success = 0;
        $errors = [];
        foreach ($producerPool as $producer) {
            try {
                for ($run = 0; $run < $tries; $run++) {
                    try {
                        $payload = is_array($msg) ? Packet::mpub($topic, $msg) : Packet::pub($topic, $msg);
                        $producer->send($payload);
                        $frame = Unpack::getFrame($producer->receive());

                        while (Unpack::isHeartbeat($frame)) {
                            $producer->send(Packet::nop());
                            $frame = Unpack::getFrame($producer->receive());
                        }

                        if (Unpack::isOK($frame)) {
                            $success++;
                        } else {
                            $errors[] = $frame['error'];
                        }

                        break;
                    } catch (\Throwable $e) {
                        if ($run >= $tries) {
                            throw $e;
                        }

                        $producer->reconnect();
                    }
                }
            } catch (\Throwable $e) {
                $errors[] = $e->getMessage();
            }

            if ($success >= $this->pubSuccessCount) {
                break;
            }
        }

        if ($success < $this->pubSuccessCount) {
            throw new PublishException(
                sprintf('Failed to publish message; required %s for success, achieved %s. Errors were: %s', $this->pubSuccessCount, $success, implode(', ', $errors))
            );
        }

        return $this;
    }


}
