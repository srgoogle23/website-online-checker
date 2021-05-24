<?php
// ============ CONFIG ============

$url_list	= 'new_list.txt'; // this is DEFAULT list of URLs. But you can either:
                                       //   1. provide the filename in $_GET['parameter'] calling "yourhost.com/path/blog_report_php?list=[FILENAME.TXT]", OR
                                       //   2. on call by crontab provide filename as first argument: "php /path/to/script/blog_report.php FILENAME.TXT".

$report_subject	= 'Webpage status report';
$report_email	= '';
$admin_email	= 'leonardo.oliveira@ecomd.com.br'; // email from which email sent

$required_head_keyword	= '<meta name="robots"'; // Keyword required on the web page. Without ">" at the end. Some webmasters close tags with >, some with />.
$email_errors_only	= true; // TRUE to email report only with errors. When host unreachable, 4xx/5xx HTTP statuses, or required keyword not found. FALSE -- send full report of statuses and redirections.

$connect_timeout	= 5;    // timeout in seconds required server to respond. No response in specified number of seconds = Host unreachable.
                                // SUGGESTION: don't setup more than 5 seconds. 5 seconds should be enough to connect.
                                // NOTE: this is only timeout for the first connections. For reading of content there is other timeouts.
$use_file_get_contents	= false; // FALSE if we use TCP-sockets if "file_get_contents()" doesn't works.
$use_output_buffering	= false; // FALSE if we don't want output buffering. (Let's display output imediately line by line.)
                                 // ...also, if you're using Nginx, "proxy_buffering" should be disabled. (Set "proxy_buffering off" in "nginx.conf".)

$show_as_table		= 1; // otherwise show in simple list
$show_title_descr	= 1;
$show_char_table	= 1;
$show_wordpress_version	= 1;

// miscellaneous
$use_open_ssl		= 1; // add ssl:// prefix in @fsockopen(), only if OpenSSL installed. But without SSL we can't read anything by HTTPS protocol.
$http_request_headers	= "User-Agent: Mozilla/5.0 (Windows NT 10.0; Win64; x64) AppleWebKit/537.36 (KHTML, like Gecko) Chrome/71.0.3578.98 Safari/537.36\r\n".
                          "Accept: text/html,application/xhtml+xml,application/xml;q=0.9,image/webp,image/apng,*/*;q=0.8\r\n".
                          "Accept-Encoding: gzip, deflate\r\n".
                          "Accept-Language: en-US,en;q=0.9\r\n";

// mySQL db (requires utilmind's "mysql.php")
$mute_same_errors	= 0; // (minutes. eg 60 * 24 = 24 hours) // don't inform about the same error for specified number of of minutes, or 0 -- don't mute errors.
$db_connect		= ['auto'   => 0, // auto-connect on include mysql.php (no, we don't want to auto-connect)
                           'host'   => 'localhost',
                           'user'   => 'admin',
                           'pass'   => '******',
                           'dbname' => 'mydbname',
                           'table'  => 'url_checker_mute', // table has 2 parameters: url: char(255, ascii_general, primary key, not null), time: datetime (not null).
                           'stub_on_fail' => false];


// ============== GO ==============
if ($mute_same_errors)
  require_once(dirname(__DIR__).'/mail/mysql.php');


function print_buffer() {
  $buf = ob_get_contents();
  ob_end_clean();
  print $buf;
  flush();

  return $buf;
}

if (!function_exists('http_chunked_decode')) {
  function http_chunked_decode($str) {
    for ($res=''; !empty($str); $str = trim($str)) {
      $pos = strpos($str, "\r\n");
      $len = hexdec(substr($str, 0, $pos));
      $res.= substr($str, $pos + 2, $len);
      $str = substr($str, $pos + 2 + $len);
    }
    return $res;
  }
}

