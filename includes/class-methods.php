<?php

namespace AD_BLIS;

if ( ! defined( 'ABSPATH' ) ) {
    exit; // Exit if accessed directly.
}

use WP_Query;
use DateTime;
use Quote;
use Quote_Query;
use V_Mailer;
use DateTimeZone;
use Email_Confirmation;
use Exception;
use Firebase\JWT\JWT;

use JWTAuth\Auth;

class Methods {

    /**
	 * JWT plugin auth instance
	 *
	 * @var Object
	 */
    private $auth;
    
	/**
	 * Success response.
     * 
     * @return array
	 */
    private function success_response( $message, $code = '', $status = 200, $fields = [] ) {
		return [
            'success'   => true,
            'message'   => $message,
            'code'      => $code,
            'status'    => $status,
            'fields'    => $fields
        ];
    }
    
	/**
	 * Error response.
     * 
     * @return array
	 */
    private function error_response( $message, $code = '', $status = 403, $fields = [] ) {
		return [
            'success'   => false,
            'message'   => $message,
            'code'      => $code,
            'status'    => $status,
            'fields'    => $fields
        ];
    }
    
	/**
	 * Get push notification tokens.
     * 
     * @param string $token Push token string.
     * 
     * @return array Array of push notification tokens.
	 */
    public function get_push_tokens() {
        $push_tokens = get_user_meta( get_current_user_id(), PUSH_NOTIFICATION_TOKENS_MK, true );
        return is_array( $push_tokens ) ? $push_tokens : [];
    }
    
	/**
	 * Delete push notification tokens.
     * 
     * @param string $token Push token string.
     * 
     * @return array Success or failure.
	 */
    public function delete_push_token( $token ) {
        if( ! $token ) {
            return $this->error_response( 'Token is required.', 'validation_error', 400 );
        }
        $push_tokens = $this->get_push_tokens();
        $token_key = array_search( $token, $push_tokens );
        if( $token_key === false ) {
            return $this->error_response( 'Token does not exist.', 'validation_error', 400 );
        }
        unset( $push_tokens[$token_key] );
        $update = update_user_meta( get_current_user_id(), PUSH_NOTIFICATION_TOKENS_MK, $push_tokens );
        if( ! $update ) {
            return $this->error_response( 'An error occurred.', 'unknown_error' );
        }
        return $this->success_response( 'Token deleted successfully.', 'deleted' );
    }
    
	/**
	 * Update push notification token.
     * 
     * @param string $token Push token string.
     * 
     * @return array Success or failure.
	 */
    public function update_push_token( $token ) {
        if( ! $token ) {
            return $this->error_response( 'Token is required.', 'validation_error', 400 );
        }
        $push_tokens = $this->get_push_tokens();
        if( in_array( $token, $push_tokens ) ) {
            return $this->success_response( 'Token already exist.', 'token_available' );
        }
        $push_tokens[] = $token;
        $update = update_user_meta( get_current_user_id(), PUSH_NOTIFICATION_TOKENS_MK, $push_tokens );
        if( ! $update ) {
            return $this->error_response( 'An error occurred.', 'unknown_error' );
        }
        return $this->success_response( 'Token updated successfully.', 'updated' );
    }
    
	/**
	 * Get request by ID.
     * 
     * @param int $id Request ID
     * 
     * @return array Request data or error
	 */
    public function get_request( $id ) {
        
        if( ! isset( $id ) || ! absint( $id ) ){
            return $this->error_response( 'Request ID is required', 'validation_error', 400 );
        }

        $post = get_post( absint( $id ) );
        if( ! $post ){
            return $this->error_response( 'Request not found.', 'not_found' );
        }

        $post_id = $post->ID;
        $user = wp_get_current_user();
        $user_id = $user->ID;
            
        // Prevent users from sending a quote to themself
        if ( $post->post_author == $user_id ) {
            return $this->error_response( 'You cannot send a quote to yourself.', 'validation_error', 400 );
        }
        
        // Check if user already sent quote
        if ( has_sent_quote( $post_id, $user_id ) ) {
            return $this->error_response( 'You have already sent a quote to this client.', 'validation_error', 400 );
        }
        
        $read = (array) $post->_read;
        $read = array_key_exists( $user_id, $read ) ? 'read' : 'unread';
        
        $publish_date = date( 'c', strtotime( $post->post_date_gmt ) );
        $start_date = $post->job_start_date;
        if( true === validateDate( $start_date, 'F j, Y' ) ){
            $start_date = date( 'c', strtotime( $start_date ) );
        }

        // Quote page details
        $expiry = date( 'c', strtotime( $post->_expiry_date ) );

        $image = wp_get_attachment_image_src( $post->_thumbnail_id );
        $image = $image ? $image[0] : '';

        $price  = (float) $post->_price;
        $user_can_quote = false;
        $subscription = has_active_subscription( $user_id, true );
        $user_balance = woo_wallet()->wallet->get_wallet_balance( $user_id, 'edit' );
        if ( $user_balance >= $price || $subscription ) {
            $user_can_quote = true;
        }

        $quote_page = [
            'user_can_quote'     => $user_can_quote,
            'price_type_options' => get_quote_price_type(),
            'topmost_data' => [
                'quote_sent'     => [ 'label' => 'pros contacted', 'value' => get_request_quotes( $post_id, true ) ],
                'expiry_date'    => [ 'label' => 'request expires', 'value' => $expiry ],
                'price'          => [ 'label' => 'cost to quote', 'value' => get_price_format( $price ) ]
            ],
            'extra_data' => [
                'title'          => [ 'label' => 'Request Title', 'value' => $post->post_title ],
                'description'    => [ 'label' => 'Description', 'value' => $post->post_content ],
                'start_date'     => [ 'label' => 'Prefered Start Date', 'value' => $start_date ],
                'hire_stage'     => [ 'label' => 'Readiness to Hire', 'value' => $post->hire_stage ],
                'contact_method' => [ 'label' => 'Contact Method', 'value' => $post->contact_method ],
                'publish_date'   => [ 'label' => 'Request Submitted', 'value' => $publish_date ],
                'attachment'     => [ 'label' => 'Attachment', 'value' => $image ]
            ]
        ];

        return [
            'ID'            => $post_id,
            'title'         => $post->post_title,
            'content'       => $post->post_content,
            'client_f_name' => $post->first_name,
            'client_l_name' => $post->last_name,
            'city'          => $post->job_area,
            'state'         => $post->job_location,
            'start_date'    => $start_date,
            'read'          => $read,
            'publish_date'  => $publish_date,
            'quote_page_data' => $quote_page
        ];
    }

	/**
	 * Get requests.
     * 
     * @param array $args {
     *  @type int $number Total number of requests to retrieve. Default 10
     *  @type int $offset Offset. Default 0
     *  @type int $page Page number to retrieve. Default 1
     *  @type string $order DESC or ASC. Default DESC
     * }
     * 
     * @return array Array of requests
	 */
    public function get_requests( $args ) {
        
        extract( $args );
        $user = wp_get_current_user();
		
        $args = [];
	    
        if ( isset( $per_page ) && $per_page ) {
            $args['number'] = $per_page;
        } else {
            $args['number'] = 10;
        }
	    
        if ( isset( $offset ) && $offset ) {
            $args['offset'] = $offset;
        }
	    
        if ( isset( $page ) && $page ) {
            $args['offset'] = ( $page - 1 ) * $args['number'];
        }
	    
        if ( isset( $order ) && $order ) {
            $args['order'] = $order;
        }
	    
        if ( isset( $radius ) ) {
            $radius = (int) $radius;
        } else {
            $radius = (int) $user->travel_pref;
        }
		
		$user_id    = $user->ID;
        $sp_lat     = $user->user_add_lat;
        $sp_lng     = $user->user_add_lng;
        $services   = $user->pros_services;
        $term_name  = [];
        
        if( $services = $user->pros_services ) {
            foreach ( $services as $service ) {
                $term = get_term( $service, 'services' );
                $term_name[] = $term->name;
            }
        }
    
        $data = array(
	        'post_type' => 'product',
	        'post_status' => 'publish',
	        'posts_per_page' => -1,
	        'tax_query' => array(
		        array(
			        'taxonomy' => 'product_tag',
			        'field'    => 'name',
			        'terms'    => $term_name,
		        ),
		        array(
                    'taxonomy'  => 'product_cat',
                    'field'     => 'term_id',
                    'terms'     => (int) get_option('expired_term_id'),
                    'operator'  => 'NOT IN'
                ),
	        ),
        );
        $query          = new WP_Query( $data );
        $posts          = $query->posts;
        $results        = [];
        
        foreach ( $posts as $post ) {
            
            $post_id = $post->ID;
            
            // Prevent users from seeing their own jobs
            if ( $post->post_author == $user_id ) {
                continue;
            }
            
            // Check if user already sent quote
            if ( has_sent_quote( $post_id, $user_id ) ) {
                continue;
            }
            
            $request_lat = $post->request_lat;
            $request_lng = $post->request_lng;
        
            $distance = (float) get_distance( $request_lat, $request_lng, $sp_lat, $sp_lng );
            
            if ( $radius ) {
                if ( $distance > $radius ) {
                    continue;
                }
            }
            
            $read = (array) $post->_read;
            $read = array_key_exists( $user_id, $read ) ? 'read' : 'unread';
            
            $publish_date = date( 'c', strtotime( $post->post_date_gmt ) );
            $start_date = $post->job_start_date;
            if( true === validateDate( $start_date, 'F j, Y' ) ){
                $start_date = date( 'c', strtotime( $start_date ) );
            }

            // Quote page details
            $expiry = date( 'c', strtotime( $post->_expiry_date ) );

            $image = wp_get_attachment_image_src( $post->_thumbnail_id );
            $image = $image ? $image[0] : '';

            $price  = (float) $post->_price;
            $user_can_quote = false;
            $subscription = has_active_subscription( $user_id, true );
            $user_balance = woo_wallet()->wallet->get_wallet_balance( $user_id, 'edit' );
            if ( $user_balance >= $price || $subscription ) {
                $user_can_quote = true;
            }

            $quote_page = [
                'user_can_quote'     => $user_can_quote,
                'price_type_options' => get_quote_price_type(),
                'topmost_data' => [
                    'quote_sent'     => [ 'label' => 'pros contacted', 'value' => get_request_quotes( $post_id, true ) ],
                    'expiry_date'    => [ 'label' => 'request expires', 'value' => $expiry ],
                    'price'          => [ 'label' => 'cost to quote', 'value' => get_price_format( $price ) ]
                ],
                'extra_data' => [
                    'title'          => [ 'label' => 'Request Title', 'value' => $post->post_title ],
                    'description'    => [ 'label' => 'Description', 'value' => $post->post_content ],
                    'start_date'     => [ 'label' => 'Prefered Start Date', 'value' => $start_date ],
                    'hire_stage'     => [ 'label' => 'Readiness to Hire', 'value' => $post->hire_stage ],
                    'contact_method' => [ 'label' => 'Contact Method', 'value' => $post->contact_method ],
                    'publish_date'   => [ 'label' => 'Request Submitted', 'value' => $publish_date ],
                    'attachment'     => [ 'label' => 'Attachment', 'value' => $image ]
                ]
            ];

            $results[] = [
                'ID'            => $post_id,
                'title'         => $post->post_title,
                'content'       => $post->post_content,
                'client_f_name' => $post->first_name,
                'client_l_name' => $post->last_name,
                'city'          => $post->job_area,
                'state'         => $post->job_location,
                'distance'      => $distance,
                'start_date'    => $start_date,
                'read'          => $read,
                'publish_date'  => $publish_date,
                'quote_page_data' => $quote_page
            ];
            
        }
        
        // Sort data asc -> dec
        usort( $results, function($a, $b) {
            return $a['distance'] <=> $b['distance'];
        });
        
        $total_records = count( $results );
        $total_pages   = ceil( $total_records / $args['number'] );
        
        $results = array_slice( $results , $args['offset'], $args['number'] );

        return [
            'data'          => $results,
            'total_records' => (int) $total_records,
            'total_pages'   => (int) $total_pages,
        ];
    }
    
