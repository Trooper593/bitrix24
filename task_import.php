<?php
    use \Bitrix\Main\Application,
        \Bitrix\Main\Type\DateTime,
        \Bitrix\Tasks\Item\Task;

    include($_SERVER["DOCUMENT_ROOT"]."/bitrix/modules/main/include/prolog_before.php");

    if(!CModule::IncludeModule("tasks") || !CModule::IncludeModule("forum")) {
        die("Module not installed");
    }

    $forum_id = 8;
    $uploaddir = '/upload/task_import/';
    $request = Application::getInstance()->getContext()->getRequest();

    //upload archive
    $fileUploaded = $request->getFile("uploadedfile");
    if(!$fileUploaded){
        echo '<h4>Upload -zip archive</h4><form method="post" enctype="multipart/form-data" action="'.POST_FORM_ACTION_URI.'"><input type="file" name="uploadedfile"><br/><input type="submit" value="Upload"></form>';
        exit();
    }else{
        //clear before upload
        removeDir($_SERVER['DOCUMENT_ROOT'] . $uploaddir);
        if (!file_exists($_SERVER['DOCUMENT_ROOT'].$uploaddir))
            mkdir($_SERVER['DOCUMENT_ROOT'].$uploaddir, 0777, true);

        $uploadedFile = $_SERVER['DOCUMENT_ROOT'].$uploaddir . basename($fileUploaded['name']);
        if (!move_uploaded_file($fileUploaded['tmp_name'], $uploadedFile))
            die("fail upload file");
        chmod($uploadedFile, 0777);
    }

    $arFileUploaded = explode(".", $fileUploaded['name']);
    $task_directory = $arFileUploaded[0].'/';

    //unpack .zip archive
    $zip = new ZipArchive;
    $unzip = $zip->open($uploadedFile);
	
    if($unzip === true) {
        for($i=0; $i<$zip->numFiles; $i++) {
            $name = $zip->getNameIndex($i);

            // Remove the first directory in the string if necessary
            $parts = explode('/', $name);
            //if(count($parts) > 1) array_shift($parts);

            $file = $_SERVER['DOCUMENT_ROOT'] . $uploaddir . $task_directory . implode('/', $parts);

            // Create the directories if necessary
            $dir = dirname($file);
            if(!is_dir($dir))
                mkdir($dir, 0777, true);

            // Check if $name is a file or directory
            if(substr($file, -1) == "/") {
                // $name is a directory
                // Create the directory
                if(!is_dir($file))
                    mkdir($file, 0777, true);
            } else {
                // $name is a file
                // Read from Zip and write to disk
                $fpr = $zip->getStream($name);
                $fpw = fopen($file, 'w');
                while($data = fread($fpr, 1024)) {
                    fwrite($fpw, $data);
                }
                fclose($fpr);
                fclose($fpw);
            }
        }
    }else{
        die('fail unzip archive');
    }

    $importDirPath = $_SERVER['DOCUMENT_ROOT'].$uploaddir.$task_directory;

    //Список всех пользователей
    $arAllLogins = [];
    $users_id_login = [];
    $rsUsers = CUser::GetList(($by="personal_country"), ($order="desc"), []);
    while($arUsers = $rsUsers->Fetch()){
        $users_id_login[$arUsers["ID"]] = $arUsers["LOGIN"];
        $arAllLogins[] = $arUsers["LOGIN"];
    }
    $users_login_id = array_flip($users_id_login);

    //0. Подготовка данных
    $arTasks = []; $arTaskFiles = []; $arTaskComments = []; $arCommentFiles = []; $arTasksChecklists = []; $arTasksTags = [];
    if(file_exists($importDirPath.'tasks.csv'))
        $arTasks = file2array($importDirPath.'tasks.csv');
    if(file_exists($importDirPath.'task_files.csv'))
        $arTaskFiles = file2array($importDirPath.'task_files.csv');
    if(file_exists($importDirPath.'task_comments.csv'))
        $arTaskComments = file2array($importDirPath.'task_comments.csv');
    if(file_exists($importDirPath.'comment_files.csv'))
        $arCommentFiles = file2array($importDirPath.'comment_files.csv');
    if(file_exists($importDirPath.'tasks_checklists.csv'))
        $arTasksChecklists = file2array($importDirPath.'tasks_checklists.csv');
    if(file_exists($importDirPath.'tasks_tags.csv'))
        $arTasksTags = file2array($importDirPath.'tasks_tags.csv');


	$arTaskIDs = [];
    $arNonExistentUsers = [
        'AUDITORS' => [],
        'ACCOMPLICES' => [],
        'RESPONSIBLE' => []
    ];
    $DB->StartTransaction();
    foreach($arTasks as $arTask){

        $userId = $users_login_id[$arTask['CREATED_BY']];
        $storage = Bitrix\Disk\Driver::getInstance()->getStorageByUserId($userId);
        $folder = $storage->getFolderForUploadedFiles();

        //1. Создаем задачу
        $task = new Task();

        $arAuditors = [];
        $arAuditorLogins = json_decode($arTask['AUDITORS']);
        if(is_array($arAuditorLogins)){
            foreach($arAuditorLogins as $arAuditorLogin){
                if(!in_array($arAuditorLogin, $arAllLogins))
                    $arNonExistentUsers['AUDITORS'][] = $arAuditorLogin;
				else
					$arAuditors[] = $users_login_id[$arAuditorLogin];				
            }
        }

        $arAccomplices = [];
        $arAccompliceLogins = json_decode($arTask['ACCOMPLICES']);
        if(is_array($arAccompliceLogins)){
            foreach($arAccompliceLogins as $arAccompliceLogin){
                if(!in_array($arAccompliceLogin, $arAllLogins))
                    $arNonExistentUsers['ACCOMPLICES'][] = $arAccompliceLogin;
				else
					$arAccomplices[] = $users_login_id[$arAccompliceLogin];
            }
        }

        if(!in_array($arTask['RESPONSIBLE_ID'], $arAllLogins))
            $arNonExistentUsers['RESPONSIBLE'][] = $arAccompliceLogin;

        $arTags = [];
        foreach($arTasksTags as $arTaskTags){
            if($arTaskTags['TASK_ID'] == $arTask['ID'])
                $arTags[] = $arTaskTags['NAME'];
        }

        $task['TITLE'] = $arTask['TITLE'];
        $task['DESCRIPTION'] = $arTask['DESCRIPTION'];
        if(!empty($arTask['DEADLINE']))
        $task['DEADLINE'] = new DateTime($arTask['DEADLINE']);
        if(!empty($arTask['START_DATE_PLAN']))
        $task['START_DATE_PLAN'] = new DateTime($arTask['START_DATE_PLAN']);
        if(!empty($arTask['END_DATE_PLAN']))
        $task['END_DATE_PLAN'] = new DateTime($arTask['END_DATE_PLAN']);
        $task['ACCOMPLICES'] =  $arAccomplices;
        $task['AUDITORS'] = $arAuditors;
        $task['TAGS'] = $arTags;
        $task['ALLOW_CHANGE_DEADLINE'] = $arTask['ALLOW_CHANGE_DEADLINE'];
        $task['TASK_CONTROL'] = $arTask['TASK_CONTROL'];
		if(isset($arTaskIDs[$arTask['ID']]))
			$task['PARENT_ID'] = $arTaskIDs[$arTask['PARENT_ID']];
        //$task['DEPENDS_ON'] = array();
        //$task['DESCRIPTION_IN_BBCODE'] = 'Y';
        $task['PRIORITY'] = $arTask['PRIORITY'];
        $task['STATUS'] = $arTask['STATUS'];
        $task['STAGE_ID'] = $arTask['STAGE_ID'];
        if(!empty($arTask['DATE_START']))
        $task['DATE_START'] = new DateTime($arTask['DATE_START']);
        $task['DURATION_PLAN'] = $arTask['DURATION_PLAN'];
        if(!empty($arTask['DURATION_FACT']))
        $task['DURATION_FACT'] = new DateTime($arTask['DURATION_FACT']);
        $task['DURATION_TYPE'] = $arTask['DURATION_TYPE'];
        $task['TIME_ESTIMATE'] = $arTask['TIME_ESTIMATE'];
        $task['REPLICATE'] = $arTask['REPLICATE'];
        $task['GROUP_ID'] = $arTask['GROUP_ID'];
        $task['RESPONSIBLE_ID'] = $users_login_id[$arTask['RESPONSIBLE_ID']];
        $task['CREATED_BY'] = $users_login_id[$arTask['CREATED_BY']];
        $task['DECLINE_REASON'] = $arTask['DECLINE_REASON'];
        if(!empty($arTask['CREATED_DATE']))
        $task['CREATED_DATE'] = new DateTime($arTask['CREATED_DATE']);
        $task['CHANGED_BY'] = $users_login_id[$arTask['CHANGED_BY']];
        if(!empty($arTask['CHANGED_DATE']))
        $task['CHANGED_DATE'] = new DateTime($arTask['CHANGED_DATE']);
        $task['STATUS_CHANGED_BY'] = $users_login_id[$arTask['STATUS_CHANGED_BY']];
        if(!empty($arTask['STATUS_CHANGED_BY']))
        $task['STATUS_CHANGED_DATE'] = new DateTime($arTask['STATUS_CHANGED_DATE']);
        $task['CLOSED_BY'] = $users_login_id[$arTask['CLOSED_BY']];
        if(!empty($arTask['CLOSED_BY']))
        $task['CLOSED_DATE'] = new DateTime($arTask['CLOSED_DATE']);
        $task['XML_ID'] = $arTask['ID'];
        $task['MARK'] = $arTask['MARK'];
        $task['ALLOW_TIME_TRACKING'] = $arTask['ALLOW_TIME_TRACKING'];
        $task['ADD_IN_REPORT'] = $arTask['ADD_IN_REPORT'];
        $task['FORUM_ID'] = $forum_id;        
        $task['MULTITASK'] = 'N';
        $task['SITE_ID'] = $arTask['SITE_ID'];
        $task['MATCH_WORK_TIME'] = $arTask['MATCH_WORK_TIME'];
		//$task['FORUM_TOPIC_ID'] = '1';
        //$task['OUTLOOK_VERSION'] = '9';
        //$task['SE_CHECKLIST'] = new Bitrix\Tasks\Item\Task\Collection\CheckList($arChecklist);
        //$task['UF_CRM_TASK'] = Bitrix\Tasks\Util\Collection;
        //$task['UF_CRM_TASK'] = Bitrix\Tasks\Util\Collection;
        //$task['SE_MEMBER'] = Bitrix\Tasks\Item\Task\Collection\Member
        //$task['SE_TAG'] = new Bitrix\Tasks\Item\Task\Collection\Tag(['tagnew1']);

        $result = $task->save();
        if($result->isSuccess()){

            $taskId = $task->getId();
			
			$arTaskIDs[$arTask['ID']] = $taskId;
			
			Bitrix\Main\Diag\Debug::writeToFile($arTask['ID'].'->'.$taskId, '', 'taskimport_result.log');
			
            $oTaskItem = new CTaskItem($taskId, $userId);

            $arChecklist = [];
            foreach($arTasksChecklists as $arTaskChecklist){
                if($arTask['ID'] == $arTaskChecklist['TASK_ID']){
                    $arChecklist[] = [
                        //'CREATED_BY' => $users_login_id[$arTaskChecklist['CREATED_BY']],
                        'TITLE' => $arTaskChecklist['TITLE'],
                        'IS_COMPLETE' => $arTaskChecklist['IS_COMPLETE']
                    ];
                }
            }
            if(count($arChecklist)>0){
                foreach($arChecklist as $checklistItem){
                    try{
                        \CTaskCheckListItem::add($oTaskItem, $checklistItem);
                    }catch(Exception $ex){
                        Bitrix\Main\Diag\Debug::writeToFile([$ex->getMessage(), $arTask], 'CTaskCheckListItem::add', 'taskimport.log');
                    }
                }
            }

            // 2. Прикрепляем файлы к задаче
            $arTaskFileIds = [];
            foreach($arTaskFiles as $arTaskFile){
                if($arTaskFile['TASK_ID'] == $arTask['ID']){

                    $arFile = CFile::MakeFileArray($_SERVER['DOCUMENT_ROOT'].$uploaddir.$arTaskFile['PATH']);
                    $file = $folder->uploadFile($arFile, array(
                        'NAME' => $arTaskFile['ORIGIN_NAME'],
                        'CREATED_BY' => $userId
                    ), array(), true);
                    $FILE_ID = $file->getId();
                    $arTaskFileIds[] = "n$FILE_ID";
                }
            }
            if(count($arTaskFileIds)>0)
                $oTaskItem->Update(array("UF_TASK_WEBDAV_FILES" => $arTaskFileIds));


            //3. Прикрепляем комменты к задаче
            foreach($arTaskComments as $arTaskComment) {
                if ($arTaskComment['TASK_ID'] == $arTask['ID']){

                    foreach ($users_id_login as $idUser => $loginUser) {
                        $arTaskComment['POST_MESSAGE'] = str_replace("USER=" . $loginUser, "USER=" . $idUser, $arTaskComment['POST_MESSAGE']);
                        $arTaskComment['POST_MESSAGE_HTML'] = str_replace("USER=" . $loginUser, "USER=" . $idUser, $arTaskComment['POST_MESSAGE_HTML']);
                    }

                    $arCommentFileIds = [];
                    foreach ($arCommentFiles as $arCommentFile) {
                        if ($arTaskComment['ID'] == $arCommentFile['COMMENT_ID']) {

                            $arFile = CFile::MakeFileArray($_SERVER['DOCUMENT_ROOT'] . $uploaddir . $arCommentFile['PATH']);
                            $file = $folder->uploadFile($arFile, array(
                                'NAME' => $arCommentFile['ORIGIN_NAME'],
                                'CREATED_BY' => $userId
                            ), array(), true);
                            $FILE_ID = $file->getId();
                            $arCommentFileIds[] = "n$FILE_ID";
                        }
                    }

                    $taskItem = \CTaskItem::getInstance($taskId, $userId);
                    $arTaskCommentFields = [
                        "AUTHOR_ID" => $users_login_id[$arTaskComment['AUTHOR_ID']],
                        "POST_MESSAGE" => $arTaskComment['POST_MESSAGE'],
                        "POST_MESSAGE_HTML" => $arTaskComment['POST_MESSAGE_HTML'],
                        "NEW_TOPIC" => $arTaskComment['NEW_TOPIC'],
                        "APPROVED" => $arTaskComment['APPROVED'],
                        "SOURCE_ID" => $arTaskComment['SOURCE_ID'],
                        "AUTHOR_REAL_IP" => $arTaskComment['AUTHOR_REAL_IP'],
                        "XML_ID" => $arTaskComment['XML_ID'],
                        "POST_DATE" => $arTaskComment['POST_DATE'],
                        "HTML" => $arTaskComment['HTML'],
                        "UF_FORUM_MESSAGE_DOC" => $arCommentFileIds
                    ];

                    try{
                        $rsTaskItem = \CTaskCommentItem::add($taskItem, $arTaskCommentFields);
                    }catch(Exception $ex){
                        Bitrix\Main\Diag\Debug::writeToFile([$ex->getMessage(), $arTask], 'CTaskCommentItem::add', 'taskimport.log');
                    }
                }
            }

        }else{
            Bitrix\Main\Diag\Debug::writeToFile([$result->dump(), $arTask], '$task->save()', 'taskimport.log');
        }
    }

    if(count($arNonExistentUsers['AUDITORS'])>0 ||
       count($arNonExistentUsers['ACCOMPLICES'])>0 ||
       count($arNonExistentUsers['RESPONSIBLE'])>0
    ){
        $DB->Rollback();
		
		$arNonExistentUsers['AUDITORS'] = array_unique($arNonExistentUsers['AUDITORS']);
		$arNonExistentUsers['ACCOMPLICES'] = array_unique($arNonExistentUsers['ACCOMPLICES']);
		$arNonExistentUsers['RESPONSIBLE'] = array_unique($arNonExistentUsers['RESPONSIBLE']);		
		
		Bitrix\Main\Diag\Debug::writeToFile($arNonExistentUsers, 'Non existent users', 'taskimport.log');
    }else{
        $DB->Commit();
    }

    function file2array($filepath){
        $row = 0;
        $arHeadings = [];
        $resultArray = [];

        if(!$filepath)
            return false;

        if (($handle = fopen($filepath, "r")) == FALSE)
            return false;

        while (($data = fgetcsv($handle, 1000, ";")) !== FALSE) {

            $data = iconv_array($data);
            if($row == 0){
                $arHeadings = $data;
            }else{
                $resultArray[] = array_combine($arHeadings, $data);
            }
            $row++;
        }
        fclose($handle);

        return $resultArray;
    }

    function removeDir($dir) {
        $files = array_diff(scandir($dir), array('.','..'));
        foreach ($files as $file) {
            (is_dir("$dir/$file")) ? removeDir("$dir/$file") : unlink("$dir/$file");
        }
        return rmdir($dir);
    }

    function iconv_array($array=[], $in = 'windows-1251', $out = 'utf-8'){
        foreach(array_keys($array) as $key){
            $array[$key] = iconv($in, $out, $array[$key]);
        }
        return $array;
    }