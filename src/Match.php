<?php

namespace TMT;

/**
 * Match class.
 * Connects to a gameserver, sets some settings, enables udp logging and observes a match.
 * @todo check if overtime is enabled or if a draw is possible
 */
class Match {
    /**
     * @var MatchData
     */
    private $match_data;

    /**
     * @var MapElection
     */
    private $map_election;

    /**
     * Current match status. (One of the constants below.)
     * @var string
     */
    private $match_status = self::MAP_ELECTION;
    const MAP_ELECTION = 'MAP_ELECTION'; // Important: Names and values of constants must be the same for defined() method
    const MAP_CHANGE = 'MAP_CHANGE';
    const WARMUP = 'WARMUP';
    const KNIFE = 'KNIFE';
    const AFTER_KNIFE = 'AFTER_KNIFE';
    const MATCH = 'MATCH';
    const END = 'END';
    const PAUSE = 'PAUSE';

    private $allowed_commands = [
        'anytime' => ['help', 'fullhelp'],
        self::MAP_ELECTION => [], // will be set in the constructor
        self::MAP_CHANGE => [],
        self::WARMUP => ['ready', 'rdy', 'unready'],
        self::KNIFE => [],
        self::AFTER_KNIFE => ['stay', 'switch', 'swap'],
        self::MATCH => ['pause'],
        self::END => [],
        self::PAUSE => ['ready', 'rdy', 'unready']
    ];

    /**
     * Ready status of both teams.
     * @var bool[]
     */
    private $ready_status = ['CT' => false, 'T' => false];

    /**
     * Current score.
     * @var int[]
     */
    private $score = ['CT' => 0, 'T' => 0];

    /**
     * Winning team of the knife round.
     * @var string
     */
    private $knife_winner;

    /**
     * Max rounds to calculate halftime and match end.
     * @var int
     */
    private $maxrounds = 30; // @todo read that from rcon after loading config

    /**
     * Max rounds in overtime to calculate halftime and match end.
     * @var int
     */
    private $ot_maxrounds = 6; // @todo read that from rcon after loading config

    /**
     * Time of the last periodic message.
     * @var int
     */
    private $last_periodic_message = 0;

    /**
     * Interval of the periodic messages.
     * @var int
     */
    private $periodic_message_interval = 30;

    /**
     * Are the sides switched?
     * @var bool
     */
    private $switched_sides = false;

    /**
     * Ip:port of the udp log receiver
     * @var string
     */
    private $udp_log_ip_port;

    /**
     * Constructs a match to observe and control it.
     * @param MatchData $match_data
     * @param string $udp_log_ip_port IP:port of the udp log receiver.
     * @throws \Exception
     */
    public function __construct($match_data, $udp_log_ip_port) {
        $this->match_data = $match_data;

        $this->udp_log_ip_port = $udp_log_ip_port;

        $this->rcon = new Rcon($match_data->getIp(), $match_data->getPort(), $match_data->getRcon(), $this);

        $this->map_election = new MapElection($match_data->getPickmode(), $match_data->getMapPool(), $this);
        $this->allowed_commands[self::MAP_ELECTION] = $this->map_election->getAvailableCommands();

        $this->rcon('mp_logdetail 0');
        $this->rcon('sv_logecho 0');
        $this->rcon('sv_logfile 0');
        $this->rcon('logaddress_add ' . $udp_log_ip_port);
        $this->rcon('log on');
        $this->rcon('mp_teamname_1 "' . $this->getTeamName('CT') . '"');
        $this->rcon('mp_teamname_2 "' . $this->getTeamName('T') . '"');
        foreach ($match_data->getRconInit() as $rcon_init) {
            $this->rcon($rcon_init);
        }
        $this->rcon('changelevel ' . $match_data->getDefaultMap()); // execute this after rcon_init because changelevel could cause rcon connection loss for a little moment

        $this->log('match created');
    }

