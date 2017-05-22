<?php

namespace TMT;

/**
 * The TournamentMatchTracker object holds all matches that are going on and waits for matches to start/stop.
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
     * Constructs the tournament match tracker.
     * In fact, that's only a tcp server waiting for requests.
     */
    public function __construct() {
        $this->arg['udp-port'] = 9999;
        $this->arg['udp-ip'] = '0.0.0.0';
        $this->arg['udp-log-ip'] = gethostbyname(gethostname());
        $this->arg['tcp-port'] = 9999;
        $this->arg['tcp-ip'] = '0.0.0.0';
        $this->arg['token'] = '';

        $this->parseCommandLineParameters();

        Log::info('started tournament match tracker (tmt) with the following parameters:');
        Log::info('  --udp-port ' . $this->arg['udp-port']);
        Log::info('  --udp-ip ' . $this->arg['udp-ip']);
        Log::info('  --udp-log-ip ' . $this->arg['udp-log-ip']);
        Log::info('  --tcp-port ' . $this->arg['tcp-port']);
        Log::info('  --tcp-ip ' . $this->arg['tcp-ip']);
        Log::info('  --token ' . $this->arg['token']);

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
     * Check a json string (from the tcp buffer) for a match abort request.
     * If request is valid, match will be aborted.
     * @param string $json_string
     * @param string $client Just the 'ip:port' string of the client.
     * @return bool True if all required data was available and has been set so the tcp client can be disconnected.
     */
    private function checkJsonForMatchAbort($json_string, $client) {
        $o = json_decode($json_string);
        if (true
            && isset($o->token)
            && isset($o->match_id)
            && isset($o->abort_match)
        ) {
            if ($o->token !== $this->arg['token']) {
                Log::warning('wrong token given in match abort data (' . $o->token . '), ignore the match abort');
                $this->tcp_server->writeToSocket($client, 'auth error');
            } else if ($o->abort_match !== true) {
                Log::warning('received a match abort request with abort_match field not set to true, ignore it');
                $this->tcp_server->writeToSocket($client, 'abort_match field not true');
            } else {
                $match = $this->getMatchById($o->match_id);
                if ($match !== false) {
                    Log::info('abort match (id ' . $o->match_id . ') on request');
                    $match->abort();
                    unset($this->matches[$o->match_id]);
                } else {
                    Log::info('abort match (id ' . $o->match_id . ') requested, but no match found with this id');
                }
            }
            return true;
        }
        return false;
    }

    /**
     * Check a json string (from the tcp buffer) for a action request.
     * If request is valid, do stuff (depending on the action).
     * @param string $json_string
     * @param string $client Just the 'ip:port' string of the client.
     * @return bool True if all required data was available and action was executed so the tcp client can be disconnected.
     */
    private function checkJsonForActionRequest($json_string, $client) {
        $o = json_decode($json_string);
        if (true
            && isset($o->token)
            && isset($o->action)
        ) {
            if ($o->token !== $this->arg['token']) {
                Log::warning('wrong token given in action request (' . $o->token . '), ignore the action request');
                $this->tcp_server->writeToSocket($client, 'auth error');
            } else {
                switch ($o->action) {
                    case 'status_request':
                        $status_request_json = $this->getStatusRequestJson();
                        Log::debug('send this status_request json: ' . $status_request_json);
                        $this->tcp_server->writeToSocket($client, $status_request_json);
                        break;
                    default:
                        Log::warning('wrong action given in action request (' . $o->action . '), ignore the action request');
                        $this->tcp_server->writeToSocket($client, 'action error');
                }
            }
            return true;
        }
        return false;
    }

    /**
     * Returns a json encoded string with all status information for the status_request.
     * @return string
     */
    private function getStatusRequestJson() {
        $o['match_count'] = count($this->matches);
        $o['matches'] = [];
        foreach ($this->matches as $match) {
            $mo['id'] = $match->getMatchData()->getMatchId();
            $mo['status'] = $match->getMatchStatus();
            $mo['map'] = $match->getMapElection()->getMatchMap();
            $mo['lastcontact_timestamp'] = $match->getLastContact();
            $mo['lastcontact_seconds'] = time() - $match->getLastContact();

            $mo['team1']['id'] = $match->getTeamId('CT');
            $mo['team1']['name'] = $match->getTeamName('CT');
            $mo['team1']['score'] = $match->getScore('CT');

            $mo['team2']['id'] = $match->getTeamId('T');
            $mo['team2']['name'] = $match->getTeamName('T');
            $mo['team2']['score'] = $match->getScore('T');
            $o['matches'][] = $mo;
        }

        return (string) json_encode($o);
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
                if ($this->checkJsonForActionRequest($buffer, $client) === true) {
                    $this->tcp_server->disconnectClient($client);
                } else if ($this->checkJsonForMatchAbort($buffer, $client) === true) {
                    $this->tcp_server->disconnectClient($client);
                } else if ($match_data->setFieldsFromJsonString($buffer) === true) {
                    if ($match_data->getToken() !== $this->arg['token']) {
                        Log::warning('wrong token given in match init data (' . $match_data->getToken() . '), ignore the match init');
                        $this->tcp_server->writeToSocket($client, 'auth error');
                    } else {
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
                            $this->tcp_server->writeToSocket($client, 'Error creating match: ' . $e->getMessage());
                        }
                    }
                    $this->tcp_server->disconnectClient($client);
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
                        $match->setLastContact();
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
