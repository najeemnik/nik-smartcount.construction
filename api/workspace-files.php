<?php
/** Rebuild image/PDF values from validated multipart files or the user's visible saved state. */
function decodeWorkspaceFiles(array $input,array $visible): array {
    if(($input['fileTransport']??null)!==1){if(!empty($_FILES))jsonResponse(false,null,'فایل بدون معلومات ذخیره معتبر نیست.',400);return $input;}
    $known=[];
    $collect=function($value)use(&$collect,&$known){
        if(is_string($value)&&preg_match('~^data:(image/(?:png|jpeg|webp|gif|bmp)|application/pdf);base64,~D',$value))$known[hash('sha256',$value)]=$value;
        elseif(is_array($value))foreach($value as $child)$collect($child);
    };$collect($visible);
    if(count($_FILES)>1||($_FILES&&!isset($_FILES['files'])))jsonResponse(false,null,'فهرست فایل‌ها معتبر نیست.',400);
    $files=$_FILES['files']??[];$uploaded=[];$total=0;
    if($files){
        foreach(['name','type','tmp_name','error','size'] as $key)if(!isset($files[$key])||!is_array($files[$key]))jsonResponse(false,null,'ساختار فایل معتبر نیست.',400);
        if(count($files['name'])>20)jsonResponse(false,null,'در یک ثبت حداکثر ۲۰ فایل تازه مجاز است.',400);
        foreach($files['name'] as $id=>$name){
            if(!preg_match('/^[a-f0-9]{64}$/D',(string)$id))jsonResponse(false,null,'شناسهٔ فایل معتبر نیست.',400);
            if(($files['error'][$id]??-1)!==UPLOAD_ERR_OK)jsonResponse(false,null,'آپلود فایل کامل نشد؛ حد حجم فایل در تنظیمات PHP هاست را بررسی کنید.',400);
            $tmp=$files['tmp_name'][$id]??'';
            if(!is_string($tmp)||!is_uploaded_file($tmp))jsonResponse(false,null,'فایل آپلود معتبر نیست.',400);
            $raw=file_get_contents($tmp);$size=$raw===false?0:strlen($raw);$total+=$size;
            if(!$size||$size>10*1024*1024||$total>21*1024*1024)jsonResponse(false,null,'حجم فایل بیش از حد مجاز است.',413);
            $mime=(new finfo(FILEINFO_MIME_TYPE))->buffer($raw);
            if($mime==='image/x-ms-bmp'&&($files['type'][$id]??'')==='image/bmp')$mime='image/bmp';
            if(!in_array($mime,['image/png','image/jpeg','image/webp','image/gif','image/bmp','application/pdf'],true)||$mime!==($files['type'][$id]??''))jsonResponse(false,null,'محتوای عکس یا PDF معتبر نیست.',400);
            if(str_starts_with($mime,'image/')&&@getimagesizefromstring($raw)===false)jsonResponse(false,null,'محتوای عکس معتبر نیست.',400);
            $url='data:'.$mime.';base64,'.base64_encode($raw);
            if(!hash_equals((string)$id,hash('sha256',$url)))jsonResponse(false,null,'محتوای فایل با شناسهٔ آن برابر نیست.',400);
            $uploaded[$id]=$url;
        }
    }
    $used=[];
    $restore=function($value)use(&$restore,$known,$uploaded,&$used){
        if(!is_array($value))return $value;
        if(array_key_exists('__nsc_file__',$value)){
            $id=$value['__nsc_file__'];
            if(count($value)!==1||!is_string($id)||!preg_match('/^[a-f0-9]{64}$/D',$id))jsonResponse(false,null,'مرجع فایل معتبر نیست.',400);
            if(isset($uploaded[$id])){$used[$id]=true;return $uploaded[$id];}
            if(isset($known[$id]))return $known[$id];
            jsonResponse(false,null,'فایل در معلومات مجاز سرور موجود نیست؛ صفحه را تازه و دوباره تلاش کنید. نسخهٔ محفوظ را نگه دارید.',409);
        }
        foreach($value as $key=>$child)$value[$key]=$restore($child);return $value;
    };
    $input['state']=$restore($input['state']??[]);
    if(count($used)!==count($uploaded))jsonResponse(false,null,'فایل اضافی در درخواست ذخیره مجاز نیست.',400);
    if(strlen(json_encode($input,JSON_THROW_ON_ERROR))>21*1024*1024)jsonResponse(false,null,'حجم معلومات بیش از حد مجاز است.',413);
    return $input;
}
