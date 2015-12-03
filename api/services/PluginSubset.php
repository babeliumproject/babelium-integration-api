<?php


require_once dirname(__FILE__) . '/../../services/utils/Config.php';
require_once dirname(__FILE__) . '/../../services/utils/Datasource.php';
require_once dirname(__FILE__) . '/../../services/utils/VideoProcessor.php';
require_once dirname(__FILE__) . '/../../services/Search.php';

require_once 'Zend/Json.php';

/**
 * API subset that can be accessed through the Moodle plug-in
 */
class PluginSubset{

	//Configuration value object
	private $cfg;
	
	//Database interaction object
	private $conn;

	//Multimedia file handling object
	private $mediaHelper;

	const EXERCISE_READY=1;
	const EXERCISE_DRAFT=0;

	public static $userId;

	public function __construct(){
		try{
			$this->cfg = new Config();
			$this->conn = new Datasource($this->cfg->host, $this->cfg->db_name, $this->cfg->db_username, $this->cfg->db_password);
			$this->mediaHelper = new VideoProcessor();
		} catch(Exception $e){
			throw new Exception($e->getMessage());
		}
	}

	// SUBTITLE.PHP //
	
	/**
	 * Retrieves the subtitle lines associated to a subtitle ID or an exercise ID.
	 * @param stdClass $subtitle
	 * 		The ID of a subtitle version or an exercise.
	 * @return bool|stdClass
	 * 		The parsed subtitle lines or false if the subtitles were not found or were not parsed.
	 */
	public function getSubtitleLines($subtitle=null) {
		if(!$subtitle)
			return false;

		$subtitleId = isset($subtitle->id) ? $subtitle->id : 0;
		$exerciseId = isset($subtitle->exerciseId) ? $subtitle->exerciseId : 0;
		//$language = isset($subtitle->language) ? $subtitle->language : NULL;

		if(!$subtitleId){
			$mediaId=0;
			$subtitle = NULL;

			$medialist = $this->getExerciseMedia($exerciseId,2,1);
			
			if($medialist){
				$mediaId=$medialist[0]->id;
			}
			if($mediaId){
            	//Get the latest subtitle version for this exercise
            	$sql = "SELECT * FROM subtitle WHERE id = (SELECT MAX(id) FROM subtitle WHERE fk_media_id=%d)";

            	$subtitle = $this->conn->_singleSelect($sql, $mediaId);
        	}
        } else {
            $sql = "SELECT * FROM subtitle WHERE id=%d";
            $subtitle = $this->conn->_singleSelect($sql, $subtitleId);
        }   

        $parsed_subtitles = $this->parseSubtitles($subtitle);

        return $parsed_subtitles;

	}

	/**
	 * Unserializes and uncompresses the given data.
	 * @param string $data
	 * 		Base64 encoded and compressed data.
	 * @return string
	 * 		The decoded string or the original parameter if any decoding step was unsuccessful.
	 */
	private function unpackblob($data){
        if(($decoded = base64_decode($data)) !== FALSE){
            if(($plaindata = gzuncompress($decoded)) !== FALSE){
                return $plaindata;
            }
        }
        return $data;
    }
    
    /**
     * Unpacks and converts serialized subtitle lines to a previous format.
     * @param stdClass $subtitle
     * 		The subtitle lines that need parsing.
     * @return bool|stdClass
     * 		The parsed subtitle lines or false if the lines could not be parsed.
     */
    private function parseSubtitles($subtitle){
        $parsed_subtitles = FALSE;
        if($subtitle){
            $serialized = $this->unpackblob($subtitle->serialized_subtitles);
            $subtitles = Zend_Json::decode($serialized);
            $parsed_subtitles = array();
            $distinct_voices = array();
            foreach($subtitles as $num => $data){
                $sline = new stdClass();
                $sline->id = $num;
                $sline->showTime = $data['start_time'] / 1000;
                $sline->hideTime = $data['end_time'] / 1000;
                $sline->text = $data['text'];
        
                $sline->exerciseRoleName = $data['meta']['voice'];
                $sline->subtitleId = $subtitle->id;
        
                //Add an id to the voice
                $c = count($distinct_voices);
                if (!array_key_exists($data['meta']['voice'],$distinct_voices)){
                    $distinct_voices[$data['meta']['voice']] = $c+1;
                }
                $sline->exerciseRoleId = $distinct_voices[$data['meta']['voice']];
        
                $parsed_subtitles[] = $sline;
            }
        }
        return $parsed_subtitles;
    }

