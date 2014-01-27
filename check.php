<?php
/**
 * WordLink · WordPress Link Checker - Version 1.0
 *
 * This script will check accessibility of URLs linked in your posts.
 *
 * Usage: Put this file in your WordPress installation directory. Enter the address:
 * http://siteaddress.com/check.php. Wait until the process is finished.
 *
 * If you have found a bug or you have an idea for an enhancement, feel free to report an issue on GitHub:
 * https://github.com/winek/wordpress-link-checker/issues/
 *
 * @package  WordLink
 * @author   Olgierd Grzyb
 * @license  http://wtfpl.net/about WTFPL
 * @link     http://winek.me/
 * @link     http://github.com/winek/wordlink
 * @version  1.0
 */

namespace Winek\WordLink;

define('REGEX_URL', '#\bhttps?://[-A-Z0-9+&@\#/%?=~_|!:,.;]*[-A-Z0-9+&@\#/%=~_|]#i');
define('REGEX_IS_URL', '#^https?://[-A-Z0-9+&@\#/%?=~_|!:,.;]*[-A-Z0-9+&@#/%=~_|]$#i');

/**
 * Finds URLs in a post content.
 *
 * This function finds URL address occurences in a post. A single URL
 * will not occur more than once.
 *
 * @param  string $text Post content
 * @return array  array of found URLs.
 */
function find_links($text)
{
    if (preg_match_all(REGEX_URL, $text, $m, PREG_SET_ORDER)) {
        return array_unique(array_map(create_function('$m', 'return $m[0];'), $m));
    }

    return array();
}

/**
 * Checks each link in the given URL array, and returns an array
 * of LinkStatus instances.
 *
 * @param  string       $text post body
 * @return Link[]
 */
function check_links($text)
{
    $results = array();
    foreach (find_links($text) as $link) {
        $results[] = check_status($link);
    }

    return $results;
}

/**
 * Executes the cURL request and follows open_basedir if needed 
 * 
 * @param  unknown $ch
 * @param  int     $max Maximum number of redirections
 * @return boolean|string
 */
function curl_exec_follow($ch, &$max = null)
{
    $mr = $max === null ? 5 : intval($max);

    if (ini_get('open_basedir') == '' && ini_get('safe_mode') == 'Off') {
        curl_setopt($ch, CURLOPT_FOLLOWLOCATION, $mr > 0);
        curl_setopt($ch, CURLOPT_MAXREDIRS, $mr);
        curl_setopt($ch, CURLOPT_RETURNTRANSFER, true);
        curl_setopt($ch, CURLOPT_SSL_VERIFYPEER, false);
    } else {
        curl_setopt($ch, CURLOPT_FOLLOWLOCATION, false);

        if ($mr > 0) {
            $original_url = curl_getinfo($ch, CURLINFO_EFFECTIVE_URL);
            $newurl = $original_url;

            $rch = curl_copy_handle($ch);

            curl_setopt($rch, CURLOPT_HEADER, true);
            curl_setopt($rch, CURLOPT_NOBODY, true);
            curl_setopt($rch, CURLOPT_FORBID_REUSE, false);
            do {
                curl_setopt($rch, CURLOPT_URL, $newurl);
                $header = curl_exec($rch);
                
                if (curl_errno($rch)) {
                    $code = 0;
                } else {
                    $code = curl_getinfo($rch, CURLINFO_HTTP_CODE);
                    if ($code == 301 || $code == 302) {
                        preg_match('/Location:(.*?)\n/', $header, $matches);
                        $newurl = trim(array_pop($matches));

                        // if no scheme is present then the new url is a
                        // relative path and thus needs some extra care
                        if (!preg_match("/^https?:/i", $newurl)) {
                            $newurl = $original_url . $newurl;
                        }
                    } else {
                        $code = 0;
                    }
                }
            } while ($code && --$mr);

            curl_close($rch);

            if (!$mr) {
                if ($max === null)
                trigger_error('Too many redirects.', E_USER_WARNING);
                else
                $max = 0;

                return false;
            }
            curl_setopt($ch, CURLOPT_URL, $newurl);
        }
    }

    return curl_exec($ch);
}