    /**
     * Returns the team name.
     * @param string $team 'CT' or 'T'
     * @return string
     */
    public function getTeamName($team) {
        if ($this->switched_sides) {
            $team = $this->getOtherTeam($team);
        }
        $name = '';
        switch ($team) {
            case 'CT': $name = $this->match_data->getTeam1Name(); break;
            case 'T': $name = $this->match_data->getTeam2Name(); break;
        }
        return str_replace(['"', ';'], '', $name);
    }

    /**
     * Returns the team id.
     * @param string $team 'CT' or 'T'
     * @return int
     */
    public function getTeamId($team) {
        if ($this->switched_sides) {
            $team = $this->getOtherTeam($team);
        }
        switch ($team) {
            case 'CT': return $this->match_data->getTeam1Id();
            case 'T': return $this->match_data->getTeam2Id();
        }
    }

    /**
     * Returns the score of one team.
     * @param string $team 'CT' or 'T'
     * @return int
     */
    public function getScore($team) {
        switch ($team) {
            case 'CT': return $this->score['CT'];
            case 'T': return $this->score['T'];
        }
    }

    /**
     * Returns the current match status.
     * @return string
     */
    public function getMatchStatus() {
        return $this->match_status;
    }

    /**
     * Sets the current match status.
     * @param string $match_status One of the class constants.
     * @throws \Exception
     */
    public function setMatchStatus($match_status) {
        if (!defined('self::' . $match_status)) {
            Log::error($match_status . ' is no supported match status, set it to ' . self::WARMUP . ' instead');
            $match_status = self::WARMUP;
        }
        $this->match_status = $match_status;
    }

    /**
     * Returns the match data object.
     * @return MatchData
     */
    public function getMatchData() {
        return $this->match_data;
    }

    /**
     * Returns the map election object.
     * @return MapElection
     */
    public function getMapElection() {
        return $this->map_election;
    }

    /**
     * Returns the opposite of 'CT' or 'T'.
     * @param $team 'CT' or 'T'
     * @return string 'T' or 'CT'
     */
    public function getOtherTeam($team) {
        switch ($team) {
            case 'CT': return 'T';
            case 'T': return 'CT';
        }
    }

    /**
     * Says messages depending on the current match status and map vote status.
     */
    public function sayPeriodicMessage() {
        // @todo: move this rcon to a better location?!
        $this->rcon('mp_warmup_pausetimer 1');

        $this->last_periodic_message = time();

        switch ($this->match_status) {
            case self::MAP_ELECTION:
                $this->map_election->sayPeriodicMessage();
                break;
            case self::WARMUP:
            case self::PAUSE:
                $this->say('WAITING FOR BOTH TEAMS TO !ready UP!');
                $single_ready_team = $this->getSingleReadyTeam();
                if ($single_ready_team !== false) {
                    $this->say($this->getTeamPrint($single_ready_team) . ' IS READY');
                    $this->say('USE !unready TO UNREADY');
                }
                break;
            case self::KNIFE:
                $this->say('KNIFE FOR SIDE!');
                $this->say('KNIFE FOR SIDE!');
                $this->say('KNIFE FOR SIDE!');
                break;
            case self::AFTER_KNIFE:
                $this->say($this->getTeamPrint($this->knife_winner) . ' WON THE KNIFE ROUND!');
                $this->say('USE !stay OR !switch TO START THE MATCH!');
                break;
            case self::MATCH:
                break;
            case self::END:
                $this->say('MATCH FINISHED!');
                $this->say('  ' . $this->getTeamPrint('CT') . ': ' . $this->score['CT']);
                $this->say('  ' . $this->getTeamPrint('T') . ': ' . $this->score['T']);
                $this->say('SCORE WILL BE SUBMITTED AUTOMATICALLY!');
                break;
        }
    }

    /**
     * Returns an often needed printable String for one team, example 'Name of the team (CT)' or 'Other team name (T)'
     * @param string $team
     * @return string
     */
    public function getTeamPrint($team) {
        return $this->getTeamName($team) . ' (' . $team . ')';
    }

    /**
     * Returns 'CT' or 'T' depending which team is ready or false if no team is ready.
     * @return bool|string
     */
    private function getSingleReadyTeam() {
        if ($this->ready_status['CT'] === true) {
            return 'CT';
        }
        if ($this->ready_status['T'] === true) {
            return 'T';
        }
        return false;
    }

