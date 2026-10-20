<?php
/**
 * School Photobook Website · api.php
 * ----------------------------------
 * The server half of the photo book. Put this file and index.html in the same
 * folder on any web host that runs PHP 7.2 or newer, then open that folder in a
 * browser. The first visit walks you through setup: what your class is called,
 * your school, a class color, a class code (for the class) and an admin code
 * (just for you).
 *
 * There's no database. Everything the site saves goes in a "photobook-data"
 * folder that this file makes next to itself: the photos, small copies for the
 * grid, captions, reactions, comments, and the private upload log (who posted
 * each photo, from which IP address and browser) that only admins can see.
 * Nothing in that folder can be opened straight from the web. Photos only load
 * for people who entered the class code.
 *
 * To back up the whole photo book, download that folder.
 * To start setup over (for example, if you forgot the admin code), delete
 * photobook-data/config.php. Photos, comments and hearts are kept.
 *
 * Works on PHP 7.2 and newer.
 */

const PB_APP = 'photobook';
const PB_VERSION = 1;
const PB_DIR = __DIR__ . '/photobook-data';
const PB_ALBUMS = ['School Days', 'Class Events', 'Trips', 'Sports', 'Friends', 'Random'];
const PB_COLORS = ['sky', 'navy', 'teal', 'green', 'gold', 'orange', 'red', 'pink', 'purple', 'gray'];
const PB_REACTIONS = ['love', 'lol', 'fire', 'cry'];
const PB_TITLE_LEN = 40;        // longest photo book name, like "Class of 2029"
const PB_SCHOOL_LEN = 60;       // longest school name
const PB_COMMENT_LEN = 280;     // longest comment, in characters
const PB_COMMENTS_MAX = 300;    // most comments one photo can have
const PB_ALBUMS_MAX = 20;       // most albums (chapters) the book can have
const PB_MAX_PHOTO = 15728640;  // 15 MB. The site shrinks photos before sending, so real ones are about 1 MB.
const PB_MAX_SMALL = 3145728;   // 3 MB
const PB_CLASS_DAYS = 180;      // how long a phone or laptop stays signed in with the class code
const PB_ADMIN_DAYS = 14;       // how long admin mode stays on
const PB_TRACE_MAX = 3000;      // upload log entries kept (oldest drop off first)
const PB_BLOCKS_MAX = 500;      // most blocked IPs/devices
const PB_REQUESTS_MAX = 3000;   // take-down requests kept in the request log (oldest drop off first)
const PB_BLOCKED_MSG = 'You have been blocked from posting. If you think this is a mistake, talk to one of the people who run the photo book.';

ini_set('display_errors', '0');
error_reporting(E_ALL);

class PBError extends Exception
{
    public $err;
    public $http;

    public function __construct($err, $message, $http = 400)
    {
        parent::__construct($message);
        $this->err = $err;
        $this->http = $http;
    }
}

try {
    pb_dirs();
    if (isset($_GET['img'])) {
        pb_image();
        exit;
    }
    $routes = [
        'status' => ['pb_status', 'GET'],
        'setup' => ['pb_setup', 'POST'],
        'login' => ['pb_login', 'POST'],
        'logout' => ['pb_logout', 'POST'],
        'book' => ['pb_book', 'GET'],
        'upload' => ['pb_upload', 'POST'],
        'react' => ['pb_react', 'POST'],
        'comments' => ['pb_comments', 'GET'],
        'comment' => ['pb_comment', 'POST'],
        'flag' => ['pb_flag', 'POST'],
        'remove' => ['pb_remove', 'POST'],
        'admin_login' => ['pb_admin_login', 'POST'],
        'admin_logout' => ['pb_admin_logout', 'POST'],
        'admin' => ['pb_admin', 'POST'],
        'replace' => ['pb_replace', 'POST'],
        'albums' => ['pb_albums', 'POST'],
        'settings' => ['pb_settings', 'POST'],
        'trace' => ['pb_trace', 'GET'],
        'block' => ['pb_block', 'POST'],
        'stored' => ['pb_stored', 'GET'],
        'requests' => ['pb_requests', 'GET'],
    ];
    $action = isset($_GET['a']) ? (string)$_GET['a'] : '';
    if (!isset($routes[$action])) {
        throw new PBError('bad_request', 'Unknown action.', 404);
    }
    list($fn, $method) = $routes[$action];
    if ($method === 'POST') {
        if (($_SERVER['REQUEST_METHOD'] ?? '') !== 'POST') {
            throw new PBError('bad_request', 'Use POST for this.', 405);
        }
        // Browsers only send this header from the photo book's own page, which blocks forged requests.
        if (($_SERVER['HTTP_X_PHOTOBOOK'] ?? '') !== '1') {
            throw new PBError('bad_request', 'Missing request header.', 400);
        }
    }
    $fn();
} catch (PBError $e) {
    pb_out(['ok' => false, 'error' => $e->err, 'message' => $e->getMessage()], $e->http);
} catch (Throwable $e) {
    error_log('photobook: ' . $e);
    pb_out(['ok' => false, 'error' => 'server', 'message' => 'Something went wrong on the server. Try again in a moment.'], 500);
}

/* ------------------------------------------------------------------ */
/* Actions                                                             */
/* ------------------------------------------------------------------ */

/**
 * Anyone can call this. Besides whether the book is set up and whether this browser is signed in,
 * it returns the book's name, school and color, so the sign-in screen can show them.
 */
function pb_status()
{
    $c = pb_config();
    pb_out([
        'ok' => true,
        'app' => PB_APP,
        'version' => PB_VERSION,
        'setup' => !$c,
        'member' => $c ? pb_is_member() : false,
        'admin' => $c ? pb_is_admin() : false,
        'brand' => pb_brand($c ?: []),
    ]);
}

/**
 * The first visit sets the book up: its name, school and color, the class code and the admin code.
 * Whoever does this becomes the first admin. After that, this returns "already_setup".
 */
function pb_setup()
{
    $in = pb_in();
    $class = pb_code($in['classCode'] ?? '');
    $admin = pb_code($in['adminCode'] ?? '');
    $title = pb_clean($in['title'] ?? '', PB_TITLE_LEN);
    $school = pb_clean($in['school'] ?? '', PB_SCHOOL_LEN);
    $color = (string)($in['color'] ?? 'sky');
    if ($title === '') {
        throw new PBError('need_title', 'Give your photo book a name, like “Class of 2029”.');
    }
    if (!in_array($color, PB_COLORS, true)) {
        $color = 'sky';
    }
    pb_check_codes($class, $admin);
    pb_tx(function () use ($class, $admin, $title, $school, $color) {
        if (pb_load('config', null)) {
            throw new PBError('already_setup', 'This photo book is already set up. Enter the class code instead.', 409);
        }
        pb_save('config', [
            'classHash' => password_hash($class, PASSWORD_DEFAULT),
            'adminHash' => password_hash($admin, PASSWORD_DEFAULT),
            'classVer' => 1,
            'adminVer' => 1,
            'secret' => bin2hex(random_bytes(32)),
            'title' => $title,
            'school' => $school,
            'color' => $color,
            'albums' => PB_ALBUMS,
            'graduationDate' => '',
            'createdAt' => time(),
        ]);
        if (!is_file(pb_file('book'))) {
            pb_save('book', ['photos' => []]);
        }
    });
    $c = pb_config(true);
    pb_grant_class();
    pb_grant_admin();
    pb_vid();
    pb_out(['ok' => true, 'admin' => true, 'settings' => pb_public_settings($c)]);
}

function pb_login()
{
    $c = pb_need_config();
    $key = 'login:' . pb_client_key();
    if (pb_hits($key, 900) >= 30) {
        throw new PBError('slow_down', 'Too many tries. Wait 15 minutes, then try again.', 429);
    }
    $code = pb_code(pb_in()['code'] ?? '');
    if ($code !== '' && password_verify($code, $c['classHash'])) {
        pb_grant_class();
        pb_vid();
        pb_out(['ok' => true, 'admin' => false]);
    }
    if ($code !== '' && password_verify($code, $c['adminHash'])) {
        pb_grant_class();
        pb_grant_admin();
        pb_vid();
        pb_out(['ok' => true, 'admin' => true]);
    }
    pb_hit($key);
    sleep(1); // slows down guessing
    throw new PBError('wrong_code', 'That code didn’t work. Check it with a classmate.', 401);
}

function pb_logout()
{
    pb_cookie('pb_class', '', 0);
    pb_cookie('pb_admin', '', 0);
    pb_out(['ok' => true]);
}

function pb_book()
{
    pb_need_member();
    $c = pb_config();
    $admin = pb_is_admin();
    $book = pb_load_book();
    $reactions = pb_load('reactions', []);
    $comments = pb_load('comments', []);
    $vid = pb_vid();
    $photos = [];
    $mine = [];
    foreach ((array)($book['photos'] ?? []) as $p) {
        if (!pb_can_see($p, $admin)) {
            continue;
        }
        $photos[] = pb_photo_out($p, pb_comment_stat($comments[$p['id']] ?? []), $admin);
        $mask = (int)($reactions[$p['id']][$vid] ?? 0);
        if ($mask) {
            $mine[$p['id']] = pb_mask_names($mask);
        }
    }
    pb_out([
        'ok' => true,
        'photos' => $photos,
        'mine' => (object)$mine,
        'settings' => pb_public_settings($c),
        'admin' => $admin,
        'blocked' => !$admin && pb_blocked_entry() !== null,
    ] + ($admin ? ['storedCount' => count(array_filter((array)($book['photos'] ?? []), function ($p) {
        return ($p['status'] ?? '') === 'stored';
    }))] : []));
}

