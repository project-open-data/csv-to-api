<?php

class CSV_To_API {

  public $ttl = 3600;
  public $source = null;
  public $source_format = null;
  public $format = null;
  public $callback = null;
  public $sort = null;
  public $sort_dir = null;
  public $data;

  /**
   * Use the query (often the requested URL) to define some settings.
   */
  function parse_query( $query = null ) {

    // If a query has been passed to the function, turn it into an array.
    if ( is_string( $query ) ) {
      $query = $this->parse_args( $query );
    }

    // If a query has not been passed to this function, just use the array of variables that
    // were passed in the URL.
    if (is_null($query)) {
      $query = $_GET;
    }

    // Define a series of configuration variables based on what was requested in the query.
    $this->source = isset( $query['source'] ) ? $this->esc_url( $query['source'] ) : null;
    $this->source_format = isset( $query['source_format'] ) ? $query['source_format'] : $this->get_extension( $this->source );
    $this->format = isset( $query['format'] ) ? $query['format'] : 'json';
    $this->callback = isset( $query['callback'] ) ? $this->jsonp_callback_filter( $query['callback'] ) : false;
    $this->sort = isset( $query['sort'] ) ? $query['sort'] : null;
    $this->sort_dir = isset( $query['sort_dir'] ) ? $query['sort_dir'] : "desc";
    $this->header_row = isset( $query['header_row'] ) ? $query['header_row'] : "y";
	
    return get_object_vars( $this );

  }


  /**
   * Fetch the requested file and turn it into a PHP array.
   */
  function parse() {

    // Create an instance of the parser for the requested file format (e.g. CSV)
    $parser = 'parse_' . $this->source_format;

    if ( !method_exists( $this, $parser ) ) {
      header( '400 Bad Request' );
      die( 'Format not supported' );
    }

    // Attempt to retrieve the data from cache
    $key = 'csv_to_api_' . md5( $this->source );
    $this->data = $this->get_cache( $key );

    if ( !$this->data ) {

      // Retrieve the requested source material via HTTP GET.
      if (ini_get('allow_url_fopen') == true) {
        $this->data = file_get_contents( $this->source );
      }
      else {
        $this->data = $this->curl_get( $this->source );
      }

      if ( !$this->data ) {
        header( '502 Bad Gateway' );
        die( 'Bad data source' );
      }

      // Turn the raw file data (e.g. CSV) into a PHP array.
      $this->data = $this->$parser( $this->data );

      // Save the data to WordPress' cache via its Transients API.
      $this->set_cache( $key, $this->data, $this->ttl );

    }
    
    $this->data = $this->query( $this->data );
    
    return $this->data;

  }


  /**
   * Return to the client the requested data in the requested format (e.g. JSON).
   */
  function output() {

    $function = 'object_to_' . $this->format;

    if ( !method_exists( $this, $function) ) {
      return false;
    }

    // Send to the browser a header specifying the proper MIME type for the requested format.
    $this->header( $this->format );
    $output = $this->$function( $this->data );

    // Prepare a JSONP callback.
    $callback = $this->jsonp_callback_filter( $this->callback );

    // Only send back JSONP if that's appropriate for the request.
    if ( $this->format == 'json' && $this->callback ) {
      return "{$this->callback}($output);";
    }

    // If not JSONP, send back the data.
    return $output;

  }


  /**
   * Create a key name, based on a CSV column header name, that is safe to embed in JavaScript
   * or XML.
   */
  function sanitize_key( $key ) {

    $key = $this->sanitize_title( $key );
    $key = str_replace( '-', '_', $key );
    return $key;

  }


  /**
   * Determine the file type of the requested file based on its extension.
   */
  function get_extension( $source = null ) {

    if ( $source == null ) {
      $source = $this->source;
    }

    $url_parts = parse_url( $source );
    $url_parts = pathinfo( $url_parts['path'] );
    
    return isset( $url_parts['extension'] ) ? $url_parts['extension'] : '';

  }


  /**
   * Convert reserved XML characters into their entitity equivalents.
   */
  function xml_entities( $string ) {

    return str_replace( array("&", "<", ">", "\"", "'"), array("&amp;", "&lt;", "&gt;", "&quot;", "&apos;"), $string );

  }

  /** 
   * Normalize all line endings to Unix line endings
   * @param string the mixed-ending string to normalized
   * @return string the normalized string
   */
  function normalize_line_endings( $string ) {
    $string = str_replace( "\r\n", "\n", $string );
    $string = str_replace( "\r", "\n", $string );
    return $string;    
  }
  
  /**
   * Turn CSV into a PHP array.
   */
  function parse_csv( $csv ) {

    $csv = $this->normalize_line_endings( $csv );

    $lines = explode( "\n", $csv );
    $lines = $this->parse_lines( $lines );
    
	// If no header row exists, automatically create field names.
	if ($this->header_row == 'n') {
	
		for ($i=0; $i<count($lines[0]); $i++)
		{
			$headers[$i] = 'field-' . ($i+1);
		}
		
	}
	
	// If a header row exists, use that as the headers.
	else {
	    $headers = array_shift( $lines );
	}
    
    $data = array();
    foreach ( $lines as $line ) {

      $row = array();

      foreach ( $line as $key => $field ) {
        $row[ $this->sanitize_key( $headers[ $key ] ) ] = $field;
      }

      $row = array_filter( $row );
      $row = $this->array_to_object( $row );
      $data[] = $row;

    }

    return $data;

  }

  /**
   * Parse CSV into array of arrays
   * Wrapper function to allow pre 5.3 compatability
   * @param array the CSV data as an array of lines
   * @return array array of preset objects
   */
  function parse_lines( $lines ) {

    //php 5.3+
    if ( function_exists( 'str_getcsv' ) ) {

      foreach ( $lines as &$line )
        $line = str_getcsv( $line );

      //php 5.2
      // fgetcsv needs a file handle,
      // so write the string to a temp file before parsing
    } else {

      $fh = tmpfile();
      fwrite( $fh, implode( "\n", $lines ) );
      fseek( $fh, 0 );
      $lines = array();

      while( $line = fgetcsv( $fh ) )
        $lines[] = $line;

      fclose( $fh );

    }

    return $lines;

  }

