<?php

// start PHP session
session_start();
// turn on error reporting for diagnostics and so you'll know why SATT broke when it does
error_reporting(E_ALL);
ini_set("display_errors", 1);
date_default_timezone_set('America/Phoenix');

/*****************  FUNCTIONS ***************/

// creates .sql backup of current WordPress database
function backup_tables($dbhandle,$tables = '*') {
	$return = '';
	// Get array of tables
	if($tables == '*') {
		$result = array();
		$result = $dbhandle->query('SHOW TABLES');
		$tables = array();
		while($row = mysqli_fetch_row($result))	{
			$tables[] = $row[0];
		}
	} else {
		$tables = is_array($tables) ? $tables : explode(',',$tables);
	}
	foreach((array)$tables as $table) {
		$result = $dbhandle->query('SELECT * FROM '.$table);
		$num_fields = mysqli_num_fields($result);

		$return.= 'DROP TABLE IF EXISTS '.$table.';';
		$row2 = mysqli_fetch_row($dbhandle->query('SHOW CREATE TABLE '.$table));
		$return.= "\n\n".$row2[1].";\n\n";

		for ($i = 0; $i < $num_fields; $i++) {
			while($row = mysqli_fetch_row($result)) {
				$return.= 'INSERT INTO '.$table.' VALUES(';
				for($j=0; $j < $num_fields; $j++) {
					$row[$j] = addslashes($row[$j]);
					$row[$j] = str_replace("\n","\\n",$row[$j]);
					if (isset($row[$j])) { $return.= '"'.$row[$j].'"' ; } else { $return.= '""'; }
					if ($j < ($num_fields-1)) { $return.= ','; }
				}
				$return.= ");\n";
			}
		}
		$return.="\n\n\n";
	}
	//save file
	$filename = 'db-backup-'.time().'.sql';
	$handle = fopen($filename,'w+');
	fwrite($handle,$return);
	fclose($handle);
	return $filename;
}

// drops existing tables if they have same name as table to be imported, imports tables
function import_tables($dbhandle,$sql_file_OR_content) {
	set_time_limit(3000);
	$SQL_CONTENT = (strlen($sql_file_OR_content) > 200 ?  $sql_file_OR_content : file_get_contents($sql_file_OR_content));
	$allLines = explode("\n",$SQL_CONTENT);
	$zzzzzz = $dbhandle->query('SET foreign_key_checks = 0');
	preg_match_all("/\nCREATE TABLE(.*?)\`(.*?)\`/si", "\n". $SQL_CONTENT, $target_tables);
	foreach ($target_tables[2] as $table){
		$dbhandle->query('DROP TABLE IF EXISTS '.$table);
	}
	$zzzzzz = $dbhandle->query('SET foreign_key_checks = 1');
	$dbhandle->query("SET NAMES 'utf8'");
	$templine = '';	// Temporary variable, used to store current query
	foreach ($allLines as $line) {  // Loop through each line
		if (substr($line, 0, 2) != '--' && $line != '') {
			$templine .= $line; 	// (if it is not a comment..) Add this line to the current segment
			if (substr(trim($line), -1, 1) == ';') {		// If it has a semicolon at the end, it's the end of the query
				$dbhandle->query($templine) or print('Error performing query \'<strong>' . $templine . '\': ' . $dbhandle->error . '<br /><br />');
				$templine = ''; // set variable to empty, to start picking up the lines after ";"
			}
		}
	}
}

// recursively delete directory
function delTree($dir) {
   $files = array_diff(scandir($dir), array('.','..'));
    foreach ($files as $file) {
      (is_dir("$dir/$file")) ? delTree("$dir/$file") : unlink("$dir/$file");
    }
    return rmdir($dir);
}

// recursively delete directory (slightly different)
function rrmdir($src) {
    $dir = opendir($src);
    while(false !== ( $file = readdir($dir)) ) {
        if (( $file != '.' ) && ( $file != '..' )) {
            $full = $src . '/' . $file;
            if ( is_dir($full) ) {
                rrmdir($full);
            }
            else {
                unlink($full);
            }
        }
    }
    closedir($dir);
    rmdir($src);
}

// moves files from temp directory ./coretemp/wordpress and overwrites existing core wordpress files
function replaceCore($src) {
	$filelist = glob($src . "/*");
	foreach ($filelist as $file) {
		$dst = substr($file, 19);
		if (is_dir($file)) {
			if (file_exists($dst) == FALSE) {
				mkdir($dst);
			}
			replaceCore($file);
		} else {
			rename($file, $dst);
		}
	}
}

// generates password for admin user creation
function generatePassword($length = 10) {
    $chars = 'abcdefghijklmnopqrstuvwxyzABCDEFGHIJKLMNOPQRSTUVWXYZ0123456789!@#$%^&)(';
    $count = mb_strlen($chars);
    for ($i = 0, $result = ''; $i < $length; $i++) {
        $index = rand(0, $count - 1);
        $result .= mb_substr($chars, $index, 1);
    }
    return $result;
}

// gets list of all files in directory recursively with path relative to WordPress install directory, does not include folder names
function getFileList($dir) {
	$filelist = new RecursiveIteratorIterator(new RecursiveDirectoryIterator($dir), RecursiveIteratorIterator::SELF_FIRST);
	foreach ($filelist as $file) {
		if ($file->getFilename() !== ".." && $file->getFilename() !== "." && is_dir($file) !== TRUE) {
			$filearray[] = $file->getPathname();
		}
	}
	return $filearray;
}

// hashes plaintext password using wordpress's own hashing function
function wp_hash_password($password) {
	global $wp_hasher;

	if ( empty($wp_hasher) ) {
		// require_once( ABSPATH . WPINC . '/class-phpass.php');
		// By default, use the portable hash from phpass
		$wp_hasher = new PasswordHash(8, true);
	}

	return $wp_hasher->HashPassword( trim( $password ) );
}

/*********************************** Setup / Obtaining Site Info ***********************/

// Get database connection strings, table prefix, version
$configfile = file_get_contents("wp-config.php");
$version = file_get_contents("wp-includes/version.php");

$start = strpos($configfile, "DB_NAME") + 11; $end = strpos($configfile, "');", $start);
$wpconfig['DB_NAME'] = substr($configfile, $start, $end - $start);
$start = strpos($configfile, "DB_USER") + 11; $end = strpos($configfile, "');", $start);
$wpconfig['DB_USER'] = substr($configfile, $start, $end - $start);
$start = strpos($configfile, "DB_PASSWORD") + 15; $end = strpos($configfile, "');", $start);
$wpconfig['DB_PASSWORD'] = substr($configfile, $start, $end - $start);
$start = strpos($configfile, "DB_HOST") + 11; $end = strpos($configfile, "');", $start);
$wpconfig['DB_HOST'] = substr($configfile, $start, $end - $start);
$start = strpos($configfile, "table_") + 17; $end = strpos($configfile, "';", $start);
$wpconfig['PREFIX'] = substr($configfile, $start, $end - $start);
$start = strpos($version, "wp_version =") + 14; $end = strpos($version, "';", $start);
$wpconfig['WP_VER'] = substr($version, $start, $end - $start);

// Check for custom port number, if none default to 3306
$portpos = strpos($wpconfig['DB_HOST'], ':');
if($portpos !== FALSE) {
	$portpos = $portpos + 1;
	$wpconfig['DB_PORT'] = substr($wpconfig['DB_HOST'], $portpos);
	$portpos = $portpos - 1;
	$wpconfig['DB_HOST'] = substr($wpconfig['DB_HOST'], 0, $portpos);
} else {
	$wpconfig['DB_PORT'] = "3306";
}

// Connect to database with mysqli if available, PDO if not
if (function_exists("mysqli_connect") == true){
	$dbconn = new mysqli($wpconfig['DB_HOST'], $wpconfig['DB_USER'], $wpconfig['DB_PASSWORD'], $wpconfig['DB_NAME'], $wpconfig['DB_PORT']);
} else {
	$dbconn = new PDO('mysql:host='.$wpconfig["DB_HOST"].';port='.$wpconfig["DB_PORT"].';dbname='.$wpconfig["DB_NAME"], $wpconfig['DB_USER'], $wpconfig['DB_PASSWORD']);
}