function pb_upload()
{
    pb_need_member();
    pb_need_not_blocked();
    $c = pb_config();
    $vid = pb_vid();
    if (pb_hits('up:' . $vid, 3600) >= 150) {
        throw new PBError('slow_down', 'That’s a lot of photos in one hour. Take a break and add more later.', 429);
    }
    $realName = pb_clean($_POST['name'] ?? '', 40);
    if ($realName === '') {
        throw new PBError('need_name', 'Put your name first. You can still post anonymously.');
    }
    $anon = (string)($_POST['anon'] ?? '') === '1';
    $limit = pb_ini_bytes((string)ini_get('post_max_size'));
    if ($limit > 0 && (int)($_SERVER['CONTENT_LENGTH'] ?? 0) > $limit) {
        throw new PBError('too_large', 'That photo is too big for the server.', 413);
    }
    if (empty($_FILES['full'])) {
        throw new PBError('bad_request', 'No photo arrived. Try again.');
    }
    $full = pb_image_in($_FILES['full'], PB_MAX_PHOTO);
    $small = null;
    if (!empty($_FILES['small']) && (int)($_FILES['small']['error'] ?? UPLOAD_ERR_NO_FILE) !== UPLOAD_ERR_NO_FILE) {
        $small = pb_image_in($_FILES['small'], PB_MAX_SMALL);
    }

    $base = bin2hex(random_bytes(12));
    $fullName = $base . '.' . $full['ext'];
    if (!@move_uploaded_file($full['tmp'], PB_DIR . '/photos/' . $fullName)) {
        throw new PBError('storage', 'The server couldn’t save that photo. The hosting space may be full.', 500);
    }
    @chmod(PB_DIR . '/photos/' . $fullName, 0644);
    $smallName = '';
    if ($small) {
        $smallName = $base . '.' . $small['ext'];
        if (@move_uploaded_file($small['tmp'], PB_DIR . '/small/' . $smallName)) {
            @chmod(PB_DIR . '/small/' . $smallName, 0644);
        } else {
            $smallName = '';
        }
    }

    $photo = [
        'id' => 'p_' . bin2hex(random_bytes(8)),
        'file' => $fullName,
        'small' => $smallName,
        'name' => $anon ? '' : $realName,
        'anon' => $anon ? 1 : 0,
        'caption' => pb_clean($_POST['caption'] ?? '', 140),
        'album' => pb_album($_POST['album'] ?? '', $c['albums'] ?? PB_ALBUMS),
        'createdAt' => pb_now_ms(),
        'w' => $full['w'],
        'h' => $full['h'],
        'r' => array_fill_keys(PB_REACTIONS, 0),
        'status' => 'live',
        'featured' => 0,
        'by' => pb_owner_tag(),
    ];
    // Who posted it, kept privately for admins (the real name lives only here).
    $trace = pb_trace_entry($photo, $realName, $anon, PB_DIR . '/photos/' . $fullName);
    pb_tx(function () use ($photo, $trace) {
        $book = pb_load_book();
        $book['photos'][] = $photo;
        pb_save('book', $book);
        pb_trace_add($trace);
    });
    pb_hit('up:' . $vid);
    pb_out(['ok' => true, 'photo' => pb_photo_out($photo)]);
}

function pb_react()
{
    pb_need_member();
    $in = pb_in();
    $id = pb_id($in['id'] ?? '');
    $k = array_search((string)($in['kind'] ?? ''), PB_REACTIONS, true);
    if ($id === '' || $k === false) {
        throw new PBError('bad_request', 'Unknown reaction.');
    }
    $bit = 1 << $k;
    $vid = pb_vid();
    $on = array_key_exists('on', $in) ? (bool)$in['on'] : null;
    $result = pb_tx(function () use ($id, $bit, $vid, $on) {
        $book = pb_load_book();
        $i = pb_find($book['photos'], $id);
        if ($i < 0 || ($book['photos'][$i]['status'] ?? 'live') !== 'live') {
            throw new PBError('gone', 'That photo was taken down.', 404);
        }
        $all = pb_load('reactions', []);
        $mask = (int)($all[$id][$vid] ?? 0);
        $want = $on === null ? !($mask & $bit) : $on;
        $next = $want ? ($mask | $bit) : ($mask & ~$bit);
        if ($next !== $mask) {
            if ($next) {
                $all[$id][$vid] = $next;
            } else {
                unset($all[$id][$vid]);
            }
            if (empty($all[$id])) {
                unset($all[$id]);
            }
            $counts = array_fill_keys(PB_REACTIONS, 0);
            foreach ((array)($all[$id] ?? []) as $m) {
                foreach (PB_REACTIONS as $j => $name) {
                    if ((int)$m & (1 << $j)) {
                        $counts[$name]++;
                    }
                }
            }
            $book['photos'][$i]['r'] = $counts;
            pb_save('reactions', $all);
            pb_save('book', $book);
        }
        return ['reactions' => pb_counts($book['photos'][$i]), 'mine' => pb_mask_names($next)];
    });
    pb_out(['ok' => true] + $result);
}

/** The comments on one photo, oldest first. Admins also see comments someone reported. */
function pb_comments()
{
    pb_need_member();
    $admin = pb_is_admin();
    $id = pb_id($_GET['id'] ?? '');
    $book = pb_load_book();
    $i = $id === '' ? -1 : pb_find($book['photos'], $id);
    if ($i < 0 || !pb_can_see($book['photos'][$i], $admin)) {
        throw new PBError('gone', 'That photo was taken down.', 404);
    }
    $list = (array)(pb_load('comments', [])[$id] ?? []);
    $out = [];
    foreach ($list as $c) {
        $status = $c['status'] ?? 'live';
        if ($status === 'live' || ($admin && $status === 'flagged')) {
            $out[] = pb_comment_out($c);
        }
    }
    pb_out(['ok' => true, 'comments' => $out] + pb_stat_out(pb_comment_stat($list), $admin));
}

function pb_comment()
{
    pb_need_member();
    pb_need_not_blocked();
    $vid = pb_vid();
    if (pb_hits('cmt:' . $vid, 3600) >= 60) {
        throw new PBError('slow_down', 'That’s a lot of comments for one hour. Take a break and come back later.', 429);
    }
    $in = pb_in();
    $id = pb_id($in['id'] ?? '');
    $text = pb_clean($in['text'] ?? '', PB_COMMENT_LEN);
    if ($id === '') {
        throw new PBError('bad_request', 'Unknown photo.');
    }
    if ($text === '') {
        throw new PBError('bad_request', 'Write a comment first.');
    }
    $comment = [
        'id' => 'c_' . bin2hex(random_bytes(8)),
        'name' => pb_clean($in['name'] ?? '', 40),
        'text' => $text,
        'createdAt' => pb_now_ms(),
        'status' => 'live',
        'by' => pb_owner_tag(),
    ];
    $stat = pb_tx(function () use ($id, $comment) {
        $book = pb_load_book();
        $i = pb_find($book['photos'], $id);
        if ($i < 0 || ($book['photos'][$i]['status'] ?? 'live') !== 'live') {
            throw new PBError('gone', 'That photo was taken down.', 404);
        }
        $all = pb_load('comments', []);
        $list = (array)($all[$id] ?? []);
        if (count($list) >= PB_COMMENTS_MAX) {
            throw new PBError('full', 'This photo has a ton of comments already.', 409);
        }
        $list[] = $comment;
        $all[$id] = $list;
        pb_save('comments', $all);
        return pb_comment_stat($list);
    });
    pb_hit('cmt:' . $vid);
    pb_out(['ok' => true, 'comment' => pb_comment_out($comment)] + pb_stat_out($stat, pb_is_admin()));
}

/**
 * Anyone in the class can ask for a photo or a comment to come down. It hides right away,
 * until an admin looks at it.
 */
function pb_flag()
{
    pb_need_member();
    pb_need_not_blocked();
    $vid = pb_vid();
    if (pb_hits('flag:' . $vid, 3600) >= 30) {
        throw new PBError('slow_down', 'That’s a lot of requests. An admin will take a look soon.', 429);
    }
    $in = pb_in();
    if (($in['kind'] ?? 'photo') === 'comment') {
        $photoId = pb_id($in['photo'] ?? '');
        $cid = pb_id($in['id'] ?? '', 'c');
        if ($photoId === '' || $cid === '') {
            throw new PBError('bad_request', 'Unknown comment.');
        }
        $stat = pb_tx(function () use ($photoId, $cid) {
            $all = pb_load('comments', []);
            $list = (array)($all[$photoId] ?? []);
            $j = pb_find($list, $cid);
            if ($j < 0) {
                throw new PBError('gone', 'That comment is already gone.', 404);
            }
            if (($list[$j]['status'] ?? 'live') === 'live') {
                $list[$j]['status'] = 'flagged';
                $list[$j]['flaggedAt'] = pb_now_ms();
                $all[$photoId] = $list;
                pb_save('comments', $all);
            }
            return pb_comment_stat($list);
        });
        pb_hit('flag:' . $vid);
        pb_out(['ok' => true] + pb_stat_out($stat, pb_is_admin()));
    }
    $id = pb_id($in['id'] ?? '');
    if ($id === '') {
        throw new PBError('bad_request', 'Unknown photo.');
    }
    // Asking to take a photo down hides it from everyone, so it takes a name and a reason, and the
    // request is saved with the IP address and device (admins can trace it and block abusers).
    $admin = pb_is_admin();
    $name = pb_clean($in['name'] ?? '', 40);
    $reason = pb_clean($in['reason'] ?? '', 300, true);
    $rlen = function_exists('mb_strlen') ? mb_strlen($reason, 'UTF-8') : strlen($reason);
    if (!$admin) {
        if ($name === '') {
            throw new PBError('need_name', 'Put your name in first.');
        }
        if ($rlen < 4) {
            throw new PBError('need_reason', 'Say why it should come down.');
        }
        $ip = pb_ip_norm((string)($_SERVER['REMOTE_ADDR'] ?? ''));
        if (pb_hits('takedown:' . $vid, 3600) >= 6 || ($ip !== '' && pb_hits('takedown-ip:' . $ip, 3600) >= 20)) {
            throw new PBError('slow_down', 'That’s a lot of take-down requests. An admin will look at the ones you sent.', 429);
        }
    }
    $info = pb_request_info($in['client'] ?? null);
    $rid = pb_tx(function () use ($id, $name, $reason, $info, $admin) {
        $book = pb_load_book();
        $i = pb_find($book['photos'], $id);
        if ($i < 0) {
            throw new PBError('gone', 'That photo was already taken down.', 404);
        }
        $p = $book['photos'][$i];
        $rid = 'r_' . bin2hex(random_bytes(8));
        $was = (string)($p['status'] ?? 'live');
        if ($was === 'live') {
            $book['photos'][$i]['status'] = 'flagged';
            $book['photos'][$i]['flaggedAt'] = pb_now_ms();
            $book['photos'][$i]['flagReq'] = $rid;
            $book['photos'][$i]['flagName'] = $admin && $name === '' ? '(an admin)' : $name;
            $book['photos'][$i]['flagReason'] = $reason;
            pb_save('book', $book);
        }
        $log = pb_load('requests', []);
        $log[] = [
            'id' => $rid,
            'photo' => $id,
            'at' => pb_now_ms(),
            'name' => $name,
            'reason' => $reason,
            'hid' => $was === 'live',
            'photoName' => (string)($p['name'] ?? ''),
            'photoCaption' => (string)($p['caption'] ?? ''),
        ] + $info;
        if (count($log) > PB_REQUESTS_MAX) {
            $log = array_slice($log, -PB_REQUESTS_MAX);
        }
        pb_save('requests', $log);
        return $rid;
    });
    pb_hit('flag:' . $vid);
    if (!$admin) {
        pb_hit('takedown:' . $vid);
        $ip = pb_ip_norm((string)($_SERVER['REMOTE_ADDR'] ?? ''));
        if ($ip !== '') {
            pb_hit('takedown-ip:' . $ip);
        }
    }
    pb_out(['ok' => true, 'request' => $rid]);
}

