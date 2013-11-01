<?php
/**
 * WordPress Link Checker
 * 
 * @package wordpress-link-checker
 * @license http://wtfpl.net/about WTFPL
 * @link    http://winek.tk
 * @version 0.2.1
 * @author  Olgierd „winek” Grzyb <hintpl@gmail.com>
 */

/**
 * Finds URLs in a post body.
 * @param string $text Post body
 * @return array multi-dimensional array of found URLs. Each item is another array, where the URL string is at 0 index.
 */
function find_links($text)
{
	if (preg_match_all('$\bhttps?://[-A-Z0-9+&@#/%?=~_|!:,.;]*[-A-Z0-9+&@#/%=~_|]$i', $text, $m, PREG_SET_ORDER)) {
		return array_unique(array_map(create_function('$m', 'return $m[0];'), $m));
	}
	return array();
}

/**
 * Checks links in the given text.
 * @param string $text post body
 * @return array of [LinkStatus] instances
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
 * Checks the status for a given link.
 * @param string $url
 * @return LinkStatus
 */
function check_status($url)
{
	global $stats; // Yes. Global variables and ugly, evil and bad.
	
	++$stats['all'];
	
	$status = new LinkStatus;
	$status->url = $status->actual_url = $url;
	
	$curl = curl_init();
	curl_setopt_array($curl, array(
		CURLOPT_URL            => $url,
		CURLOPT_RETURNTRANSFER => true,
		CURLOPT_SSL_VERIFYPEER => false,
	));
	
	if (!ini_get('open_basedir')) {
		// cURL won't let us set this option if open_basedir is set
		curl_setopt($curl, CURLOPT_FOLLOWLOCATION, true);
	}
	
	$ret = curl_exec($curl);
	
	if (!$ret) {
		// Failed, returning status as is
		$status->error = curl_error($curl);
		$status->errno = curl_errno($curl);
		return $status;
	}
	
	preg_match('#<title>(.*?)</title>#is', $ret, $m);
	if (!empty($m[1])) {
		$status->title = html_entity_decode($m[1]);
	}
	
	$status->code = curl_getinfo($curl, CURLINFO_HTTP_CODE);
	
	if ($status->code >= 400) {
		++$stats['broken'];
	} else {
		++$stats['working'];
	}
	
	$status->actual_url = curl_getinfo($curl, CURLINFO_EFFECTIVE_URL);
	
	curl_close($curl);
	return $status;
}

$stats = array(
	'all' => 0,
	'broken' => 0,
	'working' => 0,
);

/**
 * Translation table for supported languages.
 * @var array
 */
$translations = array(
	'pl' => array(
		'Database configuration' => 'Ustaw bazę danych',
		'DB name' => 'Nazwa bazy danych',
		'Server' => 'Serwer',
		'usually' => 'zwykle',
		'Username' => 'Nazwa użytkownika',
		'Password' => 'Hasło',
		'We couldn\'t find information about your blog database. Please enter missing data into the form below.' => 'Nie możemy znaleźć konfiguracji Twojej bazy danych. Wpisz odpowiednie dane do formularza poniżej.',
		'Table prefix' => 'Prefiks',
		'Next' => 'Dalej',
		
		'Error: %s' => 'Błąd: %s',
		' (redirected to %s)' => ' (przekierowanie do %s)',
		'No page under this URL.' => 'Nie znaleziono strony',
		'The page probably exists, but we don\'t have permission to see it.' => 'Strona istnieje, ale nie mamy do niej uprawnień',
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
		
	),
	'en' => array(),
);

/**
 * Describes status for a single link. Stores the title (if the page could be retrieved),
 * the status code and the URL itself.
 * If the page did redirect us somewhere else, the the actual_url property is also stored
 * for statistical purposes.
 */
