<?php
// FileUploaderInterface.php
interface FileUploaderInterface {
    public function uploadFile($file, $title, $type, $type_id, $userid, $createdby);
}
?>