/** Deletes a photo or a comment for good. Admins can delete anything; everyone else only what they added on this device. */
function pb_remove()
{
    pb_need_member();
    $in = pb_in();
    $kind = (string)($in['kind'] ?? '');
    $admin = pb_is_admin();
    $tag = pb_owner_tag();
    $mine = function ($item) use ($admin, $tag) {
        $owner = !empty($item['by']) && hash_equals((string)$item['by'], $tag);
        if (!$admin && !$owner) {
            throw new PBError('not_allowed', 'Only the person who added this, or an admin, can delete it.', 403);
        }
    };

    if ($kind === 'comment') {
        $photoId = pb_id($in['photo'] ?? '');
        $cid = pb_id($in['id'] ?? '', 'c');
        if ($photoId === '' || $cid === '') {
            throw new PBError('bad_request', 'Unknown comment.');
        }
        $stat = pb_tx(function () use ($photoId, $cid, $mine) {
            $all = pb_load('comments', []);
            $list = (array)($all[$photoId] ?? []);
            $j = pb_find($list, $cid);
            if ($j < 0) {
                throw new PBError('gone', 'That comment is already gone.', 404);
            }
            $mine($list[$j]);
            array_splice($list, $j, 1);
            if ($list) {
                $all[$photoId] = $list;
            } else {
                unset($all[$photoId]);
            }
            pb_save('comments', $all);
            return pb_comment_stat($list);
        });
        pb_out(['ok' => true] + pb_stat_out($stat, $admin));
    }

    $id = pb_id($in['id'] ?? '');
    if ($kind !== 'photo' || $id === '') {
        throw new PBError('bad_request', 'Unknown item.');
    }
    $gone = pb_tx(function () use ($id, $mine, $admin) {
        $book = pb_load_book();
        $i = pb_find($book['photos'], $id);
        if ($i < 0) {
            throw new PBError('gone', 'That’s already gone.', 404);
        }
        $item = $book['photos'][$i];
        $mine($item);
        array_splice($book['photos'], $i, 1);
        pb_save('book', $book);
        // The upload log keeps who posted it, even after it's deleted.
        pb_trace_deleted($item, $admin ? 'admin' : 'uploader');
        pb_requests_mark($id, 'deletedAt');
        // Its hearts and comments go with it.
        foreach (['reactions', 'comments'] as $name) {
            $all = pb_load($name, []);
            if (isset($all[$id])) {
                unset($all[$id]);
                pb_save($name, $all);
            }
        }
        return $item;
    });
    @unlink(PB_DIR . '/photos/' . basename((string)($gone['file'] ?? '')));
    if (!empty($gone['small'])) {
        @unlink(PB_DIR . '/small/' . basename((string)$gone['small']));
    }
    pb_out(['ok' => true]);
}

function pb_admin_login()
{
    $c = pb_need_config();
    $key = 'admin:' . pb_client_key();
    if (pb_hits($key, 900) >= 15) {
        throw new PBError('slow_down', 'Too many tries. Wait 15 minutes, then try again.', 429);
    }
    $code = pb_code(pb_in()['code'] ?? '');
    if ($code === '' || !password_verify($code, $c['adminHash'])) {
        pb_hit($key);
        sleep(1);
        throw new PBError('wrong_admin', 'That admin code didn’t work.', 401);
    }
    pb_grant_class();
    pb_grant_admin();
    pb_out(['ok' => true]);
}

function pb_admin_logout()
{
    pb_cookie('pb_admin', '', 0);
    pb_out(['ok' => true]);
}

/**
 * Admin tools for one photo: feature it, take it off the featured strip, put a flagged photo back,
 * put back a comment on it that someone reported, or edit who posted it, its caption and its album.
 */
function pb_admin()
{
    pb_need_admin();
    $in = pb_in();
    $id = pb_id($in['id'] ?? '');
    $op = (string)($in['op'] ?? '');
    if ($id === '' || !in_array($op, ['feature', 'unfeature', 'restore', 'restore_comment', 'edit', 'store'], true)) {
        throw new PBError('bad_request', 'Unknown admin action.');
    }
    if ($op === 'restore_comment') {
        $cid = pb_id($in['comment'] ?? '', 'c');
        if ($cid === '') {
            throw new PBError('bad_request', 'Unknown comment.');
        }
        $r = pb_tx(function () use ($id, $cid) {
            $all = pb_load('comments', []);
            $list = (array)($all[$id] ?? []);
            $j = pb_find($list, $cid);
            if ($j < 0) {
                throw new PBError('gone', 'That comment is gone.', 404);
            }
            $list[$j]['status'] = 'live';
            unset($list[$j]['flaggedAt']);
            $all[$id] = $list;
            pb_save('comments', $all);
            return [$list[$j], pb_comment_stat($list)];
        });
        pb_out(['ok' => true, 'comment' => pb_comment_out($r[0])] + pb_stat_out($r[1], true));
    }
    $edit = [];
    if ($op === 'edit') {
        if (array_key_exists('name', $in)) {
            $edit['name'] = pb_clean($in['name'], 40);
        }
        if (array_key_exists('caption', $in)) {
            $edit['caption'] = pb_clean($in['caption'], 140);
        }
        if (array_key_exists('album', $in)) {
            $album = pb_clean($in['album'], 40);
            if (!in_array($album, (array)(pb_config()['albums'] ?? PB_ALBUMS), true)) {
                throw new PBError('bad_request', 'Pick one of the albums.');
            }
            $edit['album'] = $album;
        }
        if (!$edit) {
            throw new PBError('bad_request', 'Nothing to change.');
        }
    }
    $photo = pb_tx(function () use ($id, $op, $edit) {
        $book = pb_load_book();
        $i = pb_find($book['photos'], $id);
        if ($i < 0) {
            throw new PBError('gone', 'That photo is gone.', 404);
        }
        if ($op === 'feature') {
            $book['photos'][$i]['featured'] = pb_now_ms();
            unset($book['photos'][$i]['nofeat']);
        } elseif ($op === 'unfeature') {
            // Off the featured strip for good, even if it has lots of hearts. Featuring it again undoes this.
            $book['photos'][$i]['featured'] = 0;
            $book['photos'][$i]['nofeat'] = 1;
        } elseif ($op === 'edit') {
            foreach ($edit as $k => $v) {
                $book['photos'][$i][$k] = $v;
            }
        } elseif ($op === 'store') {
            // Hidden storage: out of the photo book (and the admin review list) but kept, so an
            // admin can look at it later, put it back up, or delete it.
            $book['photos'][$i]['status'] = 'stored';
            $book['photos'][$i]['storedAt'] = pb_now_ms();
            $book['photos'][$i]['featured'] = 0;
        } else {
            $book['photos'][$i]['status'] = 'live';
            unset($book['photos'][$i]['flaggedAt'], $book['photos'][$i]['storedAt'],
                $book['photos'][$i]['flagReq'], $book['photos'][$i]['flagName'], $book['photos'][$i]['flagReason']);
        }
        pb_save('book', $book);
        if ($op === 'restore') {
            pb_requests_mark($id, 'restoredAt');
        } elseif ($op === 'store') {
            pb_requests_mark($id, 'storedAt');
        }
        return $book['photos'][$i];
    });
    $stat = pb_comment_stat(pb_load('comments', [])[$id] ?? []);
    pb_out(['ok' => true, 'photo' => pb_photo_out($photo, $stat, true)]);
}

/** Admin album tools: add one, rename one (its photos follow), remove one (its photos move to another), reorder. */
function pb_albums()
{
    pb_need_admin();
    $in = pb_in();
    $op = (string)($in['op'] ?? '');
    if (!in_array($op, ['add', 'rename', 'remove', 'move'], true)) {
        throw new PBError('bad_request', 'Unknown album action.');
    }
    $name = pb_clean($in['name'] ?? '', 40);
    $to = pb_clean($in['to'] ?? '', 40);
    $dir = (int)($in['dir'] ?? 0);
    if ($name === '') {
        throw new PBError('bad_request', 'Type an album name.');
    }
    $res = pb_tx(function () use ($op, $name, $to, $dir) {
        $c = pb_load('config', null);
        if (!$c) {
            throw new PBError('needs_setup', 'This photo book isn’t set up yet.', 409);
        }
        $albums = array_values((array)($c['albums'] ?? PB_ALBUMS));
        $at = pb_album_index($albums, $name);
        $moved = 0;
        if ($op === 'add') {
            if ($at >= 0) {
                throw new PBError('bad_request', 'There’s already an album called “' . $albums[$at] . '”.');
            }
            if (count($albums) >= PB_ALBUMS_MAX) {
                throw new PBError('bad_request', 'The book can have ' . PB_ALBUMS_MAX . ' albums at most.');
            }
            // New albums go in just before Random, so Random stays the last chapter.
            $random = pb_album_index($albums, 'Random');
            array_splice($albums, $random >= 0 ? $random : count($albums), 0, [$name]);
        } else {
            if ($at < 0) {
                throw new PBError('gone', 'That album is already gone.', 404);
            }
            $old = $albums[$at];
            if ($op === 'move') {
                $j = $at + ($dir < 0 ? -1 : 1);
                if ($j >= 0 && $j < count($albums)) {
                    $albums[$at] = $albums[$j];
                    $albums[$j] = $old;
                }
            } elseif ($op === 'rename') {
                if ($to === '') {
                    throw new PBError('bad_request', 'Type the new name.');
                }
                $clash = pb_album_index($albums, $to);
                if ($clash >= 0 && $clash !== $at) {
                    throw new PBError('bad_request', 'There’s already an album called “' . $albums[$clash] . '”.');
                }
                $albums[$at] = $to;
                $moved = pb_move_album_photos($old, $to);
            } else {
                if (count($albums) <= 1) {
                    throw new PBError('bad_request', 'Keep at least one album.');
                }
                $dest = pb_album_index($albums, $to);
                if ($dest < 0 || $dest === $at) {
                    throw new PBError('bad_request', 'Pick the album its photos should move to.');
                }
                $moved = pb_move_album_photos($old, $albums[$dest]);
                array_splice($albums, $at, 1);
            }
        }
        $c['albums'] = $albums;
        pb_save('config', $c);
        return [$c, $moved];
    });
    pb_config(true);
    pb_out(['ok' => true, 'settings' => pb_public_settings($res[0]), 'moved' => $res[1]]);
}

