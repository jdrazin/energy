<?php
	namespace Test;
    use GuzzleHttp\Client;
    use GuzzleHttp\Exception\GuzzleException;

    require_once __DIR__ . '/../vendor/autoload.php';

    $url = 'https://www.drazin.net:8443/control/slot_solution?token=%3EM%7C%24-u%5Bdg%22rZssv%7Cf%7CkO~D%2CPHBer0%23%5D~';
    $headers = [
        'Content-Type' => 'application/json',
        'Accept' => 'application/json'
    ];
    $query = [];
    try {
        $client = new Client();
        $response = $client->get($url, ['headers' => $headers, 'query' => $query]);
    }
    catch (GuzzleHttp\Exception\ClientException $e) {
        $a = 1;
    }
    catch (GuzzleException $e) {
        $a = 1;
    }