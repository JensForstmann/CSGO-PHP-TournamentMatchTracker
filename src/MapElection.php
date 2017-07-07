<?php

namespace TMT;

class MapElection {
    /**
     * Election mode. One of the constants below.
     * @var string
     */
    private $mode;
    const DEFAULT_MAP = 'DEFAULT_MAP';
    const AGREE = 'AGREE';
    const BEST_OF_X = 'BEST_OF_X';

    /**
     * @var string
     */
    private $best_of_x_sequence = '';

    /**
     * @var int
     */
    private $best_of_x;

    /**
     * @var string[]
     */
    private $map_pool = [];

    /**
     * @var Match
     */
    private $match;

    /**
     * @var string[]
     */
    private $map_wish = ['CT' => '', 'T' => ''];

    /**
     * @var string
     */
    private $next_turn_team = '';

    /**
     * Which maps be played (in which order)?
     * @var string[]
     */
    private $match_maps = [];

    /**
     * MapElection constructor.
     * @param Match $match
     * @throws \Exception If $mode is not supported. Or if best of x has some misconfiguration.
     */
    public function __construct($match) {
        $this->match = $match;

        $mode = strtoupper($this->match->getMatchData()->getPickmode());
        $modes = [self::DEFAULT_MAP, self::AGREE, self::BEST_OF_X];
        if (!in_array($mode, $modes)) {
            throw new \Exception('Map election mode ' . $mode . ' is not supported! Only the following modes are supported: ' . implode(', ' , $modes));
        }
        $this->mode = $mode;

        $this->map_pool = $this->match->getMatchData()->getMapPool();

        if ($this->mode === self::BEST_OF_X) {
            $this->initBestOfX();
        }

        if ($this->mode === self::DEFAULT_MAP) {
            $this->match_maps = [$this->match->getMatchData()->getDefaultMap()];
            $this->match->setMatchStatus(Match::AFTER_MAP_ELECTION);
        }
    }

    /**
     * Initializes best of x pickmode.
     * Checks best_of_x_sequence and calculates best_of_x
     * @throws \Exception Wrong format of best_of_x_sequence. Or too small map pool for long sequence.
     */
    private function initBestOfX() {
        $best_of_x_sequence = $this->match->getMatchData()->getBestOfXSequence();

        if (preg_match('~[^bpr]~', $best_of_x_sequence)) {
            throw new \Exception('Option best_of_x_sequence must only contain the letters b, p and r.');
        }

        $best_of_x = substr_count($best_of_x_sequence, 'p') + substr_count($best_of_x_sequence, 'r');
        if ($best_of_x < 1) {
            throw new \Exception('Option best_of_x_sequence must contain at least one p or r.');
        }

        if (strlen($best_of_x_sequence) > count($this->map_pool)) {
            throw new \Exception('Option best_of_x_sequence is too long for the map pool.');
        }

        $this->best_of_x_sequence = $best_of_x_sequence;
        $this->best_of_x = $best_of_x;
    }

    /**
     * Returns an array with all available commands based on the map election pickmode.
     * @return string[]
     */
    public function getAvailableCommands() {
        if ($this->mode === self::DEFAULT_MAP) {
            return [];
        }
        if ($this->mode === self::AGREE) {
            return ['map'];
        }
        if ($this->mode === self::BEST_OF_X) {
            return ['ban', 'pick'];
        }
    }

    /**
     * Sets the map wish for the team.
     * If both teams wish the same map, it will be changed.
     * @param string $team
     * @param string $map
     */
    public function commandMap($team, $map) {
        if ($this->map_wish[$team] !== '' && $map === '') {
            $this->map_wish[$team] = '';
            $this->match->say($this->match->getTeamPrint($team) . ' REVOKES MAP WISH!');
            $this->match->log($this->match->getTeamPrint($team) . ' revokes map wish');
            return;
        }

        if (!in_array($map, $this->map_pool)) {
            $this->map_wish[$team] = '';
            $this->match->say('ONLY THE FOLLOWING MAPS ARE IN THE MAP POOL:');
            $this->match->say(implode(', ', $this->match->getMatchData()->getMapPool()));
            return;
        }

        if ($this->map_wish[$team] !== $map) {
            $this->match->log($this->match->getTeamPrint($team) . ' wants map ' . $map);
        }
        $this->map_wish[$team] = $map;

        if ($this->map_wish['CT'] === $this->map_wish['T']) {
            $this->map_wish = ['CT' => '', 'T' => ''];
            $this->match->setMatchStatus(Match::AFTER_MAP_ELECTION);
        } else {
            $this->match->sayPeriodicMessage();
        }
    }

