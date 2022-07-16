<?php

namespace AD_BLIS;

if ( ! defined( 'ABSPATH' ) ) {
    exit; // Exit if accessed directly.
}

use WP_REST_Request;
use WP_REST_Response;
use WP_REST_Server;

class Api extends Methods {
    	
	/**
	 * The namespace to add to the api routes.
	 *
	 * @var string The namespace to add to the api routes
	 */
    private $namespace;

    /**
     * Class constructor
     */
    public function __construct() {
        $this->namespace = AD_BLIS_REST_API_NAMESPACE;
    }
    
	/**
	 * Register API routes
	 */
    public function register_rest_routes() {
		register_rest_route(
			$this->namespace,
			'/requests(?:/(?P<id>\d+))?',
			[
				'methods'             => WP_REST_Server::READABLE,
				'callback'            => [ $this, 'get_requests_api' ],
				'permission_callback' => [ $this, 'permission' ],
			    'args' => [
				    'id' => [
                        'sanitize_callback' => 'absint',
                    ],
				    'per_page' => [
                        'default' => 10,
                        'sanitize_callback' => 'absint',
                    ],
                    'offset' => [
                        'default' => 0,
                        'sanitize_callback' => 'absint',
                    ],
                    'order' => [
                        'default' => 'asc',
                        'sanitize_callback' => 'sanitize_text_field',
                    ]
			    ]
			]
		);
		register_rest_route(
			$this->namespace,
			'/quotes',
			array(
				'methods'             => WP_REST_Server::READABLE,
				'callback'            => [ $this, 'get_quotes_api' ],
				'permission_callback' => [ $this, 'permission' ],
			    'args' => [
                    'status' => [
                        'default' => 'all',
                        'sanitize_callback' => 'sanitize_text_field',
                    ],
				    'per_page' => [
                        'default' => 10,
                        'sanitize_callback' => 'absint',
                    ],
                    'offset' => [
                        'default' => 0,
                        'sanitize_callback' => 'absint',
                    ],
                    'orderby' => [
                        'default' => 'date',
                        'sanitize_callback' => 'sanitize_text_field',
                    ],
                    'order' => [
                        'default' => 'desc',
                        'sanitize_callback' => 'sanitize_text_field',
                    ]
			    ]
			)
		);
		register_rest_route(
			$this->namespace,
			'/send-quote/(?P<id>\d+)',
			array(
				'methods'             => WP_REST_Server::EDITABLE,
				'callback'            => [ $this, 'send_quote_api' ],
				'permission_callback' => [ $this, 'permission' ],
			    'args' => [
				    'id' => [
					    'required' => true,
					    'sanitize_callback' => 'absint'
				    ],
				    'price' => [
					    'required' => true,
					    'sanitize_callback' => 'sanitize_text_field'
				    ],
				    'price_type' => [
					    'required' => true,
					    'sanitize_callback' => 'sanitize_text_field'
				    ],
				    'message' => [
					    'required' => true,
					    'sanitize_callback' => 'sanitize_textarea_field'
				    ]
			    ]
			)
		);
		register_rest_route(
			$this->namespace,
			'/quote-templates(?:/(?P<template_id>\d+))?',
			[
				[
					'methods'             => WP_REST_Server::READABLE,
					'callback'            => [ $this, 'get_quote_templates_api' ],
					'permission_callback' => [ $this, 'permission' ],
					'args' => [
						'template_id' => [
							'required' => false,
							'sanitize_callback' => 'absint'
						],
						'request_id' => [
							'required' => false,
							'sanitize_callback' => 'absint',
						]
					]
				],
				[
					'methods'             => WP_REST_Server::EDITABLE,
					'callback'            => [ $this, 'update_quote_template_api' ],
					'permission_callback' => [ $this, 'permission' ],
					'args' => [
						'template_id' => [
							'required' => false,
							'sanitize_callback' => 'absint'
						],
						'template_title' => [
							'required' => false,
							'sanitize_callback' => 'sanitize_text_field',
						],
						'template_content' => [
							'required' => false,
							'sanitize_callback' => 'sanitize_textarea_field',
						]
					]
				],
				[
					'methods'             => WP_REST_Server::DELETABLE,
					'callback'            => [ $this, 'delete_quote_template_api' ],
					'permission_callback' => [ $this, 'permission' ],
					'args' => [
						'template_id' => [
							'required' => true,
							'sanitize_callback' => 'absint'
						]
					]
				]
			]
		);
		register_rest_route(
			$this->namespace,
			'/user-data',
			array(
				'methods'             => WP_REST_Server::READABLE,
				'callback'            => [ $this, 'get_user_data_api' ],
				'permission_callback' => [ $this, 'permission' ],
			    'args' => [
				    'field' => [
                        'sanitize_callback' => 'sanitize_text_field'
                    ]
			    ]
			)
		);
		register_rest_route(
			$this->namespace,
			'/update',
			array(
				'methods'             => WP_REST_Server::EDITABLE,
				'callback'            => [ $this, 'update_user_data_api' ],
				'permission_callback' => [ $this, 'permission' ]
			)
		);
		register_rest_route(
			$this->namespace,
			'/options/(?P<data>[a-zA-Z0-9-]+)',
			array(
				'methods'             => WP_REST_Server::READABLE,
				'callback'            => [ $this, 'get_options_api' ],
				'permission_callback' => '__return_true',
			    'args' => [
				    'data' => [
                        'sanitize_callback' => 'sanitize_text_field'
                    ]
			    ]
			)
		);
		register_rest_route(
			$this->namespace,
			'/transactions/(?P<data>[a-zA-Z0-9-]+)',
			array(
				'methods'             => WP_REST_Server::READABLE,
				'callback'            => [ $this, 'get_transactions_api' ],
				'permission_callback' => [ $this, 'permission' ],
			    'args' => [
				    'data' => [
                        'default' => '',
                        'sanitize_callback' => 'sanitize_text_field',
                    ],
				    'per_page' => [
                        'default' => 10,
                        'sanitize_callback' => 'absint',
                    ],
				    'page' => [
                        'default' => 0,
                        'sanitize_callback' => 'absint',
                    ],
                    'offset' => [
                        'default' => 0,
                        'sanitize_callback' => 'absint',
                    ],
                    'orderby' => [
                        'default' => 'date',
                        'sanitize_callback' => 'sanitize_text_field',
                    ],
                    'order' => [
                        'default' => 'DESC',
                        'sanitize_callback' => 'sanitize_text_field',
                    ]
			    ]
			)
		);
		register_rest_route(
			$this->namespace,
			'/create-user',
			array(
				'methods'             => WP_REST_Server::EDITABLE,
				'callback'            => [ $this, 'create_user_api' ]
			)
		);
		register_rest_route(
			$this->namespace,
			'/contact',
			array(
				'methods'             => WP_REST_Server::EDITABLE,
				'callback'            => [ $this, 'contact_us_api' ],
				'permission_callback' => [ $this, 'permission' ],
			    'args' => [
				    'subject' => [
					    'required' => false,
					    'sanitize_callback' => 'sanitize_text_field'
				    ],
				    'message' => [
					    'required' => true,
					    'sanitize_callback' => 'sanitize_textarea_field'
				    ],
				    'attachment' => [
					    'required' => false
				    ]
			    ]
			)
		);
		register_rest_route(
			$this->namespace,
			'/products',
			array(
				'methods'             => WP_REST_Server::READABLE,
				'callback'            => [ $this, 'products_api' ],
				'permission_callback' => [ $this, 'permission' ]
			)
		);
		register_rest_route(
			$this->namespace,
			'/reset-password',
			array(
				'methods'             => WP_REST_Server::EDITABLE,
				'callback'            => [ $this, 'reset_password_api' ],
				'permission_callback' => '__return_true',
			    'args' => [
				    'user_login' => [
						'required' => true,
					    'sanitize_callback' => 'sanitize_text_field'
				    ]
			    ]
			)
		);
		register_rest_route(
			$this->namespace,
			'/change-password',
			array(
				'methods'             => WP_REST_Server::EDITABLE,
				'callback'            => [ $this, 'change_password_api' ],
				'permission_callback' => '__return_true',
			    'args' => [
				    'user_login' => [
						'required' => true,
					    'sanitize_callback' => 'sanitize_text_field'
					],
				    'new_password' => [
						'required' => true,
					    'sanitize_callback' => 'sanitize_text_field'
				    ],
				    'confirm_password' => [
						'required' => true,
					    'sanitize_callback' => 'sanitize_text_field'
				    ],
				    'security_code' => [
						'required' => true,
					    'sanitize_callback' => 'sanitize_text_field'
				    ]
			    ]
			)
		);
		register_rest_route(
			$this->namespace,
			'/revoke',
			array(
				'methods'             => WP_REST_Server::EDITABLE,
				'callback'            => [ $this, 'revoke_api' ],
				'permission_callback' => [ $this, 'permission' ],
			    'args' => [
				    'refresh_token' => [
						'required' => true,
					    'sanitize_callback' => 'sanitize_text_field'
					],
				    'push_token' => [
						'default' => '',
					    'sanitize_callback' => 'sanitize_text_field'
				    ]
			    ]
			)
		);
		register_rest_route(
			$this->namespace,
			'/update-push-token',
			array(
				'methods'             => WP_REST_Server::EDITABLE,
				'callback'            => [ $this, 'update_push_token_api' ],
				'permission_callback' => [ $this, 'permission' ],
			    'args' => [
				    'token' => [
					    'required' => true,
					    'sanitize_callback' => 'sanitize_text_field'
				    ]
			    ]
			)
		);
    }
    