// Check database connection
if ($dbconn->connect_error) {
	die("Connection failed: " . $dbconn->connect_error);
}

// Check to see if database has wordpress content
$dbcheck = $dbconn->query('SELECT 1 FROM `' . $wpconfig['PREFIX'] . 'options` LIMIT 1');

// Get and format data from options table
$options['siteurl'] = "SELECT option_value FROM " . $wpconfig['PREFIX'] . "options WHERE option_name='siteurl'";
$options['home'] = "SELECT option_value FROM " . $wpconfig['PREFIX'] . "options WHERE option_name='home'";
$options['template'] = "SELECT option_value FROM " . $wpconfig['PREFIX'] . "options WHERE option_name='template'";
$options['stylesheet'] = "SELECT option_value FROM " . $wpconfig['PREFIX'] . "options WHERE option_name='stylesheet'";
$options['permalink_structure'] = "SELECT option_value FROM " . $wpconfig['PREFIX'] . "options WHERE option_name='permalink_structure'";
$options['active_plugins'] = "SELECT option_value FROM " . $wpconfig['PREFIX'] . "options WHERE option_name='active_plugins'";
$options['upload_path'] = "SELECT option_value FROM " . $wpconfig['PREFIX'] . "options WHERE option_name='upload_path'";
$options['db_version'] = "SELECT option_value FROM " . $wpconfig['PREFIX'] . "options WHERE option_name='db_version'";
if ($dbcheck !== false) {
	foreach ($options as &$value) {
		$value = $dbconn->query($value);
		$value = $value->fetch_assoc();
		$value = $value['option_value'];
	}
unset($value);
}

// Check if SATT has already created an admin user
if ($dbcheck !== false) {
	$admin_present = "SELECT count(*) AS total FROM " . $wpconfig['PREFIX'] . "users WHERE ID=9135";
	$admin_present = $dbconn->query($admin_present);
	$admin_present = $admin_present->fetch_assoc();
	$admin_present = $admin_present['total'];
} else {
	$admin_present = 0;
}

// Get SATT-created user's name and hashed password
if ($admin_present == 1){
	$admin_info = $dbconn->query("SELECT * FROM ".$wpconfig['PREFIX']."users WHERE ID='9135';");
	$admin_info = $admin_info->fetch_assoc();
}

// Create array with list of installed themes
$installedThemes = (glob("wp-content/themes/*", GLOB_ONLYDIR));
foreach ($installedThemes as &$value){
	$value = substr($value, 18);
}
unset($value);

// Create array with list of active plugins (unless no plugins are active) and count them
if ($dbcheck !== false) {
	$array_active_plugins = unserialize($options['active_plugins']);
	$active_plugin_count = count($array_active_plugins);
}

// Set plugin path, grab any plugins without subdirectories
$plugpath = "wp-content/plugins/*";
$phpfiles = glob($plugpath . ".php");
$plugdirs = glob($plugpath, GLOB_ONLYDIR);

// Add php files in plugin directories to list
foreach($plugdirs as $value) {
	$phpfiles_new = glob($value . "/*.php");
	$phpfiles = array_merge($phpfiles, $phpfiles_new);
}
unset($value);

// Isolate plugin-containing php files, format paths, create array where $key = Plugin Name and $value = pluginpath.php
foreach($phpfiles as $value) {
	$fstream = fopen($value, "r");
	$plugfile = fread($fstream, 8192);
	$plugfile_pos = strpos($plugfile, "Plugin Name:");
	if($plugfile_pos !== false) {
		$plugfile_path = str_replace("wp-content/plugins/", "", $value);
		$installed_plugins[] = $plugfile_path;
		$plugname = substr($plugfile, $plugfile_pos, strpos($plugfile, "\n", $plugfile_pos) - $plugfile_pos);
		$plugname = str_replace("Plugin Name: ", "", $plugname);
		$plugnamearray[] = $plugname;
	}
}
unset($value);
$installed_plugins = array_combine($plugnamearray, $installed_plugins);

// Check for available .sql files for restoring db backups
$sqlfiles = glob('*.sql');

// Get user list from database
$users_query_results = $dbconn->query("SELECT * FROM ".$wpconfig['PREFIX']."users");
while ($row = $users_query_results->fetch_assoc()){
	$users[] = $row;
}
$users_query_results->free();

/************************* Begin AJAX Handling **********************/

// AJAX handling for installing twentysixteen theme
if (isset($_POST['install2016'])){
	$corefilename = "wordpress-" . $wpconfig['WP_VER'] . ".zip";
	file_put_contents($corefilename, file_get_contents("https://wordpress.org/" . $corefilename));
	$corestream = new ZipArchive;
	$corestream->open($corefilename);
	$corestream->extractTo('./coretemp/');
	$corestream->close();
	unset($corestream);
	mkdir('wp-content/themes/twentysixteen');
	replaceCore('coretemp/wordpress/wp-content/themes/twentysixteen');
	delTree("coretemp");
	exit();
}

// AJAX handling for updating options table
if (isset($_POST['options_update'])){
	$newoptions = $_POST['options_update'];
	foreach ($newoptions as $name => &$value){
		$optionsQuery = "UPDATE " . $wpconfig['PREFIX'] . "options SET option_value='" . $value . "' WHERE option_name='" . $name . "'";
		if ($dbconn->query($optionsQuery)){
			// nothin
		} else {
			$value = "error has occurred";
		}
	}
	echo json_encode($newoptions);
	unset($_POST['options_update']);
	exit();
}

// AJAX handling for updating plugins
if (isset($_POST['update_plugins'])){
	if ($_POST['update_plugins'] !== "none"){
		$newPlugins = serialize($_POST['update_plugins']);
		$pluginsQuery = "UPDATE " . $wpconfig['PREFIX'] . "options SET option_value='" . $newPlugins . "' WHERE option_name='active_plugins'";
		if ($dbconn->query($pluginsQuery) !== FALSE){
			echo "Active plugins have been updated successfully!";
		} else {
			echo "Updating active plugins failed!";
		}
	} else {
		$newPlugins = "a:0:{}";
		$pluginsQuery = "UPDATE " . $wpconfig['PREFIX'] . "options SET option_value='" . $newPlugins . "' WHERE option_name='active_plugins'";
		if ($dbconn->query($pluginsQuery) !== FALSE){
			echo "ok";
		}
	}
	exit();
}

// AJAX handling for disabling all plugins
if (isset($_POST['disable_plugins']) && $_POST['disable_plugins'] == "none"){
	$tempfile = fopen("satt.txt", "w");
	fwrite($tempfile, $options['active_plugins']);
	fclose($tempfile);
	$newPlugins = "a:0:{}";
	$disablePluginsQuery = "UPDATE " . $wpconfig['PREFIX'] . "options SET option_value='" . $newPlugins . "' WHERE option_name='active_plugins'";
	if ($dbconn->query($disablePluginsQuery) !== FALSE){
		echo "ok";
	}
	exit();
}

// AJAX handling for restoring plugins
if (isset($_POST['restore_plugins']) && $_POST['restore_plugins'] == "restore"){
	$tempfile = fopen("satt.txt", "r");
	$restoredPlugs = fread($tempfile, filesize("satt.txt"));
	$restorePluginsQuery = "UPDATE " . $wpconfig['PREFIX'] . "options SET option_value='" . $restoredPlugs . "' WHERE option_name='active_plugins'";
	$dbconn->query($restorePluginsQuery);
	echo json_encode(unserialize($restoredPlugs));
	unlink("satt.txt");
	exit();
}

// AJAX handling for dumping database
if (isset($_POST['database_dump']) && $_POST['database_dump'] == "dbdump") {
	$filename = backup_tables($dbconn);
	echo $filename;
	exit();
}

// AJAX handling for importing database
if (isset($_POST['database_import'])){
	import_tables($dbconn,$_POST['database_import']);
	echo "something about success and such";
	exit();
}

