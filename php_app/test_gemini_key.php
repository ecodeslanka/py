<?php
/* test_gemini_key.php
   Proxies a lightweight call to Gemini to verify the stored API key works.
   Returns JSON {success, model, message}
*/
header('Content-Type: application/json');
include 'config.php';

if($_SERVER['REQUEST_METHOD']!=='POST' || ($_POST['action']??'')===''){
    echo json_encode(['success'=>false,'message'=>'Invalid request']); exit;
}

/* Fetch stored key */
$res = mysqli_query($conn,"SELECT `value` FROM ai_settings WHERE `key`='gemini_api_key' LIMIT 1");
$row = $res ? mysqli_fetch_assoc($res) : null;
$api_key = trim($row['value'] ?? '');

if(!$api_key){
    echo json_encode(['success'=>false,'message'=>'No API key configured.']); exit;
}

/* Call Gemini list-models to verify key */
$url = 'https://generativelanguage.googleapis.com/v1beta/models?key='.urlencode($api_key);
$ctx = stream_context_create(['http'=>[
    'method'  => 'GET',
    'timeout' => 8,
    'header'  => "Accept: application/json\r\n"
]]);

$resp = @file_get_contents($url, false, $ctx);
if($resp === false){
    /* fallback: try curl */
    if(function_exists('curl_init')){
        $ch = curl_init($url);
        curl_setopt_array($ch,[CURLOPT_RETURNTRANSFER=>true,CURLOPT_TIMEOUT=>8,CURLOPT_SSL_VERIFYPEER=>true]);
        $resp = curl_exec($ch); curl_close($ch);
    }
}

if(!$resp){
    echo json_encode(['success'=>false,'message'=>'Could not reach Gemini API. Check server internet access.']); exit;
}

$data = json_decode($resp, true);
if(isset($data['error'])){
    $err = $data['error']['message'] ?? 'API error';
    echo json_encode(['success'=>false,'message'=>$err]); exit;
}

/* Pick first model name */
$model = 'gemini-1.5-flash';
if(!empty($data['models'])){
    foreach($data['models'] as $m){
        if(stripos($m['name']??'','flash')!==false){ $model=basename($m['name']); break; }
    }
}
echo json_encode(['success'=>true,'model'=>$model]);