  /**
   * Turn a PHP array into a PHP object.
   */
  function array_to_object( $array ) {

    $output = new stdClass();
    foreach ( $array as $key => $value ) {
      $output->$key = $value;
    }

    return $output;

  }


  /**
   * Turn a PHP object into JSON text.
   */
  function object_to_json( $data ) {

    return json_encode( $data );

  }


  /**
   * Turn a PHP object into XML text.
   */
  function object_to_xml( $array, $xml = null, $tidy = true ) {

    if ( $xml == null ) {
      $xml = new SimpleXMLElement( '<records></records>' );
    }

    // Array of keys that will be treated as attributes, not children.
    $attributes = array( 'id' );

    // Recursively loop through each item.
    foreach ( $array as $key => $value ) {

      // If this is a numbered array, grab the parent node to determine the node name.
      if ( is_numeric( $key ) ) {
        $key = 'record';
      }

      // If this is an attribute, treat as an attribute.
      if ( in_array( $key, $attributes ) ) {
        $xml->addAttribute( $key, $value );
      }

      // If this value is an object or array, add a child node and treat recursively.
      elseif ( is_object( $value ) || is_array( $value ) ) {
        $child = $xml->addChild( $key );
        $child = $this->object_to_xml( $value, $child, false );
      }

      // Simple key/value child pair.
      else {
        $value = $this->xml_entities( $value );
        $xml->addChild( $key, $value );
      }

    }

    if ( $tidy ) {
      $xml = $this->tidy_xml( $xml );
    }

    return $xml;

  }


  /**
   * Turn a PHP object into an HTML table.
   */
  function object_to_html( $data ) {

    $output = "<table>\n<thead>\n";
    $output .= "<tr>";

    foreach ( array_keys( get_object_vars( reset( $data ) ) ) as $header ) {
      $output .= "\t<th>$header</th>";
    }

    $output .= "</tr>\n</thead>\n<tbody>";

    foreach ( $data as $row ) {

      $output .= "<tr>\n";

      foreach ( $row as $key => $value ) {
        $output .= "\t<td>$value</td>\n";
      }

      $output .= "</tr>\n";

    }

    $output .= "</tbody>\n</table>";

    return $output;

  }


  /**
   * Pass XML through PHP's DOMDocument class, which will tidy it up.
   */
  function tidy_xml( $xml ) {

    $dom = new DOMDocument();
    $dom->preserveWhiteSpace = false;
    $dom->formatOutput = true;
    $dom->loadXML( $xml->asXML() );
    return $dom->saveXML();

  }


  /**
   * Send to the browser the MIME type that defines this content (JSON, XML, or HTML).
   */
  function header( $extension = null ) {

    if ( $extension == null ) {
      $extension = $this->extension;
    }

    $mimes = $this->get_mimes();

    if ( !isset( $mimes[ $extension ] ) || headers_sent() ) {
      return;
    }

    header( 'Content-Type: ' . $mimes[ $extension ] );

  }


  /**
   * Return MIME types
   * This way we do not allow additional MIME types elsewhere.
   */
  function get_mimes() {

    return array(
      'json' => 'application/json',
      'xml' => 'text/xml',
      'htm|html' => 'text/html',
    );


  }


  /**
   * Prevent malicious callbacks from being used in JSONP requests.
   */
  function jsonp_callback_filter( $callback ) {

    // As per <http://stackoverflow.com/a/10900911/1082542>.
    if ( preg_match( '/[^0-9a-zA-Z\$_]|^(abstract|boolean|break|byte|case|catch|char|class|const|continue|debugger|default|delete|do|double|else|enum|export|extends|false|final|finally|float|for|function|goto|if|implements|import|in|instanceof|int|interface|long|native|new|null|package|private|protected|public|return|short|static|super|switch|synchronized|this|throw|throws|transient|true|try|typeof|var|volatile|void|while|with|NaN|Infinity|undefined)$/', $callback) ) {
      return false;
    }

    return $callback;

  }


  /**
   * Parse the query parameters passed in the URL and use them to populate a complete list of
   * settings.
   */
  function query( $data, $query = null ) {

    if ( $query == null ) {
      $query = $_GET;
    }

    // Fill in any defaults that are missing.
    $query = $this->default_vars( $this->get_query_vars( $data ), $query );

    // Eliminate any value in $query that equal false.
    $query = array_filter( $query );

    $data = $this->list_filter( $data, $query );

    if ( $this->sort == null ) {
      return $data;
    }

    // Optionally sort the object.
    if ( $this->sort != null )
      usort( $data, array( &$this, 'object_sort' ) );

    return $data;

  }


  /**
   * Uses a PHP array to generate a list of all column names.
   */
  function get_query_vars( $data ) {

    $vars = array();
    foreach ( $data as $row ) {

      foreach ( $row as $key=>$value ) {

        if ( !array_key_exists( $key, $vars ) ) {
          $vars[ $key ] = null;
        }

      }

    }

    return $vars;

  }