function get_redirect_url($url) {
  global $use_open_ssl, $connect_timeout, $http_request_headers;

  $url_parts = @parse_url($url);
  if (!$url_parts) return 'Bad URL.';
  if (!isset($url_parts['host'])) return 'Can\'t serve relative URL without protocol.';
  if (!isset($url_parts['path'])) $url_parts['path'] = '/';

  $port = isset($url_parts['port']) ? (int)$url_parts['port'] : 0;
  $is_ssl = (isset($url_parts['scheme']) && $url_parts['scheme'] === 'https') || ($port === 443);
  if (!$port)
    $port = $is_ssl ? 443 : 80;

  if (!$sock = @fsockopen(($use_open_ssl && $is_ssl ? 'ssl://' : '').$url_parts['host'], $port, $errno, $errstr, $connect_timeout))
    return 'Host unreachable.';

  // Set timout to 1 second (https://stackoverflow.com/questions/10449540/reading-data-from-@fsockopen-using-fgets-fread-hangs)
  if (!stream_set_timeout($sock, 1)) die('Could not set stream timeout.');

  $out = $url_parts['path'].(isset($url_parts['query']) ? '?'.$url_parts['query'] : '');
  $out = "HEAD $out HTTP/1.1\r\n".
         "Host: $url_parts[host]\r\n".
         $http_request_headers.
         "Connection: Close\r\n\r\n";
  fwrite($sock, $out);
  $in = '';
  while (!feof($sock)) $in.= fgets($sock, 8192); // if this will not work, try to use it without feof(). Just get data while its possible.
  fclose($sock);

  $code = 0;
  if (preg_match('/^HTTP\/[\d\.]+\s+(\d+)/m', $in, $m))
    $code = (int)$m[1];

  $redirect = false;
  if (preg_match('/^Location: (.+?)$/m', $in, $m) && isset($m[1]) && $m[1]) {
    if ($m[1][0] == '/')
      $redirect = $url_parts['scheme'].'://'.$url_parts['host'];
    $redirect.= trim($m[1]);
  }

  return array($code, $redirect);
}

function get_all_redirects($url) { // get all http status codes and redirect urls
  $cnt = 0;
  $http_redirects = array();
  while ($url && ($new_url = get_redirect_url($url)) && is_array($new_url)) {
    if ($cnt > 8) break; // TODO: give Too many redirects error! (����� �����������, ����� ��������� �������� � http �� https � � https �� http.)
    ++$cnt;
    if ($new_url[1] && in_array($new_url[1], $http_redirects))
      break;
    $http_redirects[] = $new_url; // code+redirect
    $url = $new_url[1];
  }

  if (!is_array($new_url))
    return $new_url;

  return $http_redirects;
}

// used only if we use "file_get_contents()".
function get_http_error_code() {
  global $http_response_header;
  if (is_array($http_response_header))
    foreach ($http_response_header as $a)
      if ($a) {
        // This is how to generate HTTP status code only...
        $a = explode(':', $a, 2);
        if (!isset($a[1]) && preg_match('/HTTP\/[\d\.]+\s+(\d+)/', $a[0], $a))
          return (int)$a[1]; // pass HTTP code we got to
      }
  return false;
}

function get_attr_content($str, $attr = false) {
  if (!$attr) $attr = 'content';
  return preg_match("/$attr=\s*('|\")(.*?)\\1/is", $str, $m) && isset($m[2]) ? trim($m[2]) : false;
}

// returns "content" of the meta tag with specified "name" attribute.
// If $attr is false, it returns the content of attribute specified by $attr_name.
function parse_meta($html, $attr, $attr_name = false) {
  $r = '';
  $attr_cont = $attr ? preg_quote($attr, '/') : '(.*?)';
  $attr_name = $attr_name ? preg_quote($attr_name, '/') : 'name';

  if (preg_match("/<meta\s+([^>]*?)$attr_name=\s*?(\"|')?$attr_cont\\2([^>]*?)>/is", $html, $m)) {
    if (!$attr)
      $r = trim($m[3]);

    // result either in $m[1] or $m[3].
    elseif (!$r = get_attr_content($m[1]))
      $r = get_attr_content($m[3]);
  }
  return $r;
}