	// EXERCISE.PHP //
	
    /**
     * Returns the exercises that can be consumed by this API key and meet the given
     * search criteria.
     * @param stdClass $data
     * 		The filters and/or search criteria used to narrow the results.
     * @return void|false|stdClass
     * 		The exercises that meet the selected criteria, void or false if the user ID is not set or no matching exercises are found.
     */
	public function getRecordableExercises($data=null){
		$where = "";
		$userId = self::$userId;
		if ($userId)
			//$where = " AND u.ID = ". $userId . " " ;
			//sprintf() if you're going to use % as a character scape it putting it twice %%, otherwise there'll be problems while parsing your string
			$where = " AND ( u.id = ". $userId ." OR (e.license like 'cc-%%' )) ";
		else
			return;

		$sql = "SELECT e.id, e.title, e.description, e.language, e.exercisecode as name, 
					   from_unixtime(e.timecreated) as addingDate, u.username as userName, 
					   e.difficulty as avgDifficulty, e.status, e.likes, e.dislikes, e.type, e.situation, e.competence, e.lingaspects
				FROM exercise e INNER JOIN user u ON e.fk_user_id= u.id WHERE e.status = 1 AND e.visible=1";
		
		$q = isset($data->q) && strlen($data->q) ? $data->q : null;
		//$sort = isset($data->sort) ? $data->sort : null;
		$lang = isset($data->lang) ? $data->lang : null;
		$difficulty = isset($data->difficulty) ? $data->difficulty : 0;
		$type = isset($data->type) ? $data->type : -1;
		$situation = isset($data->situation) ? $data->situation : 0;
		
		if($q){
			$search = new Search();
			$exidarray = $search->launchSearch($q);
			if(count($exidarray)){
				$exids = implode($exidarray,',');
				$sql .= " AND e.id IN (%s) ";
				$sql .= " ORDER BY e.title ASC, e.language ASC";
				$searchResults = $this->conn->_multipleSelect($sql,$exids);
			} else {
				$searchResults=null;	
			}
		} else {
			$sql .= " ORDER BY e.title ASC, e.language ASC";
			$searchResults = $this->conn->_multipleSelect($sql);
		}
		
		if($searchResults){
			$filtered = $searchResults;
			if($lang) 
				$filtered = $this->filterByLang($filtered, $lang);
			if($difficulty) 
				$filtered = $this->filterByDifficulty($filtered, $difficulty);
			if($type>-1) 
				$filtered = $this->filterByType($filtered, $type);
			if($situation) 
				$filtered = $this->filterBySituation($filtered, $situation);
		
			if($filtered){
				foreach($filtered as $r){
					$data = $this->getPrimaryMediaMinData($r->id);
					$r->thumbnail = $data ? $data->thumbnail : null;
					$r->duration = $data ? $data->duration : 0;
					$r->tags = $this->getExerciseTags($r->id);
					$r->descriptors = $this->getExerciseDescriptors($r->id);
				}
			}
			$searchResults = $filtered;
		}

		return $searchResults;

	}

	/**
	 * Filters the exercises by language
	 * @param array $list
	 * 		The exercise list to be filtered
	 * @param string $lang
	 * 		The language code by wich the list is filtered
	 * @return void|array
	 * 		The filtered list or void if the language or the list are not set.
	 */
	private function filterByLang($list, $lang){
		if(!$lang || !$list) return;
		$result = array();
		foreach($list as $e){
			if(strpos($e->language, $lang) !== false){
				array_push($result, $e);
			}
		}
		return $result;
	}
	
