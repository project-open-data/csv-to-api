<?php

# Include Instant API's function library.
require_once('class.csv-to-api.php');

# No Source file is given, just show documentation
if ( !isset( $_REQUEST['source'] ) ) {
  echo "<PRE>";
  require "README.md";
  die();
}

# Create a new instance of the Instant API class.
$api = new CSV_To_API();

# Intercept the requested URL and use the parameters within it to determine what data to respond with.
$api->parse_query();

# Gather the requested data from its CSV source, converting it into JSON, XML, or HTML.
$api->parse();

# Send the JSON to the browser.
echo $api->output();