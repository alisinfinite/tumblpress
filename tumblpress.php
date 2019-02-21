<?php
/*
Plugin Name: TumblPress
Plugin URI: http://alis.me/
Description: Imports Tumblr posts into WordPress, and crossposts WordPress posts to Tumblr. Also adds options to "clean up" Tumblr crossposts (delete older than <em>n</em> days) and likes.
Version: 0.1
Author: Alis
Author URI: http://alis.me/
License: GPL2
*/

// debugging
define('LOGFILE', '');

// include our other files
  include_once( dirname(__FILE__) .'/tumblr.class.php' );

if(!class_exists('OpenGraph'))
  { include_once( dirname(__FILE__) .'/OpenGraph.php' ); }

if( !class_exists( "tumblpress" ) ):
class tumblpress {

//= VARIABLES ===================================================================//
  private $wpoauth;
  private $wpconfig;
  
  public  $tumblr;
  

//= CLASS AND WORDPRESS STUFF ===================================================//
  // constructor
  public function __construct() {
    // build us up...
    register_activation_hook( plugin_basename(__FILE__), array( $this, 'init' ) );
    register_deactivation_hook( plugin_basename(__FILE__), array( $this, 'deinit' ) );
  
    // menu pages
    add_action( 'admin_menu', array( $this, 'setAdminPages' ) );
  
    // init so we can set up our oauth bullshit
    add_action( 'init', array( $this, 'tumblpress_oauth' ) );
    add_action( 'admin_init', array( $this, 'admin_init' ) );
    
    // cron tasks
    add_action( 'do_tumblr_import', array( $this, 'tumblpress_import' ) );
    add_action( 'do_tumblr_clean', array( $this, 'tumblpress_clean' ) );
    add_action( 'do_tumblr_clean_likes', array( $this, 'tumblpress_clean_likes' ) );

    // filter to add reblog sources to the post
    //add_filter( 'the_content', array( $this, 'tumblpress_sources' ) );
    add_filter( 'wp_title', array( $this, 'tumblpress_title' ) );
    
    // posts!
    add_action( 'publish_post', array( $this, 'tumblpress_publish' ) );
    //add_action( 'future_to_publish', array( $this, 'tumblpress_publish_future' ) );
    add_action( 'publish_future_post', array( $this, 'tumblpress_publish_future' ) ); // see: http://squirrelshaterobots.com/hacking/wordpress/catching-when-scheduled-posts-finally-publish-in-a-wordpress-plugin/
    
    add_action( 'save_post', array( $this, 'tumblpress_edit' ) );
    //add_action( 'edit_post', array( $this, 'tumblpress_edit' ) );
    
    add_action( 'trash_post', array( $this, 'tumblpress_delete' ) );
    add_action( 'delete_post', array( $this, 'tumblpress_delete' ) );
    
    return;
  }
  
  public function init() {
    wp_schedule_event( time(), 'hourly', 'do_tumblr_import' );
    wp_schedule_event( time(), 'daily', 'do_tumblr_clean' );
    wp_schedule_event( time(), 'hourly', 'do_tumblr_clean_likes' );
    return;
  }
  public function admin_init() {
    register_setting( 'tumblpress', 'tumblpress_config' );
    return;
  }
  
  public function deinit() {
    wp_clear_scheduled_hook( 'do_tumblr_import' );
    wp_clear_scheduled_hook( 'do_tumblr_clean' );
    wp_clear_scheduled_hook( 'do_tumblr_clean_likes' );
    return;
  }
  
  // what admin pages do we want?
  public function setAdminPages(){
    add_options_page( "TumblPress", "TumblPress", 'manage_options', 'tumblpress', array( $this, 'printAdminPage' ) );
  }


//= OAUTH (IS EVIL) =============================================================//
  // oauth!
  public function tumblpress_oauth(){
    if( isset( $_GET['do'] ) && $_GET['do'] == 'auth' ){
      // connectorize to tumblrize
      $this->tumblr = new TumblrAPI();

      $this->tumblr->oauthAuthorise();
      
      // refresh our config values
      $this->wpopts = get_option( 'tumblpress_oauth' );
    }
  }



//= OOPS I ACCIDENTALLY A WHOLE POST ============================================//
  public function tumblpress_publish( $post_id ){
    $tumblr_post = new tumblpress_crosspost( $post_id );
    $tumblr_post->doPost( 'now' );
  }