	/**
	 * Filters an exercise list by difficulty
	 * @param array $list
	 * 		The exercise list to be filtered.
	 * @param int $difficulty
	 * 		A difficulty level in the range [0-4] that correlates to [A1,A2,B1,B2,C1]
	 * @return void|array
	 * 		The filtered exercise list or void when the list or the difficulty are not set
	 */
	private function filterByDifficulty($list, $difficulty){
		if($difficulty==-1 || !$list) return;
		$result = array();
		foreach($list as $l){
			if($l->difficulty==$difficulty){
				array_push($result,$l);
			}
		}
		return $result;
	}
	
	/**
	 * Filters an exercise list by exercise-type
	 * @param array $list
	 * 		The exercise list to be filtered.
	 * @param int $type
	 * 		An exercise type in the range [0-5]
	 * @return void|array
	 * 		The filtered exercise list or void when the list or the type are not set.
	 */
	private function filterByType($list, $type){
		if($type==-1 || !$list) return;
		$result = array();
		foreach($list as $l){
			if($l->type == $type){
				array_push($result,$l);
			}
		}
		return $result;
	}
	
	/**
	 * Filters an exercise list by situation.
	 * @param array $list
	 * 		The exercise list to be filtered.
	 * @param int $situation
	 * 		A communication situation in the range [1-3]
	 * @return void|array
	 * 		The filtered exercise list or void when the list or the situation are not set.
	 */
	private function filterBySituation($list, $situation){
		if(!$situation || !$list) return;
		$result = array();
		foreach($list as $l){
			if($l->situation == $situation){
				array_push($result,$l);
			}
		}
		return $result;
	}

	/**
	 * Returns the descriptors of the provided exercise.
	 * @param int $exerciseId
	 * 		The exercise id to check for descriptors
	 * @return mixed $dcodes
	 * 		An array of descriptor codes. False when the exercise has no descriptors at all.
	 */
	private function getExerciseDescriptors($exerciseId){
		if(!$exerciseId)
			return false;
		$dcodes = false;
		$sql = "SELECT ed.* FROM rel_exercise_descriptor red INNER JOIN exercise_descriptor ed ON red.fk_exercise_descriptor_id=ed.id 
				WHERE red.fk_exercise_id=%d";
		$results = $this->conn->_multipleSelect($sql,$exerciseId);
		if($results && count($results)){
			$dcodes = array();
			foreach($results as $result){
					$dcode = sprintf("D%d_%d_%02d_%d", $result->situation, $result->level, $result->competence, $result->number);
					$dcodes[] = $dcode;
			}
			unset($result);
		}
		return $dcodes;
	}
	
	
	/**
	 * Returns the tags that were defined for the specified exercise
	 * 
	 * @param int $exerciseid
	 * 		The exercise id whose tags you want to retrieve
	 * @return mixed $tags
	 * 		An array of tags or false when no tags are defined for the specified exercise
	 */
	private function getExerciseTags($exerciseid){
		if(!$exerciseid) return;
		$tags = '';
		$sql = "SELECT t.name FROM tag t INNER JOIN rel_exercise_tag r ON t.id=r.fk_tag_id WHERE r.fk_exercise_id=%d";
		$results = $this->conn->_multipleSelect($sql, $exerciseid);
		if($results){
			$tags = array();
			foreach($results as $tag){
				$tags[] = $tag->name;
			}
		}
		return $tags;
	}
	
