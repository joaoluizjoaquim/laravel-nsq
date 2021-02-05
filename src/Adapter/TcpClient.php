<?php

namespace Jiyis\Nsq\Adapter;

use Illuminate\Support\Facades\Log;
use Socket\Raw\Factory;

class TcpClient
{

    private $socket;

    private $connected = false;

    private $config = [];

    /**
     * TcpClient constructor.
     */
    public function __construct()
    {
        $this->factory = new Factory();
    }

    public function connect(string $host, string $port) {
        $this->socket = $this->factory->createClient("tcp://$host:$port");
        $this->connected = true;
    }

    public function send(string $payload) {
        $this->socket->write($payload);
    }

    public function recv() {
        Log::debug("Reading response...");
        $result = $this->socket->read(8192);
        return $result;
    }

    public function close() {
        Log::debug("Closing tcp socket");
        $this->socket->close();
        Log::debug("Tcp socket closed");
    }
}
