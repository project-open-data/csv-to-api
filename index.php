<?php
include '../wp/wp/wp-load.php';
include 'class.instant-api.php';
$ia = new Instant_API();


$ia->parse_query();
$ia->parse();
echo $ia->output();