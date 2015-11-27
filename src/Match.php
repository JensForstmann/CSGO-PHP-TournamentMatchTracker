<?php

namespace TMT;

/**
 * Match class.
 * Connects to a gameserver, sets some settings, enables udp logging and observes a match.
 */
class Match {
    /**
     * @var Rcon
     */
    private $rcon;

    /**
     * Match id, is used for logging purposes and to submit the result.
     * @var int
     */
    private $id;

    /**
     * Game server ip address.
     * @var string
     */
    private $ip;

    /**
     * Game server port.
     * @var int
     */
    private $port;

    /**
     * The url for updating the tournament system.
     * @var string
     */
    private $url;

    /**
     * The default map.
     * @var string
     */
    private $default_map;

    /**
     * Current match status. (One of the constants below.)
     * @var string
     */
    private $match_status;
    const WARMUP = 'WARMUP';
    const KNIFE = 'KNIFE';
    const AFTER_KNIFE = 'AFTER_KNIFE';
    const MATCH = 'MATCH';
    const END = 'END';
    const PAUSE = 'PAUSE';

    /**
     * Ready status of both teams.
     * @var bool[]
     */
    private $ready_status = ['CT' => false, 'TERRORIST' => false];

    /**
     * Map vote status of both teams.
     * @var string[]
     */
    private $map_status = ['CT' => '', 'TERRORIST' => ''];

    /**
     * Both team names.
     * @var string[]
     */
    private $teamname = ['CT' => '', 'TERRORIST' => ''];

    /**
     * Current score.
     * @var int[]
     */
    private $score = ['CT' => 0, 'TERRORIST' => 0];

    /**
     * Winning team of the knife round.
     * @var string
     */
    private $knife_winner;

    /**
     * Max rounds to calculate halftime and match end.
     * @var int
     */
    private $maxrounds = 30;

    /**
     * Max rounds in overtime to calculate halftime and match end.
     * @var int
     */
    private $ot_maxrounds = 10;

    /**
     * All available maps that can be voted.
     * @var string[]
     */
    private $map_pool = ['de_dust2', 'de_inferno', 'de_train', 'de_mirage', 'de_cache', 'de_cobblestone', 'de_overpass'];

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
     * Constructs a match to observe and control it.
     * @param string $udp_log_ip_port IP:port of the udp log receiver.
     * @param string[] $map_pool All allowed maps for agreement or banning.
     * @param string $default_map The default map.
     * @param int $match_id Match id, is used for logging purposes and to submit the result.
     * @param int $team1_id The id of the first team.
     * @param string $team1_name The name of the first team.
     * @param int $team2_id THe id of the second team.
     * @param string $team2_name The name of the second team.
     * @param string $ip The ip address of the gameserver.
     * @param int $port The port of the gameserver.
     * @param string $rcon The rcon password of the gameserver.
     * @param string $password The gameserver join password that will be set.
     * @param string $config The config file to load.
     * @param string $pickmode One of: 'bo1', 'bo1random', 'agree'
     * @param string $url The url to call after match end to submit the result.
     * @param string $match_end One of: 'kick', 'quit', 'none'
     */
    public function __construct($udp_log_ip_port, $map_pool, $default_map, $match_id, $team1_id, $team1_name, $team2_id, $team2_name, $ip, $port, $rcon, $password, $config, $pickmode, $url, $match_end) {
        // @todo set all internal fields
        $this->match_id = $match_id;
        $this->teamname = ['CT' => $team1_name, 'TERRORIST' => $team2_name];
        $this->default_map = $default_map;
        $this->config = $config;
        $this->url = $url;
        $this->match_status = self::WARMUP;
        $this->ip = $ip;
        $this->port = $port;

        $this->rcon = new Rcon($ip, $port, $rcon);

        $this->rcon('mp_logdetail 0');
        $this->rcon('sv_logecho 0');
        $this->rcon('sv_logfile 0');
        $this->rcon('logaddress_delall');
        $this->rcon('logaddress_add ' . $udp_log_ip_port);
        $this->rcon('log on');
        $this->rcon('mp_teamname_1 ' . $this->teamname['CT']); // @todo check for security issues
        $this->rcon('mp_teamname_2 ' .  $this->teamname['TERRORIST']); // @todo check for security issues
        $this->rcon('changelevel ' . $default_map);

        $this->log('match created');
    }

    /**
     * Returns the current match status.
     * @return string
     */
    public function getMatchStatus() {
        return $this->match_status;
    }

