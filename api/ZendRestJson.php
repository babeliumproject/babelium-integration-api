<?php

/**
 * Babelium Project open source collaborative second language oral practice - http://www.babeliumproject.com
 *
 * Copyright (c) 2011 GHyM and by respective authors (see below).
 *
 * This file is part of Babelium Project.
 *
 * Babelium Project is free software: you can redistribute it and/or modify
 * it under the terms of the GNU General Public License as published by
 * the Free Software Foundation, either version 3 of the License, or
 * (at your option) any later version.
 *
 * Babelium Project is distributed in the hope that it will be useful,
 * but WITHOUT ANY WARRANTY; without even the implied warranty of
 * MERCHANTABILITY or FITNESS FOR A PARTICULAR PURPOSE.  See the
 * GNU General Public License for more details.
 *
 * You should have received a copy of the GNU General Public License
 * along with this program.  If not, see <http://www.gnu.org/licenses/>.
 */

require_once 'Zend/Rest/Server.php';
require_once 'Zend/Json.php';
/**
 * A customized version of Zend's Rest Server class that allows to receive base64 encoded json parameters
 * and return json and xml responses
 *
 * @author Babelium Team
 *
 */
class ZendRestJson extends Zend_Rest_Server
{
	const MOTHER_TONGUE_LEVEL = 7;
	const LEVEL_THRESHOLD = 15;
	const ROLE_INSTRUCTOR = 2;
	const ROLE_LEARNER = 3;

	protected $_faultResult = false;

	private $db;
	private $cfg;
	
	private $allowed_time_skew = 900; //allowed time skew in seconds

	private $r_access_key = '';
	private $r_referer = '';
	private $r_origin = '';
	private $r_ip = '';
	
	private function setHeaders(){
		$this->_headers = array('Content-Type: application/json');
	}

	private function requestHeaders(){
		$this->r_referer = isset($_SERVER['HTTP_REFERER']) ? $_SERVER['HTTP_REFERER'] : '';
		$r_allheaders = function_exists('apache_request_headers') ? apache_request_headers() : null;
		if($r_allheaders && isset($r_allheaders['Origin'])){
			$this->r_origin = $r_allheaders['Origin'];
		}
		if($this->r_referer && ($p = parse_url($this->r_referer))){
			$r_host = $p['host'];
			$ip = gethostbyname($r_host);
			if($ip !== $r_host){
				$this->r_ip = $ip;
			}
		}
	}

	private function initClasses(){
		require_once dirname(__FILE__) . '/../services/utils/Config.php';
		require_once dirname(__FILE__) . '/../services/utils/Datasource.php';
		date_default_timezone_set('UTC');
		$this->cfg = new Config();
		$this->db = new DataSource($this->cfg->host, $this->cfg->db_name, $this->cfg->db_username, $this->cfg->db_password);
	}
	