  /**
   * Sorts an object, in either ascending or descending order.
   * @param object $a the first object
   * @param object $b the second object
   * @return int 0, 1, or -1 the ranking of $a to $b
   * 
   * The comparison function must return an integer less than, equal to, or greater than zero 
   * if the first argument is considered to be respectively less than, equal to, or greater than the second.
   * see http://php.net/manual/en/function.usort.php for more information
   */
  function object_sort( $a, $b ) {

    $sorter = $this->sort;

    //no sort by field supplied or invalid sort field, tell usort not to do anything
    if ( $sorter == null || !isset( $a->$sorter ) || !isset( $b->$sorter ) ) {
      return 0;
    }
    
    // A = B, tell usort not to do anything
    if ( $a->$sorter == $b->$sorter )
      return 0;
    
    //flip the return values depending on the sort direction
    if ( $this->sort_dir == "desc" ) {
      $up = -1;
      $down = 1;
    } else {
      $up = 1;
      $down = -1;
    }
    
    if ( $a->$sorter < $b->$sorter )
      return $down;
      
    if ( $a->$sorter > $b->$sorter )
      return $up;
        
  }


  /**
   * Retrieve data from Alternative PHP Cache (APC).
   */
  function get_cache( $key ) {

    if ( !extension_loaded('apc') || (ini_get('apc.enabled') != 1) ) {
      if ( isset( $this->cache[ $key ] ) ) {
        return $this->cache[ $key ];
      }
    }
    else {
      return apc_fetch( $key );
    }

    return false;

  }


  /**
   * Store data in Alternative PHP Cache (APC).
   */
  function set_cache( $key, $value, $ttl = null ) {

    if ( $ttl == null )
      $ttl = $this->ttl;

    if ( extension_loaded('apc') && (ini_get('apc.enabled') == 1) ) {
      return apc_store( $key, $value, $ttl );
    }

    $this->cache[$key] = $value;

  }

  function curl_get( $url ) {
    if ( !isset($url) ) {
      return false;
    }
    $ch = curl_init();
    curl_setopt($ch, CURLOPT_CONNECTTIMEOUT, 1);
    curl_setopt($ch, CURLOPT_TIMEOUT_MS, 1200);
    curl_setopt($ch, CURLOPT_URL, $url);
    curl_setopt($ch, CURLOPT_RETURNTRANSFER, 1);
    curl_setopt($ch, CURLOPT_FOLLOWLOCATION, true);
    $result = curl_exec($ch);
    curl_close($ch);
    return $result;
  }


  // All functions below this point taken from WordPress

  /**
   * Merge user defined arguments into defaults array.
   *
   * This function is used throughout WordPress to allow for both string or array
   * to be merged into another array.
   *
   * @since 2.2.0
   *
   * @param string|array $args Value to merge with $defaults
   * @param array $defaults Array that serves as the defaults.
   * @return array Merged user defined values with defaults.
   *
   * Source: WordPress, used under GPLv3 or Later
   */
  function parse_args( $args, $defaults = '' ) {
    if ( is_object( $args ) )
      $r = get_object_vars( $args );
    elseif ( is_array( $args ) )
      $r =& $args;
    else
      $this->parse_str( $args, $r );

    if ( is_array( $defaults ) )
      return array_merge( $defaults, $r );
    return $r;
  }


  /**
   * Parses a string into variables to be stored in an array.
   *
   * Uses {@link http://www.php.net/parse_str parse_str()} and stripslashes if
   * {@link http://www.php.net/magic_quotes magic_quotes_gpc} is on.
   *
   * @since 2.2.1
   * @uses apply_filters() for the 'wp_parse_str' filter.
   *
   * @param string $string The string to be parsed.
   * @param array $array Variables will be stored in this array.
   *
   * Source: WordPress, used under GPLv3 or Later
   */
  function parse_str( $string, &$array ) {
    parse_str( $string, $array );
    if ( get_magic_quotes_gpc() )
      $array = stripslashes_deep( $array );
  }


  /**
   * Checks and cleans a URL.
   *
   * A number of characters are removed from the URL. If the URL is for displaying
   * (the default behaviour) ampersands are also replaced. The 'clean_url' filter
   * is applied to the returned cleaned URL.
   *
   * @since 2.8.0
   * @uses wp_kses_bad_protocol() To only permit protocols in the URL set
   *  via $protocols or the common ones set in the function.
   *
   * @param string $url The URL to be cleaned.
   * @param array $protocols Optional. An array of acceptable protocols.
   *  Defaults to 'http', 'https', 'ftp', 'ftps', 'mailto', 'news', 'irc', 'gopher', 'nntp', 'feed', 'telnet', 'mms', 'rtsp', 'svn' if not set.
   * @param string $_context Private. Use esc_url_raw() for database usage.
   * @return string The cleaned $url after the 'clean_url' filter is applied.
   *
   * Source: WordPress, used under GPLv3 or Later
   *
   */
  function esc_url( $url, $protocols = null, $_context = 'display' ) {
    $original_url = $url;

    if ( '' == $url )
      return $url;
    $url = preg_replace('|[^a-z0-9-~+_.?#=!&;,/:%@$\|*\'()\\x80-\\xff]|i', '', $url);
    $strip = array('%0d', '%0a', '%0D', '%0A');
    $url = $this->_deep_replace($strip, $url);
    $url = str_replace(';//', '://', $url);
    /* If the URL doesn't appear to contain a scheme, we
  	 * presume it needs http:// appended (unless a relative
  	 * link starting with /, # or ? or a php file).
  	 */
    if ( strpos($url, ':') === false && ! in_array( $url[0], array( '/', '#', '?' ) ) &&
      ! preg_match('/^[a-z0-9-]+?\.php/i', $url) )
      $url = 'http://' . $url;

    // Replace ampersands and single quotes only when displaying.
    if ( 'display' == $_context ) {
      $url = $this->kses_normalize_entities( $url );
      $url = str_replace( '&amp;', '&#038;', $url );
      $url = str_replace( "'", '&#039;', $url );
    }

    if ( ! is_array( $protocols ) )
      $protocols = $this->allowed_protocols();
    if ( $this->kses_bad_protocol( $url, $protocols ) != $url )
      return '';

    return $url;

  }


