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

    public function connect(string $host, string $port): void
    {
        $this->socket = $this->factory->createClient("tcp://$host:$port");
    }

    public function isConnected(): bool
    {
        $code = $this->socket->getOption(SOL_SOCKET, SO_ERROR);
        return $code == 0;
    }

    public function send(string $payload)
    {
        $this->socket->write($payload);
    }

    public function recv()
    {
        Log::debug("Reading response...");
        $result = $this->socket->read(16384);
        return $result;
    }

    public function close()
    {
        Log::debug("Closing tcp socket");
        $this->socket->close();
        Log::debug("Tcp socket closed");
    }
}