  public function tumblpress_publish_future( $post_id ){
    $tumblr_post = new tumblpress_crosspost( $post_id );
    $tumblr_post->doPost();
  }

  public function tumblpress_edit( $post_id ){
    // are we editing a post?
    // if we are, and it hasn't previously been crossposted, don't crosspost it...
    $p = get_post_meta( $post_id, '_tumblr_id', true );

    // unless we want to
    $xp = get_post_meta( $post_id, 'totumblr', true );
    if( ( is_array( $p ) && isset( $p['id'] ) ) || ( isset( $xp ) && !empty( $xp ) ) ){
      $tumblr_post = new tumblpress_crosspost( $post_id );
      $tumblr_post->doPost('edit');
    }
  }

  public function tumblpress_delete( $post_id ){
    $tumblr_post = new tumblpress_crosspost( $post_id );
    $tumblr_post->deletePost();
  }
  

//= SOURCE OUR STUFF ============================================================//
  public function tumblpress_sources( $c ){
	
	// only do this stuff for posts
	if( $GLOBALS['post']->post_type != 'post' )
		return $c;
  
    $options = get_option( 'tumblpress_config' );
    $cleantime = ( $options['clean'] * 24 * 60 * 60 );
    $meta = get_post_meta( $GLOBALS['post']->ID, '_tumblr_meta', true );
    $tID = get_post_meta( $GLOBALS['post']->ID, '_tumblr_id', true );

    // if we've got no tumblr id, we don't need to be here
    if( empty( $tID ) )
		  return $c;
    
    $source = ''; $backlink = '';
    
    // add source attribution
    if( !empty( $meta['source_url'] ) )
      $source .= 'Source: <a href="'. $meta['source_url'] .'" rel="nofollow">'. $meta['source_title'] .'</a>';
    
    if( !empty( $meta['reblogged_from_url'] ) && $meta['reblogged_from_url'] != $meta['source_url'] )
      $source .= ' (Via: <a href="'. $meta['reblogged_from_url'] .'" rel="nofollow">'. $meta['reblogged_from_title'] .'</a>)';
    
    /*if( !empty( $meta['reblogged_root_url'] ) && $meta['reblogged_from_url'] != $meta['reblogged_root_url'] )
      $source .= 'Origin: <a href="'. $meta['reblogged_from_url'] .'" rel="nofollow">'. $meta['reblogged_from_title'] .'</a>';*/

    // add tumblr backlinks
    if( intval( time() - strtotime( $GLOBALS['post']->post_date_gmt ) ) < intval( $cleantime ) ){
      $backlink = '<div class="tumblr_linkback"><a href="http://'. $options['xpfrom'] .'.tumblr.com/post/'. $tID .'">View on Tumblr?</a></div>';
    }
    
    if( !empty( $source ) )
      return $c . '<div class="tumblr_attribution">'. $source .'</div>' . $backlink;
    else
      return $c . $backlink;
  }


//= A LOT OF OUR TUMBLR POSTS WON'T HAVE TITLES, SO LET'S GIVE THEM SOME ========//
  public function tumblpress_title( $t ){
	
  	// only do this stuff for posts
  	if( !is_single() )
  		return $t;
  		
    //print_r( $GLOBALS['post'] );
    $tmp_title = trim( preg_replace( '/(?:\s\s+|\n|\t)/', ' ', substr( strip_tags( $GLOBALS['post']->post_content ), 0, 140 ) ) );
  
    if( !empty( $t ) || empty( $tmp_title ) )
      return $t;
    else {
      return $tmp_title .' | ';
    }
  }


//= OOPS I ACCIDENTALLY A WHOLE POST ============================================//
  public function tumblpress_import(){
    $options = get_option( 'tumblpress_config' );
    
    if( $options['xpfrom'] ){
      
      // fetch old posts
      // connectorize to tumblrize
      $tumblr = new TumblrAPI();
        
      $offset = 0;
        
      $opts = array(
        'limit'         => 20,
        'offset'        => $offset,
        'reblog_info'   => 'true',
      );
          
      $tumblr->getRequest( 'posts', $opts );
            
      $r = $tumblr->oauthResponse();
        
      $import = new tumblpress_posts();
        
      foreach( $r->response->posts as $p ){
        $r2 = $import->doImport( $p );
      }
    }
  }

