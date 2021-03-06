<?php
//  AcmlmBoard XD - User account registration page
//  Access: any, but meant for guests.
if (!defined('BLARG')) die();

require_once('lib/recaptchalib.php');

//Recaptcha secret key
$secret = "6LcBPSMUAAAAACx4ecmU9u8Eru_O60lInlqkPaQp";

//Empty response
$response = null;

//Check the secret key
$reCaptcha = new ReCaptcha($secret);

$title = __("Register");
MakeCrumbs(array(actionLink("register") => __("Register")));

print "<script src='https://www.google.com/recaptcha/api.js'></script>";

//If submitted check the reponse
if($_POST['g-recaptcha-response']) {
    $response = $reCaptcha->verifyResponse(
        $_SERVER['REMOTE_ADDR'],
        $_POST['g-recaptcha-response']
    );
}

$sexes = array(__("Male"), __("Female"), __("N/A"));

if($_POST['register']) {
	if (IsProxy()) {
		$adminemail = Settings::get('ownerEmail');
		if ($adminemail) $halp = '<br><br>If you aren\'t using a proxy, contact the board owner at: '.$adminemail;
		else $halp = '';
		
		$err = __('Registrations from proxies are not allowed. Turn off your proxy and try again.'.$halp);
	} else {
		$name = $_POST['name'];
		$cname = trim(str_replace(" ","", strtolower($name)));

		$rUsers = Query("select name, displayname from {users}");
		while($user = Fetch($rUsers)) {
			$uname = trim(str_replace(" ", "", strtolower($user['name'])));
			if($uname == $cname)
				break;
			$uname = trim(str_replace(" ", "", strtolower($user['displayname'])));
			if($uname == $cname)
				break;
		}

		$ipKnown = FetchResult("select COUNT(*) from {users} where lastip={0}", $_SERVER['REMOTE_ADDR']);

		if (stripos($_POST['email'], '@dispostable.com') !== FALSE)
			$err = __('Registration failed. Try again later.');
		else if (!$cname)
			$err = __('Enter a username and try again.');
		elseif($uname == $cname)
			$err = __("This user name is already taken. Please choose another.");
		elseif($ipKnown >= 3)
			$err = __("Another user is already using this IP address.");
		else if ($response == null)
            $err = __('You forgot to do the Captcha.');
		else if(!$_POST['readFaq'])
			$err = format(__("You really should {0}read the FAQ{1}&hellip;"), "<a href=\"".actionLink("faq")."\">", "</a>");
		else if ($_POST['likesCake'])
			$err = __("Robots not allowed.");
		else if(strlen($_POST['pass']) < 4)
			$err = __("Your password must be at least four characters long.");
		else if ($_POST['pass'] !== $_POST['pass2'])
			$err = __("The passwords you entered don't match.");
	}

	if($err) {
		Alert($err, __('Error'));
	} elseif ($response->success) {
		$newsalt = Shake();
		$sha = doHash($_POST['pass'].SALT.$newsalt);
		$uid = FetchResult("SELECT id+1 FROM {users} WHERE (SELECT COUNT(*) FROM {users} u2 WHERE u2.id={users}.id+1)=0 ORDER BY id ASC LIMIT 1");
		if($uid < 1) $uid = 1;

		$rUsers = Query("insert into {users} (id, name, password, pss, primarygroup, regdate, lastactivity, lastip, email, sex, theme) values ({0}, {1}, {2}, {3}, {4}, {5}, {5}, {6}, {7}, {8}, {9})", 
			$uid, $_POST['name'], $sha, $newsalt, Settings::get('defaultGroup'), time(), $_SERVER['REMOTE_ADDR'], $_POST['email'], (int)$_POST['sex'], Settings::get("defaultTheme"));

		Report("New user: [b]".$_POST['name']."[/] (#".$uid.") -> [g]#HERE#?uid=".$uid);

		$user = Fetch(Query("select * from {users} where id={0}", $uid));
		$user['rawpass'] = $_POST['pass'];

		if ($email != '') {
			$gravatar = SqlEscape('https://www.gravatar.com/avatar/' . md5(strtolower(trim($_POST['email']))) . '?s=128');

			// Save the gravatar to DB
			Query("UPDATE {users} SET `picture`={0} WHERE `id`={1}", $gravatar, $uid);
		}

		$bucket = "newuser"; include(BOARD_ROOT."lib/pluginloader.php");

		$rLogUser = Query("select id, pss, password from {users} where 1");
		$matches = array();

		while($testuser = Fetch($rLogUser))
		{
			if($testuser['id'] == $user['id'])
				continue;

			$sha = doHash($_POST['pass'].SALT.$testuser['pss']);
			if($testuser['password'] === $sha)
				$matches[] = $testuser['id'];
		}
		
		if (count($matches) > 0)
			Query("INSERT INTO {passmatches} (date,ip,user,matches) VALUES (UNIX_TIMESTAMP(),{0},{1},{2})", $_SERVER['REMOTE_ADDR'], $user['id'], implode(',',$matches));
		
		// mark threads older than 15min as read
		Query("INSERT INTO {threadsread} (id,thread,date) SELECT {0}, id, {1} FROM {threads} WHERE lastpostdate<={2} ON DUPLICATE KEY UPDATE date={1}", $uid, time(), time()-900);


		if($_POST['autologin']) {
			$sessionID = Shake();
			setcookie("logsession", $sessionID, 0, URL_ROOT, "", false, true);
			Query("INSERT INTO {sessions} (id, user, autoexpire) VALUES ({0}, {1}, {2})", doHash($sessionID.SALT), $user['id'], 0);
			die(header("Location: ".actionLink('profile', $user['id'], '', $user['name'])));
		} else
			die(header("Location: ".actionLink("login")));
	}
} else {
	$_POST['name'] = '';
	$_POST['email'] = '';
	$_POST['sex'] = 2;
	$_POST['autologin'] = 0;
}