	/**
	 * Returns the minimum required data to play the media associated with an exercise.
	 * @param int $exerciseid
	 * 		An exercise ID
	 * @return void|stdClass
	 * 		Returns the data of the media associated to the exercise or void when the exercise ID is not set or is not found.
	 */
	private function getPrimaryMediaMinData($exerciseid){
		if(!$exerciseid) return;
		$data = false;
		$media = $this->getExerciseMedia($exerciseid, 2, 1);
		if($media && count($media)==1){
			$data = new stdClass();
			
			$thumbdir = '/resources/images/thumbs';
			$thumbnum = $media[0]->defaultthumbnail;
			$mediacode = $media[0]->mediacode;
			
			$t =  $thumbdir . '/' . $mediacode . '/%02d.jpg';
			$fragment = sprintf($t, $thumbnum);
			
			$data->thumbnail = $fragment;
			$data->duration = $media[0]->duration;
		}
		return $data;
	}

	/**
	 * Retrieves the media associated to the specified exercise.
	 * 
	 * @param int $exerciseid
	 * 		The exercise id whose media you want to retrieve
	 * @param int $status
	 * 		The status of the media you want to retrieve. Possible values are:
	 * 			0: Raw media. Format and dimensions are not consistent
	 * 			1: Encoding media. Media that is currently being encoded to follow standard formats and dimensions
	 * 			2: Encoded media. Media with consistent format and dimensions
	 * 			3: Duplicated media. Media with contenthash already present in the system
	 * 			4: Corrupt media. Media that can't be displayed or read correctly.
	 * 			5: Deleted media. Media that is marked as deleted and will be removed periodically.
	 * @param int $level
	 * 		The level of the media you want to retrieve. Possible values are:
	 * 			0: Undefined. This media has not been assigned a level as of yet.
	 * 			1: Primary. This media is the primary file of the instance and displayed by default.
	 * 			2: Model. This media is a model associated to a primary media.
	 * 			3: Attempt. This media is a submission done following some instance.
	 * 			4: Rendition. This media is a rendition (different dimension version) of a primary media.
	 * @return mixed $results
	 * 		An array of objects with data about the media or false when matching media is not found
	 */
	private function getExerciseMedia($exerciseid, $status, $level){
		if(!$exerciseid) return;
		
		$component = 'exercise';
		
		$sql = "SELECT m.id, m.mediacode, m.instanceid, m.component, m.type, m.duration, m.level, m.defaultthumbnail, mr.status, mr.filename
				FROM media m INNER JOIN media_rendition mr ON m.id=mr.fk_media_id
				WHERE m.component='%s' AND m.instanceid=%d";
		
		if(is_array($status)){
			if(count($status)>1){
				$sparam = implode(",",$status);
				$sql.=" AND mr.status IN (%s) ";
			} else {
				$sparam = $status[0];
				$sql.=" AND mr.status=%d ";
			}	
		} else {
			$sparam=$status;
			$sql.=" AND mr.status=%d ";
		}
		
		if(is_array($level)){
			if(count($level)>1){
				$lparam = implode(",",$level);
				$sql.=" AND m.level IN (%s) ";
			} else {
				$lparam = $level[0];
				$sql.=" AND m.level=%d ";
			}
		} else {
			$lparam = $level;
			$sql.=" AND m.level=%d ";
		}
		
		
		$results = $this->conn->_multipleSelect($sql, $component, $exerciseid, $sparam, $lparam);
		if($results){
			foreach($results as $r){
				$r->netConnectionUrl = $this->cfg->streamingserver;
				$r->mediaUrl = 'exercises/'.$r->filename;
			}
		}
		return $results;
	}

	/**
	 * Returns the exercises' data and the data of its media
	 * @param int $id
	 * 		The requested exercise Id
	 * @return void|stdClass
	 * 		Returns the exercise data and the media data or void if the exercise ID is not set.
	 */
	public function getExerciseById($id = 0){
		if(!$id)
			return;

		$exdata = $this->exerciseDataById($id,self::EXERCISE_READY);
		if($exdata){
			$status = 2;
			$level = 1; //Plugin does not support model recording
			$media = $this->getExerciseMedia($exdata->id, $status, $level);
			if($media){
				$exdata->media = $media;
				//$primarymedia = $media[0];
				//$exdata->name = substr($primarymedia->filename, 0, -4); //remove extension
				//$exdata->thumbnailUri = sprintf("%02d",$primarymedia->defaultthumbnail).'.jpg';
				//$exdata->duration = $primarymedia->duration;
			}
			
		}

		return $exdata;
	}
	
