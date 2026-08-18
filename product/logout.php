<?php
session_start();

// Store some session data for the logout page (if needed)
$_SESSION['logout_message'] = "You have been successfully logged out!";
$_SESSION['logout_animation'] = true;

session_destroy();
header("location: logout_transition.php");
exit;
?>
