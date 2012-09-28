CSV to API
===========

Proof of concept to dynamically generate RESTful APIs from static CSVs. Provides JSON and XML.

Requirements
------------

Requires a LAMP server

Usage
-----

1. Clone the repository into your server's `htdocs` folder
2. Navigate to the folder in your favorite web browser
3. Query the API using the arguments below

Arguments
---------

* `source` - The URL to the source CSV
* `source_format` - if the url does not end in `.csv`, you may need to pass 'csv' here. This allows for flexibility down the line
* `format` - the requested return format, either `json`, `xml`, or `html`
* `callback` - if JSON, an optional jsonp callback
* `sort` - field to sort by (optional)
* `sort_dir` - direction to sort, either `ASC` or `DESC`
* any field(s) - may pass any fields as a key value pair to filter by

Example Usage
-------------

### Get results as XML

http://localhost/csv-to-api/?source=https://explore.data.gov/download/7tag-iwnu/CSV&source_format=csv&format=xml

### Get Results as JSONP

http://localhost/csv-to-api/?source=https://explore.data.gov/download/7tag-iwnu/CSV&source_format=csv&format=json&callback=parse_results

### Sort by a field

http://localhost/csv-to-api/?source=https://explore.data.gov/download/7tag-iwnu/CSV&source_format=csv&sort=Region

### Filter by a field

http://localhost/csv-to-api/?source=https://explore.data.gov/download/7tag-iwnu/CSV&source_format=csv&Depth=5.00

License
-------
GPLv3 or Later