    /**
     * Is triggered by any text message that is chatted ingame.
     * @param string $name The nickname of the player.
     * @param string $steam_id The steam id of the player.
     * @param string $team The team of the player.
     * @param string $message The complete message.
     */
    private function onSay($name, $steam_id, $team, $message) {
        if ($team === 'TERRORIST') {
            $team = 'T';
        }

        if ($steam_id !== 'Console') {
            $this->log('SAY | ' . $name . '<' . $team . '><' . $steam_id . '>: ' . $message);
        }

        if ($message[0] !== '!' && $message[0] !== '.') { // message is no command
            return;
        }

        $parts = explode(' ', substr($message, 1));
        if (!isset($parts[1])) {
            $parts[1] = '';
        }

        $allowed_commands = array_merge($this->allowed_commands['anytime'], $this->allowed_commands[$this->match_status]);
        if (!in_array($parts[0], $allowed_commands)) { // command is unkown or not allowed
            $this->commandHelp();
            return;
        }

        switch (strtolower($parts[0])) {
            case 'ready':
            case 'rdy':
                $this->commandReady($team);
                break;
            case 'unready':
                $this->commandUnready($team);
                break;
            case 'pause':
                $this->commandPause($team);
                break;
            case 'help':
                $this->commandHelp();
                break;
            case 'fullhelp':
                $this->commandFullhelp();
                break;
            case 'map':
            case 'vote':
            case 'pick':
                $this->map_election->wish($team, $parts[1]);
                break;
            case 'veto':
            case 'ban':
                $this->map_election->veto($team, $parts[1]);
                break;
            case 'stay':
                $this->commandStay($team);
                break;
            case 'switch':
            case 'swap':
                $this->commandSwitch($team);
                break;
            default:
                $this->say('THIS COMMAND IS NOT IMPLEMENTED YET!');
                break;
        }
    }

    /**
     * Will be triggered on any round end.
     * After the knife round the knife round winner will be set (to select side).
     * If the match is live, it will be checked if there is a side switch (haltftime) or the winner is fixed.
     * @param int $ct_score
     * @param int $t_score
     */
    private function onRoundEnd($ct_score, $t_score) {
        $this->log('round ends with ' . $this->getTeamPrint('CT') . '(' . $ct_score . ') and ' . $this->getTeamPrint('T') . '(' . $t_score . ')');
        $this->score['CT'] = $ct_score;
        $this->score['T'] = $t_score;
        if ($this->match_status === self::KNIFE) {
            $this->match_status = self::AFTER_KNIFE;
            $this->knife_winner = $ct_score === 1 ? 'CT' : 'T';
            $this->log($this->getTeamPrint($this->knife_winner) . ' wins the knife round');
            $this->rcon('mp_pause_match');
            $this->sayPeriodicMessage();
        } else if ($this->match_status === self::MATCH) {
            // report livescorse
            $this->report([
                'match_id' => $this->getMatchData()->getMatchId(),
                'type' => 'livescore',
                'team1id' => $this->getTeamId('CT'),
                'team1score' => $this->score['CT'],
                'team2id' => $this->getTeamId('T'),
                'team2score' => $this->score['T']
            ], false);
            if ($this->isHalftime($ct_score + $t_score)) {
                $this->switchTeamInternals();
            } else if ($this->isMatchEnd($ct_score, $t_score)) {
                $this->endMatch();
            }
        }
    }