class LinkStatus
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
						return trans('Server not found');
					default:
						return trans('Error: %s', $this->error);
				}
			case 200:
				return trans('OK') . ($this->title ? ' (' . $this->title . ')' : '') . (($this->actual_url != $this->url) ? trans(' (redirected to %s)', '<a href="' . htmlspecialchars($this->actual_url, ENT_HTML5 | ENT_QUOTES) . '">' . htmlspecialchars($this->actual_url) . '</a>') : '');
			case 404:
				return trans('No page under this URL.');
			case 403:
				return trans('The page probably exists, but we don\'t have permission to see it.');
			default:
				return trans('Unknown status.');
		}
		
		// catches other cases than 403-4
		if ($this->code >= 400) {
			return trans('Error %i %s', $this->code, trans(self::$messages[$this->code]));
		}
	}
	
	/**
	 * @return bool whether the link is loading properly
	 */
	public function good()
	{
		return $this->code == 200;
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

$stylesheet = 'html {
    color: #666;
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
	font-family: \'Liberation Sans\', Arial, Helvetica, sans-serif;
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
';

/**
 * Detects the client language.
 * Stolen from somewhere on the Internet.
 * @param string sDefault default language name
 * @param array languages
 * @return string
 */
function get_language($sDefault = 'en', $ihSystemLang)
{
	$sLangs = $_SERVER['HTTP_ACCEPT_LANGUAGE'];
	preg_match_all(
	'!([a-zA-Z]+)(?:-[a-zA-Z]+)?(?: *; *q *= *([01]\.[0-9]+))?!',
	$sLangs, $shFound);

	foreach ($shFound[1] as $i => $sLang) {
		$iW = (float)$shFound[2][$i];
		$ihUserLang[$sLang] = $iW > 0 ? $iW : 1;
	}
	$iChoiceWeight = 0;
	$sChoiceLang = '';
	foreach ($ihSystemLang as $sLang => $iW) {
		if (isset($ihUserLang[$sLang])) {
			$iTmpChoice = $iW * $ihUserLang[$sLang];

			if ($iTmpChoice > $iChoiceWeight and $iTmpChoice > 0) {
				$iChoiceWeight = $iTmpChoice;
				$sChoiceLang = $sLang;
			}
		}
	}

	return $sChoiceLang != '' ? $sChoiceLang : $sDefault;
}

/**
 * Translates a phrase and sends to stdout.
 * @param string $phrase
 */
function t($phrase)
{
	global $lang, $translations;
	$args = func_get_args(); // [0] is $phrase
	
	if (isset($translations[$lang][$args[0]])) {
		$args[0] = $translations[$lang][$args[0]];
	}
	
	echo htmlspecialchars(call_user_func_array('sprintf', $args)); // Yep, bad hack :3
}

/**
 * Translates a phrase and sends to stdout.
 * @param string $phrase
 */
function trans($phrase)
{
	global $lang, $translations;
	$args = func_get_args(); // [0] is $phrase
	
	if (isset($translations[$lang][$args[0]])) {
		$args[0] = $translations[$lang][$args[0]];
	}
	
	return call_user_func_array('sprintf', $args); // Yep, bad hack :3
}

$lang = get_language('en', array('en' => 1, 'pl' => 0.8));
@set_time_limit(0);

if (file_exists('wp-config.php')) {
	require_once 'wp-config.php';
	$db = new PDO('mysql:host=' . DB_HOST . ';dbname=' . DB_NAME, DB_USER, DB_PASSWORD);
	$prefix = $table_prefix;
} elseif (
	!empty($_POST['db_host']) &&
	!empty($_POST['db_name']) &&
	!empty($_POST['db_user']) &&
	isset($_POST['db_password']) &&
	isset($_POST['db_prefix'])
) {
	$db = new PDO('mysql:host=' . $_POST['db_host'] . ';dbname=' . $_POST['db_name'], $_POST['db_user'], $_POST['db_password']);
	$prefix = $_POST['db_prefix'];
} else {
?>
<!DOCTYPE html>
<meta charset="UTF-8">
<title><?php t('Database configuration') ?> – WordPress Link Checker</title>
<style><?php echo $stylesheet ?></style>
<h1><?php t('Database configuration') ?> – WordPress Link Checker</h1>
<p><?php t('We couldn\'t find information about your blog database. Please enter missing data into the form below.') ?></p>
<form method="POST">
	<fieldset>
		<legend><?php t('Database configuration') ?></legend>
		<dl>
			<dt><label for="host"><?php t('Server') ?>: (<?php t('usually') ?>: <code>localhost</code>)</label></dt>
			<dd><input type="text" id="host" name="db_host" autofocus></dd>
			<dt><label for="db"><?php t('DB name') ?>:</label></dt>
			<dd><input type="text" id="db" name="db_name"></dd>
			<dt><label for="user"><?php t('Username') ?>:</label></dt>
			<dd><input type="text" id="user" name="db_user"></dd>
			<dt><label for="password"><?php t('Password') ?>:</label></dt>
			<dd><input type="password" id="password" name="db_password"></dd>
			<dt><label for="prefix"><?php t('Table prefix') ?>:</label></dt>
			<dd><input type="text" id="prefix" name="db_prefix" value="wp_"></dd>
		</dl>
		<input type="submit" value="<?php t('Next') ?> »">
	</fieldset>
</form>
<?php
	exit;
}

class Post
{
	public $content;
	public $url;
	public $title;
	public $links;
	public $id;
}

$db->setAttribute(PDO::ATTR_ERRMODE, PDO::ERRMODE_EXCEPTION);
// TODO: include <select> with character set to satisfy everyone :D
$db->query('SET NAMES ' . (defined('DB_CHARSET') ? DB_CHARSET : 'utf8'));
$query = $db->query('SELECT `post_content`, `post_title`, `ID`, `guid` FROM `' . $prefix . 'posts` WHERE `post_type` = \'post\' AND `post_status` = \'publish\' ORDER BY `post_date` DESC');
$posts = array();

while ($row = $query->fetch(PDO::FETCH_OBJ)) {
	$links = check_links($row->post_content);
	
	if (count($links) > 0) {
		$post = $posts[$row->ID] = new Post;
		$post->title = $row->post_title;
		$post->url = $row->guid;
		$post->id = $row->ID;
		$post->content = $row->post_content;
		$post->links = $links;
	}
}

?>
<!DOCTYPE html>
<meta charset="UTF-8">
<title>WordPress Link Checker</title>
<style><?php echo $stylesheet ?></style>
<h1>WordPress Link Checker</h1>
<p><strong><?php t('Stats:') ?></strong> <?php echo $stats['all'] ?> <?php t('all') ?>, <?php echo $stats['working'] ?> <?php t('working') ?>, <?php echo $stats['broken'] ?> <?php t('broken') ?></p>
<p><?php t('Display:') ?> <input type="button" id="see-all" class="button primary pressed" value="<?php t('All') ?>"> <input type="button" id="see-working" class="button" value="<?php t('Working') ?>"> <input type="button" class="button" id="see-broken" value="<?php t('Broken') ?>"></p>
<?php foreach ($posts as $post): ?>
<article>
<h2>» <a href="<?php echo $post->url ?>"><?php echo htmlspecialchars($post->title, ENT_NOQUOTES | ENT_HTML5) ?></a></h2>
<ul>
<?php foreach ($post->links as $link): ?>
	<li class="link <?php echo $link->good() ? 'working' : 'broken' ?>"><a href="<?php echo htmlspecialchars($link->url) ?>"><strong><?php echo htmlspecialchars(str_replace(array('https://', 'http://'), '', $link->url)), '</strong></a>: ', $link->describe() ?></li>
<?php endforeach ?>
</ul>
</article>
<?php endforeach ?>
<script>
var buttons = {};
var filterState = 'all';
buttons.all = document.getElementById('see-all');
buttons.working = document.getElementById('see-working');
buttons.broken = document.getElementById('see-broken');

function showAll(className) {
	for (var key in all = document.getElementsByClassName(className)) {
		if (!isNaN(key)) {
			all[key].style.display = 'list-item';
		}
	}
}
function hideAll(className) {
	for (var key in all = document.getElementsByClassName(className)) {
		if (!isNaN(key)) {
			all[key].style.display = 'none';
		}
	}
}
function switchButtons(newState) {
	document.getElementById('see-' + filterState).className = document.getElementById('see-' + filterState).className.replace(/\s*pressed/, '');
	document.getElementById('see-' + (filterState = newState)).className += ' pressed';
}

buttons.all.onclick = function() {
	showAll('link');
	switchButtons('all');
};
buttons.working.onclick = function() {
	showAll('working');
	hideAll('broken');
	switchButtons('working');
};
buttons.broken.onclick = function() {
	hideAll('working');
	showAll('broken');
	switchButtons('broken');
};

for (var key in hdrs = document.getElementsByTagName('h2')) {
	if (isNaN(key)) {
		continue;
	}
	hdrs[key].style.cursor = 'pointer';
	hdrs[key].onclick = function(e) {
		e = e || window.event;
		if (this.parentNode.getElementsByTagName('ul')[0].style.display != 'none') {
			this.parentNode.getElementsByTagName('ul')[0].style.display = 'none';
		} else {
			this.parentNode.getElementsByTagName('ul')[0].style.display = 'block';
		}
	};
}
</script>