function get_tag_inner_html($html, $tag) {
  return preg_match("/<$tag(\s.*?)?>(.+?)<\/$tag>/is", $html, $m) || !isset($m[2]) ? trim($m[2]) : false;
}

function fetch_and_parse_page($url) {
  global $use_open_ssl, $use_file_get_contents, $http_response_header, $http_request_headers,
         $connect_timeout, $required_head_keyword;

  // retrieving remote HTML in proper way...
  if ($use_file_get_contents) {

    // prepading request
    $opts = [
      'http' => [
        'header' => $http_request_headers,
      ]
    ];
    $http_response_header = false;
    if (!$html = @file_get_contents($url, false, stream_context_create($opts))) {
      return ($http_error = get_http_error_code()) ?
        sprintf('HTTP error #%s.', get_http_error_code($http_error)) : 'Host unreachable on file_get_contents().';
    }

  }else {
    $url_parts = @parse_url($url);
    if (!$url_parts) return 'Bad URL.';
    if (!isset($url_parts['host'])) return 'Can\'t serve relative URL without protocol.';
    if (!isset($url_parts['path'])) $url_parts['path'] = '/';

    $port = isset($url_parts['port']) ? (int)$url_parts['port'] : 0;
    $is_ssl = (isset($url_parts['scheme']) && $url_parts['scheme'] == 'https') || ($port == 443);
    if (!$port)
      $port = $is_ssl ? 443 : 80;

    if (!$sock = @fsockopen(($use_open_ssl && $is_ssl ? 'ssl://' : '').$url_parts['host'], $port, $errno, $errstr, $connect_timeout))
      return 'Host unreachable on GET.'; // weird, because first probe was successful!

    // Set timout to 1 second (https://stackoverflow.com/questions/10449540/reading-data-from-@fsockopen-using-fgets-fread-hangs)
    if (!stream_set_timeout($sock, 1)) die('Could not set stream timeout.');

    $get = $url_parts['path'].(isset($url_parts['query']) ? '?'.$url_parts['query'] : '');
    $get = "GET $get HTTP/1.1\r\n".
           "Host: $url_parts[host]\r\n".
           $http_request_headers.
           "Connection: Close\r\n\r\n";
    fwrite($sock, $get);

    $html = '';
    while (!feof($sock)) $html.= fgets($sock, 8192); // if this will not work, try to use it without feof(). Just get data while its possible.

    fclose($sock);
  }

  if (!$html)
    return "Empty response. No data received.";

  // split header and content
  @list($header, $html) = explode(strpos($html, "\r\n\r\n") !== false ? "\r\n\r\n" : "\n\n", $html, 2); // malformed header with \n\n?

  if (!$html)
    return 'No content after HTTP header.';

  // if the content is chunked -- decode it.
  if (preg_match('/^transfer-encoding: (.+?)$/im', $header, $m) && isset($m[1]) && $m[1] &&
      (trim($m[1]) == 'chunked'))
    $html = http_chunked_decode($html);

  // if the content is gzipped -- ungzip it.
  if (preg_match('/^content-encoding: (.+?)$/im', $header, $m) && isset($m[1]) && $m[1] &&
      (trim($m[1]) == 'gzip'))
    $html = gzdecode($html);

  // getting the HEAD section.
  if (!preg_match('/<head(\s.*?)?>(.+?)(<\/head>|<body\s)/is', $html, $m) || !isset($m[2]))
    return 'No header section.';

  $head = $m[2];

  // first of all -- determinate the character set of incoming data. We looking both at <head> section of HTML and to HTTP response. <head> have higher priority.
  if ((!$charset = parse_meta($head, 'content-type', 'http-equiv')) &&
      (!$charset = parse_meta($head, false, 'charset'))) {
    // try to find in HTTP response...
    if (preg_match('/^content-type: (.+?)$/im', $header, $m) && isset($m[1]) && $m[1])
      $charset = trim($m[1]);
  }
  if ($charset) {
    if (strpos($charset, $i = 'charset=') !== false)
      list($i, $charset) = explode($i, $charset);
    if (strtolower($charset) == 'utf8') // fixing bad charcode
      $charset = 'utf-8';

    $is_utf8 = strtolower($charset) == 'utf-8';
  }else
    $is_utf8 = true; // actually we don't know the charcode, but don't need convert it.


  // title
  if (!$title = (preg_match('/<title>(.+?)<\/title>/si', $head, $m) && isset($m[1])) ? $m[1] : false)
    $title = parse_meta($head, 'og:title', 'property'); // check OpenGraph's title too.
  if ($title && !$is_utf8)
    $title = mb_convert_encoding($title, 'utf-8', $charset);

  // description. First looking for standard description, then into OpenGraph's og:description.
  if (!$description = parse_meta($head, 'description'))
    $description = parse_meta($head, 'og:description', 'property');
  if ($description && !$is_utf8)
    $description = mb_convert_encoding($description, 'utf-8', $charset);

  // Wordpress version
  if ($wordpress = parse_meta($head, 'generator'))
    if (($i = stripos($wordpress, ($j = 'wordpress'))) === 0)
      $wordpress = substr($wordpress, strlen($j)+1);
    else
      $wordpress = ''; // not Wordpress.

  $required_keyword_found = !$required_head_keyword ||
    (strpos($head, $required_head_keyword) > 0);

  // collecting the information to return
  return [
      'url'         => $url,
      'title'       => $title,
      'keywords'    => parse_meta($head, 'keywords'),
      'description' => $description,
      'charset'     => $charset,
      'wordpress'   => $wordpress,
      'req_keyword' => $required_keyword_found,
    ];
}