  // clean old posts out of our tumblr
  public function tumblpress_clean(){
    global $wpdb;
  
    $options = get_option( 'tumblpress_config' );
    
    if( $options['xpfrom'] && $options['clean'] > 0 ){
      
      // fetch old posts
      // connectorize to tumblrize
      $tumblr = new TumblrAPI();
        
      $offset = 0;
      $limit = 20;
            
      // first of all, we need to get our blog info to see how many total posts we have
      $tumblr->getRequest( 'info', array() );            
      $r = $tumblr->oauthResponse();
  
      // our offset is our total posts minus our limit...
      $offset = $r->response->blog->posts - $limit;
    
      // now let's get those posts, with reblog and notes info!
      $opts = array(
          'limit'         => $limit,
          'offset'        => $offset,
          'reblog_info'   => 'true',
          'notes_info'    => 'true',
      );
      $tumblr->getRequest( 'posts', $opts );            
      $r = $tumblr->oauthResponse();

      //echo '<pre>'; print_r( $r->response->posts ); echo '</pre>';
      
      // just in case we missed a post, and need to re-import it
      $import = new tumblpress_posts();
      
      $c = ( $options['clean'] * 24 * 60 * 60 );
      foreach( $r->response->posts as $p ):
      // is our post sufficiently old?
      if( intval( time() - $p->timestamp ) > intval( $c ) ):
      
        // check if we previously imported this, and if we didn't, import it
        if( $import->previouslyImported( $p->id ) == 0 ){
          echo "<p>Post ID <strong>". $p->id ."</strong> not previously imported. Doing that now...</p>";
          $r = $import->doImport( $p );
        }
        
        // okay now grab our wordpress post id based on our tumblr post id
        $wpID = $wpdb->get_var( $wpdb->prepare(
          "SELECT `post_id` FROM $wpdb->postmeta WHERE meta_key = %s AND meta_value = %s LIMIT 0, 1;",
          '_tumblr_id', $p->id
        ) );
        //echo "<p>Tumblr post ID <strong>". $p->id ."</strong> maps to WordPress post ID <strong>$wpID</strong>.</p>";
    
        // check if our post is an 'original', i.e. none of the reblog info exists
        // if it is, add our tumblr notes to the post: replies as comments, reblogs as tracksbacks
        if( ( empty( $p->reblogged_root_name ) || $p->reblogged_root_name == $options['xpfrom'] ) ){
        $likes = array();
        foreach( $p->notes as $n ){
          if( $n->type != 'like' && $n->type != 'posted' ){

	    $url = ( $n->type == 'reblog' ) ? $n->blog_url .'post/'. $n->post_id : $n->blog_url;

            if( isset( $n->reply_text ) )
              { $content = $n->reply_text; }
            elseif( isset( $n->answer_text ) )
              { $content = $n->answer_text; }
            elseif( isset( $n->added_text ) )
              { $content = $n->added_text .' <small><a href="'. $url .'">(more)</a></small>'; }
            else
	      { $content = ''; }
  
            $data = array(
              'comment_post_ID'       => $wpID,
              'comment_author'        => $n->blog_name,
              'comment_author_email'  => empty($content) ? '' : $n->blog_name .'.'. get_bloginfo( 'admin_email' ),
              'comment_author_url'    => $url,
              'comment_content'       => $content,
              'comment_type'          => empty($content) ? 'trackback' : '',
              'comment_parent'        => 0,
              'user_id'               => 0,
              'comment_author_IP'     => '66.6.40.61', // tumblr.com's ip as at 30/5/2013
              'comment_agent'         => 'Tumblr API v2',
              'comment_date'          => date( 'Y-m-d H:i:s', $n->timestamp ),
              'comment_approved'      => 1,
            );
            
            wp_insert_comment( $data );
            
            //echo '<pre>'; print_r( $data ); echo '</pre>';
                
          } elseif( $n->type == 'like' && $n->blog_name != $options['xpfrom'] ) {
            $likes[] = array(
              'user' => $n->blog_name,
              'url'  => $n->blog_url,
            );
          } } // end if, end foreeach
        
          if( count( $likes ) > 0 ){
            //echo '<pre>'; print_r( $likes ); echo '</pre>';
            add_post_meta( $wpID, '_tumblr_likes', $likes, true );
          }
        }
    
        // and when all of that is done, delete the sucker from tumblr... bye post!
        $tumblr->sendRequest( 'post/delete', array( 'id' => $p->id ) );
        $r = $tumblr->oauthResponse();
          
      endif; endforeach;
    }
  }
  