    /**
     * Ends the match. Call the report method to transfer the result. Do some cleaning stuff.
     */
    private function endMatch() {
        $this->log('match end');
        $this->match_status = self::END;
        $this->sayPeriodicMessage();

        $this->report([
            'match_id' => $this->getMatchData()->getMatchId(),
            'type' => 'end',
            'team1id' => $this->getTeamId('CT'),
            'team1score' => $this->score['CT'],
            'team2id' => $this->getTeamId('T'),
            'team2score' => $this->score['T']
        ]);

        $seconds_until_server_cleanup = 180;

        Tasker::add($seconds_until_server_cleanup, function() {
            $this->log('execute rcon_end commands');
            foreach ($this->match_data->getRconEnd() as $rcon_end) {
                $this->rcon($rcon_end);
            }
            $this->disableUDPLogging();
        });

        switch(strtolower($this->match_data->getMatchEnd())) {
            case 'kick':
                Tasker::add($seconds_until_server_cleanup, function() {
                    $command = '';
                    foreach (explode("\n", $this->rcon('status')) as $line) {
                        if (preg_match('~^# +(\\d+) +(\\d+) +"(.*)" +([STEAM_:0-9]+) +([0-9:]+) +([0-9]+) +([0-9]+) +(.+) +([0-9]+) +([0-9.:]+)$~', $line, $matches)) {
                            $command .= 'kickid ' . $matches[1] . ';';
                        }
                    }
                    $this->log('kick all');
                    $this->rcon($command);
                });
                break;
            case 'quit':
                Tasker::add($seconds_until_server_cleanup, function() {
                    $this->log('quit server');
                    $this->rcon('quit');
                });
                break;
            case 'none':
                break;
            default:
                $this->log('match end action is not supported:' . $this->match_data->getMatchEnd());
        }
    }

    /**
     * Abort the message. This means to disable the udp logging. (And write a log message.)
     */
    public function abort() {
        $this->log('abort match');
        $this->disableUDPLogging();
    }

    /**
     * Disable the udp logging.
     */
    private function disableUDPLogging() {
        $this->log('disable udp logging');
        $this->rcon('logaddress_del ' . $this->udp_log_ip_port);
    }

    /**
     * Do all the stuff that has to be done.
     * This function should be called frequently.
     * @param string[] Array with udp log packets
     */
    public function doWork($packets) {
        // react to things happened on the server
        foreach ($packets as $packet) {
            $this->decodePacket($packet);
        }

        // call periodic message method if it is time to do so
        if (time() > $this->last_periodic_message + $this->periodic_message_interval) {
            $this->sayPeriodicMessage();
        }
    }

    /**
     * Sets a team as ready.
     * If both teams are ready, the match will be either started or unpaused.
     * @param string $team
     */
    private function commandReady($team) {
        if ($this->ready_status[$team] !== true) {
            $this->log($this->getTeamPrint($team) . ' is ready');
        }

        $say = $this->ready_status[$team] === false;

        $this->ready_status[$team] = true;
        if ($this->ready_status['CT'] === true && $this->ready_status['T'] === true) {
            $this->ready_status = ['CT' => false, 'T' => false];
            if ($this->match_status === self::WARMUP) {
                $this->startKniferound();
            } else {
                $this->log('unpause match');
                $this->match_status = self::MATCH;
                $this->say('MATCH IS LIVE AGAIN!');
                $this->say('MATCH IS LIVE AGAIN!');
                $this->say('MATCH IS LIVE AGAIN!');
                $this->rcon('mp_unpause_match');
            }
        } else if ($say) {
            $this->sayPeriodicMessage();
        }
    }

    /**
     * Sets a team as unready.
     * @param string $team
     */
    private function commandUnready($team) {
        if ($this->ready_status[$team] !== false) {
            $this->log($this->getTeamPrint($team) . ' is unready');
        }
        $this->ready_status[$team] = false;
    }

    /**
     * Pauses the match (only if the match is live).
     * @param string $team
     */
    private function commandPause($team) {
        $this->match_status = self::PAUSE;
        $this->log($this->getTeamPrint($team) . ' pauses the match');
        $this->say($this->getTeamPrint($team) . ' PAUSES THE MATCH');
        $this->say('MATCH WILL BE PAUSED (NEXT FREEZETIME)');
        $this->say('TYPE !ready TO CONTINUE');
        $this->rcon('mp_pause_match');
    }

    /**
     * Displays all commands which are available for the current match status.
     */
    private function commandHelp() {
        $commands = array_merge($this->allowed_commands['anytime'], $this->allowed_commands[$this->match_status]);
        $this->say('YOU CAN USE THE FOLLOWING COMMANDS:');
        $this->say('!' . implode(', !', $commands));
        $this->say('USE !fullhelp TO GET ALL COMMANDS!');;
    }

