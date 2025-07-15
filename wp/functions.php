<?php

add_action(
    'rest_api_init',
    function () {
        register_rest_route(
                                'my-namespace/v1',
                                '/proxy',
                                [
                                    'methods'             => 'POST',
                                    'callback'            => 'my_proxy_handler',
                                    'permission_callback' => '__return_true',       // adjust for authentication if needed
                                ]
                            );
    }
);

function my_proxy_handler(WP_REST_Request $request) {
    $json_data = $request->get_json_params(); // Extract the JSON body

    $external_url = 'https://external-site.com/api/endpoint'; // Replace with your target

    $response = wp_remote_post(
                                $external_url,
                                [
                                    'headers' => [
                                    'Content-Type' => 'application/json',
                                ],
                                'body'          => json_encode($json_data),
                                'timeout'       => 15,
                                'sslverify'     => true // set to false ONLY for dev or self-signed certs
    ]);

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
