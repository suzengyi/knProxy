<?php
require_once('conf.php');
require_once('includes/module_parser.php');
require_once('includes/module_encoder.php');
require_once('includes/module_url.php');
require_once('includes/module_http.php');
require_once('includes/general_functions.php');

$knEncoder = new knEncoder();
if(!isset($_GET['url']) || $_GET['url']==''){
	include('index.inc.php');
	exit();
}
$url = $_GET['url'];
$knEncoder->serverKey = KNPROXY_SECRET;
if(isset($_GET['encrypt_key'])){
	$key = (int)$_GET['encrypt_key'];
	$knEncoder->setKey($key);
	$knEncoder->serverKey='';
}
if(!preg_match('~/~',$url))
	$url = $knEncoder->decode($url);
$knEncoder->serverKey = KNPROXY_SECRET;
$knEncoder->setKey(0);
/** Url Decrypted, Enc Engine Reinited **/
if(strtolower(substr($url,0,6))=='about:'){
	include_once('includes/module_about.php');
	print_about_page($url);
	exit();
}elseif(strtolower(substr($url,0,7))=='stream:'){
	/** Forces the proxy to go under Stream mode **/
	define('KNPROXY_FORCE_STREAM',1);
	$url = substr($url,7,strlen($url));
	$url = checkHttpUrl($url);
	//We need not init a parser instance here or any instance
	$knHTTP = new knHTTP($url,true);
	$knHTTP->start_stream(true);//The Script will be terminated
}
$url = checkHttpUrl($url);
/** Get the Scripts URL **/
$_HOST = $_SERVER['HTTP_HOST'];
if(strtolower(substr($_SERVER['HTTP_HOST'],0,4))!='http' && (!isset($_SERVER['HTTPS']) || $_SERVER['HTTPS']=='')){
	$_HOST = 'http://' . $_SERVER['HTTP_HOST'];
}else{
	$_HOST = 'https://' . $_SERVER['HTTP_HOST'];
}
$_SCRIPT =$_HOST . $_SERVER['SCRIPT_NAME'];
/** Create the modules **/
$knURL = new knUrl();
$knURL->setBaseurl($url);
$knHTTP = new knHttp($url);
/** Init them **/
if(isset($_POST['knproxy_gettopost']) && $_POST['knproxy_gettopost']=='true'){
	unset($_POST['knproxy_gettopost']);
	$knHTTP->set_get($_POST);
}else
	$knHTTP->set_post($_POST);
$knHTTP->set_cookies($_COOKIE);
if(!empty($_POST['knUSER']) || isset($_COOKIE['__knLogin'])){
	if($_POST['knUSER']==''){
		$pas=explode('/',$url);
		unset($pas[count($pas)-1]);
		$url_base = strtolower(implode('/',$pas));
		$a=explode('|',$_COOKIE['__knLogin']);
		foreach($a as $vak){
			$uK = explode('#',$vak);
			if($uK[0]!='' && strpos($url_base,$uK[0])===0){
				$knHTTP->httpauth=$uK[1];
				break;
			}
		}
	}else{
		$knHTTP->set_http_creds($_POST['knUSER'],$_POST['knPASS']);
		$pas=explode('/',$url);
		unset($pas[count($pas)-1]);
		$url_base = strtolower(implode('/',$pas));
		$a=explode('|',$_COOKIE['__knLogin']);
		$a[]=$url_base . '#' . $knHTTP->httpauth;
		setcookie('__knLogin',implode('|',$a),2147364748);
	}
}
/** See if we should give out an HTTPS warning **/
if(defined('KNPROXY_HTTPS_WARNING') && KNPROXY_HTTPS_WARNING != 'off')
	if($knHTTP->is_https==true && (!isset($_COOKIE['knprox_ssl_warning']) || $_COOKIE['knprox_ssl_warning']!='off') && !isset($_POST['yes'])){
		include_once('includes/gui_notice.php');exit();
	}elseif($knHTTP->is_https && isset($_POST['yes'])){
		setcookie('knprox_ssl_warning','off',2147483647);
	}