/**
 * Loads a post from an identifier.
 * @param  int  $id
 * @return Post
 */
function load_post($id)
{
    global $wpdb;

    $row = $wpdb->get_row($wpdb->prepare(
        'SELECT `ID`, `post_content`, `post_title` FROM `' . $wpdb->prefix . 'posts` WHERE `ID` = %d',
        $id
    ));

    $post = new Post;
    $post->title   = $row->post_title;
    $post->url     = get_permalink($row->ID);
    $post->id      = $row->ID;
    $post->content = $row->post_content;

    return $post;
}

/**
 * Loads all published posts' IDs from WordPress blog database
 * and returns them as an array. No additional information is returned.
 * If you need to load a single post, use [get_post()].
 * @return Post[]
 */
function load_post_ids()
{
    global $wpdb;

    // This is hacky: $wpdb->get_results(..., OBJECT_K) returns array of objects indexed by first column's value.
    // As our first column is the ID, we may perform an array_keys() to retrieve post identifiers.
    return array_keys($wpdb->get_results('SELECT `ID` FROM `' . $wpdb->prefix . 'posts` WHERE `post_type` = \'post\' AND `post_status` = \'publish\' ORDER BY `post_date` DESC', OBJECT_K));
}

/**
 * Outputs a string, HTML-escaped.
 * @param string $string
 */
function h($string)
{
    echo htmlspecialchars($string, ENT_QUOTES, 'UTF-8');
}

/**
 * Detects the client language.
 * Stolen from somewhere on the Internet.
 *
 * @param  string $default   Default language.
 * @param  array  $available Available languages.
 * @param  string $accept    Accept-Language header value.
 * @return string
 */
function get_language($default, array $available = array())
{
    preg_match_all(
            '!([a-zA-Z]+)(?:-[a-zA-Z]+)?(?: *; *q *= *([01](?:\.[0-9]+)?))?!',
            $_SERVER['HTTP_ACCEPT_LANGUAGE'],
            $found);

    foreach ($found[1] as $i => $lang) {
        $weight = (float) $found[2][$i];
        $accepted[$lang] = $weight > 0 ? $weight : 1;
    }

    $choiceWeight = 0;
    $choice = $default;
    $languages = array_flip($available);
    $i = 1;
    $baseWeight = 1;

    foreach ($languages as &$weight) {
        $weight = $baseWeight;
    }

    foreach ($languages as $lang => $weight) {
        if (isset($accepted[$lang])) {
            $tmpChoice = $weight * $accepted[$lang];

            if ($tmpChoice > $choiceWeight and $tmpChoice > 0) {
                $choiceWeight = $tmpChoice;
                $choice = $lang;
            }
        }
    }

    return $choice;
}

/**
 * Translates a phrase and sends to stdout/server response.
 * Accepts printf-like additional parameters.
 *
 *     t('New %i messages', $numberOfMessages)
 *
 * @param string $phrase
 */
function t($phrase)
{
    echo htmlspecialchars(call_user_func_array('Winek\WordLink\translate', func_get_args()));
}

/**
 * Translates a phrase.
 * May be called with more than one parameter - additional parameters
 * will be passed to sprintf call.
 * 
 *     echo translate('Fatal error: %s', $error);
 * 
 * @param string $phrase
 */
function translate($phrase)
{
    global $lang, $translations;
    $args = func_get_args(); // [0] is $phrase

    if (isset($translations[$lang][$args[0]])) {
        $args[0] = $translations[$lang][$args[0]];
    }

    return call_user_func_array('sprintf', $args);
}

/**
 * Checks the status for a given link.
 *
 * @param  string $url
 * @return Link
 */