	/**
	 * Permission.
	 * 
	 * @return bool
	 */
    public function permission() {
        return current_user_can( 'read' );
    }
    
	/**
	 * Get requests.
	 *
	 * @param WP_REST_Request $request The request.
	 * @return WP_REST_Response The response.
	 */
    public function get_requests_api( WP_REST_Request $request ) {
		if( $request->get_param('id') ){
			$response = $this->get_request( $request->get_param('id') );
			if( false === $response['success'] ){
				extract( $response );
				return $this->rest_error_response( $message, $code, $status );
			}
			return $response;
		}
		extract( $this->get_requests( $request->get_params() ) );
        $response = rest_ensure_response( $data );
        $response->header( 'X-WP-Total', $total_records );
        $response->header( 'X-WP-TotalPages', $total_pages );
		return $response;
	}
    
	/**
	 * Get quotes.
	 *
	 * @param WP_REST_Request $request The request.
	 * @return WP_REST_Response The response.
	 */
    public function get_quotes_api( WP_REST_Request $request ) {
		extract( $this->get_quotes( $request->get_params() ) );
        $response = rest_ensure_response( $data );
        $response->header( 'X-WP-Total', $total_records );
        $response->header( 'X-WP-TotalPages', $total_pages );
		return $response;
	}
    
