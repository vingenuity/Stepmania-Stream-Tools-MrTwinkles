<?php

// PHP "Song scraper" for Stepmania
// https://github.com/DaveLinger/Stepmania-Stream-Tools
// This script scrapes your Stepmania cache directory for songs and posts each unique song to a mysql database table.
// It cleans [TAGS] from the song titles and it saves a "search ready" version of each song title (without spaces or special characters) to the "strippedtitle" column.
// This way you can have another script search/parse your entire song library - for example to make song requests.
// You only need to re-run this script any time you add new songs and Stepmania has a chance to build its cache. It'll skip songs that already exist in the DB.
// The same exact song title is allowed to exist in different packs.
//
// Run this from the command line like this: "php scrape_songs_cache.php"
//
// "Wouldn't it be nice" future features?:
// 
// 2. Automatically upload each SONG's banner to the remote server (optional - this would use a lot of remote storage space)

// Configuration

if (php_sapi_name() == "cli") {
    // In cli-mode
} else {
	// Not in cli-mode
	if (!isset($_GET['security_key']) || $_GET['security_key'] != $security_key || empty($_GET['security_key'])){die("Fuck off");}
}

include ('config.php');

// Code

function fixEncoding($line){
	//detect and convert ascii directory string to UTF-8 (Thanks, StepMania!)
	$encoding = mb_detect_encoding($line,'UTF-8,CP1252,ASCII,ISO-8859-1');
	if($encoding != 'UTF-8'){
		//echo "Invalid UTF-8 detected ($encoding). Converting...\n";
		$line = mb_convert_encoding($line,'UTF-8',$encoding);
		//echo "Text: ".$line."\n";
	}elseif($encoding == FALSE || empty($encoding)){
		//encoding not detected, assuming 'ISO-8859-1', again, thanks, StepMania.
		$encoding = 'ISO-8859-1';
		//echo "Invalid UTF-8 detected ($encoding) (fallback). Converting...\n";
		$line = mb_convert_encoding($line,'UTF-8',$encoding);
		//echo "Text: ".$line."\n";
	}
	return $line;
}

function parseMetadata($file) {
	$file_arr = array();
	$lines = array();
	$delimiter = ":";
	$eol = ";";
	
	//$data = utf8_encode(file_get_contents($file));
	$data = file_get_contents($file);
	$data = substr($data,0,strpos($data,"//-------"));
	
	$file_arr = preg_split("/{$eol}/",$data);
	//print_r($file_arr);
	
	foreach ($file_arr as $line){
		// if there is no $delimiter, set an empty string
			$line = trim($line);
			if (substr($line,0,1) == "#"){
				if (stripos($line,$delimiter)===FALSE){
					$key = $line;
					$value = "";
			// esle treat the line as normal with $delimiter
				}else{
					$key = substr($line,0,strpos($line,$delimiter));
					$value = substr($line,strpos($line,$delimiter)+1);
				}
				$value = fixEncoding($value);
				$lines[trim($key,'"')] = trim($value,'"');	
			}
			
	}
	
	return $lines;
}