/** Admin settings: the book's name, school and color, new codes, the album list and the graduation date. */
function pb_settings()
{
    pb_need_admin();
    $in = pb_in();
    $newClass = isset($in['classCode']) && pb_code($in['classCode']) !== '' ? pb_code($in['classCode']) : null;
    $newAdmin = isset($in['adminCode']) && pb_code($in['adminCode']) !== '' ? pb_code($in['adminCode']) : null;
    pb_check_codes($newClass, $newAdmin);
    $brand = [];
    if (array_key_exists('title', $in)) {
        $title = pb_clean($in['title'], PB_TITLE_LEN);
        if ($title === '') {
            throw new PBError('need_title', 'Give your photo book a name, like “Class of 2029”.');
        }
        $brand['title'] = $title;
    }
    if (array_key_exists('school', $in)) {
        $brand['school'] = pb_clean($in['school'], PB_SCHOOL_LEN);
    }
    if (array_key_exists('color', $in)) {
        $color = (string)$in['color'];
        if (!in_array($color, PB_COLORS, true)) {
            throw new PBError('bad_request', 'Pick one of the colors.');
        }
        $brand['color'] = $color;
    }
    $albums = null;
    if (isset($in['albums']) && is_array($in['albums'])) {
        $albums = [];
        foreach ($in['albums'] as $a) {
            $a = pb_clean($a, 40);
            if ($a !== '' && !in_array($a, $albums, true) && count($albums) < PB_ALBUMS_MAX) {
                $albums[] = $a;
            }
        }
        if (!$albums) {
            throw new PBError('bad_request', 'Keep at least one album.');
        }
    }
    $grad = null;
    if (array_key_exists('graduationDate', $in)) {
        $grad = trim((string)$in['graduationDate']);
        if ($grad !== '' && !preg_match('/^\d{4}-\d{2}-\d{2}$/', $grad)) {
            throw new PBError('bad_request', 'Use a date like 2029-05-20.');
        }
    }
    $c = pb_tx(function () use ($newClass, $newAdmin, $brand, $albums, $grad) {
        $c = pb_load('config', null);
        if (!$c) {
            throw new PBError('needs_setup', 'This photo book isn’t set up yet.', 409);
        }
        if ($newClass !== null && $newAdmin === null && password_verify($newClass, $c['adminHash'])) {
            throw new PBError('bad_request', 'Use a different class code than your admin code.');
        }
        if ($newAdmin !== null && $newClass === null && password_verify($newAdmin, $c['classHash'])) {
            throw new PBError('bad_request', 'Use a different admin code than the class code.');
        }
        if ($newClass !== null) {
            $c['classHash'] = password_hash($newClass, PASSWORD_DEFAULT);
            $c['classVer'] = (int)$c['classVer'] + 1; // signs everyone out
        }
        if ($newAdmin !== null) {
            $c['adminHash'] = password_hash($newAdmin, PASSWORD_DEFAULT);
            $c['adminVer'] = (int)$c['adminVer'] + 1;
        }
        foreach ($brand as $k => $v) {
            $c[$k] = $v;
        }
        if ($albums !== null) {
            $c['albums'] = $albums;
        }
        if ($grad !== null) {
            $c['graduationDate'] = $grad;
        }
        pb_save('config', $c);
        return $c;
    });
    pb_config(true);
    pb_grant_class(); // keeps the admin who made the change signed in
    pb_grant_admin();
    pb_out(['ok' => true, 'settings' => pb_public_settings($c)]);
}

/** Sends one photo, only to people who entered the class code. */
function pb_image()
{
    $c = pb_config();
    if (!$c || !pb_is_member()) {
        pb_plain(401, 'Enter the class code first.');
    }
    $id = pb_id($_GET['img'] ?? '');
    $useSmall = ($_GET['s'] ?? '') === 't';
    $book = pb_load_book();
    $i = $id === '' ? -1 : pb_find($book['photos'], $id);
    if ($i < 0) {
        pb_plain(404, 'Photo not found.');
    }
    $p = $book['photos'][$i];
    $admin = pb_is_admin();
    if (!pb_can_see($p, $admin) && !($admin && ($p['status'] ?? '') === 'stored')) {
        pb_plain(404, 'Photo not found.');
    }
    $rel = ($useSmall && !empty($p['small'])) ? 'small/' . basename((string)$p['small']) : 'photos/' . basename((string)$p['file']);
    $path = PB_DIR . '/' . $rel;
    if (!is_file($path)) {
        pb_plain(404, 'Photo not found.');
    }
    $ext = strtolower(pathinfo($path, PATHINFO_EXTENSION));
    $type = $ext === 'png' ? 'image/png' : ($ext === 'webp' ? 'image/webp' : 'image/jpeg');
    $etag = '"' . $p['id'] . ($useSmall ? '-s' : '-f') . (empty($p['ver']) ? '' : '-v' . (int)$p['ver']) . '"';
    header('Content-Type: ' . $type);
    header('Cache-Control: private, max-age=31536000, immutable');
    header('ETag: ' . $etag);
    header('X-Content-Type-Options: nosniff');
    if (!empty($_GET['dl'])) {
        header('Content-Disposition: attachment; filename="photo-' . substr($p['id'], 2) . '.' . ($ext === 'jpeg' ? 'jpg' : $ext) . '"');
    } else {
        header('Content-Disposition: inline');
    }
    if (trim((string)($_SERVER['HTTP_IF_NONE_MATCH'] ?? '')) === $etag) {
        http_response_code(304);
        exit;
    }
    header('Content-Length: ' . filesize($path));
    readfile($path);
    exit;
}

/* ------------------------------------------------------------------ */
/* Upload log: tracing a photo back to who posted it (admins only)    */
/* ------------------------------------------------------------------ */

/**
 * Admins only. With ?id=p_… it returns everything saved about that upload, plus other
 * uploads from the same device or IP address. Without an id it lists recent uploads,
 * deleted ones included; ?q= searches names, IPs, devices, captions and browsers.
 */
function pb_trace()
{
    pb_need_admin();
    $log = pb_load('uploads', []);
    $book = pb_load_book();
    $byId = [];
    foreach ($book['photos'] as $p) {
        $byId[$p['id']] = $p;
    }
    $logged = [];
    foreach ($log as $e) {
        $logged[(string)($e['id'] ?? '')] = true;
    }
    // Photos posted before the log existed only have what the book saved.
    foreach ($book['photos'] as $p) {
        if (!isset($logged[$p['id']])) {
            $log[] = pb_trace_legacy($p);
        }
    }
    usort($log, function ($a, $b) {
        return (int)($b['at'] ?? 0) <=> (int)($a['at'] ?? 0);
    });

    $id = pb_id($_GET['id'] ?? '');
    if ($id !== '') {
        $entry = null;
        foreach ($log as $e) {
            if (($e['id'] ?? '') === $id) {
                $entry = $e;
                break;
            }
        }
        if (!$entry) {
            throw new PBError('gone', 'There’s no record of that photo.', 404);
        }
        $related = [];
        foreach ($log as $e) {
            if (($e['id'] ?? '') === $id) {
                continue;
            }
            $sameDevice = !empty($entry['device']) && ($e['device'] ?? '') === $entry['device'];
            $sameIp = !empty($entry['ip']) && ($e['ip'] ?? '') === $entry['ip'];
            if ($sameDevice || $sameIp) {
                $row = pb_trace_row($e, $byId);
                $row['match'] = $sameDevice && $sameIp ? 'both' : ($sameDevice ? 'device' : 'ip');
                $related[] = $row;
                if (count($related) >= 100) {
                    break;
                }
            }
        }
        $out = pb_trace_full($entry, $byId);
        pb_out(['ok' => true, 'entry' => $out, 'related' => $related]);
    }

    $q = pb_clean($_GET['q'] ?? '', 80);
    $q = function_exists('mb_strtolower') ? mb_strtolower($q, 'UTF-8') : strtolower($q);
    $rows = [];
    $total = 0;
    foreach ($log as $e) {
        if ($q !== '') {
            $hay = implode(' ', [
                $e['name'] ?? '', $e['ip'] ?? '', $e['device'] ?? '', $e['caption'] ?? '', $e['album'] ?? '',
                $e['ua'] ?? '', implode(' ', (array)($e['fwd'] ?? [])), $e['id'] ?? '',
            ]);
            $hay = function_exists('mb_strtolower') ? mb_strtolower($hay, 'UTF-8') : strtolower($hay);
            if (strpos($hay, $q) === false) {
                continue;
            }
        }
        $total++;
        if (count($rows) < 300) {
            $rows[] = pb_trace_row($e, $byId);
        }
    }
    pb_out(['ok' => true, 'uploads' => $rows, 'total' => $total]);
}

/** Everything about the request that posted a photo. */
function pb_trace_entry($photo, $realName, $anon, $path)
{
    $hdr = function ($k, $max) {
        return pb_clean($_SERVER[$k] ?? '', $max);
    };
    $fwd = [];
    $proxies = [
        'HTTP_X_FORWARDED_FOR' => 'X-Forwarded-For',
        'HTTP_CF_CONNECTING_IP' => 'CF-Connecting-IP',
        'HTTP_X_REAL_IP' => 'X-Real-IP',
        'HTTP_TRUE_CLIENT_IP' => 'True-Client-IP',
        'HTTP_FORWARDED' => 'Forwarded',
    ];
    foreach ($proxies as $k => $label) {
        $v = $hdr($k, 200);
        if ($v !== '') {
            $fwd[$label] = $v;
        }
    }
    // What the browser said about itself. It can't be fully trusted, but it helps.
    $c = json_decode((string)($_POST['client'] ?? ''), true);
    $c = is_array($c) ? $c : [];
    $str = function ($k, $max) use ($c) {
        return pb_clean($c[$k] ?? '', $max);
    };
    $num = function ($k) use ($c) {
        return isset($c[$k]) && is_numeric($c[$k]) ? (int)$c[$k] : null;
    };
    $client = [
        'tz' => $str('tz', 60),
        'tzOff' => $num('tzOff'),
        'lang' => $str('lang', 80),
        'platform' => $str('platform', 60),
        'model' => $str('model', 60),
        'mobile' => isset($c['mobile']) && is_bool($c['mobile']) ? $c['mobile'] : null,
        'screen' => $str('screen', 40),
        'touch' => $num('touch'),
        'file' => $str('file', 120),
        'fileSize' => $num('fileSize'),
        'fileType' => $str('fileType', 40),
        'fileDate' => $num('fileDate'),
    ];
    $ip = (string)($_SERVER['REMOTE_ADDR'] ?? '');
    return [
        'id' => $photo['id'],
        'at' => $photo['createdAt'],
        'name' => $realName,
        'anon' => $anon,
        'album' => $photo['album'],
        'caption' => $photo['caption'],
        'ip' => filter_var($ip, FILTER_VALIDATE_IP) ? $ip : pb_clean($ip, 60),
        'fwd' => (object)$fwd,
        'ua' => $hdr('HTTP_USER_AGENT', 400),
        'lang' => $hdr('HTTP_ACCEPT_LANGUAGE', 120),
        'ref' => $hdr('HTTP_REFERER', 200),
        'device' => pb_owner_tag(),
        'asAdmin' => pb_is_admin(),
        'client' => $client,
        'sha256' => is_file($path) ? (string)hash_file('sha256', $path) : '',
        'bytes' => is_file($path) ? (int)filesize($path) : 0,
        'w' => $photo['w'],
        'h' => $photo['h'],
    ];
}

/** Adds an upload to the log. Call inside pb_tx. */
function pb_trace_add($entry)
{
    $log = pb_load('uploads', []);
    $log[] = $entry;
    if (count($log) > PB_TRACE_MAX) {
        $log = array_slice($log, -PB_TRACE_MAX);
    }
    pb_save('uploads', $log);
}