	/**
	 * Implement Zend_Server_Interface::handle()
	 *
	 * @param  array $request
	 * @throws Zend_Rest_Server_Exception
	 * @return string|void
	 */
	public function handle($request = false)
	{
		$this->setHeaders();
		$this->requestHeaders();
		$this->initClasses();
		if (!$request) {
			$request = $_REQUEST;
		}

		//We can sanitize $_GET and $_POST before using their contents or let each method deal with its parameters' cleanliness.
		$g = array();
		$p = array();
		if(isset($_GET) && count($_GET) > 0){
			$g = $_GET;
		}
		if(isset($_POST) && count($_POST) > 0){
			$p = $_POST;
		}

		//$_POST should always contain at least 'method' and 'header' properties
		if(count($g) == 1 && count($p) > 1){
			$m = array_keys($g);
			$this->_method = $m[0];
			if(isset($this->_functions[$this->_method])){
				if($this->_functions[$this->_method] instanceof Zend_Server_Reflection_Function || $this->_functions[$this->_method] instanceof Zend_Server_Reflection_Method && $this->_functions[$this->_method]->isPublic()){

					//Check if the request is valid					
					try{
						$this->_validateRequest($p);
					

						//Retrieve the request parameters, if any
						$request_params = array();
						if(array_key_exists('parameters',$p)){
							$request_params = $this->_parseParams($p['parameters']);
							$request_keys = array_keys($request_params);
							array_walk($request_keys, array(__CLASS__, "lowerCase"));
							$request_params = array_combine($request_keys, $request_params);
						}
						$func_args = $this->_functions[$this->_method]->getParameters();
						$calling_args = array();
						$missing_args = array();
						foreach($func_args as $arg){
							if(isset($request_params[strtolower($arg->getName())])){
								$calling_args[] = $request_params[strtolower($arg->getName())];
							} elseif( $arg->isOptional()){
								$calling_args[] = $arg->getDefaultValue();
							} else {
								$missing_args[] = $arg->getName();
							}
						}

						foreach ($request_params as $key => $value) {
							if (substr($key, 0, 3) == 'arg') {
								$key = str_replace('arg', '', $key);
								$calling_args[$key] = $value;
								if (($index = array_search($key, $missing_args)) !== false) {
									unset($missing_args[$index]);
								}
							}
						}

						// Sort arguments by key -- @see ZF-2279
						ksort($calling_args);

						$result = false;
						if (count($calling_args) < count($func_args)) {
							require_once 'Zend/Rest/Server/Exception.php';
							$result = $this->fault(new Zend_Rest_Server_Exception('Invalid Method Call to ' . $this->_method . '. Missing argument(s): ' . implode(', ', $missing_args) . '.'), 400);
						}

						if (!$result && $this->_functions[$this->_method] instanceof Zend_Server_Reflection_Method) {
							// Get class
							$class = $this->_functions[$this->_method]->getDeclaringClass()->getName();

							if ($this->_functions[$this->_method]->isStatic()) {
								// for some reason, invokeArgs() does not work the same as
								// invoke(), and expects the first argument to be an object.
								// So, using a callback if the method is static.
								try {
									$result = $this->_callStaticMethod($class, $calling_args);
								} catch(Exception $e) {
									$result = $this->fault($e);
								}
							} else {
								// Object method
								try {
									$result = $this->_callObjectMethod($class, $calling_args);
								} catch(Exception $e){
									$result = $this->fault($e);
								}
							}
						} elseif (!$result) {
							try {
								$result = call_user_func_array($this->_functions[$this->_method]->getName(), $calling_args); //$this->_functions[$this->_method]->invokeArgs($calling_args);
							} catch (Exception $e) {
								$result = $this->fault($e);
							}
						}
					} catch (Exception $e){
						$result = $this->fault($e);
					}	
				} else {
					require_once "Zend/Rest/Server/Exception.php";
					$result = $this->fault(new Zend_Rest_Server_Exception("Unknown Method '$this->_method'."),404);
				}
			} else {
				require_once "Zend/Rest/Server/Exception.php";
				$result = $this->fault(new Zend_Rest_Server_Exception("Unknown Method '$this->_method'."),404);
			}
		} else {
			require_once "Zend/Rest/Server/Exception.php";
			$result = $this->fault(new Zend_Rest_Server_Exception("Malformed request."),400);
		}
		
		if (!$this->_faultResult){
			if (is_array($result) || is_object($result)) {
				$response = $this->_handleStruct($result);
			} else {
				$response = $this->_handleScalar($result);
			}
		} else {
			$response = $result;
		}

		$response = Zend_Json::encode($response,false);
		$response = preg_replace_callback('/\\\\u([0-9a-f]{4})/i', create_function('$match', 'return mb_convert_encoding(pack("H*", $match[1]), "UTF-8", "UCS-2BE");'), $response);

		if (!$this->returnResponse()) {
			if (!headers_sent()) {
				foreach ($this->_headers as $header) {
					header($header);
				}
			}
			echo $response;
			return;
		}

		return $response;
	}