  /**
   * Perform a deep string replace operation to ensure the values in $search are no longer present
   *
   * Repeats the replacement operation until it no longer replaces anything so as to remove "nested" values
   * e.g. $subject = '%0%0%0DDD', $search ='%0D', $result ='' rather than the '%0%0DD' that
   * str_replace would return
   *
   * @since 2.8.1
   * @access private
   *
   * @param string|array $search
   * @param string $subject
   * @return string The processed string
   *
   * Source: WordPress, used under GPLv3 or Later
   *
   */
  function _deep_replace( $search, $subject ) {
    $found = true;
    $subject = (string) $subject;
    while ( $found ) {
      $found = false;
      foreach ( (array) $search as $val ) {
        while ( strpos( $subject, $val ) !== false ) {
          $found = true;
          $subject = str_replace( $val, '', $subject );
        }
      }
    }

    return $subject;
  }


  /**
   * Converts and fixes HTML entities.
   *
   * This function normalizes HTML entities. It will convert "AT&T" to the correct
   * "AT&amp;T", "&#00058;" to "&#58;", "&#XYZZY;" to "&amp;#XYZZY;" and so on.
   *
   * @since 1.0.0
   *
   * @param string $string Content to normalize entities
   * @return string Content with normalized entities
   *
   * Source: WordPress, used under GPLv3 or Later
   *
   */
  function kses_normalize_entities($string) {
    # Disarm all entities by converting & to &amp;

    $string = str_replace('&', '&amp;', $string);

    # Change back the allowed entities in our entity whitelist

    $string = preg_replace_callback('/&amp;([A-Za-z]{2,8});/', array( $this, 'kses_named_entities' ), $string);
    $string = preg_replace_callback('/&amp;#(0*[0-9]{1,7});/', array( $this, 'kses_normalize_entities2' ), $string);
    $string = preg_replace_callback('/&amp;#[Xx](0*[0-9A-Fa-f]{1,6});/', array( $this, 'kses_normalize_entities3' ), $string);

    return $string;
  }


  /**
   * Retrieve a list of protocols to allow in HTML attributes.
   *
   * @since 3.3.0
   * @see wp_kses()
   * @see esc_url()
   *
   * @return array Array of allowed protocols
   *
   * Source: WordPress, used under GPLv3 or Later
   *
   */
  function allowed_protocols() {
    static $protocols;

    if ( empty( $protocols ) ) {
      $protocols = array( 'http', 'https', 'ftp', 'ftps', 'mailto', 'news', 'irc', 'gopher', 'nntp', 'feed', 'telnet', 'mms', 'rtsp', 'svn', 'tel', 'fax', 'xmpp' );
    }

    return $protocols;
  }


  /**
   * Sanitize string from bad protocols.
   *
   * This function removes all non-allowed protocols from the beginning of
   * $string. It ignores whitespace and the case of the letters, and it does
   * understand HTML entities. It does its work in a while loop, so it won't be
   * fooled by a string like "javascript:javascript:alert(57)".
   *
   * @since 1.0.0
   *
   * @param string $string Content to filter bad protocols from
   * @param array $allowed_protocols Allowed protocols to keep
   * @return string Filtered content
   *
   * Source: WordPress, used under GPLv3 or Later
   *
   */
  function kses_bad_protocol($string, $allowed_protocols) {
    $string = $this->kses_no_null($string);
    $iterations = 0;

    do {
      $original_string = $string;
      $string = $this->kses_bad_protocol_once($string, $allowed_protocols);
    } while ( $original_string != $string && ++$iterations < 6 );

    if ( $original_string != $string )
      return '';

    return $string;
  }


  /**
   * Removes any null characters in $string.
   *
   * @since 1.0.0
   *
   * @param string $string
   * @return string
   *
   * Source: WordPress, used under GPLv3 or Later
   *
   */
  function kses_no_null($string) {
    $string = preg_replace('/\0+/', '', $string);
    $string = preg_replace('/(\\\\0)+/', '', $string);

    return $string;
  }


  /**
   * Sanitizes content from bad protocols and other characters.
   *
   * This function searches for URL protocols at the beginning of $string, while
   * handling whitespace and HTML entities.
   *
   * @since 1.0.0
   *
   * @param string $string Content to check for bad protocols
   * @param string $allowed_protocols Allowed protocols
   * @return string Sanitized content
   *
   * Source: WordPress, used under GPLv3 or Later
   *
   */
  function kses_bad_protocol_once($string, $allowed_protocols, $count = 1 ) {
    $string2 = preg_split( '/:|&#0*58;|&#x0*3a;/i', $string, 2 );
    if ( isset($string2[1]) && ! preg_match('%/\?%', $string2[0]) ) {
      $string = trim( $string2[1] );
      $protocol = $this->kses_bad_protocol_once2( $string2[0], $allowed_protocols );
      if ( 'feed:' == $protocol ) {
        if ( $count > 2 )
          return '';
        $string = $this->kses_bad_protocol_once( $string, $allowed_protocols, ++$count );
        if ( empty( $string ) )
          return $string;
      }
      $string = $protocol . $string;
    }

    return $string;
  }


  /**
   * Callback for wp_kses_bad_protocol_once() regular expression.
   *
   * This function processes URL protocols, checks to see if they're in the
   * whitelist or not, and returns different data depending on the answer.
   *
   * @access private
   * @since 1.0.0
   *
   * @param string $string URI scheme to check against the whitelist
   * @param string $allowed_protocols Allowed protocols
   * @return string Sanitized content
   *
   * Source: WordPress, used under GPLv3 or Later
   *
   */
  function kses_bad_protocol_once2( $string, $allowed_protocols ) {
    $string2 = $this->kses_decode_entities($string);
    $string2 = preg_replace('/\s/', '', $string2);
    $string2 = $this->kses_no_null($string2);
    $string2 = strtolower($string2);

    $allowed = false;
    foreach ( (array) $allowed_protocols as $one_protocol )
      if ( strtolower($one_protocol) == $string2 ) {
        $allowed = true;
        break;
      }

    if ($allowed)
      return "$string2:";
    else
      return '';
  }