// AJAX handling for tarballing
if (isset($_POST['tarball'])){
	$sqlbackuppath = backup_tables($dbconn);
	rename($sqlbackuppath, "wp-content/".$sqlbackuppath);
	$todaysdate = date("Y-m-d");
	$tarstream = new PharData('satt-backup-' . $todaysdate . '.tar');
	$tarstream->buildFromDirectory("wp-content");
	unset($tarstream);
	$tarstream = gzopen('satt-backup-' . $todaysdate . '.tar.gz', 'w9');
	gzwrite($tarstream, file_get_contents('satt-backup-' . $todaysdate . '.tar'));
	gzclose($tarstream);
	unlink('satt-backup-' . $todaysdate . '.tar');
	unlink("wp-content/".$sqlbackuppath);
	echo "satt-backup-" . $todaysdate . ".tar.gz";
	exit();
}

// AJAX handling for killing PHP processes
if (isset($_POST['killProcesses'])){
	$fileowner = shell_exec("ls -ld ".__FILE__." | awk '{print \$3}'");
	shell_exec('pkill -U '.$fileowner.';');
	echo $fileowner;
	exit();
}

// AJAX handling for clearing Varnish cache
if (isset($_POST['clearCache'])){
	shell_exec("wp godaddy cache flush");
	echo $_SERVER['HTTP_HOST'];
	exit();
}

// AJAX handling for creating ini
if (isset($_POST['inivalues'])){
	$iniarray = $_POST['inivalues'];
	$filename = $iniarray['inifilename'];
	unset($iniarray['inifilename']);
	$newinicontent = "";
	foreach ($iniarray as $name => $value){
		$newinicontent .= $name . " = " . $value . "\n";
	}
	$inifile = fopen($filename, "w");
	fwrite($inifile, $newinicontent);
	fclose($inifile);
	echo json_encode($newinicontent);
	exit();
}

// AJAX handling for refreshing ini values
if (isset($_POST['inirefresh'])){
	$values = $_POST['inirefresh'];
	foreach($values as $value){
		$newvalues[$value] = ini_get($value);
	}
	echo json_encode($newvalues);
	exit();
}

// AJAX handling for replacing core files
if (isset($_POST['replace_core'])){
	$corefilename = "wordpress-" . $wpconfig['WP_VER'] . ".zip";
	file_put_contents($corefilename, file_get_contents("https://wordpress.org/" . $corefilename));
	$corestream = new ZipArchive;
	$corestream->open($corefilename);
	$corestream->extractTo('./coretemp/');
	$corestream->close();
	unset($corestream);
	rrmdir('coretemp/wordpress/wp-content');
	$corefilelist = getFileList('coretemp/wordpress');
	$corebackup = new ZipArchive;
	$corebackup->open("corebackup.zip", ZipArchive::CREATE);
	foreach ($corefilelist as &$file) {
		$file = substr($file, 19);
		$corebackup->addFile($file);
	}
	unset($file);
	$corebackup->close();
	replaceCore('coretemp/wordpress');
	delTree("coretemp");
	unlink($corefilename);
	exit();
}

// AJAX handling for restoring original core files
if (isset($_POST['restore_core'])){
	$corestream = new ZipArchive;
	$corestream->open("corebackup.zip");
	$corestream->extractTo('./coretemp/wordpress');
	$corestream->close();
	unset($corestream);
	replaceCore('coretemp/wordpress');
	delTree('coretemp');
	unlink('corebackup.zip');
	exit();
}

// AJAX handling for adding admin user
if (isset($_POST['username'])){
	$admin_user = $_POST['username'];
	include("wp-includes/class-phpass.php");
	$admin_pass = wp_hash_password($_POST['password']);
	$admin_add = "INSERT INTO " . $wpconfig['PREFIX'] . "users (ID,user_login,user_pass,user_email) VALUES ('9135','" . $admin_user . "','" . $admin_pass . "','donotreply@godaddy.com');";
	$admin_add .= "INSERT INTO " . $wpconfig['PREFIX'] . "usermeta (umeta_id,user_id,meta_key,meta_value) VALUES ('NULL','9135','nickname','" . $admin_user . "');";
	$admin_add .= "INSERT INTO " . $wpconfig['PREFIX'] . "usermeta (umeta_id,user_id,meta_key,meta_value) VALUES ('NULL','9135','rich_editing','true');";
	$admin_add .= "INSERT INTO " . $wpconfig['PREFIX'] . "usermeta (umeta_id,user_id,meta_key,meta_value) VALUES ('NULL','9135','comment_shortcuts','false');";
	$admin_add .= "INSERT INTO " . $wpconfig['PREFIX'] . "usermeta (umeta_id,user_id,meta_key,meta_value) VALUES ('NULL','9135','admin_color','fresh');";
	$admin_add .= "INSERT INTO " . $wpconfig['PREFIX'] . "usermeta (umeta_id,user_id,meta_key,meta_value) VALUES ('NULL','9135','use_ssl','0');";
	$admin_add .= "INSERT INTO " . $wpconfig['PREFIX'] . "usermeta (umeta_id,user_id,meta_key,meta_value) VALUES ('NULL','9135','" . $wpconfig['PREFIX'] . "capabilities','a:1:{s:13:\"administrator\";s:1:\"1\";}');";
	$admin_add .= "INSERT INTO " . $wpconfig['PREFIX'] . "usermeta (umeta_id,user_id,meta_key,meta_value) VALUES ('NULL','9135','" . $wpconfig['PREFIX'] . "user_level','10');";
	$admin_add .= "INSERT INTO " . $wpconfig['PREFIX'] . "usermeta (umeta_id,user_id,meta_key,meta_value) VALUES ('NULL','9135','show_admin_bar_front','true');";
	$dbconn->multi_query($admin_add);
	$admin_info = array(
		"user_login" => $admin_user,
		"user_pass" => $admin_pass,
		"user_email" => "donotreply@godaddy",
	);
	echo json_encode($admin_info);
	exit();
}

// AJAX handling for removing admin user
if (isset($_POST['remove_user'])){
	$admin_remove = "DELETE FROM " . $wpconfig['PREFIX'] . "users WHERE ID='9135';";
	$admin_remove .= "DELETE FROM " . $wpconfig['PREFIX'] . "usermeta WHERE user_id='9135';";
	$dbconn->multi_query($admin_remove);
	exit();
}

// AJAX handling for Password Generator
if (isset($_GET['passgen']) && $_GET['passgen'] == "request"){
	echo generatePassword();
	unset($_GET['passgen']);
	exit();
}

// AJAX handling for Updating User Password
if (isset($_POST['newPass']) && isset($_POST['userName'])){
	$newPass = $_POST['newPass'];
	$userName = $_POST['userName'];
	if (strlen($newPass) < 32){
		include("wp-includes/class-phpass.php");
		$newPass = wp_hash_password($newPass);
	}
	$dbconn->query("UPDATE ".$wpconfig['PREFIX']."users SET user_pass='".$newPass."' WHERE user_login='".$userName."';");
	echo $newPass;
	exit();
}

// AJAX handling for Updating User Email
if (isset($_POST['newEmail']) && isset($_POST['userName'])){
	$newEmail = $_POST['newEmail'];
	$userName = $_POST['userName'];
	$dbconn->query("UPDATE ".$wpconfig['PREFIX']."users SET user_email='".$newEmail."' WHERE user_login='".$userName."';");
	echo $newEmail;
	exit();
}

// AJAX handling for Remove SATT potato
if (isset($_GET['killsatt']) && $_GET['killsatt'] == "yes"){
	$path = dirname(__FILE__);
	if (strncasecmp(PHP_OS, 'WIN', 3) == 0) {
		exec("del /f " . $path . "\satt.php");
	} else {
		shell_exec("rm -f " . $path . "/corebackup.zip"); // delete temp files first! no file left behind.
		shell_exec("rm -f " . $path . "/sattcreds.txt");
		shell_exec("rm -f " . $path . "/satt.txt");
		shell_exec("rm -f " . $path . "/satt.php");
		echo "ok";
		exit();
	}
}
/*************************************** Begin CSS / HTML Layout ****************************/
?>

<html><head><meta charset="UTF-8"><title>SATT</title>
<style>
* {
	font-family: "Trebuchet MS", Helvetica, sans-serif;
  -webkit-box-sizing: border-box;
     -moz-box-sizing: border-box;
          box-sizing: border-box;
}

body {
	margin-right: 0px;
}

.notifyjs-corner {
	margin-right: 0px !important;
}

.notifyjs-wrapper {
	margin-right: 0px !important;
}

.notifyjs-corner {
	margin-right: 0px !important;
}