	public function fault($exception = null, $code = null)
	{
		if (isset($this->_functions[$this->_method])) {
			$function = $this->_functions[$this->_method];
		} elseif (isset($this->_method)) {
			$function = $this->_method;
		} else {
			$function = 'unknown';
		}

		if ($function instanceof Zend_Server_Reflection_Method) {
			$class = $function->getDeclaringClass()->getName();
		} else {
			$class = false;
		}

		if ($function instanceof Zend_Server_Reflection_Function_Abstract) {
			$method = $function->getName();
		} else {
			$method = $function;
		}

		$json = array();
                $json['header']['method'] = $method;
                //$json['header']['session'] = session_id();


		if ($exception instanceof Exception) {
			$json['response'] = array("message" => $exception->getMessage());
			$code = $exception->getCode();
			$message = $exception->getMessage();
		} elseif (($exception !== null) || 'rest' == $function) {
			$json['response'] = array("message" => 'An unknown error occurred. Please try again.');
		} else {
			$json['response'] = array("message" => 'Call to ' . $method . ' failed.');
		}

		$json['status'] = 'failure';
			
		// Headers to send
		if ($code === null){
			//Service exception or unknown exception. Send general 400 Bad Request header to client
			$this->_headers[] = 'HTTP/1.0 400 Bad Request';
		} else {
			switch($code){
				case 403:
					$this->_headers[] = empty($message) ? 'HTTP/1.0 403 Forbidden' : 'HTTP/1.0 403 '.$message;
					break;
				case 404:
					$this->_headers[] = empty($message) ? 'HTTP/1.0 404 File Not Found' : 'HTTP/1.0 404 '.$message;
					break;
				case 500:
					$this->_headers[] = empty($message) ? 'HTTP/1.0 500 Internal Server Error' : 'HTTP/1.0 500 '.$message;
					break;
				case 400:
					$this->_headers[] = empty($message) ? 'HTTP/1.0 400 Bad Request' : 'HTTP/1.0 400 '.$message;
					break;
				default:
					$this->_headers[] = 'HTTP/1.0 400 Bad Request';
					break;
			}
		}
		$this->_faultResult = true;

		//Log the fault in the server
		$message = empty($message) ? implode(",", $json['response']) : $message;
		error_log("[".date("d/m/Y H:i:s")."] referer=".$this->r_referer." origin=".$this->r_origin." ip=".$this->r_ip." access_key=".$this->r_access_key." message=".$message."\n",3,"/var/www/vhosts/babelium/logs/moodle.log");	

		return $json;
	}

	/**
	 * Handle an array or object result
	 *
	 * @param array|object $struct Result Value
	 * @return string XML Response
	 */
	protected function _handleStruct($struct)
	{
		$function = $this->_functions[$this->_method];
		$method = $function->getName();
		 
		$json = array();
		$json['header']['method'] = $method;
		//$json['header']['session'] = session_id();

		if(isset($struct)){
			if((is_array($struct) && count($struct) >= 1) || is_object($struct)){
				$json['response'] = (object) $struct;
			}
		} else {
			$json['response'] = null;
		}

		$json['status'] = 'success';

		return $json;
	}

	protected function _handleScalar($value)
	{
		$function = $this->_functions[$this->_method];
		$method = $function->getName();
		 
		$json = array();
		$json['header']['method'] = $method;
		//$json['header']['session'] = session_id();

		 
		if ($value === false) {
			$value = 0;
		} elseif ($value === true) {
			$value = 1;
		}

		if (isset($value)) {
			$json['response'] = $value;
		} else {
			$json['response'] = null;
		}

		$json['status'] = 'success';

		return $json;
	}

	protected function _parseParams($parameters){
		$result_parameter = $parameters;
		//This means we have more than one parameter and thus, we want it to be an object
		if(is_array($result_parameter) && count($result_parameter) > 1){
			$object_parameter = (object)$parameters;
			//$result_parameter = $object_parameter;
			$result_parameter = array('arg'=>$object_parameter);
		}
		return $result_parameter;
	}
	
