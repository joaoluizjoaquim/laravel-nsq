<?php

namespace Jiyis\Nsq\Model;

class NsqdList
{

    private $instances = [];

    public function add(Nsqd $nsqd): void
    {
        $this->instances[] = $nsqd;
    }

    public function getInstanceWithLargestDepthMessage(): Nsqd
    {
        $largestInstance = $this->instances[0];
        foreach($this->instances as $instance) {
            if ($instance->getTotalMessages() > $largestInstance->getTotalMessages()) {
                $largestInstance = $instance;
            }
        }
        return $largestInstance;
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

    public function getTotalMessages(): int
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