<?php

namespace TMT;

class MapElection {
    /**
     * Election mode. One of the constants below.
     * @var string
     */
    private $mode;
    const AGREE = 'AGREE';
    const BO1 = 'BO1';
    const BO1RANDOM = 'BO1RANDOM';

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
    private $last_maps_random = 3;

    /**
     * MapElection constructor.
     * @param string $mode
     * @param string[] $map_pool
     * @param Match $match
     * @throws \Exception If $mode is not supported.
     */
    public function __construct($mode, $map_pool, $match) {
        $mode = strtoupper($mode);
        $modes = [self::AGREE, self::BO1, self::BO1RANDOM];
        if (!in_array($mode, $modes)) {
            throw new \Exception('Map election mode ' . $mode . ' is not supported! Only the following modes are supported: ' . implode(', ' , $modes));
        }
        $this->mode = $mode;

        $this->map_pool = $map_pool;

        $this->match = $match;
    }

    /**
     * Sets the map wish for the team.
     * If both teams wish the same map, it will be changed.
     * @param string $team
     * @param string $map
     */
    public function wish($team, $map) {
        if ($this->mode !== self::AGREE) {
            return;
        }

        if ($this->map_wish[$team] !== '' && $map === '') {
            $this->map_wish[$team] = '';
            $this->match->say($this->match->getTeamPrint($team) . ' REVOKES MAP WISH!');
            $this->match->log($this->match->getTeamPrint($team) . ' revokes map wish');
            return;
        }

        if (!in_array($map, $this->map_pool)) {
            $this->map_wish[$team] = '';
            $this->match->say('ONLY THE FOLLOWING MAPS ARE IN THE MAP POOL:');
            $this->match->say(implode(', ', $this->map_pool));
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
        if ($this->mode === self::AGREE) {
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
            $this->match->say($this->match->getTeamName($team) . ' VETOED ' . $map);
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
        $this->match->rcon('changelevel ' . $map);
        $this->match->setMatchStatus(Match::WARMUP);
    }

    public function sayPeriodicMessage() {
        if ($this->mode === self::AGREE) {
            $this->match->say('WAITING FOR BOTH TEAMS TO AGREE ON A !map');
            foreach (['CT', 'T'] as $team) {
                if ($this->map_wish[$team] !== '') {
                    $this->match->say($this->match->getTeamPrint($team) . ' WANTS MAP ' . $this->map_wish[$team]);
                    $this->match->say('AGREE WITH !map ' . $this->map_wish[$team]);
                }
            }

            return;
        }

        switch ($this->mode) {
            case self::BO1RANDOM:
                $this->match->say('!veto MAPS. FROM THE LAST ' . $this->last_maps_random . ' MAPS A RANDOM ONE WILL BE PLAYED.');
                break;
            case self::BO1:
                $this->match->say('!veto MAPS. THE LAST REMAINING MAP WILL BE PLAYED.');
                break;
        }

        $this->sayNextTurn();
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
