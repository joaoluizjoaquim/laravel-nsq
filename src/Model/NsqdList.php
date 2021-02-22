<?php

namespace Jiyis\Nsq\Model;

class NsqdList
{

    private $instances;

    /**
     * NsqdList constructor.
     */
    public function __construct()
    {
        $this->instances = [];
    }

    public function add(Nsqd $nsqd): void
    {
        $this->instances[] = $nsqd;
    }

    public function orderByDepthMessagesDesc()
    {
        usort($this->instances, function($instanceA, $instanceB) {
            return $instanceA->getTotalMessages() < $instanceB->getTotalMessages();
        });
        return $this->instances;
    }


    public function isWithoutMessages(): bool
    {
        foreach($this->instances as $instance) {
            if($instance->hasMessagesToRead()) {
                return false;
            }
        }
        return true;
    }

    public function size(): int
    {
        return array_reduce($this->instances, function ($total, $instance) {
            $total += $instance->getTotalMessages();
            return $total;
        }, $size = 0);
    }

    public function close(): void
    {
        foreach ($this->instances as $client) {
            $client->close();
        }
    }
}