/** Marks an upload deleted (keeping the record). Call inside pb_tx. */
function pb_trace_deleted($photo, $by)
{
    $log = pb_load('uploads', []);
    $j = pb_find($log, (string)$photo['id']);
    if ($j < 0) {
        $log[] = pb_trace_legacy($photo);
        $j = count($log) - 1;
    }
    $log[$j]['deletedAt'] = pb_now_ms();
    $log[$j]['deletedBy'] = $by;
    if (!empty($photo['flaggedAt'])) {
        $log[$j]['flaggedAt'] = (int)$photo['flaggedAt'];
    }
    if (count($log) > PB_TRACE_MAX) {
        $log = array_slice($log, -PB_TRACE_MAX);
    }
    pb_save('uploads', $log);
}

/** What's known about a photo posted before the upload log existed. */
function pb_trace_legacy($p)
{
    return [
        'id' => (string)$p['id'],
        'at' => (int)($p['createdAt'] ?? 0),
        'name' => (string)($p['name'] ?? ''),
        'anon' => !empty($p['anon']),
        'album' => (string)($p['album'] ?? ''),
        'caption' => (string)($p['caption'] ?? ''),
        'device' => (string)($p['by'] ?? ''),
        'w' => (int)($p['w'] ?? 0),
        'h' => (int)($p['h'] ?? 0),
        'legacy' => true,
    ];
}

function pb_trace_status($e, $byId)
{
    if (!empty($e['deletedAt'])) {
        return 'deleted';
    }
    $id = (string)($e['id'] ?? '');
    return isset($byId[$id]) ? (string)($byId[$id]['status'] ?? 'live') : 'deleted';
}

/** A short line for lists. */
function pb_trace_row($e, $byId)
{
    $id = (string)($e['id'] ?? '');
    return [
        'id' => $id,
        'at' => (int)($e['at'] ?? 0),
        'name' => (string)($e['name'] ?? ''),
        'anon' => !empty($e['anon']),
        'ip' => (string)($e['ip'] ?? ''),
        'device' => (string)($e['device'] ?? ''),
        'ua' => (string)($e['ua'] ?? ''),
        'album' => (string)($e['album'] ?? ''),
        'caption' => (string)($e['caption'] ?? ''),
        'status' => pb_trace_status($e, $byId),
        'small' => isset($byId[$id]) && !empty($byId[$id]['small']),
        'legacy' => !empty($e['legacy']),
    ];
}

function pb_trace_full($e, $byId)
{
    $out = $e;
    $id = (string)($e['id'] ?? '');
    $out['status'] = pb_trace_status($e, $byId);
    $out['small'] = isset($byId[$id]) && !empty($byId[$id]['small']);
    $out['fwd'] = (object)($e['fwd'] ?? []);
    if (isset($byId[$id]) && !empty($byId[$id]['flaggedAt'])) {
        $out['flaggedAt'] = (int)$byId[$id]['flaggedAt'];
    }
    unset($out['legacy']);
    $out['legacy'] = !empty($e['legacy']);
    $out['blocks'] = pb_blocks_for((string)($e['ip'] ?? ''), (string)($e['device'] ?? ''));
    return $out;
}

/**
 * Admins only. Photos moved to hidden storage, newest stored first.
 */
function pb_stored()
{
    pb_need_admin();
    $book = pb_load_book();
    $comments = pb_load('comments', []);
    $rows = [];
    foreach ($book['photos'] as $p) {
        if (($p['status'] ?? '') !== 'stored') {
            continue;
        }
        $row = pb_photo_out($p, pb_comment_stat($comments[$p['id']] ?? []), true);
        $row['storedAt'] = (int)($p['storedAt'] ?? 0);
        $row['flaggedAt'] = (int)($p['flaggedAt'] ?? 0);
        $rows[] = $row;
    }
    usort($rows, function ($a, $b) {
        return $b['storedAt'] <=> $a['storedAt'];
    });
    pb_out(['ok' => true, 'photos' => $rows]);
}

/* ------------------------------------------------------------------ */
/* Blocking (admins): stop an IP address or a device from posting      */
/* ------------------------------------------------------------------ */

/**
 * Admins only. op "list" returns every block. op "add" blocks the IP address and/or the
 * device of an upload in the log (id + what: both | ip | device), or an IP typed in (ip).
 * op "remove" (bid) lets them post again. Blocked people can still look at photos,
 * but can't upload, comment or report.
 */
function pb_block()
{
    pb_need_admin();
    $in = pb_in();
    $op = (string)($in['op'] ?? 'list');
    if ($op === 'list') {
        pb_out(['ok' => true, 'blocks' => pb_blocks_out(pb_load('blocks', []))]);
    }
    if ($op === 'add') {
        $note = pb_clean($in['note'] ?? '', 80);
        $ip = '';
        $device = '';
        $photo = '';
        $name = '';
        $id = pb_id($in['id'] ?? '');
        $rid = pb_rid($in['rid'] ?? '');
        if ($rid !== '') {
            $what = (string)($in['what'] ?? 'both');
            if (!in_array($what, ['both', 'ip', 'device'], true)) {
                throw new PBError('bad_request', 'Pick what to block.');
            }
            $entry = null;
            foreach (pb_load('requests', []) as $e) {
                if (($e['id'] ?? '') === $rid) {
                    $entry = $e;
                }
            }
            if (!$entry) {
                throw new PBError('gone', 'There’s no record of that request.', 404);
            }
            $ip = $what !== 'device' ? pb_ip_norm((string)($entry['ip'] ?? '')) : '';
            $device = $what !== 'ip' ? (string)($entry['device'] ?? '') : '';
            $photo = (string)($entry['photo'] ?? '');
            $name = (string)($entry['name'] ?? '');
            if ($ip === '' && $device === '') {
                throw new PBError('bad_request', 'There’s nothing saved for that request to block.');
            }
            $note = $note === '' ? 'take-down request' : $note;
        } elseif ($id !== '') {
            $what = (string)($in['what'] ?? 'both');
            if (!in_array($what, ['both', 'ip', 'device'], true)) {
                throw new PBError('bad_request', 'Pick what to block.');
            }
            $entry = null;
            foreach (pb_load('uploads', []) as $e) {
                if (($e['id'] ?? '') === $id) {
                    $entry = $e;
                }
            }
            if (!$entry) {
                $book = pb_load_book();
                $i = pb_find($book['photos'], $id);
                if ($i >= 0) {
                    $entry = pb_trace_legacy($book['photos'][$i]);
                }
            }
            if (!$entry) {
                throw new PBError('gone', 'There’s no record of that photo.', 404);
            }
            if ($what !== 'device') {
                $ip = pb_ip_norm((string)($entry['ip'] ?? ''));
            }
            if ($what !== 'ip') {
                $device = (string)($entry['device'] ?? '');
            }
            $photo = $id;
            $name = (string)($entry['name'] ?? '');
            if ($ip === '' && $device === '') {
                throw new PBError('bad_request', $what === 'ip' ? 'There’s no IP address saved for that photo.' : 'There’s nothing saved for that photo to block.');
            }
        } else {
            $raw = trim((string)($in['ip'] ?? ''));
            $ip = pb_ip_norm($raw);
            if ($ip === '') {
                throw new PBError('bad_ip', 'That doesn’t look like an IP address (like 203.0.113.24).');
            }
        }
        $blocks = pb_tx(function () use ($ip, $device, $photo, $name, $note) {
            $list = pb_load('blocks', []);
            foreach ($list as $b) {
                if (($b['ip'] ?? '') === $ip && ($b['device'] ?? '') === $device) {
                    return $list; // already blocked
                }
            }
            if (count($list) >= PB_BLOCKS_MAX) {
                throw new PBError('full', 'That’s a lot of blocks. Unblock some old ones first.', 409);
            }
            $list[] = [
                'id' => 'b_' . bin2hex(random_bytes(6)),
                'ip' => $ip,
                'device' => $device,
                'photo' => $photo,
                'name' => $name,
                'note' => $note,
                'at' => pb_now_ms(),
            ];
            pb_save('blocks', $list);
            return $list;
        });
        pb_out(['ok' => true, 'blocks' => pb_blocks_out($blocks), 'mine' => $ip !== '' && $ip === pb_ip_norm((string)($_SERVER['REMOTE_ADDR'] ?? ''))]);
    }
    if ($op === 'remove') {
        $bid = (string)($in['bid'] ?? '');
        if (!preg_match('/^b_[a-f0-9]{12}$/', $bid)) {
            throw new PBError('bad_request', 'Unknown block.');
        }
        $blocks = pb_tx(function () use ($bid) {
            $list = pb_load('blocks', []);
            $j = pb_find($list, $bid);
            if ($j < 0) {
                throw new PBError('gone', 'That block was already removed.', 404);
            }
            array_splice($list, $j, 1);
            pb_save('blocks', $list);
            return $list;
        });
        pb_out(['ok' => true, 'blocks' => pb_blocks_out($blocks)]);
    }
    throw new PBError('bad_request', 'Unknown block action.');
}

/** A take-down request id (r_…), or ''. */
function pb_rid($v)
{
    return (is_string($v) && preg_match('/^r_[a-f0-9]{16}$/', $v)) ? $v : '';
}

/** Who is sending this request: IP address, proxy headers, browser, device and what the page said about itself. */
function pb_request_info($c)
{
    $hdr = function ($k, $max) {
        return pb_clean($_SERVER[$k] ?? '', $max);
    };
    $fwd = [];
    foreach (['HTTP_X_FORWARDED_FOR' => 'X-Forwarded-For', 'HTTP_CF_CONNECTING_IP' => 'CF-Connecting-IP', 'HTTP_X_REAL_IP' => 'X-Real-IP',
        'HTTP_TRUE_CLIENT_IP' => 'True-Client-IP', 'HTTP_FORWARDED' => 'Forwarded'] as $k => $label) {
        $v = $hdr($k, 200);
        if ($v !== '') {
            $fwd[$label] = $v;
        }
    }
    if (is_string($c)) {
        $c = json_decode($c, true);
    }
    $c = is_array($c) ? $c : [];
    $str = function ($k, $max) use ($c) {
        return pb_clean($c[$k] ?? '', $max);
    };
    $num = function ($k) use ($c) {
        return isset($c[$k]) && is_numeric($c[$k]) ? (int)$c[$k] : null;
    };
    $ip = (string)($_SERVER['REMOTE_ADDR'] ?? '');
    return [
        'ip' => filter_var($ip, FILTER_VALIDATE_IP) ? $ip : pb_clean($ip, 60),
        'fwd' => (object)$fwd,
        'ua' => $hdr('HTTP_USER_AGENT', 400),
        'lang' => $hdr('HTTP_ACCEPT_LANGUAGE', 120),
        'ref' => $hdr('HTTP_REFERER', 200),
        'device' => pb_owner_tag(),
        'asAdmin' => pb_is_admin(),
        'client' => [
            'tz' => $str('tz', 60),
            'tzOff' => $num('tzOff'),
            'lang' => $str('lang', 80),
            'platform' => $str('platform', 60),
            'mobile' => isset($c['mobile']) && is_bool($c['mobile']) ? $c['mobile'] : null,
            'screen' => $str('screen', 40),
            'touch' => $num('touch'),
        ],
    ];
}