// ============ end of functions ==============


// Checking the arguments...
// If command line arguments is set -- processing CVS file.
if (isset($argv[1]) && file_exists($argv[1]))
  $url_list = $argv[1];
elseif (isset($_GET['list']) && ($i = $_GET['list']) && file_exists($i))
  $url_list = $i;



// PREPARING...

$mtime = microtime(1);

/*
// Let's test connection to localhost before checking any URLs. Internet connection by HTTP protocol must be present.
if (!$sock = @fsockopen(($use_open_ssl ? 'ssl://' : '').'localhost', $use_open_ssl ? 443 : 80, $errno, $errstr, $connect_timeout * 2)) { // twice of normal $connect_timeout!
  print 'Can\'t connect to localhost. Internet connection not present?'; // weird, because first probe was successful!
  exit;
}
fclose($sock); // close test connection gracefully.
*/

// Loading the input URLS from the XML file by name input_url.xml
if (!$url_list) {
  print 'No $url_list provided.'; // TODO: send these error messages by email in order to show errors when the task running on crontab/scheduler.
  exit;
}
if ($url_list[0] !== '/')
  $url_list = __DIR__.'/'.$url_list;
if (!$urls = file_get_contents($url_list)) {
  print 'Can\'t read file with URLs.';
  exit;
}

if ($urls[0] == '<') { // It's XML?
  if ((!$urls = simplexml_load_string($urls)) ||
      (!$urls = $urls->children())) {
    print 'Unable to read or parse XML file.';
    exit;
  }
}else
  $urls = explode("\n", $urls);


// disable cache
header("Cache-Control: no-store, no-cache, must-revalidate, max-age=0");
header("Cache-Control: post-check=0, pre-check=0", false);
header("Pragma: no-cache");
// set default socket timeout (but we also set it individually on each request)
ini_set('default_socket_timeout', $connect_timeout);
// Let's disable output buffers.
if (!$use_output_buffering) {
  ini_set('output_buffering', 0);
  ini_set('zlib.output_compression', 0);
  ini_set('session.use_trans_sid', 0);
  ob_implicit_flush(1);
  @ob_end_flush(); // it doesn't works (returns notice) on my local Windows PC, but required to start output without buffering

  /* All the ini_settings above still doesn't work on IIS servers (Windows), because IIS manages its own buffers.
     You need to create or modify web.config in the same folder where is your PHP running and add following lines:
     (Change php-7.3.7 and path to "php-cgi.exe" accordingly to your environment.)

<?xml version="1.0" encoding="UTF-8"?>
<configuration>
    <system.webServer>
        <handlers>
            <clear />
            <add name="php-7.3.7" path="*.php" verb="GET,HEAD,POST" modules="FastCgiModule" scriptProcessor="C:\Program Files\PHP\v7.3\php-cgi.exe" resourceType="Either" requireAccess="Script" responseBufferLimit="0" />
        </handlers>
    </system.webServer>
</configuration>
   */
}

