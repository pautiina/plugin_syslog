<?php
/*
 +-------------------------------------------------------------------------+
 | Copyright (C) 2007-2010 The Cacti Group                                 |
 |                                                                         |
 | This program is free software; you can redistribute it and/or           |
 | modify it under the terms of the GNU General Public License             |
 | as published by the Free Software Foundation; either version 2          |
 | of the License, or (at your option) any later version.                  |
 |                                                                         |
 | This program is distributed in the hope that it will be useful,         |
 | but WITHOUT ANY WARRANTY; without even the implied warranty of          |
 | MERCHANTABILITY or FITNESS FOR A PARTICULAR PURPOSE.  See the           |
 | GNU General Public License for more details.                            |
 +-------------------------------------------------------------------------+
 | Cacti: The Complete RRDTool-based Graphing Solution                     |
 +-------------------------------------------------------------------------+
 | This code is designed, written, and maintained by the Cacti Group. See  |
 | about.php and/or the AUTHORS file for specific developer information.   |
 +-------------------------------------------------------------------------+
 | http://www.cacti.net/                                                   |
 +-------------------------------------------------------------------------+
*/

/* do NOT run this script through a web browser */
if (!isset($_SERVER["argv"][0]) || isset($_SERVER['REQUEST_METHOD'])  || isset($_SERVER['REMOTE_ADDR'])) {
	die("<br><strong>This script is only meant to run at the command line.</strong>");
}
$no_http_headers = true;

/* Let it run for an hour if it has to, to clear up any big
 * bursts of incoming syslog events
 */
ini_set('max_execution_time', 3600);
ini_set('memory_limit', '256M');

global $syslog_debug;

$syslog_debug = false;

/* process calling arguments */
$parms = $_SERVER["argv"];
array_shift($parms);

if (sizeof($parms)) {
	foreach($parms as $parameter) {
		@list($arg, $value) = @explode("=", $parameter);

		switch ($arg) {
		case "--debug":
		case "-d":
			$syslog_debug = true;

			break;
		case "--version":
		case "-V":
		case "-H":
		case "--help":
			display_help();
			exit(0);
		default:
			echo "ERROR: Invalid Argument: ($arg)\n\n";
			display_help();
			exit(1);
		}
	}
}

/* record the start time */
list($micro,$seconds) = split(" ", microtime());
$start_time = $seconds + $micro;

$dir = dirname(__FILE__);
chdir($dir);

if (strpos($dir, 'plugins') !== false) {
	chdir('../../');
}
include("./include/global.php");
include("./plugins/syslog/config.php");

/* If Syslog Collection is Disabled, Exit Here */
if (read_config_option("syslog_enabled") == '') {
	print "NOTE: Syslog record transferral and alerting/reporting is disabled.  Exiting\n";
	exit -1;
}

/* Connect to the Syslog Database */
syslog_connect();

/* Initialization Section */
$r = read_config_option("syslog_retention");
if ($r == '' or $r < 0 or $r > 365) {
	if ($r == '') {
		$sql = "REPLACE INTO settings VALUES ('syslog_retention','30')";
	}else{
		$sql = "UPDATE settings SET value='30' WHERE name='syslog_retention'";
	}

	$result = db_execute($sql);

	kill_session_var("sess_config_array");
}

$retention = read_config_option("syslog_retention");
$retention = date("Y-m-d", time() - (86400 * $retention));
$email     = read_config_option("syslog_email");
$emailname = read_config_option("syslog_emailname");
$from      = '';

if ($email != '') {
	if ($emailname != '') {
		$from = "\"$emailname\" ($email)";
	} else {
		$from = $email;
	}
}

/* delete old syslog and syslog soft messages */
if ($retention > 0) {
	/* delete from the main syslog table first */
	db_execute("DELETE FROM syslog WHERE logtime < '$retention'", true, $syslog_cnn);

	$syslog_deleted = $syslog_cnn->Affected_Rows();

	/* now delete from the syslog removed table */
	db_execute("DELETE FROM syslog_removed WHERE logtime < '$retention'", true, $syslog_cnn);

	$syslog_deleted += $syslog_cnn->Affected_Rows();

	syslog_debug("Deleted " . $syslog_deleted .
		" Syslog Message" . ($syslog_deleted == 1 ? "" : "s" ) .
		" (older than $retention days)");
}