function parseNotedata($file) {
	$file_arr = array();
	$lines = array();
	$delimiter = ":";
	$eol = ";";
	$notedata_array = array();
	
	//$data = utf8_encode(file_get_contents($file));
	$data = file_get_contents($file);

	if( strpos($data,"#NOTEDATA:")){
		$data = substr($data,strpos($data,"//-------"));
		$data = substr($data,strpos($data,"#"));
		
	//getting notedata info...
			$notedata_array = array();
			
				$notedata_total = substr_count($data,"#NOTEDATA:"); //how many step charts are there?
				$notedata_offset = 0;
				$notedata_next = 0;
				$notedata_count = 1;
				//start from the first occurance of notedata, set found data to array
				while ($notedata_count <= $notedata_total){ 
					$notedata_offset = strpos($data, "#NOTEDATA:",$notedata_next);
					$notedata_next = strpos($data, "#NOTEDATA:",$notedata_offset + strlen("#NOTEDATA:"));
						if ($notedata_next === FALSE){
							$notedata_next = strlen($data);
						}
					
					$data_sub = substr($data,$notedata_offset,$notedata_next-$notedata_offset);
					$file_arr = "";
					$file_arr = preg_split("/{$eol}/",$data_sub);
					
					foreach ($file_arr as $line){
						$line = trim($line);
						//only process lines beginning with '#'
						if (substr($line,0,1) == "#"){
							// if there is no $delimiter, set an empty string
							if (stripos($line,$delimiter)===FALSE){
								$key = $line;
								$value = "";
						// esle treat the line as normal with $delimiter
							}else{
								$key = substr($line,0,strpos($line,$delimiter));
								$value = substr($line,strpos($line,$delimiter)+1);
							}
							$value = fixEncoding($value);
							// trim any quotes (messes up later queries)
							$lines[trim($key,'"')] = trim($value,'"');	
						}	
					}
					
					//build array of notedata chart information
					
				//Not all chart files have these descriptors, so let's check if they exist to avoid notices/errors	
					array_key_exists('#CHARTNAME',$lines) 	? addslashes($lines['#CHARTNAME']) 	: $lines['#CHARTNAME']   = "";
					array_key_exists('#DESCRIPTION',$lines) ? addslashes($lines['#DESCRIPTION']): $lines['#DESCRIPTION'] = "";
					array_key_exists('#CHARTSTYLE',$lines)  ? addslashes($lines['#CHARTSTYLE']) : $lines['#CHARTSTYLE']  = "";
					array_key_exists('#CREDIT',$lines)      ? addslashes($lines['#CREDIT']) 	: $lines['#CREDIT']      = "";
					
					if( array_key_exists('#DISPLAYBPM',$lines)){
						if( strpos($lines['#DISPLAYBPM'],':') > 0){
							$display_bpmSplit = array();
							$display_bpmSplit = preg_split("/:/",$lines['#DISPLAYBPM']);
							$lines['#DISPLAYBPM'] = intval($display_bpmSplit[0],0)."-".intval($display_bpmSplit[1],0);
						}else{
							$lines['#DISPLAYBPM'] = intval($lines['#DISPLAYBPM'],0);
						}
					}else{
						  $lines['#DISPLAYBPM']  = "";
					}
					
					$notedata_array[] = array('chartname' => $lines['#CHARTNAME'], 'steptype' => $lines['#STEPSTYPE'], 'description' => $lines['#DESCRIPTION'], 'chartstyle' => $lines['#CHARTSTYLE'], 'difficulty' => $lines['#DIFFICULTY'], 'meter' => $lines['#METER'], 'radarvalues' => $lines['#RADARVALUES'], 'credit' => $lines['#CREDIT'], 'displaybpm' => $lines['#DISPLAYBPM'], 'stepfilename' => $lines['#STEPFILENAME']);

					$notedata_count++;
				}
	}
	
	return $notedata_array;
}

function prepareCacheFiles($filesArr){
	//sort files by last modified date
	echo "Sorting cache files by modified date...\n";
	$micros = microtime(true);
	usort( $filesArr, function( $a, $b ) { return filemtime($b) - filemtime($a); } );
	echo ("Sort time: ".round(microtime(true) - $micros,3)." secs.\n");

	return $filesArr;
}

function isIgnoredPack($songfilename){
	global $packsIgnore;

	$return = FALSE;
	if(!empty($songfilename)){
		//song has a an associated simfile
		$song_dir = substr($songfilename,1,strrpos($songfilename,"/")-1); //remove benginning slash and file extension

		//Get pack name
		$pack = substr($song_dir, 0, strripos($song_dir, "/"));
		$pack = substr($pack, strripos($pack, "/")+1);
		//if the pack is on ignore list, skip it
		if (in_array($pack,$packsIgnore)){
			$return = TRUE;
		}
	}
	return $return;
}