// Let's go!! STARTING THE BUFFERED OUTPUT!
ob_start();
?>
<html>
<head>
<meta http-equiv="content-type" content="text/html; charset=UTF-8" />
<title><?=$report_subject?></title>
<!-- Don't move these styles to CSS. They required to send styled report by email. -->
<style>
* {
         box-sizing: border-box;
}
ol {
	text-align: left;
}
th {
	background-color: #EEE;
}
.cell_num {
	text-align: right;
}
.cell-charset,
.cell-wp-version {
	text-align: center;
	white-space: nowrap;
}
.descr {
	font-size: .8em;
	margin: .4em 0 0 20px;
}
.redir {
	font-size: .8em;
	padding: 2px 4px;
}
.error {
	background-color: #FFCECE; /* light red */
	font-weight: bold;
}
.warn {
	background-color: #FFFF9A; /* light yellow */
}
.no-keyword {
	margin-top: .5em;
	color: red;
	font-weight: bold;
}

.container_div {
	width: 85%;
	max-width: 1140px;
	margin: 0 auto;
	text-align: center;
}

.report_table {
	margin: 0 auto;
	width: 100%;
}

@media (max-width: 768px) {
	.container_div {
		width: 98%;
	}

	.report_table td.cell-url {
		border-bottom: 0;
	}
	.report_table td.cell-descr,
        .report_table td.error {
		border-top: 0;
	}

	.report_table th,
        .report_table td.cell-charset {
		display: none;
        }

	.report_table td.colm {
		display: block;
		width: 100% !important;
	}
}
</style>
<style class="strip_from_email">
div#in_progress::after {
	margin-top: 1.5em;
	display: block;
	content: url(./ani-fountain.svg);
}
</style>
</head>
<body>

<div class="container_div">
  <p><strong><?=$report_subject?></strong> for &ldquo;<strong><?=basename($url_list)?></strong>&rdquo;</p>
  <div id="in_progress">
<?php if ($show_as_table){?>
  <table cellspacing="0" cellpadding="3" border="1" class="report_table">
    <tr>
      <th width="1%" class="cell_num">#</th>
      <th>Website URL</th>
<?php if ($show_title_descr) {?>
      <th>Title &amp; Description</th>
<?php }if ($show_char_table) {?>
      <th>Chr</th>
<?php }if ($show_wordpress_version) {?>
      <th>WP&nbsp;ver</th>
<?php }?>
    </tr>
<?php
}else { // show as list
  print '<ol>';
}

$out_email_header = print_buffer(); // preparing report with errors