/* get a uniqueID to allow moving of records to done table */
while (1) {
	$uniqueID = rand(1, 127);
	$count    = db_fetch_cell("SELECT count(*) FROM syslog_incoming WHERE status=" . $uniqueID, '', true, $syslog_cnn);

	if ($count == 0) {
		break;
	}
}

syslog_debug("Unique ID = " . $uniqueID);

/* flag all records with the uniqueID prior to moving */
db_execute("UPDATE syslog_incoming SET status=" . $uniqueID . " WHERE status=0", true, $syslog_cnn);

$syslog_incoming = $syslog_cnn->Affected_Rows();

syslog_debug("Found   " . $syslog_incoming .
	" new Message" . ($syslog_incoming == 1 ? "" : "s" ) .
	" to process");

/* update the hosts, facilities, and priorities tables */
db_execute("INSERT INTO syslog_facilities (facility) SELECT DISTINCT facility FROM syslog_incoming ON DUPLICATE KEY UPDATE facility=VALUES(facility)");
db_execute("INSERT INTO syslog_priorities (priority) SELECT DISTINCT priority FROM syslog_incoming ON DUPLICATE KEY UPDATE priority=VALUES(priority)");
db_execute("INSERT INTO syslog_hosts (host) SELECT DISTINCT host FROM syslog_incoming ON DUPLICATE KEY UPDATE host=VALUES(host)");
db_execute("INSERT INTO `" . $syslogdb_default . "`.`syslog_host_facilities`
	(host_id, facility_id)
	SELECT host_id, facility_id
	FROM ((SELECT DISTINCT host, facility
		FROM `" . $syslogdb_default . "`.`syslog_incoming`) AS s
		INNER JOIN `" . $syslogdb_default . "`.`syslog_hosts` AS sh
		ON s.host=sh.host
		INNER JOIN `" . $syslogdb_default . "`.`syslog_facilities` AS sf
		ON sf.facility=s.facility)", true, $syslog_cnn);

/* remote records that don't need to to be transferred */
$syslog_items   = syslog_remove_items("syslog_incoming");
$syslog_removed = $syslog_items["removed"];
$syslog_xferred = $syslog_items["xferred"];

/* send out the alerts */
$query = db_fetch_assoc("SELECT * FROM syslog_alert", true, $syslog_cnn);
$syslog_alerts  = sizeof($query);

syslog_debug("Found   " . $syslog_alerts .
	" Alert Rule" . ($syslog_alerts == 1 ? "" : "s" ) .
	" to process");

$syslog_alarms = 0;
if (sizeof($query)) {
foreach($query as $alert) {
	$sql    = '';
	$alertm = '';

	if ($alert['type'] == 'facility') {
		$sql = "SELECT * FROM syslog_incoming
			WHERE " . $syslog_incoming_config["facilityField"] . "='" . $alert['message'] . "'
			AND status=" . $uniqueID;
	} else if ($alert['type'] == 'messageb') {
		$sql = "SELECT * FROM syslog_incoming
			WHERE " . $syslog_incoming_config["textField"] . "
			LIKE '" . $alert['message'] . "%'
			AND status=" . $uniqueID;
	} else if ($alert['type'] == 'messagec') {
		$sql = "SELECT * FROM syslog_incoming
			WHERE " . $syslog_incoming_config["textField"] . "
			LIKE '%" . $alert['message'] . "%'
			AND status=" . $uniqueID;
	} else if ($alert['type'] == 'messagee') {
		$sql = "SELECT * FROM syslog_incoming
			WHERE " . $syslog_incoming_config["textField"] . "
			LIKE '%" . $alert['message'] . "'
			AND status=" . $uniqueID;
	} else if ($alert['type'] == 'host') {
		$sql = "SELECT * FROM syslog_incoming
			WHERE " . $syslog_incoming_config["hostField"] . "='" . $alert['message'] . "'
			AND status=" . $uniqueID;
	}

	if ($sql != '') {
		$at = db_fetch_assoc($sql, true, $syslog_cnn);

		if (sizeof($at)) {
		foreach($at as $a) {
			$a['message'] = str_replace('  ', "\n", $a['message']);
			while (substr($a['message'], -1) == "\n") {
				$a['message'] = substr($a['message'], 0, -1);
			}

			$alertm .= "-----------------------------------------------\n";
			$alertm .= 'Hostname : ' . $a['host'] . "\n";
			$alertm .= 'Date     : ' . $a['date'] . ' ' . $a['time'] . "\n";
			$alertm .= 'Severity : ' . $a['priority'] . "\n\n";
			$alertm .= 'Message  :' . "\n" . $a['message'] . "\n";
			$alertm .= "-----------------------------------------------\n\n";

			syslog_debug("Alert Rule '" . $alert['name'] . "
				' has been activated");

			$syslog_alarms++;
		}
		}
	}

	if ($alertm != '') {
		syslog_sendemail($alert['email'], '', 'Event Alert - ' . $alert['name'], $alertm);
	}
}
}

/* MOVE ALL FLAGGED MESSAGES TO THE SYSLOG TABLE */
db_execute('INSERT INTO syslog (logtime, priority_id, facility_id, host_id, message)
	SELECT TIMESTAMP(`' . $syslog_incoming_config['dateField'] . '`, `' . $syslog_incoming_config["timeField"]     . '`),
	priority_id, facility_id, host_id, message
	FROM (SELECT date, time, priority_id, facility_id, host_id, message
		FROM syslog_incoming AS si
		INNER JOIN syslog_facilities AS sf
		ON sf.facility=si.facility
		INNER JOIN syslog_priorities AS sp
		ON sp.priority=si.priority
		INNER JOIN syslog_hosts AS sh
		ON sh.host=si.host
		WHERE status=' . $uniqueID . ") AS merge", true, $syslog_cnn);

$moved = $syslog_cnn->Affected_Rows();

syslog_debug("Moved   " . $moved . " Message" . ($moved == 1 ? "" : "s" ) . " to the 'syslog' table");

/* DELETE ALL FLAGGED ITEMS FROM THE INCOMING TABLE */
db_execute("DELETE FROM syslog_incoming WHERE status=" . $uniqueID, true, $syslog_cnn);

syslog_debug("Deleted " . $syslog_cnn->Affected_Rows() . " already processed Messages from incoming");

/* Add the unique hosts to the syslog_hosts table */
$sql = "INSERT INTO syslog_hosts (host) (SELECT DISTINCT host FROM syslog_incoming) ON DUPLICATE KEY UPDATE host=VALUES(host)";

db_execute($sql, true, $syslog_cnn);

syslog_debug("Updated " . $syslog_cnn->Affected_Rows() .
	" hosts in the syslog hosts table");

/* OPTIMIZE THE TABLES ONCE A DAY, JUST TO HELP CLEANUP */
if (date("G") == 0 && date("i") < 5) {
	db_execute("OPTIMIZE TABLE syslog_incoming, syslog, syslog_remove, syslog_alert");
}

syslog_debug("Processing Reports...");

/* Lets run the reports */
$reports = db_fetch_assoc("SELECT * FROM syslog_reports", true, $syslog_cnn);
$syslog_reports = sizeof($reports);

syslog_debug("We have " . $syslog_reports . " Reports in the database");

if (sizeof($reports)) {
foreach($reports as $syslog_report) {
	print '   Report: ' . $syslog_report['name'] . "\n";
	if ($syslog_report['min'] < 10)
		$syslog_report['min'] = '0' . $syslog_report['min'];

	$base_start_time = $syslog_report['hour'] . ' : ' . $syslog_report['min'];

	$current_time = strtotime("now");
	if (empty($last_run_time)) {
		if ($current_time > strtotime($base_start_time)) {
			/* if timer expired within a polling interval, then poll */
			if (($current_time - 300) < strtotime($base_start_time)) {
				$next_run_time = strtotime(date("Y-m-d") . " " . $base_start_time);
			}else{
				$next_run_time = strtotime(date("Y-m-d") . " " . $base_start_time) + 3600*24;
			}
		}else{
			$next_run_time = strtotime(date("Y-m-d") . " " . $base_start_time);
		}
	}else{
		$next_run_time = $last_run_time + $seconds_offset;
	}
	$time_till_next_run = $next_run_time - $current_time;

	if ($next_run_time < 0) {
		print '       Next Send: Now' . "\n";
		print "       Creating Report...\n";

		$sql     = '';
		$reptext = '';
		if ($syslog_report['type'] == 'messageb') {
			$sql = "SELECT * FROM syslog
				WHERE " . $syslog_incoming_config["textField"] . "
				LIKE '" . $syslog_report['message'] . "%'";
		}

		if ($syslog_report['type'] == 'messagec') {
			$sql = "SELECT * FROM syslog
				WHERE " . $syslog_incoming_config["textField"] . "
				LIKE '%" . $syslog_report['message'] . "%'";
		}

		if ($syslog_report['type'] == 'messagee') {
			$sql = "SELECT * FROM syslog
				WHERE " . $syslog_incoming_config["textField"] . "
				LIKE '%" . $syslog_report['message'] . "'";
		}

		if ($syslog_report['type'] == 'host') {
			$sql = "SELECT * FROM syslog
				WHERE " . $syslog_incoming_config["hostField"] . "='" . $syslog_report['message'] . "'";
		}

		if ($sql != '') {
			$date2 = date("Y-m-d H:i:s", time());
			$date1 = date("Y-m-d H:i:s", time() - 86400);
			$sql  .= " AND logtime BETWEEN '". $date1 . "' AND '" . $date2 . "'";
			$sql  .= " ORDER BY logtime DESC";
			$items = db_fetch_assoc($sql, true, $syslog_cnn);

			syslog_debug("We have " . $syslog_cnn->Affected_Rows() . " items for the Report");

			if (sizeof($items)) {
			foreach($items as $item) {
				$reptext .= "<tr>" . $item['date'] . "</td><td>" . $item['time'] . "</td><td>" . $item['message'] . "</td></tr>\n";
			}
			}

			if ($reptext != '') {
				$reptext = '<html><body><center><h2>' . $syslog_report['name'] . "</h2></center><table>\n" .
					    "<tr><td>Date</td><td>Time</td><td>Message</td></tr>\n" . $reptext;

				$reptext .= "</table>\n";
				// Send mail
				syslog_sendemail($syslog_report['email'], '', 'Event Report - ' . $syslog_report['name'], $reptext);
			}
		}
	} else {
		print '       Next Send: ' . date("F j, Y, g:i a", $next_run_time) . "\n";
	}
}
}

syslog_debug("Finished processing Reports...");

syslog_process_log($start_time, $syslog_deleted, $syslog_incoming, $syslog_removed, $syslog_xferred, $syslog_alerts, $syslog_alarms, $syslog_reports);

function syslog_process_log($start_time, $deleted, $incoming, $removed, $xferred, $alerts, $alarms, $reports) {
	/* record the end time */
	list($micro,$seconds) = split(" ", microtime());
	$end_time = $seconds + $micro;

	cacti_log("SYSLOG STATS:Time:" . round($end_time-$start_time,2) . ", Deletes:" . $deleted . ", Incoming:" . $incoming . ", Removes:" . $removed . ", XFers:" . $xferred . ", Alerts:" . $alerts . ", Alarms:" . $alarms . ", Reports:" . $reports, true, "SYSTEM");

	set_config_option("syslog_stats", "time:" . round($end_time-$start_time,2) . "deletes:" . $deleted . " incoming:" . $incoming . " removes:" . $removed . " xfers:" . $xferred . " alerts:" . $alerts . " alarms:" . $alarms . " reports:" . $reports);
}

function display_help() {
	echo "Syslog Poller Process 1.0, Copyright 2004-2010 - The Cacti Group\n\n";
	echo "The main Syslog poller process script for Cacti Syslogging.\n\n";
	echo "usage: syslog_process.php [--debug|-d]\n\n";
}
