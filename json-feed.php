<?php
/**
 * JSONFeed
 *
 * @author Evo Stamatov <evo@ionata.com.au>
 * @version 0.1.1
 *
 * @created 2013-02-08
 * @lastUpdated 2016-12-21
 */

class JSONFeed
{

  const VERSION = '0.1.1';

  function __construct( $args = array() )
  {

    static $UID = 0;

    $defaults = array(
      'url'           => '',                      // must not be empty and contain only alphanumeric, ., _ or - chars
      'key'           => 'json-feed-' . ($UID++), // must not be empty and contain only alphanumeric, _ or - chars
      'get_data'      => '',                      // a callabale which returns an array (or an object) -- will be json_encoded
      'post_type'     => 'post',                  // the post type to attach the last_modified handler -- set to false to not attach actions
      'edit_posts'    => 'edit_posts',            // publish capability
      'delete_posts'  => 'delete_posts',          // trash capability
      // 'cache_timeout' => false,                   // cache timeout -- false == no cache, a number = seconds until flushed
      'cache_timeout' => 3600,                    // cache timeout -- 3600 == an hour
      'cache_key'     => null,                    // a cache key to allow different results for the same key
    );

    $this->args = wp_parse_args( $args, $defaults );

    // TODO: sanitize url (will be treated as regex) and key as per the rewrite tag regex
    // if ( preg_match( $this->get('key'), '/[^a-z0-9_-]/' ) ) { ... }

    add_action( 'init', array(&$this, 'init_url_rewrites'), 998 );

    // TODO: move this to a better hook -- maybe shutdown?
    add_action( 'init', array(&$this, 'maybe_flush_rewrite_rules'), 999 );

    add_action( 'parse_request', array(&$this, 'parse_rewrite_request') );

    if ( is_admin() && $this->get('post_type') ) {

      if ( current_user_can( $this->get('edit_posts') ) ) {
        // based on do_action( "{$new_status}_{$post->post_type}", $post->ID, $post );
        add_action( 'publish_' . $this->get('post_type'), array(&$this, 'update_last_modified_variable'), 10, 2 );
      }

      if ( current_user_can( $this->get('delete_posts') ) ) {
        // based on do_action( "{$new_status}_{$post->post_type}", $post->ID, $post );
        add_action( 'trash_' . $this->get('post_type'), array(&$this, 'update_last_modified_variable'), 10, 2 );
      }

    }

  }

  /**
   * Return a value for given key from the args
   *
   * @param  string $key
   * @param  *      $default
   *
   * @return *
   */
  function get ( $key, $default = null )
  {
    if ( isset( $this->args[$key] ) ) {
      return $this->args[$key];
    }

    return $default;
  }

  /**
   * Return the args key
   *
   * @param  string $suffix A suffix to append to the key
   *
   * @return string
   */
  function get_key ( $suffix = null )
  {

    $key = $this->get('key');

    if ( $suffix !== null ) {
      return $key . $suffix;
    }

    return $key;

  }

  /**
   * Return the args url
   *
   * @return string
   */
  function get_url ()
  {

    return $this->get('url');

  }

  /**
   * Return the args url
   *
   * @return string
   */
  function get_cache_key ()
  {

    $cache_key = $this->get('cache_key', '');

    if ( $cache_key ) {
      $cache_key = '-' . $cache_key;
    }

    return $this->get_key( '-cache' . $cache_key );

  }

  /**
   * Get the last modified value from the db
   *
   * @return integer|null The timestamp
   */
  function get_last_modified ()
  {
      return get_option( $this->get_key('-last-modified'), null );
  }

  /**
   * Add a new rewrite rule and a tag to handle the partial variable
   */
  function init_url_rewrites ()
  {

    add_rewrite_rule( $this->get_url(), 'index.php?partial=' . $this->get_key(), 'top' );
    add_rewrite_tag( '%partial%', '([^&][a-z0-9_-]+)' );

  }

  /**
   * Flush the rewrite rules once all are added
   */
  function maybe_flush_rewrite_rules ()
  {

    $feed_rules_option_name = $this->get_key('-version');
    $feed_rules_version = self::VERSION;
    $feed_rules_current_version = get_option($feed_rules_option_name);

    if ( $feed_rules_current_version && version_compare($feed_rules_current_version, $feed_rules_version) >= 0 ) {
      return;
    }
    
    update_option( $feed_rules_option_name, $feed_rules_version );

    if ( defined('JSON_FEED_DID_FLUSH_REWRITE_RULES') ) {
      return;
    }

    define( 'JSON_FEED_DID_FLUSH_REWRITE_RULES', true );

    flush_rewrite_rules();

  }

  /**
   * Handle the requests
   */
  function parse_rewrite_request( &$wp )
  {

    if ( ! isset( $wp->query_vars['partial'] ) ) {
      return;
    }

    if ( $wp->query_vars['partial'] === $this->get_key() ) {
      $this->render_the_feed();
      exit();
    }

  }

  /**
   * Update the options variable for proper ETag updates
   */
  function update_last_modified_variable( $post_id, $post )
  {

    if ( defined( 'DEALS_LAST_MODIFIED_UPDATED' ) || wp_is_post_revision( $post_id ) ) {
      return;
    }

    define( 'DEALS_LAST_MODIFIED_UPDATED', true );

    update_option( $this->get_key( '-last-modified' ), current_time( 'timestamp' ) );

    delete_transient( $this->get_cache_key() );

  }