	/**
	 * Get quotes sent by users.
     * 
     * @param array $args {
     *  @type int $number Total number to retrieve. Default 20
     *  @type int $offset Offset. Default 0
     *  @type int $page Page number to retrieve. Default 1
     *  @type string $orderby ID or date. Default date
     *  @type string $order DESC or ASC. Default DESC
     *  @type string $status hired|not_hired|active. Default All
     * }
     * 
     * @return array Array of quotes
	 */
    public function get_quotes( $args ) {
        extract( $args );
        $args = [];
        $user = wp_get_current_user();
        $args['user_id'] = $user->ID;
	    
        if ( isset( $per_page ) && $per_page ) {
            $args['number'] = $per_page;
        }
	    
        if ( isset( $offset ) && $offset ) {
            $args['offset'] = $offset;
        }
	    
        if ( isset( $page ) && $page ) {
            $args['offset'] = ( absint( $page ) - 1 ) * $args['number'];
        }
	    
        if ( isset( $orderby ) && $orderby ) {
            $args['orderby'] = $orderby;
        }
	    
        if ( isset( $order ) && $order ) {
            $args['order'] = $order;
        }
	    
        if ( isset( $status ) && isset( get_quote_status()[$status] ) ) {
            $args['status'] = $status;
        }
	    
        $query  = new Quote_Query( $args );
        $quotes = $query->get_quotes();
        
        // Count total
	    $args['number'] = -1;
	    $args['offset'] = 0;
        $total_records  = new Quote_Query( $args );
        $total_records  = $total_records->count();
        $total_pages    = ceil( $total_records / $per_page );
        $results        = [];
        
        foreach ( $quotes as $quote ) {
            
            $data = [];
            if ( $post = get_post( $quote->request_id ) ){
                
                $post_id    = $post->ID;
                $expired    = get_option( 'expired_term_id' );
                $expired    = has_term( $expired, 'product_cat', $post_id );
                
                $publish_date = date( 'c', strtotime( $post->post_date_gmt ) );
                $start_date = $post->job_start_date;
                if( true === validateDate( $start_date, 'F j, Y' ) ){
                    $start_date = date( 'c', strtotime( $start_date ) );
                }
                
                $data = [
                    'ID'                => $post_id,
                    'title'             => $post->post_title,
                    'content'           => $post->post_content,
                    'client_f_name'     => $post->first_name,
                    'client_l_name'     => $post->last_name,
                    'city'              => $post->job_area,
                    'state'             => $post->job_location,
                    'start_date'        => $start_date,
                    'publish_date'      => $publish_date,
                    'contact_method'    => $post->contact_method,
                    'phone_number'      => '',
                    'customer_profile'  => '',
                    'expired'           => $expired,
                ];
                
                if ( $user = get_user_by( 'email', $post->email ) ){
                    $convo_url = get_permalink( get_option('vc_conversations_page') );
                    $data['client_id']         = $user->ID;
                    $data['customer_profile']  = get_user_profile_link( $user );
                    $data['client_pm_url']   = "{$convo_url}{$user->user_login}/";
                    $data['client_profile_image'] = get_profile_photo( $user, 120, true, true );
	                $data['phone_number']  = $post->is_number_verified;
                }
                
            }
            
            $data = [
                'quote_id'      => (int) $quote->quote_id,
                'request_id'    => (int) $quote->request_id,
                'request_name'  => $quote->request_name,
                'message'       => $quote->message,
                'price'         => $quote->price,
                'price_type'    => $quote->price_type,
                'status'        => get_quote_status()[$quote->status],
                'date'          => date( 'c', strtotime( $quote->date ) ),
                'request'       => $data
            ];
            
            $results[] = $data;
                
        }
        return [
            'data'          => $results,
            'total_records' => (int) $total_records,
            'total_pages'   => (int) $total_pages
        ];
    }
    
	/**
	 * Send quote
     * 
     * @param array $args {
     *  @type int $id Request ID
     *  @type float $price
     *  @type string $price_type
     *  @type string $message
     * }
     * 
     * @return array Array of client data
	 */
    public function send_quote( $args ) {
        extract( $args );
        $error = [];
        if( ! isset( $id ) || ! $id ){
            $error['id'] = [ 'error' => 'Request ID is required', 'key' => 'id' ];
        }
        if( ! isset( $price ) || ! sanitize_number_input( $price ) ){
            $error['price'] = [ 'error' => 'Price is required.', 'key' => 'price' ];
        }
        if( ! isset( $price_type ) || ! sanitize_text_field( $price_type ) ){
            $error['price_type'] = [ 'error' => 'Price type is required.', 'key' => 'price_type' ];
        }
        if( ! array_key_exists( sanitize_text_field( $price_type ), get_quote_price_type() ) ){
            $error['price_type'] = [ 'error' => 'Invalid price type', 'key' => 'price_type' ];
        }
        if( ! isset( $message ) || ! sanitize_textarea_field( stripslashes( $message ) ) ){
            $error['message'] = [ 'error' => 'Message is required.', 'key' => 'message' ];
        }
        if( $error ){
            return $this->error_response( 'Missing field(s).', 'validation_error', 400, $error );
        }

        $price      = sanitize_number_input( $price );
        $price_type = get_quote_price_type()[sanitize_text_field( $price_type )];
        $message    = sanitize_textarea_field( stripslashes( $message ) );

        $user = wp_get_current_user();
		$user_id = $user->ID;

        $post = get_post( $id );
        if ( ! $post ) {
            return $this->error_response( 'The request has been removed.', 'invalid_request' );
        }
        $post_id = $post->ID;

        // Check if user is allowed to send quote
        if ( true !== $response = $this->user_can_send_quote( $user,  $post ) ) {
            return $this->error_response( $response['message'], 'not_allowed' );
        }

        // Check if the user has already sent quote
        if ( has_sent_quote( $post_id, $user_id ) ) {
            return $this->error_response( 'You have already contacted this client. View their contact info on the quotes page.', 'not_available' );
        }

        $quotes_per_client = (int) get_option( 'quotes_per_client' );
        
        // Check if request has expired
        if ( has_term( 'post-expired', 'product_cat', $post_id ) ) {
            $quote_sent = get_request_quotes( $post_id, true );
            // Can be 'not_hiring', 'outside_viscorner', or INT (user_id)
            if ( $post->_pro_hired !== 'not_hiring' ) {
                return $this->error_response( 'The client already hired.', 'not_available' );
            } else if ( $quote_sent >= $quotes_per_client ) {
                return $this->error_response( 'The maximum number of pros that can contact the client has been reached.', 'not_available' );
            } else {
                return $this->error_response( 'The request has already expired.', 'not_available' );
            }
        }

        $request_fee  = (float) $post->_price;
        $has_sub      = has_active_subscription( $user_id, true );
        $user_balance = woo_wallet()->wallet->get_wallet_balance( $user_id, 'edit' );

        if ( true === $has_sub || $user_balance >= $request_fee ) {

            // Use wallet if no subscription
            if ( false === $has_sub ) {
                $description = "Payment for request #$post_id";
                $debit = woo_wallet()->wallet->debit( $user_id, $request_fee, $description );
                if ( $debit === false ) {
                    return $this->error_response( 'Your wallet balance is low. Please fund your wallet or subscribe to send quote.', 'not_allowed' );
                }
            }

            $request_title  = $post->post_title;
            $service_id     = $post->service_id;
            $quote_info = array(
                'user_id'       => $user_id,
                'request_id'    => $post_id,
                'request_name'  => $request_title,
                'service_id'    => $service_id,
                'price'         => $price,
                'price_type'    => $price_type,
                'message'       => $message
            );
            
            // Create the quote
            $quote = new Quote();
            $quote->create_quote( $quote_info );
            
            // Maybe expire the request
            if ( get_request_quotes( $post_id, true ) >= $quotes_per_client ) {
                expire_request( $post_id );
            }

            // Response data
            $client_id = '';
            $client_username = '';
            $client_profile_image = '';
            $convo_url = get_permalink( get_option('vc_conversations_page') );
            $client_phone = $post->is_number_verified;

            // Email customer about new quote
            if ( $client = get_user_by( 'email', $post->email ) ) {
                $hide_this      = true; // Used to temporarily hide email field
                $client_id      = $client->ID;
                $client_username = $client->user_login;
                $client_profile_image = get_profile_photo( $user, 120, true, true );
                $convo_url      = "{$convo_url}{$client_username}/";
                $sp_profile     = get_user_profile_link( $user );
                $first_name     = $client->first_name;
                $sp_name        = $user->display_name;
                $sp_phone       = $user->{PHONE_NUMBER_MK};
                $price          = get_price_format( $price ) . ' | ' . $price_type;

                // Email content
                ob_start();
                include( VISCORNER_COMMON_DIR . "emails/new-quote.php" );
                $content = ob_get_clean();

                $mailer = new V_Mailer();
                $data = [
                    'email'   => $client->user_email,
                    'subject' => "$sp_name is interested in your $request_title request",
                    'content' => add_email_template( $content, $subject )
                ];
                $mailer->send( $data );
            }

            // Maybe save quote template
            if( ( isset( $save_template ) && $save_template ) ){
                $this->update_quote_template([
                    'template_title' => ( isset( $template_title ) && $template_title ) ? $template_title : 'New template',
                    'template_content' => $message,
                ]);
            }

            $data = [
                'first_name'        => $post->first_name,
                'last_name'         => $post->last_name,
                'contact_method'    => $post->contact_method,
                'phone_number'      => $client_phone,
                'client_id'         => $client_id,
                'client_pm_url'     => $convo_url,
                'client_profile_image' => $client_profile_image
            ];

            return [
                'success' => true,
                'data'    => $data
            ];
        
        }
        return $this->error_response( 'Your wallet balance is low. Please fund your wallet or subscribe to send quote.', 'not_allowed' );
    }
    
	/**
	 * Get quote templates
	 *
     * @param array $args {
     *  @type int $template_id Template ID
     *  @type int $request_id Request ID
     * }
     * 
	 * @return array
	 */
    public function get_quote_templates( $args = [] ) {
        $templates = wp_get_current_user()->{QUOTE_TEMPLATES_MK};
        if( $args && isset( $args['template_id'] ) ){
            extract( $args );
            if( $templates && isset( $templates[$template_id] ) ){
                $template = $templates[$template_id];
                if( isset( $request_id ) && $request_id ){
                    if( $request = get_post( $request_id ) ){
                        $replace = [
                            '[first_name]' => $request->first_name
                        ];
                        $template['content'] = strtr( $template['content'], $replace );
                    }
                }
                return [
                    'success' => true,
                    'data'    => $template
                ];
            }
            return $this->error_response( 'Template not found.', 'template_not_found' );
        }
        return [
            'success' => true,
            'data'    => $templates
        ];
    }
    
	/**
	 * Update quote data.
	 *
     * @param array $args {
     *  @type int $template_id Template ID.
     *  @type string $template_title Optional.
     *  @type string $template_content Optional.
     * }
     * 
	 * @return array Array of success or failure data.
	 */
    public function update_quote_template( $data ) {
        extract( $data );
        $errors = [];
        if( ( isset( $template_title ) && ! $template_title ) ){
            $key = 'template_title';
            $errors[$key] = [
                'error' => "Title is required.",
                'key'   => $key
            ];
        }
        if( ( isset( $template_content ) && ! $template_content ) ){
            $key = 'template_content';
            $errors[$key] = [
                'error' => "Content is required.",
                'key'   => $key
            ];
        }
        if( $errors ){
            return $this->error_response( 'An error occurred', 'validation_error', 400, $errors );
        }
        $user = wp_get_current_user();
        $templates = $user->{QUOTE_TEMPLATES_MK};
        $templates = is_array( $templates ) ? $templates : [];
        if( isset( $template_id ) && $template_id ){
            if( ! isset( $templates[$template_id] ) ){
                return $this->error_response( 'Template not found.', 'not_found' );
            }
            if( isset( $template_title ) && ( $templates[$template_id]['title'] !== $template_title ) ){
                $templates[$template_id]['title'] = sanitize_text_field( stripslashes( $template_title ) );
            }
            if( isset( $template_content ) && ( $templates[$template_id]['content'] !== $template_content ) ){
                $templates[$template_id]['content'] = sanitize_textarea_field( stripslashes( $template_content ) );
            }
        } else {
            if( ( ! isset( $template_title ) ) ){
                $key = 'template_title';
                $errors[$key] = [
                    'error' => "Title is required.",
                    'key'   => $key
                ];
            }
            if( ( ! isset( $template_content ) ) ){
                $key = 'template_content';
                $errors[$key] = [
                    'error' => "Content is required.",
                    'key'   => $key
                ];
            }
            if( $errors ){
                return $this->error_response( 'An error occurred', 'validation_error', 400, $errors );
            }
            $template_id = time();
            $templates[$template_id] = [
                'title' => sanitize_text_field( stripslashes( $template_title ) ),
                'content' => sanitize_textarea_field( stripslashes( $template_content ) )
            ];
        }
        update_user_meta( $user->ID, QUOTE_TEMPLATES_MK, $templates );
        return $this->success_response( 'Template saved successfully.', 'template_saved', 200, ['template_id' => $template_id] );
    }
    
