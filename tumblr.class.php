<?php
class TumblrAPI {

//= VARIABLES AND STUFF =========================================================//
 
  // tumblr api variables
  private $req_url = 'http://www.tumblr.com/oauth/request_token';
  private $authurl = 'http://www.tumblr.com/oauth/authorize';
  private $acc_url = 'http://www.tumblr.com/oauth/access_token';
  private $api_url = 'http://api.tumblr.com/v2/blog';             // the blog api, won't accept user methods obvs
  private $api_usr = 'http://api.tumblr.com/v2/user';             // for getting userinfo
  
  // our tumblpress oauth info
  // you'll need to get this from: https://www.tumblr.com/oauth/apps
  private $conskey = '';
  private $conssec = '';
  
  private $token;
  private $secret;
  
  private $userkey;
  private $usersec;
  
  // our OAuth class
  private $oauth;
  
  // errors
  private $err;
  
  // wordpress saved variables
  private $wpopts;
  private $wpconfig;


//= CONSTRUCTORS AND STUFF ======================================================//
  // lololol constructor i hate oop
  function __construct(){
    
    // extract our config values
    $this->wpopts   = get_option( 'tumblpress_oauth' );
    $this->wpconfig = get_option( 'tumblpress_config' );

    // do we actually have the OAuth class?
    if( class_exists( 'OAuth' ) ):
      try{
        $this->oauth = new OAuth( $this->conskey, $this->conssec, OAUTH_SIG_METHOD_HMACSHA1, OAUTH_AUTH_TYPE_URI );
        //$this->oauth->enableDebug();
      
      } catch( OAuthException $E ){
        print_r($E);
      }

    // if we don't have the OAuth class...
    else:
      $this->err = 'The PHP <a href="http://php.net/manual/en/book.oauth.php">OAuth</a> class is required to use <code>TumblrAPI</code>.';
      return false;
    endif;
  }
  // aa-aa-and that's a wrap.
  function __destruct() {
    return true;
  }


//= PUBLIC FUNCTIONS (AND STUFF) ================================================//
  // connect our tumblr account
  public function oauthAuthorise(){

    // this is basically if our redirect fucks up, reset us so we don't get "caught"
    if( !isset( $_GET['oauth_token'] ) && array_key_exists('auth_state', $this->wpopts) && $this->wpopts['auth_state'] == 1 )
      $this->wpopts['auth_state'] = 0;

    try {
      // if the user has clicked the button, get our access token
      if( array_key_exists('auth_state', $this->wpopts) && $this->wpopts['auth_state'] == 1 ){

        $this->oauth->setToken( $_GET['oauth_token'], $this->wpopts['oauth_token_secret'] );

        $access_token_info = $this->oauth->getAccessToken( $this->acc_url, '', '', 'GET' );
        
        $this->wpopts['auth_state'] = 2;

        $this->wpopts['token'] = $access_token_info['oauth_token'];
        $this->wpopts['secret'] = $access_token_info['oauth_token_secret'];
        
        update_option( 'tumblpress_oauth', $this->wpopts );
        
        // after we've done this, we need to progress by calling this function again
        // kinda messy but whatevs, fuck oauth
        $this->oauthAuthorise();
      
      // if we have our access token, auth us with it!
      } elseif( array_key_exists('auth_state', $this->wpopts) && $this->wpopts['auth_state'] == 2 ){
  
        $this->oauth->setToken( $this->wpopts['token'], $this->wpopts['secret'] );
      
        $this->oauth->fetch( $this->api_usr .'/info' );
        $r = $this->oauthResponse();
        
        // did we succeed? :D
        if( $r->meta->status == 200 ){
        
          unset( $this->wpopts['oauth_token']  );
          unset( $this->wpopts['oauth_token_secret'] );
          
          // get and save our username
          $this->wpopts['tumblr_user'] = $r->response->user->name;
          
          // get and save our blogs
          $this->wpopts['tumblr_blogs'] = array(); $i = 0;
          foreach( $r->response->user->blogs as $b ){
            $this->wpopts['tumblr_blogs'][$i]['name'] = $b->name;
            $this->wpopts['tumblr_blogs'][$i]['url']  = $b->url;
            $i++;
          }
          
          update_option( 'tumblpress_oauth', $this->wpopts );
        
        // or not? :(
        } else {
          throw new OAuthException( $r );
        }
      
      // start at the bottom, redirect the user to tumblr and get them to click the button
      } else {
        $request_token_info = $this->oauth->getRequestToken( $this->req_url, admin_url() .'options-general.php?page=tumblpress&do=auth', 'GET' );
        
        $this->wpopts['oauth_token']        = $request_token_info['oauth_token'];
        $this->wpopts['oauth_token_secret'] = $request_token_info['oauth_token_secret'];
        $this->wpopts['auth_state']         = 1;
        
        update_option( 'tumblpress_oauth', $this->wpopts );
        
        header( 'Location: '. $this->authurl .'?oauth_token='. $this->wpopts['oauth_token'] );
        exit;     
      }
      
    } catch( OAuthException $E ) {
      //print_r($E);
      $json = json_decode( $this->oauth->getLastResponse() );
      
      // if we failed to authorise… grr! but we should probably feed that back to the user and unset all our variables
      if( $json->meta->status == 401 ) {
        unset( $this->wpopts['auth_state'] );
        unset( $this->wpopts['oauth_token']  );
        unset( $this->wpopts['oauth_token_secret'] );
        unset( $this->wpopts['secret'] );
        unset( $this->wpopts['token'] );
        
        update_option( 'tumblpress_oauth', $this->wpopts );
        
        $this->err = "Autorisation failed! You'll need to reauth again, sorry. Honestly this is probably just OAuth being evil.";
      }
      return false;
    }
    
    // if we're falling to here, send us back to our callback page with no query string fluff
    header( 'Location: '. admin_url() .'options-general.php?page=tumblpress' );
    return true;
  }
  
  
  // post some shiznit…
  public function sendRequest( $api, $opts ){
    try {
      $this->oauth->setToken( $this->wpopts['token'], $this->wpopts['secret'] );
      $this->oauth->fetch( $this->api_url .'/'. $this->wpconfig['xpfrom'] .'.tumblr.com/'. $api, $opts, OAUTH_HTTP_METHOD_POST );
      
    } catch( OAuthException $E ) {
      $json = json_decode($this->oauth->getLastResponse());
      error_log("OAUTH EXCEPTION! \n" . var_export($E, true) ."\n\n". var_export($json, true) ."\n\n", 3, LOGFILE);
      return false;
    }
  }


