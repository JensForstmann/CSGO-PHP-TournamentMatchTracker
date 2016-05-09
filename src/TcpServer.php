<?php

namespace TMT;

/**
 * Class TcpServer
 * Manages the clients and their incoming traffic.
 */
class TcpServer {
    /**
     * @var resource
     */
    private $server_socket;

    /**
     * @var resource[]
     */
    private $sockets = [];

    /**
     * @var string[]
     */
    private $buffers = [];

    /**
     * @param string $ip Listening ip.
     * @param int $port Listening port.
     * @throws \Exception On error (either from socket creation of from set blocking mode attempt).
     */
    public function __construct($ip, $port) {
        $this->server_socket = stream_socket_server('tcp://' . $ip . ':' . $port, $errno, $errstr, STREAM_SERVER_BIND | STREAM_SERVER_LISTEN);
        if ($this->server_socket === false) {
            throw new \Exception('starting the server socket occured an error: (' . $errno . ') ' . $errstr);
        }
        $this->sockets[stream_socket_get_name($this->server_socket, false)] = $this->server_socket;
        $this->setBlocking($this->server_socket, false, false);
    }

    /**
     * Sets blocking mode for a socket.
     * @param resource $socket
     * @param bool $bool If set to true -> blocking, false -> non-blocking.
     * @param bool $client_socket Set to true if $socket is a client socket (not the server socket).
     * @throws \Exception If stream_set_blocking returns not true.
     */
    private function setBlocking($socket, $bool, $client_socket = true) {
        $mode = (int) $bool;
        if (!stream_set_blocking($socket, $mode)) {
            throw new \Exception('set blocking to ' . $mode . ' failed for socket ' . stream_socket_get_name($socket, $client_socket));
        }
    }

    /**
     * Returns false on stream_select error.
     * But in normal case it returns an associative array with 'ip:port' strings as keys and all ingoing tcp traffic as value.
     * @return bool|\string[]
     */
    public function getAllBuffers() {
        $read = $this->sockets;
        $null = null;
        $ret = stream_select($read, $null, $null, 0);

        if ($ret === false) {
            Log::warning('stream select error');
            return false;
        }

        foreach ($read as $read_socket) {
            if ($read_socket === $this->server_socket) {
                while (($new_socket = @stream_socket_accept($this->server_socket, 0)) !== false) {
                    try {
                        $this->setBlocking($new_socket, false);
                        $client_ip_port = stream_socket_get_name($new_socket, true);
                        Log::debug('new client connection: ' . $client_ip_port);
                        $this->sockets[$client_ip_port] = $new_socket;
                        $this->buffers[$client_ip_port] = '';
                    } catch (\Exception $e) {
                        Log::warning($e->getMessage());
                        Log::warning('drop this client connection');
                    }
                }
            } else {
                $client_ip_port = stream_socket_get_name($read_socket, true);

                if (feof($read_socket) === true) {
                    Log::debug('client disconnects (feof): ' . $client_ip_port);
                    $this->disconnectClient($client_ip_port);
                } else {
                    do {
                        $new_data = @fread($read_socket, 8192);
                        if (is_string($new_data)) {
                            Log::debug('new client data: ' . $new_data);
                            $this->buffers[$client_ip_port] .= $new_data;
                        }
                    } while (is_string($new_data) && $new_data !== '');
                }
            }
        }

        return $this->buffers;
    }

    /**
     * Cleans up the internal arrays (socket array and buffer) for the ip port combination.
     * @param string $client_ip_port Just a 'ip:port' string.
     */
    public function disconnectClient($client_ip_port) {
        fclose($this->sockets[$client_ip_port]);
        unset($this->sockets[$client_ip_port]);
        unset($this->buffers[$client_ip_port]);
    }

    /**
     * Writes data to the socket.
     * @param string $client_ip_port Just the 'ip:port' string of the client.
     * @param string $data Data that will be written to the socket.
     */
    public function writeToSocket($client_ip_port, $data) {
        if ($client_ip_port === $this->server_socket) {
            Log::warning('writing to server socket is not allowed, abort');
            return;
        }

        $max_errors = 10;
        $client = $this->sockets[$client_ip_port];

        for ($written = 0, $errors = 0; $written < strlen($data) && $errors < $max_errors; $written += $fwrite) {
            $fwrite = @fwrite($client, substr($data, $written));
            if ($fwrite === 0 || $fwrite === false) {
                $errors++;
                Log::warning('API TCP: writing to socket failed');
            } else {
                $errors = 0;
            }
        }
    }
}