	/**
	 * Delete quote data.
     * 
     * @param int $template_id Template ID.
     * 
	 * @return array Array of success or failure data.
	 */
    public function delete_quote_template( $template_id ) {
        $user = wp_get_current_user();
        $templates = $user->{QUOTE_TEMPLATES_MK};
        $templates = is_array( $templates ) ? $templates : [];
        if( ! isset( $templates[$template_id] ) ){
            return $this->error_response( 'Template not found.', 'not_found' );
        }
        unset( $templates[$template_id] );
        if( $templates ){
            update_user_meta( $user->ID, QUOTE_TEMPLATES_MK, $templates );
        } else {
            delete_user_meta( $user->ID, QUOTE_TEMPLATES_MK );
        }
        return $this->success_response( 'Template deleted successfully.', 'template_deleted' );
    }
    
	/**
	 * Get user data
     * 
     * @param string $field field key.
     * 
	 * @return array|string Array of user data or value of specified key.
	 */
    public function get_user_data( $field = '' ) {
        $user = wp_get_current_user();
        $balance = woo_wallet()->wallet->get_wallet_balance( $user->ID, 'edit' );
        $fields = [
            'has_subscription'  => has_active_subscription( $user->ID, true ),
            'wallet_balance'    => get_price_format( $balance ),
            'profile_image_url' => get_profile_photo( $user, 70, true, true ),
            'profile_url'       => get_user_profile_link( $user ),
            'notifications'     => $this->get_user_notifications(),
            'first_name'        => $user->first_name,
            'last_name'         => $user->last_name,
            'username'          => $user->user_login,
            'email'             => $user->user_email,
            'display_name'      => $user->display_name,
            'phone_number'      => $user->phone_number,
            'whatsapp_number'   => $user->whatsapp_number,
            'description'       => $user->business_info,
            'business_type'     => $user->Provider_type,
            'year_founded'      => $user->founding_year,
            'no_of_employees'   => $user->no_of_employees,
            'website'           => $user->user_website_url,
            'youtube'           => $user->video,
            'services'          => $user->pros_services,
            'travel_preference' => $user->travel_pref,
            'address'           => $user->business_address,
            'city'              => $user->business_area,
            'state'             => $user->business_state,
            'address2'          => $user->address2,
            'city2'             => $user->city2,
            'state2'            => $user->state2,
            'address3'          => $user->address3,
            'city3'             => $user->city3,
            'state3'            => $user->state3,
            'prices'            => $user->average_prices,
            'qualifications'    => $user->qualifications,
            'quote_templates'   => $user->quote_templates ? $user->quote_templates : [],
            'bank_name'             => $user->{BANK_NAME_MK},
            'bank_account_name'     => $user->{BANK_ACCOUNT_NAME_MK},
            'bank_account_number'   => $user->{BANK_ACCOUNT_NUMBER_MK},
            'id_card_type'          => $user->{ID_CARD_TYPE_MK},
            'id_card_name'          => $user->{ID_CARD_NAME_MK},
            'id_card_expiry_date'   => date( 'c', strtotime( $user->{ID_CARD_EXPIRY_DATE_MK} ) ),
            'cac_registration_type' => $user->{CAC_REGISTRATION_TYPE_MK},
            'cac_business_name'     => $user->{CAC_BUSINESS_NAME_MK},
            'cac_registration_no'   => $user->{CAC_REGISTRATION_NO_MK},
            'id_card_status'        => $user->{ID_CARD_STATUS_MK},
            'cac_status'            => $user->{CAC_STATUS_MK},
            'bank_account_status'   => $user->{BANK_ACCOUNT_STATUS_MK},
            'no_of_employees'       => $user->{NO_OF_EMPLOYEES_MK},
            'hired_count'           => (int) $user->{TIMES_HIRED_COUNT_MK},
            'reviews_avg'           => (float) number_format( $user->_reviews_avg, 1 ),
            'reviews_total'         => (int) $user->reviews_total
        ];
        if( $field ) {
            if ( isset( $fields[ $field ] ) ) {
                return $fields[ $field ];
            }
            return '';
        }
        return $fields;
    }
    
	/**
	 * Validate user data
     * 
     * @param array $data User data to validate
     * @param array $user WordPress user object. Optional
     * 
     * @return array Validated and Sanitized data
	 */
    public function validate_user_data( $data, $user = [] ) {
        $fields  = $this->get_account_fields();
        $data    = array_intersect_key( $data, $fields );
        
        $values = [];
        $errors = [];

        foreach( $data as $key => $value ){
            
            $value      = is_array( $value ) ? $value : trim( $value );
            $label      = $fields[$key]['label'];
            $type       = $fields[$key]['type'];
            $meta_key   = $fields[$key]['key'];
            $required   = $fields[$key]['required'];
            
            // Validate
            if( $required === true && ! $value ){

                $errors[$key] = [
                    'error' => "$label is required.",
                    'key'   => $key
                ];
                
            } else if( $value === '0' || $value ) {
                
                if ( $type === 'email' ){
                    if ( ! filter_var( $value, FILTER_VALIDATE_EMAIL ) ) {
                        $errors[$key] = [
                            'error' => "Invalid email address.",
                            'key'   => $key
                        ];
                    }
                
                } else if ( $type === 'password' ){
            
                    // Prevent saving them in meta
                    if( in_array( $key, [ 'current_password', 'confirm_password' ]) ){
                        continue;
                    }
                    
                    if( $key === 'delete_acc_pass' ) {
                        if ( false === $this->confirm_password( $data['delete_acc_pass'], $user ) ) {
                            $errors[$key] = [
                                'error' => 'Your password is incorrect.',
                                'key'   => $key
                            ];
                        }

                    } else if ( isset( $user->ID ) ) {
                        // Validate password for registered users
                        $response = $this->validate_password( $data['current_password'], $data['new_password'], $data['confirm_password'], $user );
                        if ( true !== $response ) {
                            $errors[$response['key']] = [
                                'error' => $response['error'],
                                'key'   => $response['key']
                            ]; 
                        }

                    } else {
                        // Validate password for new users
                        if ( false === $this->is_strong_password( $data['new_password'] ) ) {
                            $errors[$key] = [
                                'error' => 'Your password must be at least 8 characters in length and it must contain at least one lower case letter, one capital letter and one number.',
                                'key'   => $key
                            ];
                        }

                    }
                    
                } else if ( $type === 'number' ){

                    $min = $fields[$key]['min'];
                    $max = $fields[$key]['max'];
                    $response = $this->validate_number( $value, $min, $max );
                    if ( true !== $response ) {
                        $errors[$key] = [
                            'error' => ( $min || $max ) ? $response : "$label is invalid",
                            'key'   => $key
                        ]; 
                    }
                
                } else if ( $type === 'url' ){

                    if ( ! filter_var( $value, FILTER_VALIDATE_URL ) ) {
                        $errors[$key] = [
                            'error' => 'Invalid url',
                            'key'   => $key
                        ]; 
                    }

                } else if ( $type === 'select' ){

                    // Try to decode json
                    if (  is_string( $value ) ) {
                        if ( strpos( $value, '[') !== false ) {
                            $value = json_decode( stripslashes( $value ), true );
                        }
                    }

                    if ( $fields[$key]['options'] ) {
                        if ( is_array( $value ) ) {
                            foreach ( $value as $k => $v ) {
                                if ( ! isset( $fields[$key]['options'][$v] ) ){
                                    $errors[$key] = [
                                        'error' => 'Invalid selection',
                                        'key'   => $key
                                    ]; 
                                    break;
                                }
                            }
                        } else {
                            if ( ! isset( $fields[$key]['options'][$value] ) ) {
                                $errors[$key] = [
                                    'error' => 'Invalid selection',
                                    'key'   => $key
                                ]; 
                            }
                        }
                    } else if ( in_array( $key, [ 'services', 'state' ] ) ){
                        $taxonomy = ( $key === 'state' ) ? 'category' : $key;
                        if ( is_array( $value ) ) {
                            foreach ( $value as $v ) {
                                if ( ! get_term( $v, $taxonomy ) ) {
                                    $errors[$key] = [
                                        'error' => 'Invalid selection',
                                        'key'   => $key
                                    ]; 
                                    break;
                                }
                            }
                        } else {
                            if ( ! $term = get_term( $value, $taxonomy ) ) {
                                $errors[$key] = [
                                    'error' => 'Invalid selection',
                                    'key'   => $key
                                ]; 
                            }
                        }
                    }

                }
                
            }
            
            // Sanitize
            if ( $type === 'text' ){
                $values[$meta_key] = sanitize_text_field( stripslashes( $value ) );
                
            } else if ( $type === 'textarea' ){
                $values[$meta_key] = sanitize_textarea_field( stripslashes( $value ) );
                    
            } else if ( $type === 'select' ){
                $values[$meta_key] = is_array( $value ) ? array_map( 'sanitize_text_field', $value ) : sanitize_text_field( $value );
                    
            } else if ( $type === 'number' ){
                $values[$meta_key] = sanitize_text_field( $value );
                    
            } else if ( $type === 'url' ){
                $values[$meta_key] = sanitize_url( $value );
                    
            } else {
                $values[$meta_key] = $value;
            }
            
        }
        if( $errors ){
            $success = false;
            $data    = $errors;
        } else {
            $success = true;
            $data    = $values;
        }
        return [ 'success' => $success, 'data' => $data ];
    }
    
	/**
	 * Update user data
     * 
     * @param array $data User data to update
     * 
	 * @return array Array of success or failure data.
	 */
    public function update_user_data( $data ) {

        $user    = wp_get_current_user();
        $user_id = $user->ID;
        
        // Update notifications
        if( array_intersect_key( $data, $this->get_notification_keys( null, false ) ) ) {
            return $this->update_notifications( $user_id, $data );
        }
        
        // Update profile picture
        if( isset( $data[PROFILE_PHOTO_MK] ) ) {
            return $this->update_profile_photo( $user, $data );
        }
        
        // Templates have their own API
        if( isset( $data[QUOTE_TEMPLATES_MK] ) ) {
            unset( $data[QUOTE_TEMPLATES_MK] );
        }
        
        $data = $this->validate_user_data( $data, $user );
        
        if( false === $data['success'] ) {
            return $this->error_response( 'An error occurred', 'validation_error', 400, $data['data'] );
        }

        $values = $data['data'];
        
        // Update verification data
        if( array_intersect_key( $values, $this->verfification_data_keys() ) ) {
            return $this->update_verfification_data( $user, $values );
        }
        
        // Delete account
        if( isset( $values['delete_acc_pass'] ) ) {
            require_once( ABSPATH.'wp-admin/includes/user.php' );
            if ( true === wp_delete_user( $user_id ) ) {
                return $this->success_response( 'Account deleted successfully.', 'account_deleted' );
            }
            return $this->error_response( 'There is an issue deleting your account.', 'update_error' );
        }

        $primary_data_keys = [
            'user_email',
            'display_name',
            'user_pass'
        ];
    
        $user_meta = [];
        $user_data = [];
        foreach( $values as $key => $value ){
            if( in_array( $key, $primary_data_keys ) ){
                if( $value !== $user->{$key} ){
                    // Ignore same password
                    if( $key === 'user_pass' && $this->confirm_password( $value, $user ) ){
                        continue;
                    }
                    $user_data[$key] = $value;
                    // Sync nickname
                    if( $key === 'display_name' ){
                        $user_meta['nickname'] = $value;
                    }
                }
            } else if( $value === '0' || $value ) {
                if( $value !== get_user_meta( $user_id, $key, true ) ){
                    $user_meta[$key] = $value;
                }
            } else {
                delete_user_meta( $user_id, $key );
            }
        }
        
        if( $user_data ){
            $result = wp_update_user( array_merge( ['ID' => $user_id], $user_data ) );
            if ( is_wp_error( $result ) ) {
                return $this->error_response( $result->get_error_message(), 'update_error' );
            }
        }
        
        if( $user_meta ){
            foreach( $user_meta as $key => $value ){
                update_user_meta( $user_id, $key, $value );
            }
        }
        
        if( isset( $user_data['user_email'] ) ) {
            update_user_meta( $user_id, VC_EMAIL_CONFIRMATION_KEY, 'no' );
            Email_Confirmation::get_instance()->send_email( wp_get_current_user() );
            return $this->success_response( 'Email successfully updated.', 'email_changed' );
        }
    
        if( isset( $user_data['user_pass'] ) ){
            return $this->success_response( 'Password successfully updated.', 'password_changed' );
        }
        return $this->success_response( 'Updated successfully.', 'data_updated' );
    }