    /**
     * Bans a map from the map pool.
     * @param string $team
     * @param string $map
     */
    public function commandBan($team, $map) {
        if ($this->best_of_x_sequence[0] !== 'b') {
            $this->match->say($this->match->getTeamName($this->next_turn_team) . ' MUST !pick FIRST!');
            return;
        }

        if ($team !== $this->next_turn_team && $this->next_turn_team !== '') {
            $this->match->say($this->match->getTeamName($this->match->getOtherTeam($team)) . ' MUST !ban A MAP FIRST!');
            return;
        }

        $key = array_search($map, $this->map_pool);

        if ($key === false) {
            $this->match->say('YOU CAN ONLY !ban THE FOLLOWING MAPS:');
            $this->match->say(implode(', ', $this->map_pool));
            return;
        }

        $this->match->say($this->match->getTeamName($team) . ' BANNED ' . $map);

        // delete banned map from map pool
        unset($this->map_pool[$key]);
        $this->map_pool = array_values($this->map_pool);
        $this->next_turn_team = $this->match->getOtherTeam($team);

        // reduce sequence
        $this->best_of_x_sequence = substr($this->best_of_x_sequence, 1);

        $this->checkForRandom();
        $this->checkForBestOfXEnd();
    }

    /**
     * Picks a map from the map pool.
     * @param $team
     * @param $map
     */
    public function commandPick($team, $map) {
        if ($this->best_of_x_sequence[0] !== 'p') {
            $this->match->say($this->match->getTeamName($this->next_turn_team) . ' MUST !ban FIRST!!');
            return;
        }

        if ($team !== $this->next_turn_team && $this->next_turn_team !== '') {
            $this->match->say($this->match->getTeamName($this->match->getOtherTeam($team)) . ' MUST !pick A MAP FIRST!');
            return;
        }

        $key = array_search($map, $this->map_pool);

        if ($key === false) {
            $this->match->say('YOU CAN ONLY !pick THE FOLLOWING MAPS:');
            $this->match->say(implode(', ', $this->map_pool));
            return;
        }

        $this->match->say($this->match->getTeamName($team) . ' PICKED ' . $map);

        // add picked map to the match maps
        $this->match_maps[] = $map;

        // delete picked map from map pool
        unset($this->map_pool[$key]);
        $this->map_pool = array_values($this->map_pool);
        $this->next_turn_team = $this->match->getOtherTeam($team);

        // reduce sequence
        $this->best_of_x_sequence = substr($this->best_of_x_sequence, 1);

        $this->checkForRandom();
        $this->checkForBestOfXEnd();
    }

    /**
     * Checks best of x sequence for random. Eventually random picks a map. And eventually ends the map election.
     */
    private function checkForRandom() {
        if ($this->best_of_x_sequence[0] !== 'r') {
            return;
        }

        $random_map_key = array_rand($this->map_pool);

        $this->match->say('THE MAP ' . $this->map_pool[$random_map_key] . 'HAS BEEN RANDOMLY DRAWN.');

        // add random map to the match maps
        $this->match_maps[] = $this->map_pool[$random_map_key];

        // delete random map from map pool
        unset($this->map_pool[$random_map_key]);
        $this->map_pool = array_values($this->map_pool);

        // reduce sequence
        $this->best_of_x_sequence = substr($this->best_of_x_sequence, 1);

        // check if next is also random
        $this->checkForRandom();
    }

    private function checkForBestOfXEnd() {
        if ($this->best_of_x !== count($this->match_maps)) {
            // progress with map election
            $this->sayNextTurn();
            return;
        }

        $this->match->say('MAPS FOR THIS BEST OF ' . $this->best_of_x . ' MATCH:');
        $this->match->say(implode(', ', $this->match_maps));

        $this->match->setMatchStatus(Match::AFTER_MAP_ELECTION);
    }

    /**
     * Says messages depending on the current map vote status.
     */
    public function sayPeriodicMessage() {
        if ($this->mode === self::AGREE) {
            $this->match->say('WAITING FOR BOTH TEAMS TO AGREE ON A !map');
            foreach (['CT', 'T'] as $team) {
                if ($this->map_wish[$team] !== '') {
                    $this->match->say($this->match->getTeamPrint($team) . ' WANTS MAP ' . $this->map_wish[$team]);
                    $this->match->say('AGREE WITH !map ' . $this->map_wish[$team]);
                }
            }
        }
        if ($this->mode === self::BEST_OF_X) {
            if (count($this->match_maps) > 0) {
                $this->match->say('MAPS TO PLAY SO FAR: ' . implode(', ', $this->match_maps));
            }
            $this->sayNextTurn();
        }
    }

    /**
     * Says information for next ban/pick turn.
     */
    private function sayNextTurn() {
        if ($this->best_of_x_sequence[0] === 'b') {
            $this->match->say('REMAINING MAPS FOR !ban:');
        }
        if ($this->best_of_x_sequence[0] === 'p') {
            $this->match->say('REMAINING MAPS FOR !pick:');
        }

        $this->match->say(implode(', ', $this->map_pool));

        if ($this->next_turn_team !== '' && $this->best_of_x_sequence[0] === 'b') {
            $this->match->say($this->match->getTeamPrint($this->next_turn_team) . ' MUST !ban NOW!');
        }

        if ($this->next_turn_team !== '' && $this->best_of_x_sequence[0] === 'p') {
            $this->match->say($this->match->getTeamPrint($this->next_turn_team) . ' MUST !pick NOW!');
        }
    }

    /**
     * Returns the match maps.
     * @return string[]
     */
    public function getMatchMaps() {
        return $this->match_maps;
    }
}
