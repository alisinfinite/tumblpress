<?php
class tumblpress_crosspost extends tumblpress {
  
  private $blogurl;
  private $id;
  private $post;
  
  private $err;

  // wordpress saved variables
  private $wpconfig;

  
  // lololol constructor i hate oop
  public function __construct( $post_id ){
    $this->post = get_post( $post_id );

    // extract our config values
    $this->wpconfig = get_option( 'tumblpress_config' );
  }
  
  // aa-aa-and that's a wrap.
  public function __destruct() {
    return true;
  }
   
  // return us our last error message
  public function getError(){
    return $this->err;
  }
  
//= SO ACTUALLY DO SOME STUFF ===================================================//
// crosspost published, non-private posts to tumblr...
  public function doPost( $when = false ){

    // haxx to catch scheduled posts
    // http://squirrelshaterobots.com/hacking/wordpress/catching-when-scheduled-posts-finally-publish-in-a-wordpress-plugin/
    // ... this breaks post editing, urgh
    //if( $when == 'now' and $this->post->post_modified != $this->post->post_date ) { return; }
    if( $this->post->post_status != 'publish' || post_password_required($this->post->ID) === true ) { return; }

    // get our categories and tags
    // do this first so we can drop out if we're in one of our No Crosspost Categories
    $cats = wp_get_post_categories( $this->post->ID );
    $tags = wp_get_post_tags( $this->post->ID );
    
    $this->post->tags = '';

    $is_excluded = false;
    
    // populate the categories
    // ... and take out any commas while we're at it (replace with ALT 0130)
    foreach( $cats as $c ){
      $cat = get_category( $c );
      $this->post->tags .= str_replace( ',', '‚', $cat->name ) .',';
    }
    foreach( $tags as $t ){
      $this->post->tags .= str_replace( ',', '‚', $t->name ) .',';
    }
    if( isset( $this->wpconfig['exclude'] ) ){
      // normalise the commas in our exclusion list and turn it into an array
      $exclude = explode( ',', $this->wpconfig['exclude'] );
      if( is_array( $exclude ) ){
        foreach( $exclude as $e ) {
          if( stripos( $this->post->tags, trim( $e ) ) !== false ) {
            $is_excluded += stripos( $this->post->tags, trim( $e ) );
          }
        }
      }
    }
    
    // we don't want to crosspost anything if it's something we've just imported!
    $exags = array_map( 'trim', explode( ',', $this->wpconfig['xpcat'] ) );
    $intersect = array_intersect( $cats, $exags );

    // alright so check to make sure it's okay for us to do this...
    if(
      $this->post->post_type == 'post'
      && $this->post->post_status == 'publish'
      && post_password_required( $this->post->ID ) !== true
      && $is_excluded === false
      && count( $intersect ) == 0
    ):
   
    // start populating some common values…
    $opts = array(
      'state'      => 'published',                       // we can also sent these to the queue ('queue')
      //'state'      => 'queue',				// Tumblr changes the post ID when posts move from queue to publish, so we'd have the wrong ID in our postmeta
      //'publish_on' => $this->post->post_date_gmt .' GMT',  // undocumented: https://groups.google.com/forum/#!msg/tumblr-api/TpmjyDMIDsc/iqueeAGllZYJ
      'tags'       => $this->post->tags,
      'tweet'      => 'off',                               // don't tweet crossposted posts
      'format'     => 'html',
      'date'       => $this->post->post_date_gmt .' GMT',
      'slug'       => $this->post->post_name,
      'source_url' => get_permalink( $this->post->ID ),    // this is a little undocumented…
    );
    
    // are we editing a post? let's see if we've got a meta variable to work with…
    // if our Tumblr ID is set but is < 1, it means we haven't crossposted but would like to
    $p = get_post_meta( $this->post->ID, '_tumblr_id', true );
    if( isset( $p ) && !empty( $p ) && $p > 0 ){
      $opts['id'] = $p;
    }
    
    // get the post format
    $f = get_post_format( $this->post->ID );
    if( $f == 'aside' ){
      $opts['type']         = 'text';
      $opts['title']        = $this->post->post_title;
      $opts['body']         = apply_filters( 'the_content', $this->post->post_content );
    
    } elseif( $f == 'link' ){
      $output = preg_match_all( '/<a href=[\'"]([^\'"]+)[\'"]/im', $this->post->post_content, $m );
    
      $opts['type']         = 'link';
      $opts['url']          = $m[1][0];
      $opts['title']        = $this->post->post_title;
      $opts['description']  = apply_filters( 'the_content', $this->post->post_content );
			
			// get opengraph info for the link, if it exists
			$graph = OpenGraph::fetch($m[1][0]);
			if($graph->image) {
				$wxh = getimagesize($graph->image);
				
				$opts['photos'][] = array(
					'caption'       => '',
					'original_size' => array(
						  'width'     => $wxh[0],
						  'height'    => $wxh[1],
						  'url'       => $graph->image
					  ),
					'alt_sizes'     => array()
				);
			}
			if($graph->description)
				{ $opts['excerpt'] = $graph->description; }
    
    } elseif( $f == 'quote' ){
      preg_match( '/<blockquote.*?>([^<]+|.*?)?<\/blockquote>\.?\s(.*)/ims', $this->post->post_content, $m );
    
      $opts['type']         = 'quote';
      $opts['quote']        = nl2br( $m[1] );  // we use this rather than the_content because the formatting looks nicer on tumblr
      $opts['source']       = apply_filters( 'the_content', $m[2] );
    
    } elseif( $f == 'chat' ){
      $opts['title']        = $this->post->post_title;
      $opts['conversation'] = strip_tags( $this->post->post_content );
    
    } elseif( $f == 'image' ){
      $img = wp_get_attachment_image_src( get_post_thumbnail_id( $this->post->ID ), 'full' );
    
      $opts['type']         = 'photo';
      //$opts['source'][0]    = $img[0];
      $opts['data'][0]      = file_get_contents( $img[0] );
      $opts['link']         = get_permalink( $this->post->ID );
      $opts['caption']      = apply_filters( 'the_content', $this->post->post_content );
    
    // text posts are excerpts with links
    } elseif( $f === false || $f == 'standard' ){
      $opts['type']         = 'link';
      $opts['url']          = get_permalink( $this->post->ID );
      $opts['title']        = $this->post->post_title;
      $opts['description']  = ( $this->post->post_excerpt ) ? apply_filters( 'the_excerpt', $this->post->post_excerpt ) : apply_filters( 'the_content', $this->post->post_content );
 
			// get featured image for this post, if it exists
			$img = wp_get_attachment_image_src( get_post_thumbnail_id( $this->post->ID ), 'large' );
			if($img) {
				$opts['photos'][] = array(
					'caption'       => '',
					'original_size' => array(
						  'width'     => $wxh[1],
						  'height'    => $wxh[2],
						  'url'       => $img[0]
					  ),
					'alt_sizes'     => array()
				);
			}
			
    // if we don't match anything, just make a text post
    } else {
      $opts['type']         = 'text';
      $opts['title']        = $this->post->post_title;
      $opts['body']         = apply_filters( 'the_content', $this->post->post_content );

    }
   
    //error_log("TUMBLR REQUEST FOR ". $this->post->ID .":\n". var_export($opts, true) ."\n\n", 3, LOGFILE);

    // okay, now we… do our thing!
    // connectorize to tumblrize
    $tumblr = new TumblrAPI();
    
    if( isset( $opts['id'] ) )
      { $tumblr->sendRequest( 'post/edit', $opts ); }
    else
      { $tumblr->sendRequest( 'post', $opts ); }
      
    $r = $tumblr->oauthResponse();
    //error_log("TUMBLR RESPONSE FOR ". $this->post->ID .":\n". var_export($r->meta, true) ."\n". var_export($r->response, true) ."\n\n", 3, LOGFILE);
    
    // did we work? then we should save our id for future reference!
    if( $r->meta->status == 201 ){
      error_log("Adding post meta: ". $this->post->ID ." _tumblr_id ". $r->response->id ."... ", 3, LOGFILE);
      
      if( add_post_meta( $this->post->ID, '_tumblr_id', $r->response->id ) )
	{ error_log(" SUCCESS!\n\n", 3, LOGFILE); }
      else
	{ error_log(" FAILED!\n\n", 3, LOGFILE); }
    }
    
  endif;
  }
  
  // delete posts
  public function deletePost(){
    $p = get_post_meta( $this->post->ID, '_tumblr_id', true );
    error_log("Deleting post: ". $this->post->ID ." _tumblr_id ". $p ."... ", 3, LOGFILE);

    if( is_array( $p ) && isset( $p ) ){
      // okay, now we… do our thing!
      // connectorize to tumblrize
      $tumblr = new TumblrAPI();

      // something in here is failing
      $tumblr->sendRequest( 'post/delete', array( 'id' => $p ) );
      $r = $tumblr->oauthResponse();
      error_log("Tumblr response for $p:" . var_export($r, true), 3, LOGFILE);

      // did we work? then we should delete our meta...
      if( $r->meta->status == 200 ){
        delete_post_meta( $this->post->ID, '_tumblr_id' );
      }
    }
  }

// fin.
}