<?php
require_once(__DIR__.'/utils/index.php');
use function utils\fetchSchema;
require_once(__DIR__.'/utils/assoc.php');
use function assoc\{keyExists, merge, getValue};
$output = [
  '$schema' => "http://json-schema.org/draft-07/schema#",
  '$id' =>  $_SERVER['SCRIPT_URI']
];

$processDir = '../configs/processes';
$instanceDir = '../configs/instances';
$vocabulary = '../configs/instances/.schemas/vocabulary.json';
$vocabulary = json_decode(file_get_contents($vocabulary), true);

$processPath = $_SERVER['PATH_INFO'];

$pattern = '/\/(request|response)/';
if (!preg_match($pattern, $processPath, $keyRoot)) {
  header('HTTP/1.0 404 Not Found', true, 404);
  exit(1);
}

$keyRoot = $keyRoot[1];

$processPath = $processDir . preg_replace($pattern, '', $processPath) . '.json';
if (!file_exists($processPath)) {
  header('HTTP/1.0 404 Not Found', true, 404);
  exit(1);
}

$process = json_decode(file_get_contents($processPath), true);
$request = [];
$response = [];

$defType = ['type' => ['string', 'number']];

header('Content-Type: application/json');

array_walk_recursive($process, function($value, $key) use ($instanceDir, $vocabulary, $defType, &$request, &$response) {
  if (substr($key, 0, 1) === '$')
    return;
  $instance = fetchSchema($instanceDir, $value);

  if (array_key_exists('request', $instance)) {
    $req = $instance['request'];
    $defs = !array_key_exists('defaults', $req) ? [] : $req['defaults'];
    if (array_key_exists('fields', $req))
      foreach ($req['fields'] as $key => $_) {
        if (
          keyExists($req, ['overrides', $key])
          || array_key_exists($key, $response)
        ) continue;

        $enum = !keyExists($req, ['values', $key])
        ? $defType
        : ['enum' => array_keys($req['values'][$key])];
        $default = !keyExists($defs, $key)
        ? []
        : ['default' => $defs[$key]];

        $request[$key] = merge(getValue($request, $key, []),
          switching($key, $vocabulary['patternProperties']),
          getValue($vocabulary, ['properties', $key], []),
          $enum,
          $default
        );
      }

  }

  if (array_key_exists('response', $instance)) {
    $res = $instance['response'];
    if (array_key_exists('fields', $res))
      foreach ($res['fields'] as $key => $_) {
        if (array_key_exists($key, $request))
          continue;
        
        $enum = !keyExists($res, ['values', $key])
        ? $defType
        : ['enum' => array_values($res['values'][$key])];
        $response[$key] = merge(getValue($response, $key, []),
          switching($key, $vocabulary['patternProperties']),
          getValue($vocabulary, ['properties', $key], []),
          $enum
        );
      }
    }
});

$output += ['properties' => $$keyRoot];
echo json_encode($output , JSON_PRETTY_PRINT | JSON_UNESCAPED_SLASHES);

function switching($value, $patterns) {
  $return = [];
  foreach($patterns as $pattern => $output) {
    if (preg_match("/{$pattern}/", $value))
      return $output;
  }
  return $return;
}