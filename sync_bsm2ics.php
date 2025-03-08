<?php

if (isset($argv[1]) && is_numeric($argv[1]) && $argv[1] > 0) {
    $bsm_leaguegroup = $argv[1];
} else {
    echo "ERROR: given leaguegroup ".escapeshellarg($argv[1])." is invalid".PHP_EOL;
    exit(1);
}

if (isset($argv[2]) && is_numeric($argv[2]) && $argv[2] > 0) {
    $bsm_teamid = $argv[2];
} else {
    echo "ERROR: given teamID ".escapeshellarg($argv[2])." is invalid".PHP_EOL;
    exit(1);
}

if (isset($argv[3]) && is_numeric($argv[3]) && $argv[3] > 0) {
    $match_duration = $argv[3];
} else {
    $match_duration = 9000; // 60 * 60 * 2.5 = 2,5h
}

require_once "config.php";

if (empty($nc_cal_url)) {
    echo "ERROR: no calendar URL given".PHP_EOL;
    exit(1);
}

if (empty($nc_login) || empty($nc_password)) {
    echo "ERROR: no Nextcloud Login given".PHP_EOL;
    exit(1);
}

function check_header_line($curl, $header_line) {
    $status = curl_getinfo($curl);
    if ($status['http_code'] > 399) {
        echo "HTTP ".$status['http_code'].PHP_EOL;
        echo "Aborting after request: " . $status['url'].PHP_EOL;
        exit(1);
    }
    return strlen($header_line);
}

$calid_prefix = "bsm-" . $bsm_leaguegroup . "-" . $bsm_teamid . "-";
$userpwd = $nc_login . ':' . $nc_password;

echo "Loading existing calendar entries...".PHP_EOL;
$nc_url = $nc_cal_url . "?export&accept=jcal";
$headers = array('Content-Type: text/calendar', 'charset=utf-8');
$ch = curl_init($nc_url);
curl_setopt($ch, CURLOPT_RETURNTRANSFER, true);
curl_setopt($ch, CURLOPT_HEADERFUNCTION, "check_header_line");
curl_setopt($ch, CURLOPT_HTTPHEADER, $headers);
curl_setopt($ch, CURLOPT_HTTPAUTH, CURLAUTH_BASIC);
curl_setopt($ch, CURLOPT_USERPWD, $userpwd);
curl_setopt($ch, CURLOPT_CUSTOMREQUEST, 'GET');
$response = curl_exec($ch);
if (false === $response) {
    echo "ERROR fetching calendar entries".PHP_EOL;
    echo "Aborting after request: ".$nc_url.PHP_EOL;
    exit(1);
}
curl_close($ch);

$entries = json_decode($response);
$caldata = $entries[2];

$calendar_entries = array();
foreach ($caldata as $calitem) {
    if ("vevent" == $calitem[0]) {
        //var_dump($calitem[0]);
        $calentry = array();
        foreach($calitem[1] as $keyvalue) {
            switch ($keyvalue[0]) {
                case 'uid':
                    if (str_starts_with($keyvalue[3], $calid_prefix)) {
                        $calentry['uid'] = $keyvalue[3];
                    } else {
                        //echo "ignoring ".$keyvalue[3];
                        continue 3;
                    }
                    break;
                case 'dtstart':
                    $calentry['dtstart'] = $keyvalue[3];
                    break;
                case 'dtend':
                    $calentry['dtend'] = $keyvalue[3];
                    break;
                case 'description':
                    $calentry['description'] = $keyvalue[3];
                    break;
                case 'location':
                    $calentry['location'] = $keyvalue[3];
                    break;
                case 'summary':
                    $calentry['summary'] = $keyvalue[3];
                    break;
            }
        }
        $calendar_entries[$calentry['uid']] = $calentry;
    }
}


echo "loading matches from BSM...".PHP_EOL;

$url = "https://bsm.baseball-softball.de/league_groups/" . $bsm_leaguegroup . "/matches.json?compact=true";
$ch = curl_init($url);
$headers = array('Content-Type: application/json', 'charset=utf-8');
curl_setopt($ch, CURLOPT_RETURNTRANSFER, true);
curl_setopt($ch, CURLOPT_HTTPHEADER, $headers);
$response = curl_exec($ch);
if (false === $response) {
    echo "ERROR fetching match data from BSM".PHP_EOL;
    echo "Aborting after request: ".$url.PHP_EOL;
    exit(1);
}
curl_close($ch);

$matchdata = json_decode($response);

//var_dump($matchdata);
$own_matches = array();
foreach ($matchdata as $data) {
    if ($data->home_league_entry->team->id == $bsm_teamid || $data->away_league_entry->team->id == $bsm_teamid) {
        $own_matches[] = $data;
    }
}
echo "got ".sizeof($matchdata)." matches for league, ".sizeof($own_matches)." with given team".PHP_EOL;

