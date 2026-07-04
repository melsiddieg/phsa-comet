<?php
require_once("db.php");
require_once("common.php");

my_session_start();

if( verify_session(false) )
{
	header("Location:index.php");

	echo "<script language='javascript'>";
	echo "<!-- \n";
	echo "window.location='index.php';";
	echo "// -->";
	echo "</script>";
	die();
}



$pdo 	= new PDO('mysql:host=' . $_MY_SERV . ';dbname=' . $_MY_DB, $_MY_USER , $_MY_PASS);


$errMsg	= "";

$expected = array("username", "password");

if( count($_POST) && check_expected($expected) )
{
	
	$ip = get_ip(); 
//	die("$ip");

	$sql	= "select COUNT(*) as cnt from loginfailures where ip='$ip' AND ((date_time + INTERVAL 15 MINUTE) > now())";
	$rs = $pdo->query($sql);
	$ft = $rs->fetch(PDO::FETCH_ASSOC);

	if( $ft["cnt"] > 6 )
		$errMsg="You have been blocked due to several unsuccessful login tries during the last few minutes. Please try again after 15 minutes.";

	else
	{
		$un		= trim( str_replace("'","", str_replace("\"","",strip_tags($_POST["username"]))) );
		$pw		= trim( str_replace("'","", str_replace("\"","",strip_tags($_POST["password"]))) );

		$sql = "select  *  from  phsa_users  where UPPER(username) = UPPER('$un') AND pass_word = CONCAT('*', UPPER(SHA1(UNHEX(SHA1('$pw')))))";	

		$rs = $pdo->query($sql);
		if( !($ft = $rs->fetch(PDO::FETCH_ASSOC)) ) 
		{
			$errMsg = "Wrong UserName and/or Password. Please try again. You will be blocked for several minutes from logging in after 6 wrong username and/or password tries.";
			$sql = "insert into loginfailures(ip, date_time, username) values('$ip',now(),'$un')";
			$rs = $pdo->query($sql);
		}

		elseif( $ft["enabled"] != "1" )
		{
			$errMsg = "This account is disabled. Please contact us for further details.";
			$sql = "insert into loginfailures(ip, date_time, username) values('$ip',now(),'$un')";
			$rs = $pdo->query($sql);
		}
			
		else
		{
			$now = time();
		
			$_SESSION["PHSA_USER"]			= "Y";
			$_SESSION["PHSA_UID"]			= $ft["id"];
			$_SESSION["PHSA_UNAME"]			= $ft["username"];
			$_SESSION["PHSA_PRIV_MAP"]		= $ft["mapper"];
			$_SESSION["PHSA_PRIV_IMPORT"]	= $ft["importer"];
			$_SESSION["PHSA_PRIV_REVIEW"]	= $ft["reviewer"];
			$_SESSION["lastAcc"]			= $now;
			$_SESSION["key"]				= md5(_SESSION_PASS . $now);

			$sql = "update phsa_users set last_login_time = now() , last_login_ip = '$ip' where UPPER(username) = UPPER('$un')";	
			$rs = $pdo->query($sql);


			if( isset($_GET["ret"]) )
			{
				$ret_url = substr(trim( str_replace("'","", str_replace("\"","", strip_tags($_GET["ret"]) )) ), 0, 50);
			}
			else
			{
				$ret_url = "index.php";
			}


			?>
			<script language='javascript'>
			<!--
			window.location='<?php echo $ret_url; ?>';
			// -->
			</script>
			You are now being redirected. In case you are not redirected in a few seconds, please <a href='<?php echo $ret_url; ?>'>click here</a>.
			<?php
			die();

		}
	}

}

?>
<html>
<head>
<title>COMET Login Page</title>
<link rel="icon" href="comet.png">
</head>
<body style='background-color:#98dfff; padding:50px;'>
<div align='center'>
<table border='0' cellspacing='40'>
<tr>
	<td style='width:220px;' align='center'></td>
	<td style='padding:0px; width:285px;'>

<div style='width:365px;'>
<table border='1'  cellspacing='0' cellpadding='20'  style='background-color:#eaeaea; font-family:Arial;'><tr><td>
<div style="font-family:Times New Roman; font-size:30px; font-weight:bold;">Users Login</div><br/>

<?php
if( $errMsg != "" )
	echo "<div style='color:#dd0000; font-weight:bold; padding:13px;'>$errMsg</div>";
?>

<form method='post' action='#'>
		<table border='0' cellspacing='0' cellpadding='0'>

			<tr>
				<td style='padding:11px 15px 0px 15px;' nowrap><span class='tbl_userpass'>Username:</td>
				<td style='padding:10px 15px 1px 15px;'><input type='text' name='username' size='25' maxlength='100' class='userpass_input'/></td>
			</tr>
			<tr>
				<td style='padding:11px 15px 0px 15px;'><span class='tbl_userpass'>Password:&nbsp;</span></td>
				<td style='padding:10px 15px 1px 15px;'><input type='password' name='password' size='25' maxlength='50' class='userpass_input'/></td>
			</tr>
			<tr>
				<td style='padding:11px 15px 0px 15px;' colspan='2'><div align='right'><input type='submit' value='Login' class='login_btn'></div></td>
			</tr>
		</table>
</form>

</td></tr></table>

</div>

	</td>
	<td style='width:220px;' align='center'>
</tr>
</table>
</div>
</body>
</html>