CSV to API
===========

Dynamically generate RESTful APIs from static CSVs. Provides JSON, XML, and HTML.

What Problem This Solves
------------------------

The simplicity with which CSV files can be created has made them the default data format for bulk data. It is comparatively more difficult to create an API to share the same data atomically and transactionally.

How This Solves It
------------------

*CSV to API* acts as a filter, sitting between CSV and the browser, allowing users to interact with that CSV as if it was a native API. The column names (that is, the cells that comprise the first row in the file) function as the key names.

Note that this can be run on any server to create an API for any CSV file on any server. There is no need to install *CSV to API* for each unique CSV file or even each unique server—an organization can link to each and every one of their CSV files via *CSV to API*, or an individual could even use their own installation of *CSV to API* to access arbitrary remote CSV files as if they were APIs.

When Alternative PHP Cache (APC) is installed, parsed data is stored within APC, which accellerates  its functionality substantially. While APC is not required, it is recommended highly.

Requirements
------------

* PHP
* APC (optional)

Usage
-----

1. Copy `class.csv-to-api.php` and `index.php` to your web server.
2. Load a CSV file via the URL `index.php`, using the arguments below.

Arguments
---------

* `source`: the URL to the source CSV
* `source_format`: if the url does not end in `.csv`, you should specify 'csv' here (to facilitate future functionality)
* `format`: the requested return format, either `json`, `xml`, or `html` (default `json`)
* `callback`: if JSON, an optional JSONP callback
* `sort`: field to sort by (optional)
* `sort_dir`: direction to sort, either `asc` or `desc` (default `asc`)
* any field(s): may pass any fields as a key/value pair to filter by

Example Usage
-------------

All examples use [data from REXUS](http://catalog.data.gov/dataset/real-estate-across-the-united-states-rexus-inventory-building), the primary tool used by the Public Building Service to track and manage the government's real property assets and to store inventory data, building data, customer data, and lease information.

### Get CSV as JSONP (default behavior)
http://labs.data.gov/csv-to-api/?source=http://www.gsa.gov/dg/data_gov_bldg_star.csv

### Get results as XML

http://labs.data.gov/csv-to-api/?source=http://www.gsa.gov/dg/data_gov_bldg_star.csv&format=xml

### Get results as JSONP with a specified callback

http://labs.data.gov/csv-to-api/?source=http://www.gsa.gov/dg/data_gov_bldg_star.csv&format=json&callback=parse_results

### Get results as HTML

http://labs.data.gov/csv-to-api/?source=http://www.gsa.gov/dg/data_gov_bldg_star.csv&format=html

### Sort by a field

http://labs.data.gov/csv-to-api/?source=http://www.gsa.gov/dg/data_gov_bldg_star.csv&sort=Bldg+Zip

### Filter by a field

http://labs.data.gov/csv-to-api/?source=http://www.gsa.gov/dg/data_gov_bldg_star.csv&Region+Code=11

License
-------
GPLv3 or later.
