<?php

namespace TMT;

/**
 * The UDP log receiver is a simple udp socket that listens to incoming messages. The source game will send events
 * to this socket.
 */
class UdpLogReceiver {
    /**
     * @var string
     */
    private $gameserver_ip_port;

    /**
     * @var resource
     */
    private $fp;

    /**
     * @var int
     */
    private $port;

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
        $this->gameserver_ip_port = $ip . ':' . $port;

        $fp = stream_socket_server('udp://0.0.0.0:0', $errno, $errstr, STREAM_SERVER_BIND);

        if ($fp === false) {
            throw new \Exception('Error creating ' . __CLASS__ . ': ' . $errstr . ' (' . $errno . ')');
        }

        if (stream_set_timeout($fp, 0) === false) {
            throw new \Exception('Error setting stream timeout for ' . __CLASS__);
        }

        $this->port = (int) explode(':', stream_socket_get_name($fp, false))[1];

        $this->fp = $fp;
    }

    /**
     * Returns the port of the udp socket.
     * @return int
     */
    public function getPort() {
        return $this->port;
    }

    /**
     * Returns an array of all incoming packets. Drops all packets which are not from the gameserver (checks if
     * origin ip and port matches to the config setting).
     * @return array
     */
    public function getNewPackets() {
        $empty = [];
        $packets = [];

        do {
            $read = [$this->fp];
            if (stream_select($read, $empty, $empty, 0) === false) {
                Log::warning('Stream select error within the ' . __CLASS__);
            }
            if (count($read) === 1) {
                $packet = stream_socket_recvfrom($this->fp, 8192, 0, $peer); // @todo: check if it still works if two packets arrive at the same time
                if ($peer === $this->gameserver_ip_port) {
                    $packets[] = $packet;
                } else {
                    Log::notice('Ignoring packet from ' . $peer . ' because it is not the gamesever (' . $this->gameserver_ip_port . ')!');
                }
            }
        } while (count($read) === 1);

        return $packets;
    }
}
