<?php

namespace TMT;

/**
 * The OvalOffice holds all matches that are going on and waits for new matches to start.
 */
class TournamentMatchTracker {
    /**
     * @var TcpServer
     */
    private $tcp_server;

    private $udp_log_receiver;

    /**
     * @var Match[]
     */
    private $matches = [];

    /**
     * Command line arguments
     * @var array
     */
    private $arg = [];

    /**
     * Constructs the oval office.
     * In fact, that's only a tcp server waiting for requests.
     */
    public function __construct() {
        $this->arg['udp-port'] = 9999;
        $this->arg['udp-ip'] = getHostByName(getHostName());
        $this->arg['tcp-port'] = 9999;
        $this->arg['tcp-ip'] = '0.0.0.0';

        $this->parseCommandLineParameters();

        try {
            $this->tcp_server = new TcpServer($this->arg['tcp-ip'], $this->arg['tcp-port']);
            $this->udp_log_receiver = new UdpLogReceiver($this->arg['udp-ip'], $this->arg['udp-port']);
        } catch (\Exception $e) {
            Log::error($e->getMessage());
            exit(100);
        }
    }

    private function parseCommandLineParameters() {
        // ./tmt.php --udp-port 1234 --udp-ip x.y.z.z --tcp-port 6789 --tcp-ip t.u.i.p
        global $argv, $argc;

        $key = null;
        for ($n = 1; $n < $argc; $n++) {
            $arg = $argv[$n];
            if (substr($arg, 0, 2) === '--' && $key === null) {
                $key = substr($arg, 2);
            } else if (substr($arg, 0, 2) !== '--' && is_string($key)) {
                $this->arg[$key] = $arg;
                $key = null;
            } else {
                Log::error('error in command line at ' . $arg);
                Log::error('full command line: ' . implode(' ' , $argv));
            }
        }
    }

    /**
     * Main program loop.
     *
     * Every loop it will check the tcp server for new incoming requests and create for every request a match object.
     * Furthermore at all match objects the doWork method is called to observe the match and react to it.
     */
    public function loop() {
        while (true) {
            // check incoming tcp traffic to start new matches
            $buffers = $this->tcp_server->getAllBuffers();
            foreach ($buffers as $client => $buffer) {
                $match_data = new MatchData();
                if ($match_data->setFieldsFromJsonString($buffer) === true) {
                    $this->tcp_server->disconnectClient($client);
                    try {
                        Log::debug('create new match with the following data: ' . $match_data->getJsonString());
                        $this->matches[] = new Match($match_data, $this->arg['udp-ip'] . ':' . $this->arg['udp-port']);
                    } catch (\Exception $e) {
                        Log::warning('Error creating match: ' . $e->getMessage());
                    }
                }
            }

            // check incoming log traffic (udp)
            $log_packets = $this->udp_log_receiver->getNewPackets();

            // watch all matches
            foreach ($this->matches as $key => $match) {
                try {
                    $match_ip_port = $match->getMatchData()->getIpPort();
                    $match_log_packets = [];
                    if (isset($log_packets[$match_ip_port])) {
                        $match_log_packets = $log_packets[$match_ip_port];
                    }
                    $match->doWork($match_log_packets);
                } catch (\Exception $e) {
                    Log::warning('while doing work for match ' . $match->getMatchId() . ' an exception was thrown: ' . $e->getMessage());
                }
                if ($match->getMatchStatus() === Match::END) {
                    unset($this->matches[$key]);
                }
            }

            // execute jobs
            Tasker::doWork();

            // be nice to the cpu
            usleep(100 * 1000); // 100 ms
        }
    }
}