	/**
	 * Validates the Headers of the request
	 *
	 * @param mixed $request
	 * @throws Exception when the headers have an incorrect format
	 * @return bool $result
	 */
	protected function _validateRequest($request){
		$request_method = array();
		$request_header = array();
		$result = FALSE;
		//Check if minimum required fields are present in the request
		if( array_key_exists('method',$request) && array_key_exists('header',$request) ){
			$request_method = $request['method'];
			$request_header = $request['header'];
			if( ($this->_method == $request_method) && array_key_exists('authorization', $request_header) && array_key_exists('date', $request_header) && array_key_exists('lisdata', $request_header)){
				$client_authorization = trim($request_header['authorization']);
				$client_authorization = str_replace(array("\r","\n","\t"), '', $client_authorization);
				$client_date = trim($request_header['date']);
				$client_lis_data = unserialize(trim($request_header['lisdata']));
				if( preg_match('/BMP ([^:]+):(.+)/s', $client_authorization, $matches) ){
					$client_access_key = $matches[1];
					$this->r_access_key = $client_access_key;
					$client_signature = $matches[2];
					$consumer_id = $this->_validateAuthorization($client_access_key, $client_signature, $client_date, $request_method);
					$consumer_user_id = $this->_validateLisData($client_lis_data, $consumer_id);
					//Pass the data to the service subset
					//PluginSubset::$credentials = array('consumer_id'=>$consumer_id,'consumer_user_id'=>$consumer_user_id);
					if(!isset($_SESSION)){
    					session_start();
					}
					$_SESSION['consumer_id'] = $consumer_id;
					$_SESSION['consumer_user_id'] = $consumer_user_id;
				} else {
					throw new Exception("Malformed authorization header",400);
				}
				
			} else {
				throw new Exception("Missing headers",400);
			}
		} else {
			throw new Exception("Malformed request",400);
		}
		return $result;
	}
	
	/**
	 * Validates the Authorization header sent by the consumer
	 *
	 * @param String $cl_access_key
	 * @param String $cl_signature
	 * @param String $cl_date
	 * @param String $r_method
	 * @throws Exception when invalid credentials are provided, header is malformed, request date is 
	 * 					 too skewed or consumer_key is not found in the storage
	 * @return int $consumer_id
	 */
	private function _validateAuthorization($cl_access_key, $cl_signature, $cl_date, $r_method){
		if(!$cl_access_key || !$cl_signature || !$cl_date || !$r_method )
			throw new Exception("Malformed authorization header",400);
		
		//Date header check
		//$s_date = date(DATE_RFC1123);
		$s_timestamp = time();	
		if(($cl_timestamp = strtotime($cl_date)) == FALSE)
			throw new Exception("Malformed date header",400);
			
		//Check if the provided access key is registered in our database and if the request is coming from the expected referer
		try{
			//Query the DB for the provided accessKey
			$tablename = 'serviceconsumer';
			$sql = "SELECT id, access_key, secret_access_key, domain FROM %s WHERE access_key = '%s' AND enabled=1 AND id>1";
			$service_consumer = $this->db->_singleSelect($sql,$tablename,$cl_access_key);
		} catch(Exception $e){
			throw new Exception("Not found",404);
		}
		if($service_consumer){
			$s_secret_access_key = $service_consumer->secret_access_key;
			$s_access_key = $service_consumer->access_key;
			$s_referer = $service_consumer->domain;
		} else {
			throw new Exception("Invalid credentials provided",403);
		}
	
		//Check if the request is skewed in time to avoid replication
		if( $cl_timestamp > ($s_timestamp - $this->allowed_time_skew) &&
		    $cl_timestamp < ($s_timestamp + $this->allowed_time_skew) ){
			
			$referer_pieces = parse_url($s_referer);
		    $s_stringtosign = utf8_encode($r_method . "\n" . $cl_date . "\n" . $referer_pieces['host']);
			$digest = hash_hmac("sha256", $s_stringtosign, $s_secret_access_key, false);
			$s_signature = base64_encode($digest);
			
			if ($cl_signature == $s_signature){
				$consumer_id = $service_consumer->id;
				return $consumer_id;
			} else {
				throw new Exception("Invalid signature",403);
			}
		}else{
			throw new Exception("Request date is too skewed",403);
		}
	}

	/**
	 * Validates the additional data sent by the LIS
	 *
	 * @param mixed $params
	 * @param int $consumerid
	 * @return int $userid
	 */
	private function _validateLisData($params, $consumerid){
		//$params['resource_link_id'];
		//$params['resource_link_title'];
		//$params['resource_link_description'];
		$uid = $params['user_id'];
		$role = $params['roles'];
		$idnumber = $params['context_id'];
		$shortname = $params['context_label'];
		$title = $params['context_title'];
		$locale = $params['launch_presentation_locale'];
		$firstname = $params['lis_person_name_given'];
		$lastname = $params['lis_person_name_family'];
		$email = $params['lis_person_contact_email_primary'];

		$username = substr(strtolower(str_replace(' ', '', $firstname)),0,1) . strtolower(str_replace(' ', '', $lastname)) . $uid;

		$courseid = $this->_getConsumerCourseId($consumerid, $idnumber, $shortname, $title, $locale);
		$userid = $this->_getConsumerUserId($consumerid, $username, $firstname, $lastname, $email, $courseid, $role, $locale);
		
		return $userid;
	}

