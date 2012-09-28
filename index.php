<?php

# Include Instant API's function library.
require_once('class.csv-to-api.php');

# Create a new instance of the Instant API class.
$ia = new CSV_To_API();

# Intercept the requested URL and use the parameters within it to determine what data to respond with.
$ia->parse_query();

# Gather the requested data from its CSV source, converting it into JSON, XML, or HTML.
$ia->parse();

# Send the JSON to the browser.
echo $ia->output();