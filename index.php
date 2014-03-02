<?php
require_once('config.php');
// Page to compare owned Steam games for users to find something to play...

if( ($_SERVER['REQUEST_METHOD'] == "GET") and (strlen($_GET['SteamNames']) > 1))
{	// Let's go ahead and grab user info and go from there...
	$AllUsers = array();
	$AllGames = array();
	$Users = explode(",",$_GET['SteamNames']);
	foreach($Users as $SteamName)
	{
		$ch = curl_init("http://steamcommunity.com/id/".$SteamName."/?xml=1");
		curl_setopt($ch, CURLOPT_RETURNTRANSFER, 1);
		curl_setopt($ch,CURLOPT_HEADER, 0);
		$Output = curl_exec($ch);
		$p = xml_parser_create();
		xml_parse_into_struct($p, $Output, $Vals, $index);
		$SteamID64 = $Vals[ $index['STEAMID64'][0] ]['value'];
		echo $SteamName.": Steam ID is ".$SteamID64."<br/>";
		// Now we have the 64bit SteamID. We should go and 
		$SteamGamesOwned = pullAllSteamGames($SteamID64, $SteamAPIKey);
		//print_r($SteamGamesOwned);
		$SteamGames = parseSteamGames($SteamGamesOwned);
		//print_r($SteamGames);
		//
		//Now we should pack this into the earlier array and move on.
		$AllUsers[] = array("SteamName" => $SteamName, "SteamID" => $SteamID64, "Games" => $SteamGames);
		curl_close($ch);
		flush();
	}
	//print_r($AllUsers);
	echo "Beginning analysis of games for ".count($AllUsers)." people....<br/>";
	flush();
	// Now that we have arrays with all of the games, we'll need to compare them...
	$TempGames = $AllUsers[0]['Games'];
	for($i = 1; $i < count($AllUsers); $i++)
	{	// Compare keys between the first user and all other users.
		echo "&nbsp;&nbsp;Comparing ".$AllUsers[0]['SteamName']." to ".$AllUsers[$i]['SteamName']."...<br/>";
		$KeysToRemove = array_diff_key($TempGames, $AllUsers[$i]['Games']);
		echo "&nbsp;&nbsp;&nbsp;&nbsp;We started with ".count($TempGames)." eligible games and are removing ".count($KeysToRemove).", resulting in ". (count($TempGames)-count($KeysToRemove))." games overall.<br/>";
		//print_r($KeysToRemove);
		foreach($KeysToRemove as $k => $v)
		{
			unset($TempGames[$k]);
		}
	}
	
	
	//Remove any games that aren't multiplayer
	echo "Removing single player only games:<br/>";
	flush();
	foreach($TempGames as $GameID => $Gametime)
	{
		$GameInfo = parseApp($GameID);
		$GameInfo['name'] = preg_replace("/[^A-Za-z0-9 ]/", '', $GameInfo['name']);
		$MetaInfo = $GameInfo['categories'];
		$IsMulti = FALSE;
		foreach($MetaInfo as $InfoArr)
		{
			if($InfoArr['description'] == 'Multi-player')
			{	
				$IsMulti = TRUE;
			}
			if($InfoArr['description'] == 'Co-op')
			{	
				$IsMulti = TRUE;
			}
		}
		// We need to search to see if it has multiplayer. If it doesn't? Don't play it.
		if($IsMulti)
		{
			$AllGames[$GameID] = $GameInfo;
		}
		else
		{
			echo "&nbsp;&nbsp;".$GameInfo['name']."<br/>";
			unset($TempGames[$GameID]);
		}
		flush();
	}
	echo "After removing non-multiplayer games, we have ".count($TempGames)." games left.<br/>";
	
	
	
	
	
	//Now we have all of the games sorted out
	echo "Games have been analyzed. Determining best games to play...<br/>";
	echo "&nbsp;&nbsp;Most popular 5 games:<br/>";
	//print_r($TempGames);
	$SortedGametime = sumGametime($TempGames, $AllUsers);
	//Now get the top 5 app info...
	$Keys = array_keys($SortedGametime);
	//print_r($Keys);
	$GamesFound = 0;
	$i = 0;
	while( ($GamesFound < 5) and ($i < count($SortedGametime)))
	{
		echo "<a href='http://store.steampowered.com/app/".$Keys[$i]."'><img src='".$AllGames[$Keys[$i]]['header_image']."' width='153' height='72'/>".$AllGames[$Keys[$i]]['name']."</a><br/>";
		$GamesFound++;
		flush();
		$i++;
	}
	
	
	//Randomly pick 5 games.
	shuffle($Keys);
	echo "&nbsp;&nbsp;5 random games:<br/>";
	$GamesFound = 0;
	$i = 0;
	while( ($GamesFound < 5) and ($i < count($SortedGametime)))
	{
		echo "<a href='http://store.steampowered.com/app/".$Keys[$i]."'><img src='".$AllGames[$Keys[$i]]['header_image']."' width='153' height='72'/>".$AllGames[$Keys[$i]]['name']."</a><br/>";
		$GamesFound++;
		flush();
		$i++;
	}
	
	
	
}
	



echo "Find the person's Steam Community ID by going to something like http://steamcommunity.com/id/xxxxxxxxxx/ . Mine is <a>http://steamcommunity.com/id/a10waveracer/</a>.<br/>";
echo "<form method='GET' action='index.php' ><br/>
	Enter Steam Community IDs, separated by commas:<br/>
	<input type='text' name='SteamNames' /><br/>
	<input type='submit' value='Find me some games!' />
	</form>";

function pullAllSteamGames($SteamID, $APIKey)
{
	$ch2 = curl_init("http://api.steampowered.com/IPlayerService/GetOwnedGames/v0001/?key=".$APIKey."&steamid=".$SteamID."&format=json");
	curl_setopt($ch2, CURLOPT_RETURNTRANSFER, 1);
	curl_setopt($ch2,CURLOPT_HEADER, 0);
	$CurlOutput = curl_exec($ch2);
	curl_close($ch2);
	// Now parse the json
	$GetOwnedGames = json_decode($CurlOutput, TRUE);
	return $GetOwnedGames;
}
function parseSteamGames($GamesArray)
{
	$OwnedGames = array();
	$GamesArray = $GamesArray['response']['games'];
	foreach($GamesArray as $Game)
	{
		$OwnedGames[$Game['appid']] = $Game['playtime_forever'];
	}
	return $OwnedGames;
}
function sumGametime($OwnedGames, $AllUsers)
{	// Given a games array with array('appid' => 'gametime')
	$OutputArr = array();
	foreach($OwnedGames as $GameID => $Gametime)
	{
		$OutputArr[$GameID] = 0;
		for($i = 0; $i < count($AllUsers); $i++)
		{
			$OutputArr[$GameID] += $AllUsers[$i]['Games'][$GameID];
		}
	}
	arsort($OutputArr);
	return $OutputArr;
}
function printDelim()
{
	echo "<br/>***************************************<br/>";
}
function parseApp($AppID)
{
	$ch2 = curl_init("http://store.steampowered.com/api/appdetails/?appids=".$AppID);
	curl_setopt($ch2, CURLOPT_RETURNTRANSFER, 1);
	curl_setopt($ch2,CURLOPT_HEADER, 0);
	$CurlOutput = curl_exec($ch2);
	curl_close($ch2);
	// Now parse the json
	$GameInfo = json_decode($CurlOutput, TRUE);
	$GameInfo = $GameInfo[$AppID]['data'];
	return $GameInfo;
}


?>