	/**
	 * Send quote
	 *
	 * @param WP_REST_Request $request The request.
	 * @return array|WP_REST_Request Array on success and WP_REST_Request on failure.
	 */
    public function send_quote_api( WP_REST_Request $request ) {
		$response = $this->send_quote( $request->get_params() );
		extract( $response );
		if( true === $success ){
			return $response;
		} else {
			return $this->rest_error_response( $message, $code, $status, $fields );
		}
	}
    
	/**
	 * Get quote templates
	 *
	 * @param WP_REST_Request $request The request.
	 * @return array|WP_REST_Request Array on success and WP_REST_Request on failure.
	 */
    public function get_quote_templates_api( WP_REST_Request $request ) {
		$response = $this->get_quote_templates( $request->get_params() );
		if( true === $response['success'] ){
			return $response;
		} else {
			extract( $response );
			return $this->rest_error_response( $message, $code, $status, $fields );
		}
	}
    
	/**
	 * Update quote templates
	 *
	 * @param WP_REST_Request $request The request.
	 * @return array|WP_REST_Request Array on success and WP_REST_Request on failure.
	 */
    public function update_quote_template_api( WP_REST_Request $request ) {
		$response = $this->update_quote_template( $request->get_params() );
		if( true === $response['success'] ){
			return $response;
		} else {
			extract( $response );
			return $this->rest_error_response( $message, $code, $status, $fields );
		}
	}
    
	/**
	 * Delete quote templates
	 *
	 * @param WP_REST_Request $request The request.
	 * @return WP_REST_Request
	 */
    public function delete_quote_template_api( WP_REST_Request $request ) {
		extract( $this->delete_quote_template( $request->get_param('template_id') ) );
		if( true === $success ){
			return $this->rest_success_response( $message, $code, $status );
		} else {
			return $this->rest_error_response( $message, $code, $status, $fields );
		}
	}
    
	/**
	 * Get user data
	 *
	 * @param WP_REST_Request $request The request.
	 * @return array User data
	 */
    public function get_user_data_api( WP_REST_Request $request ) {
		return $this->get_user_data( $request->get_param( 'field' ) );
	}
    
	/**
	 * Update user data
	 *
	 * @param WP_REST_Request $request The request.
	 * @return WP_REST_Request
	 */
    public function update_user_data_api( WP_REST_Request $request ) {
		$data = array_merge( $request->get_params(), $request->get_file_params() );
		extract( $this->update_user_data( $data ) );
		if( true === $success ){
			return $this->rest_success_response( $message, $code, $status );
		} else {
			return $this->rest_error_response( $message, $code, $status, $fields );
		}
	}
    