function check_status($url)
{
    static $status_cache = array();

    if (isset($status_cache[$url])) {
        return $status_cache[$url];
    }

    $status = new Link;
    $status->url = $status->actual_url = $url;

    $curl = curl_init();
    curl_setopt_array($curl, array(
        CURLOPT_URL	           => $url,
        CURLOPT_RETURNTRANSFER => true,
        CURLOPT_SSL_VERIFYPEER => false,
        CURLOPT_USERAGENT      => 'Mozilla/5.0 (X11; Linux x86_64; rv:26.0) Gecko/20100101 Firefox/26.0',
    ));

    $ret = curl_exec_follow($curl);

    if (!$ret) {
        // Failed, returning status as is
        $status->error = curl_error($curl);
        $status->errno = curl_errno($curl);

        return $status_cache[$url] = $status;
    }

    preg_match('#<title>(.*?)</title>#is', $ret, $m);
    if (!empty($m[1])) {
        $status->title = html_entity_decode($m[1]);
    }

    $status->code = curl_getinfo($curl, CURLINFO_HTTP_CODE);
    $status->actual_url = curl_getinfo($curl, CURLINFO_EFFECTIVE_URL);

    curl_close($curl);

    return $status_cache[$url] = $status;
}

/**
 * Translation table for supported languages.
 * @var array
 */
$translations = array(
    'pl' => array(
        'No WordPress configuration (wp-config.php) found in current location. This script should be uploaded into root directory of your installation.' =>
        'Pliku wp-config.php nie ma w obecnym katalogu. Czy wrzuciłeś skrypt do głównego katalogu instalacji WordPressa?',

        'Error: %s' => 'Błąd: %s',
        ' (redirected to %s)' => ' (przekierowanie do %s)',
        'No page under this URL.' => 'Nie znaleziono strony',
        'Permission denied' => 'Brak uprawnień (403)',
        'Unknown status.' => 'Nieznany stan',
        'Error %i %s' => 'Błąd %i %s',
        'Error %i %s' => 'Błąd %i %s',
        'Server not found' => 'Nie ma takiego serwera',

        'Display:' => 'Wyświetlaj:',
        'All' => 'Wszystko',
        'Working' => 'Działające',
        'Broken' => 'Niedziałające',

        'Stats:' => 'Statystyki:',
        'all' => 'wszystkich',
        'broken' => 'niedziałających',
        'working' => 'działających',

        'Loading…' => 'Ładowanie…'
    ),
    'en' => array(),
);

/**
 * Describes a post. You may load additional data using the identifier
 * from the "id" property.
 * Posts are loaded within [load_posts()].
 */
class Post
{
    public $content;
    public $url;
    public $title;
    public $links;
    public $id;
}

/**
 * Describes status for a single link. Stores the title (if the page could be retrieved),
 * the status code and the URL itself.
 * If the page did redirect us somewhere else, the the actual_url property is also stored
 * for statistical purposes.
 */
class Link
{
    public $url;
    public $actual_url;
    public $title;
    public $error;
    public $errno;

    /**
     * HTTP status code. Value 0 means that from some reasons the request could not be achieved
     * (eg. the internet domain does not exist anymore).
     */
    public $code = 0;

    /**
     * Returns a nice description of the status.
     * @return string
     */
    public function describe()
    {
        switch ($this->code) {
            case 0:
                switch ($this->errno) {
                    case CURLE_COULDNT_RESOLVE_HOST:
                        return translate('Server not found');
                    default:
                        return translate('Error: %s', $this->error);
                }
            case 200:
                return translate('OK') . ($this->title ? ' (' . $this->title . ')' : '') . (($this->actual_url != $this->url) ? translate(' (redirected to %s)', '<a href="' . htmlspecialchars($this->actual_url, ENT_HTML5 | ENT_QUOTES) . '">' . htmlspecialchars($this->actual_url) . '</a>') : '');
            case 404:
                return translate('No page under this URL.');
            case 403:
                return translate('Premission denied.');
            default:
                return translate('Unknown status.');
        }

        // catches other cases than 403-4
        if ($this->code >= 400) {
            return translate('Error %i %s', $this->code, translate(self::$messages[$this->code]));
        }
    }

    /**
     * @return bool whether the link is loading properly
     */
    public function good()
    {
        return $this->code < 400 && $this->code > 0;
    }