    /**
     * Displays all available commands.
     */
    private function commandFullhelp() {
        $commands = [];
        array_walk_recursive($this->allowed_commands, function($value, $key) use (&$commands) {
            $commands[] = $value;
        });
        $this->say('ALL COMMANDS:');
        $this->say('!' . implode(', !', $commands));
    }

    /**
     * Teams will not be switched. Go ahead and start the match
     * @param string $team The team which executes the command. Will be checked if it was the winning team of the knife round.
     */
    private function commandStay($team) {
        if ($team === $this->knife_winner) {
            $this->log($this->getTeamPrint($team) . ' wants to stay');
            $this->say($this->getTeamPrint($team) . ' WANTS TO STAY');
            $this->startMatch();
        }
    }

    /**
     * Switches the teams.
     * @param string $team The team which executes the command. Will be checked if it was the winning team of the knife round.
     */
    private function commandSwitch($team) {
        if ($team === $this->knife_winner) {
            $this->log($this->getTeamPrint($team) . ' wants to switch sides');
            $this->say($this->getTeamPrint($team) . ' WANTS TO SWITCH SIDES');
            $this->switchTeamInternals();
            $this->rcon('mp_swapteams');
            $this->startMatch();
        }
    }

    /**
     * Starts the knife round.
     */
    private function startKniferound() {
        $this->log('start knife round');
        $this->match_status = self::KNIFE;
        foreach ($this->match_data->getRconConfig() as $rcon_config) {
            $this->rcon($rcon_config);
        }
        $this->rcon('mp_warmup_end');
        $this->rcon('mp_restartgame 3');
        $this->say('--------------------------------------');
        $this->say('DO NOT FORGET TO RECORD!');
        $this->sayPeriodicMessage();
    }

    /**
     * Starts the match.
     */
    private function startMatch() {
        $this->log('start match');
        $this->match_status = self::MATCH;
        $this->score = ['CT' => 0, 'T' => 0];
        $this->rcon('mp_unpause_match');
        foreach ($this->match_data->getRconConfig() as $rcon_config) {
            $this->rcon($rcon_config);
        }
        $this->rcon('mp_restartgame 10');
        $this->report([
            'match_id' => $this->match_data->getMatchId(),
            'type' => 'start'
        ]);
        $this->say('THE MATCH IS LIVE AFTER THE NEXT RESTART!');
        $this->say('GL & HF EVERYBODY');
        Tasker::add(11, function() {
            $this->say('MATCH IS LIVE!');
            $this->say('MATCH IS LIVE!');
            $this->say('MATCH IS LIVE!');
        });
    }

    /**
     * Says something on the gameserver.
     * @param string $message
     */
    public function say($message) {
        $this->rcon('say [BL-BOT] ' . str_replace(';', '', $message));
    }

    /**
     * Executes a rcon command on the gameserver.
     * @param string $rcon
     * @return string rcon return output
     */
    public function rcon($rcon) {
        $cmd = explode(' ', trim($rcon))[0];
        if (!in_array($cmd, ['say', 'mp_warmup_pausetimer'])) {
            $this->log('rcon executed: ' . $rcon, true);
        }
        return $this->rcon->rcon($rcon);
    }

    /**
     * Writes something into the logfile, together with the match id.
     * @param string $message
     * @param bool $debug
     */
    public function log($message, $debug = false) {
        $message = 'MATCH ' . $this->match_data->getMatchId() . ' | ' . $message;
        if ($debug === true) {
            Log::debug($message);
        } else {
            Log::info($message);
        }
    }

    /**
     * Method to switch internal fields like team names, score and map wish status.
     */
    private function switchTeamInternals() {
        $this->log('switch teams');

        $this->switched_sides = !$this->switched_sides;

        $this->score = ['CT' => $this->score['T'], 'T' => $this->score['CT']];
        $this->ready_status = ['CT' => $this->ready_status['T'], 'T' => $this->ready_status['CT']];
    }

