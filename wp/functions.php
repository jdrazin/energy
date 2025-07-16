<?php

add_action(
    'rest_api_init',
    function () {
        register_rest_route(
                                'projections',                                      // route_namespace: first URL segment after core prefix
                                '/proxy',                                           // route :          base URL for route you are adding
                                [
                                    'methods'             => 'POST',
                                    'callback'            => 'my_proxy_handler',
                                    'permission_callback' => '__return_true',       // adjust for authentication if needed
                                ]
                            );
    }
);

function my_proxy_handler(WP_REST_Request $request) {
    $json_data  = $request->get_json_params(); // Extract the JSON body
    $url        = 'https://88.202.150.174:8444/projections';
    $response   = wp_remote_post(
                                    $url,
                                    [
                                        'headers'       =>  [
                                                                'Content-Type' => 'application/json',
                                                            ],
                                        'body'          =>  json_encode($json_data),
                                        'timeout'       =>  15,
                                        'sslverify'     =>  true // set to false ONLY for dev or self-signed certs
                                    ]
                                );

    if (is_wp_error($response)) {
        return new WP_REST_Response(
                                    [
                                        'error' => $response->get_error_message()
                                    ],
                                    500);
    }
    $body       = wp_remote_retrieve_body($response);
    $code       = wp_remote_retrieve_response_code($response);
    $headers    = wp_remote_retrieve_headers($response);
    return new WP_REST_Response(
        json_decode($body, true), // re-decode JSON (or return raw body)
        $code,
        $headers
    );
}

/*

// see https://chatgpt.com/c/686e5472-1464-800d-8714-eaf45f049053

add_action  (
                'init',
                'redirect_json_post_to_external'
            );

function redirect_json_post_to_external() {
//    $url = 'https://www.drazin.net:8444/projection';
	$url = 'https://88.202.150.174:8444/projection';

    // Check that it's a JSON POST request to a specific endpoint (e.g., /wp-json/my-namespace/my-route)
	$server_request_uri = $_SERVER['REQUEST_URI'];
    if ($_SERVER['REQUEST_METHOD'] === 'POST' && strpos($server_request_uri, '/projections') !== false) {

        // Get raw POST data
        $raw_post_data = file_get_contents('php://input');
        $json_data = json_decode($raw_post_data, true);

        // Forward the POST request to the external API
        $response = wp_remote_post($url,
									[
										'sslverify' => false,   // bypass SSL certificate verification
										'headers'	=> [
														'Content-Type' => 'application/json',
														],
										'body' 		=> json_encode($json_data),
									]);

        // Return the external response directly
        if (is_wp_error($response)) {
            wp_send_json_error([
									'message' 				=> 'Request failed: ' . $url,
			                    	'server_request_uri' 	=> $server_request_uri,
									'response'				=> var_export($response, true)
								]);
        } else {
            $body = wp_remote_retrieve_body($response);
            $decoded = json_decode($body, true);
            wp_send_json_success($decoded);
		//    wp_send_json($decoded);
        }
        exit;
    }

 */