	/**
	 * Create user
	 *
	 * @param WP_REST_Request $request The request.
	 * @return WP_REST_Request
	 */
    public function create_user_api( WP_REST_Request $request ) {
		extract( $this->create_user( $request->get_params() ) );
		if( true === $success ){
			return $this->rest_success_response( $message, $code, $status );
		} else {
			return $this->rest_error_response( $message, $code, $status, $fields );
		}
	}
    
	/**
	 * Pricing options
	 *
	 * @param WP_REST_Request $request The request.
	 * @return array Product data
	 */
    public function products_api() {
		return $this->products();
	}
    
	/**
	 * Get options
	 * 
	 * @return array
	 */
    public function get_options_api( WP_REST_Request $request ) {
		return $this->get_options( $request->get_param( 'data' ) );
	}
    
	/**
	 * Get transactions
	 * 
	 * @return WP_REST_Response The response.
	 */
    public function get_transactions_api( WP_REST_Request $request ) {
		extract( $this->get_transactions( $request->get_params() ) );
        $response = rest_ensure_response( $data );
        $response->header( 'X-WP-Total', $total_records );
        $response->header( 'X-WP-TotalPages', $total_pages );
		return $response;
	}

	/**
	 * Contact customer support
	 *
	 * @param WP_REST_Request $request The request.
	 * @return WP_REST_Request
	 */
    public function contact_us_api( WP_REST_Request $request ) {
		$data = array_merge( $request->get_params(), $request->get_file_params() );
		extract( $this->contact_us( $data ) );
		if( true === $success ){
			return $this->rest_success_response( $message, $code, $status );
		} else {
			return $this->rest_error_response( $message, $code, $status );
		}
	}

	/**
	 * Reset password
	 *
	 * @param WP_REST_Request $request The request.
	 * @return WP_REST_Request
	 */
    public function reset_password_api( WP_REST_Request $request ) {
		extract( $this->password_reset_request( $request->get_param('user_login') ) );
		if( true === $success ){
			return $this->rest_success_response( $message, $code, $status );
		} else {
			return $this->rest_error_response( $message, $code, $status );
		}
	}

	/**
	 * Change password
	 *
	 * @param WP_REST_Request $request The request.
	 * @return WP_REST_Request
	 */
    public function change_password_api( WP_REST_Request $request ) {
		extract( $this->change_password( $request->get_params() ) );
		if( true === $success ){
			return $this->rest_success_response( $message, $code, $status );
		} else {
			return $this->rest_error_response( $message, $code, $status );
		}
	}

	/**
	 * Revoke
	 *
	 * @param WP_REST_Request $request The request.
	 * @return WP_REST_Request
	 */
    public function revoke_api( WP_REST_Request $request ) {
		extract( $this->revoke_token( $request->get_header('authorization'), $request->get_param('refresh_token'), $request->get_param('push_token') ) );
		if( true === $success ){
			return $this->rest_success_response( $message, $code, $status );
		} else {
			return $this->rest_error_response( $message, $code, $status );
		}
	}
    
	/**
	 * Update push token.
	 *
	 * @param WP_REST_Request $request The request.
	 * @return WP_REST_Request
	 */
    public function update_push_token_api( WP_REST_Request $request ) {
		extract( $this->update_push_token( $request->get_param('token') ) );
		if( true === $success ){
			return $this->rest_success_response( $message, $code, $status );
		} else {
			return $this->rest_error_response( $message, $code, $status );
		}
	}
    
	/**
	 * Success response.
	 *
	 * @param string $message The response message.
	 * @param string $code The response code.
	 * @param string $status The response status.
	 * @param array $fields Affected fields.
	 * 
	 * @return WP_REST_Request
	 */
    private function rest_success_response( $message, $code = '', $status = 200, $fields = [] ) {
		return new WP_REST_Response(
			[
				'success'    => true,
				'status'     => $status,
				'code'       => $code,
				'message'    => $message,
				'data'       => [ 
                    'status' => $status,
                    'fields' => $fields,
                    'params' => [],
                ]
            ],
			$status
		);
    }
    
	/**
	 * Error response.
	 *
	 * @param string $message The response message.
	 * @param string $code The response code.
	 * @param string $status The response status.
	 * @param array $fields Affected fields.
	 * 
	 * @return WP_REST_Request
	 */
    private function rest_error_response( $message, $code = '', $status = 403, $fields = [] ) {
		return new WP_REST_Response(
			[
				'success'    => false,
				'status'     => $status,
				'code'       => $code,
				'message'    => $message,
				'data'       => [ 
                    'status' => $status,
                    'fields' => $fields,
                    'params' => [],
                ]
            ],
			$status
		);
    }

}