.notifyjs-container {
	margin-right: 0px !important;
}

.button {
	margin: 0px;
	transition-duration: 0.4s;
	-webkit-transition-duration: 0.4s;
	background-color: #0066ff; /* Blue */
	border: none;
	color: white;
	padding: 5px;
	text-align: center;
}

.button.adduser {
	height: 60px;
	margin-right: 5px !important;
}

.button.removeuser {
	position: absolute;
	top: 10px;
	right: 5px;
	height: 60px;
}

.button.login {
	text-decoration: none;
	font-weight: normal;
	font-size: 13px;
	padding: 22px;
	position: absolute;
	top: 10px;
	right: 115px;
	width: 80px;
	height: 60px;
}

.button:hover {
	background-color: #0047b3;
	cursor: pointer;
}

.button.options {
	float: right;
}

.button.plugins {
	float: left;
	margin-right: 5px;
}

.button.dbarea {
	width: 120px;
	margin-right: 5px;
}

.button.phparea {
	width: 280px;
	margin-right: 5px;
}

.button.corereplace {
	position: absolute;
	margin: none;
	padding: none;
	overflow: hidden;
	top: 10px;
	right: 5px;
	display: inline-block;
	height: 60px;
}

.button.submitini {
	float: right;
}

button.visitsite {
	position: absolute;
	height: 60px;
	right: 5px;
	top: 10px;
}

button.visitlogin {
	position: absolute;
	height: 60px;
	right: 79px;
	top: 10px;
}

.button.insert2016 {
	position: absolute;
	left: -165px;
	top: -4px;
}

div#phptopbuttons {
	text-align: center;
}

div#dbcontent {
	padding-top: 10px;
}

div#phpcontent {
	padding-top: 10px;
}

div#dbcontent li {
	margin-left: 5px;
	margin-bottom: 5px;
}

div#phpcontent li {
	margin-left: 5px;
	margin-bottom: 5px;
	display: inline;
}

.edit-button {
	position: absolute;
	right: 0px;
	color: #0066ff;
}

.edit-button:hover {
	cursor: pointer;
}

.save-button {
	position: absolute;
	right: 0px;
	color: #0066ff;
}

.save-button:hover {
	cursor: pointer;
}

.cancel-button {
	position: absolute;
	right: 30px;
	color: #ff0000;
}

.cancel-button:hover {
	cursor: pointer;
}

#inimakertitle {
	text-align: center;
}

.innerWrap {
	height: 100%;
	width: 100%;
	position: relative;
}

.innerWrap2 {
	height: 100%;
	width: 100%;
	float: left;
}

.content {
	width: 810px;
	border-left: 5px solid black;
	border-right: 5px solid black;
	border-bottom: 5px solid black;
	margin: auto;
	border-collapse: collapse;
	background-color: #e6ffff;
	list-style: none;
}

#dblink {
	display: inline;
	float: right;
	margin-right: 5px;
}

#existing_ini_info {
	float: left;
	width: 450px;
	color: red;
}

.infoIcon {
	position: absolute;
	left: -50px;
	top: 0px;
	border-style: solid;
	border-radius: 11px;
	border-width: 2px;
	width: 20px;
	height: 20px;
	text-align: center;
	font-size: 90%;
	font-weight: 600;
	font-style: italic;
	font-family: "Times New Roman", Times, serif;
	color: #0066ff;
}

.infoIcon:hover {
	cursor: pointer;
}

.infoDiv {
	position: relative;
}

#inifilename {
	float: right;
	margin-right: 5px;
}

#inirefresh {
	position: absolute;
	right: 20px;
	top: 8px;
	line-height: 1.7em;
	font-size: 1em;
	color: #0066ff;
}

#inirefresh:hover {
	cursor: pointer;
}

.modify-password-input {
	width: 300px;
}

.modify-email-input {
	width: 175px;
}

#optionstable select {
	width: 150px;
	border: 1px solid blue;
	border-radius: 3px;
}

#tarlink {
	display: inline;
	float: right;
	margin-right: 5px;
}

.sectionheader {
	position: relative;
	width: 840px;
	height: 80px;
	margin: auto;
	background-color: #cce6ff;
	color: #0066ff;
	font-weight: bold;
	text-decoration: none;
	padding: 0px;
	font-size: 28px;
	box-shadow: 0 0 5px 2px rgba(0,0,0,.35);
}

.sectiontitle {
	margin: 0px;
	display: block;
	padding: 22px;

}

#pluginswitcher {
	width: 810px;
	border: 5px solid black;
	border-top: none;
	margin: auto;
	border-collapse: collapse;
	background-color: #e6ffff;
}

#pluginswitchcontent {
	margin: auto;
	padding: 20px;
	-moz-column-count: 3;
	-moz-column-gap: 1em;
	-webkit-column-count: 3;
	-webkit-column-gap: 1em;
	column-count: 3;
	column-gap: 1em;
}

td {
	border: 2px solid black;
	padding-left: 10px;
	padding-right: 10px;
	padding-top: 10px;
	padding-bottom: 10px;
}

td.header {
	border-top: none;
	color: blue;
	font-size: large;
	font-weight: bold;
}

td.header.currentiniheader {
	padding-right: 5px;
	position: relative;
}

table.ini td:nth-child(2) {
	width: 300px;
}

#num_plugins {
	padding-top: 4px;
	display: inline-block;
}

.inputbox {
	margin-right: 5px;
	border: 1px solid blue;
	width: 150px;
	padding: 1;
	border-radius: 3px;
}

.inputbox.admin {
	position: relative;
	margin-top: 0px;
	margin-left: 4px;
	display: inline-block;
}

.inputbox.ini {
	width: 300px;
}

.adminuserpass {
	margin: 0px;
	padding: 0px;
	overflow: hidden;
	float: right;
	line-height: 100%;
	font-size: 22px;
}

.versionnotify {
	margin: 0px;
	padding: 0px;
	overflow: hidden;
	line-height: 100%;
	font-size: 18px;
}

.nodbcontent {
	margin: 0px;
	padding: 0px;
	overflow: hidden;
	line-height: 100%;
	font-size: 18px;
}

.nodbcontentdiv {
	position: absolute;
	margin: none;
	padding: none;
	overflow: hidden;
	top: 30px;
	right: 5px;
	display: inline-block;
}

#userpassinputdiv {
	position: absolute;
	margin: none;
	padding: none;
	overflow: hidden;
	top: 10px;
	right: 0px;
	display: inline-block;
}

#currentPhpVersion {
	position: absolute;
	right: 15px;
	top: 28px;
	font-size: 18px;
}

#versionnotifydiv {
	position: absolute;
	margin: none;
	padding: none;
	overflow: hidden;
	top: 20px;
	right: 150px;
	display: inline-block;
}

#removeSATT {
    position:fixed;
    right:25;
    bottom:25;
	text-align: center;
	max-width: 150px;
}

#removeSuccess {
	position: fixed;
	right: 0;
	bottom: 0;
}

</style>
<script type="text/javascript" src="https://ajax.googleapis.com/ajax/libs/jquery/1.12.4/jquery.min.js"></script>
<script type="text/javascript" src="https://cdnjs.cloudflare.com/ajax/libs/notify/0.4.2/notify.min.js"></script>
<script src="https://use.fontawesome.com/1e202d39a8.js"></script>

</head>
<body>
<!-- Interface -->

<h1 style="text-align:center;">SATT</h1>

<div id="optionsheader" class="sectionheader">
	<span class="sectiontitle">Options Table</span>
	<a href="<?php echo $options['siteurl']; ?>" target="_blank" id="visitsitelink"><button class="button visitsite">Visit Site</button></a>
	<a href="<?php echo rtrim($options['siteurl'], '/') . "/wp-admin"; ?>" target="_blank" id="visitloginlink"><button class="button visitlogin">Visit Login</button></a>