$fields = array(
	'username' => "<input type=\"text\" name=\"name\" maxlength=20 size=24 value=\"".htmlspecialchars($_POST['name'])."\" class=\"required\">",
	'password' => "<input type=\"password\" name=\"pass\" size=24 class=\"required\">",
	'password2' => "<input type=\"password\" name=\"pass2\" size=24 class=\"required\">",
	'email' => "<input type=\"email\" name=\"email\" value=\"".htmlspecialchars($_POST['email'])."\" maxlength=\"60\" size=24>",
	'sex' => MakeOptions("sex",$_POST['sex'],$sexes),
	'readfaq' => "<label><input type=\"checkbox\" name=\"readFaq\">".format(__("I have read the {0}FAQ{1}"), "<a href=\"".actionLink("faq")."\">", "</a>")."</label>",
	'autologin' => "<label><input type=\"checkbox\" checked=\"checked\" name=\"autologin\"".($_POST['autologin']?' checked="checked"':'').">".__("Log in afterwards")."</label>",
	
	'btnRegister' => "<input type=\"submit\" name=\"register\" value=\"".__("Register")."\">",
);

echo "<form action=\"".htmlentities(actionLink("register"))."\" method=\"post\">";

RenderTemplate('form_register', array('fields' => $fields));

echo "<span style=\"display : none;\"><input type=\"checkbox\" name=\"likesCake\"> I am a robot</span></form>";


function MakeOptions($fieldName, $checkedIndex, $choicesList)
{
	$checks[$checkedIndex] = " checked=\"checked\"";
	foreach($choicesList as $key=>$val)
		$result .= format("
					<label>
						<input type=\"radio\" name=\"{1}\" value=\"{0}\"{2}>
						{3}
					</label>", $key, $fieldName, $checks[$key], $val);
	return $result;
}

function IsProxy() {
	if ($_SERVER['HTTP_X_FORWARDED_FOR'] && $_SERVER['HTTP_X_FORWARDED_FOR'] != $_SERVER['REMOTE_ADDR'])
		return true;

	$result = QueryURL('http://www.stopforumspam.com/api?ip='.urlencode($_SERVER['REMOTE_ADDR']));
	if (!$result)
		return false;

	if (stripos($result, '<appears>yes</appears>') !== FALSE)
		return true;

	return false;
}

?>
