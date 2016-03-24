<?php

namespace TMT;

class MatchData {
    /**
     * @var string[]
     */
    private $map_pool;

    /**
     * @var string
     */
    private $default_map;

    /**
     * @var int
     */
    private $match_id;

    /**
     * @var int
     */
    private $team1_id;

    /**
     * @var string
     */
    private $team1_name;

    /**
     * @var int
     */
    private $team2_id;

    /**
     * @var string
     */
    private $team2_name;

    /**
     * @var string
     */
    private $ip;

    /**
     * @var int
     */
    private $port;

    /**
     * @var string
     */
    private $rcon;

    /**
     * @var string
     */
    private $password;

    /**
     * @var string
     */
    private $config;

    /**
     * @var string
     */
    private $pickmode;

    /**
     * @var string
     */
    private $url;

    /**
     * @var string
     */
    private $match_end;

    /**
     * @var string[]
     */
    private $rcon_bootstrap;

    /**
     * @var string
     */
    private $json_string;

    /**
     * MatchData constructor.
     * @param string $json_string
     * @return bool True if all required data was available and has been set.
     */
    public function setFieldsFromJsonString($json_string) {
        $o = json_decode($json_string);
        if (true
            && isset($o->map_pool) && is_array($o->map_pool)
            && isset($o->default_map)
            && isset($o->match_id)
            && isset($o->team1->id) && isset($o->team1->name)
            && isset($o->team2->id) && isset($o->team2->name)
            && isset($o->ip)
            && isset($o->port)
            && isset($o->rcon)
            && isset($o->password)
            && isset($o->config)
            && isset($o->pickmode)
            && isset($o->url)
            && isset($o->match_end)
            && isset($o->rcon_bootstrap) && is_array($o->rcon_bootstrap)
        ) {
            $this->map_pool = $o->map_pool;
            $this->default_map = $o->default_map;
            $this->match_id = $o->match_id;
            $this->team1_id = $o->team1->id;
            $this->team1_name = $o->team1->name;
            $this->team2_id = $o->team2->id;
            $this->team2_name = $o->team2->name;
            $this->ip = $o->ip;
            $this->port = $o->port;
            $this->rcon = $o->rcon;
            $this->password = $o->password;
            $this->config = $o->config;
            $this->pickmode = $o->pickmode;
            $this->url = $o->url;
            $this->match_end = $o->match_end;
            $this->rcon_bootstrap = $o->rcon_bootstrap;

            $this->json_string = json_encode($o);

            return true;
        }

        return false;
    }

    /**
     * @return \string[]
     */
    public function getMapPool() {
        return $this->map_pool;
    }

    /**
     * @return string
     */
    public function getDefaultMap() {
        return $this->default_map;
    }

    /**
     * @return int
     */
    public function getMatchId() {
        return $this->match_id;
    }

    /**
     * @return int
     */
    public function getTeam1Id() {
        return $this->team1_id;
    }

    /**
     * @return string
     */
    public function getTeam1Name() {
        return $this->team1_name;
    }

    /**
     * @return int
     */
    public function getTeam2Id() {
        return $this->team2_id;
    }

    /**
     * @return string
     */
    public function getTeam2Name() {
        return $this->team2_name;
    }

    /**
     * @return string
     */
    public function getIp() {
        return $this->ip;
    }

    /**
     * @return int
     */
    public function getPort() {
        return $this->port;
    }

    /**
     * @return string
     */
    public function getRcon() {
        return $this->rcon;
    }

    /**
     * @return string
     */
    public function getPassword() {
        return $this->password;
    }

    /**
     * @return string
     */
    public function getConfig() {
        return $this->config;
    }

    /**
     * @return string
     */
    public function getPickmode() {
        return $this->pickmode;
    }

    /**
     * @return string
     */
    public function getUrl() {
        return $this->url;
    }

    /**
     * @return string
     */
    public function getMatchEnd() {
        return $this->match_end;
    }

    /**
     * @return \string[]
     */
    public function getRconBootstrap() {
        return $this->rcon_bootstrap;
    }

    /**
     * @return string
     */
    public function getJsonString() {
        return $this->json_string;
    }

    /**
     * @return string
     */
    public function getIpPort() {
        return $this->ip . ':' . $this->port;
    }
}
