<?php
// AllScan Main controller (index.php)
// Author: David Gleason - AllScan.info
require_once('include/common.php');
require_once('include/hwUtils.php');
$html = new Html();
$msg = [];

// Init base cfgs
asInit($msg);
// Init DB (exits on error)
$db = dbInit();
// Validate/create required DB tables. Returns count of users in user table (exits on error)
$userCnt = checkTables($db, $msg);
if(!$userCnt)
	redirect('user/');
// Init gCfgs (exits on error)
$cfgModel = new CfgModel($db);
// Init Users module, validate user
$userModel = new UserModel($db);
$user =	$userModel->validate();
if(!readOk())
	redirect('user/');

// Load node and host definitions
$hosts = [];
$onLoad = '';
$nodes = readAllmonIni($msg, $hosts);
if(!empty($nodes) && !empty($hosts)) {
	$node = $nodes[0];
	$host = $hosts[0];

	// Load ASL DB
	$astdb = readAstDb($msg);

	if($astdb !== false)
		$onLoad = " onLoad=\"asInit('server.php?nodes=$node')\"";

	$favsFile = file_exists(favsini) ? favsini : (file_exists(smfavsini) ? smfavsini : null);

	// Handle form submits
	$parms = getRequestParms();
	if(isset($_POST['Submit']) && $astdb !== false) {
		processForm($parms, $msg);
		unset($_POST, $parms);
	}
}

// Output headers
pageInit($onLoad);

if(!isset($node) || $astdb === false)
	asExit(implode(BR, $msg));

if(!$gCfg[call] && adminUser())
	p('global.inc not found. Check Supermon install or Enter Call Sign, Location and Node Title on Cfgs page');

$remNode = (isset($parms['node']) && validDbID($parms['node']) && strlen($parms['node']) < 9) ? $parms['node'] : '';

showConnStatusTable();

if(modifyOk())
	showNodeCtrlForm();

h2('Favorites');
// Read in favorites.ini
$favs = [];
$favcmds = [];
if(!$favsFile) {
	msg('favorites.ini not found. Check Supermon install or click below to create file in ' . getcwd() . '/.');
	showFavsIniForm();
} else {
	$favsIni = parse_ini_file($favsFile, true);
	if($favsIni === false) {
		p("Error parsing $favsFile. Check file format/permissions or create file with www-data writeable permissions.");
	} else {
		// Combine [general] stanza with this node's stanza
		$favsCfg = $favsIni['general'];
		if(isset($favsIni[$node])) {
			foreach($favsIni[$node] as $type => $arr) {
				if($type == 'label') {
					foreach($arr as $label) {
						$favsCfg['label'][] = $label;
					}
				} elseif($type == 'cmd') {
					foreach($arr as $cmd) {
						$favsCfg['cmd'][] = $cmd;
					}
				}
			}
		}
		$favsCfg['label'] = array_map('trim', $favsCfg['label']);
		$favsCfg['cmd'] = array_map('trim', $favsCfg['cmd']);
		foreach($favsCfg['cmd'] as $i => $c) {
			if(!$c) {
				unset($favsCfg['cmd'][$i], $favsCfg['label'][$i]);
			} else {
				if(preg_match('/[0-9]{4,8}/', $c, $m) == 1)
					$favs[$i] = (object)['node'=>$m[0], 'label'=>$favsCfg['label'][$i], 'cmd'=>$c];
				else
					$favcmds[$i] = (object)['label'=>$favsCfg['label'][$i], 'cmd'=>$c];
			}
		}
		// if(count($favcmds))
			// varDump($favcmds);
		$msg[] = _count($favs) . " favorites read from $favsFile";
	}
}

// Combine favs node, label data with astdb data into favList
$favList = [];
foreach($favs as $n => $f) {
	if(array_key_exists($f->node, $astdb)) {
		list($x, $call, $desc, $loc) = $astdb[$f->node];
	} else {
		if($f->node < 3000000)
			list($x, $call, $desc, $loc) = [$n, '-', '[Not in ASL DB]', '[Check Node Number]'];
		else
			list($x, $call, $desc, $loc) = [$n, '-', '[EchoLink Node]', '-'];
	}
	$name = str_replace([$f->node, $call, $desc, $loc, ' ,'], ' ', $f->label);
	foreach(['call', 'name', 'desc', 'loc'] as $var)
		$$var = trim(str_replace('  ', ' ', $$var), " .,;\n\r\t\v\x00");
	if(!$name)
		$name = $call;
	elseif(strpos($name, $call) === false && $call !== '-')
		$name = $call . ' ' . $name;
	$favList[] = [$n, $f->node, $name, $desc, $loc, NBSP, NBSP];
}

// Sort favList by specified column if fs parm is set
$colKey = ['num', 'node', 'name', 'desc', 'loc'];
$sortCol = isset($_GET['fs']) && in_array($_GET['fs'], $colKey) ? $_GET['fs'] : 'num';
if($sortCol && !empty($favList) && count($favList) > 1) {
	$col = array_search($sortCol, $colKey);
	$sortAsc = !(isset($_GET['fso']) && $_GET['fso'] === 'd');
	$favList = sortArray($favList, $col, !$sortAsc);
}