    public static $messages = array(
        // Informational 1xx
        100 => 'Continue',
        101 => 'Switching Protocols',

        // Success 2xx
        200 => 'OK',
        201 => 'Created',
        202 => 'Accepted',
        203 => 'Non-Authoritative Information',
        204 => 'No Content',
        205 => 'Reset Content',
        206 => 'Partial Content',

        // Redirection 3xx
        300 => 'Multiple Choices',
        301 => 'Moved Permanently',
        302 => 'Found', // 1.1
        303 => 'See Other',
        304 => 'Not Modified',
        305 => 'Use Proxy',
        307 => 'Temporary Redirect',

        // Client Error 4xx
        400 => 'Bad Request',
        401 => 'Unauthorized',
        402 => 'Payment Required',
        403 => 'Forbidden',
        404 => 'Not Found',
        405 => 'Method Not Allowed',
        406 => 'Not Acceptable',
        407 => 'Proxy Authentication Required',
        408 => 'Request Timeout',
        409 => 'Conflict',
        410 => 'Gone',
        411 => 'Length Required',
        412 => 'Precondition Failed',
        413 => 'Request Entity Too Large',
        414 => 'Request-URI Too Long',
        415 => 'Unsupported Media Type',
        416 => 'Requested Range Not Satisfiable',
        417 => 'Expectation Failed',

        // Server Error 5xx
        500 => 'Internal Server Error',
        501 => 'Not Implemented',
        502 => 'Bad Gateway',
        503 => 'Service Unavailable',
        504 => 'Gateway Timeout',
        505 => 'HTTP Version Not Supported',
        509 => 'Bandwidth Limit Exceeded',
    );
}

$lang = get_language('en', array('en', 'pl'));
@set_time_limit(0);

if (file_exists('wp-config.php')) {
    require_once 'wp-config.php';
} else {
    die(translate('No WordPress configuration (wp-config.php) found in current location. This script should be uploaded into root directory of your installation.'));
}

if (!empty($_POST['postId']) && ctype_digit($_POST['postId'])) {
    /**
     * We want to be so DRY.
     * @param  string $value
     * @return string the value, HTML-escaped
     */
    function esc($value)
    {
        return htmlspecialchars($value, ENT_QUOTES, 'UTF-8');
    }

    try {
        $post = load_post($_POST['postId']);
    } catch (\InvalidArgumentException $e) {
        header($_SERVER['SERVER_PROTOCOL'] . ' 404 Not Found');
        exit;
    }

    $links = check_links($post->content);

    if (count($links) <= 0) {
        header($_SERVER['SERVER_PROTOCOL'] . ' 404 Not Found');
        echo '<?xml version="1.0"?><error code="404" />';
        exit;
    }

    header('Content-Type: text/xml');
    echo '<?xml version="1.0"?>
<post id="' . $post->id . '" url="' . esc($post->url) . '" title="' . esc($post->title) . '">
    <links>';

    foreach ($links as $link) {
        echo '<link url="' . esc($link->url) . '" status="' . ($link->good() ? 'working' : 'broken') . '" description="' . esc($link->describe()) . '" />';
    }

    echo '</links>
</post>';
    exit;
}

$post_ids = load_post_ids();

/*
 * The "internals" part finishes here. Now, we should display a page template.
 */

?>
<!DOCTYPE html>
<meta charset="UTF-8">
<title>WordPress Link Checker</title>
<style>
html {
        color: #555;
        background: #f7f7f7;
}

body {
    font-family: Arial, Helvetica, sans-serif;
    font-size: .9em;
    line-height: 1.5;
    margin: 1em 2em;
    background: #fff;
    padding: 15px 30px;
    border-radius: 3px;
    -moz-border-radius: 3px;
    -webkit-border-radius: 3px;
    -o-border-radius: 3px;
    border: 1px #e0e0e0 solid;
    box-shadow: 0 2px 1px #eee;
}

a {
    color: #28c;
    text-decoration: none;
}

a:hover {
    color: #048;
    text-decoration: underline;
}

h1 {
    border-bottom: 1px #eee solid;
    line-height: 1.7;
    font-size: 2em;
    color: #777;
}

h1, h2 {
    letter-spacing: -1px;
}