  // clean old tumblr likes
  public function tumblpress_clean_likes(){
    $limit = 1; $offset = 0;
    
    // get our options
    $options = get_option( 'tumblpress_config' );
    
    // don't do this if our array key is missing, or our clean_likes isn't set
    if( !array_key_exists( 'clean_likes', $options ) || $options['clean_likes'] < 1 )
      return;
    
    // make us a tumblr
    $tumblr = new TumblrAPI();
    
    // first of all, we need to get our like to see how many total likes we have
    $tumblr->getUserRequest( 'likes', array( 'limit' => $limit, 'offset' => $offset ) );
    $r = $tumblr->oauthResponse();

    // if we have more likes than our limit, let's do a thing...
    if( $r->response->liked_count > $options['clean_likes'] ):
	
      // our limit is how many likes we have over our total, up to a max of 20
      // (tumblr's api doesn't seem to, a-har, like going over 20)
      $limit = $r->response->liked_count - $options['clean_likes']; 
      $limit = ( $limit > 20 ) ? 20 : $limit;
    
      // our offset is our total likes minus our limit...
      $offset = $r->response->liked_count - $limit;

      // now get our likes again, limited to our new values...
      $tumblr->getUserRequest( 'likes', array( 'limit' => $limit, 'offset' => $offset ) );
      $r = $tumblr->oauthResponse();
    
   	  // get rid of our likes...
      foreach( $r->response->liked_posts as $l )
        { $tumblr->sendUserRequest( 'unlike', array( 'id' => $l->id, 'reblog_key' => $l->reblog_key ) ); }

    endif;
  }


//= OUR OPTIONS PAGE ============================================================//
  // gogo admin page
  public function printAdminPage() {
    global $wpdb;
  
    if( !current_user_can( 'manage_options' ) ) {
      wp_die( __( 'You do not have sufficient permissions to access this page.' ) );
    }
    
    // connectorize to tumblrize
    $this->tumblr = new TumblrAPI();
    
    // refresh our config values
    $this->wpopts = get_option( 'tumblpress_oauth' );

    // print some stuff...
    
    // if we're refreshing our auth session
    if( isset( $_GET['do'] ) && $_GET['do'] == 'logout' ){
      delete_option( 'tumblpress_oauth' );
      // extract our config values
      $this->wpopts = get_option( 'tumblpress_oauth' );
    }
    
    // get our tumblr error, if we've got one
    $terr = ( is_object( $this->tumblr ) ) ? $this->tumblr->getError() : false;
    
    // bulk import
    if( isset( $_GET['do'] ) && $_GET['do'] == 'tumblr-import' ) {
      $this->printBulkImportPage();
    
    // suggested followers
    } elseif( isset( $_GET['do'] ) && $_GET['do'] == 'tumblr-suggest' ) {
      $this->printSuggestedFollowsPage();
    
    // else...
    } else {
?>
<div class="wrap">
<h2>TumblPress</h2>

<?php if( $terr ) { echo '<div class="error"><p><strong>Tumblr Error:</strong> ', $terr, '</p></div>'; } ?>

<h3>General settings</h3>
<form method="post" action="options.php">
<?php settings_fields( 'tumblpress' ); ?>
<?php $options = get_option( 'tumblpress_config' ); ?>
<table class="form-table"><tbody>

  <tr>
    <th scope="row"><label>Tumblr user</label></th>
    <td><?php
  echo ( isset( $this->wpopts['tumblr_user'] ) )
    ? $this->wpopts['tumblr_user'] .' (<a href="./options-general.php?page=tumblpress&do=logout">logout</a>)'
    : '<a href="./options-general.php?page=tumblpress&do=auth">login</a>';
?></td>
  </tr>

<?php if( isset( $this->wpopts['tumblr_blogs'] ) && is_array( $this->wpopts['tumblr_blogs'] ) ): ?>
  <tr>
    <th scope="row"><label for="tumblpress_config[xpfrom]">Import from</label></th>
    <td><select name="tumblpress_config[xpfrom]" id="xpfrom"><?php
    
  foreach( $this->wpopts['tumblr_blogs'] as $b ){
    $sel = ( $options['xpfrom'] == $b['name'] ) ? ' selected="selected" ' : '';
    
    echo '<option value="', $b['name'], '"', $sel, '>', $b['name'], ': ', $b['url'], '</option>';
  }
  
?></select></td>
  </tr>
<?php endif; ?>

  <tr>
    <th scope="row"><label for="tumblpress_config[xpcat]">Add imported posts to category</label></th>
    <td><?php wp_dropdown_categories( array( 'name' => 'tumblpress_config[xpcat]', 'hide_empty' => 0, 'selected' => isset( $options['xpcat'] ) ? $options['xpcat'] : '' ) ); ?></td>
  </tr>

  <tr>
    <th scope="row"><label for="tumblpress_config[exclude]">Exclude posts tagged with</label></th>
    <td><input name="tumblpress_config[exclude]" type="text" id="exclude" value="<?php echo ( isset( $options['exclude'] ) ) ? $options['exclude'] : ''; ?>" class="regular-text" />
        <p class="description">Comma delimited list of tag names not to crosspost, e.g. <code>tumblr only, to:not crosspost, hush</code></p></td>
  </tr>

  <tr>
    <th scope="row"><label for="tumblpress_config[include]">Include posts tagged with</label></th>
    <td><input name="tumblpress_config[include]" type="text" id="include" value="<?php echo ( isset( $options['include'] ) ) ? $options['include'] : ''; ?>" class="regular-text" />
        <p class="description">Comma delimited list of tag names to always crosspost, e.g. <code>replies, to:crosspost, to keep</code></p></td>
  </tr>

  <tr>
    <th scope="row"><label for="tumblpress_config[reblogs]">Import reblogs?</label></th>
    <td><input name="tumblpress_config[reblogs]" type="checkbox" id="reblogs" value="1" <?php echo (isset($options['reblogs']) && $options['reblogs'] > 0) ? 'checked' : ''; ?> /></td>
  </tr>

  <tr>
    <th scope="row"><label for="tumblpress_config[clean]">Delete posts older than</label></th>
    <td><input name="tumblpress_config[clean]" type="text" id="clean" value="<?php echo ( isset( $options['clean'] ) ) ? $options['clean'] : '0'; ?>" class="regular-text" />
        <p class="description"><strong>Delete</strong> posts from Tumblr older than this manys days. Note that posts deleted in this way <em>cannot be restored</em>. Set to <code>0</code> to disable.</p></td>
  </tr>

  <tr>
    <th scope="row"><label for="tumblpress_config[clean_likes]">Only keep the last <em>n</em> likes</label></th>
    <td><input name="tumblpress_config[clean_likes]" type="text" id="clean_likes" value="<?php echo ( isset( $options['clean_likes'] ) ) ? $options['clean_likes'] : '0'; ?>" class="regular-text" />
        <p class="description"><strong>Delete</strong> (unlike) older likes from posts. Note that likes <em>cannot be restored</em>. Set to <code>0</code> to disable.</p></td>
  </tr>

</tbody></table>
<?php submit_button(); ?>
</form>

<?php if( $options['clean'] > 0 ): ?>
<h3>Suggested follows</h3>
<p>Not sure who to follow? Try <a href="options-general.php?page=tumblpress&do=tumblr-suggest">these people</a>. Note that this page might take a while to load, especially if you happen to have a lot of followers!</p>
<?php endif; ?>

<h3>Import old posts</h3>
<p>Importing all your old posts may take forever. But you can <a href="options-general.php?page=tumblpress&do=tumblr-import">give it a go here</a>. Remember to leave the browser window open while the process is running!</p>

</div>
<?php
    }
  }
  
