<?php
/***
by robert@rebelwords.org
v 0.1
on Jan 11th, 2014
v 0.2
on March 15th, 2014
****rokuencode.php*****
**This script is intended to be called from MythTV as a user job.
**Call it like this from MythTV's user jobs: /usr/bin/rokuencode.php "%DIR%" "%FILE%" "%CHAN%" "%STARTTIME%"
**It will use HandBrake to convert the standard mpg's to roku-playable x264 files
**with friendly file names and will cut commercials.
**Added the framerate ("-r") to Handbrake of 29.97 which resolved the sound being off by 1 second.
**
**To do: improve the quality of mythtranscode.  Noticed some quality loss from the requirement to transcode.
***********************
**/
ini_set("log_errors", 1);
ini_set("error_log", "/tmp/rokuencode_errors.log");
error_log( "Hello, errors!");

$directory=$argv[1] 	/*%DIR%*/;
$filename=$argv[2] 	/*%FILE%*/;
$channel=$argv[3] 	/*%CHAN%*/;
$start=$argv[4] 	/*%STARTTIME%*/;

function filesize64($file)
{
/** Required to calculate file sizes > 2 GB on 32-bit php.  filesize() doesn't do this. **/
    static $iswin;
    if (!isset($iswin)) {
        $iswin = (strtoupper(substr(PHP_OS, 0, 3)) == 'WIN');
    }

    static $exec_works;
    if (!isset($exec_works)) {
        $exec_works = (function_exists('exec') && !ini_get('safe_mode') && @exec('echo EXEC') == 'EXEC');
    }

    // try a shell command
    if ($exec_works) {
        $cmd = ($iswin) ? "for %F in (\"$file\") do @echo %~zF" : "stat -c%s \"$file\"";
        @exec($cmd, $output);
        if (is_array($output) && ctype_digit($size = trim(implode("\n", $output)))) {
            return $size;
        }
    }

    // try the Windows COM interface
    if ($iswin && class_exists("COM")) {
        try {
            $fsobj = new COM('Scripting.FileSystemObject');
            $f = $fsobj->GetFile( realpath($file) );
            $size = $f->Size;
        } catch (Exception $e) {
            $size = null;
        }
        if (ctype_digit($size)) {
            return $size;
        }
    }

    // if all else fails
    return filesize($file);
}

function connect_to_mythtv() {
/* Vanilla MySQL connection script */
	$mysqluser="mythtv";
	$mysqlpassword="mythtv";
	$mysqlhost="localhost";
	$connID=@mysql_pconnect($mysqlhost, $mysqluser, $mysqlpassword);
	if ($connID) {
		mysql_select_db("mythconverg");
	return $connID;
}
else {
	echo "Unable to connect to database.";
	exit();
}
}
connect_to_mythtv();
/*Flag the commercials.*/
$flagcommercials='/usr/bin/mythcommflag --chanid "'.$channel.'" --starttime "'.$start.'"';
shell_exec($flagcommercials);
/*Generate the cutlist of commercials.*/
$gencutlist='/usr/bin/mythutil --gencutlist --chanid "'.$channel.'" --starttime "'.$start.'"';
shell_exec($gencutlist);
/*Remove the cutlist.  mythtranscode does lose some image quality. Not sure how to improve */
$transcode='/usr/bin/mythtranscode --honorcutlist --mpeg2 -i "'.$directory.'"/"'.$filename.'" -o "'.$directory.'"/"'.$filename.'".tmp';
/*Replace file with freshly transcoded one.*/
$mvold='/bin/mv "'.$directory.'"/"'.$filename.'" "'.$directory.'"/"'.$filename.'".old';
$mvnew='/bin/mv "'.$directory.'"/"'.$filename.'".tmp "'.$directory.'"/"'.$filename.'"';
shell_exec($mvold);
shell_exec($mvnew);
/*Rebuild mythcommflag*/
$rebuild='/usr/bin/mythcommflag --chanid "'.$channel.'" --starttime "'.$start.'" --rebuild';
shell_exec($rebuild);
/*Clean up*/
$clearcut='/usr/bin/mythutil --clearcutlist --chanid "'.$channel.'" --starttime "'.$start.'"';
shell_exec($clearcut);
/*Get the filesize to update the mythconverg table*/
$filesize=filesize64("$directory/$filename");
$converg="UPDATE recorded SET cutlist=0,filesize='$filesize' WHERE basename='$filename'";
$doconverg=mysql_query($converg);
/*Seed variables for the xml file to be read on Roku*/
$sql="SELECT title,category,DATE_FORMAT(originalairdate, '%Y') as 'air_year',description,DATE_FORMAT(starttime, '%b %D') as 'ftime',DATE_FORMAT(starttime, '%Y') as 'play_year',subtitle FROM recorded WHERE basename='$filename'";
$result=mysql_query($sql) or die (mysql_error());
while($row=mysql_fetch_array($result))
	{
	$chanid=$row['chanid'];
	$starttime=$row['starttime'];
	$title=$row['title'];
	$ftime=$row['ftime'];
	$play_year=$row['play_year'];
	$subtitle=$row['subtitle'];
	$description=$row['description'];
	$category=$row['category'];
	$air_year=$row['air_year'];
	$base_name="$title $ftime $subtitle";
	$full_title="$title $ftime $subtitle.mp4";
/*Magical Handbrake mythtv -> roku one liner*/
$HandbrakeCLI='/usr/bin/HandBrakeCLI -i "'.$directory.'"/"'.$filename.'" -o "'.$directory.'"/"'.$full_title.'" -e x264 -b 1500 -E faac -r 29.97 -B 160 -R 48 -w 720 -O';
/*Generate the image for roku*/
$imagemagick='/usr/bin/convert "'.$directory.'"/"'.$filename.'".png "'.$directory.'"/"'.$base_name.'".jpg';
	shell_exec($killcommercials); 
	shell_exec($HandbrakeCLI);
	shell_exec($imagemagick);
/*Write the xml file for roku*/
ob_start();
?>
<video>
  <title><?php echo "$title - $subtitle";?></title>
  <year><?php echo "$air_year"?></year>
  <genre><?php echo "$category"?></genre>
  <mpaa>NR</mpaa>
  <director>Not Avaiable</director>
  <actors>Not Available</actors>
  <description><?php echo "$description"?></description>
  <length>Not available</length>
</video>
<?php
$xml_file = ob_get_clean();
$filename= "/var/lib/mythtv/recordings/$base_name.xml";
$fh=fopen($filename,'w');
fwrite($fh,$xml_file);
fclose($fh);
}