  /**
   * A snippet for properly managing ETag headers
   *
   * @param  string  $last_modified      The last_modified timestamp
   * @param  string  $hash_entropy_addon An addon that can be specified for better ETag uniqueness
   *
   * @return void                        Exits if IF_MODIFIED_SINCE matches the last_modified date or the ETag matches
   */
  function manage_etag( $last_modified, $hash_entropy_addon = '' )
  {

    // get a unique hash
    $etag = md5( $last_modified . $hash_entropy_addon );
    $etag = apply_filters('json_feed_etag', $etag, $this->get_key(), $last_modified, $hash_entropy_addon);

    // get gmdate
    $gmdate = gmdate( 'D, d M Y H:i:s', $last_modified ) . ' GMT';
    $gmdate = apply_filters('json_feed_last_modified_gmdate', $gmdate, $this->get_key() );

    // get the HTTP_IF_MODIFIED_SINCE header if set
    $if_modified_since = ( isset( $_SERVER['HTTP_IF_MODIFIED_SINCE'] ) ? $_SERVER['HTTP_IF_MODIFIED_SINCE'] : false );

    // get the HTTP_IF_NONE_MATCH header if set (etag: unique file hash)
    $etag_header = ( isset( $_SERVER['HTTP_IF_NONE_MATCH'] ) ? trim( preg_replace( '#(W/)?(\\\")?([^\\\"]+)(\\\")?#i', '$3', $_SERVER['HTTP_IF_NONE_MATCH'] ) ) : false );

    // set last-modified header
    header( 'Last-Modified: ' . $gmdate );

    // set etag header
    header( 'ETag: "' . $etag . '"' );

    // make sure caching is turned on
    header( 'Cache-Control: public' );

    // check if page is changed. If not, send 304 and exit
    if ( ( $if_modified_since !== false && strtotime( $if_modified_since ) >= $last_modified ) || $etag_header == $etag ) {

      do_action('json_feed_not_modified', $this->get_key());

      header( 'HTTP/1.1 304 Not Modified' );
      header( 'Connection: close' );
      exit;
    }

  }

  function get_the_data ()
  {
    $data = null;

    if ( is_callable( $this->get( 'get_data' ) ) ) {
      if ( $this->get('cache_timeout') === false ) {
        $data = false;
      } else {
        $key = $this->get_cache_key();
        $data = get_transient( $key );
      }

      if ($data === false) {
        $data = call_user_func( $this->get('get_data'), $this->get_url(), $this->get_key(), $this->get_last_modified() );

        if ( $this->get('cache_timeout') !== false ) {
          set_transient( $key, $data, $this->get('cache_timeout') );
        }
      } else {
        // error_log('INFO: Got data from cache [' . __FUNCTION__ . ']');
      }

    }

    return $data;
  }

  function render_the_feed ()
  {

    do_action( 'will_render_the_feed_' . $this->get_key(), &$this );
    do_action( 'will_render_the_feed', &$this, $this->get_key() );

    if ( headers_sent() ) {
      error_log('ERROR: headers already sent [' . __CLASS__ . ']');
      exit;
    }

    # Generate proper ETag
    if ( ! isset( $_REQUEST['no-cache'] ) ) {
      $last_modified = $this->get_last_modified();

      if ( empty( $last_modified ) ) {
        $last_modified = current_time( 'timestamp' );
        $this->update_last_modified_variable(0, null);
      }

      // allow time_offset reset parameter
      // $last_modified += 0;

      // NOTE: could exit
      $this->manage_etag( $last_modified, self::VERSION );
    }

    # Get the data
    $data = $this->get_the_data();

    $this->output_data( $data );

    do_action( 'did_render_the_feed_' . $this->get_key(), &$this );
    do_action( 'did_render_the_feed', &$this, $this->get_key() );

  }

  function output_data ( $data )
  {

    # Set JSON headers
    header( 'Content-type: text/json' );
    header( 'Content-type: application/json' );

    # Enable GZIP encoding
    $manual_compression = false;
    $encoding = isset( $_SERVER['HTTP_ACCEPT_ENCODING'] ) ? $_SERVER['HTTP_ACCEPT_ENCODING'] : false;
    if ( $encoding && strstr( $encoding, 'gzip' ) && extension_loaded( 'zlib' ) ) {
      if ( ! ob_start( 'ob_gzhandler' ) ) {

        # TODO: check if this code is obsolete nowadays
        $manual_compression = true;
        ob_start();
        ob_implicit_flush(0);
        header( 'Content-Encoding: gzip' );

      }
    }

    # Output the JSON
    echo json_encode( $data );

    # Handle manual compression
    if ( $manual_compression ) {
      $gzip_size = ob_get_length();
      $gzip_contents = ob_get_clean();

      echo "\x1f\x8b\x08\x00\x00\x00\x00\x00",
        substr(gzcompress($gzip_contents, 6), 0, - 4), // substr -4 isn't needed
        pack('V', crc32($gzip_contents)),    // crc32 and
        pack('V', $gzip_size);               // size are ignored by all the browsers i have tested
    }

  }

}