</div>
<div id="hideoptions">
<table id="optionstable" class="content">
	<tr>
		<td class="header">Option Name</td>
		<td class="header">Current Value</td>
		<td class="header">New Value</td>
	</tr>
	<tr>
		<td>siteurl</td>
		<td id="siteurl"><?php echo $options['siteurl']; ?></td>
		<td><input class="inputbox" type="text" name="siteurl" maxlength="120"></td>
	</tr><tr>
		<td>home</td>
		<td id="home"><?php echo $options['home']; ?></td>
		<td><input class="inputbox" type="text" name="home" maxlength="120"></td>
	</tr>
	<tr>
		<td>template</td>
		<td id="template"><?php echo $options['template']; ?></td>
		<td><div class="infoDiv"><button id="install2016" class="button insert2016">Install twentysixteen</button>
		<select id="templateSelect" name="template">
		<option value="">Select...</option>
		<?php foreach($installedThemes as $theme){echo "<option value=\"" . $theme . "\">" . $theme . "</option>";} ?>
		</select></div>
		</td>
	</tr>
	<tr>
		<td>stylesheet</td>
		<td id="stylesheet"><?php echo $options['stylesheet']; ?></td>
		<td><div class="infoDiv">
			<select id="stylesheetSelect" name="stylesheet">
			<option value="">Select...</option>
			<?php foreach($installedThemes as $theme){echo "<option value=\"" . $theme . "\">" . $theme . "</option>";} ?>
			</select></div>
		</td>
	</tr>
	<tr>
		<td>permalink_structure</td>
		<td id="permalink_structure"><?php echo $options['permalink_structure']; ?></td>
		<td><input class="inputbox" type="text" name="permalink_structure" placeholder="Please don't." maxlength="120"></td>
	</tr>
	<tr>
		<td>upload_path</td>
		<td id="upload_path"><?php echo $options['upload_path']; ?></td>
		<td><input class="inputbox" type="text" name="upload_path" maxlength="120"></td>
	</tr>
	<tr>
		<td>db_version</td>
		<td id="db_version"><?php echo $options['db_version']; ?></td>
		<td><input class="inputbox" type="text" name="db_version" maxlength="60"></td>
	</tr>
	<tr class="header">
		<td colspan="3">
			<button class="button options" id="submitoptions">&#x25B2;Update Options</button>
			<button class="button plugins" id="update_plugins">&#x25BC;Update Plugins</button>
			<button class="button plugins" id="disable_plugins">Disable All Plugins</button>
			<button class="button plugins" id="restore_plugins">Restore Original Plugins</button>
			<span id="num_plugins"><?php echo $active_plugin_count." plugins active."; ?></span>
		</td>
	</tr>
</table>
<div id="pluginswitcher">
	<div id="pluginswitchcontent"><?php
foreach ($installed_plugins as $key => $value) {
	if (!empty($array_active_plugins) && in_array($value, $array_active_plugins)) {
		$checked = "checked";
	} else {
		$checked = "";
	}
	echo "<input type=\"checkbox\" name=\"aPlug[]\" value=\"" . $value . "\"" . $checked . ">" . $key . "<br>";
}
unset($value);
?></div>
</div>


</div> <!-- close id="hideoptions" -->
<div id="dbheader" class="sectionheader">
<span class="sectiontitle">Backup & Restore</span>
<?php
if ($dbcheck !== false) {
	} else {
		echo "<div class=\"nodbcontentdiv\"><span class=\"nodbcontent\">No WordPress content in database!</span></div>";
} ?>

</div>
<div id="hidedb">
<div id="dbcontent" class="content">
	<li><button class="button dbarea" id="database_dump">Database Dump</button>Create a backup of the current WordPress database.<div id="dblink"></div></li>
	<li><button class="button dbarea" id="database_import">Database Import</button>Drop all tables and import from backup.
	<?php
	if (empty($sqlfiles)) {
		echo "<select><option value=\"\">No SQL Files</option></select>";
	} else {
		echo "<select name=\"sqlfile\"><option value=\"\">Select...</option>";
		foreach ($sqlfiles as $file) {
			echo "<option value=\"" . $file . "\">" . $file . "</option>";
		}
		echo "</select>";
	} ?></li>
	<li><button class="button dbarea" id="tarball">Tarball</button>Archive/Compress wp-content and sql dump for site migration.<div id="tarlink"></div></li>
</div> <!-- close dbcontent div-->
</div> <!-- close hidden content div -->
<div id="phpheader" class="sectionheader">
	<span class="sectiontitle">PHP / Caching</span>
	<div id="currentPhpVersion"><?php echo "PHP Version: " . phpversion(); ?></div>
</div> <!-- close sectionheader div -->
<div id="phpcontent" class="content">
	<div id="phptopbuttons">
		<button class="button phparea" id="killProcesses">Kill PHP Processes</button>
		<button class="button phparea" id="clearCache">Clear Varnish (MWP Only)</button>
	</div><br>
	<div id="inimakertitle">Create a PHP Initialization File</div>
</div>
<div id="inimaker">
	<table id="inimakertable" class="content ini">
		<tr>
			<td colspan=3>
				<div id="existing_ini_info"></div>
				<button class="button submitini" id="createini">Create ini</button>
				<select id="inifilename" name="inifilename">
					<option value="select">Filename...</option>
					<option value="php.ini">php.ini (cPanel/MWP)</option>
					<option value="php5.ini">php5.ini (2gh/4gh)</option>
					<option value=".user.ini">.user.ini (cPanel/MWP/Plesk)</option>
				</select>
			</td>
		</tr>
		<tr>
			<td class="header">Property</td>
			<td class="header currentiniheader">Current Value<i class="fa fa-refresh fa-lg" id="inirefresh"></td>
			<td class="header">New Value</td>
		</tr>
		<tr>
			<td>memory_limit</td>
			<td class="currentini" name="memory_limit"><?php echo ini_get('memory_limit'); ?></td>
			<td><div class="infoDiv"><input class="inputbox ini" type="text" name="memory_limit"><div class="infoIcon" name="memory_limit">i</div></div></td>
		</tr>
		<tr>
			<td>max_execution_time</td>
			<td class="currentini" name="max_execution_time"><?php echo ini_get('max_execution_time'); ?></td>
			<td><div class="infoDiv"><input class="inputbox ini" type="text" name="max_execution_time"><div class="infoIcon" name="max_execution_time">i</div></div></td>
		</tr>
		<tr>
			<td>max_input_time</td>
			<td class="currentini" name="max_input_time"><?php echo ini_get('max_input_time'); ?></td>
			<td><div class="infoDiv"><input class="inputbox ini" type="text" name="max_input_time"><div class="infoIcon"  name="max_input_time">i</div></div></td>
		</tr>
		<tr>
			<td>post_max_size</td>
			<td class="currentini" name="post_max_size"><?php echo ini_get('post_max_size'); ?></td>
			<td><div class="infoDiv"><input class="inputbox ini" type="text" name="post_max_size"><div class="infoIcon" name="post_max_size">i</div></div></td>
		</tr>
		<tr>
			<td>max_input_vars</td>
			<td class="currentini" name="max_input_vars"><?php echo ini_get('max_input_vars'); ?></td>
			<td><div class="infoDiv"><input class="inputbox ini" type="text" name="max_input_vars"><div class="infoIcon" name="max_input_vars">i</div></div></td>
		</tr>
		<tr>
			<td>file_uploads</td>
			<td class="currentini" name="file_uploads"><?php echo ini_get('file_uploads'); ?></td>
			<td><div class="infoDiv"><input class="inputbox ini" type="text" name="file_uploads"><div class="infoIcon" name="file_uploads">i</div></div></td>
		</tr>
		<tr>
			<td>max_file_uploads</td>
			<td class="currentini" name="max_file_uploads"><?php echo ini_get('max_file_uploads'); ?></td>
			<td><div class="infoDiv"><input class="inputbox ini" type="text" name="max_file_uploads"><div class="infoIcon" name="max_file_uploads">i</div></div></td>
		</tr>
		<tr>
			<td>upload_max_filesize</td>
			<td class="currentini" name="upload_max_filesize"><?php echo ini_get('upload_max_filesize'); ?></td>
			<td><div class="infoDiv"><input class="inputbox ini" type="text" name="upload_max_filesize"><div class="infoIcon" name="upload_max_filesize">i</div></div></td>
		</tr>
		<tr>
			<td>output_buffering</td>
			<td class="currentini" name="output_buffering"><?php echo ini_get('output_buffering'); ?></td>
			<td><div class="infoDiv"><input class="inputbox ini" type="text" name="output_buffering"><div class="infoIcon" name="output_buffering">i</div></div></td>
		</tr>
		<tr>
			<td>error_reporting</td>
			<td class="currentini" name="error_reporting"><?php echo ini_get('error_reporting'); ?></td>
			<td><div class="infoDiv"><input class="inputbox ini" type="text" name="error_reporting"><div class="infoIcon" name="error_reporting">i</div></div></td>
		</tr>
		<tr>
			<td>display_errors</td>
			<td class="currentini" name="display_errors"><?php echo ini_get('display_errors'); ?></td>
			<td><div class="infoDiv"><input class="inputbox ini" type="text" name="display_errors"><div class="infoIcon" name="display_errors">i</div></div></td>
		</tr>
		<tr>
			<td>log_errors</td>
			<td class="currentini" name="log_errors"><?php echo ini_get('log_errors'); ?></td>
			<td><div class="infoDiv"><input class="inputbox ini" type="text" name="log_errors"><div class="infoIcon" name="log_errors">i</div></div></td>
		</tr>
		<tr>
			<td>error_log</td>
			<td class="currentini" name="error_log"><?php echo ini_get('error_log'); ?></td>
			<td><div class="infoDiv"><input class="inputbox ini" type="text" name="error_log"><div class="infoIcon" name="error_log">i</div></div></td>
		</tr>
		<tr>
			<td>date.timezone</td>
			<td class="currentini" name="date.timezone"><?php echo ini_get('date.timezone'); ?></td>
			<td><div class="infoDiv"><input class="inputbox ini" type="text" name="date.timezone"><div class="infoIcon" name="date.timezone">i</div></div></td>
		</tr>
		<tr>
			<td>short_open_tag</td>
			<td class="currentini" name="short_open_tag"><?php echo ini_get('short_open_tag'); ?></td>
			<td><div class="infoDiv"><input class="inputbox ini" type="text" name="short_open_tag"><div class="infoIcon" name="short_open_tag">i</div></div></td>
		</tr>
	</table>