	/**
	 * Makes a 'fake' request to the media server to assign a media recording slot
	 * @return stdClass
	 * 		The data of the recording slot for the media server
	 */
	public function requestRecordingSlot(){
		$prefix = "resp-";
		$optime = round(microtime(true)*1000); //ms precision to mimic client-side behaviour.
		$mediadir = 'responses';
		$mediafilename = $prefix.$optime.'.flv';
				
		$mediaUrl = $mediadir.'/'.$mediafilename;
				
		$data = new stdClass();
		$data->mediaUrl = $mediaUrl;
		$data->netConnectionUrl = $this->cfg->streamingserver;
		$data->maxDuration = 600;
		
		return $data;
	}

	/**
	 * Returns the data of the requested exercise
	 * @param int $exerciseid
	 * 		The ID of the exercise
	 * @param int $status
	 * 		The status of the exercise in the range [0,1]: Visible, Private
	 * @return void|stdClass
	 * 		The exercise data or void when exercise ID is not set or is not found
	 */
	private function exerciseDataById($exerciseid,$status=0){
		if(!$exerciseid) return;

		$sql = "SELECT e.id, 
					   e.title, 
					   e.description, 
					   e.language, 
					   e.exercisecode, 
       				   from_unixtime(e.timecreated) as addingDate, 
       				   u.username as userName, 
       				   e.difficulty as avgDifficulty, 
       				   e.status, 
       				   e.likes,
       				   e.dislikes,
       				   e.type,
					   e.competence, e.situation, e.lingaspects, e.licence, e.attribution, e.visible
				FROM   exercise e INNER JOIN user u ON e.fk_user_id= u.id
       			WHERE e.id = %d";
       	if($status){
			$cstatus = $status ? 1 : 0;
			$sql .= " AND e.status=%d LIMIT 1";
			$result = $this->conn->_singleSelect($sql,$exerciseid,$cstatus);
		} else {
			$sql .= " LIMIT 1";
			$result = $this->conn->_singleSelect($sql,$exerciseid);
		}
		if($result){
			$result->tags = $this->getExerciseTags($result->id);
			$result->descriptors = $this->getExerciseDescriptors($result->id);
		}
		return $result;
	}

	public function getExerciseLocales($exerciseId=0) {
		if(!$exerciseId)
			return false;

		$sql = "SELECT DISTINCT language as locale FROM exercise
				WHERE id = %d";

		$results = $this->conn->_multipleSelect ( $sql, $exerciseId );

		return $results; // return languages
	}

	// RESPONSE.PHP //
	
