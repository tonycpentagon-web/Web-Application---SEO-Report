<?php
// Local loopback router: only built frontend assets or Laravel's public entry point are served.
$path=rawurldecode(parse_url($_SERVER['REQUEST_URI'],PHP_URL_PATH)??'/');
$backend=dirname(__DIR__).'/backend';
$isBackend=str_starts_with($path,'/api/')||str_starts_with($path,'/sanctum/')||$path==='/up'||($_SERVER['REQUEST_METHOD']!=='GET'&&in_array($path,['/login','/logout','/password','/forgot-password','/reset-password'],true));
if($isBackend){require $backend.'/public/index.php';return;}
$dist=realpath(dirname(__DIR__).'/frontend/dist');
if(!$dist){http_response_code(503);echo 'Frontend build missing.';return;}
$file=realpath($dist.$path);
if(str_starts_with($path,'/assets/')&&$file&&is_file($file)&&str_starts_with($file,$dist.DIRECTORY_SEPARATOR)){
 $type=['js'=>'application/javascript','css'=>'text/css','png'=>'image/png','svg'=>'image/svg+xml','woff2'=>'font/woff2','jpg'=>'image/jpeg'][pathinfo($file,PATHINFO_EXTENSION)]??null;
 if(!$type){http_response_code(404);return;}header('Content-Type: '.$type);header('X-Content-Type-Options: nosniff');readfile($file);return;
}
if(str_contains($path,'..')||str_contains($path,"\0")||preg_match('~/(backend|storage|local-runtime|vendor|document-processor)(/|$)|\.env|\.php$~i',$path)){http_response_code(404);return;}
header('Content-Type: text/html; charset=utf-8');header('Cache-Control: no-store');header('Referrer-Policy: no-referrer');header('X-Robots-Tag: noindex, nofollow');readfile($dist.'/index.html');