function curlPost($postSource, $array){
	global $target_url;
	global $security_key;
	unset($ch,$result,$post,$jsonArray);
	//add the security_key to the array
	$jsonArray = array('security_key' => $security_key, 'source' => $postSource, 'data' => $array);
	//encode array as json
	$post = json_encode($jsonArray);
	if(json_last_error_msg() != "No error"){
		//there was an error with the json string, die
		die(json_last_error_msg());
	}
	//this curl method only works with PHP 5.5+
	$ch = curl_init();
	curl_setopt($ch, CURLOPT_URL,$target_url."/status.php");
	curl_setopt($ch, CURLOPT_RETURNTRANSFER, true);
	curl_setopt($ch, CURLOPT_HTTPHEADER, array('Content-Type: application/json'));
	curl_setopt($ch, CURLOPT_ENCODING,'gzip,deflate');
	curl_setopt($ch, CURLOPT_SSL_VERIFYPEER, true); //must specify cacert.pem location in php.ini
	curl_setopt($ch, CURLOPT_POST,1); 
	curl_setopt($ch, CURLOPT_POSTFIELDS, $post);
	$result = curl_exec ($ch);
	if(curl_exec($ch) === FALSE){echo 'Curl error: '.curl_error($ch);}
	echo $result; //echo from the server-side script
	echo (round(curl_getinfo($ch)['total_time_us'] / 1000000,3)." secs.\n");
	curl_close ($ch);
	//print_r($result);
	return $result;
}

//get start time
$microStart = microtime(true);

$files = array ();
foreach(glob("{$cacheDir}/*", GLOB_BRACE) as $file) {
    $files[] = $file;
}

if(count($files) == 0){die("No files. Songs cache directory not found in Stepmania directory. You must start Stepmania before running this software. Also, if you are not running Stepmania in portable mode, your Stepmania directory may be in \"AppData\".");}

$i = 0;
$chunk = 500;

//prepare sm_songs database for scraping and check if this is a first-run
echo "Preparing database for song scraping...\n";
$firstRun = curlPost("songsStart",array(0));
//print_r($initial_array);

//loop through cache files, process to json strings, and post to the webserver for further processing
$totalFiles = count($files);
echo "Looping through ".$totalFiles." cache files...\n";
$totalChunks = ceil($totalFiles / $chunk);
$currentChunk = 1;
if ($firstRun != TRUE){
	//only sort files if NOT first run
	$files = prepareCacheFiles($files);
}
//print_r($files);
$files = array_chunk($files,$chunk,true);
foreach ($files as $filesChunk){
	unset($cache_array,$cache_file,$metadata,$notedata_array);
	foreach ($filesChunk as $file){	
		//get md5 hash of file to determine if there are any updates
		$file_hash = md5_file($file);
		$metadata = parseMetadata($file);
		$metadata['file_hash'] = $file_hash;
		$metadata['file'] = fixEncoding(basename($file));
		$notedata_array = parseNotedata($file);
		//sanity on the file, if no filename or notedata, ignore
		if (isset($metadata['#SONGFILENAME']) && !empty($metadata['#SONGFILENAME']) && !empty($notedata_array)){
			//check if this file is in an ignored pack
			if (isIgnoredPack($metadata['#SONGFILENAME']) == FALSE){
				$cache_file = array('metadata' => $metadata, 'notedata' => $notedata_array);
				$cache_array[] = $cache_file;
				$i++;
			}
		}else{
			echo "There was an error with: [".$metadata['file']."]. No chartfile or NOTEDATA found! Skipping...\n";
		}
	}
	echo "Sending ".$currentChunk." of ".$totalChunks." chunk(s) via cURL...\n";
	curlPost("songs", $cache_array);
	$currentChunk++;
}

//mark songs as (not)installed
echo "Finishing up...\n";
curlPost("songsEnd",array($i));

//display time
echo ("\nTotal time: ". round(microtime(true) - $microStart,3) . " secs.\n");

//

// Let's clean up the sm_songs db, removing records that are not installed, have never been requested, never played, or don't have a recorded score
	//echo "Purging song database and cleaning up...";
	//$sql_purge = "DELETE FROM sm_songs 
	//			WHERE NOT EXISTS(SELECT NULL FROM sm_requests WHERE sm_requests.song_id = sm_songs.id LIMIT 1) AND NOT EXISTS (SELECT NULL FROM sm_scores WHERE sm_scores.song_id = sm_songs.id LIMIT 1) AND NOT EXISTS (SELECT NULL FROM sm_songsplayed WHERE sm_songsplayed.song_id = sm_songs.id LIMIT 1) AND sm_songs.installed<>1";
	//if (!mysqli_query($conn, $sql_purge)) {
	//		echo "Error: " . $sql_purge . "\n" . mysqli_error($conn);
	//	}

//

?>