h2 {
    color: #ccc;
    margin-top: 1.2em;
    margin-bottom: .5em;
}

h2 a {
    color: #000;
}

h2 a:hover {
    color: #27c;
    text-decoration: none;
}

fieldset {
    border: 1px #eee solid;
    padding: 8px 14px;
}

legend {
    color: #444;
    font-size: .85em;
    font-weight: bold;
}

label {
    font-size: .9em;
}

dd {
    padding-left: 2px;
    margin: 2px 0 5px 0;
}

input[type=text], input[type=password] {
    padding: 6px;
    width: 175px;
    box-shadow: 1px 1px 1px #eee inset;
    font-size: .9em;
    border: 1px #d7d7d7 solid;
    border-radius: 2px;
    -moz-border-radius: 2px;
    -webkit-border-radius: 2px;
    -o-border-radius: 2px;
}

input[type=submit] {
    font-size: 1.3em;
    font-family: Arial, Helvetica, sans-serif;
    padding: 8px 12px;
    background: #27a;
    border: 0;
    color: #fff;
    border-radius: 3px;
    -moz-border-radius: 3px;
    -webkit-border-radius: 3px;
    -o-border-radius: 3px;
    text-shadow: 0 1px 1px #037;
}

input[type=submit]:hover {
    background: #176c9c;
    cursor: pointer;
}

input[type=submit]:focus {
    background: #058;
    cursor: pointer;
}

.button {
    cursor: pointer;
    background: #f6f6f6;
    border: 1px solid;
    border-color: #ddd #ccc #bbb #ccc;
    border-radius: 2px;
    -moz-border-radius: 2px;
    -webkit-border-radius: 2px;
    padding: 5px 8px;
    font-family: 'Liberation Sans', Arial, Helvetica, sans-serif;
    color: #3a3a3a;
    box-shadow: 0 1px 0 #eee, 0 1px 1px #f9f9f9 inset;
}

.button[disabled],.button[disabled]:hover,form:invalid .button,form:invalid .button:hover {
    cursor: default;
    border-color: #eee;
    background: #f6f6f6;
    box-shadow: none;
    opacity: 0.8;
    -moz-opacity: 0.8;
    filter: alpha(opacity = 80);
    color: #888;
}

.button.primary {
    font-weight: bold;
}

.button.pressed {
    background: #e6e6e6;
    box-shadow: 0 1px 2px #ddd inset, 0 1px 1px #ccc inset;
}

.button:hover {
    background: #f8f8f8;
    box-shadow: 0 1px 1px #dfdfdf;
}

.button.pressed:hover {
    background: #eee;
    box-shadow: 0 1px 2px #ddd inset, 0 1px 1px #ccc inset;
}

li.broken {
    background: #ffc;
    border-bottom: 1px solid #eda;
}

#loader-container {
    position: absolute;
    left: 50%;
    top: 0;
}

#loader {
    position: relative;
    left: -50%;
    font-style: italic;
    color: #888;
    text-align: center;
    text-align: center;
    background: #fff;
    padding: 8px 12px;
    border: 1px #ddd solid;
}

#loader img {
    vertical-align: middle;
    margin-right: 5px;
}
</style>
<h1>WordPress Link Checker</h1>
<p><strong><?php t('Stats:') ?></strong> <span id="stats-all">0</span> <?php t('all') ?>, <span id="stats-working">0</span> <?php t('working') ?>, <span id="stats-broken">0</span> <?php t('broken') ?></p>
<p><?php t('Display:') ?> <input type="button" id="see-all" class="button primary pressed" value="<?php t('All') ?>"> <input type="button" id="see-working" class="button" value="<?php t('Working') ?>"> <input type="button" class="button" id="see-broken" value="<?php t('Broken') ?>"></p>
<div id="loader-container"><div id="loader"><img src="data:image/gif;base64,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" alt=""> <?php t('Loading…') ?></div></div>
<main id="list"></main>
<script>
var posts = [<?php echo implode(',', $post_ids) ?>],
    buttons = {
        all: document.getElementById('see-all'),
        working: document.getElementById('see-working'),
        broken: document.getElementById('see-broken')
    },
    stats = {
        all: document.getElementById('stats-all'),
        working: document.getElementById('stats-working'),
        broken: document.getElementById('stats-broken')
    },
    container = document.getElementById('list'),
    filterState = 'all',
    interval = 500;

