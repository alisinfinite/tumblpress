<?php
class tumblpress_posts extends tumblpress {
  
  private $post;
  
  private $err;

  // wordpress saved variables
  private $wpconfig;

  
  // lololol constructor i hate oop
  public function __construct(){

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
// import a tumblr post into wordpress
  public function doImport( $p ){
    global $wpdb;
    
    $this->post = $p;
    $options = get_option( 'tumblpress_config' );
  
    // check to see if we've already added this...
    $previously = $this->previouslyImported( $p->id );
      
    // or if it's in an excluded tag...
    $exags = array_map( 'trim', explode( ',', $this->wpconfig['exclude'] ) );
    $intersect = array_intersect( $p->tags, $exags );

    // or if it's in an INCLUDED tag...
    $inags = array_map( 'trim', explode( ',', $this->wpconfig['include'] ) );
    $intersect2 = array_intersect( $p->tags, $inags );
    
    // if this post is new, and it's not in one of our excluded categories, or it IS in one of the included categories,
    // or it's a reblog and we're not doing those
    if( $previously == 0
	&& ( count($intersect) == 0 || count($intersect2) > 0 )
	&& ( (isset($p->reblogged_from_name) && $this->wpconfig['reblogs'])
		|| (!isset($p->reblogged_from_name) && !$this->wpconfig['reblogs'])
	)
    ):

    //error_log("New post found! Importing... \n\n", 3, LOGFILE);
    
    // add our post format to our tags as well
    $p->tags[] = 'type: '. $p->type;
    
    // and tags reblogs while we're at it...
    if( isset( $p->reblogged_from_name ) ){
      $p->tags[] = 'reblog';
      $p->tags[] = 'via: '. $p->reblogged_from_name;
      
      if( isset( $p->reblogged_root_name ) && $p->reblogged_root_name != $p->reblogged_from_name )
        $p->tags[] = 'source: '. $p->reblogged_root_name;
    }
    
  
    // format some stuff
    $format  = '';
    
    switch( $p->type ){
      case 'photo':
        $format = count( $p->photos ) > 1 ? 'gallery' : 'image';

        $content = '<div class="tumblr_photoset wp-caption aligncenter">';
        foreach( $p->photos as $img ){
          $content .= '<a href="'. $img->alt_sizes[0]->url .'" alt="'. $img->caption .'"><img  class="alignnone tumblr_img" src="'. $img->alt_sizes[1]->url .'" height="'. $img->alt_sizes[1]->height .'" width="'. $img->alt_sizes[1]->width .'"></a>';
        }
        $content .= "</div>\n\n". $p->caption;
      break;
          
      case 'quote':
        $format = 'quote';
        $content = '<blockquote class="tumblr_quote"><p>'. $p->text ."</p></blockquote>\n\n". $p->source;
      break;
          
      case 'link':
        $format = 'link';
        $content = '<a href="'. $p->url .'" class="tumblr_link">'. $p->title ."</a>\n\n". $p->description;
      break;
          
      case 'chat':
        $format = 'chat';
        $content = ''; $i = 0;
        foreach( $p->dialogue as $c ){
          $i++;
          $content .= '<p class="tumblr_chat'.( $i % 2 ).'"><span class="tumblr_chatname tumblr_chatname_'. strtolower( $c->name ) .'">'. $c->label .'</span> <span class="tumblr_chatline">'. $c->phrase .'</span></p>';
        }
      break;
          
      case 'audio':
        $format = 'audio';
        $content = $p->player ."\n\n". $p->caption;
      break;
          
      case 'video':
        $format = 'video';
        $content = $p->player[0]->embed_code ."\n\n". $p->caption;
      break;
          
      case 'answer':
        $askname = $p->asking_url ? '<a href="'. $p->asking_url .'" class="tumblr_user">'. $p->asking_name .'</a>' : $p->asking_name;
            
        $content = '<p class="tumblr_asker">'. $askname .' asked:</p><blockquote class="tumblr_question"><p>'. $p->question ."</q></blockquote>\n\n". $p->answer; 
            
      break;
        
      default:
        $format = strlen( $p->body ) > 400 ? '' : 'aside';
        $content = str_ireplace( '<!-- more -->', '<!--more-->', $p->body );
      break;
    }
      
    $post = array(
      'post_type'     => 'post',
      //'post_status'   => 'publish',
      'post_status'   => 'draft',
      'post_content'  => $content,
      'post_date'     => date( 'Y-m-d H:i:s', $p->timestamp ),
      'post_modified' => date( 'Y-m-d H:i:s', $p->timestamp ),
      'post_name'     => $p->id,
      'post_title'    => isset( $p->title ) ? $p->title : '',
    );

    // disallow comments on reblogs, but use our default settings for original posts
	// TODO: grab our blog name from the options rather than being a bad coder
	if( isset( $p->reblogged_from_name ) && $p->reblogged_root_name != $options['xpfrom'] )
		{ $post['comment_status'] = 'closed'; }
        
    $meta = array(
      'url'                   => $p->post_url,
      'source_url'            => isset( $p->source_url ) ? $p->source_url : '',
      'source_title'          => isset( $p->source_title ) ? $p->source_title : '',
      'reblogged_from_url'    => isset( $p->reblogged_from_url ) ? $p->reblogged_from_url : '',
      'reblogged_from_title'  => isset( $p->reblogged_from_title ) ? $p->reblogged_from_title : '',
      'reblogged_root_url'    => isset( $p->reblogged_root_url ) ? $p->reblogged_root_url : '',
      'reblogged_root_title'  => isset( $p->reblogged_root_title ) ? $p->reblogged_root_title : '',
          
    );
    
    // inset our post into wordpress
    $id = wp_insert_post( $post, false );
    
    if( $id > 0 ){
      // add tags and categories
      wp_set_post_terms( $id, $this->wpconfig['xpcat'], 'category', false );
      wp_set_post_terms( $id, $p->tags, 'post_tag', false );
    
      // the post format
      set_post_format( $id, $format );
      
      // post meta!
      add_post_meta( $id, '_tumblr_id', $p->id, true );
      add_post_meta( $id, '_tumblr_meta', $meta, true );
      
      // if we're an image or a gallery, let's import our media too...
      // ... but let's only do this for our own original posts, lest we break our server
      if( $p->type == 'photo' && empty( $p->reblogged_from_name ) ){
        $imgid = array();
        foreach( $p->photos as $img ){
          $imgid[] = media_sideload_image( $img->alt_sizes[0]->url, $id, $img->caption );
        }
      }

      // once we've done all of that, update our original post again
      // this is so any other plugin that triggers off wp_inser_post has information like the
      // tags, etc.
      // it's not a super-elegant solution, but... meh
      /*wp_update_post( array(
        'ID'          => $id, 
        'post_status' => 'publish'
      ) );*/
    }
    return $id;
    
    endif;
    return false;
  }

  // given a tumblr post id, see if we've previously imported this into wordpress
  public function previouslyImported( $id ){
    global $wpdb;

    //error_log("Checking whether post $id has an associated post ID... ", 3, LOGFILE);
    $previously = $wpdb->get_var( $wpdb->prepare(
      "SELECT COUNT(*) FROM $wpdb->postmeta WHERE meta_key = %s AND meta_value = %s;",
      '_tumblr_id', $id
    ) );
    //error_log("ID: $previously \n\n", 3, LOGFILE);
  
    return $previously;
  }

// fin.
}

// to make image sideloading work in the cron
require_once(ABSPATH . 'wp-admin/includes/media.php');
require_once(ABSPATH . 'wp-admin/includes/file.php');
require_once(ABSPATH . 'wp-admin/includes/image.php');