  /**
   * Convert all entities to their character counterparts.
   *
   * This function decodes numeric HTML entities (&#65; and &#x41;). It doesn't do
   * anything with other entities like &auml;, but we don't need them in the URL
   * protocol whitelisting system anyway.
   *
   * @since 1.0.0
   *
   * @param string $string Content to change entities
   * @return string Content after decoded entities
   *
   * Source: WordPress, used under GPLv3 or Later
   *
   */
  function kses_decode_entities($string) {
    $string = preg_replace_callback('/&#([0-9]+);/', array( $this, '_kses_decode_entities_chr' ), $string);
    $string = preg_replace_callback('/&#[Xx]([0-9A-Fa-f]+);/', array( $this, '_kses_decode_entities_chr_hexdec' ), $string);

    return $string;
  }


  /**
   * Regex callback for wp_kses_decode_entities()
   *
   * @param array $match preg match
   * @return string
   *
   * Source: WordPress, used under GPLv3 or Later
   *
   */
  function _kses_decode_entities_chr( $match ) {
    return chr( $match[1] );
  }


  /**
   * Regex callback for wp_kses_decode_entities()
   *
   * @param array $match preg match
   * @return string
   *
   * Source: WordPress, used under GPLv3 or Later
   *
   */
  function _kses_decode_entities_chr_hexdec( $match ) {
    return chr( hexdec( $match[1] ) );
  }


  /**
   * Sanitizes title or use fallback title.
   *
   * Specifically, HTML and PHP tags are stripped. Further actions can be added
   * via the plugin API. If $title is empty and $fallback_title is set, the latter
   * will be used.
   *
   * @since 1.0.0
   *
   * @param string $title The string to be sanitized.
   * @param string $fallback_title Optional. A title to use if $title is empty.
   * @param string $context Optional. The operation for which the string is sanitized
   * @return string The sanitized string.
   *
   * Source: WordPress, used under GPLv3 or Later
   *
   */
  function sanitize_title($title, $fallback_title = '', $context = 'save') {
    $raw_title = $title;

    if ( 'save' == $context )
      $title = $this->remove_accents($title);

    if ( '' === $title || false === $title )
      $title = $fallback_title;

    return $title;
  }