/** Send And Load **/
$knHTTP->send();
$headers = $knHTTP->refined_headers();
/** Debug Mode **/
if(isset($_GET['debug']) && $_GET['debug']=='true'){
	$eobj=Array('status'=>1994);//AUTOMATICALLY RE ENABLE SSL WARNINGS
	setcookie('knprox_ssl_warning','on',1);
	setcookie('knLogin','',1);
	if(isset($_GET['clear_cookies']) && $_GET['clear_cookies']=='true'){
		foreach($_COOKIE as $key=>$val){
			setcookie($key,'deleted',1);
		}
	}
	include('includes/gui_error.php');
	exit();
}
/** Do some fancy stuff with the headers **/
if($headers['HTTP_RESPONSE']==401){
	//UNAUTHORIZED
	$realm = $headers['WWW_AUTHENTICATE_REALM'];
	include('includes/gui_httpauth.php');
	exit();
}
/** Await Authentication **/
header('HTTP/1.1 ' . $headers['HTTP_RESPONSE'] . ' Omitted');
if(((int)$headers['HTTP_RESPONSE']>=400 && $headers['HTTP_RESPONSE']!=404) || (int)$headers['HTTP_RESPONSE']<1){
	$eobj=Array('status'=>$headers['HTTP_RESPONSE']);
	include('includes/gui_error.php');
	exit();
}
/** Load The Page **/
header('Content-Type: ' . $knHTTP->doctype);
/** Need Redirection? **/
if(isset($headers['HTTP_LOCATION']) && $headers['HTTP_LOCATION']!=''){
	$url = $knURL->getAbsolute($headers['HTTP_LOCATION']);
	$knurl = $knEncoder->encode($url);
	$nURL = basename(__FILE__) . "?url=" . $knurl;
	header('Location: ' . $nURL );
}
/** Downloads And Filename **/
if(isset($headers['CONTENT_DISPOSITION']) && $headers['CONTENT_DISPOSITION']!='')
	header('Content-Disposition: ' . $headers['CONTENT_DISPOSITION']);
/** Do a Range Check **/
if(!empty($headers['ACCEPT_RANGES']))
	header('Accept-Ranges: ' . $headers['ACCEPT_RANGES']);
/** Http Refresh Headers **/
if(isset($headers['HTTP_REFRESH'])){
	$pre=basename(__FILE__) . '?url=';
	header('refresh:'.(int)$headers['refresh'][0].';url='. $pre . $knEncoder->encode($knURL->getAbsolute($headers['refresh'][1])));
}

if(isset($headers['HTTP_COOKIES']) && is_array($headers['HTTP_COOKIES']))
	foreach($headers['HTTP_COOKIES'] as $cookie){
		if($cookie[2]!=''){
			$expires = strtotime($cookie[2]);
			setcookie($cookie[0],$cookie[1],$expires);
		}else{
			setcookie($cookie[0],$cookie[1]);//Session cookie
		}
	}
/** Parsing Process **/
$knParser = new knParser($knURL,$knHTTP->content,$_SCRIPT . '?url=');
$knParser->setMimeType($knHTTP->doctype);
$knParser->setCharset($knHTTP->doctype,$knHTTP->content);
$knParser->setEncoder($knEncoder);
if(defined('KNPROXY_ENCRYPT_PAGE') && KNPROXY_ENCRYPT_PAGE=='true'){
	if($knParser->type=='text/html' || $knParser->type==''){
		$t = '<script language="javascript" type="text/javascript" src="js/denjihou.js"></script>';
		$t.= '<script language="javascript" type="text/javascript">';
		$knParser->set_value('use_page_encryption',true);
		$knParser->parse();
		$key = $knParser->get_value('key','');
		$t.= 'knEncode.setxmkey("' . $key . '");' . "\n";
		$t.= 'knEncode.charset="'. $knParser->charset .'";' . "\n";
		$t.= 'var page = knEncode.decode("' . $knParser->output . '");' . "\n";
		$t.= 'document.write(page);' . "\n";
		$t.= '</script>';
		if(defined('KNPROXY_USE_GZIP') && KNPROXY_USE_GZIP == 'true' && substr_count($_SERVER['HTTP_ACCEPT_ENCODING'], 'gzip') && function_exists('ob_gzhandler')){
			ob_start("ob_gzhandler");
			echo $t;
		}else{
			echo $t;
		}
		exit();
	}
}
$knParser->parse();
if(defined('KNPROXY_USE_GZIP') && KNPROXY_USE_GZIP == 'true' && substr_count($_SERVER['HTTP_ACCEPT_ENCODING'], 'gzip') && function_exists('ob_gzhandler')){
	if(substr($knParser->type,0,5)=='text/'){
		ob_start("ob_gzhandler");
		echo $knParser->output;
	}else
		echo $knParser->output;
}else
	echo $knParser->output;
?>