</div>


<div id="coreheader" class="sectionheader"><span class="sectiontitle">Replace Core Files</span>
	<div id="versionnotifydiv"><span class="versionnotify"><?php echo "Current WordPress<br>Version: " . $wpconfig['WP_VER']; ?></span></div>
	<button class="button corereplace" id="replace_core">Replace Core Files</button>
	<button class="button corereplace" id="restore_core">Restore Originals</button>
</div> <!-- close coreheader div -->
<div id="adminheader" class="sectionheader"><span class="sectiontitle">Create Admin User</span>
	<button class="button removeuser" id="removeuserbutton">Remove User</button>
	<?php echo "<a class=\"button login\" href=\"" . $options['siteurl'] . "/wp-admin\" target=\"_blank\" id=\"loginbutton\">Login</a>"; ?>
	<div id="userpassinputdiv"><span class="adminuserpass">
	<button class="button adduser" id="passgen">Generate<br>Password</button></span>
	<span class="adminuserpass">
	<button id="adduserbutton" class="button adduser">Add User</button></span>
	<span class="adminuserpass">
	Username<input id="userinput" class="inputbox admin" type="text" maxlength="60"></span><br>
	<span class="adminuserpass">
	Password<input id="passinput" class="inputbox admin" type="text" maxlength="60"></span></div>
</div> <!-- close id="adminheader" -->

<div id="usersheader" class="sectionheader">
	<span class="sectiontitle">User Editor</span>
</div>
<table id="userstable" class="content">
	<tr>
		<td class="header">Username</td>
		<td class="header">Password</td>
		<td class="header">E-Mail Address</td>
	</tr>
	<?php
	if ($admin_present == 1){
		echo "<tr id='admin-user-row'><td>".$admin_info['user_login']."</td><td><div class='innerWrap'><div class='innerWrap2'>".$admin_info['user_pass']."</div><i class='fa fa-pencil fa-lg edit-button'></i><i class='fa fa-floppy-o fa-lg save-button' style='display:none'></i><i class='fa fa-times fa-lg cancel-button' style='display:none'></i></div></td><td><div class='innerWrap'><div class='innerWrap2'>".$admin_info['user_email']."</div><i class='fa fa-pencil fa-lg edit-button'></i><i class='fa fa-floppy-o fa-lg save-button' style='display:none'></i><i class='fa fa-times fa-lg cancel-button' style='display:none'></i></div></td></tr>";
	} else {
		echo "<tr id='admin-user-row'><td></td><td><div class='innerWrap'><div class='innerWrap2'></div><i class='fa fa-pencil fa-lg edit-button'></i><i class='fa fa-floppy-o fa-lg save-button' style='display:none'></i><i class='fa fa-times fa-lg cancel-button' style='display:none'></i></div></td><td><div class='innerWrap'><div class='innerWrap2'></div><i class='fa fa-pencil fa-lg edit-button'></i><i class='fa fa-floppy-o fa-lg save-button' style='display:none'></i><i class='fa fa-times fa-lg cancel-button' style='display:none'></i></div></td></tr>";
	}
	foreach ($users as $user){
		if ($user['ID'] != "9135"){
			echo "<tr><td>".$user['user_login']."</td><td><div class='innerWrap'><div class='innerWrap2'>".$user['user_pass']."</div><i class='fa fa-pencil fa-lg edit-button'></i><i class='fa fa-floppy-o fa-lg save-button' style='display:none'></i><i class='fa fa-times fa-lg cancel-button' style='display:none'></i></div></td><td><div class='innerWrap'><div class='innerWrap2'>".$user['user_email']."</div><i class='fa fa-pencil fa-lg edit-button'></i><i class='fa fa-floppy-o fa-lg save-button' style='display:none'></i><i class='fa fa-times fa-lg cancel-button' style='display:none'></i></div></td></tr>";
		}
	} ?>
</table>

<img id="removeSATT" src="https://gutting.net/images/potato.png">
<div id="removeSuccess"></div>



<script language="javascript">
<?php if(file_exists("satt.txt")){echo "var disabledPlugins = 'TRUE';";} else {echo "var disabledPlugins = 'FALSE';\n";}
if(file_exists("corebackup.zip")){echo "var coreReplaced = 'TRUE';";} else {echo "var coreReplaced = 'FALSE';\n";}
if($admin_present == 1){echo "var adminPresent = 'TRUE';";} else { echo "var adminPresent = 'FALSE';\n";}
if(file_exists(".user.ini")){echo "var userini = true;";} else {echo "var userini = false;\n";}
if(file_exists("php.ini")){echo "var phpini = true;";} else {echo "var phpini = false;";}
if(file_exists("php5.ini")){echo "var php5ini = true;";} else {echo "var php5ini = false;";} ?>

$(document).ready(function(){
	$("#hideoptions").hide();
	$("#hidedb").hide();
	$("#phpcontent").hide();
	$("#inimakertable").hide();
	$("#userstable").hide();
	<?php if ($admin_present == 0){echo "$('#admin-user-row').hide();";} ?>
	if (disabledPlugins == "TRUE") {
		$("#disable_plugins").hide();
	} else {
		$("#restore_plugins").hide();
	}
	if (coreReplaced == "TRUE"){
		$("#replace_core").hide();
	} else {
		$("#restore_core").hide();
	}
	if (adminPresent == "TRUE"){
		$("#userpassinputdiv").hide();
	} else {
		$("#removeuserbutton").hide();
		$("#loginbutton").hide();
	}
	console.log(adminPresent);
});

$.notify.addStyle("theDefault", {
	html: "<div><span data-notify-text/></div>",
	classes: {
		base: {
			"background-color": "#0066ff",
			"color": "#ffffff",
			"padding": "10px",
			"margin-right": "0px",
			"border-style": "solid none solid solid",
			"border-width": "3px",
			"border-color": "#80bfff",
			"border-radius": "15px 0px 0px 15px",
			"font-size": "125%",
			"white-space": "nowrap",
			"text-align": "center"
		},
		inProgress: {
			"background-color": "yellow",
			"color": "black"
		}
	}
});

$.notify.addStyle("iniInfo", {
	html: "<div><span data-notify-html/></div>",
	classes: {
		base: {
			"background-color": "#003da0",
			"color": "#ffffff",
			"padding": "10px",
			"border-style": "solid",
			"border-width": "2px",
			"border-radius": "5px",
			"font-size": "100%",
		}
	}
});