/** Notes on every request for a photo that an admin put it back, stored it or deleted it. Call inside pb_tx. */
function pb_requests_mark($photoId, $field)
{
    $log = pb_load('requests', []);
    $changed = false;
    foreach ($log as $k => $e) {
        if (($e['photo'] ?? '') === $photoId && empty($e[$field])) {
            $log[$k][$field] = pb_now_ms();
            $changed = true;
        }
    }
    if ($changed) {
        pb_save('requests', $log);
    }
}

function pb_request_row($e, $byId)
{
    $pid = (string)($e['photo'] ?? '');
    $p = $byId[$pid] ?? null;
    return [
        'id' => (string)($e['id'] ?? ''),
        'photo' => $pid,
        'at' => (int)($e['at'] ?? 0),
        'name' => (string)($e['name'] ?? ''),
        'reason' => (string)($e['reason'] ?? ''),
        'hid' => !empty($e['hid']),
        'ip' => (string)($e['ip'] ?? ''),
        'device' => (string)($e['device'] ?? ''),
        'ua' => (string)($e['ua'] ?? ''),
        'asAdmin' => !empty($e['asAdmin']),
        'photoStatus' => $p ? (string)($p['status'] ?? 'live') : 'deleted',
        'small' => $p && !empty($p['small']),
        'ver' => $p ? (int)($p['ver'] ?? 0) : 0,
        'photoName' => (string)($e['photoName'] ?? ''),
        'photoCaption' => (string)($e['photoCaption'] ?? ''),
        'restoredAt' => (int)($e['restoredAt'] ?? 0),
        'storedAt' => (int)($e['storedAt'] ?? 0),
        'deletedAt' => (int)($e['deletedAt'] ?? 0),
    ];
}

/**
 * Admins only. The take-down request log, newest first: ?q= searches it, ?photo= lists one photo's
 * requests, ?rid= returns one request with everything saved about it, its blocks, and other requests
 * and uploads from the same device or IP address.
 */
function pb_requests()
{
    pb_need_admin();
    $log = pb_load('requests', []);
    usort($log, function ($a, $b) {
        return (int)($b['at'] ?? 0) <=> (int)($a['at'] ?? 0);
    });
    $book = pb_load_book();
    $byId = [];
    foreach ($book['photos'] as $p) {
        $byId[$p['id']] = $p;
    }
    $rid = pb_rid($_GET['rid'] ?? '');
    if ($rid !== '') {
        $entry = null;
        foreach ($log as $e) {
            if (($e['id'] ?? '') === $rid) {
                $entry = $e;
                break;
            }
        }
        if (!$entry) {
            throw new PBError('gone', 'There’s no record of that request.', 404);
        }
        $match = function ($e) use ($entry) {
            $d = !empty($entry['device']) && ($e['device'] ?? '') === $entry['device'];
            $i = !empty($entry['ip']) && ($e['ip'] ?? '') === $entry['ip'];
            return $d && $i ? 'both' : ($d ? 'device' : ($i ? 'ip' : ''));
        };
        $related = [];
        foreach ($log as $e) {
            if (($e['id'] ?? '') === $rid) {
                continue;
            }
            $m = $match($e);
            if ($m !== '' && count($related) < 100) {
                $row = pb_request_row($e, $byId);
                $row['match'] = $m;
                $related[] = $row;
            }
        }
        $uploads = [];
        $ulog = pb_load('uploads', []);
        usort($ulog, function ($a, $b) {
            return (int)($b['at'] ?? 0) <=> (int)($a['at'] ?? 0);
        });
        foreach ($ulog as $e) {
            $m = $match($e);
            if ($m !== '' && count($uploads) < 100) {
                $row = pb_trace_row($e, $byId);
                $row['match'] = $m;
                $uploads[] = $row;
            }
        }
        $out = pb_request_row($entry, $byId);
        $out['fwd'] = (object)($entry['fwd'] ?? []);
        $out['lang'] = (string)($entry['lang'] ?? '');
        $out['ref'] = (string)($entry['ref'] ?? '');
        $out['client'] = (object)($entry['client'] ?? []);
        $out['blocks'] = pb_blocks_for((string)($entry['ip'] ?? ''), (string)($entry['device'] ?? ''));
        pb_out(['ok' => true, 'entry' => $out, 'related' => $related, 'uploads' => $uploads]);
    }
    $photo = pb_id($_GET['photo'] ?? '');
    $q = pb_clean($_GET['q'] ?? '', 80);
    $q = function_exists('mb_strtolower') ? mb_strtolower($q, 'UTF-8') : strtolower($q);
    $rows = [];
    $total = 0;
    foreach ($log as $e) {
        if ($photo !== '' && ($e['photo'] ?? '') !== $photo) {
            continue;
        }
        if ($q !== '') {
            $hay = implode(' ', [$e['name'] ?? '', $e['reason'] ?? '', $e['ip'] ?? '', $e['device'] ?? '', $e['ua'] ?? '',
                implode(' ', (array)($e['fwd'] ?? [])), $e['photo'] ?? '', $e['photoName'] ?? '', $e['photoCaption'] ?? '']);
            $hay = function_exists('mb_strtolower') ? mb_strtolower($hay, 'UTF-8') : strtolower($hay);
            if (strpos($hay, $q) === false) {
                continue;
            }
        }
        $total++;
        if (count($rows) < 300) {
            $rows[] = pb_request_row($e, $byId);
        }
    }
    pb_out(['ok' => true, 'requests' => $rows, 'total' => $total]);
}

/** An IP address written one standard way (so ::1 and 0:0::1 match), or '' if it isn't one. */
function pb_ip_norm($ip)
{
    $ip = trim((string)$ip);
    if ($ip === '' || !filter_var($ip, FILTER_VALIDATE_IP)) {
        return '';
    }
    $bin = @inet_pton($ip);
    $out = $bin === false ? false : @inet_ntop($bin);
    return $out === false ? strtolower($ip) : strtolower($out);
}

/** The block that covers whoever is asking (by IP address or device), or null. */
function pb_blocked_entry()
{
    $list = pb_load('blocks', []);
    if (!$list) {
        return null;
    }
    $ip = pb_ip_norm((string)($_SERVER['REMOTE_ADDR'] ?? ''));
    $device = isset($_COOKIE['pb_vid']) && preg_match('/^[a-f0-9]{32}$/', (string)$_COOKIE['pb_vid']) ? pb_owner_tag() : '';
    foreach ($list as $b) {
        if (($ip !== '' && ($b['ip'] ?? '') === $ip) || ($device !== '' && ($b['device'] ?? '') === $device)) {
            return $b;
        }
    }
    return null;
}

/** Stops blocked people from posting. Admins are never blocked. */
function pb_need_not_blocked()
{
    if (!pb_is_admin() && pb_blocked_entry() !== null) {
        throw new PBError('blocked', PB_BLOCKED_MSG, 403);
    }
}

/** Blocks that cover this IP address or device, for the trace screen. */
function pb_blocks_for($ip, $device)
{
    $ip = pb_ip_norm($ip);
    $out = [];
    foreach (pb_load('blocks', []) as $b) {
        $m = [];
        if ($ip !== '' && ($b['ip'] ?? '') === $ip) {
            $m[] = 'ip';
        }
        if ($device !== '' && ($b['device'] ?? '') === $device) {
            $m[] = 'device';
        }
        if ($m) {
            $row = pb_blocks_out([$b])[0];
            $row['match'] = $m;
            $out[] = $row;
        }
    }
    return $out;
}

function pb_blocks_out($list)
{
    $out = [];
    foreach ((array)$list as $b) {
        $out[] = [
            'id' => (string)($b['id'] ?? ''),
            'ip' => (string)($b['ip'] ?? ''),
            'device' => (string)($b['device'] ?? ''),
            'photo' => (string)($b['photo'] ?? ''),
            'name' => (string)($b['name'] ?? ''),
            'note' => (string)($b['note'] ?? ''),
            'at' => (int)($b['at'] ?? 0),
        ];
    }
    usort($out, function ($a, $b) {
        return $b['at'] <=> $a['at'];
    });
    return $out;
}

/* ------------------------------------------------------------------ */
/* Who is asking                                                       */
/* ------------------------------------------------------------------ */

function pb_config($fresh = false)
{
    static $config = false;
    if ($fresh || $config === false) {
        $config = pb_load('config', null);
    }
    return $config;
}

function pb_need_config()
{
    $c = pb_config();
    if (!$c) {
        throw new PBError('needs_setup', 'This photo book isn’t set up yet.', 409);
    }
    return $c;
}

function pb_need_member()
{
    pb_need_config();
    if (!pb_is_member()) {
        throw new PBError('not_member', 'Enter the class code to see the photo book.', 401);
    }
}

function pb_need_admin()
{
    pb_need_config();
    if (!pb_is_admin()) {
        throw new PBError('not_admin', 'Admin mode is off. Enter the admin code again.', 403);
    }
}

function pb_is_member()
{
    $c = pb_config();
    if (!$c) {
        return false;
    }
    $t = pb_token('pb_class');
    if ($t && ($t['k'] ?? '') === 'c' && (int)($t['v'] ?? -1) === (int)$c['classVer']) {
        return true;
    }
    return pb_is_admin();
}

function pb_is_admin()
{
    $c = pb_config();
    $t = pb_token('pb_admin');
    return $c && $t && ($t['k'] ?? '') === 'a' && (int)($t['v'] ?? -1) === (int)$c['adminVer'];
}

function pb_grant_class()
{
    $c = pb_need_config();
    pb_cookie('pb_class', pb_sign(['k' => 'c', 'v' => (int)$c['classVer'], 'exp' => time() + PB_CLASS_DAYS * 86400]), PB_CLASS_DAYS);
}

function pb_grant_admin()
{
    $c = pb_need_config();
    pb_cookie('pb_admin', pb_sign(['k' => 'a', 'v' => (int)$c['adminVer'], 'exp' => time() + PB_ADMIN_DAYS * 86400]), PB_ADMIN_DAYS);
}

/** A random id for this browser, so reactions count once per person and people can delete what they added. */
function pb_vid()
{
    static $vid = null;
    if ($vid !== null) {
        return $vid;
    }
    $v = isset($_COOKIE['pb_vid']) ? (string)$_COOKIE['pb_vid'] : '';
    if (!preg_match('/^[a-f0-9]{32}$/', $v)) {
        $v = bin2hex(random_bytes(16));
        pb_cookie('pb_vid', $v, 400);
    }
    $vid = $v;
    return $vid;
}

function pb_owner_tag()
{
    return substr(hash('sha256', 'owner|' . pb_vid()), 0, 20);
}

function pb_sign($payload)
{
    $c = pb_need_config();
    $body = pb_b64(json_encode($payload));
    return $body . '.' . pb_b64(hash_hmac('sha256', $body, $c['secret'], true));
}

