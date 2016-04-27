# REQUIREMENTS
* PHP > 5.4.0
* PHP-JSON
* PHP-OPENSSL
* write access to own folder (for logging to `./tmt.log`)

# START
    ./tmt.php --udp-port 9999 --udp-ip 192.168.0.13 --udp-log-ip 109.110.111.112 --tcp-port 9999 --tcp-ip 192.168.0.13 --token somesecurity

* `--udp-port`: Port (udp) that is used to receive logging data from gameserver.
* `--udp-ip`: IP address for binding the udp socket. (May be a local IP behind router/firewall/NAT).
* `--udp-log-ip`: IP address to that gameserver will send the logging data. (May be a public IP.)
* `--tcp-port`: Port (tcp) that is used to receive init data for a match.
* `--tcp-ip`: IP address for bindung the tcp socket. (May be a local IP behind router/firewall/NAT).
* `--token`: String that has to be the same as in the json init data to accept the job.

# DEFAULTS
If a specific argument is not available, it will default to:

* `--udp-port`: 9999
* `--udp-ip`: 0.0.0.0 (listen on all ips/devices)
* `--udp-log-ip`: `gethostbyname(gethostname())` (Not reliable!)
* `--tcp-port`: 9999
* `--tcp-ip`: 0.0.0.0 (listen on all ips/devices)
* `--token`: "" (empty string)

# MATCH INIT
The following is an example how to init a match. It must be send to the script using the tcp socket.

```
{
    "token": "somesecurity",
    "map_pool": [
            "de_dust2",
            "de_train",
            "de_overpass",
            "de_inferno",
            "de_cache",
            "de_mirage",
            "de_cbble"],
    "default_map": "de_dust2",
    "match_id": 1337,
    "team1": {
            "id": 13,
            "name": "Team NixMacher"},
    "team2": {
            "id": 37,
            "name": "Bobs Bau-Verein"},
    "ip": "10.22.33.44",
    "port": 27500,
    "rcon": "rcon_password",
    "pickmode": "bo1random",
    "url": "https://www.example.org/api/csgo.php?token=abcdefg",
    "match_end": "kick",
    "rcon_init": [
        "hostname \"MATCH: Team NixMacher vs. Bobs Bau-Verein\"",
        "sv_password strenggeheim"
    ],
    "rcon_config": [
        "mp_autokick 0",
        "mp_autoteambalance 0;mp_buytime 15"
    ],
    "rcon_end": [
        "hostname \"empty server\"",
        "sv_password nochgeheimer"
    ]
}
```

Notes:
* `token`: (string) kind of password that has to match the command line parameter
* `map_pool`: array of strings
* `pickmode`: (string) `default_map`, `agree`, `bo1`, `bo1random` or `bo1randomagree` (the last will offer both the !veto and the !map commands)
* `match_end`: (string) `kick`, `quit` or `none`
* `rcon_init`: array of strings, rcon commands will be executed once after the rcon connection is established, each entry must be shorter than 4000 chars
* `rcon_config`: array of strings, rcon commands will be executed twice (before knife round and before match start), each entry must be shorter than 4000 chars
* `rcon_end`: array of strings, rcon commands will be executed three minutes after match end (right before match_end action), each entry must be shorter than 4000 chars

# REPORTS
The tool will report events to the url (if given in the init data):

* elected map (won't be sent if pickmode is `default_map`)
```
$_POST['match_id'] = 1337;
$_POST['type'] = 'map';
$_POST['map'] = 'de_mirage';
```

* match start (when the knife round winner has chosen a side)
```
$_POST['match_id'] = 1337;
$_POST['type'] = 'start';
```

* livescore
```
$_POST['match_id'] = 1337;
$_POST['type'] = 'livescore';
$_POST['team1id'] = 13;
$_POST['team1score'] = 16;
$_POST['team2id'] = 37;
$_POST['team2score'] = 12;
```

* score after match end
```
$_POST['match_id'] = 1337;
$_POST['type'] = 'end';
$_POST['team1id'] = 13;
$_POST['team1score'] = 16;
$_POST['team2id'] = 37;
$_POST['team2score'] = 12;
```

# MATCH ABORT
The following is an example how to abort a match. It must be send to the script using the tcp socket.

```
{
    "token": "somesecurity",
    "match_id": 1337,
    "abort_match": true
}
```

Aborting the match means stopping the gameserver sending log data to the udp socket and deleting the internal match.

# USER COMMANDS (INGAME)
While beeing ingame on a tracked server the following commands are available.
Keep in mind that a few commands are jus aliases and will do the same as other commands.
Furthermore a command can be prefixed either by the `!` or the `.` character.
* During the map election if pickmode is `agree`:
    * !map, !vote, !pick
* During the map election if pickmode is `bo1` or `bo1random`:
    * !veto, !ban
* During the map election if pickmode is `bo1randomagree`:
    * !map, !vote, !pick, !veto, !ban
* During warmup:
    * !ready, !rdy
* For the winning team after the knife round:
    * !stay, !switch, !swap
* During the match:
    * !pause
* While the match is paused:
    * !ready, !rdy