  // get some shiznit…
  public function getRequest( $api, $opts, $oauth = false ){
    try {
	  if( $oauth == true )
		$this->oauth->setToken( $this->wpopts['token'], $this->wpopts['secret'] );
	  else 
        $opts = array_merge( $opts, array( 'api_key' => $this->conskey ) );
      
      $this->oauth->fetch( $this->api_url .'/'. $this->wpconfig['xpfrom'] .'.tumblr.com/'. $api, $opts, OAUTH_HTTP_METHOD_GET );
      
    } catch( OAuthException $E ) {
      //echo '<pre>';
        //print $this->api_url .'/'. $this->wpconfig['xpfrom'] .'.tumblr.com/'. $api .'?'. http_build_query( $opts ) .'<br/>';
        //print_r($E);
      //echo '</pre>';
      //$json = json_decode( $this->oauth->getLastResponse() );

      return false;
    }
  }
  
  
  // post some shiznit... for users!
  public function sendUserRequest( $api, $opts ){
    try {
      $this->oauth->setToken( $this->wpopts['token'], $this->wpopts['secret'] );
      $this->oauth->fetch( $this->api_usr .'/'. $api, $opts, OAUTH_HTTP_METHOD_POST );
      
    } catch( OAuthException $E ) {
      //print_r($E);
      //$json = json_decode( $this->oauth->getLastResponse() );

      return false;
    }
  }


  // get some shiznit... for users!
  public function getUserRequest( $api, $opts ){
    try {
      //$opts = array_merge( $opts, array( 'api_key' => $this->conskey ) );
      $this->oauth->setToken( $this->wpopts['token'], $this->wpopts['secret'] );
      $this->oauth->fetch( $this->api_usr .'/'. $api, $opts, OAUTH_HTTP_METHOD_GET );
      
    } catch( OAuthException $E ) {
      //echo '<pre>';
        //print $this->api_url .'/'. $this->wpconfig['xpfrom'] .'.tumblr.com/'. $api .'?'. http_build_query( $opts ) .'<br/>';
        //print_r($E);
      //echo '</pre>';
      //$json = json_decode( $this->oauth->getLastResponse() );

      return false;
    }
  }


  // return us our last error message
  public function getError(){
    return $this->err;
  }
  
  // return us out last oauth response
  public function oauthResponse(){
    return json_decode( $this->oauth->getLastResponse() );
  }


//= PRIVATE FUNCTIONS (NO MORE THAN THAT) =======================================//
  // set our user tokens
  private function oauthSetToken(){
    if( $this->userkey && $this->usersec ){
      //$this->oauth->setToken( $this->userkey, $this->usersec );
      return true;
    } else {
      return false;
    }
  }
}