<?php
require_once("db.php");
#require_once __DIR__ . '/../src/db.php';
require_once("common.php");
#require_once __DIR__ . '/../src/common.php';
my_session_start();
#var_dump( verify_session(false) ); exit;   //  ← TEMP probe

#verify_session();
$pdo 	= new PDO('mysql:host=' . $_MY_SERV . ';dbname=' . $_MY_DB, $_MY_USER , $_MY_PASS);

?>
<html>
<head>
<link rel="stylesheet" href="styles.css?v=1">
<link rel="icon" href="comet.png">
<title>COMET - Central Online Mapping and Export Tool</title>
<style>
td.menu 
{
  padding: 2px;
  width: 100%;
  border: none;
  text-align: left;
  outline: none;
}

td.menu:hover 
{
  background-color: #A0680C;
}

a.menu_link 
{
  color: #F7E4C6;
  cursor: pointer;
  border: none;
  text-align: left;
  outline: none;
  font-size: 20px;
  text-decoration:none;
}

a.menu_link:hover 
{
  color: #ffffff;
}

</style>
</head>

<body style='font-family:Arial; background-color:#FFFAF2;'>

<div id="index_menu" class="sidenav">
	<a href="javascript:void(0)" class="closebtn" onclick="close_index_menu()">&times;</a>
	<div style='margin:20px;'>
		<div style='font-size:35px; color:#ffe7b7; font-weight:bold;'>Menu</div><br/><br/>
		<table width='100%'>
			<tr><td class='menu'><a class='menu_link' href='index_source.php'>Source Terms by Cerner Area</a></td></tr>
			<tr><td class='menu'><a class='menu_link' href='index_domain.php'>Mapped Terms by OMOP Domain</a></td></tr>
			<?php
			if( isset($_SESSION["PHSA_PRIV_REVIEW"]) && $_SESSION["PHSA_PRIV_REVIEW"] === "1" )
				echo "<tr><td style='height:20px;'></td></tr><tr><td class='menu'><a class='menu_link' href='list_review.php?start=0'>Review Mapping Changes</a></td></tr>";
			?>
			<tr><td style='height:20px;'></td></tr>
			<tr><td class='menu'><a class='menu_link' href='export_maps.php'>Export Maps</a></td></tr>
			<tr><td class='menu'><a class='menu_link' href='export_exclusions.php'>Export Exclusions</a></td></tr>
			<tr><td style='height:20px;'></td></tr>
			
			<?php
			if( isset($_SESSION["PHSA_PRIV_IMPORT"]) && $_SESSION["PHSA_PRIV_IMPORT"] === "1" )
			{
				echo "<tr><td class='menu'><a class='menu_link'>Import Excel File</a></td></tr>";
				echo "<tr><td class='menu'><a class='menu_link' href='import_mr_txt.php'>Import Text File</a></td></tr>";
			}
			?>
			
			<tr><td style='height:40px;'></td></tr>
			<tr><td class='menu'><a class='menu_link' href='logout.php'>Sign Out</a></td></tr>
		</table>
	</div>
</div>

<span style="font-size:33px;cursor:pointer; background-color:#875503; color:#F8EBD5; padding:8px 15px; " onclick="open_index_menu()">&#9776;</span>

<div align = 'center'>
<table width='1000px'  height='90%' border='0' cellpadding = '5'>
<tr>
	<td align='left' valign='middle' style="font-size:70px; color:#C28119; font-family: Arial; font-weight:bold; "><i>Welcome to</i></td>
</tr>
<tr>
	<td></td>
</tr>
<tr>
	<td align='center' valign='top'><img src='comet_large.png' border='0' width='500' alt='COMET - CENTRAL ONLINE MAPPING EXPORT TOOL' /></td>
</tr>
</table>
</div>


<script>
function open_index_menu() 
{
	document.getElementById("index_menu").style.width = "400px";
}

function close_index_menu() 
{
	document.getElementById("index_menu").style.width = "0";
}
</script>

</body>
</html>
