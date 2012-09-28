<?php

class Instant_API {
	
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
			$query = wp_parse_args( $query );
		}
		
		// If a query has not been passed to this function, just use the array of variables that
		// were passed in the URL.
		if (is_null($query)) {
			$query = $_GET;
		}
		
		// Define a series of configuration variables based on what was requested in the query.
		$this->source = isset( $query['source'] ) ? esc_url( $query['source'] ) : null;
		$this->source_format = isset( $query['source_format'] ) ? $query['source_format'] : $this->get_extension( $this->url );
		$this->format = isset( $query['format'] ) ? $query['format'] : 'json';
		$this->callback = isset( $query['callback'] ) ? $this->jsonp_callback_filter( $query['callback'] ) : false;
		$this->sort = isset( $query['sort'] ) ? $query['sort'] : null;
		$this->sort_dir = isset( $query['sort_dir'] ) ? $query['sort_dir'] : null;
		
		return get_object_vars( $this );
		
	}
	
	/**
	 * Fetch the requested file and turn it into a PHP array.
	 */
	function parse() {
		
		// Attempt to retrieve the data from WordPress' cache via its Transients API
		// http://codex.wordpress.org/Transients_API
		$key = 'IA_' . md5( $this->source );
		if ( $data = get_transient( $key ) ) {
			return $data;
		}
		
		// Create an instance of the parser for the requested file format (e.g. CSV)
		$parser = 'parse_' . $this->source_format;
		if ( !method_exists( $this, $parser ) ) {
			wp_die( 'Format not supported' );
		}
		
		// Retrieve the requested source material via HTTP GET.
		$this->data = wp_remote_get( $this->source );

		if ( is_wp_error( $this->data ) ) {
			return wp_die( 'Bad data source' );
		}
		
		// Save the contents of the requested file.
		$this->data = wp_remote_retrieve_body( $this->data );
		
		// Turn the raw file data (e.g. CSV) into a PHP array.
		$this->data = $this->$parser( $this->data );
		
		// Save the data to WordPress' cache via its Transients API.
		set_transient( $key, $this->data, $this->ttl );
		
		return $this->query( $this->data );
		
	}

	/**
	 * Return to the client the requested data in the requested format (e.g. JSON).
	 */
	function output() {
		
		$function = 'object_to_' . $this->format;

		if ( !method_exists( $this, $function) )
		{
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
		
		$key = sanitize_title( $key );
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
		return $url_parts['extension'];
		
	}
	
	/**
	 * Convert reserved XML characters into their entitity equivalents.
	 */
	function xml_entities( $string ) {

    	return str_replace( array("&", "<", ">", "\"", "'"), array("&amp;", "&lt;", "&gt;", "&quot;", "&apos;"), $string );

     }
     
	/**
	 * Turn CSV into a PHP array.
	 */
	function parse_csv( $csv ) {
	
		$lines = explode( "\n", $csv );
		$headers = str_getcsv( array_shift( $lines ) );
		$data = array();
		foreach ( $lines as $line ) {
		
			$row = array();
		
			foreach ( str_getcsv( $line ) as $key => $field ) {
				$row[ $this->sanitize_key( $headers[ $key ] ) ] = $field;
			}
		
			$row = array_filter( $row );
			$row = $this->array_to_object( $row );
			$data[] = $row;
		
		}
		
		return $data;
		
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
	function object_to_xml( $array, $xml, $tidy = true ) {

		if ( $xml == null ) {
			$xml = new SimpleXMLElement( '<records></records>' );
		}

		//array of keys that will be treated as attributes, not children
		$attributes = array( 'id' );
		
		//recursively loop through each item
		foreach ( $array as $key => $value ) {

			//if this is a numbered array,
			//grab the parent node to determine the node name
			if ( is_numeric( $key ) ) {
				$key = 'record';
			}
		
			//if this is an attribute, treat as an attribute
			if ( in_array( $key, $attributes ) ) {
				$xml->addAttribute( $key, $value );
			}
		
			//if this value is an object or array, add a child node and treat recursively
			elseif ( is_object( $value ) || is_array( $value ) ) {
					$child = $xml->addChild( $key );
					$child = $this->object_to_xml( $value, $child, false );
			}
			
			//simple key/value child pair
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
	 * Return MIME types, filtered.
	 * This way we do not allow additional MIME types elsewhere.
	 */
	function get_mimes() {

		add_filter( 'upload_mimes', array( &$this, 'mime_filter' ) );
		$mimes = get_allowed_mime_types();
		remove_filter( 'upload_mimes', array( &$this, 'mime_filter' ) );
		return $mimes;

	}


	/**
	 * Add our mimetypes
	 */
	function mime_filter( $mimes ) {
		return $mimes + array(
			'json' => 'application/json',
			'xml' => 'text/xml',
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
	 * 
	 */
	function query( $data, $query = null ) {
		
		if ( $query == null ) {
			$query = $_GET;
		}
		
		// Fill in any defaults that are missing.
		$query = shortcode_atts( $this->get_query_vars( $data ), $query );
		
		// Eliminate any value in $query that equal false.
		$query = array_filter( $query );
		
		$data = wp_list_filter( $data, $query );
		
		if ( $this->sort == null ) {
			return $data;
		}

		// Optionally sort the object.
		$data = usort( $data, array( &$this, 'object_sort' ) );
		
		return $data;
		
	}

	/**
	 * 
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
	 */
	function object_sort( $a, $b ) {
	
		$sorter = $this->sort;
		
		if ( $sorter == null ) {
			return 0;
		}
			
		$sorter = ( $sorter == 'DESC' ) ? SORT_ASC : SORT_DESC;
		
	    return $a->$sorter == $b->$sorter ? 0 : ( $a->$sorter > $b->$sorter ) ? 1 : -1;
	
	}
	
}