	/**
	 * Saves the user's submission (response) and returns the response ID and thumbnail URLs of the recording
	 * @param stdClass $data
	 * 		The submission data that needs to be checked and saved
	 * @throws Exception
	 * 		When the given data is not valid, the user ID is not set, the script has not permissions to write in the thumbnail
	 *      or poster folders, the exercise is no longer available and therefore has not associated media, the recording file
	 *      is not found on the filesystem or symbolic links for the images cannot be created.
	 * @returns bool|stdClass
	 * 		The response ID and the thumbnail URL of the submission
	 */
	public function admSaveResponse($data){
		$userId = self::$userId;
		
		if(!$data || !isset($data->mediaUrl))
			throw new Exception("Invalid parameters",1000);
		if(!$userId)
			throw new Exception("Invalid UserID",1001);
		
		$recordingUrl = $data->mediaUrl;
		$responsecode = substr($recordingUrl,strrpos($recordingUrl,'/')+1,-4);
		
		set_time_limit(0);
		//$this->_getResourceDirectories();
		$thumbnail = 'default.jpg';
		$source= 'Red5';
		
		try{
			$mediaId=0;
			$medialist = $this->getExerciseMedia($data->exerciseId,2,1);
			if($medialist){
				$mediaId=$medialist[0]->id;
			}
			if(!$mediaId){
				throw new Exception("Can't find any media associated with this exercise");
			}

			$videoPath = $this->cfg->red5Path .'/'. $recordingUrl;
			$mediaData = $this->mediaHelper->retrieveMediaInfo($videoPath);
			$duration = $mediaData->duration;

			if($mediaData->hasVideo){
				$thumbdir = $this->cfg->imagePath.'/'.$responsecode;
				$posterdir = $this->cfg->posterPath.'/'.$responsecode;
				$this->mediaHelper->takeFolderedRandomSnapshots($videoPath, $thumbdir, $posterdir);
				//The videoprocessor no longer generates softlinks to 'default.jpg'
				@symlink($thumbdir.'/01.jpg',$thumbdir.'/default.jpg');
				@symlink($posterdir.'/01.jpg',$posterdir.'/default.jpg');
			} else {
				//Make a folder with the same hash as the audio-only response and link to the parent folder's nothumb.png
				$thumbdir = $this->cfg->imagePath . '/' . $responsecode;
				if(!is_dir($thumbdir)){
					if(!mkdir($thumbdir))
						throw new Exception("You don't have enough permissions to create the thumbail folder: ".$thumbdir."\n");
					if(!is_writable($thumbdir))
						throw new Exception("You don't have enough permissions to write to the thumbnail folder: ".$thumbdir."\n");
					if( !symlink($this->cfg->imagePath.'/nothumb.png', $thumbdir.'/default.jpg')  )
						throw new Exception ("Couldn't create link for the thumbnail\n");
				} else {
					throw new Exception("A directory with this name already exists: ".$thumbdir."\n");
				}
			}
		} catch (Exception $e){
			throw new Exception($e->getMessage());
		}


		$insert = "INSERT INTO response (fk_user_id, fk_exercise_id, fk_media_id, file_identifier, is_private, thumbnail_uri, source, duration, adding_date, rating_amount, character_name, fk_subtitle_id) ";
		$insert = $insert . "VALUES ('%d', '%d', '%d', '%s', 1, '%s', '%s', '%s', now(), 1, '%s', %d ) ";

		$result = $this->conn->_insert($insert, $userId , $data->exerciseId, $mediaId, $responsecode, $thumbnail, $source, $duration, $data->characterName, $data->subtitleId );
		if($result){
			$r = new stdClass();
			$r->responseId = $result;
			if($media->hasVideo){
				$r->responseThumbnail = '//' . $_SERVER['HTTP_HOST'] . '/resources/images/thumbs/' . $responsecode . '/default.jpg';
			} else {
				$r->responseThumbnail = '//' . $_SERVER['HTTP_HOST'] . '/resources/images/thumbs/nothumb.png';
			}
			$r->responseFileIdentifier = $responsecode;
			return $r;
		} else {
			return false;
		}
	}

	/**
	 * Sets the folders of the different media resources of the media server
	 */
	private function _getResourceDirectories(){
		$sql = "SELECT prefValue
			FROM preferences
			WHERE (prefName='exerciseFolder' OR prefName='responseFolder' OR prefName='evaluationFolder') 
			ORDER BY prefName";
		$result = $this->conn->_multipleSelect($sql);
		if($result){
			$this->evaluationFolder = $result[0] ? $result[0]->prefValue : '';
			$this->exerciseFolder = $result[1] ? $result[1]->prefValue : '';
			$this->responseFolder = $result[2] ? $result[2]->prefValue : '';
		}
	}
	
