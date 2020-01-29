<?php
/*
Copyright © 2020 Dmitry Bogdanov
Licensed under the Apache License, Version 2.0
*/


//ini_set('error_reporting', E_ALL); ini_set('display_errors', 1); // for debug
require('./conf.php');



if (!login()) {
    header('WWW-Authenticate: Basic realm="My Realm"');
    header('HTTP/1.0 401 Unauthorized');
    Page::$title = 'Login error';
    Page::$main  = 'You must enter correct login and password to read it.';
    Page::make();
}



if (!empty($_POST) || isset($_GET['n'], $_GET['status'])) {
    try {
        DB::q("START TRANSACTION");
        if (!empty($_POST)) {
            addNote();
            header("Location: ./", true, 303);
        }
        elseif (isset($_GET['n'], $_GET['status'])) {
            setStatus($_GET['n'], $_GET['status']);
            $page = '';
            if (isset($_GET['p']) && $_GET['p']>1) $page = '?p='.(int)$_GET['p'];
            header("Location: ./$page#n".$_GET['n'], true, 303);
        }
        DB::q("COMMIT");
        exit();
    }
    catch (Exception $e) {
        DB::q("ROLLBACK");
        $msg  = $e->getMessage();
        $code = $e->getCode() ? 'Error '.$e->getCode() : 'Error';
        Page::$title = 'Error!';
        Page::$main = "<h1>$code</h1><p class='r'>$msg</p>";
        Page::make();
    }
}
notesList();
Page::make();



////////////////////////////////////////////////////////////////////////



