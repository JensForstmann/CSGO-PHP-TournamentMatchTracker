<?php

namespace TMT;

/**
 * The UDP log receiver is a simple udp socket that listens to incoming messages. The source game will send events
 * to this socket.
 */
class UdpLogReceiver {
    /**
     * UDP listening socket
     * @var resource
     */
    private $fp;

    /**
     * Constructs the UDP Log Receiver.
     *
     * Simply creates a listening udp socket.
     *
     * @param string $ip
     * @param int $port
     * @throws \Exception
     */
    public function __construct($ip, $port) {
        $fp = stream_socket_server('udp://' . $ip . ':' . $port, $errno, $errstr, STREAM_SERVER_BIND);

        if ($fp === false) {
            throw new \Exception('Error creating ' . __CLASS__ . ': ' . $errstr . ' (' . $errno . ')');
        }

        if (stream_set_timeout($fp, 0) === false) {
            throw new \Exception('Error setting stream timeout for ' . __CLASS__);
        }

        $this->fp = $fp;
    }

    /**
     * Returns an array of all incoming packets.
     * @return string[] key = origin ip:port, value = payload data
     */
    public function getNewPackets() {
        $empty = [];
        $packets = [];

        do {
            $read = [$this->fp];
            if (stream_select($read, $empty, $empty, 0) === false) {
                Log::warning('Stream select error within ' . __CLASS__);
            }
            if (count($read) === 1) {
                $packet = stream_socket_recvfrom($this->fp, 8192, 0, $peer);
                if (!isset($packets[$peer])) {
                    $packets[$peer] = [];
                }
                $packets[$peer][] = $packet;
            }
        } while (count($read) === 1);

        return $packets;
    }
}
