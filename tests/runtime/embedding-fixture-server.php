<?php
declare(strict_types=1);

// Disposable loopback fixture, not an application endpoint. Stores counts only.
if (PHP_SAPI !== 'cli-server' || !in_array($_SERVER['REMOTE_ADDR']??'', ['127.0.0.1','::1'],true)) { http_response_code(403); exit; }
$counter=getenv('EVA_EMBED_FIXTURE_COUNTER');
if (!$counter || !preg_match('~^/tmp/eva-embedding-fixture-[a-z0-9-]+\.count$~D',$counter)) throw new RuntimeException('Select a disposable counter file.');
header('Content-Type: application/json');
if (($_SERVER['REQUEST_METHOD']??'')!=='POST') { http_response_code(405); echo '{}'; return; }
$path=parse_url($_SERVER['REQUEST_URI'],PHP_URL_PATH);
if (!in_array($path,['/api/embed','/v1/embeddings'],true)) { http_response_code(404); echo '{}'; return; }
if ($path==='/v1/embeddings' && ($_SERVER['HTTP_AUTHORIZATION']??'')!=='Bearer fixture-secret') { http_response_code(401); echo '{}'; return; }
$body=json_decode((string)file_get_contents('php://input'),true);
if (!is_array($body) || !is_array($body['input']??null) || !is_string($body['model']??null)) { http_response_code(400);echo '{}';return; }
$count=is_file($counter)?(int)file_get_contents($counter):0;
file_put_contents($counter,(string)($count+1),LOCK_EX);chmod($counter,0644);
$vectors=array_map(static fn(string $text):array=>[(float)strlen($text),1.0,2.0],$body['input']);
if ($path==='/api/embed') echo json_encode(['model'=>$body['model'],'embeddings'=>$vectors]);
else {
    $data=[];foreach($vectors as $index=>$vector)$data[]=['index'=>$index,'embedding'=>$vector];
    echo json_encode(['model'=>$body['model'],'data'=>array_reverse($data)]);
}
