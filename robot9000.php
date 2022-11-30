<?php
# Parameters:
# name: The name field the user supplied, minus any tripcode (before #)
# email: User supplied email field
# sub: user supplied subject field
# com: user supplied comment field (before or after wordfilters? dunno)
# md5: md5 of the supplied image. null if no image.
# ip: the IP of the user, in unsigned integer (packed) form
# mod: true if the user is a mod
#
# Return value: A string. If the string is "ok", the post should go through.
# If the string is anything else, abort posting and display that message
# to the user.
#
# Integration info;
# This should run before wordfilters (including >>num) and duplicate(md5) detection. 
# It doesn't know about bans, so those need to be done seperately, but it
# doesn't care if that is before or after.
# It really should run after valid file checks (jpg/png/gif, >0x0, etc) but that's not critical.
# 
# Synchronization: There is (in theory) a minor race condition because the tables are not locked.
# It's not exploitable for any useful purpose, and it's blocked by the floodcheck
#
# Changelog:
# 2008/02/20 04:20: Added changelog, fixed $txt error that killed all posts
# 2008/02/20 04:56: Fixed the signal-ratio filter to handle the stupid HTML
# 2008/02/20 05:21: Added a check for repeated characters
# 2008/02/20 07:05: Added a check for long spams
# 2008/02/20 13:02: Rearranged the filters for better results.
# 2008/02/20 23:58: Fixed a bug that broke posts with two quotes far apart
# 2008/02/21 01:57: Fixed a dumb bug with the number filter..
# 2008/02/21 02:06: Adding content-percentage info to the content filter.
# 2008/02/21 02:15: Adjusted long-text filter.
# 2008/02/21 02:18: Removed long-text filter.
# 2008/02/22 16:43: Added mute-expiring.
# 2008/02/22 17:54: Fixed mute-expiring.
# 2008/02/22 18:13: Added #nextnow and #muteinfo secret mod capcodes
# 2008/02/22 18:21: Fixed #muteinfo for mods.


define('R9K_SIGNAL_RATIO',0.1); # This is a cutoff for posts with high compressibility
define('R9K_MAX_DURATION',31536000); # That'd be a full year. Needless to say, if you hit this you're spamming bigtime
define('R9K_DATE_FORMAT','%m/%d/%y(%a)%H:%M:%S');
define('R9K_DEMUTE_PERIOD',86400); # one day