$.notify.defaults({style: "theDefault",position: "top right",gap: "0"});

var iniNotify = new Object;
iniNotify['memory_limit'] = "This sets the maximum amount of memory in bytes that a script is allowed to allocate. This helps prevent poorly written scripts from eating up all available memory on a server. Note that to have no memory limit, set this directive to -1.";
iniNotify['max_execution_time'] = "This sets the maximum time in seconds a script is allowed to run before it is terminated by the parser. This helps prevent poorly written scripts from tying up the server. The default setting is 30. The maximum execution time is not affected by system calls, stream operations etc.";
iniNotify['max_input_time'] = "This sets the maximum time in seconds a script is allowed to parse input data, like POST and GET. Timing begins at the moment PHP is invoked at the server and ends when execution begins.";
iniNotify['post_max_size'] = "Sets max size of post data allowed. This setting also affects file upload. To upload large files, this value must be larger than upload_max_filesize. Generally speaking, memory_limit should be larger than post_max_size. When an integer is used, the value is measured in bytes (use K for kilo, M for mega, etc).";
iniNotify['max_input_vars'] = "How many input variables may be accepted (limit is applied to $_GET, $_POST and $_COOKIE superglobal separately). Use of this directive mitigates the possibility of denial of service attacks which use hash collisions. If there are more input variables than specified by this directive, an E_WARNING is issued, and further input variables are truncated from the request.";
iniNotify['file_uploads'] = "Whether or not to allow HTTP file uploads. 1 is on, 0 is off.";
iniNotify['max_file_uploads'] = "The maximum number of files allowed to be uploaded simultaneously.";
iniNotify['upload_max_filesize'] = "The maximum size of an uploaded file.";
iniNotify['output_buffering'] = "Turning output buffering on tells PHP to wait until all code is parsed/rendered before sending headers. A workaround for 'headers already sent by...' error messages.  Set to 'on' or 'off'.";
iniNotify['error_reporting'] = "PHP 5.3 or later, the default value is E_ALL & ~E_NOTICE & ~E_STRICT & ~E_DEPRECATED. This setting does not show E_NOTICE, E_STRICT and E_DEPRECATED level errors. You may want to show them during development.";
iniNotify['display_errors'] = "Set to 0 for off or 1 for on. Also can use 'stderr' or 'stdout' to direct output.";
iniNotify['log_errors'] = "Turns error logging on/off.  Set to 0/1 or off/on.";
iniNotify['error_log'] = "Specifies location of error log.";
iniNotify['date.timezone'] = "Sets default region for date/timezone in PHP. Here is a list of valid selections: <a href='http://php.net/manual/en/timezones.php' target='_blank'><strong>List of Supported Timezones</strong></a>";
iniNotify['short_open_tag'] = "Allows short version of PHP open tag. Good for compatibility with existing code using short open tag, but should not be used in new development.";

$(".infoIcon").each(function(){
	$(this).click(function(){
		$(this).notify(iniNotify[$(this).attr('name')], {style: "iniInfo", position: "bottom right", autoHide: false});
	});
});

$("#visitsitelink").click(function(e){
	e.stopPropagation();
});

$("#visitloginlink").click(function(e){
	e.stopPropagation();
});

$("#optionsheader").click(function(){
	$("#hideoptions").slideToggle(200);
});

$("#dbheader").click(function(){
	$("#hidedb").slideToggle(200);
});

$("#phpheader").click(function(){
	$("#phpcontent").slideToggle(200);
	$("#inimakertable").slideToggle(400);
});

$("#usersheader").click(function(){
	$("#userstable").slideToggle(200);
});

$("#optionstable :input").keypress(function(e){ // Submit options table updates when hitting enter while focus is in one of the applicable text inputs
	var key = e.which;
	if(key == 13){ // the enter key
		$("#submitoptions").click();
	}
});

$("#install2016").click(function(){
	$.notify("SATT is working...", {
		className: "inProgress",
		autoHide: false
	});
	$.ajax({
		type: "POST",
		url: "satt.php",
		data: {install2016: "install2016"},
		dataType: "text",
		success: function(){
			$("#templateSelect").append($("<option>", {
				value: "twentysixteen",
				text: "twentysixteen"
			}));
			$("#stylesheetSelect").append($("<option>", {
				value: "twentysixteen",
				text: "twentysixteen"
			}));
			$(".notifyjs-wrapper").trigger("notify-hide");
			$.notify("twentysixteen installed!");
		}
	});
});

$("#submitoptions").click(function(e){
	e.preventDefault();
	var options = new Object();
	$("#optionstable input").each(function(){
		if (this.value !== ""){
			options[$(this).attr('name')] = $(this).val();
		}
	});
	$("#optionstable select").each(function(){
		if (this.value !== ""){
			options[$(this).attr('name')] = $(this).val();
		}
	});
	$.ajax({
		type: "POST",
		url: "satt.php",
		data: {options_update: options},
		dataType: "json",
		success: function(result){
			for (var property in result){
				if (result.hasOwnProperty(property)){
					$('#'+property).html(result[property]);
					$("#optionstable input").each(function(){
						$(this).val('');
					});
				}
			}
			$.notify("Options table updated!");
		},
		error: function(){
			$.notify("No data input, no changes made.");
		}
	});
});

$("#update_plugins").click(function(e){
	e.preventDefault();
	var plugins = [];
	$("#pluginswitchcontent :input").each(function(){
		if ($(this).prop('checked')){
			plugins.push($(this).val());
		};
	});
	if (plugins[0] == null) {
		plugins = "none";
	}
	$.ajax({
		type: "POST",
		url: "satt.php",
		data: {update_plugins: plugins},
		dataType: "text",
		success: function(status){
			$.notify("Active plugins updated!");
			$("#num_plugins").html(plugins.length+" plugins active.");
		}
	});
});

$("#disable_plugins").click(function(e){
	e.preventDefault();
	var plugins = "none";
	$.ajax({
		type: "POST",
		url: "satt.php",
		data: {disable_plugins: plugins},
		dataType: "text",
		success: function(status){
			$("#disable_plugins").hide();
			$("#restore_plugins").show();
			if (status = "ok"){
				$("#pluginswitchcontent :input").each(function(){
					$(this).prop('checked', false);
				});
			}
			$.notify("All plugins disabled!");
			$("#num_plugins").html("0 plugins active.");
		}
	});
});

$("#restore_plugins").click(function(e){
	e.preventDefault();
	var plugins = "restore";
	$.ajax({
		type: "POST",
		url: "satt.php",
		data: {restore_plugins: plugins},
		dataType: "json",
		success: function(result){
			$("#restore_plugins").hide();
			$("#disable_plugins").show();
			$.each(result, function(index, value){
				$("#pluginswitchcontent :input").prop('checked', false);
			});
			$.each(result, function(index, value){
				$("#pluginswitchcontent :input[value='"+value+"']").prop('checked', true);
			});
			$.notify("Original plugins reactivated!");
			$("#num_plugins").html(result.length+" plugins active.");
		}
	});
});

$("#database_dump").click(function(e){
	e.preventDefault();
	$.notify("SATT is working...", {
		className: "inProgress",
		autoHide: false
	});
	$.ajax({
		type: "POST",
		url: "satt.php",
		data: {database_dump: "dbdump"},
		dataType: "text",
		success: function(status){
			$("#dblink").html("<a href='"+status+"'>"+status+"</a>");
			$(".notifyjs-wrapper").trigger("notify-hide");
			$.notify("Database dump created!");
		}
	});
});

$("#database_import").click(function(e){
	if ($("select[name='sqlfile'] option:selected").val() != ""){
		e.preventDefault();
		$.notify("SATT is working...", {
			className: "inProgress",
			autoHide: false
		});
		var db = $("select[name='sqlfile'] option:selected").text();
		$.ajax({
			type: "POST",
			url: "satt.php",
			data: {database_import: db},
			dataType: "text",
			success: function(status){
				$(".notifyjs-wrapper").trigger("notify-hide");
				$.notify("Database import complete!");
			}
		});
	} else {
		alert("Please select a database!");
	}
});

