<?php

namespace AD_BLIS;

if ( ! defined( 'ABSPATH' ) ) {
    exit; // Exit if accessed directly.
}

class Setup {
    
	// Class instance
	static $instance;

    private $api;
	
	/** 
     * Singleton instance
     */
    public static function get_instance() {
	    if ( ! isset( self::$instance ) ) {
		    self::$instance = new self();
	    }
	    return self::$instance;
    }

    /**
     * Class constructor
     */
    public function __construct() {
        $this->constants();
        $this->includes();
        $this->hooks();
    }
    
    /**
     * Api instance
     * 
     * @return object $api Api object
     */
    private function api() {	
        if( $this->api ){
			return $this->api;
		} else {
			return $this->api = new Api();
		}
    }
    
    /**
     * Hooks
     */
    public function hooks() {
        add_filter( 'jwt_auth_valid_credential_response', [ $this, 'credential_response' ], 10, 2 );
        add_filter( 'jwt_auth_authorization_header', [ $this, 'bypass_whitelisted_apis' ] );
        add_filter( 'jwt_auth_payload', [ $this, 'remove_expired_token' ], 15, 2 );
        add_filter( 'jwt_auth_extra_token_check', [ $this, 'custom_auth' ], 15, 4 );
        add_filter( 'jwt_auth_expire', [ $this, 'modify_auth_expiry' ] );
        add_action( 'rest_api_init', [ $this->api(), 'register_rest_routes' ] );
    }

	/**
	 * Filter to add refresh token to jwt plugin successful login response
     * 
     * @param array $response Basic user data.
	 * @param WP_User $user WordPress user Object
	 *
	 * @return array $response Modified basic user data
	 */
	public function credential_response( $response, $user ) {
        $expiry = time() + ( YEAR_IN_SECONDS * 1 );
        $response['data']['refresh_token'] = $this->api()->generate_token( $user, $expiry, true );
        return $response;
	}
    
    /**
     * Filter to prevent validation of whiltelisted APIs
     * jwt plugin only checks for whitelisted apis if header is not set
     * 
     * @param string $header_key Header key
	 *
	 * @return string $header_key Header key
     */
    public static function bypass_whitelisted_apis( $header_key ) {
        if( \JWTAuth\Setup::getInstance()->auth->is_whitelisted() ){
            if( isset( $_SERVER[ $header_key ] ) ){
                unset( $_SERVER[ $header_key ] );
            }
            if( isset( $_SERVER[ 'REDIRECT_HTTP_AUTHORIZATION' ] ) ){
                unset( $_SERVER[ 'REDIRECT_HTTP_AUTHORIZATION' ] );
            }
        }
        return $header_key;
    }
    
    /**
     * Clean expired tokens.
     * This is called each time a token is generated.
     * 
	 * @param array   $payload The token's payload.
	 * @param WP_User $user The user who owns the token.
	 *
	 * @return array $payload The token's payload.
     */
    public function remove_expired_token( $payload, $user ) {
        $this->api()->remove_expired_token( $user->ID );
        return $payload;
    }
    
    /**
	 * Filter token validation to check if token is revoked.
	 *
	 * @param string  $value The failed message.
	 * @param WP_User $user The user who owns the token.
	 * @param string  $token The token.
	 * @param array   $payload The token's payload.
	 *
	 * @return string The error message if failed, empty string if it passes.
	 */
    public function custom_auth( $value, $user, $token, $payload ) {
        $revoked_tokens = (array) get_user_meta( $user->ID, REVOKED_TOKENS_MK, true );
        foreach ( $revoked_tokens as $jwt_token ) {
            if ( $jwt_token === $token ) {
                return 'Invalid token.';
            }
        }
        return '';
    }
    
    /**
     * Filter to add more whitelist endpoints
     * We have to use anonymous function with 'jwt_auth_whitelist' hook. See read me in jwt plugin
     * 
     * @return array $endpoints The whitelisted endpoints
     */
    public static function auth_whitelist() {
        add_filter( 'jwt_auth_whitelist', function ( $endpoints ) {
            $endpoints[] = '/wp-json/jwt-auth/v1/token';
            $endpoints[] = '/wp-json/'.AD_BLIS_REST_API_NAMESPACE.'/reset-password';
            $endpoints[] = '/wp-json/'.AD_BLIS_REST_API_NAMESPACE.'/change-password';
            $endpoints[] = '/wp-json/'.AD_BLIS_REST_API_NAMESPACE.'/create-user';
            $endpoints[] = '/wp-json/'.AD_BLIS_REST_API_NAMESPACE.'/options/*';
            return $endpoints;
        });
    }
    
    /**
     * Filter to modify login validity
     * 
     * @param string $expiry Expiry date timestamp
     * @return string Modified expiry date timestamp
     */
    public function modify_auth_expiry( $expiry ) {
        return time() + ( MINUTE_IN_SECONDS * 30 );
    }
    
    /**
     *  Define constants
     */
    private function constants() {
        define( 'AD_BLIS_REST_API_NAMESPACE', 'vc/v1' );
        define( 'AD_BLIS_PATH', home_url('/wp-json/' . AD_BLIS_REST_API_NAMESPACE . '/') );
    }
    
    /**
     * Load plugin files
     */
    private function includes() {
		require_once 'class-methods.php';
		require_once 'class-apis.php';
    }

}