	/**
	 * Get the course ID for the given LIS data
	 * 
	 * @param int $consumerid
	 * @param String $shortname
	 * @param String $fullname
	 * @param String $lang
	 * @return int $course_id
	 */
	private function _getConsumerCourseId($consumerid, $idnumber, $shortname, $fullname, $lang){
		$course_id = -1;
 		$sql = "SELECT id FROM course WHERE shortname='%s' AND fk_serviceconsumer_id=%d";
 		$course = $this->db->_singleSelect($sql, $shortname, $consumerid);
 		if(!$course){
 			$sql_courseinsert = "INSERT INTO course (category,fullname,fk_serviceconsumer_id,idnumber,shortname,format,startdate,visible,language,timecreated,timemodified) VALUES (%d,'%s',%d,%d,'%s','%s',%d,%d,'%s',%d,%d)";
 			$course_id = $this->db->_insert($sql_courseinsert,0,$fullname,$consumerid,$idnumber,$shortname,'topics',time(),1,$lang,time(),time());
 		} else {
 			$course_id = $course->id;
 		}
 		return $course_id;
	}

	/**
	 * Get the user ID for the given LIS data
	 *
	 * @param
	 * @return int $user_id
	 *
	 * @throws Exception if the data couldn't be added to the database
	 */
	private function _getConsumerUserId($consumerid, $username, $firstname, $lastname, $email, $courseid, $roles, $lang){
		$user_id = -1;
		$user_active = 0;
		$role_id = $this->_helperIsInstructor($roles) ? self::ROLE_INSTRUCTOR : self::ROLE_LEARNER;
		//Compute a pseudo-random password
		$password = $this->_helperGenerateHash();
		$activationhash = $this->_helperGenerateHash(20);
		$hashed_password=sha1($password);
		$tablename = 'user';
		$sql_userselect = "SELECT * FROM %s WHERE fk_serviceconsumer_id=%d AND username='%s'";
		$user = $this->db->_singleSelect($sql_userselect,$tablename,$consumerid,$username);
		if(!$user){
			try {
				$this->db->_startTransaction();
				
				$sql_userinsert = "INSERT INTO %s (username,fk_serviceconsumer_id,password,firstname,lastname,email,active,activation_hash,creditCount) VALUES ('%s',%d,'%s','%s','%s','%s',%d,'%s',%d)";
				$user_id = $this->db->_insert($sql_userinsert,$tablename,$username,$consumerid,$hashed_password,$firstname,$lastname,$email,0,$activationhash,10000);

				//Give the user a default language
				$default_locale = empty($lang) ? 'en_US' : $this->_helperParseLocale($lang);
				$sql_localeinsert = "INSERT INTO user_languages (fk_user_id, language, level, purpose, positives_to_next_level) VALUES (%d, '%s',%d,'%s',%d)";
				$user_locale_id = $this->db->_insert($sql_localeinsert, $user_id, $default_locale, self::MOTHER_TONGUE_LEVEL,'evaluate',self::LEVEL_THRESHOLD);

				if($user_id && $user_locale_id){
					$this->db->_endTransaction();
				} else {
					throw new Exception("Database error");
				}
				
			} catch (Exception $e) {
				$this->db->_failedTransaction();
				throw new Exception("Server Internal error", 500);
			}
		} else {
			$user_id = $user->id;
			$user_active = $user->active;
		}

		//Give the user a role in the course
		$sql_courseroleselect = "SELECT * FROM rel_course_role_user WHERE fk_role_id=%d AND fk_course_id=%d AND fk_user_id=%d";
		$courserole = $this->db->_singleSelect($sql_courseroleselect,$role_id,$courseid,$user_id);
		if(!$courserole){
			$sql_courseroleinsert = "INSERT INTO rel_course_role_user (fk_role_id,fk_course_id,fk_user_id,timemodified) VALUES (%d,%d,%d,%d)";
			$crole = $this->db->_insert($sql_courseroleinsert,$role_id,$courseid,$user_id,time());
		}
		$sql_wasinstructorbefore = "SELECT COUNT(*) as insroles FROM rel_course_role_user WHERE fk_role_id=%d AND fk_user_id=%d";
		$instructorcourseroles = $this->db->_singleSelect($sql_wasinstructorbefore, self::ROLE_INSTRUCTOR, $user_id);
		$insroles = $instructorcourseroles ? $instructorcourseroles->insroles : 0; 

		//Send an email with the access credentials if the request role is Instructor the user is not enabled and 
		//the course role was inserted in this request (which should happen only once)
		if($role_id==self::ROLE_INSTRUCTOR && !$user_active && $crole && $insroles==1){
			$sql_userupdate = "UPDATE user SET password='%s', activation_hash='%s' WHERE id=%d";
			$this->db->_update($sql_userupdate, $hashed_password, $activationhash, $user_id);
			$this->_helperSendUserEmail($username,$password,$lang,$activationhash);
		}


		return $user_id;
	}

