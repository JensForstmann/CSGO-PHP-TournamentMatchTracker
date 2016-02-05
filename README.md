# REQUIREMENTS
* PHP > 5.4.0
* PHP-JSON
* write access to own folder (for logging to `./tmt.log`)

# START
    ./tmt.php --udp-port 9999 --udp-ip 192.168.0.13 --udp-log-ip 109.110.111.112 --tcp-port 9999 --tcp-ip 192.168.0.13

* `--udp-port`: Port (udp) that is used to receive logging data from gameserver.
* `--udp-ip`: IP address for binding the udp socket. (May be a local IP behind router/firewall/NAT).
* `--udp-log-ip`: IP address to that gameserver will send the logging data. (May be a public IP.)
* `--tcp-port`: Port (tcp) that is used to receive init data for a match.
* `--tcp-ip`: IP address for bindung the tcp socket. (May be a local IP behind router/firewall/NAT).

# DEFAULTS
If a specific argument is not available, it will default to:

* `--udp-port`: 9999
* `--udp-ip`: 0.0.0.0 (listen on all ips/devices)
* `--udp-log-ip`: `getHostByName(getHostName())` (Not reliable!)
* `--tcp-port`: 9999
* `--tcp-ip`: 0.0.0.0 (listen on all ips/devices)

# INIT
The following is an example how to init a match. It must send to the script using the tcp socket.

```
{
    "map_pool": [
            "de_dust2",
            "de_train",
            "de_overpass",
            "de_inferno",
            "de_cache",
            "de_mirage",
            "de_cbble.bsp"],
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
    "password": "server_password",
    "config": "esl5on5.cfg",
    "pickmode": "bo1random",
    "url": "https://www.bieberlan.de/api/turniere/csgo.php?token=abcdefg",
    "match_end": "kick"
}
```

Notes:
* `map_pool`: array of strings
* `pickmode`: (string) `agree`, `bo1` or `bo1random`
* `matchend`: (string) `kick`, `quit` or `none`

# REPORTS
The tool will report events to the url (given in the init data):

* elected map
```
$_POST['match_id'] = 1337;
$_POST['type'] = 'map';
$_POST['map'] = 'de_mirage';
```

* match start
```
$_POST['match_id'] = 1337;
$_POST['type'] = 'start';
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