function robot9000($name,$email,$sub,$com,$md5,$ip,$mod){
	if($email=='#nextnow' && $mod){
		mysql_call("update r9k_mutes set next_expire=NOW() where IP=$ip;");
		return 'Your next_expire has been reset.';
	}
	if($name=='Anonymous'){
		$name=''; # We don't care about anonymous
	}

	if($md5=='d41d8cd98f00b204e9800998ecf8427e'){ # empty file, same as no file
		$md5=null;
	}

	# Textless posts would allow users to simply avoid the robot9000 stuff by pretending 
	# there are no comment fields, and just writing text on the picture. 
	if($com==''){
		return "Textless posts are not allowed";
	}
	$demute=false; 
	# Check if the user is already banned
	$timeout_power=0; 
	if(!$mod || $email=='#muteinfo'){
		# Can't inject, since $ip is numeric
		$res=mysql_call("select timeout_power,UNIX_TIMESTAMP(mute_until),UNIX_TIMESTAMP(next_expire) from r9k_mutes where ip=$ip"); 
		if($res){
			$row=mysql_fetch_row($res);
			if($row){
				$now=time();
				$timeout_power=$row[0];
				if($email=='#muteinfo'){ # SECRET CODES
					$notmuted='not';
					if($row[1]>$now){
						$notmuted='';
					}
					$when_timeout=strftime(R9K_DATE_FORMAT,$row[1]);
					$when_next_expire=strftime(R9K_DATE_FORMAT,$row[2]);
					return "You're $notmuted muted. <br />Mute timeout: $when_timeout. <br />Next expire: $when_next_expire.<br />Mute power: $timeout_power.";
				}
				if($row[1]>$now){ # Already muted!
					$duration=niceDuration($row[1]-$now);
					$when=strftime(R9K_DATE_FORMAT,$row[1]);
					return "You're muted! You cannot post until $when, $duration from now"; #TODO: prettier date display
				}
				if($row[2]<$now){
					$demute=true;
				}
			}
		}else{
			if($email=='#muteinfo'){
				return 'You have never been muted.';
			}
		}
	}
	
	$mute=null;
	
	# Clean up the post so that they can't avoid getting mutted with odd characters. 
	if(preg_match('/[\\x80-\\xFF]/',$com)){
		return "Non-ASCII text is not allowed.";
	}

	$txt=strtolower($com);
	$stxt=preg_replace('/<.*?>/s','',$txt); # strip HTML
	/*if(preg_match('|[^\s/]{20,}|',$stxt)){ # Lots of text in a row, no spaces, not URLs
		$mute='signal';
	}*/
	$olength=strlen($stxt); # After HTML stripping because otherwise it's bad. 
	$stxt=preg_replace('/&gt;&gt;\d+/','',$stxt); # strip >>links of the dead kind
	$stxt=preg_replace('/&#?\w+;/','',$stxt); # Strip entities
	$stxt=preg_replace('/[^a-z\d-]+/','',$stxt); # strip non-alphanum characters
	$stxt=preg_replace('/^\d*(.*)\d*$/','\1',$stxt); # strip numbers at beginning and end of text
	$stxt=preg_replace('/(.)(\\1{3,})/','\\1',$stxt); # strip repeated characters
	# This can't raise a divide by zero error since we already tested if $com is empty
	if(strlen($txt)>10 && strlen($stxt)/$olength<R9K_SIGNAL_RATIO){ 
		$mute='signal-ratio';
		$ratio=strlen($stxt)/$olength;
	}
	
	if(!$mute){ # not muted, so try and add the comment/image md5s to the DB
		$txtmd5=mysql_real_escape_string(substr($stxt,0,32));
		$md5str=($md5==null)?'NULL':"'$md5'";
		if(!mysql_call("insert into r9k_posts(text,image) values('$txtmd5',$md5str);")){ # txtmd5 is escaped and $md5 is supplied by trusted code
			# So the comment or text is not original. To give better error messages, we find out why:
			$mute="dunno"; # Default mute reason incase something weird happens
			$res=mysql_call("select text,image from r9k_posts where text='$txtmd5' or image='$md5'"); # see above SQL
			if($res){
				while($row=mysql_fetch_row($res)){
					if($row[0]==$txtmd5){
						$mute="text";
					}
					if($row[1]!=null && $row[1]==$md5){
						$mute="image";
					}
				}
			}
		}
	}
	if($mute){
		# User is bad, so time to mute

		# Set up a pretty reason
		$why='of an unknown error.'; # default reason
		if($mute=='text'){
			$why="your comment was not original.";
		}elseif($mute=='image'){
			$why="your image was not original.";
		}elseif($mute=='signal'){
			$why="your comment was too low in content.";
		}elseif($mute=='signal-ratio'){
			$sr=sprintf('%0.2f%%',$ratio*100.0);
			$why="your comment was too low in content ($sr content)";
		}

		if(!$mod){
			$timeout_power++;
			$mute_duration=pow(2,$timeout_power);

			if($mute_duration>R9K_MAX_DURATION){
				$timeout_power--; # Reset the power so that we don't ever overflow
				$mute_duration=R9K_MAX_DURATION;
			}
			$next_expire=R9K_DEMUTE_PERIOD; # stupid constants
			mysql_call("insert into r9k_mutes(ip,timeout_power,mute_until,next_expire) 
				values($ip,$timeout_power,DATE_ADD(NOW(),interval $mute_duration SECOND),DATE_ADD(NOW(),interval $next_expire SECOND))
				on duplicate key update timeout_power=$timeout_power,mute_until=VALUES(mute_until),next_expire=VALUES(next_expire)");

			return 'You have been muted for '.niceDuration($mute_duration).", because $why";
		}else{
			return "You would have been muted for that post, because $why";
		}
	}else{
		if($demute){ # We only demute when posting and your last mute was 
			$next_expire=R9K_DEMUTE_PERIOD;
			mysql_call("update r9k_mutes set timeout_power=IF(timeout_power>0,timeout_power-1,0),next_expire=DATE_ADD(NOW(),INTERVAL $next_expire SECOND) where IP=$ip;");
		}
		return 'ok'; # Everything went OK, user can post!
	}
}

# HELPER FUCTIONS
function niceDuration($secs){
	$w=(int)($secs/604800);
	$d=(int)($secs/86400) % 7;
	$h=(int)($secs/3600) % 24;
	$m=((int)($secs/60)) % 60;
	$s=((int)$secs) % 60;
	$out=array();
	$pairs=array(
		array($w,'week'),
		array($d,'day'),
		array($h,'hour'),
		array($m,'minute'),
		array($s,'second'));
	foreach($pairs as $v){
		if($v[0]!=0){
			$out[]=$v[0].' '.$v[1].($v[0]==1?'':'s');
		}
	}
	return implode(' ',$out);
}
?>