    /**
     * Returns ip:port.
     * @return string
     */
    public function getIpPort() {
        return $this->ip . ':' . $this->port;
    }

    /**
     * Returns match id.
     * @return int
     */
    public function getMatchId() {
        return $this->match_id;
    }

    /**
     * Says messages depending on the current match status and map vote status.
     */
    private function sayPeriodicMessage() {
        // @todo: move this rcon to a better location?!
        $this->rcon('mp_warmup_pausetimer 1');

        $this->last_periodic_message = time();

        switch ($this->match_status) {
            case self::WARMUP:
                $this->say('USE !map TO CHANGE MAP (BOTH TEAMS HAVE TO)');
                // No break! We want the PAUSED block as well!
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
                break;
            case self::AFTER_KNIFE:
                $this->say($this->getTeamPrint($this->knife_winner) . ' WON THE KNIFE ROUND!');
                $this->say('USE !stay OR !switch TO START THE MATCH!');
                break;
            case self::MATCH:
                break;
            case self::END:
                $leading_team = $this->getLeadingTeam();
                $this->say('MATCH FINISHED! WINNER: ' . $this->getTeamPrint($leading_team));
                $this->say('  ' . $this->getTeamPrint('CT') . ': ' . $this->score['CT']);
                $this->say('  ' . $this->getTeamPrint('TERRORIST') . ': ' . $this->score['TERRORIST']);
                $this->say('SCORE WILL BE SUBMITTED AUTOMATICALLY!');
                break;
        }

        $map_wished = false;
        foreach (['CT', 'TERRORIST'] as $team) {
            if ($this->map_status[$team] !== '') {
                $map_wished = true;
                $this->say($this->getTeamPrint($team) . ' WANTS TO CHANGE MAP TO ' . $this->map_status[$team]);
                $this->say('TO CONFIRM TYPE: !map ' . $this->map_status[$team]);
            }
        }
        if ($map_wished === true) {
            $this->say('TO REVOKE A MAP WISH TYPE: !map');
        }
    }

    /**
     * Returns an often needed printable String for one team:
     *      Name of the team (CT)
     *      Other team name (TERRORRIST)
     * @param string $team
     * @return string
     */
    private function getTeamPrint($team) {
        return $this->teamname[$team] . ' (' . $team . ')';
    }

    /**
     * Returns 'CT' or 'TERRORIST' depending which team is ready or false if no team is ready.
     * @return bool|string
     */
    private function getSingleReadyTeam() {
        if ($this->ready_status['CT'] === true) {
            return 'CT';
        }
        if ($this->ready_status['TERRORIST'] === true) {
            return 'TERRORIST';
        }
        return false;
    }

    /**
     * Returns 'CT' or 'TERRORIST' depending which team is leading or false if it's even.
     * @return bool|string
     */
    private function getLeadingTeam() {
        if ($this->score['CT'] > $this->score['TERRORIST']) {
            return 'CT';
        }
        if ($this->score['TERRORIST'] > $this->score['CT']) {
            return 'TERRORIST';
        }
        return false;
    }

