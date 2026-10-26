<?php
// Only the local gateway can invoke this private origin. No document root is served.
$secret=getenv('REVIEW_ORIGIN_SECRET');$host=getenv('REVIEW_HOST');
if(!$secret||!hash_equals($secret,$_SERVER['HTTP_X_REVIEW_ORIGIN']??'')||($_SERVER['HTTP_HOST']??'')!==$host){http_response_code(404);exit;}
// TLS terminates at Cloudflare. The gateway fixes this host and strips forwarding headers.
$_SERVER['HTTPS']='on';$_SERVER['SERVER_PORT']='443';
unset($_SERVER['HTTP_X_REVIEW_ORIGIN']);
$path=rawurldecode(parse_url($_SERVER['REQUEST_URI'],PHP_URL_PATH)??'/');
if($_SERVER['REQUEST_METHOD']==='POST'&&str_ends_with($path,'/delivery/exports')){
 $body=file_get_contents('php://input',false,null,0,16385);$data=json_decode($body,true);
 $allowed=['executive_summary','overview','website_traffic','organic_search','keywords','seo_work','ai_visibility','key_events','sources'];
 if(strlen($body)>16384||!is_array($data)||($data['type']??'')!=='draft'||($data['profile']??'')!=='client_preview'||array_diff(array_keys($data),['type','profile','sections'])||!is_array($data['sections']??null)||array_filter($data['sections'],fn($v)=>!is_string($v)||!in_array($v,$allowed,true))){http_response_code(422);header('Content-Type: application/json');echo '{"message":"Only private client-preview Draft PDFs are available in this review workspace."}';exit;}
}
require dirname(__DIR__,2).'/backend/public/index.php';