// Output Favorites table
if(empty($favList)) {
	p('No Favorites have yet been added');
} else {
	$hdrCols = ['#', 'Node', 'Name', 'Desc', 'Location', '<small>Rx%</small>', '<small>LCnt</small>'];
	if(count($favList) > 1) {
		foreach($hdrCols as $key => &$col) {
			if($key > 4)
				break;
			$ck = $colKey[$key];
			if($sortCol === $ck) {
				// Link to sort in opposite order
				$col = getSortLink($parms, $ck, !$sortAsc, $col, 'fs', 'fso');
				// Show an arrow indicating current sort col and direction
				$col .= upDownArrow($sortAsc);
			} else {
				// Link to sort in ASC order (or DESC order for time cols)
				$col = getSortLink($parms, $ck, true, $col, 'fs', 'fso');
			}
		}
	}
	$out = $html->tableOpen($hdrCols, null, 'favs', null, 'favs');
	foreach($favList as $f) {
		$nodeNumAttr = ['1' => 'class="nodeNum" onClick="setNodeBox(' . $f[1] . ')"'];
		// Link name to ASL stats page for node
		if($f[1] >= 2000 && $f[1] < 3000000)
			$f[2] = $html->a("http://stats.allstarlink.org/stats/" . $f[1], null, $f[2], null, 'stats');
		if($f[3] == '')
			$f[3] = '-';
		if($f[4] == '')
			$f[4] = '-';
		$out .= $html->tableRow($f, null, null, false, $nodeNumAttr);
	}
	$out .= $html->tableClose();
	echo $out;
}

// Status Messages div
echo "<p id=\"scanmsg\"></p>";
$msg = implode(BR, $msg);
echo "<div id=\"statmsg\">$msg</div>" . BR;

$sep = ENSP . '|' . ENSP;
// Show CPU Temp if available
if(($ct = cpuTemp()))
	echo '<span id="cputemp">' . $ct . '</span>' . $sep;

// Show function buttons and Links
echo $html->linkButton('Node Stats', "http://stats.allstarlink.org/stats/$node", 'small', null, null, 'stats');

if(modifyOk())
	echo $html->linkButton('Restart Asterisk', null, 'small', null, 'astrestart();');

showFooterLinks();

asExit();

// ---------------------------------------------------
function processForm($parms, &$msg) {
	global $astdb, $favsFile, $globalInc;
	$node = $parms['node'];
	switch($parms['Submit']) {
		case "Add Favorite":
			$msg[] = "Add Node $node to Favorites requested";
			if(!validDbID($node)) {
				$msg[] = "Invalid node#.";
				break;
			}
			if(!array_key_exists($node, $astdb)) {
				$msg[] = "Node $node not found in ASL DB. Check Node Number.";
				break;
			}
			// Parse file lines and add new favorite after last label,cmd lines.
			// Note: Does not look at [general] or [node#] sections (TBI)
			if(($favs = readFileLines($favsFile, $msg, true)) === false)
				break;
			$n = count($favs);
			$msg[] = "$n lines read from $favsFile";
			$lastCmdLn = 0;
			for($i=0; $i < $n; $i++) {
				if(strpos($favs[$i], 'cmd[]') === 0) {
					if(strpos($favs[$i], " $node\"")) {
						$msg[] = "Node $node already exists in favorites.";
						break(2);
					}
					$insertLn = $i + 2;
				}
			}
			// Add blank line after last fav entry if not present
			if($favs[$insertLn] !== '') {
				array_splice($favs, $insertLn, 0, ['']);
				$n++;
			}
			list($x, $call, $desc, $loc) = $astdb[$node];
			$label = "label[] = \"$call $desc, $loc $node\"";
			$cmd = "cmd[] = \"rpt cmd %node% ilink 3 $node\"";
			array_splice($favs, $insertLn, 0, [$label, $cmd, '']);
			$n = count($favs);
			if(!writeFileLines($favsFile, $favs, $msg))
				break;
			$msg[] = "Successfully wrote $n lines to $favsFile";
			break;
		case "Delete Favorite":
			$msg[] = "Delete Node $node from Favorites requested";
			if(!validDbID($node)) {
				$msg[] = "Invalid node#.";
				break;
			}
			// Parse file lines and delete favorite's label,cmd lines.
			// Note: Does not look for [general] or [node#] sections
			if(($favs = readFileLines($favsFile, $msg, true)) === false)
				break;
			$n = count($favs);
			$msg[] = "$n lines read from $favsFile";
			$lastCmdLn = 0;
			for($i=0; $i < $n; $i++) {
				if(strpos($favs[$i], 'cmd[]') == 0 && strpos($favs[$i], " $node\""))
					$delLn = $i + 1;
			}
			if(!isset($delLn)) {
				$msg[] = "Node $node not found in $favsFile";
				break;
			}
			$nLines = 2;
			$startLn = $delLn - $nLines;
			if($startLn <= 0) {
				$msg[] = error("Invalid $favsFile format");
				break;
			}
			// Also delete blank line after entry if present
			if($favs[$delLn] === '' && $delLn < $n - 1)
				$nLines++;
			for($i=0; $i < $nLines; $i++)
				unset($favs[$startLn + $i]);
			$n = count($favs);
			if(!writeFileLines($favsFile, $favs, $msg))
				break;
			$msg[] = "Successfully wrote $n lines to $favsFile";
			break;
		case CREATE_FAVORITESINI_FILE:
			$from = 'docs/favorites.ini.sample';
			$to = favsini;
			if(copy($from, $to)) {
				$msg[] = "Copied $from to $to OK";
				chmod($to, 0664);
				$favsFile = $to;
			} else {
				$msg[] = error("Copy $from to $to Error. Check directory permissions.");
			}
			break;
	}
}

function sortArray($list, $col, $desc) {
	$colVals = [];
	foreach($list as $val)
		$colVals[] = $val[$col];
	// Sort the column data while retaining array keys
	if($desc)
		arsort($colVals);
	else
		asort($colVals);
	// Reorder the input array
	$out = [];
	foreach(array_keys($colVals) as $k)
		$out[] = $list[$k];
	return $out;
}
