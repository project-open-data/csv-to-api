<?php

# WordPress makes it possible to use WordPress functions outside of WordPress, by including its
# wp-load.php library.
$parse_uri = explode( 'wp-content', $_SERVER['SCRIPT_FILENAME'] );
require_once($parse_uri[0] . 'wp-load.php');

# Include Instant API's function library.
require_once('class.csv-to-api.php');

# Create a new instance of the Instant API class.
$ia = new Instant_API();

# Intercept the requested URL and use the parameters within it to determine what data to respond with.
$ia->parse_query();

# Gather the requested data from its CSV source, converting it into JSON, XML, or HTML.
$ia->parse();

# Send the JSON to the browser.
echo $ia->output();