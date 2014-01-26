<?php
/***
by robert@rebelwords.org
v 0.1
on Jan 11th, 2014
****rokuencode.php*****
**This script is intended to be called from MythTV as a user job.
**Call it like this from MythTV's user jobs: /usr/bin/rokuencode.php "%DIR%" "%FILE%"
**It will use HandBrake to convert the standard mpg's to roku-playable x264 files
**with friendly file names.
**Added the framerate ("-r") to Handbrake of 29.97 which resolved the sound being off by 1 second.
***********************
**/
$filename=$argv[2];
$directory=$argv[1];
function connect_to_mythtv() {
	$mysqluser="mythtv";
	$mysqlpassword="YOURPASSWORDHERE";
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
$sql="SELECT title,category,description,DATE_FORMAT(starttime, '%b %D') as 'ftime',DATE_FORMAT(starttime, '%Y') as 'play_year',subtitle FROM recorded WHERE basename='$filename'";
$result=mysql_query($sql) or die (mysql_error());
while($row=mysql_fetch_array($result))
	{
	$title=$row['title'];
	$ftime=$row['ftime'];
	$play_year=$row['play_year'];
	$subtitle=$row['subtitle'];
	$description=$row['description'];
	$category=$row['category'];
	$base_name="$title $ftime $subtitle";
	$full_title="$title $ftime $subtitle.mp4";
$HandbrakeCLI='/usr/bin/HandBrakeCLI -i "'.$directory.'"/"'.$filename.'" -o "'.$directory.'"/"'.$full_title.'" -e x264 -b 1500 -E faac -r 29.97 -B 160 -R 48 -w 720 -O';
$imagemagick='/usr/bin/convert "'.$directory.'"/"'.$filename.'".png "'.$directory.'"/"'.$base_name.'".jpg';
	shell_exec($HandbrakeCLI);
	shell_exec($imagemagick);
ob_start();
?>
<video>
  <title><?php echo "$title";?></title>
  <year><?php echo "$play_year"?></year>
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