$save_in_cal = array();
foreach ($own_matches as $match) {
    if (isset($calendar_entries[$calid_prefix . $match->id])) {
        //update entry
        // TODO compare and update only if different
        $entry = $calendar_entries[$calid_prefix . $match->id];
        unset($calendar_entries[$calid_prefix . $match->id]);
    } else {
        //create new cal entry
        $entry = array();
    }

    $home = $match->home_league_entry->team->name;
    $h = $match->home_league_entry->team->short_name;
    $guest = $match->away_league_entry->team->name;
    $g = $match->away_league_entry->team->short_name;

    $entry['uid'] = $calid_prefix . $match->id;
    $entry['dtstart'] = $match->time;
    $entry['dtend'] = $match->time;
    $entry['description'] = $h . " vs. " . $g . "\\n(Match " . $entry['uid'] . ")";
    $entry['location'] = $match->field->street . '\\n' . $match->field->postal_code . ' ' . $match->field->city;
    $entry['summary'] = $home . " - " . $guest . " (" . $match->league->acronym . ")";

    $save_in_cal[] = $entry;
}


//delete matches vanished from BSM
echo "deleting..." . sizeof($calendar_entries).PHP_EOL;
foreach ($calendar_entries as $entry_to_del) {
    echo "deleting " . $entry_to_del["uid"].PHP_EOL;
    $del_url = $nc_cal_url . $entry_to_del["uid"] . ".ics";
    $ch = curl_init($del_url);
    curl_setopt($ch, CURLOPT_RETURNTRANSFER, true);
    curl_setopt($ch, CURLOPT_HTTPHEADER, $headers);
    curl_setopt($ch, CURLOPT_HTTPAUTH, CURLAUTH_BASIC);
    curl_setopt($ch, CURLOPT_USERPWD, $userpwd);
    curl_setopt($ch, CURLOPT_CUSTOMREQUEST, 'DELETE');
    $response = curl_exec($ch);
    if (false === $response) {
        echo "ERROR deleting calendar entry".PHP_EOL;
        echo "Aborting after request: ".$del_url.PHP_EOL;
        exit(1);
    }
    curl_close($ch);
}


echo "saving matches..." . sizeof($save_in_cal).PHP_EOL;
foreach ($save_in_cal as $calmatch) {
    $add_url = $nc_cal_url . $calmatch['uid'] . ".ics";
    $headers = array('Content-Type: text/calendar', 'charset=utf-8');
    $description = $calmatch['description'];
    $location = $calmatch['location'];
    $summary = $calmatch['summary'];
    $tstart = gmdate("Ymd\THis\Z", strtotime($calmatch['dtstart']));
    $tend = gmdate("Ymd\THis\Z", strtotime($calmatch['dtend']) + $match_duration);
    $tstamp = gmdate("Ymd\THis\Z");
    $uid = $calmatch['uid'];
    $body = <<<__EOD
    BEGIN:VCALENDAR
    VERSION:2.0
    BEGIN:VEVENT
    TZID:Europe/Berlin
    DTSTAMP:$tstamp
    DTSTART:$tstart
    DTEND:$tend
    UID:$uid
    DESCRIPTION:$description
    LOCATION:$location
    SUMMARY:$summary
    END:VEVENT
    BEGIN:VTIMEZONE
    TZID:Europe/Berlin
    BEGIN:DAYLIGHT
    TZOFFSETFROM:+0100
    TZOFFSETTO:+0200
    TZNAME:CEST
    DTSTART:19700329T020000
    RRULE:FREQ=YEARLY;BYMONTH=3;BYDAY=-1SU
    END:DAYLIGHT
    BEGIN:STANDARD
    TZOFFSETFROM:+0200
    TZOFFSETTO:+0100
    TZNAME:CET
    DTSTART:19701025T030000
    RRULE:FREQ=YEARLY;BYMONTH=10;BYDAY=-1SU
    END:STANDARD
    END:VTIMEZONE
    END:VCALENDAR
    __EOD;

    $ch = curl_init($add_url);
    curl_setopt($ch, CURLOPT_RETURNTRANSFER, true);
    curl_setopt($ch, CURLOPT_HTTPHEADER, $headers);
    curl_setopt($ch, CURLOPT_HTTPAUTH, CURLAUTH_BASIC);
    curl_setopt($ch, CURLOPT_USERPWD, $userpwd);
    //curl_setopt($ch, CURLOPT_PUT, true);
    curl_setopt($ch, CURLOPT_CUSTOMREQUEST, 'PUT');
    curl_setopt($ch, CURLOPT_POSTFIELDS, $body);

    //Execute the request.
    $response = curl_exec($ch);
    if (false === $response) {
        echo "ERROR creating/updating calendar entry ".$uid.PHP_EOL;
        echo "Aborting after request: ".$add_url.PHP_EOL;
        exit(1);
    }
    curl_close($ch);
}

?>