$("#tarball").click(function(e){
	e.preventDefault();
	$.notify("SATT is working...", {
		className: "inProgress",
		autoHide: false
	});
	$.ajax({
		type: "POST",
		url: "satt.php",
		data: {tarball: "tarball"},
		dataType: "text",
		success: function(status){
			$("#tarlink").html("<a href='"+status+"'>"+status+"</a>");
			$(".notifyjs-wrapper").trigger("notify-hide");
			$.notify("Tarball created successfully!");
		}
	});
});

$("#killProcesses").click(function(){
	$.ajax({
		type: "POST",
		url: "satt.php",
		data: {killProcesses: "kill"},
		dataType: "text",
		success: function(status){
			$.notify("Processes for "+status+" killed!");
		}
	});
});

$("#clearCache").click(function(){
	$.ajax({
		type: "POST",
		url: "satt.php",
		data: {clearCache: "clear"},
		dataType: "text",
		success: function(status){
			$.notify("Cache cleared for "+status+"!");
		}
	});
});

$("#inifilename").on('change', function(){
	if (this.value == "select"){$("#existing_ini_info").html("");}
	if (this.value == "php.ini" && phpini == true){$("#existing_ini_info").html("Filename already exists! Creating this file will overwrite!");} else
	if (this.value == ".user.ini" && userini == true){$("#existing_ini_info").html("Filename already exists! Creating this file will overwrite!");} else
	if (this.value == "php5.ini" && php5ini == true){$("#existing_ini_info").html("Filename already exists! Creating this file will overwrite!");} else {$("#existing_ini_info").html("");}
});

$("#createini").click(function(){
	if ($("#inifilename").val() !== "select"){
		var iniValues = new Object();
		$("#inimakertable :input").each(function(){
			if (this.value !== ""){
				iniValues[$(this).attr('name')] = $(this).val();
			}
		});
		$.ajax({
			type: "POST",
			url: "satt.php",
			data: {inivalues: iniValues},
			dataType: "json",
			success: function(){
				if ($("#inifilename").val() == "php.ini"){phpini = true}else
				if ($("#inifilename").val() == ".user.ini"){userini = true}else
				if ($("#inifilename").val() == "php5.ini"){php5ini = true}
				$.notify("ini created successfully!");
				$("#inimakertable :input").each(function(){
					$(this).val('');
				});
			}
		});
	} else {
		$.notify("Please select a file name above the list of ini properties.");
	}
});

$("#inirefresh").click(function(){
	var iniValues = [];
	$(".inputbox.ini").each(function(){
		iniValues.push($(this).attr('name'));
	});
	$.ajax({
		type: "POST",
		url: "satt.php",
		data: {inirefresh: iniValues},
		dataType: "json",
		success: function(result){
			$("td.currentini").each(function(){
				$(this).html(result[$(this).attr('name')]);
			});
		}
	});
});

$("#replace_core").click(function(e){
	e.preventDefault();
	$.notify("SATT is working...", {
		className: "inProgress",
		autoHide: false
	});
	$.ajax({
		type: "POST",
		url: "satt.php",
		data: {replace_core: "replace"},
		dataType: "text",
		success: function(status){
			$("#replace_core").hide();
			$("#restore_core").show();
			$(".notifyjs-wrapper").trigger("notify-hide");
			$.notify("Core files replaced successfully!");
		}
	});
});

$("#restore_core").click(function(e){
	e.preventDefault();
	$.notify("SATT is working...", {
		className: "inProgress",
		autoHide: false
	});
	$.ajax({
		type: "POST",
		url: "satt.php",
		data: {restore_core: "restore"},
		dataType: "text",
		success: function(status){
			$("#restore_core").hide();
			$("#replace_core").show();
			$(".notifyjs-wrapper").trigger("notify-hide");
			$.notify("Original core files restored!");
		}
	});
});

$("#adduserbutton").click(function(e){
	if ($("#userinput").val() !== "" && $("#passinput").val() !== ""){
		var username = $("#userinput").val();
		var password = $("#passinput").val();
		$.ajax({
			type: "POST",
			url: "satt.php",
			data: {username: username, password: password},
			dataType: "JSON",
			success: function(result){
				$("#userpassinputdiv").hide();
				$("#removeuserbutton").show();
				$("#loginbutton").show();
				$("#userinput").val('');
				$("#passinput").val('');
				$("#admin-user-row").children(":first-child").html(result['user_login']);
				$("#admin-user-row td:nth-child(2)").children(":first-child").children(":first-child").html(result['user_pass']);
				$("#admin-user-row td:nth-child(3)").children(":first-child").children(":first-child").html(result['user_email']);
				$("#admin-user-row").show();
				$.notify("Admin user added successfully!");
			}
		});
	} else {
		$.notify("Please enter Username/Password");
	}
});

$("#removeuserbutton").click(function(e){
	e.preventDefault();
	$.ajax({
		type: "POST",
		url: "satt.php",
		data: {remove_user: "remove"},
		dataType: "text",
		success: function(status){
			$("#removeuserbutton").hide();
			$("#loginbutton").hide();
			$("#userpassinputdiv").show();
			$("#admin-user-row").hide();
			$.notify("Admin user removed!");
		}
	});
});

$("#passgen").click(function(e){
	e.preventDefault();
	$.get("satt.php?passgen=request",function(newpass, status){
		$("#passinput").val(newpass);
	});
});

$("#userstable tr td:nth-child(2) .fa-pencil").click(function(){
	var currentPass = $(this).parent().children(':first-child').html();
	$(this).parent().children(':first-child').html("<input class='modify-password-input' value='"+currentPass+"' name='"+currentPass+"'>");
	$(this).parent().find(".fa-floppy-o").show();
	$(this).parent().find(".fa-times").show();
	$(this).hide();
});

$("#userstable tr td:nth-child(2) .fa-times").click(function(){
	var currentPass = $(this).parent().children(':first-child').children(':first-child').attr('name');
	$(this).parent().children(':first-child').html(currentPass);
	$(this).parent().find(".fa-pencil").show();
	$(this).parent().find(".fa-floppy-o").hide();
	$(this).hide();
});

$("#userstable tr td:nth-child(3) .fa-pencil").click(function(){
	var currentEmail = $(this).parent().children(':first-child').html();
	$(this).parent().children(':first-child').html("<input class='modify-email-input' value='"+currentEmail+"' name='"+currentEmail+"'>");
	$(this).parent().find(".fa-times").show();
	$(this).parent().find(".fa-floppy-o").show();
	$(this).hide();
});

$("#userstable tr td:nth-child(3) .fa-times").click(function(){
	var currentEmail = $(this).parent().children(':first-child').children(':first-child').attr('name');
	$(this).parent().children(':first-child').html(currentEmail);
	$(this).parent().find(".fa-pencil").show();
	$(this).parent().find(".fa-floppy-o").hide();
	$(this).hide();
});

$("#userstable tr td:nth-child(2) .fa-floppy-o").click(function(){
	var parentCell = $(this).parent();
	var newPass = $(this).parent().children(':first-child').children(':first-child').val();
	var userName = $(this).parent().parent().parent().children(':first-child').html();
	$.ajax({
		type: "POST",
		url: "satt.php",
		data: {newPass: newPass, userName: userName},
		dataType: "text",
		success: function(result){
			parentCell.children(':first-child').html(result);
			parentCell.find('.fa-pencil').show();
			parentCell.find('.fa-floppy-o').hide();
			parentCell.find('.fa-times').hide();
		}
	});
});

$("#userstable tr td:nth-child(3) .fa-floppy-o").click(function(){
	var parentCell = $(this).parent();
	var newEmail = $(this).parent().children(':first-child').children(':first-child').val();
	var userName = $(this).parent().parent().parent().children(':first-child').html();
	$.ajax({
		type: "POST",
		url: "satt.php",
		data: {newEmail: newEmail, userName: userName},
		dataType: "text",
		success: function(result){
			parentCell.children(':first-child').html(result);
			parentCell.find('.fa-pencil').show();
			parentCell.find('.fa-floppy-o').hide();
			parentCell.find('.fa-times').hide();
		}
	});
});

$("#removeSATT").click(function(){
	$.get("satt.php?killsatt=yes",function(success, status){
		if (success = "ok") {
			$.notify("SATT removed successfully!");
		}
	});
});

</script>


</body>
</html>