    /**
     * Is triggered by any text message that is chatted ingame.
     * @param string $name The nickname of the player.
     * @param string $steam_id The steam id of the player.
     * @param string $team The teeam of the player.
     * @param string $message The complete message.
     */
    private function onSay($name, $steam_id, $team, $message) {
        if ($steam_id !== 'Console') {
            $this->log('SAY | ' . $name . '<' . $team . '><' . $steam_id . '>: ' . $message);
        }
        if ($message[0] === '!') {
            $parts = explode(' ', substr($message, 1));
            switch (strtolower($parts[0])) {
                case 'ready':
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
                    if (!isset($parts[1])) {
                        $parts[1] = '';
                    }
                    $this->commandMap($team, $parts[1]);
                    break;
                case 'stay':
                    $this->commandStay($team);
                    break;
                case 'switch':
                    $this->commandSwitch($team);
                    break;
                case 'dev':
                    $this->say('status: ' . $this->match_status);
                    $this->say($this->getTeamPrint('CT') . ': ' . $this->score['CT']);
                    $this->say($this->getTeamPrint('TERRORIST') . ': ' . $this->score['TERRORIST']);
                    $this->say('ME: ' . $name . ' @ ' . $this->getTeamPrint($team));
                    break;
                default:
                    $this->commandUnkown();
                    break;
            }
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
        $this->log('round ends with ' . $this->getTeamPrint('CT') . '(' . $ct_score . ') and ' . $this->getTeamPrint('TERRORIST') . '(' . $t_score . ')');
        $this->score['CT'] = $ct_score;
        $this->score['TERRORIST'] = $t_score;
        if ($this->match_status === self::KNIFE) {
            $this->match_status = self::AFTER_KNIFE;
            $this->knife_winner = $ct_score === 1 ? 'CT' : 'TERRORIST';
            $this->log($this->getTeamPrint($this->knife_winner) . ' wins the knife round');
            $this->rcon('mp_pause_match');
            $this->sayPeriodicMessage();
        } else if ($this->match_status === self::MATCH) {
            if ($this->isTeamswitch($ct_score + $t_score)) {
                $this->switchTeamInternals();
            } else if ($this->isMatchEnd($ct_score, $t_score)) {
                $this->log('match end');
                $this->match_status = self::END;
                $this->sayPeriodicMessage();
                $this->sendResult($ct_score, $t_score);
                // @todo kick all player
                // @todo disable udp logging via rcon
            }
        }
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
        if ($this->match_status === self::WARMUP || $this->match_status === self::PAUSE) {
            if ($this->ready_status[$team] !== true) {
                $this->log($this->getTeamPrint($team) . ' is ready');
            }
            $this->ready_status[$team] = true;
            if ($this->ready_status['CT'] === true && $this->ready_status['TERRORIST'] === true) {
                $this->ready_status = ['CT' => false, 'TERRORIST' => false];
                if ($this->match_status === self::WARMUP) {
                    $this->startKniferound();
                } else {
                    $this->log('unpause match');
                    $this->match_status = self::MATCH;
                    $this->say('MATCH IS LIVE AGAIN!');
                    $this->rcon('mp_unpause_match');
                }
            } else {
                $this->sayPeriodicMessage();
            }
        }
    }

    /**
     * Sets a team as unready.
     * @param string $team
     */
    private function commandUnready($team) {
        if (in_array($this->match_status, [self::WARMUP, self::PAUSE])) {
            if ($this->ready_status[$team] !== false) {
                $this->log($this->getTeamPrint($team) . ' is unready');
            }
            $this->ready_status[$team] = false;
        }
    }

    /**
     * Pauses the match (only if the match is live).
     * @param string $team
     */
    private function commandPause($team) {
        if ($this->match_status === self::MATCH) {
            $this->log($this->getTeamPrint($team) . ' wants to pause the match');
            $this->match_status = self::PAUSE;
            $this->say('MATCH WILL BE PAUSED (NEXT FREEZETIME)');
            $this->say('TYPE !ready TO CONTINUE');
            $this->rcon('mp_pause_match');
        }
    }

    /**
     * Displays all commands which are available for the current match status.
     */
    private function commandHelp() {
        $commands = '!map';
        switch ($this->match_status) {
            case self::AFTER_KNIFE:
                $commands .= ', !stay, !switch';
                break;
            case self::WARMUP:
            case self::PAUSE:
                $commands .= ', !ready, !unready';
                break;
            case self::MATCH:
                $commands .= ', !pause';
                break;
        }

        $this->say('YOU CAN USE THE FOLLOWING COMMANDS:');
        $this->say($commands);
        $this->say('USE !fullhelp TO GET ALL COMMANDS!');;
    }

    /**
     * Displays all available commands.
     */
    private function commandFullhelp() {
        $this->say('ALL COMMANDS:');
        $this->say('!ready, !unready, !stay, !switch, !pause, !map, !help, !fullhelp');
    }

    /**
     * Will be called if a team votes for a map. If both teams vote for the same map, it will be changed.
     * @param string $team
     * @param string $map
     */
    private function commandMap($team, $map) {
        if ($this->map_status[$team] !== '' && $map === '') {
            $this->map_status[$team] = '';
            $this->say($this->getTeamPrint($team) . ' REVOKED MAP WISH!');
            $this->log($this->getTeamPrint($team) . ' revokes map wish');
            return;
        }

        if (!in_array($map, $this->map_pool)) {
            $this->map_status[$team] = '';
            $this->say('ONLY THE FOLLOWING MAPS ARE IN THE MAP POOL:');
            $this->say(implode(', ', $this->map_pool));
        } else {
            if ($this->map_status[$team] !== $map) {
                $this->log($this->getTeamPrint($team) . ' wants map ' . $map);
            }
            $this->map_status[$team] = $map;
            if ($this->map_status['CT'] === $this->map_status['TERRORIST']) {
                $this->map_status = ['CT' => '', 'TERRORIST' => ''];
                $this->log('change map to ' . $map);
                $this->rcon('changelevel ' . $map);
                $this->match_status = self::WARMUP;
            } else {
                $this->sayPeriodicMessage();
            }
        }
    }

    /**
     * Will be called if an unkown command is executed.
     */
    private function commandUnkown() {
        $this->say('WUT? TRY IT WITH !help OR !fullhelp');
    }

    /**
     * Teams will not be switched. Go ahead and start the match
     * @param string $team The team which executes the command. Will be checked if it was the winning team of the knife round.
     */
    private function commandStay($team) {
        if ($this->match_status === self::AFTER_KNIFE && $team === $this->knife_winner) {
            $this->log($this->getTeamPrint($team) . ' wants to stay');
            $this->startMatch();
        }
    }

    /**
     * Switches the teams.
     * @param string $team The team which executes the command. Will be checked if it was the winning team of the knife round.
     */
    private function commandSwitch($team) {
        if ($this->match_status === self::AFTER_KNIFE && $team === $this->knife_winner) {
            $this->log($this->getTeamPrint($team) . ' wants to switch sides');
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
        $this->rcon('exec ' . $this->config);
        $this->rcon('mp_warmup_end');
        $this->rcon('mp_restartgame 3');
        $this->say('DO NOT FORGET TO RECORD!');
        $this->sayPeriodicMessage();
    }

    /**
     * Starts the match.
     */
    private function startMatch() {
        $this->log('start match');
        $this->match_status = self::MATCH;
        $this->score = ['CT' => 0, 'TERRORIST' => 0];
        $this->rcon('mp_unpause_match');
        $this->rcon('exec ' . $this->config);
        $this->rcon('mp_restartgame 10');
        $this->say('THE MATCH IS LIVE AFTER THE NEXT RESTART!');
        $this->say('GL & HF EVERYBODY');
    }

    /**
     * Says something on the gameserver.
     * @param string $message
     */
    private function say($message) {
        $this->rcon('say [BL-BOT] ' . $message);
    }

    /**
     * Executes a rcon command on the gameserver.
     * @param string $rcon
     */
    private function rcon($rcon) {
        $this->rcon->rcon($rcon);
    }

    /**
     * Writes something into the logfile, together with the match id.
     * @param string $message
     */
    private function log($message) {
        Log::info('MATCH ' . $this->id . ' | ' . $message);
    }

    /**
     * Method to switch internal fields like team names, score and map wish status.
     */
    private function switchTeamInternals() {
        $this->log('switch teams');

        $this->teamname = ['CT' => $this->teamname['TERRORIST'], 'TERRORIST' => $this->teamname['CT']];
        $this->score = ['CT' => $this->score['TERRORIST'], 'TERRORIST' => $this->score['CT']];
        $this->map_status = ['CT' => $this->map_status['TERRORIST'], 'TERRORIST' => $this->map_status['CT']];
        $this->ready_status = ['CT' => $this->ready_status['TERRORIST'], 'TERRORIST' => $this->ready_status['CT']];

    }

    /**
     * Returns true if the teams are switched (at halftimes).
     * @param int $rounds_played
     * @return bool
     */
    private function isTeamswitch($rounds_played) {
        $ot_halftime = $this->maxrounds + (max(1, $this->getOvertimeNumber($rounds_played)) - 0.5) * $this->ot_maxrounds;
        return $rounds_played === $this->maxrounds / 2 || $rounds_played === $ot_halftime;
    }

    /**
     * Returns true if the winner is fixed.
     * @param int $ct_score
     * @param int $t_score
     * @return bool
     */
    private function isMatchEnd($ct_score, $t_score) {
        $score_to_win = $this->maxrounds / 2 + $this->getOvertimeNumber($ct_score + $t_score) * $this->ot_maxrounds / 2 + 1;
        return $ct_score === $score_to_win || $t_score === $score_to_win;
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

    private function sendResult($ct_score, $t_score) {
        $data = ['id' => $this->id, 'ct_score' => $ct_score, 't_score' => $t_score];
        $this->log('send result: ' . json_encode($data));
        $options = ['http' => [
            'header'  => "Content-type: application/x-www-form-urlencoded\r\n",
            'method'  => 'POST',
            'content' => http_build_query($data)]];
        $context  = stream_context_create($options);
        file_get_contents($this->url, false, $context);
        // @todo check result of file_get_contents
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
            echo $packet . PHP_EOL;
        }
    }
}
