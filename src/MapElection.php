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
    const BO1 = 'BO1';
    const BO1RANDOM = 'BO1RANDOM';
    const BO1RANDOMAGREE = 'BO1RANDOMAGREE';

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
    private $next_veto_team = '';

    /**
     * From how many maps left, the random mode should fix a map.
     * @var int
     */
    private $last_maps_random;

    /**
     * MapElection constructor.
     * @param string $mode
     * @param string[] $map_pool
     * @param Match $match
     * @throws \Exception If $mode is not supported.
     */
    public function __construct($mode, $map_pool, $match) {
        $mode = strtoupper($mode);
        $modes = [self::DEFAULT_MAP, self::AGREE, self::BO1, self::BO1RANDOM, self::BO1RANDOMAGREE];
        if (!in_array($mode, $modes)) {
            throw new \Exception('Map election mode ' . $mode . ' is not supported! Only the following modes are supported: ' . implode(', ' , $modes));
        }
        $this->mode = $mode;

        $this->map_pool = $map_pool;

        $this->match = $match;

        $this->last_maps_random = count($this->map_pool) % 2 === 0 ? 2 : 3;

        if ($mode == self::DEFAULT_MAP) {
            $this->match->setMatchStatus(Match::WARMUP);
        }
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
            return ['map', 'vote', 'pick'];
        }
        if ($this->mode === self::BO1 || $this->mode === self::BO1RANDOM) {
            return ['veto', 'ban'];
        }
        if ($this->mode === self::BO1RANDOMAGREE) {
            return ['map', 'vote', 'pick', 'veto', 'ban'];
        }
    }

    /**
     * Sets the map wish for the team.
     * If both teams wish the same map, it will be changed.
     * @param string $team
     * @param string $map
     */
    public function wish($team, $map) {
        if ($this->mode !== self::AGREE && $this->mode !== self::BO1RANDOMAGREE) {
            return;
        }

        if ($this->map_wish[$team] !== '' && $map === '') {
            $this->map_wish[$team] = '';
            $this->match->say($this->match->getTeamPrint($team) . ' REVOKES MAP WISH!');
            $this->match->log($this->match->getTeamPrint($team) . ' revokes map wish');
            return;
        }

        // don't use $this->map_pool because if there already have been vetos, $this->map_pool is not full any more
        if (!in_array($map, $this->match->getMatchData()->getMapPool())) {
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
            $this->changeMap($map);
        } else {
            $this->match->sayPeriodicMessage();
        }
    }

    /**
     * Vetos a map from the map pool.
     * If the map is fixed it will be changed.
     * @param string $team
     * @param string $map
     */
    public function veto($team, $map) {
        if ($this->mode !== self::BO1 && $this->mode !== self::BO1RANDOM && $this->mode !== self::BO1RANDOMAGREE) {
            return;
        }

        if ($team !== $this->next_veto_team && $this->next_veto_team !== '') {
            $this->match->say($this->match->getTeamName($this->match->getOtherTeam($team)) . ' MUST !veto A MAP FIRST!');
            return;
        }

        $key = array_search($map, $this->map_pool);

        if ($key === false) {
            $this->match->say('ONLY THE FOLLOWING MAPS CAN BE !veto:');
            $this->match->say(implode(', ', $this->map_pool));
            return;
        }

        $this->match->say($this->match->getTeamName($team) . ' VETOED ' . $map);

        // delete vetoed map from map pool
        unset($this->map_pool[$key]);
        $this->map_pool = array_values($this->map_pool);
        $this->next_veto_team = $this->match->getOtherTeam($team);

        $elected_map = false;
        if ($this->mode === self::BO1 && count($this->map_pool) === 1) {
            $elected_map = $this->map_pool[0];
        } else if ($this->mode === self::BO1RANDOM && count($this->map_pool) === $this->last_maps_random) {
            $elected_map = $this->map_pool[mt_rand(0, $this->last_maps_random - 1)];
        }

        if ($elected_map !== false) {
            $this->changeMap($elected_map);
        } else {
            $this->sayNextTurn();
        }
    }

    /**
     * Changes the elected map on the gameserver and sends a report to the match url.
     * @param string $map
     */
    private function changeMap($map) {
        $this->match->log('change map to ' . $map);

        $this->match->report([
            'match_id' => $this->match->getMatchData()->getMatchId(),
            'type' => 'map',
            'map' => $map
        ]);

        $this->match->say('MAP WILL BE CHANGED TO ' . $map . ' IN 10 SECONDS');

        $this->match->setMatchStatus(Match::MAP_CHANGE);

        Tasker::add(10, function ($map) {
            $this->match->rcon('changelevel ' . $map);
            $this->match->setMatchStatus(Match::WARMUP);
        }, [$map]);
    }

    /**
     * Says messages depending on the current map vote status.
     */
    public function sayPeriodicMessage() {
        if ($this->mode === self::AGREE || $this->mode === self::BO1RANDOMAGREE) {
            $this->match->say('WAITING FOR BOTH TEAMS TO AGREE ON A !map');
            foreach (['CT', 'T'] as $team) {
                if ($this->map_wish[$team] !== '') {
                    $this->match->say($this->match->getTeamPrint($team) . ' WANTS MAP ' . $this->map_wish[$team]);
                    $this->match->say('AGREE WITH !map ' . $this->map_wish[$team]);
                }
            }
        }
        if ($this->mode === self::BO1RANDOMAGREE) {
            $this->match->say('...ALTERNATIVELY...');
        }
        if ($this->mode === self::BO1RANDOM || $this->mode === self::BO1RANDOMAGREE) {
            $this->match->say('!veto MAPS. FROM THE LAST ' . $this->last_maps_random . ' MAPS A RANDOM ONE WILL BE PLAYED.');
            $this->sayNextTurn();
        }
        if ($this->mode === self::BO1) {
            $this->match->say('!veto MAPS. THE LAST REMAINING MAP WILL BE PLAYED.');
            $this->sayNextTurn();
        }
    }

    /**
     * Says information for next veto turn (remaining maps and the team that have to veto).
     */
    private function sayNextTurn() {
        $this->match->say('REMAINING MAPS FOR !veto:');
        $this->match->say(implode(', ', $this->map_pool));
        if ($this->next_veto_team !== '') {
            $this->match->say($this->match->getTeamPrint($this->next_veto_team) . ' MUST !veto NOW!');
        }
    }
}
