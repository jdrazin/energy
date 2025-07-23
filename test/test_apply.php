<?php
$string = '$data[\'time\'][\'suffix1\'][\'suffix2\'][\'param\']';
$value  = 123.456;
// $data['time']['suffix1']['suffix2']['param'];
$data = [];
setValueByStringPath($data, $string, $value);

print_r($data);
exit(0);

function setValueByStringPath(array &$array, string $path, $value): void {
    // Convert 'foo[bar][baz]' to ['foo', 'bar', 'baz']
    preg_match_all('/\[?([^\[\]]+)\]?/', $path, $matches);
    $keys = $matches[1];

    $ref = &$array;
    foreach ($keys as $key) {
        if (!isset($ref[$key]) || !is_array($ref[$key])) {
            $ref[$key] = [];
        }
        $ref = &$ref[$key];
    }
    $ref = $value;
}


	
	
	