    /**
     * Create a user.
     * 
     * @param array $data Form data.
     * 
	 * @return array Array of success or failure data.
     */
    public function create_user( $data ){
        $fields   = $this->get_account_fields();
        $defaults = array_combine( array_keys( $fields ), array_column( $fields, 'default' ) );
        $data     = wp_parse_args( $data, $defaults );
        // Remove unused
        unset( $data['current_password'] );
        unset( $data['confirm_password'] );
        unset( $data['delete_acc_pass'] );

        $data = $this->validate_user_data( $data );
        if( false === $data['success'] ) {
            return $this->error_response( 'An error occurred', 'validation_error', 400, $data['data'] );
        }
        $data = $data['data'];
        $userdata = [
            'user_login'    => $data['user_login'],
            'nickname'      => $data['display_name'],
            'display_name'  => $data['display_name'],
            'first_name'    => $data['first_name'],
            'last_name'     => $data['last_name'],
            'user_email'    => $data['user_email'],
            'user_pass'     => $data['user_pass'],
            'role'          => 'pro',
            'meta_input'    => [
                'times_hired_count'             => 0, // Needed for orderby
                '_reviews_avg'                  => 0, // Needed for orderby
                '_current_woo_wallet_balance'   => 0, // Needed for wallet query
                VC_NEW_REGISTRATION_KEY         => 1, // Needed to give free trial
                VC_EMAIL_CONFIRMATION_KEY       => 'no' // Email confirmation
            ]
        ];
        // Set meta and remove empty values
        $userdata['meta_input'] = array_filter( array_merge( $userdata['meta_input'], array_diff_key( $data, $userdata ) ) );
        $user_id = wp_insert_user( $userdata );
        if ( is_wp_error( $user_id ) ) {
            return $this->error_response( $user_id->get_error_message(), 'insert_error' );
        }
        // Update notifications
        foreach( $this->get_notification_keys( null, false ) as $key => $value ){
            update_user_meta( $user_id, $key, 1 );
        }
        // Send activation email
        Email_Confirmation::get_instance()->send_email( get_userdata( $user_id ) );
        return $this->success_response( 'User created successfully', 'user_created' );
    }

	/**
	 * Update notifications.
     * 
     * @param int $user_id User ID.
     * @param array $data Array of notification keys.
     * 
     * @return array Array of success data.
	 */
    public function update_notifications( $user_id, $data ) {
        foreach( $data as $key => $value ){
            if( $value ) {
                update_user_meta( $user_id, $key, 1 );
            } else {
                delete_user_meta( $user_id, $key );
            }
        }
        return $this->success_response( 'Updated successfully', 'data_updated' );
    }

	/**
	 * Update notifications.
     * 
     * @param array $user WordPress user object.
     * @param array $data Array of verfification data.
     * 
     * @return array Array of success or failure data.
	 */
    public function update_verfification_data( $user, $data ) {
        $old_data = [];
        $new_upload = false;
        foreach( $data as $key => $value ){
            if( in_array( $key, ['id_card_front_page', 'id_card_back_page', 'cac_registration_doc'] ) ){
                $um_uploads_dir = trailingslashit( wp_upload_dir()['basedir'] ) . 'ultimatemember';
                $user_folder    = trailingslashit( $um_uploads_dir ) . absint( $user->ID );
                if ( ! is_dir( $user_folder ) ) {
                    wp_mkdir_p( $user_folder );
                }
                if( is_array( $value['name'] ) ){
                    foreach( $value['name'] as $k => $name ){
                        $file_ext       = pathinfo( $name, PATHINFO_EXTENSION );
                        $seperator      = $k ? "_{$k}" : '';
                        $file_name_tmp  = "{$key}_tmp{$seperator}.".strtolower( $file_ext );
                        $file_loc_tmp   = trailingslashit( $user_folder ) . $file_name_tmp;
                        $args = [
                            'file_name' => $name,
                            'new_name'  => $file_name_tmp,
                            'tmp_name'  => $value['tmp_name'][$k],
                            'size'      => $value['size'][$k],
                            'directory' => $user_folder,
                            'allowed_ext' => ['jpeg', 'jpg', 'png', 'pdf']
                        ];
                        $result = $this->upload_file( $args );
                        if( true !== $result ) {
                            return $this->error_response( 'An error occurred', 'validation_error', 400, [ $key => [ 'error' => $result, 'key' => $key ] ] );
                        }
                    }
                } else {
                    extract( $value );
                    $file_ext       = pathinfo( $name, PATHINFO_EXTENSION );
                    $file_name_tmp  = "{$key}_tmp.".strtolower( $file_ext );
                    $file_loc_tmp   = trailingslashit( $user_folder ) . $file_name_tmp;
                    $args = [
                        'file_name' => $name,
                        'new_name'  => $file_name_tmp,
                        'tmp_name'  => $tmp_name,
                        'size'      => $size,
                        'directory' => $user_folder,
                        'allowed_ext' => ['jpeg', 'jpg', 'png', 'pdf']
                    ];
                    $result = $this->upload_file( $args );
                    if( true !== $result ) {
                        return $this->error_response( 'An error occurred', 'validation_error', 400, [ $key => [ 'error' => $result, 'key' => $key ] ] );
                    }
                }
                // Remove all old
                foreach( glob("{$user_folder}/{$key}_old*") as $file ){
                    unlink( $file );
                }
                // Rename current file to old
                foreach( glob("{$user_folder}/{$key}_new*") as $file ){
                    rename( $file, str_replace( '_new', '_old', $file ) );
                }
                // Rename temp file to new
                foreach( glob("{$user_folder}/{$key}_tmp*") as $file ){
                    rename( $file, str_replace( '_tmp', '_new', $file ) );
                }
                $new_upload = true;
                continue;
            }
            if( $value !== $user->{$key} ){
                $old_data[$key] = $user->{$key};
                update_user_meta( $user->ID, $key, $value ); // Save new data
            }
        }
        // Save old data
        if( $old_data ){
            $old = is_array( $user->{VERIFICATION_OLD_DATA_MK} ) ? $user->{VERIFICATION_OLD_DATA_MK} : [];
            $update = array_merge( $old, $old_data );
            update_user_meta( $user->ID, VERIFICATION_OLD_DATA_MK, $update );
        }
        // Determine status key
        $keys = implode( '_', array_keys( $data ) );
        if( strpos( $keys, 'id_card_' ) !== false ){
            $status_key = ID_CARD_STATUS_MK;
        } else if ( strpos( $keys, 'cac_' ) !== false ) {
            $status_key = CAC_STATUS_MK;
        } else if ( strpos( $keys, 'bank_' ) !== false ) {
            $status_key = BANK_ACCOUNT_STATUS_MK;
        }
        $new_status = $user->{$status_key};
        // Change status
        if( $old_data || $new_upload ){
            $new_status = 'Pending verification';
            update_user_meta( $user->ID, $status_key, $new_status );
        }
        $field['newStatus'] = $new_status;
        return $this->success_response( 'Updated successfully', 'data_updated', 200, $field );
    }
    
	/**
	 * Update profile picture
     * 
     * @param array $user WordPress user object.
     * @param array $data {
     *  @type array $profile_photo File upload data.
     *  @type array $crop_data Optional {
     *   x: the offset left of the cropped area.
     *   y: the offset top of the cropped area.
     *   width: the width of the cropped area.
     *   height: the height of the cropped area.
     *  }
     * }
     * 
     * @return array Success or Failure.
	 */
    public function update_profile_photo( $user, $data ) {
        extract( $data );
        if( ! isset( $profile_photo ) || ! $profile_photo ){
            return $this->error_response( 'No file found.', 'update_error' );
        }
        extract( $profile_photo );
        // File location
        $file_ext       = pathinfo( $name, PATHINFO_EXTENSION );
        $temp_file_name = 'profile_photo_temp.'.strtolower( $file_ext );
        $file_name      = 'profile_photo.'.strtolower( $file_ext );
        $um_uploads_dir = trailingslashit( wp_upload_dir()['basedir'] ) . 'ultimatemember';
        $user_folder    = trailingslashit( $um_uploads_dir ) . absint( $user->ID );
        $temp_file_loc  = trailingslashit( $user_folder ) . $temp_file_name;
        $file_loc       = trailingslashit( $user_folder ) . $file_name;
        if ( ! is_dir( $user_folder ) ) {
            wp_mkdir_p( $user_folder );
        }
        $args = [
            'file_name' => $name,
            'new_name'  => $temp_file_name,
            'tmp_name'  => $tmp_name,
            'size'      => $size,
            'directory' => $user_folder,
            'allowed_ext' => ['jpeg', 'jpg', 'png', 'gif']
        ];
        $result = $this->upload_file( $args );
        if( true !== $result ) {
            return $this->error_response( $result, 'validation_error' );
        }
        if ( ! file_exists( $temp_file_loc ) ) {
            return $this->error_response( 'There is a problem uploading your profile picture.', 'update_error' );
        }
        // Editor
        $editor = wp_get_image_editor( $temp_file_loc );
        if ( is_wp_error( $editor ) ) {
            return $this->error_response( 'There is a problem uploading your profile picture.', 'update_error' );
        }
        // Exif orientation
        $editor->maybe_exif_rotate();
        // Crop
        $crop = true;
        if ( isset( $crop_data ) && $crop_data ) {
            extract( json_decode( stripslashes( $crop_data ), true ) );
            $crop = $editor->crop( $x, $y, $width, $height );
            if ( ! is_wp_error( $crop ) ) {
                $crop = false;
            }
        }
        // Resize
        $um_options = get_option( 'um_options' );
        $quality = ( isset( $um_options['image_compression'] ) && $um_options['image_compression'] ) ? $um_options['image_compression'] : 60;
        $thumb_sizes = ( isset( $um_options['photo_thumb_sizes'] ) && $um_options['photo_thumb_sizes'] ) ? $um_options['photo_thumb_sizes'] : [40,80,190];
        $dimensions = $editor->get_size();
        if ( $dimensions['width'] > 250 ) {
            $resize = $editor->resize( 250, 250, $crop );
        }
        $editor->set_quality( $quality );
        $save = $editor->save( $temp_file_loc );
        if ( is_wp_error( $save ) ) {
            unlink( $temp_file_loc );
            return $this->error_response( 'There is a problem uploading your profile picture.', 'update_error' );
        }
        // Rename from temp
        rename( $temp_file_loc, $file_loc );
        // Update profile photo meta
        update_user_meta( $user->ID, 'profile_photo', $file_name );
        // Multi resize
        $editor = wp_get_image_editor( $file_loc );
        if ( ! is_wp_error( $editor ) ) {
            // Delete all files before resize
            foreach( glob("$user_folder/profile_photo*") as $file ){
                if( $file !== $file_loc ) {
                    unlink( $file );
                }
            }
            $sizes = (array) $thumb_sizes;
            $sizes_array = [];
            foreach ( $sizes as $size ) {
                $sizes_array[] = [ 'width' => $size ];
            }
            $editor->multi_resize( $sizes_array );
        }
        return $this->success_response( get_profile_photo( $user, 120, true, true ), 'data_updated' );
    }

	/**
	 * User notifications
     * 
     * @return array
	 */
    public function get_user_notifications() {
        $user = wp_get_current_user();
        $notifications = [];
        foreach( $this->get_notification_keys( null, false ) as $key => $sub ){
            if( $user->{$key} ) {
                $notifications[ $key ] = true;
            } else {
                $notifications[ $key ] = false;
            }
        }
        return $notifications;
    }
    
	/**
	 * Products.
	 *
	 * @return array Array of products
	 */
    public function products() {
        $user_id = get_current_user_id();
        $results = [];
        $all_products = [ 'subscription' => get_subscription_products(), 'wallet' => get_wallet_products() ];
        foreach( $all_products as $item => $products ){
            $results[$item] = [];
            foreach( $products as $key => $product_id ){
                $product = wc_get_product( $product_id );
                if( ! $product ) {
                    continue;
                }
                $reg_price  = $product->get_regular_price();
                $sale_price = '';
                
                // Use product sale price if no coupon
                if ( is_product_sale_allowed( $product->get_id(), $user_id ) ) {
                    $sale_price = $product->get_sale_price();
                }
                
                // Apply coupon only if coupon sale price is not 0
                if ( $sale_price && $sale_price < $reg_price ) {
                    $savings = ($reg_price - $sale_price);
                    $actual  = $reg_price;
                    $price   = $sale_price;
                } else {
                    $savings = 0;
                    $actual  = $reg_price;
                    $price   = $reg_price;
                }
                $results[$item][] = [
                    'value'         => (string) $key,
                    'savings'       => (float) $savings,
                    'actual_price'  => (float) $actual,
                    'price'         => (float) $price,
                    'product'       => $product->get_name(),
                    'default'       => (bool) $product->get_meta('default_product')
                ];
            }
        }
        return [
            'success' => true,
            'products' => $results
        ];
    }
    