	/**
	 * Parse the language code sent in the request and add a region code when it is not specified
	 *
	 * @param String $locale
	 * @return String $parsed_locale
	 */
	private function _helperParseLocale($locale){
		$parsed_locale='en_US';
		$region=null;
		$available_languages=array('en_US','es_ES','eu_ES','fr_FR, de_DE');
		if($locale){
			$parts = explode("_", $locale);
			if (count($parts) > 0){
				$language = $parts[0];
				if (count($parts) > 1)
					$region = strtoupper($parts[1]);
				foreach ($available_languages as $l){
					$lparts = explode("_", $l);
					if($region){
						if($lparts[0] == $language && $lparts[1] == $region){
							$parsed_locale = $l;
							break;
						}
					} else{
						if($lparts[0] == $language){
							$parsed_locale = $l;
							break;
						}
					}
				}
			}	
		}
		return $parsed_locale;
	}

	/**
	 * Check if the request user has an intructor role in the provided context
	 *
	 * @param mixed $roles
	 * @return bool
	 */
	private function _helperIsInstructor($roles) {
        $troles = strtolower($roles);
        if ( ! ( strpos($troles,"instructor") === false ) ) return true;
        if ( ! ( strpos($troles,"administrator") === false ) ) return true;
        return false;
    }

	/**
	 * Generate a pseudo-random hash of the specified length using the provided alphabet
	 *
	 * @param String $alphabet
	 * @param int $length
	 * @return String $hash
	 */
	private function _helperGenerateHash($length=10, $alphabet='abcdefghijklmnopqrstuvwxyzABCDEFGHIJKLMNOPQRSTUVWXYZ0123456789'){
		$hash = "";
		// Generate Hash
		for ( $i = 0; $i < $length; $i++ )
		$hash .= substr($alphabet, rand(0, strlen($alphabet)-1), 1);
		return $hash;
	}

	/**
	 * Send the new user an email with the user's data
	 * @param String $username
	 * @param String $password
	 * @param String $lang
	 * @param String $activationhash
	 *
	 * @throws Exception if unable to create the mail body template
	 */
	private function _helperSendUserEmail($username, $password, $lang, $activationhash){
		//TODO delegate this task to task queue to avoid lag in the calls
		require_once dirname(__FILE__) . '/../services/utils/Mailer.php';
		
		$mail = new Mailer($username);
		$activation_link = htmlspecialchars('http://'.$_SERVER['HTTP_HOST'].'/Main.html#/activation/activate/hash='.$activationhash.'&user='.$username);
		$subject = 'Babelium Project: Account Data';
		$args = array(
			'PROJECT_NAME' => 'Babelium Project',
			'USERNAME' => $username,
			'PASSWORD' => $password,
			'PROJECT_SITE' => 'http://'.$_SERVER['HTTP_HOST'],
			'ACTIVATION_LINK' => $activation_link);

		if ( !$mail->makeTemplate("external_mail_activation", $args, $lang) )
			throw new Exception("Mail sending", 500);

		$mail = $mail->send($mail->txtContent, $subject, $mail->htmlContent);
	}

}
?>