	// EVALUATION.PHP //
	/**
	 * Returns all the data of a response and the media associated with it.
	 * @param int $responseId
	 * 		The ID of the response to retrieve.
	 * @throws Exception
	 * 		When the response ID does not exist or is not specified.
	 * @return stdClass|null
	 * 		The response and the media or null if the media was missing.
	 */
	public function getResponseData($responseId){
		if(!$responseId)
			throw new Exception("Invalid parameters", 1000);
	
		$response = $this->getResponseById($responseId);
		if(!$response)
			throw new Exception("Response id does not exist",1006);
	
		$status = 2; //Available media
		$exmedia = $this->getMediaById($response->fk_media_id,$status);
		if($exmedia){
			$response->leftMedia = $exmedia;
				
			$rightMedia = new stdClass();
			$rightMedia->netConnectionUrl = $this->cfg->streamingserver;
			$rightMedia->mediaUrl = 'responses/'.$response->file_identifier.'.flv';
				
			$response->rightMedia = $rightMedia;
		}
	
		return isset($response->leftMedia) ? $response : null;
	}
	
	/**
	 * Returns all the data of a response and the name of the user who recorded it.
	 * @param int $responseid
	 * 		The ID of the response to retrieve
	 * @return void|null|stdClass
	 * 		The response data or null|void if the response ID does not exist or is not set.
	 */
	private function getResponseById($responseid){
		if(!$responseid) return;
	
		$sql = "SELECT r.*, u.username
		FROM response r INNER JOIN user u ON r.fk_user_id=u.id
		WHERE r.id=%d";
	
		$result = $this->conn->_singleSelect($sql, $responseid);
		return $result;
	}
	
	/**
	 * Returns the data of the renditions associated with a media ID.
	 * @param int $mediaid
	 * 		The ID of the media data to retrieve
	 * @param int|Array $status
	 * 		The status of the media rendition we want to retrieve
	 * @throws Exception
	 * 		When the media ID is not set.
	 * @return null|stdClass
	 * 		The media data or null if the media ID was not found
	 */
	private function getMediaById($mediaid,$status){
		if(!$mediaid)
			throw new Exception("Invalid parameters",1000);
	
		$sql = "SELECT m.id, m.mediacode, m.instanceid, m.component, m.type, m.duration, m.level, m.defaultthumbnail, mr.status, mr.filename
		FROM media m INNER JOIN media_rendition mr ON m.id=mr.fk_media_id
		WHERE m.id=%d";
			
		if(is_array($status)){
			if(count($status)>1){
				$sparam = implode(",",$status);
				$sql.=" AND mr.status IN (%s) ";
			} else {
				$sparam = $status[0];
				$sql.=" AND mr.status=%d ";
			}
		} else {
			$sparam=$status;
			$sql.=" AND mr.status=%d ";
		}
		$sql .= " LIMIT 1";
			
		$result = $this->conn->_singleSelect($sql, $mediaid, $sparam);
		if($result){
			$result->netConnectionUrl = $this->cfg->streamingserver;
			$result->mediaUrl = 'exercises/'.$result->filename;
		}
		return $result;
	}
	

	public function admGetResponseById($responseId){
		try{

			$sql = "SELECT r.file_identifier as responseName, 
				       r.character_name as responseRole, 
				       r.fk_subtitle_id as subtitleId, 
				       r.thumbnail_uri as responseThumbnailUri,
				       e.id as exerciseId,
				       e.title 
				FROM response r INNER JOIN exercise e ON r.fk_exercise_id = e.id
				WHERE (e.status=1 AND e.visible=1 AND r.id = '%d')";	

			$result = $this->conn->_singleSelect($sql, $responseId);
			if($result){
				$status = 2;
				$level = 1; //Plugin does not support model recording
				$media = $this->getExerciseMedia($result->exerciseId, $status, $level);
				if($media){
					$result->media = $media;
					//$primarymedia = $media[0];
					//$result->exerciseName = substr($primarymedia->filename, 0, -4); //remove extension
					//$exdata->exerciseThumbnailUri = sprintf("%02d",$primarymedia->defaultthumbnail).'.jpg';
					//$exdata->duration = $primarymedia->duration;
				}
			}

			return $result;
		} catch(Exception $e){
			throw new Exception($e->getMessage());
		}
	}
}
?>