	/**
	 * Get options
     * 
     * @param string $data Key of data to return
     * 
     * @return array
	 */
    public function get_options( $data ) {
        if( in_array( $data, [ 'services', 'states' ] ) ){
            return $this->get_tax( $data );
        }
        if( $data === 'categorized-services' ){
            return $this->categorized_services();
        }
        if( $data === 'notification-keys' ){
            return $this->get_notification_keys();
        }
        if( $data === 'account-fields' ){
            return $this->get_account_fields();
        }
        return []; 
    }
    
	/**
	 * Get transactions
     * 
     * @param string $data Key of data to return
     * 
     * @return array
	 */
    public function get_transactions( $data ) {
        if( $data['data'] === 'orders' ){
            return $this->get_orders( $data );
        }
        if( $data['data'] === 'subscriptions' ){
            return $this->get_subscriptions( $data );
        }
        if( $data['data'] === 'wallet' ){
            return $this->get_wallet_transactions( $data );
        }
        return []; 
    }
    
	/**
	 * Get orders
     * 
     * @param array $data {
     *  @type int $limit Total number to retrieve. Default 10
     *  @type int $offset Offset. Default 0
     *  @type int $page Page number to retrieve. Default 1
     *  @type string $orderby ID or date. Default date
     *  @type string $order DESC or ASC. Default DESC
     * }
     * 
     * @return array Array of orders
	 */
    public function get_orders( $data ) {
        
		extract( $data );
        $user = wp_get_current_user();
		
        $args = [];
	    
        if ( isset( $per_page ) && $per_page ) {
            $args['limit'] = $per_page;
        }
	    
        if ( isset( $offset ) && $offset ) {
            $args['offset'] = $offset;
        }
	    
        if ( isset( $page ) && $page ) {
            unset( $args['offset'] );
            $args['paged'] = $page;
        }
	    
        if ( isset( $orderby ) && $orderby ) {
            $args['orderby'] = $orderby;
        }
	    
        if ( isset( $order ) && $order ) {
            $args['order'] = $order;
        }
		
        $args['paginate']    = true;
        $args['customer_id'] = $user->ID;

        // Payment data
        $account_details = get_option( 'woocommerce_bacs_accounts');
        $settings        = get_option( 'woocommerce_v_paystack_settings' );
        $test_public_key = $settings['test_public_key'];
        $live_public_key = $settings['live_public_key'];
        $public_key      = ( $settings['testmode'] === 'yes' ) ? $test_public_key : $live_public_key;
        $currency        = 'NGN';

        $orders  = wc_get_orders( $args );
        $results = [];

        foreach ( $orders->orders as $order ) {

            $payment_data = [];

            $items = [];
            foreach ( $order->get_items() as $item ) {
                $items[] = [ 
                    'name'  => $product_name = $item->get_name(),
                    'total' => get_price_format( $item->get_total() )
                ];
            }
            
            if( $order->get_status() === 'pending' || $order->get_status() === 'on-hold' ){
                if( $order->get_coupon_codes() ){
                    // Cancel order with coupon to prevent customers from paying lower price on account page.
                    $order->update_status( 'cancelled', __( 'Order cancelled. Orders with coupon cannot be paid for on account page.' ) );
                }
                if( $order->get_status() === 'pending' ){
                    $payment_data = [
                        'meta_order_id' => $order->get_id(),
                        'amount'        => $order->get_total() * 100,
                        'txnref'        => $order->get_meta('_paystack_txn_ref'),
                        'product'       => $product_name . ' (Qty: 1)',
                        'currency'      => $currency,
                        'public_key'    => $public_key
                    ];
                } else if ( $order->get_status() === 'on-hold' ){
                    $payment_data = [
                        'meta_order_id' => $order->get_id(),
                        'amount'        => $order->get_total(),
                        'bank_name'     => $account_details[0]['bank_name'],
                        'account_name'  => $account_details[0]['account_name'],
                        'account_number' => $account_details[0]['account_number']
                    ];
                }
            }

            $results[] = [
                'order_id'       => $order->get_id(),
                'status'         => [ 
                    'key'  => $order->get_status(),
                    'name' => wc_get_order_status_name( $order->get_status() )
                ],
                'date_created'   => $order->get_date_created()->setTimezone(new DateTimeZone('UTC'))->format('c'),
                'payment_method' => $order->get_payment_method_title(),
                'items'          => $items,
                'total'          => get_price_format( $order->get_total() ),
                'payment_data'   => $payment_data
            ];
            
        }
        return [
            'data'          => $results,
            'total_records' => $orders->total,
            'total_pages'   => $orders->max_num_pages
        ];
    }
    
	/**
	 * Get subscriptions
     * 
     * @param array $data {
     *  @type int $subscriptions_per_page Total number to retrieve. Default 10
     *  @type int $offset Offset. Default 0
     *  @type int $page Page number to retrieve. Default 1
     *  @type string $orderby ID or date. Default date
     *  @type string $order DESC or ASC. Default DESC
     * }
     * 
     * @return array Array of subscriptions
	 */
    public function get_subscriptions( $data ) {
        
		extract( $data );
        $user = wp_get_current_user();
		
        $args = [];
	    
        if ( isset( $per_page ) && $per_page ) {
            $args['subscriptions_per_page'] = $per_page;
        } else {
            $per_page = 10;
            $args['subscriptions_per_page'] = $per_page;
        }
	    
        if ( isset( $offset ) && $offset ) {
            $args['offset'] = $offset;
        }
	    
        if ( isset( $page ) && $page ) {
            $args['offset'] = ( absint( $page ) - 1 ) * $args['subscriptions_per_page'];
        }
	    
        if ( isset( $orderby ) && $orderby ) {
            if ( $orderby === 'date' ) {
                $args['orderby'] = 'start_date';
            } else {
                $args['orderby'] = $orderby;
            }
        }
	    
        if ( isset( $order ) && $order ) {
            $args['order'] = $order;
        }
		
        $args['customer_id'] = $user->ID;
        
        $subscriptions  = wcs_get_subscriptions( $args );

        $args['subscriptions_per_page'] = -1;
	    $args['offset'] = 0;
        $total_records = count( wcs_get_subscriptions( $args ) );
        $total_pages   = ceil( $total_records / $per_page );

        $results = [];

        foreach ( $subscriptions as $subscription ) {
            $total    = get_price_format( $subscription->get_total() );
            $interval = $subscription->get_billing_interval();
            $period   = $subscription->get_billing_period();
            if ( $interval == 1 ) {
                $total = "$total / $period";
            } else {
                $total = "$total every $interval {$period}s";
            }

            $orders = [];
            foreach ( $subscription->get_related_orders('all') as $order ) {
                $orders[] = [ 
                    'order_id'      => $order->get_id(),
                    'status'        => [ 
                        'key'       => $order->get_status(),
                        'name'      => wc_get_order_status_name( $order->get_status() )
                    ],
                    'date_created'  => $order->get_date_created()->setTimezone(new DateTimeZone('UTC'))->format('c'),
                    'total'         => get_price_format( $order->get_total() ),
                ];
             }

            $results[] = [
                'subscription_id'   => $subscription->get_id(),
                'status'            => [ 
                    'key'           => $subscription->get_status(),
                    'name'          => wcs_get_subscription_status_name( $subscription->get_status() ) 
                ],
                'total'             => $total,
                'start_date'        => $this->format_date( $subscription->get_date( 'start' ) ),
                'last_payment'      => $this->format_date( $subscription->get_date( 'last_order_date_paid' ) ),
                'next_payment'      => $this->format_date( $subscription->get_date( 'next_payment' ) ),
                'end_date'          => $this->format_date( $subscription->get_date( 'end' ) ),
                'payment_method'    => $subscription->is_manual() ? 'Via Manual Renewal' : $subscription->get_payment_method_title(),
                'orders'            => $orders
            ];
        }
        return [
            'data'          => $results,
            'total_records' => $total_records,
            'total_pages'   => $total_pages,
        ];
    }
    
	/**
	 * Get wallet transactions
     * 
     * @param array $data {
     *  @type int $limit Total number to retrieve. Default 10
     *  @type int $offset Offset. Default 0
     *  @type int $page Page number to retrieve. Default 1
     *  @type string $orderby ID or date. Default date
     *  @type string $order DESC or ASC. Default DESC
     * }
     * 
     * @return array Array of wallet transactions
	 */
    public function get_wallet_transactions( $data ) {
        
		extract( $data );
        $user = wp_get_current_user();
	    
        if ( $page ) {
            $offset = ( $page - 1 ) * $per_page;
        }
		
        $args['limit'] = "$offset, $per_page";
	    
        if ( $orderby === 'date' ) {
            $args['order_by'] = 'transaction_id';
        } else {
            $args['order_by'] = $orderby;
        }
	    
        if ( $order ) {
            $args['order'] = $order;
        }
		
        $args['user_id'] = $user->ID;

        $transactions   = get_wallet_transactions( $args );
        $total_records  = get_wallet_transactions_count( $args['user_id'] );
        $total_pages    = ceil( $total_records / $per_page );

        $results = [];

        foreach ( $transactions as $transaction ) {
            $results[] = [
                'ID'      => $transaction->transaction_id,
                'credit'  => $transaction->type === 'credit' ? get_price_format( $transaction->amount ) : '',
                'debit'   => $transaction->type === 'debit' ? get_price_format( $transaction->amount ) : '',
                'details' => $transaction->details,
                'date'    => $this->format_date( $transaction->date, 'c', 'Europe/Helsinki' )
            ];
        }
        return [
            'data'          => $results,
            'total_records' => $total_records,
            'total_pages'   => $total_pages,
        ];
    }

    /**
     * Get notification keys
     * 
     * @param string $role User role key. Default All
     * @param bool $segment Whether to seperate email & push keys. Default true.
     * 
     * @return array Array of notification keys
     */
    public function get_notification_keys( $role = null, $segment = true ){
        $keys = [
            'email' => [
                'email_not_new_request'   => [
                    'label'     => $new_request_label = 'New customer requests',
                    'desc'      => $new_request_desc = '',
                    'role'      => ['pro'],
                    'segment'   => 'request'
                ],
                'email_not_request_reminder' => [
                    'label'     => $request_reminder_label = 'Request reminders',
                    'desc'      => $request_reminder_desc = '',
                    'role'      => ['pro'],
                    'segment'   => 'request'
                ],
                'email_not_account_activity' => [
                    'label'     => $account_activity_label = 'Account activity',
                    'desc'      => $account_activity_desc = 'Stay up to with your account activities' . ($role === 'customer') ? '' : ' e.g. new hires',
                    'role'      => ['pro', 'customer'],
                    'segment'   => 'account'
                ],
                'email_not_newsletter'    => [
                    'label'     => $newsletter_label = 'Newsletters',
                    'desc'      => $newsletter_desc = 'Stay up to date with the lastest events on VisCorner',
                    'role'      => ['pro', 'customer'],
                    'segment'   => 'promotion'
                ],
                'email_not_success_tip'   => [
                    'label'     => $success_tip_label = 'Success tips',
                    'desc'      => $success_tip_desc = 'Learn how to grow your business on VisCorner',
                    'role'      => ['pro'],
                    'segment'   => 'promotion'
                ],
                'email_not_discount_offer' => [
                    'label'     => $discount_offer_label = 'Discount offers',
                    'desc'      => $discount_offer_desc = 'Get notified about discounts and offers',
                    'role'      => ['pro'],
                    'segment'   => 'promotion'
                ],
                'email_not_help_viscorner' => [
                    'label'     => $help_viscorner_label = 'Help VisCorner',
                    'desc'      => $help_viscorner_desc = 'Take quick surveys to help make VisCorner better for you',
                    'role'      => ['pro', 'customer'],
                    'segment'   => 'promotion'
                ],
                'email_not_market_report' => [
                    'label'     => $market_report_label = 'Market reports & insights',
                    'desc'      => $market_report_desc = 'See how you compare to other pros',
                    'role'      => ['pro'],
                    'segment'   => 'promotion'
                ]
            ],
            'push' => [
                'push_not_new_request'   => [
                    'label'     => $new_request_label,
                    'desc'      => $new_request_desc,
                    'role'      => ['pro'],
                    'segment'   => 'request'
                ],
                'push_not_request_reminder' => [
                    'label'     => $request_reminder_label,
                    'desc'      => $request_reminder_desc,
                    'role'      => ['pro'],
                    'segment'   => 'request'
                ],
                'push_not_account_activity' => [
                    'label'     => $account_activity_label,
                    'desc'      => $account_activity_desc,
                    'role'      => ['pro', 'customer'],
                    'segment'   => 'account'
                ],
                'push_not_newsletter'    => [
                    'label'     => $newsletter_label,
                    'desc'      => $newsletter_desc,
                    'role'      => ['pro', 'customer'],
                    'segment'   => 'promotion'
                ],
                'push_not_success_tip'   => [
                    'label'     => $success_tip_label,
                    'desc'      => $success_tip_desc,
                    'role'      => ['pro'],
                    'segment'   => 'promotion'
                ],
                'push_not_discount_offer' => [
                    'label'     => $discount_offer_label,
                    'desc'      => $discount_offer_desc,
                    'role'      => ['pro'],
                    'segment'   => 'promotion'
                ],
                'push_not_help_viscorner' => [
                    'label'     => $help_viscorner_label,
                    'desc'      => $help_viscorner_desc,
                    'role'      => ['pro', 'customer'],
                    'segment'   => 'promotion'
                ],
                'push_not_market_report' => [
                    'label'     => $market_report_label,
                    'desc'      => $market_report_desc,
                    'role'      => ['pro'],
                    'segment'   => 'promotion'
                ]
            ]
        ];        
        if ( $role ) {
            foreach( $keys as $category => $items ){
                $keys[$category] = [];
                foreach( $items as $key => $item ){
                    if( in_array( $role, $item['role'] ) ){
                        $keys[$category][$key] =  $item;
                    }
                }
            }
        }
        if ( $segment === false ) {
            $keys = array_merge( $keys['email'], $keys['push'] );
        }
        return $keys;
    }