function pb_token($name)
{
    $c = pb_config();
    $token = isset($_COOKIE[$name]) ? (string)$_COOKIE[$name] : '';
    if (!$c || strpos($token, '.') === false) {
        return null;
    }
    list($body, $sig) = explode('.', $token, 2);
    if (!hash_equals(pb_b64(hash_hmac('sha256', $body, $c['secret'], true)), $sig)) {
        return null;
    }
    $payload = json_decode((string)base64_decode(strtr($body, '-_', '+/')), true);
    if (!is_array($payload) || (int)($payload['exp'] ?? 0) < time()) {
        return null;
    }
    return $payload;
}

function pb_b64($s)
{
    return rtrim(strtr(base64_encode($s), '+/', '-_'), '=');
}

/**
 * Sets (or, with $days = 0, clears) a cookie. The header is written by hand instead of with
 * setcookie()'s options array, because that array needs PHP 7.3 and some hosts still run 7.2.
 */
function pb_cookie($name, $value, $days)
{
    $path = str_replace('\\', '/', dirname((string)($_SERVER['SCRIPT_NAME'] ?? '/api.php')));
    $path = rtrim($path, '/') . '/';
    $https = (!empty($_SERVER['HTTPS']) && strtolower((string)$_SERVER['HTTPS']) !== 'off')
        || strtolower((string)($_SERVER['HTTP_X_FORWARDED_PROTO'] ?? '')) === 'https';
    $life = $days > 0 ? (int)round($days * 86400) : 0;
    $parts = [
        rawurlencode($name) . '=' . ($days > 0 ? rawurlencode((string)$value) : ''),
        'Expires=' . gmdate('D, d M Y H:i:s', $days > 0 ? time() + $life : 0) . ' GMT',
        'Max-Age=' . $life,
        'Path=' . $path,
    ];
    if ($https) {
        $parts[] = 'Secure';
    }
    $parts[] = 'HttpOnly';
    $parts[] = 'SameSite=Lax';
    if (!headers_sent()) {
        header('Set-Cookie: ' . implode('; ', $parts), false);
    }
    if ($days > 0) {
        $_COOKIE[$name] = $value;
    } else {
        unset($_COOKIE[$name]);
    }
}

function pb_client_key()
{
    $ip = (string)($_SERVER['REMOTE_ADDR'] ?? '');
    $fwd = (string)($_SERVER['HTTP_X_FORWARDED_FOR'] ?? '');
    if ($fwd !== '') {
        $first = trim(explode(',', $fwd)[0]);
        if (filter_var($first, FILTER_VALIDATE_IP)) {
            $ip = $first;
        }
    }
    return substr(hash('sha256', 'ip|' . $ip), 0, 16);
}

/* ------------------------------------------------------------------ */
/* Saved data: small JSON files that can't be opened from the web      */
/* ------------------------------------------------------------------ */

function pb_dirs()
{
    static $done = false;
    if ($done) {
        return;
    }
    foreach ([PB_DIR, PB_DIR . '/photos', PB_DIR . '/small'] as $d) {
        if (!is_dir($d) && !@mkdir($d, 0755, true) && !is_dir($d)) {
            throw new PBError('storage', 'The site couldn’t make its photobook-data folder. In your host’s file manager, check that the folder holding api.php has permissions 755.', 500);
        }
        if (!is_file($d . '/index.html')) {
            @file_put_contents($d . '/index.html', '');
        }
    }
    $rules = PB_DIR . '/.htaccess';
    if (!is_file($rules)) {
        @file_put_contents($rules, "# Private. The photo book reads these files through api.php.\n"
            . "<IfModule mod_authz_core.c>\n  Require all denied\n</IfModule>\n"
            . "<IfModule !mod_authz_core.c>\n  Order allow,deny\n  Deny from all\n</IfModule>\n");
    }
    if (!is_writable(PB_DIR)) {
        throw new PBError('storage', 'The photobook-data folder isn’t writable. Set its permissions to 755 in your host’s file manager.', 500);
    }
    $done = true;
}

function pb_file($name)
{
    return PB_DIR . '/' . $name . '.php';
}

function pb_load_book()
{
    $book = pb_load('book', ['photos' => []]);
    $book['photos'] = array_values((array)($book['photos'] ?? []));
    return $book;
}

/** Everyone sees live photos. Admins also see the ones waiting for a look. (Photos in hidden
 *  storage stay out of the book; admins open them from admin → hidden storage.) */
function pb_can_see($p, $admin)
{
    $status = $p['status'] ?? 'live';
    return $status === 'live' || ($admin && $status === 'flagged');
}

function pb_load($name, $default)
{
    $f = pb_file($name);
    if (!is_file($f)) {
        return $default;
    }
    $raw = @file_get_contents($f);
    if ($raw === false) {
        throw new PBError('storage', 'The site couldn’t read its saved data.', 500);
    }
    $nl = strpos($raw, "\n");
    $data = json_decode($nl === false ? '' : substr($raw, $nl + 1), true);
    return is_array($data) ? $data : $default;
}

function pb_save($name, $data)
{
    // JSON_HEX_TAG keeps "<" out of the file, so nothing in it can ever run as PHP.
    $json = json_encode($data, JSON_UNESCAPED_UNICODE | JSON_UNESCAPED_SLASHES | JSON_HEX_TAG);
    if ($json === false) {
        throw new PBError('server', 'The site couldn’t save that.', 500);
    }
    $tmp = PB_DIR . '/tmp-' . bin2hex(random_bytes(6)) . '.php';
    if (@file_put_contents($tmp, "<?php http_response_code(404); exit; ?>\n" . $json) === false) {
        throw new PBError('storage', 'The site couldn’t save. The hosting space may be full.', 500);
    }
    if (!@rename($tmp, pb_file($name))) {
        @unlink($tmp);
        throw new PBError('storage', 'The site couldn’t save that.', 500);
    }
}

/** Runs $fn while holding a lock, so two people saving at once can't overwrite each other. */
function pb_tx($fn)
{
    $h = @fopen(PB_DIR . '/lock.php', 'c');
    if (!$h) {
        throw new PBError('storage', 'The site couldn’t open its data.', 500);
    }
    if (!flock($h, LOCK_EX)) {
        fclose($h);
        throw new PBError('storage', 'The site is busy. Try again.', 503);
    }
    try {
        return $fn();
    } finally {
        flock($h, LOCK_UN);
        fclose($h);
    }
}

function pb_hits($bucket, $window)
{
    $all = pb_load('limits', []);
    $now = time();
    $n = 0;
    foreach ((array)($all[$bucket] ?? []) as $t) {
        if ((int)$t > $now - $window) {
            $n++;
        }
    }
    return $n;
}

function pb_hit($bucket)
{
    pb_tx(function () use ($bucket) {
        $all = pb_load('limits', []);
        $now = time();
        foreach ($all as $k => $hits) {
            $keep = [];
            foreach ((array)$hits as $t) {
                if ((int)$t > $now - 86400) {
                    $keep[] = (int)$t;
                }
            }
            if ($keep) {
                $all[$k] = $keep;
            } else {
                unset($all[$k]);
            }
        }
        $all[$bucket][] = $now;
        pb_save('limits', $all);
    });
}

/* ------------------------------------------------------------------ */
/* Input checks and output                                             */
/* ------------------------------------------------------------------ */

function pb_in()
{
    static $in = null;
    if ($in !== null) {
        return $in;
    }
    $raw = (string)file_get_contents('php://input');
    if (strlen($raw) > 65536) {
        throw new PBError('too_large', 'That’s too much text.', 413);
    }
    $data = $raw === '' ? [] : json_decode($raw, true);
    $in = is_array($data) ? $data : [];
    return $in;
}

function pb_code($v)
{
    $s = is_scalar($v) ? (string)$v : '';
    $s = preg_replace('/\s+/u', '', $s);
    if (!is_string($s)) {
        return '';
    }
    $s = function_exists('mb_strtolower') ? mb_strtolower($s, 'UTF-8') : strtolower($s);
    return substr($s, 0, 100);
}

function pb_check_codes($class, $admin)
{
    if ($class !== null && strlen($class) < 4) {
        throw new PBError('bad_request', 'The class code needs at least 4 characters.');
    }
    if ($admin !== null && strlen($admin) < 6) {
        throw new PBError('bad_request', 'The admin code needs at least 6 characters.');
    }
    if ($class !== null && $admin !== null && $class === $admin) {
        throw new PBError('bad_request', 'Use a different admin code than the class code.');
    }
}

/** Trims, collapses spaces, drops control characters and caps the length. */
function pb_clean($v, $max, $multiline = false)
{
    $s = is_scalar($v) ? (string)$v : '';
    if ($s === '' || !preg_match('//u', $s)) {
        return '';
    }
    if ($multiline) {
        $s = str_replace(["\r\n", "\r"], "\n", $s);
        $s = preg_replace('/[^\S\n]+/u', ' ', $s);
        $s = preg_replace('/ *\n */u', "\n", $s);
        $s = preg_replace('/\n{3,}/u', "\n\n", $s);
    } else {
        $s = preg_replace('/\s+/u', ' ', $s);
    }
    $s = preg_replace('/[\x{0000}-\x{0009}\x{000B}-\x{001F}\x{007F}\x{200B}-\x{200F}\x{202A}-\x{202E}\x{2066}-\x{2069}]/u', '', (string)$s);
    $s = trim((string)$s);
    if (function_exists('mb_substr')) {
        $s = mb_substr($s, 0, $max, 'UTF-8');
    } else {
        preg_match_all('/./us', $s, $m);
        $s = implode('', array_slice($m[0], 0, $max));
    }
    return trim($s);
}

/** A photo id (p_…) or, with $kind 'c', a comment id (c_…). Anything else comes back as ''. */
function pb_id($v, $kind = 'p')
{
    return (is_string($v) && preg_match('/^' . ($kind === 'c' ? 'c' : 'p') . '_[a-f0-9]{16}$/', $v)) ? $v : '';
}

/** Where an album sits in the list, ignoring upper/lower case. -1 if it isn't there. */
function pb_album_index($albums, $name)
{
    $key = pb_lower($name);
    foreach ((array)$albums as $i => $a) {
        if (pb_lower($a) === $key) {
            return (int)$i;
        }
    }
    return -1;
}

function pb_lower($s)
{
    return function_exists('mb_strtolower') ? mb_strtolower((string)$s, 'UTF-8') : strtolower((string)$s);
}

/** Moves every photo in album $from into album $to. Call it inside pb_tx. Returns how many moved. */
function pb_move_album_photos($from, $to)
{
    $book = pb_load_book();
    $n = 0;
    foreach ($book['photos'] as $i => $p) {
        if ((string)($p['album'] ?? '') === $from) {
            $book['photos'][$i]['album'] = $to;
            $n++;
        }
    }
    if ($n) {
        pb_save('book', $book);
    }
    return $n;
}

function pb_album($v, $albums)
{
    $a = pb_clean($v, 40);
    if (in_array($a, $albums, true)) {
        return $a;
    }
    return in_array('Random', $albums, true) ? 'Random' : (string)end($albums);
}