    /**
     * Returns true if the teams are switched (at halftimes).
     * @param int $rounds_played
     * @return bool
     */
    private function isHalftime($rounds_played) {
        $ot_halftime = $this->maxrounds + (max(1, $this->getOvertimeNumber($rounds_played)) - 0.5) * $this->ot_maxrounds;
        return $rounds_played === $this->maxrounds / 2 || $rounds_played === (int)$ot_halftime;
    }

    /**
     * Returns true if the winner is fixed.
     * @param int $ct_score
     * @param int $t_score
     * @return bool
     */
    private function isMatchEnd($ct_score, $t_score) {
        $score_to_win = $this->maxrounds / 2 + $this->getOvertimeNumber($ct_score + $t_score) * $this->ot_maxrounds / 2 + 1;
        return $ct_score === (int)$score_to_win || $t_score === (int)$score_to_win;
    }

    /**
     * Returns the current overtime number.
     * Here are some examples with maxrounds=30 and ot_maxrounds:
     *      30 => 0, 31 => 1, 40 => 1, 41 => 2, 50 => 2, 51 => 3
     * @param int $rounds_played
     * @return int
     */
    private function getOvertimeNumber($rounds_played) {
        return max(0, ceil(($rounds_played - $this->maxrounds) / $this->ot_maxrounds));
    }

    /**
     * Posts data to the match data url (HTTP POST).
     * @param array $post_data
     * @param bool $retry_on_error If true the report will be retried automatically after 180 seconds.
     */
    public function report(array $post_data, $retry_on_error = true) {
        if (empty($this->match_data->getUrl())) {
            $this->log('report url is empty, so no reporting at all', true);
            return;
        }

        $this->log('report: ' . json_encode($post_data));

        $options = ['http' => [
            'header'  => "Content-type: application/x-www-form-urlencoded\r\n",
            'method'  => 'POST',
            'timeout' => 10,
            'content' => http_build_query($post_data)]];

        $context = stream_context_create($options);

        $ret = file_get_contents($this->match_data->getUrl(), false, $context);

        if ($ret === false) {
            $this->log('report failed');
            if ($retry_on_error) {
                $this->log('retry report in 180 seconds');
                Tasker::add(180, [$this, __METHOD__], [$post_data]); // @todo add a counter to limit the number of attempts, maybe increase time between two tries
            }
        } else {
            $this->log('report returns: ' . trim($ret));
        }
    }

    /**
     * Decodes a packet from the udp logging and reacts to it (calls other internal methods).
     * @param string $packet Complete raw udp packet payload.
     */
    private function decodePacket($packet) {
        // \xFF\xFF\xFF\xFFRL 11/03/2015 - 11:51:27:
        $head_pattern = '~^\\xFF\\xFF\\xFF\\xFFRL \\d\\d/\\d\\d/\\d\\d\\d\\d - \\d\\d:\\d\\d:\\d\\d: ';

        // \x0A\x00
        $tail_pattern = '\\x0A\\x00$~';

        // "Name<PID><STEAM_ID><TEAM>"
        $player_pattern = '"(.*)<(\\d+)><(.*)><(|Unassigned|CT|TERRORIST|Console)>"';

        if (preg_match($head_pattern . 'Team "(CT|TERRORIST)" triggered "([a-zA-Z_]+)" \\(CT "(\\d+)"\\) \\(T "(\\d+)"\\)' . $tail_pattern, $packet, $matches)) {
            // team triggered
            $team = $matches[1];
            $trigger_action = $matches[2];
            $ct_score = $matches[3];
            $t_score = $matches[4];

            $this->onRoundEnd((int)$ct_score, (int)$t_score);

        } else if (preg_match($head_pattern . $player_pattern . ' say(_team)? "(.*)"' . $tail_pattern, $packet, $matches)) {
            // say
            $player['name'] = $matches[1];
            $player['pid'] = $matches[2];
            $player['guid'] = $matches[3];
            $player['team'] = $matches[4];

            $team_chat = $matches[5] === '_team' ? true : false;

            $message = $matches[6];

            $this->onSay($player['name'], $player['guid'], $player['team'], $message);

        } else {
            // echo $packet . PHP_EOL;
        }
    }
}