	/**
	 * Contact customer support
	 */
    public function contact_us( $data ) {
        extract( $data );
        $user       = wp_get_current_user();
		$subject    = trim( stripslashes( $subject ) );
		$message    = trim( stripslashes( $message ) );
        $errors     = [];
        $files      = [];
        if( ! $message ) {
            $errors[] = [
                'error' => 'Message is required.',
                'key'   => 'message'
            ];
        }
        if( isset( $attachment ) && isset( $attachment['name'] ) && $attachment['name'] ){
            if( is_array( $attachment['name'] ) ){
                foreach( $attachment['name'] as $k => $name ){
                    $args = [
                        'file_name' => $name,
                        'tmp_name'  => $attachment['tmp_name'][$k],
                        'size'      => $attachment['size'][$k]
                    ];
                    $result = $this->upload_file( $args );
                    if( true !== $result ) {
                        $errors[] = [
                            'error' => $result,
                            'key'   => 'attachment'
                        ];
                    }
                    $files[] = VISCORNER_COMMON_DIR . "uploads/$name";
                }
            } else {
                extract( $attachment );
                $args = [
                    'file_name' => $name,
                    'tmp_name'  => $tmp_name,
                    'size'      => $size
                ];
                $result = $this->upload_file( $args );
                if( true !== $result ) {
                    $errors[] = [
                        'error' => $result,
                        'key'   => 'attachment'
                    ];
                }
                $files[] = VISCORNER_COMMON_DIR . "uploads/$name";
            }
        }
        if( $errors ){
            return $this->error_response( 'An error occurred', 'validation_error', 400, $errors );
        }
        $mailer = new V_Mailer();
        $data = [
            'sender'     => ['name' => "{$user->display_name} via Account Page", 'email' => 'support@viscorner.com'],
            'reply_to'   => ['name' => $user->display_name, 'email' => $user->user_email],
            'email'      => get_bloginfo('admin_email'),
            'subject'    => $subject,
            'content'    => $message,
            'attachment' => $files
        ];
        if( ! $mailer->send( $data ) ) {
            return $this->error_response( 'Sorry, there was an error sending your message.', 'email_error' );
        }
        // Delete the files
        foreach( $files as $file ){
            unlink( $file );
        }
        return $this->success_response( 'Email sent. We will get back to you shortly!', 'email_sent' );
    }
    
	/**
	 * Upload file.
	 */
    public function upload_file( $args ) {
        $defaults = [
            'file_name' => '',
            'new_name'  => '',
            'tmp_name'  => '',
            'size'      => '',
            'allowed_ext' => ['jpeg', 'jpg', 'png', 'pdf', 'gif'],
            'max_size'  => 10000000,
            'directory' => VISCORNER_COMMON_DIR . 'uploads'
        ];
        $args = wp_parse_args( $args, $defaults );
        extract( $args );
        $file_name = sanitize_text_field( $file_name );
        $new_name = sanitize_text_field( $new_name );
        $location = $new_name ? "$directory/$new_name" : "$directory/$file_name";
        $file_ext = pathinfo( $file_name, PATHINFO_EXTENSION );
        $errors = [];
        if ( $size > $max_size ) {
            return "File too large. Max size is " . formatBytes( $max_size );
        }
        if( ! in_array( $file_ext, $allowed_ext ) ){
            return "Only " . implode( ', ', $allowed_ext ) . " files are allowed";
        }
        if( ! $errors ) {
            if ( ! is_dir( $directory ) ) {
                mkdir( $directory );
            }
            if ( ! move_uploaded_file( $tmp_name, $location ) ) {
                return "There was an error uploading your file";
            }
        }
        return true;
	}
    
	/**
	 * Get service terms.
     * 
     * @param string $taxonomy Taxonomy (services|states) to return. 
     * 
     * @return array Array of taxonomies.
	 */
    public function get_tax( $taxonomy ) {
        $transient_name = "vc_taxonomy_cache_{$taxonomy}";
	    $data = get_transient( $transient_name );
	    
	    if ( $data ) {
	        return $data;
	    }
        
        $data = [];

        $args = [
		    'taxonomy'   =>  $taxonomy,
		    'hide_empty' =>  false,
		    'childless'  =>  true
        ];

        if( $taxonomy === 'states' ){
		    $args['taxonomy']   = 'category';
            $args['parent']     = 0;
            $args['exclude']    = [37];
            unset( $args['childless'] );
        }

	    $terms = get_terms( $args );
	    
	    if ( is_wp_error( $terms ) || empty( $terms ) ){
	        return [];
        }
        
        foreach ( $terms as $term ) {
            $data[] = [
                'name' => wp_specialchars_decode( $term->name ),
                'id'   => $term->term_id,
            ];
        }
        
        // Save the tags and ids in a transient.
        set_transient( $transient_name, $data );
        return $data;
    }
    
	/**
	 * Get categorized services. 
     * 
     * @return array Array of categorized services.
	 */
    public function categorized_services() {
        $service_tax = 'services';
        $transient_name = 'vc_categorized_services_cache';
	    $data = get_transient( $transient_name );
	    
	    if ( $data ) {
	        return $data;
	    }

	    $cats = get_terms([
		    'taxonomy'   =>  $service_tax,
		    'hide_empty' =>  false,
		    'parent'     =>  0, // Topmost term
	    ]);
	
	    $data = [];
	    
	    foreach( $cats as $cat ) {
        
	        $terms = get_terms([
		        'taxonomy'   =>  $service_tax,
		        'hide_empty' =>  false,
		        'parent'     =>  $cat->term_id
	        ]);
	    
	        $items = [];
	        
            foreach ( $terms as $term ) {
                
                $items[] = [
                    'name'  => wp_specialchars_decode($term->name),
                    'id'    => $term->term_id,
                ];

            }
	    
            if ( $cat->slug === 'more-services' ) {
                $last_name = $cat->name;
                $more = $items;
                continue;
            }
            
            $data[] = [ 
                'cat' => $cat->name,
                'items' => $items
            ];
		
        }
        
        // Add "Other Services" to the last
        $data[] = [ 
            'cat' => 'Other Services',
            'items' => $more
        ];
        
        // Save the tags and ids in a transient.
        set_transient( $transient_name, $data );
        
        return $data;
            
    }
    
	/**
	 * Get state as key value pair
     * 
     * @return array Array of states
	 */
    public function get_state_key_value() {
	    if ( isset( $this->state_key_value ) ) {
	        return $this->state_key_value;
	    }
        return $this->state_key_value = array_column( $this->get_tax( 'states' ), 'name', 'name' );
    }
    
	/**
	 * Get account fields
     * 
     * @param string $field Field to return. Optional.
     * 
     * @return array Array of all fields or array of a single field
	 */
    public function get_account_fields( $field = '' ) {
        $fields = [
            'first_name'        => [
                'key'           => FIRST_NAME_MK,
                'label'         => 'First name',
                'desc'          => '',
                'placeholder'   => '',
                'default'       => '',
                'type'          => 'text',
                'required'      => true
            ],
            'last_name'         => [
                'key'           => LAST_NAME_MK,
                'label'         => 'Last name',
                'desc'          => '',
                'placeholder'   => '',
                'default'       => '',
                'type'          => 'text',
                'required'      => true
            ],
            'email'             => [
                'key'           => USER_EMAIL_MK,
                'label'         => 'Email address',
                'desc'          => 'If you change this, you will be required to confirm the new email address.',
                'placeholder'   => '',
                'default'       => '',
                'type'          => 'email',
                'required'      => true
            ],
            'username'      => [
                'key'           => USER_LOGIN_MK,
                'label'         => 'Username',
                'desc'          => '',
                'placeholder'   => '',
                'default'       => '',
                'type'          => 'text',
                'required'      => true
            ],
            'display_name'      => [
                'key'           => DISPLAY_NAME_MK,
                'label'         => 'Display name',
                'desc'          => '',
                'placeholder'   => '',
                'default'       => '',
                'type'          => 'text',
                'required'      => true
            ],
            'phone_number'      => [
                'key'           => PHONE_NUMBER_MK,
                'label'         => 'Phone number',
                'desc'          => '',
                'placeholder'   => '',
                'default'       => '',
                'type'          => 'number',
                'required'      => true,
                'min'           => '',
                'max'           => ''
            ],
            'whatsapp_number'   => [
                'key'           => WHATSAPP_NUMBER_MK,
                'label'         => 'WhatsApp number',
                'desc'          => '',
                'placeholder'   => '',
                'default'       => '',
                'type'          => 'number',
                'required'      => false,
                'min'           => '',
                'max'           => ''
            ],
            'description'      => [
                'key'           => BUSINESS_INFO_MK,
                'label'         => 'Business description',
                'desc'          => 'Tell customers what you do in detail',
                'placeholder'   => '',
                'default'       => '',
                'type'          => 'textarea',
                'required'      => true
            ],
            'business_type'     => [
                'key'           => PROVIDER_TYPE_MK,
                'label'         => 'Business type',
                'desc'          => '',
                'placeholder'   => '',
                'default'       => '',
                'type'          => 'select',
                'required'      => true,
                'options'       => [
                    'Registered company' => 'Registered company',
                    'Private person'     => 'Private person'
                ]
            ],
            'year_founded'      => [
                'key'           => FOUNDING_YEAR_MK,
                'label'         => 'Year founded',
                'desc'          => '',
                'placeholder'   => '',
                'default'       => '',
                'type'          => 'number',
                'required'      => true,
                'min'           => 1900,
                'max'           => ''
            ],
            'no_of_employees'   => [
                'key'           => NO_OF_EMPLOYEES_MK,
                'label'         => 'Number of employees',
                'desc'          => '',
                'placeholder'   => '',
                'default'       => '',
                'type'          => 'number',
                'required'      => false,
                'min'           => 1,
                'max'           => ''
            ],
            'website'           => [
                'key'           => USER_WEBSITE_URL_MK,
                'label'         => 'Website URL',
                'desc'          => '',
                'placeholder'   => '',
                'default'       => '',
                'type'          => 'url',
                'required'      => false
            ],
            'youtube'           => [
                'key'           => YOUTUBE_VIDEO_URL_MK,
                'label'         => 'Youtube URL',
                'desc'          => '',
                'placeholder'   => '',
                'default'       => '',
                'type'          => 'url',
                'required'       => false
            ],
            'services'          => [
                'key'           => PROS_SERVICES_MK,
                'label'         => 'Services',
                'desc'          => 'Make sure you select all related services (e.g. as a makeup artist, select bridal makeup, wedding makeup, photoshoot makeup etc.)',
                'placeholder'   => '',
                'default'       => [],
                'type'          => 'select',
                'required'      => true,
                'options'       => []
            ],
            'travel_preference' => [
                'key'           => 'travel_pref',
                'label'         => 'Travel preference',
                'desc'          => 'How many kilometers can you travel?',
                'placeholder'   => '',
                'default'       => '',
                'type'          => 'select',
                'required'      => true,
                'options'       => [
                    10   =>  '10 km',
                    20   =>  '20 km',
                    40   =>  '40 km',
                    60   =>  '60 km',
                    80   =>  '80 km',
                    100  =>  '100 km',
                ]
            ],
            'address'           => [
                'key'           => BUSINESS_ADDRESS_MK,
                'label'         => 'Address',
                'desc'          => '',
                'placeholder'   => '',
                'default'       => '',
                'type'          => 'text',
                'required'      => true
            ],
            'city'              => [
                'key'           => BUSINESS_AREA_MK,
                'label'         => 'City',
                'desc'          => '',
                'placeholder'   => '',
                'default'       => '',
                'type'          => 'text',
                'required'      => true
            ],
            'state'             => [
                'key'           => BUSINESS_STATE_MK,
                'label'         => 'State',
                'desc'          => '',
                'placeholder'   => '',
                'default'       => '',
                'type'          => 'select',
                'required'      => true,
                'options'       => $this->get_state_key_value()
            ],
            'address2'           => [
                'key'           => ADDRESS2_MK,
                'label'         => 'Address 2',
                'desc'          => '',
                'placeholder'   => '',
                'default'       => '',
                'type'          => 'text',
                'required'      => false
            ],
            'city2'             => [
                'key'           => CITY2_MK,
                'label'         => 'City 2',
                'desc'          => '',
                'placeholder'   => '',
                'default'       => '',
                'type'          => 'text',
                'required'      => false
            ],
            'state2'            => [
                'key'           => STATE2_MK,
                'label'         => 'State 2',
                'desc'          => '',
                'placeholder'   => '',
                'default'       => '',
                'type'          => 'select',
                'required'      => false,
                'options'       => $this->get_state_key_value()
            ],
            'address3'          => [
                'key'           => ADDRESS3_MK,
                'label'         => 'Address 3',
                'desc'          => '',
                'placeholder'   => '',
                'default'       => '',
                'type'          => 'text',
                'required'      => false
            ],
            'city3'             => [
                'key'           => CITY3_MK,
                'label'         => 'City 3',
                'desc'          => '',
                'placeholder'   => '',
                'default'       => '',
                'type'          => 'text',
                'required'      => false
            ],
            'state3'            => [
                'key'           => STATE3_MK,
                'label'         => 'State 3',
                'desc'          => '',
                'placeholder'   => '',
                'default'       => '',
                'type'          => 'select',
                'required'      => false,
                'options'       => $this->get_state_key_value()
            ],
            'prices'            => [
                'key'           => AVERAGE_PRICES_MK,
                'label'         => 'Prices',
                'desc'          => 'Explain your prices (e.g. We charge ₦50,000 on average for a 5 page static website.)',
                'placeholder'   => '',
                'default'       => '',
                'type'          => 'textarea',
                'required'      => false
            ],
            'qualifications'    => [
                'key'           => QUALIFICATIONS_MK,
                'label'         => 'Qualifications',
                'desc'          => 'Let customers know how qualified you are',
                'placeholder'   => '',
                'default'       => '',
                'type'          => 'textarea',
                'required'      => false
            ],
            'quote_templates'   => [
                'key'           => QUOTE_TEMPLATES_MK,
                'label'         => 'Quote templates',
                'desc'          => '',
                'placeholder'   => '',
                'default'       => '',
                'type'          => 'array',
                'required'      => false
            ],
            'profile_photo'     => [
                'key'           => PROFILE_PHOTO_MK,
                'label'         => 'Upload your photo',
                'desc'          => '(max: 10MB)',
                'placeholder'   => '',
                'default'       => '',
                'type'          => 'array',
                'required'      => false
            ],
            'new_password'      => [
                'key'           => USER_PASS_MK,
                'label'         => 'New password',
                'desc'          => '',
                'placeholder'   => '',
                'default'       => '',
                'type'          => 'password',
                'required'      => true
            ],
            'current_password'  => [
                'key'           => 'current_password',
                'label'         => 'Current password',
                'desc'          => '',
                'placeholder'   => '',
                'default'       => '',
                'type'          => 'password',
                'required'      => true
            ],
            'confirm_password'  => [
                'key'           => 'confirm_password',
                'label'         => 'Confirm new password',
                'desc'          => '',
                'placeholder'   => '',
                'default'       => '',
                'type'          => 'password',
                'required'      => true
            ],
            'delete_acc_pass'   => [
                'key'           => 'delete_acc_pass',
                'label'         => 'Enter your password',
                'desc'          => 'All your information on VisCorner will be deleted. This action cannot be reversed.',
                'placeholder'   => '',
                'default'       => '',
                'type'          => 'password',
                'required'      => true
            ]
        ];
        $fields = array_merge( $fields, $this->verfification_data_keys() );
        if( $field ) {
            if ( isset( $fields[ $field ] ) ) {
                return $fields[ $field ];
            }
            return [];
        }
        return $fields;
    }
    