  /**
   * Converts all accent characters to ASCII characters.
   *
   * If there are no accent characters, then the string given is just returned.
   *
   * @since 1.2.1
   *
   * @param string $string Text that might have accent characters
   * @return string Filtered string with replaced "nice" characters.
   *
   * Source: WordPress, used under GPLv3 or Later
   *
   */
  function remove_accents($string) {
    if ( !preg_match('/[\x80-\xff]/', $string) )
      return $string;

    if (seems_utf8($string)) {
      $chars = array(
        // Decompositions for Latin-1 Supplement
        chr(194).chr(170) => 'a', chr(194).chr(186) => 'o',
        chr(195).chr(128) => 'A', chr(195).chr(129) => 'A',
        chr(195).chr(130) => 'A', chr(195).chr(131) => 'A',
        chr(195).chr(132) => 'A', chr(195).chr(133) => 'A',
        chr(195).chr(134) => 'AE', chr(195).chr(135) => 'C',
        chr(195).chr(136) => 'E', chr(195).chr(137) => 'E',
        chr(195).chr(138) => 'E', chr(195).chr(139) => 'E',
        chr(195).chr(140) => 'I', chr(195).chr(141) => 'I',
        chr(195).chr(142) => 'I', chr(195).chr(143) => 'I',
        chr(195).chr(144) => 'D', chr(195).chr(145) => 'N',
        chr(195).chr(146) => 'O', chr(195).chr(147) => 'O',
        chr(195).chr(148) => 'O', chr(195).chr(149) => 'O',
        chr(195).chr(150) => 'O', chr(195).chr(153) => 'U',
        chr(195).chr(154) => 'U', chr(195).chr(155) => 'U',
        chr(195).chr(156) => 'U', chr(195).chr(157) => 'Y',
        chr(195).chr(158) => 'TH', chr(195).chr(159) => 's',
        chr(195).chr(160) => 'a', chr(195).chr(161) => 'a',
        chr(195).chr(162) => 'a', chr(195).chr(163) => 'a',
        chr(195).chr(164) => 'a', chr(195).chr(165) => 'a',
        chr(195).chr(166) => 'ae', chr(195).chr(167) => 'c',
        chr(195).chr(168) => 'e', chr(195).chr(169) => 'e',
        chr(195).chr(170) => 'e', chr(195).chr(171) => 'e',
        chr(195).chr(172) => 'i', chr(195).chr(173) => 'i',
        chr(195).chr(174) => 'i', chr(195).chr(175) => 'i',
        chr(195).chr(176) => 'd', chr(195).chr(177) => 'n',
        chr(195).chr(178) => 'o', chr(195).chr(179) => 'o',
        chr(195).chr(180) => 'o', chr(195).chr(181) => 'o',
        chr(195).chr(182) => 'o', chr(195).chr(184) => 'o',
        chr(195).chr(185) => 'u', chr(195).chr(186) => 'u',
        chr(195).chr(187) => 'u', chr(195).chr(188) => 'u',
        chr(195).chr(189) => 'y', chr(195).chr(190) => 'th',
        chr(195).chr(191) => 'y', chr(195).chr(152) => 'O',
        // Decompositions for Latin Extended-A
        chr(196).chr(128) => 'A', chr(196).chr(129) => 'a',
        chr(196).chr(130) => 'A', chr(196).chr(131) => 'a',
        chr(196).chr(132) => 'A', chr(196).chr(133) => 'a',
        chr(196).chr(134) => 'C', chr(196).chr(135) => 'c',
        chr(196).chr(136) => 'C', chr(196).chr(137) => 'c',
        chr(196).chr(138) => 'C', chr(196).chr(139) => 'c',
        chr(196).chr(140) => 'C', chr(196).chr(141) => 'c',
        chr(196).chr(142) => 'D', chr(196).chr(143) => 'd',
        chr(196).chr(144) => 'D', chr(196).chr(145) => 'd',
        chr(196).chr(146) => 'E', chr(196).chr(147) => 'e',
        chr(196).chr(148) => 'E', chr(196).chr(149) => 'e',
        chr(196).chr(150) => 'E', chr(196).chr(151) => 'e',
        chr(196).chr(152) => 'E', chr(196).chr(153) => 'e',
        chr(196).chr(154) => 'E', chr(196).chr(155) => 'e',
        chr(196).chr(156) => 'G', chr(196).chr(157) => 'g',
        chr(196).chr(158) => 'G', chr(196).chr(159) => 'g',
        chr(196).chr(160) => 'G', chr(196).chr(161) => 'g',
        chr(196).chr(162) => 'G', chr(196).chr(163) => 'g',
        chr(196).chr(164) => 'H', chr(196).chr(165) => 'h',
        chr(196).chr(166) => 'H', chr(196).chr(167) => 'h',
        chr(196).chr(168) => 'I', chr(196).chr(169) => 'i',
        chr(196).chr(170) => 'I', chr(196).chr(171) => 'i',
        chr(196).chr(172) => 'I', chr(196).chr(173) => 'i',
        chr(196).chr(174) => 'I', chr(196).chr(175) => 'i',
        chr(196).chr(176) => 'I', chr(196).chr(177) => 'i',
        chr(196).chr(178) => 'IJ', chr(196).chr(179) => 'ij',
        chr(196).chr(180) => 'J', chr(196).chr(181) => 'j',
        chr(196).chr(182) => 'K', chr(196).chr(183) => 'k',
        chr(196).chr(184) => 'k', chr(196).chr(185) => 'L',
        chr(196).chr(186) => 'l', chr(196).chr(187) => 'L',
        chr(196).chr(188) => 'l', chr(196).chr(189) => 'L',
        chr(196).chr(190) => 'l', chr(196).chr(191) => 'L',
        chr(197).chr(128) => 'l', chr(197).chr(129) => 'L',
        chr(197).chr(130) => 'l', chr(197).chr(131) => 'N',
        chr(197).chr(132) => 'n', chr(197).chr(133) => 'N',
        chr(197).chr(134) => 'n', chr(197).chr(135) => 'N',
        chr(197).chr(136) => 'n', chr(197).chr(137) => 'N',
        chr(197).chr(138) => 'n', chr(197).chr(139) => 'N',
        chr(197).chr(140) => 'O', chr(197).chr(141) => 'o',
        chr(197).chr(142) => 'O', chr(197).chr(143) => 'o',
        chr(197).chr(144) => 'O', chr(197).chr(145) => 'o',
        chr(197).chr(146) => 'OE', chr(197).chr(147) => 'oe',
        chr(197).chr(148) => 'R', chr(197).chr(149) => 'r',
        chr(197).chr(150) => 'R', chr(197).chr(151) => 'r',
        chr(197).chr(152) => 'R', chr(197).chr(153) => 'r',
        chr(197).chr(154) => 'S', chr(197).chr(155) => 's',
        chr(197).chr(156) => 'S', chr(197).chr(157) => 's',
        chr(197).chr(158) => 'S', chr(197).chr(159) => 's',
        chr(197).chr(160) => 'S', chr(197).chr(161) => 's',
        chr(197).chr(162) => 'T', chr(197).chr(163) => 't',
        chr(197).chr(164) => 'T', chr(197).chr(165) => 't',
        chr(197).chr(166) => 'T', chr(197).chr(167) => 't',
        chr(197).chr(168) => 'U', chr(197).chr(169) => 'u',
        chr(197).chr(170) => 'U', chr(197).chr(171) => 'u',
        chr(197).chr(172) => 'U', chr(197).chr(173) => 'u',
        chr(197).chr(174) => 'U', chr(197).chr(175) => 'u',
        chr(197).chr(176) => 'U', chr(197).chr(177) => 'u',
        chr(197).chr(178) => 'U', chr(197).chr(179) => 'u',
        chr(197).chr(180) => 'W', chr(197).chr(181) => 'w',
        chr(197).chr(182) => 'Y', chr(197).chr(183) => 'y',
        chr(197).chr(184) => 'Y', chr(197).chr(185) => 'Z',
        chr(197).chr(186) => 'z', chr(197).chr(187) => 'Z',
        chr(197).chr(188) => 'z', chr(197).chr(189) => 'Z',
        chr(197).chr(190) => 'z', chr(197).chr(191) => 's',
        // Decompositions for Latin Extended-B
        chr(200).chr(152) => 'S', chr(200).chr(153) => 's',
        chr(200).chr(154) => 'T', chr(200).chr(155) => 't',
        // Euro Sign
        chr(226).chr(130).chr(172) => 'E',
        // GBP (Pound) Sign
        chr(194).chr(163) => '',
        // Vowels with diacritic (Vietnamese)
        // unmarked
        chr(198).chr(160) => 'O', chr(198).chr(161) => 'o',
        chr(198).chr(175) => 'U', chr(198).chr(176) => 'u',
        // grave accent
        chr(225).chr(186).chr(166) => 'A', chr(225).chr(186).chr(167) => 'a',
        chr(225).chr(186).chr(176) => 'A', chr(225).chr(186).chr(177) => 'a',
        chr(225).chr(187).chr(128) => 'E', chr(225).chr(187).chr(129) => 'e',
        chr(225).chr(187).chr(146) => 'O', chr(225).chr(187).chr(147) => 'o',
        chr(225).chr(187).chr(156) => 'O', chr(225).chr(187).chr(157) => 'o',
        chr(225).chr(187).chr(170) => 'U', chr(225).chr(187).chr(171) => 'u',
        chr(225).chr(187).chr(178) => 'Y', chr(225).chr(187).chr(179) => 'y',
        // hook
        chr(225).chr(186).chr(162) => 'A', chr(225).chr(186).chr(163) => 'a',
        chr(225).chr(186).chr(168) => 'A', chr(225).chr(186).chr(169) => 'a',
        chr(225).chr(186).chr(178) => 'A', chr(225).chr(186).chr(179) => 'a',
        chr(225).chr(186).chr(186) => 'E', chr(225).chr(186).chr(187) => 'e',
        chr(225).chr(187).chr(130) => 'E', chr(225).chr(187).chr(131) => 'e',
        chr(225).chr(187).chr(136) => 'I', chr(225).chr(187).chr(137) => 'i',
        chr(225).chr(187).chr(142) => 'O', chr(225).chr(187).chr(143) => 'o',
        chr(225).chr(187).chr(148) => 'O', chr(225).chr(187).chr(149) => 'o',
        chr(225).chr(187).chr(158) => 'O', chr(225).chr(187).chr(159) => 'o',
        chr(225).chr(187).chr(166) => 'U', chr(225).chr(187).chr(167) => 'u',
        chr(225).chr(187).chr(172) => 'U', chr(225).chr(187).chr(173) => 'u',
        chr(225).chr(187).chr(182) => 'Y', chr(225).chr(187).chr(183) => 'y',
        // tilde
        chr(225).chr(186).chr(170) => 'A', chr(225).chr(186).chr(171) => 'a',
        chr(225).chr(186).chr(180) => 'A', chr(225).chr(186).chr(181) => 'a',
        chr(225).chr(186).chr(188) => 'E', chr(225).chr(186).chr(189) => 'e',
        chr(225).chr(187).chr(132) => 'E', chr(225).chr(187).chr(133) => 'e',
        chr(225).chr(187).chr(150) => 'O', chr(225).chr(187).chr(151) => 'o',
        chr(225).chr(187).chr(160) => 'O', chr(225).chr(187).chr(161) => 'o',
        chr(225).chr(187).chr(174) => 'U', chr(225).chr(187).chr(175) => 'u',
        chr(225).chr(187).chr(184) => 'Y', chr(225).chr(187).chr(185) => 'y',
        // acute accent
        chr(225).chr(186).chr(164) => 'A', chr(225).chr(186).chr(165) => 'a',
        chr(225).chr(186).chr(174) => 'A', chr(225).chr(186).chr(175) => 'a',
        chr(225).chr(186).chr(190) => 'E', chr(225).chr(186).chr(191) => 'e',
        chr(225).chr(187).chr(144) => 'O', chr(225).chr(187).chr(145) => 'o',
        chr(225).chr(187).chr(154) => 'O', chr(225).chr(187).chr(155) => 'o',
        chr(225).chr(187).chr(168) => 'U', chr(225).chr(187).chr(169) => 'u',
        // dot below
        chr(225).chr(186).chr(160) => 'A', chr(225).chr(186).chr(161) => 'a',
        chr(225).chr(186).chr(172) => 'A', chr(225).chr(186).chr(173) => 'a',
        chr(225).chr(186).chr(182) => 'A', chr(225).chr(186).chr(183) => 'a',
        chr(225).chr(186).chr(184) => 'E', chr(225).chr(186).chr(185) => 'e',
        chr(225).chr(187).chr(134) => 'E', chr(225).chr(187).chr(135) => 'e',
        chr(225).chr(187).chr(138) => 'I', chr(225).chr(187).chr(139) => 'i',
        chr(225).chr(187).chr(140) => 'O', chr(225).chr(187).chr(141) => 'o',
        chr(225).chr(187).chr(152) => 'O', chr(225).chr(187).chr(153) => 'o',
        chr(225).chr(187).chr(162) => 'O', chr(225).chr(187).chr(163) => 'o',
        chr(225).chr(187).chr(164) => 'U', chr(225).chr(187).chr(165) => 'u',
        chr(225).chr(187).chr(176) => 'U', chr(225).chr(187).chr(177) => 'u',
        chr(225).chr(187).chr(180) => 'Y', chr(225).chr(187).chr(181) => 'y',
        // Vowels with diacritic (Chinese, Hanyu Pinyin)
        chr(201).chr(145) => 'a',
        // macron
        chr(199).chr(149) => 'U', chr(199).chr(150) => 'u',
        // acute accent
        chr(199).chr(151) => 'U', chr(199).chr(152) => 'u',
        // caron
        chr(199).chr(141) => 'A', chr(199).chr(142) => 'a',
        chr(199).chr(143) => 'I', chr(199).chr(144) => 'i',
        chr(199).chr(145) => 'O', chr(199).chr(146) => 'o',
        chr(199).chr(147) => 'U', chr(199).chr(148) => 'u',
        chr(199).chr(153) => 'U', chr(199).chr(154) => 'u',
        // grave accent
        chr(199).chr(155) => 'U', chr(199).chr(156) => 'u',
      );

      $string = strtr($string, $chars);
    } else {
      // Assume ISO-8859-1 if not UTF-8
      $chars['in'] = chr(128).chr(131).chr(138).chr(142).chr(154).chr(158)
        .chr(159).chr(162).chr(165).chr(181).chr(192).chr(193).chr(194)
        .chr(195).chr(196).chr(197).chr(199).chr(200).chr(201).chr(202)
        .chr(203).chr(204).chr(205).chr(206).chr(207).chr(209).chr(210)
        .chr(211).chr(212).chr(213).chr(214).chr(216).chr(217).chr(218)
        .chr(219).chr(220).chr(221).chr(224).chr(225).chr(226).chr(227)
        .chr(228).chr(229).chr(231).chr(232).chr(233).chr(234).chr(235)
        .chr(236).chr(237).chr(238).chr(239).chr(241).chr(242).chr(243)
        .chr(244).chr(245).chr(246).chr(248).chr(249).chr(250).chr(251)
        .chr(252).chr(253).chr(255);

      $chars['out'] = "EfSZszYcYuAAAAAACEEEEIIIINOOOOOOUUUUYaaaaaaceeeeiiiinoooooouuuuyy";

      $string = strtr($string, $chars['in'], $chars['out']);
      $double_chars['in'] = array(chr(140), chr(156), chr(198), chr(208), chr(222), chr(223), chr(230), chr(240), chr(254));
      $double_chars['out'] = array('OE', 'oe', 'AE', 'DH', 'TH', 'ss', 'ae', 'dh', 'th');
      $string = str_replace($double_chars['in'], $double_chars['out'], $string);
    }

    return $string;
  }


