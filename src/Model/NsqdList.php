<?php

namespace Jiyis\Nsq\Model;


use Illuminate\Support\Arr;
use Illuminate\Support\Facades\Log;

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

    public function add(Nsqd $nsqd)
    {
        $this->instances[] = $nsqd;
    }

    public function orderByDepthMessagesDesc()
    {
        usort($this->instances, function($instanceA, $instanceB) {
            return $instanceB->getDepthMessages() - $instanceA->getDepthMessages();
        });
        return $this->instances;
    }


    public function isWithoutMessages(): bool
    {
        foreach($this->instances as $instance) {
            if($instance->hasDepthMessages()) {
                return false;
            }
        }
        return true;
    }

    public function size(): int
    {
        return array_reduce($this->instances, function ($total, $instance) {
            $total += $instance->getDepthMessages();
            return $total;
        }, $size = 0);
    }

    public function close()
    {
        foreach ($this->instances as $client) {
            $client->close();
        }
    }
}