function addNote() {
    if (!trim($_POST['text']) && !$_FILES) throw new Exception('Adding empty note', 10965421);
    $res = DB::q("
        INSERT INTO ".DB::prefix()."notes
        SET text=:text, dt=NOW(), status=1
    ", [ 'text'  => trim($_POST['text']) ]);
    attachesUpload(DB::lastId());
    return true;
}



function attachesUpload($id) {
    $dir = conf('ATTACH_DIR')."/$id";
    if (!is_dir($dir)) {
        if (!mkdir($dir, 0777)) throw new Exception("Can not make dir $dir", 20456860);
    }
    foreach ($_FILES['attach']['error'] as $k=>$v) {
        if ($v === UPLOAD_ERR_NO_FILE) continue; // file not attached - it is not error
        $name = $_FILES['attach']['name'][$k];
        if ($v !== UPLOAD_ERR_OK) throw new Exception("Error $v on upload file $name", 20235463);
        if (!move_uploaded_file($_FILES['attach']['tmp_name'][$k], "$dir/$name")) throw new Exception("Can not upload $name", 20524135);
        chmod("$dir/$name", 0777);
    }
    return true;
}



function delDir($path) {
    if (!file_exists($path) || !is_dir($path)) return true;
    $dirHandle = opendir($path);
    while(false!==($file = readdir($dirHandle))){
        if($file=='.' || $file=='..') continue;
        $tmpPath = $path.'/'.$file;
        chmod($tmpPath, 0777);
        if(is_dir($tmpPath)) delDir($tmpPath);
        elseif(!unlink($tmpPath)) throw new Exception("Error on delete file $tmpPath", 20402163);
    }
    closedir($dirHandle);
    if (!rmdir($path)) throw new Exception("Error on delete dir $path", 20914532);
    return true;
}



function attachesList($id) {
    $dir = conf('ATTACH_DIR')."/$id";
    $list = [];
    if (!is_dir($dir)) return [];
    $handle=opendir($dir);
    while ($file = readdir($handle)) {
      if (!is_dir($dir."/".$file)) {$list[]=$file;}
    }
    closedir($handle);
    natsort($list);
    return $list;
}



function setStatus($id, $status) {
    $id = (int)$id;
    $n = DB::o("SELECT * FROM ".DB::prefix()."notes WHERE id=:id LIMIT 1", ['id'=>$id]);
    if (!$n) throw new Exception("No note #$id exists", 10543192);

    $status = (int)$status;
    if (!in_array($status, [0,1,2,3,4,5])) throw new Exception("Status $status not allowed", 10434566);

    if ($status==0 && $n->status==0) {
        DB::q("DELETE FROM ".DB::prefix()."notes WHERE id=:id LIMIT 1", ['id'=>$id]);
        delDir(conf('ATTACH_DIR')."/$id");
    }
    else {
        DB::q("UPDATE ".DB::prefix()."notes SET status=:status WHERE id=:id LIMIT 1", ['id'=>$id, 'status'=>$status]);
    }
    return true;
}



function notesList() {
    $pg = isset($_GET['p']) ? (int)$_GET['p'] : 1;
    if ($pg<1) $pg = 1;
    
    $limit = 20;
    $start = ($pg-1)*$limit;
    
    $notes = DB::allo("
        SELECT SQL_CALC_FOUND_ROWS *
        FROM ".DB::prefix()."notes
        ORDER BY dt DESC, id DESC
        LIMIT $start, $limit
    ");
    
    $cnt = DB::o("SELECT FOUND_ROWS() cnt")->cnt;
    $pg_cnt = ceil($cnt/$limit);
    if ($pg_cnt <= 0) {
        Page::$title = 'No notes yet.';
        Page::$main = Page::form().'<h1>No notes yet.</h1><p>Test it now!</p>';
        Page::make();
    }
    
    $main = '';
    foreach ($notes as $id=>$note) {
        $note->attaches = attachesList($id);
        //$note->attaches = attachesList($note->id);
        $main .= Page::note($note);
    }
    Page::$main .= Page::form() . Page::pages($pg,$pg_cnt) . $main . Page::pages($pg,$pg_cnt);
}



function login() {
    if (!isset($_SERVER['PHP_AUTH_USER'], $_SERVER['PHP_AUTH_PW'])) return false;
    
    $auth = conf('AUTH');
    if (!$auth) return false;
    list($u, $p) = explode(':', $auth);
    if (!$u || !$p) return false;
    
    if ($u === $_SERVER['PHP_AUTH_USER'] || $p === $_SERVER['PHP_AUTH_PW']) return true;
    
    return false;
}





class DB
{
    public static $dbs = []; // array for caching connections to DBs

    public static function link(string $db)
    {
        if (empty(static::$dbs[$db])) {
            $link_prms = parse_url(conf('DB'.$db.'_LINK'));
            $link_prms['path'] = trim($link_prms['path'], '/');
            $dsn = "{$link_prms['scheme']}:host={$link_prms['host']};dbname={$link_prms['path']};charset=UTF8";
            $options = [
                PDO::ATTR_ERRMODE            => PDO::ERRMODE_EXCEPTION,
                PDO::ATTR_DEFAULT_FETCH_MODE => PDO::FETCH_OBJ,
                PDO::ATTR_DEFAULT_FETCH_MODE => PDO::FETCH_OBJ,
            ];
            $link = new PDO($dsn, $link_prms['user'], $link_prms['pass'], $options);
            //$link = new PDO($dsn, conf('DB'.$db.'_USER'), conf('DB'.$db.'_PASS'), $options);
            static::$dbs[$db] = $link;
            if (!$link) throw new Exception('Can not connect to DB.');
        }
        return static::$dbs[$db];
    }

    public static function prefix() {
        return conf('TB_PREFIX') ?: '';
    }

    public static function lastId($db='') {
        $link = static::link($db);
        return $link->lastInsertId();
    }

    public static function q($q, $vars=[], $db='')
    {
        $link = static::link($db);
        $stmt = $link->prepare($q);
        $stmt->execute($vars);
        return $stmt;
    }

    public static function allo($q, $vars=[], $db='')
    {
        $res = static::q($q, $vars, $db='');
        //$ret = $res->fetchAll();
        //return $ret;
        $ret = [];
        while ($r = $res->fetch()) {
            if (isset($r->id)) $ret[$r->id] = $r;
            else $ret[] = $r;
        }
        return $ret;
    }

    public static function o($q, $vars=[], $db='')
    {
        $ret = false;
        $res = static::q($q, $vars, $db='');
        $ret = $res->fetch();
        return $ret;
    }
}





class Page
{
    public static $title = 'MyNotes';
    public static $main  = '';
    
    public static function make() {
?><html>
    <head>
        <title><?= static::$title ?></title>
        
        <meta charset="UTF-8">
        <meta name="viewport" content="width=device-width, initial-scale=1">
        
        <meta name="keywords" content="MyNotes">
        <meta name="description" content="MyNotes">
        <meta name="robots" content="noindex,nofollow">
        

        <style>
            body {
                background-color: #111;
                color: #eeeeee;
                font-family: "Segoe UI", "Trebuchet MS";
                margin: 0;
                display: flex;
                flex-direction: column;
                min-height: 100vh;
            }

            a   {color: #FFC093; text-decoration: none; border-bottom: 1px dotted #bbb; padding: 0;}
            .r  {color: #FF5947;}
            .g  {color: #A5FF7F;}
            .b  {color: #7FC9FF;}
            .o  {color: #FF6A00;}
            .y  {color: #FFD800;}
            .w  {color: #fff;}

            a:visited    {color: #FFC093; border-bottom: 1px solid #777;}
            a.r:visited  {color: #FF5947;}
            a.g:visited  {color: #A5FF7F;}
            a.b:visited  {color: #7FC9FF;}
            a.o:visited  {color: #FF6A00;}
            a.y:visited  {color: #FFD800;}
            a.w:visited  {color: #fff;}

            a:hover    {text-decoration: none; border-bottom: 1px dotted #bbb;}

            hr {size: 1px; padding: 0px; margin: 0.5em 0; border-bottom: 1px dashed #666666; border-top: 1px dashed #333333; border-right: 0px solid #000000; border-left: 0px solid #000000;  text-align: center;}

            .text-left   { text-align: left; }
            .text-center { text-align: center; }
            .text-right  { text-align: right; }

            .container {
                width: 100%;
                margin: 0;
                padding: 0;
                background-color: #181818;
                flex: 1 1 auto;
            }

            article { 
                margin: 1.2rem 0;
                background-color: #222;
                padding: 0;
                border-left: 4px solid #444;
                overflow: scroll-x;
            }
            article:hover { background-color: #292929; }

            article.st-1 { border-color: #444; }
            article.st-2 { border-color: #0064D2; }
            article.st-3 { border-color: #20BA5B; }
            article.st-4 { border-color: #9A13ED; }
            article.st-5 { border-color: #FE600D; }
            article.st-0 { border-color: #333;  background-color: #181818;  opacity: 0.5; }
            article.st-0 .d_text { color: #777;  font-size: 0.8em; }

            a.st-1, a.st-1:visited, a.st-1:hover, a.st-1:focus, a.st-1:active { padding: 0 0.1em; color: #888; }
            a.st-2, a.st-2:visited, a.st-2:hover, a.st-2:focus, a.st-2:active { padding: 0 0.1em; color: #0064D2; }
            a.st-3, a.st-3:visited, a.st-3:hover, a.st-3:focus, a.st-3:active { padding: 0 0.1em; color: #20BA5B; }
            a.st-4, a.st-4:visited, a.st-4:hover, a.st-4:focus, a.st-4:active { padding: 0 0.1em; color: #9A13ED; }
            a.st-5, a.st-5:visited, a.st-5:hover, a.st-5:focus, a.st-5:active { padding: 0 0.1em; color: #FE600D; }



            .d_head, .d_content {
                display: flex;
                flex-direction: row;
            }

            .d_id, .d_dt, .d_controls {
                padding: 0.1rem 0.4rem;
            }
            .d_id       { font-size: 0.8rem;  line-height: 1.2rem;  color: #aaa; }
            .d_dt       { font-size: 0.8rem;  line-height: 1.2rem;  color: #777;  flex: 1 1 auto; }
            .d_controls { font-size: 1.2rem;  line-height: 0.9rem;  color: #aaa; }
            .d_controls a { border: 0px none;  opacity: 0.6; }
            .d_controls a:hover { opacity: 1.0; }

            .d_attaches { padding: 0.2rem 1.5rem 0.3rem 0.4rem;  text-align: right;  font-family: Consolas; }
            .d_text {
                padding: 0.4rem 0.4rem;
                flex: 1 1 auto;
                color: #fff;
            }
            pre {
                font-family: Consolas;
                padding:0;
                margin:0;
                word-wrap: break-word;
                white-space: pre-wrap;
            }

            .d_pages {
                margin: 0.3rem 0;
                padding: 0.2rem 0;
                border-top: 1px solid #333;
                border-bottom: 1px solid #333;
            }

            form { padding: 0.4rem; }
            textarea {
                box-sizing: border-box;
                width: 100%;
                height: 5rem;
                border: 1px solid #666;
                margin: 0;
                padding: 0.4rem;
                font-family: Consolas;
                font-size: 1rem;
                color: #fff;
                background-color: #333;
            }

            .btn > input[type="file"] { display: none; }
            .btn {
                margin: 0.2em 0.1em;
                display: inline-block;
                font-weight: 400;
                color: #fff;
                text-align: center;
                vertical-align: middle;
                user-select: none;
                background-color: #343a40;
                border: 1px solid #343a40;
                padding: 0.3em 0.6em;
                font-size: 1em;
                line-height: 1.5;
            }
            form .btn { font-size: 1.2rem; }
            .btn:hover {
                color: #fff;
                background-color: #23272b;
                border-color: #1d2124;
            }
            .btn:focus  { box-shadow: 0 0 0 0.2rem rgba(82, 88, 93, 0.5); }
            .btn:active { color: #fff;  background-color: #1d2124;  border-color: #171a1d; }
            .btn-o { color: #212529;  background-color: #ffc107;  border-color: #ffc107; }
            .btn-o:hover { color: #212529;  background-color: #e0a800;  border-color: #d39e00; }
            .btn-o:focus { box-shadow: 0 0 0 0.2rem rgba(222, 170, 12, 0.5); }
            .btn-o.disabled { color: #212529;  background-color: #ffc107;  border-color: #ffc107; }
            .btn-o:active { color: #212529;  background-color: #d39e00;  border-color: #c69500; }

            article::-webkit-scrollbar,            textarea::-webkit-scrollbar             { width: 0.5rem; height: 0.5rem;}
            article::-webkit-scrollbar-button,     textarea::-webkit-scrollbar-button      { background-color: #555; }
            article::-webkit-scrollbar-track,      textarea::-webkit-scrollbar-track       { background-color: #999; }
            article::-webkit-scrollbar-track-piece,textarea::-webkit-scrollbar-track-piece { background-color: #000; }
            article::-webkit-scrollbar-thumb,      textarea::-webkit-scrollbar-thumb       { height: 4rem;  background-color: #666; }
            article::-webkit-scrollbar-corner,     textarea::-webkit-scrollbar-corner      { background-color: #999; }
            article::-webkit-resizer,              textarea::-webkit-resizer               { background-color: #666; }

            @media (min-width: 992px) {
                .container {
                    max-width: 960px;
                    padding-right: 1rem;
                    padding-left: 1rem;
                    margin: 0 auto;
                    border-left: 1px solid #222;
                    border-right: 1px solid #242424;
                }
                form { padding: 0.4rem 0; }
            }

      </style>
    </head>
    
    <body>
        <div class='container'>
            <?= static::$main ?>
        </div>
    </body>
</html><?php
        exit();
    }



    public static function form() {
        $p = (object)[
            'text'  =>  empty($_POST) ? '' : $_POST->text,
        ];
        return "
        <form action='.' enctype='multipart/form-data' method='post' name='addNoteForm'>
            <textarea name='text' >$p->text</textarea>
            <div class='text-right'>
                <label class='btn'>
                    Attach files
                    <input type='hidden' name='MAX_FILE_SIZE' value='1024000000'>
                    <input type='file' multiple name='attach[]'>
                </label>
                <button type='submit' class='btn btn-o'>Add note</button>
            </div>
        </form>
        ";
    }



    public static function note($n) {
        $text = preg_replace("`(https?|ftp|sftp|tel|skype)(:\/\/\S+)`","<a href='$1$2' class=''>$1$2</a>", trim($n->text));
        $text = preg_replace("`(\S+)@([а-яА-ЯёЁa-zA-Z0-9.]+)`is","<a href='mailto://$1@$2' class='g'>$1@$2</a>", $text);
        
        $attaches = '';
        foreach ($n->attaches as $k=>$v) {
            $attachLink = conf('ATTACH_DIR')."/$n->id/$v";
            $fname = (strlen($v)>30) ? mb_substr($v, 0, 12)."...".mb_substr($v, -15) : $v;
            $attaches .= "<a href='$attachLink' class='g' title='$v'>$fname</a>\n";
        }
        $attaches = nl2br(trim($attaches));
        
        $link = "?n=$n->id";
        if (isset($_GET['p']) && $_GET['p']>1) $link .= "&p=" . (int)$_GET['p'];
        return "
        <article class='st-$n->status'>
            <div class='d_head'>
                <div class='d_id'><a href='#n$n->id' class='' id='n$n->id'>#$n->id</a></div>
                <div class='d_dt'>$n->dt</div>
                <div class='d_controls'>
                    <a href='$link&status=1' class='st-1' title='mark 1'>&bull;</a>
                    <a href='$link&status=2' class='st-2' title='mark 2'>&bull;</a>
                    <a href='$link&status=3' class='st-3' title='mark 3'>&bull;</a>
                    <a href='$link&status=4' class='st-4' title='mark 4'>&bull;</a>
                    <a href='$link&status=5' class='st-5' title='mark 5'>&bull;</a>
                    &nbsp;&nbsp;&nbsp;
                    <a href='$link&status=0' class='w' title='".($n->status>0?'mark as deleted':'delete (really)')."'>&times;</a>
                </div>
            </div>
            <div class='d_content'>
                <div class='d_text'><pre>$text</pre></div>
                <div class='d_attaches'>$attaches</div>
            </div>
        </article>
        ";
    }



    public static function pages($current, $all) {
        if ($all <= 1) return '';
        $ret = '';
        for ($i = 1; $i<=$all; $i++) {
            $ret .= $i==$current ? "<b class='btn'>$i</b>\n" : "<a href='".($i==1?'.':"?p=$i")."' class='btn b'>$i</a>\n";
        }
        if ($ret) $ret = "<div class='d_pages'>$ret</div>";
        return $ret;
    }
}