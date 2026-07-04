<?php
include("common.php");
my_session_start();

$_SESSION["PHSA_USER"]	= "";
$_SESSION["PHSA_UID"]	= "";
$_SESSION["PHSA_UNAME"]	= "";
$_SESSION["lastAcc"]			= "";
$_SESSION["key"]				= "";


unset($_SESSION["PHSA_USER"]);
unset($_SESSION["PHSA_UID"]);
unset($_SESSION["PHSA_UNAME"]);
unset($_SESSION["lastAcc"]);
unset($_SESSION["key"]);


header("Location:bye.php");

echo "<script language='javascript'>";
echo "<!-- \n";
echo "window.location='bye.php';";
echo "// -->";
echo "</script>";


?>