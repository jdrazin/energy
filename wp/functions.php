<?php

// see https://chatgpt.com/c/686e5472-1464-800d-8714-eaf45f049053

add_action(
    'rest_api_init',
    function () {
        register_rest_route(
            'api',                                      // route_namespace: first URL segment after core prefix
            '/projection',                              // route :          base URL for route you are adding
            [
                'methods'             => 'POST',
                'callback'            => 'my_proxy_handler',
                'permission_callback' => '__return_true',       // adjust for authentication if needed
            ]
        );
    }
);

function my_proxy_handler(WP_REST_Request $request) {
    $json_body  = $request->get_json_params(); // Extract the JSON body
    $url        = 'https://88.202.150.174:8444/projections';
    $response   = wp_remote_post(
        $url,
        [
            'headers'       =>  [
                'Content-Type' => 'application/json',
            ],
            'body'          =>  json_encode($json_body),
            'timeout'       =>  15,
            'sslverify'     =>  false // set to false ONLY for dev or self-signed certs
        ]
    );

    if (is_wp_error($response)) {
        return new WP_REST_Response(
            [
                'error' => $response->get_error_message(),
                'url'   => $url
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