function showAll(className)
{
    for (var key in all = document.getElementsByClassName(className)) {
        if (!isNaN(key)) {
            all[key].style.display = 'list-item';
        }
    }
}

function hideAll(className)
{
    for (var key in all = document.getElementsByClassName(className)) {
        if (!isNaN(key)) {
            all[key].style.display = 'none';
        }
    }
}

function switchButtons(newState)
{
    document.getElementById('see-' + filterState).className = document.getElementById('see-' + filterState).className.replace(/\s*pressed/, '');
    document.getElementById('see-' + (filterState = newState)).className += ' pressed';
}

function xmlHttp()
{
    var xmlhttp;
    try {
        xmlhttp = new XMLHttpRequest();
    } catch (e) {
        try {
            xmlhttp = new ActiveXObject('Msxml2.XMLHTTP');
        } catch (e) {
            try {
                xmlhttp = new ActiveXObject('Microsoft.XMLHTTP');
            } catch (e) {
                alert('It seems you have a legacy browser which does not support AJAX. Thus, you are forbidden to use WordPress link checker!');

                return false;
            }
        }
    }

    return xmlhttp;
}

buttons.all.onclick = function () {
    showAll('link');
    switchButtons('all');
};
buttons.working.onclick = function () {
    showAll('working');
    hideAll('broken');
    switchButtons('working');
};
buttons.broken.onclick = function () {
    hideAll('working');
    showAll('broken');
    switchButtons('broken');
};

function incrementStats(statField)
{
    statField.innerHTML = parseInt(statField.innerHTML) + 1;
}

function doCheckPost(id, index)
{
    var xmlhttp = xmlHttp();
    xmlhttp.onreadystatechange = function () {
        // Don't touch. Appreciate it works.
        if (xmlhttp.readyState === 4) {
            posts.splice(index, 1);

            if (0 === posts.length) {
                document.getElementById('loader-container').style.display = 'none';
            };

            if (xmlhttp.status === 200 && xmlhttp.responseXML) {
                var entry = document.createElement('article'), links;
                var post = xmlhttp.responseXML.getElementsByTagName('post')[0];
                var header = document.createElement('h2');
                header.appendChild(document.createTextNode('» '));
                var link = document.createElement('a');
                link.href = post.getAttribute('url');
                link.appendChild(document.createTextNode(post.getAttribute('title')));
                header.appendChild(link);
                var list = document.createElement('ul');
                entry.appendChild(header);
                entry.appendChild(list);

                for (var i in links = post.getElementsByTagName('link')) {
                    if (isNaN(i)) {
                        continue;
                    }
                    if (links[i].getAttribute('status') == 'broken') {
                        incrementStats(stats.broken);
                    } else {
                        incrementStats(stats.working);
                    }
                    incrementStats(stats.all);
                    var item = document.createElement('li');
                    item.className = 'link ' + links[i].getAttribute('status');
                    var emphasis = document.createElement('strong');
                    var url = document.createElement('a');
                    url.href = links[i].getAttribute('url');
                    url.appendChild(document.createTextNode(links[i].getAttribute('url')));
                    emphasis.appendChild(url);
                    item.appendChild(emphasis);
                    item.appendChild(document.createTextNode(' – '));
                    item.innerHTML += links[i].getAttribute('description');
                    list.appendChild(item);
                }

                container.insertBefore(entry, container.firstChild);
            }
        }
    };
    xmlhttp.open('POST', '<?php echo $_SERVER['SCRIPT_NAME'] ?>', true);
    xmlhttp.setRequestHeader('Content-Type', 'application/x-www-form-urlencoded');
    xmlhttp.send('postId=' + encodeURIComponent(id));
}

function checkPost(id)
{
    setTimeout(function () {
        doCheckPost(id);
    }, interval += 350);
}

for (var post in posts) {
    checkPost(posts[post], post);
}
</script>
