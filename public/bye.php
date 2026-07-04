<?php
include("common.php");
my_session_start();

unset($_SESSION["PHSA_USER"]);
unset($_SESSION["PHSA_UID"]);
unset($_SESSION["PHSA_UNAME"]);
unset($_SESSION["lastAcc"]);
unset($_SESSION["key"]);

header("Location:index.php");

echo "<script language='javascript'>";
echo "<!-- \n";
echo "window.location='index.php';";
echo "// -->";
echo "</script>";
?>