  // gogo suggested follows page
  public function printSuggestedFollowsPage(){
    global $wpdb;
    
    if( !current_user_can( 'manage_options' ) ) {
      wp_die( __( 'You do not have sufficient permissions to access this page.' ) );
    }
    
    // refresh our config values
    $options = get_option( 'tumblpress_config' );
    
    if( $options['clean'] == 0 ) {
      wp_die( __( 'This only works if you\'re deleting old Tumblr posts (and importing the comments).' ) );
    }
    
    $interval = $options['clean'] + 30;
?>

<div class="wrap">
<h2>Suggested follows</h2>
<p>The following is a list of people you might like to follow. It's made of everyone who follows you, and who's commented or reblogged something original from you in the past 30 days (and that's been imported from Tumblr to WordPress).</p>

<?php
    // quick and dirty to get our followers
    // the first call is just to get the total followers
    $this->tumblr->getRequest( 'followers', array( 'limit' => 1, 'offset' => 0 ), true );
    $r = $this->tumblr->oauthResponse();
    
    if( $r->response->total_users > 0 ): echo '<p>';
    for( $offset = 0; $offset <= $r->response->total_users; $offset += 20 ):
    
      $this->tumblr->getRequest( 'followers', array( 'limit' => 20, 'offset' => $offset ) );
      $r = $this->tumblr->oauthResponse();
    
      if( is_array( $r->response->users ) && count( $r->response->users ) > 0 ):
      foreach( $r->response->users as $u ):
      
        $num = $wpdb->get_var( $wpdb->prepare( "SELECT COUNT(*) AS 'count' FROM $wpdb->comments WHERE `comment_author` = %s AND `comment_date` > DATE_SUB( NOW(), INTERVAL %d DAY )",
          $u->name, $interval ) );
    
    	  $style = ( intval( time() - $u->updated ) > intval( 30 * 24 * 60 * 60 ) ) ? ' style="color: #CCC;"' : '';
    	  
    	  if( $num > 1 )
          echo '<a href="'. $u->url .'"'. $style .'>'. $u->name .'</a> ';
    
      endforeach;
      endif; // if we've got results
    
    endfor; // increasing the offset
    echo '</p>'; endif; // total_users > 0

?>
</div>
<?php
  }

  // gogo bulk post import page
  public function printBulkImportPage() {
    if( !current_user_can( 'manage_options' ) ) {
      wp_die( __( 'You do not have sufficient permissions to access this page.' ) );
    }
    
    // connectorize to tumblrize
    $this->tumblr = new TumblrAPI();
    
    // refresh our config values
    $this->wpopts = get_option( 'tumblpress_oauth' );
    $options = get_option( 'tumblpress_config' );

    // print some stuff...
    
    // get our tumblr error, if we've got one
    $terr = ( is_object( $this->tumblr ) ) ? $this->tumblr->getError() : false;
?>
<div class="wrap">
<h2>Bulk import posts from Tumblr...</h2>
<p>Okay let's do this thing! Remember not to close your browser window while this is running...</p>

<?php if( $terr ) { echo '<div class="error"><p><strong>Tumblr Error:</strong> ', $terr, '</p></div>'; } ?>

<?php 
    if( $options['xpfrom'] ):
    
    // fetch old posts
    // connectorize to tumblrize
    $tumblr = new TumblrAPI();
    
    // and our importer
    $import = new tumblpress_posts();
      
    $offset = 0 + $options['xpoffset'];
    $limit = 20;
    
    // long script!
    set_time_limit( 0 );
    
    while( $limit > 0 ){
    ob_start();
      $opts = array(
        'limit'         => $limit,
        'offset'        => $offset,
        'reblog_info'   => 'true',
      );
        
      $tumblr->getRequest( 'posts', $opts );
          
      $r = $tumblr->oauthResponse();
      
      // duur bad code...
      if( isset( $r->response->posts ) && is_array( $r->response->posts ) && count( $r->response->posts ) > 0 ){
        foreach( $r->response->posts as $p ){
          
          echo "<p>Starting import of Tumblr post <strong>$p->id</strong>... ";
      
          $r = $import->doImport( $p );
          if( $r )
            echo "inserted with WordPress ID <strong># $r</strong>!</p>";
          else
            echo "<strong>failed</strong>! (Has it been imported before? Is it on the excluded tags list?)</p>";
        }
          
        // do a little sleepage to give Tumblr a rest
        //sleep( 5 );
        
        $options['xpoffset'] += $limit;
        update_option( 'tumblpress_config', $options );
        $offset += $limit;
      
     // else we're at the end, so we've got no more posts to scan
     // so let's reset our values for next time!
     } else {
      $limit = 0;
    $options['xpoffset'] = 0;
        update_option( 'tumblpress_config', $options );
     }
    ob_end_flush();
    }
      
    endif; // end checking if we actually have a tumblr defined
?>

</div>
<?php
  }

}

// extend our class
include_once( dirname(__FILE__) .'/tumblpress.export.php' );
include_once( dirname(__FILE__) .'/tumblpress.import.php' );

endif;
//===============================================================================//

// initalise the class
if( class_exists( "tumblpress" ) )
  $tumblpress = new tumblpress();