function pb_image_in($f, $max)
{
    $err = (int)($f['error'] ?? UPLOAD_ERR_NO_FILE);
    if ($err === UPLOAD_ERR_INI_SIZE || $err === UPLOAD_ERR_FORM_SIZE) {
        throw new PBError('too_large', 'That photo is too big for the server.', 413);
    }
    $tmp = (string)($f['tmp_name'] ?? '');
    if ($err !== UPLOAD_ERR_OK || $tmp === '' || !is_uploaded_file($tmp)) {
        throw new PBError('bad_request', 'The photo didn’t arrive. Try again.');
    }
    $size = (int)@filesize($tmp);
    if ($size <= 0) {
        throw new PBError('bad_request', 'The photo didn’t arrive. Try again.');
    }
    if ($size > $max) {
        throw new PBError('too_large', 'That photo is too big.', 413);
    }
    $type = pb_sniff((string)@file_get_contents($tmp, false, null, 0, 16));
    $info = $type ? @getimagesize($tmp) : false;
    if (!$type || !$info || (int)$info[0] < 1 || (int)$info[1] < 1) {
        throw new PBError('not_image', 'That file isn’t a photo the book can use. Try a JPG or PNG.', 415);
    }
    return [
        'tmp' => $tmp,
        'type' => $type,
        'ext' => $type === 'image/png' ? 'png' : ($type === 'image/webp' ? 'webp' : 'jpg'),
        'w' => (int)$info[0],
        'h' => (int)$info[1],
    ];
}

function pb_sniff($head)
{
    if (strncmp($head, "\xFF\xD8\xFF", 3) === 0) {
        return 'image/jpeg';
    }
    if (strncmp($head, "\x89PNG\r\n\x1A\n", 8) === 0) {
        return 'image/png';
    }
    if (strlen($head) >= 12 && substr($head, 0, 4) === 'RIFF' && substr($head, 8, 4) === 'WEBP') {
        return 'image/webp';
    }
    return null;
}

function pb_ini_bytes($v)
{
    $v = trim($v);
    if ($v === '') {
        return 0;
    }
    $n = (float)$v;
    $unit = strtolower(substr($v, -1));
    if ($unit === 'g') {
        $n *= 1073741824;
    } elseif ($unit === 'm') {
        $n *= 1048576;
    } elseif ($unit === 'k') {
        $n *= 1024;
    }
    return (int)$n;
}

function pb_find($list, $id)
{
    foreach ((array)$list as $i => $item) {
        if (($item['id'] ?? '') === $id) {
            return (int)$i;
        }
    }
    return -1;
}

function pb_counts($p)
{
    $r = (array)($p['r'] ?? []);
    $out = [];
    foreach (PB_REACTIONS as $k) {
        $out[$k] = max(0, (int)($r[$k] ?? 0));
    }
    return $out;
}

function pb_mask_names($mask)
{
    $out = [];
    foreach (PB_REACTIONS as $i => $k) {
        if ($mask & (1 << $i)) {
            $out[] = $k;
        }
    }
    return $out;
}

/** $stat is [live comments, reported comments]. Only admins hear about reported ones. */
function pb_photo_out($p, $stat = [0, 0], $admin = false)
{
    $out = [
        'id' => (string)$p['id'],
        'name' => (string)($p['name'] ?? ''),
        'caption' => (string)($p['caption'] ?? ''),
        'album' => (string)($p['album'] ?? 'Random'),
        'createdAt' => (int)($p['createdAt'] ?? 0),
        'width' => max(1, (int)($p['w'] ?? 4)),
        'height' => max(1, (int)($p['h'] ?? 3)),
        'reactions' => pb_counts($p),
        'comments' => (int)$stat[0],
        'status' => (string)($p['status'] ?? 'live'),
        'featured' => (int)($p['featured'] ?? 0),
        'nofeat' => !empty($p['nofeat']),
        'small' => !empty($p['small']),
        'own' => pb_is_own($p),
        'ver' => (int)($p['ver'] ?? 0),
    ];
    if ($admin) {
        $out['reported'] = (int)$stat[1];
        $out['anon'] = !empty($p['anon']);
        if (!empty($p['flagReq'])) {
            $out['flagReq'] = (string)$p['flagReq'];
            $out['flagName'] = (string)($p['flagName'] ?? '');
            $out['flagReason'] = (string)($p['flagReason'] ?? '');
            $out['flaggedAt'] = (int)($p['flaggedAt'] ?? 0);
        }
    }
    return $out;
}

function pb_comment_out($c)
{
    return [
        'id' => (string)$c['id'],
        'name' => (string)($c['name'] ?? ''),
        'text' => (string)($c['text'] ?? ''),
        'createdAt' => (int)($c['createdAt'] ?? 0),
        'status' => (string)($c['status'] ?? 'live'),
        'own' => pb_is_own($c),
    ];
}

/** True when this browser added the photo or comment. */
function pb_is_own($item)
{
    static $tag = null;
    if ($tag === null) {
        $tag = pb_owner_tag();
    }
    return !empty($item['by']) && hash_equals((string)$item['by'], $tag);
}

/** Counts a photo's comments: [live, reported]. */
function pb_comment_stat($list)
{
    $live = 0;
    $reported = 0;
    foreach ((array)$list as $c) {
        $status = $c['status'] ?? 'live';
        if ($status === 'live') {
            $live++;
        } elseif ($status === 'flagged') {
            $reported++;
        }
    }
    return [$live, $reported];
}

function pb_stat_out($stat, $admin)
{
    return $admin ? ['count' => $stat[0], 'reported' => $stat[1]] : ['count' => $stat[0]];
}

/** The book's name, school and color. These show on the sign-in screen, so they aren't secret. */
function pb_brand($c)
{
    $color = (string)($c['color'] ?? 'sky');
    return [
        'title' => (string)($c['title'] ?? ''),
        'school' => (string)($c['school'] ?? ''),
        'color' => in_array($color, PB_COLORS, true) ? $color : 'sky',
    ];
}

function pb_public_settings($c)
{
    return pb_brand($c) + [
        'albums' => array_values((array)($c['albums'] ?? PB_ALBUMS)),
        'graduationDate' => (string)($c['graduationDate'] ?? ''),
    ];
}

function pb_now_ms()
{
    return (int)round(microtime(true) * 1000);
}

function pb_out($data, $status = 200)
{
    if (!headers_sent()) {
        http_response_code($status);
        header('Content-Type: application/json; charset=utf-8');
        header('Cache-Control: no-store');
        header('X-Content-Type-Options: nosniff');
        header('Referrer-Policy: same-origin');
    }
    echo json_encode($data, JSON_UNESCAPED_UNICODE | JSON_UNESCAPED_SLASHES | JSON_PARTIAL_OUTPUT_ON_ERROR);
    exit;
}

function pb_plain($status, $text)
{
    http_response_code($status);
    header('Content-Type: text/plain; charset=utf-8');
    header('Cache-Control: no-store');
    echo $text;
    exit;
}

/* ------------------------------------------------------------------ */
/* Admin: edit or replace a photo's picture                            */
/* ------------------------------------------------------------------ */

/**
 * Swaps a photo's picture for a new one (an edited version, or a different photo). The photo keeps its
 * id, caption, album, hearts, comments and featured spot. The old files aren't deleted: they move to
 * photobook-data/replaced/, which can't be opened from the web, so an edit can always be undone by hand.
 */
function pb_replace()
{
    pb_need_admin();
    $id = pb_id($_POST['id'] ?? '');
    if ($id === '') {
        throw new PBError('bad_request', 'Unknown photo.');
    }
    $limit = pb_ini_bytes((string)ini_get('post_max_size'));
    if ($limit > 0 && (int)($_SERVER['CONTENT_LENGTH'] ?? 0) > $limit) {
        throw new PBError('too_large', 'That photo is too big for the server.', 413);
    }
    if (empty($_FILES['full'])) {
        throw new PBError('bad_request', 'No photo arrived. Try again.');
    }
    $full = pb_image_in($_FILES['full'], PB_MAX_PHOTO);
    $small = null;
    if (!empty($_FILES['small']) && (int)($_FILES['small']['error'] ?? UPLOAD_ERR_NO_FILE) !== UPLOAD_ERR_NO_FILE) {
        $small = pb_image_in($_FILES['small'], PB_MAX_SMALL);
    }
    $base = bin2hex(random_bytes(12));
    $fullName = $base . '.' . $full['ext'];
    if (!@move_uploaded_file($full['tmp'], PB_DIR . '/photos/' . $fullName)) {
        throw new PBError('storage', 'The server couldn’t save that photo. The hosting space may be full.', 500);
    }
    @chmod(PB_DIR . '/photos/' . $fullName, 0644);
    $smallName = '';
    if ($small) {
        $smallName = $base . '.' . $small['ext'];
        if (@move_uploaded_file($small['tmp'], PB_DIR . '/small/' . $smallName)) {
            @chmod(PB_DIR . '/small/' . $smallName, 0644);
        } else {
            $smallName = '';
        }
    }
    try {
        $r = pb_tx(function () use ($id, $fullName, $smallName, $full) {
            $book = pb_load_book();
            $i = pb_find($book['photos'], $id);
            if ($i < 0) {
                throw new PBError('gone', 'That photo is gone.', 404);
            }
            $old = ['file' => (string)($book['photos'][$i]['file'] ?? ''), 'small' => (string)($book['photos'][$i]['small'] ?? '')];
            $book['photos'][$i]['file'] = $fullName;
            $book['photos'][$i]['small'] = $smallName;
            $book['photos'][$i]['w'] = $full['w'];
            $book['photos'][$i]['h'] = $full['h'];
            $book['photos'][$i]['ver'] = (int)($book['photos'][$i]['ver'] ?? 0) + 1;
            $book['photos'][$i]['editedAt'] = pb_now_ms();
            pb_save('book', $book);
            return [$old, $book['photos'][$i]];
        });
    } catch (Throwable $e) {
        @unlink(PB_DIR . '/photos/' . $fullName);
        if ($smallName !== '') {
            @unlink(PB_DIR . '/small/' . $smallName);
        }
        throw $e;
    }
    list($old, $photo) = $r;
    $keep = PB_DIR . '/replaced';
    if (!is_dir($keep)) {
        @mkdir($keep, 0755, true);
        @file_put_contents($keep . '/index.html', '');
    }
    $stamp = date('Ymd-His');
    foreach ([['photos', $old['file']], ['small', $old['small']]] as $f) {
        $name = basename($f[1]);
        $from = PB_DIR . '/' . $f[0] . '/' . $name;
        if ($name !== '' && is_file($from)) {
            if (!@rename($from, $keep . '/' . $id . '-' . $stamp . '-' . $f[0] . '-' . $name)) {
                @unlink($from);
            }
        }
    }
    $stat = pb_comment_stat(pb_load('comments', [])[$id] ?? []);
    pb_out(['ok' => true, 'photo' => pb_photo_out($photo, $stat, true)]);
}