$out_email = ''; // walk through the records
$cnt = 0;
foreach ($urls as $url)
  if (($url = trim($url)) && ($url[0] !== ';')) { // each URL. Excluding "commented" ones, which statrs with ";".
    ++$cnt;
    set_time_limit(120); // +2 minutes before script timeout

    // looking for all redirects / retrieving the final destination...
    $redirects = '';
    if ((!$http_redirects = get_all_redirects($url)) || !is_array($http_redirects)) {
      $data = $http_redirects; // error message. Host unreachable etc.

    }else {
      $final_destination = $url;
      $http_error_code = false;
      foreach ($http_redirects as $key => $val) {
        if ($val[0] >= 400) {
          $http_error_code = $val[0];
          break;

        }elseif ($val[1]) {
          $redirects.= "<div><a href=\"$val[1]\">$val[1]</a> ($val[0])</div>";
          $final_destination = $val[1];
        }
      }

      // get and parse the website URL
      $data = fetch_and_parse_page($final_destination);
      // we need redirect URL too.
      if ($redirects)
        $redirects = "<div class=\"warn redir\">$redirects</div>";
    }

    $status_note = '';
    if ($is_success = is_array($data)) {
      if ($http_error_code) {
        $is_success = false;
        $status_note = "<div class=\"error\">HTTP error code #$http_error_code</div>";

      }
      /*
      elseif (!$data['req_keyword']) {
        $is_success = false;
        $status_note = '<div class="warn no-keyword">Required keyword not found!</div>';
      }
      */
    }else
      $status_note = '<div class="error">'.$data.'</div>';

    if ($show_as_table) {
      $line = <<<END
<tr>
  <td class="cell_num">$cnt</td>
  <td class="colm cell-url"><a href="$url">$url</a>$redirects</td>

END;
    if ($is_success = is_array($data)) { // successful HTTP status
      if ($show_title_descr) {
        $line.= <<<END
  <td class="colm cell-descr" style="background-color: #caffca">$data[title]<div class="descr">$data[description]</div>$status_note</td>
  <td class="cell-charset">$data[charset]</td>

END;
      }
      if ($show_wordpress_version) {
        $line.= <<<END
  <td class="cell-wp-version">$data[wordpress]</td>

END;
      }
    }else { // HTTP error or Host unreachable
      $line.= <<<END
  <td colspan="3" class="colm">$status_note</td>

END;
    }
    $line.= <<<END
</tr>

END;
    }else { // show as list
      $line = "<li><a href=\"$url\">$url</a>$status_note</li>\n";
    }
    print $line;
    flush();

    if (!$is_success || !$email_errors_only) {
      if ($mute_same_errors) {
        mdb::connect();
        // The time of mySQL server can be different from local time, so always use server's time.
        if (mdb::qquery("SELECT DATE_ADD(time, INTERVAL $mute_same_errors MINUTE)>CURRENT_TIMESTAMP AS not_expired
                         FROM $db_connect[table] WHERE url=".($quoted_url = mdb::quote($url)))) {
          continue; // skip it
        }else { // not in DB or expired. In either case add it to DB.
          mdb::rquery("INSERT INTO $db_connect[table] (url,time) VALUES($quoted_url, CURRENT_TIMESTAMP)
                       ON DUPLICATE KEY UPDATE time=CURRENT_TIMESTAMP");
          // ...and use the message in error report
        }
      }
      $out_email.= $line;
    }
  }

ob_start();
print $show_as_table ? '</table>' : '</ol>';
?>
  </div>
  <script class="strip_from_email">
    // <![CDATA[
    document.getElementById("in_progress").id = "";
    // ]]>
  </script>
  <p><i>Generated in <?=number_format((microtime(1) - $mtime), 2)?> seconds by &ldquo;<?=basename($_SERVER['SCRIPT_NAME'])?>&rdquo;.</i></p>
</div>

</body>
</html>
<?php

// Sending report via email...
if ($report_email && $out_email) {
  $out_email_footer = print_buffer();

  // strip some stuff which shouldn't ever be in email.
  $out_email = preg_replace('/<(\w+)\s+?[^>]*?class=\s*?("|\')strip_from_email\\2[^>]*?>(.*?)<\/\\1>/is', '', $out_email_header.$out_email.$out_email_footer);

  $email_headers = "From: $admin_email\r\n".
                   "CC: utilmind@gmail.com\r\n".
                   "MIME-Version: 1.0\r\n".
                   "Content-type: text/html; charset=utf-8\r\n".
                   "X-Priority: 1 (Highest)\r\n";

  if (mail($report_email, $report_subject, $out_email, $email_headers))
    print "Report mail has been successfully sent to $report_email.";
  else
    print 'Report mail failed.';

}//else
//  print 'No problems to report by email.';
