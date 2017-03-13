<?php

namespace Demo;

use Amp\Deferred;
use Amp\Failure;
use Exception;
use function Amp\cancel;
use function Amp\onReadable;
use function Amp\onWritable;
use function Amp\Socket\listen;

class Server {
    private $callback;
    private $clients;

    public function __construct(callable $callback) {
        $this->callback = $callback;
    }

    public function bind(string $addr) {
        $serverSocket = listen($addr);
        $this->setupClientAcceptor($serverSocket);
    }

    private function setupClientAcceptor($serverSocket) {
        onReadable($serverSocket, function () use ($serverSocket) {
            if (!$clientSocket = @\stream_socket_accept($serverSocket, 0, $peerName)) {
                return;
            }

            $this->loadClient($clientSocket, $peerName);
        });
    }

    private function loadClient($clientSocket, $peerName) {
        $portStartPos = strrpos($peerName, ":");
        $ip = substr($peerName, 0, $portStartPos);
        $port = (int) substr($peerName, $portStartPos + 1);

        print "Accepting client from {$ip} on port {$port}" . PHP_EOL;

        $this->clients[$peerName] = $clientSocket;
        \stream_set_blocking($clientSocket, false);

        $this->setupClientReadWatcher($clientSocket, $ip, $port);
    }

    private function setupClientReadWatcher($clientSocket, $ip, $port) {
        onReadable($clientSocket, function ($watcherId, $clientSocket) use ($ip, $port) {
            static $buffer = "";

            $data = \fread($clientSocket, 8192);

            if ($data === "" || $data === false) {
                if (!\is_resource($clientSocket) || @\feof($clientSocket)) {
                    cancel($watcherId);

                    print "Client closed connection from {$ip} on port {$port}" . PHP_EOL;

                    $this->unloadClient($ip, $port);
                }

                return;
            }

            // Buffer data and emit line by line once we have a line feed

            $buffer .= $data;

            while (($pos = \strpos($buffer, "\n")) !== false) {
                $line = \trim(\substr($buffer, 0, $pos));
                $buffer = substr($buffer, $pos + 1);

                $callback = $this->callback;
                $callback($line, $ip, $port);
            }
        });
    }

    private function unloadClient(string $ip, int $port) {
        unset($this->clients[$ip . ":" . $port]);
    }

    public function sendTo(string $ip, int $port, string $payload) {
        if (!isset($this->clients[$ip . ":" . $port])) {
            return new Failure(new Exception("Client already disconnected."));
        }

        $client = $this->clients[$ip . ":" . $port];

        $deferred = new Deferred;

        onWritable($client, function ($watcherId) use ($deferred, $client, $payload) {
            static $buffer = null;

            if ($buffer === null) {
                $buffer = $payload;
            }

            $bytes = fwrite($client, $payload);

            if ($bytes === false) {
                cancel($watcherId);

                return;
            }

            $buffer = substr($buffer, $bytes);

            if (!strlen($buffer)) {
                $deferred->succeed();
                cancel($watcherId);
            }
        });

        return $deferred->promise();
    }
}