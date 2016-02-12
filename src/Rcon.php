<?php

namespace TMT;

/**
 * Rcon Class for Source Games
 *
 * https://developer.valvesoftware.com/wiki/Source_RCON_Protocol
 */
class Rcon {
    /**
     * Server IP Adress
     * @var String
     */
    private $ip;
    /**
     * Server Query Port
     * @var int
     */
    private $port;
    /**
     * Rcon Password
     * @var String
     */
    private $password;
    /**
     * Connection Pointer
     * @var resource
     */
    private $fp;
    /**
     * Packet type: login request
     */
    const SERVERDATA_AUTH = 3;
    /**
     * Packet type: response from login request
     */
    const SERVERDATA_AUTH_RESPONSE = 2;
    /**
     * Packet type: command execution request
     */
    const SERVERDATA_EXECCOMMAND = 2;
    /**
     * Packet type: response from command execution
     */
    const SERVERDATA_RESPONSE_VALUE = 0;
    /**
     * @var string tcp stream buffer
     */
    private $buffer = '';
    /**
     * @var Match
     */
    private $match;


    /**
     * Connector
     * @param string $ip
     * @param int $port
     * @param string $password
     * @param Match $match
     * @throws \Exception
     */
    public function __construct($ip, $port, $password, Match $match) {
        $this->ip = $ip;
        $this->port = $port;
        $this->password = $password;
        $this->match = $match;

        $this->connect();
        $this->login();
    }

    /**
     * Connects to the query port.
     * @throws \Exception
     */
    private function connect() {
        $this->fp = stream_socket_client('tcp://' . $this->ip . ':' . $this->port, $errno, $errstr);
        if ($this->fp === false) {
            throw new \Exception("RCON connection failed: $errstr ($errno)");
        }
        if (!stream_set_timeout($this->fp, 0, 100 * 1000)) { // 100 ms
            throw new \Exception('Error setting stream timeout');
        }
    }

    /**
     * Authenticates with the password.
     * @throws \Exception
     */
    private function login() {
        $this->send($this->password, 0, self::SERVERDATA_AUTH);

        /**
         * "When the server receives an auth request, it will respond with an empty SERVERDATA_RESPONSE_VALUE, followed immediately by a SERVERDATA_AUTH_RESPONSE indicating whether authentication succeeded or failed."
         * @see https://developer.valvesoftware.com/wiki/Source_RCON_Protocol#SERVERDATA_AUTH_RESPONSE
         */
        $this->getResponse();

        if ($this->getResponse()['id'] === -1) {
            throw new \Exception('Bad RCON password');
        }
    }

    /**
     * @param string $body
     * @param int $id
     * @param int $type
     * @return bool False on error. True on success.
     */
    private function send($body, $id = 0, $type = self::SERVERDATA_EXECCOMMAND) {
        $size = strlen($body) + 10; // 4 bytes id field, 4 bytes type field, 1 null byte after data, 1 null byte packet end
        $packet = pack('l', $size) . pack('l', $id) . pack('l', $type) . $body . "\x00\x00";
        return @fwrite($this->fp, $packet) !== false;
    }

    private function getResponse() {
        for ($try = 10; $try > 0; $try--) {
            $this->buffer .= fread($this->fp, 8192);
            $packet = $this->decodePacket();
            if (is_array($packet)) {
                return $packet;
            }
            usleep(100 * 1000); // 100 ms
        }
        return false;
    }

    private function decodePacket() {
        if (strlen($this->buffer) < 12) {
            return false; // not enough data
        }

        list(, $size, $id, $type) = unpack('l3', substr($this->buffer, 0, 12));

        if ($size > strlen($this->buffer) + 4) { // 4 bytes of the size field
            return false; // not enough data
        }

        $body = substr($this->buffer, 12, $size - 10); // 12 = start of body, 10 = 4 bytes id + 4 bytes type + 2  null bytes

        $this->buffer = substr($this->buffer, $size + 4); // cut off the decoded packet

        return ['body' => $body, 'type' => $type, 'id' => $id];
    }

    public function rcon($command) {
        for ($try = 10; $try > 0; $try--) {
            if ($this->send($command) === false) {
                try {
                    $this->match->log('RCON: connection lost while sending command ' . $command);
                    usleep(1000 * 1000); // 1 s
                    $this->match->log('RCON: reconnect...');
                    $this->connect();
                    $this->match->log('RCON: relogin...');
                    $this->login();
                    if ($try > 1) {
                        $this->match->log('RCON: resend command...');
                    }
                } catch (\Exception $e) {
                    $this->match->log('Failed: ' . $e->getMessage());
                }
            } else {
                $try = -1; // sending was successful
            }
        }

        if ($try === 0) {
            $this->match->log('RCON: too much tries for sending command ' . $command);
            $this->match->log('RCON: return empty string');
            return '';
        }

        /**
         * Multi packet response trick!
         * Only if we receive a packet with id === -20 we know that the previous packet was the last one
         * of a (possibly) multi packet reponse.
         * @see https://developer.valvesoftware.com/wiki/Source_RCON_Protocol#Multiple-packet_Responses
         */
        $this->send('', -20);

        $answer = '';
        for ($try = 10; $try > 0; $try--) {
            $packet = $this->getResponse();
            if ($packet === false) {
                try {
                    $this->match->log('RCON: connection lost while getting answer from command ' . $command);
                    $this->match->log('RCON: reconnect...');
                    $this->connect();
                    $this->match->log('RCON: relogin...');
                    $this->login();
                    $this->match->log('RCON: return empty string');
                    return '';
                } catch (\Exception $e) {
                    $this->match->log('Failed: ' . $e->getMessage());
                }
            } else if ($packet['id'] === -20) {
                return $answer;
            } else {
                $answer .= $packet['body'];
            }
        }
        $this->match->log('RCON: too much tries for getting answer from command ' . $command);
        $this->match->log('RCON: return empty string');
        return '';
    }
}