  /**
   * Combine user attributes with known attributes and fill in defaults when needed.
   *
   * The pairs should be considered to be all of the attributes which are
   * supported by the caller and given as a list. The returned attributes will
   * only contain the attributes in the $pairs list.
   *
   * If the $atts list has unsupported attributes, then they will be ignored and
   * removed from the final returned list.
   *
   * @since 2.5
   *
   * @param array $pairs Entire list of supported attributes and their defaults.
   * @param array $atts User defined attributes in shortcode tag.
   * @return array Combined and filtered attribute list.
   *
   * Source: WordPress, used under GPLv3 or Later
   * Original name: shortcode_atts
   *
   */
  function default_vars($pairs, $atts) {
    $atts = (array)$atts;
    $out = array();
    foreach ($pairs as $name => $default) {
      if ( array_key_exists($name, $atts) )
        $out[$name] = $atts[$name];
      else
        $out[$name] = $default;
    }
    return $out;
  }


  /**
   * Callback for wp_kses_normalize_entities() regular expression.
   *
   * This function only accepts valid named entity references, which are finite,
   * case-sensitive, and highly scrutinized by HTML and XML validators.
   *
   * @since 3.0.0
   *
   * @param array $matches preg_replace_callback() matches array
   * @return string Correctly encoded entity
   *
   * Source: WordPress, used under GPLv3 or Later
   *
   */
  function kses_named_entities($matches) {
    global $allowedentitynames;

    if ( empty($matches[1]) )
      return '';

    $i = $matches[1];
    return ( ! in_array($i, $allowedentitynames) ) ? "&amp;$i;" : "&$i;";
  }


