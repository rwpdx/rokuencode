<?php

ini_set("log_errors", 1);
ini_set("error_log", "/tmp/rokuencode_errors.log");

$dir=$argv[1] /*%DIR%*/;
echo "dir is $dir\n";
$file=$argv[2] /*%FILE%*/;
echo "file is $file\n";
$chan=$argv[3] /*%CHAN%*/;
echo "chan is $chan\n";
$starttime=$argv[4] /*%STARTTIME%*/;
echo "starttime is $starttime\n";
/******************************* Function declarations *****************************************************/
function filesize64($file)
{
    static $exec_works;
    if (!isset($exec_works)) {
        $exec_works = (function_exists('exec') && !ini_get('safe_mode') && @exec('echo EXEC') == 'EXEC');
    }
    if ($exec_works) {
        $cmd = ($iswin) ? "for %F in (\"$file\") do @echo %~zF" : "stat -c%s \"$file\"";
        @exec($cmd, $output);
        if (is_array($output) && ctype_digit($size = trim(implode("\n", $output)))) 
	{
            return $size;
        }
        if (ctype_digit($size)) {
            return $size;
        }
    }
    return filesize($file);
}

function connect_to_mythtv() 
{
	$mysqluser="mythtv";
	$mysqlpassword="REDACTED"; /*YOUR PASSWORD HERE***/
	$mysqlhost="localhost";
	$connID=@mysql_pconnect($mysqlhost, $mysqluser, $mysqlpassword);
	if ($connID) 
	{
		mysql_select_db("mythconverg");
		return $connID;
	}
	else 
	{
		echo "Unable to connect to database.";
		exit();
	}
}
/*************************************************************************************************************/
connect_to_mythtv();

echo "Doing mythcommflag (flagging)...(step 1)\n";
$flagcommercials='/usr/bin/mythcommflag --chanid "'.$chan.'" --starttime "'.$starttime.'"';
	shell_exec($flagcommercials);
echo "Done!\n";
echo "Doing mythutil (cutlist)...(step 2)\n";
$gencutlist='/usr/bin/mythutil --gencutlist --chanid "'.$chan.'" --starttime "'.$starttime.'"';
	shell_exec($gencutlist);
echo "Done!\n";
echo "Doing transcode with cutlist...(step 3)\n";
$transcode='/usr/bin/mythtranscode --chanid "'.$chan.'" --starttime "'.$starttime.'" --honorcutlist --mpeg2';
	shell_exec($transcode);
echo "Done!\n";
echo "Moving old file...\n";
$mvold='/bin/mv "'.$dir.'"/"'.$file.'" "'.$dir.'"/"'.$file.'".old';
	shell_exec($mvold);
echo "Moving cut file in it's place...\n";
$mvnew='/bin/mv "'.$dir.'"/"'.$file.'".tmp "'.$dir.'"/"'.$file.'"';
	shell_exec($mvnew);
echo "Done!\n";
echo "Rebuilding mythcommflag...\n";
$rebuild='/usr/bin/mythcommflag --chanid "'.$chan.'" --starttime "'.$starttime.'" --rebuild';
	shell_exec($rebuild);
echo "Done!\n";
echo "Clearing cutlist info...\n";
$clearcut='/usr/bin/mythutil --clearcutlist --chanid "'.$chan.'" --starttime "'.$starttime.'"';
	shell_exec($clearcut);
$filesize=filesize64("$dir/$file");
echo "Done!\n";
echo "Calculated filesize to be $filesize\n";
echo "Updating mythtv database...\n";
$converg="UPDATE recorded SET cutlist=0,filesize='$filesize' WHERE basename='$file'";
	mysql_query($converg);
$sql="SELECT 
	title
	,category
	,DATE_FORMAT(originalairdate, '%Y') as 'air_year'
	,description,DATE_FORMAT(starttime, '%b %D') as 'ftime'
	,DATE_FORMAT(starttime, '%Y') as 'play_year'
	,subtitle FROM recorded 
      WHERE basename='$file'";
$result=mysql_query($sql) or die (mysql_error());
while($row=mysql_fetch_array($result))
	{
	$chanid=$row['chanid'];
	$starttimetime=$row['starttime'];
	$title=$row['title'];
	$ftime=$row['ftime'];
	$play_year=$row['play_year'];
	$subtitle=$row['subtitle'];
	$description=$row['description'];
	$category=$row['category'];
	$air_year=$row['air_year'];
	$base_name="$title $ftime $subtitle";
	$full_title="$title $ftime $subtitle.mp4";
echo "Starting conversion for Roku...\n";
$HandbrakeCLI='/usr/bin/HandBrakeCLI -i "'.$dir.'"/"'.$file.'" -o "'.$dir.'"/"'.$full_title.'" -e x264 -b 1500 -E faac -r 29.97 -B 160 -R 48 -w 720 -O';
echo "Making the image...\n";
$imagemagick='/usr/bin/convert "'.$dir.'"/"'.$file.'".png "'.$dir.'"/"'.$base_name.'".jpg';
	shell_exec($HandbrakeCLI);
	shell_exec($imagemagick);
echo "Prepping the xml file...\n";
ob_start();
?>
<video>
  <title><?php echo "$title - $subtitle";?></title>
  <year><?php echo "$air_year"?></year>
  <genre><?php echo "$category"?></genre>
  <mpaa>NR</mpaa>
  <director>Not Available</director>
  <actors>Not Available</actors>
  <description><?php echo "$description"?></description>
  <length>Not available</length>
</video>
<?php
$xml_file = ob_get_clean();
$file= "/var/lib/mythtv/recordings/$base_name.xml";
$fh=fopen($file,'w');
fwrite($fh,$xml_file);
fclose($fh);
echo "All DONE!\n";
}
