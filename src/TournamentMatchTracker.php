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

    /**
     * @var UdpLogReceiver
     */
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
        $this->arg['udp-ip'] = '0.0.0.0';
        $this->arg['udp-log-ip'] = getHostByName(getHostName());
        $this->arg['tcp-port'] = 9999;
        $this->arg['tcp-ip'] = '0.0.0.0';

        $this->parseCommandLineParameters();

        Log::info('started tournament match tracker (tmt) with the following parameters:');
        Log::info('  --udp-port ' . $this->arg['udp-port']);
        Log::info('  --udp-ip ' . $this->arg['udp-ip']);
        Log::info('  --udp-log-ip ' . $this->arg['udp-log-ip']);
        Log::info('  --tcp-port ' . $this->arg['tcp-port']);
        Log::info('  --tcp-ip ' . $this->arg['tcp-ip']);

        try {
            Log::info('starting tcp server...');
            $this->tcp_server = new TcpServer($this->arg['tcp-ip'], $this->arg['tcp-port']);
            Log::info('tcp server started');
            Log::info('starting udp log receiver...');
            $this->udp_log_receiver = new UdpLogReceiver($this->arg['udp-ip'], $this->arg['udp-port']);
            Log::info('udp log receiver started');
        } catch (\Exception $e) {
            Log::error($e->getMessage());
            exit(100);
        }
    }

    /**
     * Parse the command line parameters and set the arg property.
     */
    private function parseCommandLineParameters() {
        // ./tmt.php --udp-port 1234 --udp-ip a.b.c.d --udp-log-ip e.f.g.h --tcp-port 6789 --tcp-ip i.j.k.l
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
     * Return match by match id. Returns false if no match is found.
     * @param int $match_id
     * @return bool|Match
     */
    private function getMatchById($match_id) {
        if (isset($this->matches[$match_id])) {
            return $this->matches[$match_id];
        }
        return false;
    }

    /**
     * Return match by gameserver ip and port. Returns false if no match is found.
     * @param string $match_ip_port
     * @return bool|Match
     */
    private function getMatchByIpPort($match_ip_port) {
        foreach ($this->matches as $match) {
            if ($match->getMatchData()->getIpPort() === $match_ip_port) {
                return $match;
            }
        }
        return false;
    }

    /**
     * Main program loop.
     *
     * Every loop it will check the tcp server for new incoming requests and create for every request a match object.
     * Furthermore at all match objects the doWork method is called to observe the match and react to it.
     */
    public function loop() {
        Log::info('start working...');
        while (true) {
            // check incoming tcp traffic to start new matches
            $buffers = $this->tcp_server->getAllBuffers();
            foreach ($buffers as $client => $buffer) {
                $match_data = new MatchData();
                if ($match_data->setFieldsFromJsonString($buffer) === true) {
                    $this->tcp_server->disconnectClient($client);
                    try {
                        Log::info('create new match with the following data: ' . $match_data->getJsonString());

                        $match_id = $match_data->getMatchId();
                        $match_by_id = $this->getMatchById($match_id);
                        if ($match_by_id !== false) {
                            Log::info('match with id ' . $match_id . ' already exists, abort it first');
                            $match_by_id->abort();
                            unset($this->matches[$match_id]);
                        }

                        $match_ip_port = $match_data->getIpPort();
                        $match_by_ip_port = $this->getMatchByIpPort($match_ip_port);
                        if ($match_by_ip_port !== false) {
                            Log::info('match at server ' . $match_ip_port . ' already exists, abort it first');
                            $match_by_ip_port->abort();
                            unset($this->matches[$match_by_ip_port->getMatchData()->getMatchId()]);
                        }

                        $this->matches[$match_id] = new Match($match_data, $this->arg['udp-log-ip'] . ':' . $this->arg['udp-port']);

                        Log::info('now watching ' . count($this->matches) . ' matches');
                    } catch (\Exception $e) {
                        Log::warning('Error creating match: ' . $e->getMessage());
                    }
                }
            }

            // check incoming log traffic (udp)
            $log_packets = $this->udp_log_receiver->getNewPackets();

            // watch all matches
            foreach ($this->matches as $match_id => $match) {
                try {
                    $match_ip_port = $match->getMatchData()->getIpPort();
                    $match_log_packets = [];
                    if (isset($log_packets[$match_ip_port])) {
                        $match_log_packets = $log_packets[$match_ip_port];
                    }
                    $match->doWork($match_log_packets);
                } catch (\Exception $e) {
                    Log::warning('while doing work for match ' . $match->getMatchData()->getMatchId() . ' an exception was thrown: ' . $e->getMessage());
                }
                if ($match->getMatchStatus() === Match::END) {
                    unset($this->matches[$match_id]);
                    Log::info('now watching ' . count($this->matches) . ' matches');
                }
            }

            // execute jobs
            Tasker::doWork();

            // be nice to the cpu
            usleep(100 * 1000); // 100 ms
        }
    }
}