  /**
   * Callback for wp_kses_normalize_entities() regular expression.
   *
   * This function helps wp_kses_normalize_entities() to only accept 16-bit values
   * and nothing more for &#number; entities.
   *
   * @access private
   * @since 1.0.0
   *
   * @param array $matches preg_replace_callback() matches array
   * @return string Correctly encoded entity
   *
   * Source: WordPress, used under GPLv3 or Later
   *
   */
  function kses_normalize_entities2($matches) {
    if ( empty($matches[1]) )
      return '';

    $i = $matches[1];
    if ($this->valid_unicode($i)) {
      $i = str_pad(ltrim($i, '0'), 3, '0', STR_PAD_LEFT);
      $i = "&#$i;";
    } else {
      $i = "&amp;#$i;";
    }

    return $i;
  }


  /**
   * Callback for wp_kses_normalize_entities() for regular expression.
   *
   * This function helps wp_kses_normalize_entities() to only accept valid Unicode
   * numeric entities in hex form.
   *
   * @access private
   *
   * @param array $matches preg_replace_callback() matches array
   * @return string Correctly encoded entity
   *
   * Source: WordPress, used under GPLv3 or Later
   *
   */
  function kses_normalize_entities3($matches) {
    if ( empty($matches[1]) )
      return '';

    $hexchars = $matches[1];
    return ( ! $this->valid_unicode(hexdec($hexchars)) ) ? "&amp;#x$hexchars;" : '&#x'.ltrim($hexchars, '0').';';
  }


  /**
   * Helper function to determine if a Unicode value is valid.
   *
   * @param int $i Unicode value
   * @return bool True if the value was a valid Unicode number
   *
   * Source: WordPress, used under GPLv3 or Later
   *
   */
  function valid_unicode($i) {
    return ( $i == 0x9 || $i == 0xa || $i == 0xd ||
      ($i >= 0x20 && $i <= 0xd7ff) ||
      ($i >= 0xe000 && $i <= 0xfffd) ||
      ($i >= 0x10000 && $i <= 0x10ffff) );
  }


  /**
   * Filters a list of objects, based on a set of key => value arguments.
   *
   * @since 3.1.0
   *
   * @param array $list An array of objects to filter
   * @param array $args An array of key => value arguments to match against each object
   * @param string $operator The logical operation to perform:
   *    'AND' means all elements from the array must match;
   *    'OR' means only one element needs to match;
   *    'NOT' means no elements may match.
   *   The default is 'AND'.
   * @return array
   *
   * Source: WordPress, used under GPLv3 or Later
   *
   */
  function list_filter( $list, $args = array(), $operator = 'AND' ) {
    if ( ! is_array( $list ) )
      return array();

    if ( empty( $args ) )
      return $list;

    $operator = strtoupper( $operator );
    $count = count( $args );
    $filtered = array();

    foreach ( $list as $key => $obj ) {
      $to_match = (array) $obj;

      $matched = 0;
      foreach ( $args as $m_key => $m_value ) {
        if ( array_key_exists( $m_key, $to_match ) && $m_value == $to_match[ $m_key ] )
          $matched++;
      }

      if ( ( 'AND' == $operator && $matched == $count )
        || ( 'OR' == $operator && $matched > 0 )
        || ( 'NOT' == $operator && 0 == $matched ) ) {
        $filtered[$key] = $obj;
      }
    }

    return $filtered;
  }


}