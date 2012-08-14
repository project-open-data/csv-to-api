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

	function parse_query( $query = null ) {
		
		if ( is_string( $query ) )
			$query = wp_parse_args( $query );
			
		if ( $query == null )
			$query = $_GET;
		
		$this->source = isset( $query['source'] ) ? esc_url( $query['source'] ) : null;
		$this->source_format = isset( $query['source_format'] ) ? $query['source_format'] : $this->get_extension( $this->url );
		$this->format = isset( $query['format'] ) ? $query['format'] : 'json';
		$this->callback = isset( $query['callback'] ) ? $this->jsonp_callback_filter( $query['callback'] ) : false;
		$this->sort = isset( $query['sort'] ) ? $query['sort'] : null;
		$this->sort_dir = isset( $query['sort_dir'] ) ? $query['sort_dir'] : null;
		
		return get_object_vars( $this );
		
	}

	function parse() {
			
		$key = 'IA_' . md5( $this->source );
		if ( $data = get_transient( $key ) )
			return $data;

		$parser = 'parse_' . $this->source_format;
		if ( !method_exists( $this, $parser ) )
			wp_die( 'Format not supported' );		
		
		$this->data = wp_remote_get( $this->source );

		if ( is_wp_error( $this->data ) )
			return wp_die( 'Bad data source' );		
			
		$this->data = wp_remote_retrieve_body( $this->data );

		$this->data = $this->$parser( $this->data );

		set_transient( $key, $this->data, $this->ttl );
		
		return $this->query( $this->data );
		
	}
	
	function output() {
		
		$function = 'object_to_' . $this->format;

		if ( !method_exists( $this, $function) )
			return false;

		$this->header( $this->format );
		$output = $this->$function( $this->data );

		$callback = $this->jsonp_callback_filter( $this->callback );
		
		if ( $this->format == 'json' && $this->callback )
			return "{$this->callback}($output);";
		
		return $output;

	}

	function sanitize_key( $key ) {
		
		$key = sanitize_title( $key );
		$key = str_replace( '-', '_', $key );
		return $key;
		
	}
	
	function get_extension( $source = null ) {

		if ( $source == null )
			$source = $this->source;
			
		$url_parts = parse_url( $source );
		$url_parts = pathinfo( $url_parts['path'] ); 
		return $url_parts['extension'];
		
	}

	function xml_entities( $string ) {

    	return str_replace( array("&", "<", ">", "\"", "'"), array("&amp;", "&lt;", "&gt;", "&quot;", "&apos;"), $string );

     }
	
	function parse_csv( $csv ) {
	
		$lines = explode( "\n", $csv );
		$headers = str_getcsv( array_shift( $lines ) );
		$data = array();
		foreach ( $lines as $line ) {
		
			$row = array();
		
			foreach ( str_getcsv( $line ) as $key => $field )
				$row[ $this->sanitize_key( $headers[ $key ] ) ] = $field;
		
			$row = array_filter( $row );
			$row = $this->array_to_object( $row );
			$data[] = $row;
		
		}
		
		return $data;
		
	}	
	
	function array_to_object( $array ) {
		
		$output = new stdClass();
		foreach ( $array as $key => $value ) 
			$output->$key = $value;

		return $output;
		
	}
	
	function object_to_json( $data ) {
		
		return json_encode( $data );
		
	}
	
	function object_to_xml( $array, $xml, $tidy = true ) {

		if ( $xml == null )
			$xml = new SimpleXMLElement( '<records></records>' );

		//array of keys that will be treated as attributes, not children
		$attributes = array( 'id' );
		
		//recursively loop through each item
		foreach ( $array as $key => $value ) {

			//if this is a numbered array,
			//grab the parent node to determine the node name
			if ( is_numeric( $key ) )
				$key = 'record';
		
			//if this is an attribute, treat as an attribute
			if ( in_array( $key, $attributes ) ) {
				$xml->addAttribute( $key, $value );
		
				//if this value is an object or array, add a child node and treat recursively
			} else if ( is_object( $value ) || is_array( $value ) ) {
					$child = $xml->addChild( $key );
					$child = $this->object_to_xml( $value, $child, false );
		
				//simple key/value child pair
			} else {
					$value = $this->xml_entities( $value );
					$xml->addChild( $key, $value );
			}
		
		}
		
		if ( $tidy )
			$xml = $this->tidy_xml( $xml );

		return $xml;
		
	}
	
	function object_to_html( $data ) {
		
		$output = "<table>\n";
		$output .= "<tr>";

		foreach ( array_keys( get_object_vars( reset( $data ) ) ) as $header ) 
			$output .= "\t<th>$header</th>";
			
		$output .= "</tr>";
		
		foreach ( $data as $row ) {
			
			$output .= "<tr>\n";
			
			foreach ( $row as $key => $value )				
				$output .= "\t<td>$value</td>\n";
			
			$output .= "</tr>\n";
			
		}
		
		$output .= '</table>';
		
		return $output;
		
	}
	
	function tidy_xml( $xml ) {

		$dom = new DOMDocument();
		$dom->preserveWhiteSpace = false;
		$dom->formatOutput = true;
		$dom->loadXML( $xml->asXML() );
		return $dom->saveXML();
		
	}	
	
	function header( $extension = null ) {
	
		if ( $extension == null )
			$extension = $this->extension;
		
		$mimes = $this->get_mimes();

		if ( !isset( $mimes[ $extension ] ) || headers_sent() )
			return;

		header( 'Content-Type: ' . $mimes[ $extension ] );
		
	}
	
	/**
	 * Return mime types filtered
	 * This way we do not allow additional mimetypes elsewhere
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
	
	function jsonp_callback_filter( $callback ) {
		
		//http://stackoverflow.com/a/10900911/1082542
		if ( preg_match( '/[^0-9a-zA-Z\$_]|^(abstract|boolean|break|byte|case|catch|char|class|const|continue|debugger|default|delete|do|double|else|enum|export|extends|false|final|finally|float|for|function|goto|if|implements|import|in|instanceof|int|interface|long|native|new|null|package|private|protected|public|return|short|static|super|switch|synchronized|this|throw|throws|transient|true|try|typeof|var|volatile|void|while|with|NaN|Infinity|undefined)$/', $callback) )
			return false;

		return $callback;
				
	}
	
	function query( $data, $query = null ) {
		
		if ( $query == null )
			$query = $_GET;
				
		$query = shortcode_atts( $this->get_query_vars( $data ), $query );
		
		$query = array_filter( $query );
		
		$data = wp_list_filter( $data, $query );
		
		if ( $this->sort == null )
			return $data;
			
		$data = usort( $data, array( &$this, 'object_sort' ) );
		
		return $data;
		
	}
	
	function get_query_vars( $data ) {
	
		$vars = array();
		foreach ( $data as $row ) {

			foreach ( $row as $key=>$value ) {
				
				if ( !array_key_exists( $key, $vars ) )
					$vars[ $key ] = null;
				
			}
			
		}
		
		return $vars;
		
	}
	
	function object_sort( $a, $b ) {
	
		$sorter = $this->sort;
		
		if ( $sorter == null )
			return 0;
			
		$sorter = ( $sorter == 'DESC' ) ? SORT_ASC : SORT_DESC;
		
	    return $a->$sorter == $b->$sorter ? 0 : ( $a->$sorter > $b->$sorter ) ? 1 : -1;
	
	}
	
}