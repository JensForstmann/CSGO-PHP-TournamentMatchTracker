<?php

namespace TMT;

/**
 * The OvalOffice holds all matches that are going on and waits for new matches to start.
 */
class OvalOffice {
    /**
     * @var TcpServer
     */
    private $tcp_server;

    /**
     * @var Match[]
     */
    private $matches = [];

    /**
     * Constructs the oval office.
     * In fact, that's only a tcp server waiting for requests.
     */
    public function __construct() {
        try {
            $this->tcp_server = new TcpServer('0.0.0.0', 9999);
        } catch (\Exception $e) {
            Log::error($e->getMessage());
            exit(100);
        }
        //$this->matches[] = new Match(1337, '192.168.11.138', 27016, 'pass', 'esl5on5.cfg', '', 'Team G-Hole', 'BieberLAN', 'de_dust2');
    }

    /**
     * Main program loop.
     *
     * Every loop it will check the tcp server for new incoming requests and create for every request a match object.
     * Furthermore at all match objects the doWork method is called to observe the match and react to it.
     */
    public function loop() {
        while (true) {
            // check new matches to start
            $buffers = $this->tcp_server->getAllBuffers();
            foreach ($buffers as $client => $buffer) {
/* EXAMPLE
{
    "id": 1337,
    "ip": "192.168.11.138",
    "port": 27016,
    "rcon": "pass",
    "mode": "esl5on5.cfg",
    "url": "",
    "teamname1": "Team G-Hole",
    "teamname2": "BieberLAN",
    "map": "de_dust2"
}
*/
                $m = json_decode($buffer);
                if (isset($m->id)
                    && isset($m->ip)
                    && isset($m->port)
                    && isset($m->rcon)
                    && isset($m->mode)
                    && isset($m->url)
                    && isset($m->teamname1)
                    && isset($m->teamname2)
                    && isset($m->map)
                ) {
                    $this->tcp_server->disconnectClient($client);
                    Log::info('Create new match with the following data: ' . json_encode($m));
                    try {
                        $this->matches[$m->id] = new Match($m->id, $m->ip, $m->port, $m->rcon, $m->mode, $m->url, $m->teamname1, $m->teamname2, $m->map);
                    } catch (\Exception $e) {
                        Log::warning('Error creating match: ' . $e->getMessage());
                    }
                }
            }

            // watch all matches
            foreach ($this->matches as $id => $match) {
                try {
                    $match->doWork();
                } catch (\Exception $e) {
                    Log::warning('while doing work for match ' . $id . ' an exception was thrown: ' . $e->getMessage());
                }
                if ($match->getMatchStatus() === Match::END) {
                    unset($this->matches[$id]);
                }
            }

            // be nice to the cpu
            usleep(100 * 1000); // 100 ms
        }
    }
}