	/**
	 * Verfification data keys
     * 
     * @return array Array of all verfification data fields
	 */
    public function verfification_data_keys() {
        $required = is_user_logged_in() || false;
        $fields = [
            'bank_name'         => [
                'key'           => BANK_NAME_MK,
                'label'         => 'Bank name',
                'desc'          => '',
                'placeholder'   => '',
                'default'       => '',
                'type'          => 'text',
                'required'      => $required
            ],
            'bank_account_name' => [
                'key'           => BANK_ACCOUNT_NAME_MK,
                'label'         => 'Bank account name',
                'desc'          => 'This name must match the name on your ID or the name on your business registration certificate.',
                'placeholder'   => '',
                'default'       => '',
                'type'          => 'text',
                'required'      => $required
            ],
            'bank_account_number' => [
                'key'           => BANK_ACCOUNT_NUMBER_MK,
                'label'         => 'Bank account number',
                'desc'          => '',
                'placeholder'   => '',
                'default'       => '',
                'type'          => 'number',
                'required'      => $required,
                'min'           => '',
                'max'           => ''
            ],
            'id_card_type'      => [
                'key'           => ID_CARD_TYPE_MK,
                'label'         => 'ID type',
                'desc'          => '',
                'placeholder'   => '',
                'default'       => '',
                'type'          => 'select',
                'required'      => $required,
                'options'       => [
                    'National identity card' => 'National identity card',
                    'Drivers licence'        => 'Drivers licence',
                    'Voters card'            => 'Voters card',
                    'International passport' => 'International passport'
                ]
            ],
            'id_card_name' => [
                'key'           => ID_CARD_NAME_MK,
                'label'         => 'Full name',
                'desc'          => '',
                'placeholder'   => '',
                'default'       => '',
                'type'          => 'text',
                'required'      => $required
            ],
            'id_card_expiry_date' => [
                'key'           => ID_CARD_EXPIRY_DATE_MK,
                'label'         => 'Expiry date',
                'desc'          => '',
                'placeholder'   => '',
                'default'       => '',
                'type'          => 'date',
                'required'      => $required
            ],
            'id_card_front_page' => [
                'key'           => 'id_card_front_page',
                'label'         => 'ID front page',
                'desc'          => 'Upload the front page of your ID',
                'placeholder'   => '',
                'default'       => '',
                'type'          => 'array',
                'required'      => $required
            ],
            'id_card_back_page' => [
                'key'           => 'id_card_back_page',
                'label'         => 'ID back page',
                'desc'          => 'Upload the back page of your ID',
                'placeholder'   => '',
                'default'       => '',
                'type'          => 'array',
                'required'      => $required
            ],
            'cac_registration_type' => [
                'key'           => CAC_REGISTRATION_TYPE_MK,
                'label'         => 'Business registration type',
                'desc'          => 'Business name registration number usually begins with BN while company registration number begins with RC.',
                'placeholder'   => '',
                'default'       => '',
                'type'          => 'select',
                'required'      => $required,
                'options'       => [
                    'Business name registration' => 'Business name registration',
                    'Incorporated company'       => 'Incorporated company'
                ]
            ],
            'cac_business_name' => [
                'key'           => CAC_BUSINESS_NAME_MK,
                'label'         => 'Legal business name',
                'desc'          => 'This is the name on your registration certificate.',
                'placeholder'   => '',
                'default'       => '',
                'type'          => 'text',
                'required'      => $required
            ],
            'cac_registration_no' => [
                'key'           => CAC_REGISTRATION_NO_MK,
                'label'         => 'Registration number',
                'desc'          => '',
                'placeholder'   => '',
                'default'       => '',
                'type'          => 'text',
                'required'      => $required
            ],
            'cac_registration_doc' => [
                'key'           => 'cac_registration_doc',
                'label'         => 'Registration certificate',
                'desc'          => 'Certificate showing that the business has been registered with the CAC.',
                'placeholder'   => '',
                'default'       => '',
                'type'          => 'array',
                'required'      => $required
            ],
        ];
        return $fields;
    }

    /**
    * Validate date and date format
    * 
    * @param string $date Date string.
    * @param string $format Date format. Default Y-m-d H:i:s
    * 
    * @return bool
    */
    public function validateDate( $date, $format = 'Y-m-d H:i:s' ) {
        $d = DateTime::createFromFormat($format, $date);
        return $d && $d->format($format) === $date;
    }

	/**
	 * Format date
     * 
     * @param string $date Date string.
     * @param string $new_format New date format. Default c
     * @param string $timezone Timezone to format to. Default UTC
     * @param string $current_format Current date format. Default Y-m-d H:i:s
     * 
     * @return string|false DateTime string on success or false on failure
	 */
    public function format_date( $date, $new_format = 'c', $timezone = 'UTC', $current_format = 'Y-m-d H:i:s' ) {
        if( $this->validateDate( $date, $current_format ) ){
            return date_format( date_create( $date, timezone_open( $timezone ) ), $new_format );
        }
        return false;
    }
    
	/**
	 * Reset password for unathorized users.
     * 
     * @param string $user_login User email or username.
     * 
     * @return array Success or failure
	 */
    public function password_reset_request( $user_login ) {
        if ( isset( $user_login ) && sanitize_text_field( $user_login ) ) {
            $user_login = trim( sanitize_text_field( $user_login ) );
        } else {
            $user_login = "";
        }
        if ( empty( $user_login ) ) {
            return $this->error_response( 'Please provide your email or username.', 'validation_error', 400 );
        }
        $user = '';
        if ( is_email( $user_login ) && email_exists( $user_login ) ) {
            $user = get_user_by( 'email', $user_login );
        } elseif ( ! is_email( $user_login ) && username_exists( $user_login ) ) {
            $user = get_user_by( 'login', $user_login );
        }
        if ( empty( $user ) || ! is_a( $user, '\WP_User' ) ) {
            return $this->error_response( 'Invalid login details.', 'validation_error', 400 );
        }
        $user_id    = $user->ID;
        $is_admin   = user_can( $user_id, 'manage_options' );
        $um_options = get_option( 'um_options' );
        if ( $um_options['enable_reset_password_limit'] ) { // if reset password limit is set
            if ( ! ( $um_options['disable_admin_reset_password_limit'] && $is_admin ) ) {
                // Does not trigger this when a user has admin capabilities and when reset password limit is disabled for admins
                $attempts = (int) get_user_meta( $user_id, PASSWORD_RESET_ATTEMPTS, true );
                $limit    = (int) $um_options['reset_password_limit_number'];
                if ( $attempts >= $limit ) {
                    return $this->error_response( 'You have reached the limit for requesting password change. Please contact us if you are having issues accessing your account.', 'validation_error', 400 );
                } else {
                    update_user_meta( $user_id, PASSWORD_RESET_ATTEMPTS, $attempts + 1 );
                }
            }
        }
        // Email content
        $security_code = generate_random_number(8);
        $variables = [
            'first_name'    => $user->first_name,
            'security_code' => $security_code,
        ];
        $subject = 'VisCorner account password reset';
        $content = add_email_template( get_template_content( 'password-reset.php', $variables ), $subject );
        $mailer  = new V_Mailer();
        $data = [
            'email'   => $user->user_email,
            'subject' => $subject,
            'content' => $content
        ];
        if( ! $mailer->send( $data ) ){
            return $this->error_response( 'An error occurred.', 'unknown_error' );
        }
        update_user_meta( $user_id, PASSWORD_RESET_CODE, $security_code );
        return $this->success_response( 'A password reset code has been sent to your email address.', 'success' );
    }
    
	/**
	 * Change password for unathorized users.
     * 
     * @param array $args {
     *  @type string $security_code Security code.
     *  @type string $new_password New password.
     *  @type string $confirm_password Confirmation password.
     *  @type string $user_login User email or username.
     * }
     * 
     * @return array Success or failure
	 */
    public function change_password( $args ) {
        extract( $args );
        if ( ! isset( $security_code ) || ! sanitize_text_field( $security_code ) ) {
            return $this->error_response( 'Security code is required.', 'validation_error', 400 );
        }
        if ( ! isset( $new_password ) || ! sanitize_text_field( $new_password ) ) {
            return $this->error_response( 'You must enter a new password.', 'validation_error', 400 );
        }
        if ( ! isset( $confirm_password ) || ! sanitize_text_field( $confirm_password ) ) {
            return $this->error_response( 'You must confirm your new password.', 'validation_error', 400 );
        }
        if ( ! isset( $user_login ) || ! sanitize_text_field( $user_login ) ) {
            return $this->error_response( 'Please provide your email or username.', 'validation_error', 400 );
        }
        $new_password     = trim( sanitize_text_field( $new_password ) );
        $confirm_password = trim( sanitize_text_field( $confirm_password ) );
        if ( false === $this->is_strong_password( $new_password ) ) {
            return $this->error_response( 'Your password must be at least 8 characters in length and it must contain at least one lower case letter, one capital letter and one number.', 'validation_error', 400 );
        }
        if ( $new_password !== $confirm_password ) {
            return $this->error_response( 'Your passwords do not match.', 'validation_error', 400 );
        }
        $user_login = trim( sanitize_text_field( $user_login ) );
        $user       = '';
        if ( is_email( $user_login ) && email_exists( $user_login ) ) {
            $user = get_user_by( 'email', $user_login );
        } elseif ( ! is_email( $user_login ) && username_exists( $user_login ) ) {
            $user = get_user_by( 'login', $user_login );
        }
        if ( empty( $user ) || ! is_a( $user, '\WP_User' ) ) {
            return $this->error_response( 'Please provide a valid login details.', 'validation_error', 400 );
        }
        $user_id = $user->ID;
        $saved_code = get_user_meta( $user_id, PASSWORD_RESET_CODE, true );
        if ( empty( $saved_code ) ) {
            return $this->error_response( 'Invalid security code.', 'validation_error', 400 );
        }
        if ( trim( sanitize_text_field( $security_code ) ) !== $saved_code ) {
            return $this->error_response( 'Your security code is incorrect.', 'validation_error', 400 );
        }
        reset_password( $user, $new_password );
        update_user_meta( $user_id, PASSWORD_RESET_CODE, '' );
        update_user_meta( $user_id, PASSWORD_RESET_ATTEMPTS, 0 );
        // Email content
        $variables = [ 'first_name' => $user->first_name ];
        $subject = 'Your VisCorner password has been changed';
        $content = add_email_template( get_template_content( 'password-changed.php', $variables ), $subject );
        $mailer  = new V_Mailer();
        $data = [
            'email'   => $user->user_email,
            'subject' => $subject,
            'content' => $content
        ];
        $mailer->send( $data );
        return $this->success_response( 'Your VisCorner password has changed.', 'success' );
    }
    
	/**
	 * Validate password.
     * 
     * @param string $password Password to confirm.
     * @param string $new_password New password to confirm.
     * @param string $confirm_password Confirmation password.
     * @param array $user WordPress user object.
     * 
     * @return bool|array True on success and array on failure
	 */
    private function validate_password( $password, $new_password, $confirm_password, $user ) {
        if ( false === $this->confirm_password( $password, $user ) ) {
            return [
                'error' => 'Your password is incorrect.',
                'key'   => 'current_password'
            ];
        }
        if ( false === $this->is_strong_password( $new_password ) ) {
            return [
                'error' => 'Your password must be at least 8 characters in length and it must contain at least one lower case letter, one capital letter and one number.',
                'key'   => 'new_password'
            ];
        }
        if ( $new_password !== $confirm_password ) {
            return [
                'error' => 'Your passwords do not match.',
                'key'   => 'confirm_password'
            ];
        }
        return true;
    }
    
	/**
	 * Confirm user password.
     * 
     * @param string $password Password to confirm.
     * @param array $user WordPress user object.
     * 
     * @return bool
	 */
    private function confirm_password( $password, $user ) {
        if ( ! $password || false === wp_check_password( $password, $user->user_pass, $user->ID ) ) {
            return false;
        }
        return true;
    }
    
	/**
	 * Check strong password.
     * 
     * @param string $password Password to check.
     * 
     * @return bool
	 */
    public function is_strong_password( $password ) {
        $lowercase = preg_match('@[a-z]@', $password);
        $uppercase = preg_match('@[A-Z]@', $password);
        $number    = preg_match('@[0-9]@', $password);
        if ( strlen( $password ) < 8 || ! $lowercase || ! $uppercase || ! $number ) {
            return false;
        }
        return true;
    }

    /**
    *  Validate number
     * 
     * @param int|string $number Number to validate.
     * @param string $min Minimum. Optional.
     * @param string $max Maximum. Optional.
     * 
     * @return bool|string True on success and string on failure
    */
    private function validate_number( $number, $min = '', $max = '' ) {
        // Remove + and check that all characters are numeric
        if ( ! ctype_digit( str_replace( '+', '', "$number" ) ) ) {
            return "Invalid number.";
        }
        if ( $min !== '' && ( $number < $min ) ) {
            return "Value must be greater than $min.";
        }
        if ( $max !== '' && ( $number > $max ) ) {
            return "Value must be less than $min.";
        }
        return true;
    }
    
	/**
	 * Check if user can send quote
     * 
     * @param array $user WordPress user object.
     * @param array $request WordPress post object.
     * 
     * @return bool|array True on success and array on failure
	 */
    private function user_can_send_quote( $user, $request ) {
        if ( ! in_array( $request->service_id, $user->{PROS_SERVICES_MK} ) ) {
            return [ 'success' => false, 'message' => 'You are not allowed to contact this customer.' ];
        }
        if ( get_user_meta( $user->ID, VC_EMAIL_CONFIRMATION_KEY, true ) ) {
            return [ 'success' => false, 'message' => 'You must confirm your email address before you can send quotes.' ];
        }
        if ( in_array( $user->ID, (array) get_option( 'users_who_cannot_send_quote' ) ) ) {
            return [ 'success' => false, 'message' => 'You are prohibited from sending quotes.' ];
        }
        return true;
    }
    
	/**
	 * Revoke tokens
     * 
     * @param string $bearer Authorization bearer access token.
	 * @param string $refresh_token Refresh token
	 * @param string $push_token Push notification token. Optional
     * 
	 * @return array Success or Failure
	 */
    public function revoke_token( $bearer, $refresh_token, $push_token = '' ) {
        $user_id = get_current_user_id();
        
        // Remove refresh token from refresh token list
        $refresh_tokens = get_user_meta( $user_id, JWT_REFRESH_TOKENS_MK, true );
        if( in_array( $refresh_token, $refresh_tokens ) ){
            unset( $refresh_tokens[ array_search( $refresh_token, $refresh_tokens ) ] );
            update_user_meta( $user_id, JWT_REFRESH_TOKENS_MK, $refresh_tokens );
        }

        // Revoke refresh tokens
        $revoked_tokens = $this->remove_expired_token( $user_id, true );
        $revoked_tokens = array_merge( $revoked_tokens, [$refresh_token] );
        update_user_meta( $user_id, REVOKED_REFRESH_TOKENS_MK, $revoked_tokens );

        sscanf( $bearer, 'Bearer %s', $token );
        $revoked_tokens = $this->remove_expired_token( $user_id );
        if( $token && ! in_array( $token, $revoked_tokens ) ){
            $revoked_tokens = array_merge( $revoked_tokens, [$token] );
            update_user_meta( $user_id, REVOKED_TOKENS_MK, $revoked_tokens );
        }

        if( $push_token ){
            $this->delete_push_token( $push_token );
        }
        
        return $this->success_response( 'Token revoked.', 'token_revoked' );
    }
    
	/**
	 * Remove expired revoked tokens and return not yet expired tokens
     * 
     * @param int $user_id User ID.
	 * @param bool $refresh Whether to clean expired refresh or access token. Default false
     * 
	 * @return array Array of revoked tokens that have not yet expired.
	 */
    public function remove_expired_token( $user_id, $refresh = false ) {
        if( $refresh ){
            $secret_key = JWT_AUTH_REFRESH_SECRET_KEY;
            $revoke_key = REVOKED_REFRESH_TOKENS_MK;
        } else {
            $secret_key = JWT_AUTH_SECRET_KEY;
            $revoke_key = REVOKED_TOKENS_MK;
        }
        $revoked_tokens = (array) get_user_meta( $user_id, $revoke_key, true );
        if( $revoked_tokens ){
            $jwt = $this->get_auth();
            $current_time  = time();
            $remove_tokens = [];
            foreach ( $revoked_tokens as $key => $jwt_token ) {
                try {
                    $payload = JWT::decode( $jwt_token, $secret_key, [$jwt->get_alg()] );
                } catch ( Exception $e ) {
                    $remove_tokens[$key] = $jwt_token;
                    continue;
                }
                if ( isset( $payload->exp ) && $payload->exp < $current_time ) {
                    $remove_tokens[$key] = $jwt_token;
                }
            }
            if( $remove_tokens ){
                $revoked_tokens = array_diff( $revoked_tokens, $remove_tokens );
                if( $revoked_tokens ){
                    update_user_meta( $user_id, $revoke_key, $revoked_tokens );
                } else {
                    delete_user_meta( $user_id, $revoke_key );
                }
            }
        }
        return $revoked_tokens;
    }

	/**
	 * Generate token
	 *
	 * @param WP_User $user The WP_User object.
     * @param string $expire Expiry date timestamp.
	 * @param bool $refresh Whether to generate refresh or access token. Default false
	 *
	 * @return string The token string.
	 */
    public function generate_token( $user, $expiry, $refresh = false ) {
        $user_id = $user->ID;

        $jwt = $this->get_auth();
		$access_secret_key = defined( 'JWT_AUTH_SECRET_KEY' ) ? JWT_AUTH_SECRET_KEY : false;
		$refresh_secret_key = defined( 'JWT_AUTH_REFRESH_SECRET_KEY' ) ? JWT_AUTH_REFRESH_SECRET_KEY : false;
        
		$issued_at  = time();
		$not_before = $issued_at - 2; // To prevent 'jwt not active' error in node server
		$payload = array(
			'iss'  => $jwt->get_iss(),
			'iat'  => $issued_at,
			'nbf'  => $not_before,
			'exp'  => $expiry,
			'data' => array(
				'user' => array(
					'id' => $user_id,
				),
			),
		);
		$alg = $jwt->get_alg();
        
        if( $refresh === false ){
            // Let the user modify the token data before the sign.
            return JWT::encode( apply_filters( 'jwt_auth_payload', $payload, $user ), $access_secret_key, $alg );
        } else if ( $refresh === true ) {
            // Clean expired
            $this->remove_expired_token( $user_id, true );
            
            $refresh_token = JWT::encode( $payload, $refresh_secret_key, $alg );

            // Record issued secret tokens
            $refresh_tokens = get_user_meta( $user_id, JWT_REFRESH_TOKENS_MK, true );
            $refresh_tokens = is_array( $refresh_tokens ) ? $refresh_tokens : [];
            $refresh_tokens = array_merge( $refresh_tokens, [$refresh_token] );
            update_user_meta( $user_id, JWT_REFRESH_TOKENS_MK, $refresh_tokens );
    
            return $refresh_token;
        }
    }
    
	/**
	 * Refresh access token.
     * 
     * @param array $token refresh token.
	 *
	 * @return array New access token data
	 */
	public function refresh_token( $token ) {
        // Try to decode the token.
		try {
            $secret_key = defined( 'JWT_AUTH_REFRESH_SECRET_KEY' ) ? JWT_AUTH_REFRESH_SECRET_KEY : false;
            $jwt        = $this->get_auth();
			$alg        = $jwt->get_alg();
			$payload    = JWT::decode( $token, $secret_key, array( $alg ) );

			// The Token is decoded now validate the iss.
			if ( $payload->iss !== $jwt->get_iss() ) {
                return $this->error_response( 'The iss do not match with this server.', 'validation_error', 400 );
			}

			// Check the user id existence in the token.
			if ( ! isset( $payload->data->user->id ) ) {
                return $this->error_response( 'User ID not found in the token.', 'validation_error', 400 );
			}

            $user_id = $payload->data->user->id;

			// So far so good, check if the given user id exists in db.
			$user = get_user_by( 'id', $user_id );

			if ( ! $user ) {
                return $this->error_response( 'User does not exist.', 'validation_error', 400 );
			}
			
			$revoked_tokens = (array) get_user_meta( $user_id, REVOKED_REFRESH_TOKENS_MK, true );
			foreach ( $revoked_tokens as $jwt_token ) {
				if ( $jwt_token === $token ) {
                    return $this->error_response( 'Token has been revoked.', 'validation_error', 400 );
				}
			}

			$expiry = time() + ( MINUTE_IN_SECONDS * 30 );
			$token = $this->generate_token( $user, $expiry );

            return [
                'success' => true,
                'token'   => $token,
                'expiry'  => $expiry
            ];
		} catch ( Exception $e ) {
			// Something went wrong when trying to decode the token, return error response.
            return $this->error_response( $e->getMessage(), 'validation_error', 400 );
		}
    }
    
    /**
     * JWT plugin auth instance
     * 
     * @return object $api JWT plugin Auth object
     */
    private function get_auth() {
		if( $this->auth ){
			return $this->auth;
		} else {
			return $this->auth = new Auth();
		}
	}

}