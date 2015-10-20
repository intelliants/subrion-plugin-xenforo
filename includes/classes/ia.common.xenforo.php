<?php
//##copyright##

// define absolute path for xenForo installation
define('IA_XF_PATH', 'PUT_YOUR_FORUMS_ABSOLUTE_PATH_HERE');
define('IA_XF_SALT', 'PUT_YOUR_FORUMS_API_KEY_HERE');
define('IA_XF_API', 'http://PUT_YOUR_FORUMS_URL_HERE/api.php');

class iaXFBridge
{
	public $hash = false;

	public function __construct()
	{
		if (!defined('XENFORO_AUTOLOADER_SETUP'))
		{
			$this->_initialize();
		}
	}

	private function _initialize()
	{
		require IA_XF_PATH . '/library/XenForo/Autoloader.php';

		XenForo_Autoloader::getInstance()->setupAutoloader(IA_XF_PATH . '/library');

		$startTime = microtime(true);
		XenForo_Application::initialize(IA_XF_PATH . '/library', IA_XF_PATH);
		XenForo_Application::set('page_start_time', $startTime);
		XenForo_Application::disablePhpErrorHandler();

		// start xenForo session
		XenForo_Session::startPublicSession();

		// turn off the strict error reporting.
		error_reporting(E_ALL & ~E_NOTICE);

		restore_error_handler();
		restore_exception_handler();
	}

	private function _restRequest(array $params)
	{
		if (!is_array($params))
		{
			return false;
		}

		$request = array();
		foreach ($params as $key => $value)
		{
			$request[] = $key . '=' . urlencode($value);
		}

		$contents = file_get_contents(IA_XF_API . '?' . implode('&', $request));
		if ($contents)
		{
			$json = json_decode($contents, true);

			return $json;
		}
	}

	private function _restGetHash(array $account)
	{
		if ($this->hash)
		{
			return $this->hash;
		}

		// send authenticate request
		$account['action'] = 'authenticate';
		$result = $this->_restRequest($account);

		if (isset($result['hash']))
		{
			$this->hash = $result['hash'];
		}

		return $this->hash;
	}

	public function userRegister(array $account)
	{
		// create new user
		$writer = XenForo_DataWriter::create('XenForo_DataWriter_User');

		// set all the values
		$writer->set('username', $account['username']);
		$writer->set('email', $account['email']);
		$writer->setPassword($account['password'], $account['password']);

		// if the email corresponds to an existing Gravatar, use it
		$options = XenForo_Application::get('options');
		if ($options->gravatarEnable && XenForo_Model_Avatar::gravatarExists($account['email']))
		{
			$writer->set('gravatar', $account['email']);
		}

		$writer->set('user_group_id', XenForo_Model_User::$defaultRegisteredGroupId);

		// save user
		$writer->save();
	}

	public function userLogin(array $account)
	{
		if ($this->_restGetHash($account))
		{
			$userParams = array(
				'action' => 'getUser',
				'value' => $account['username'],
				'hash' => $account['username'] . ':' . $this->hash
			);

			// get user information
			$userInfo = $this->_restRequest($userParams);
			if ($userInfo)
			{
				$dbLoginModel = XenForo_Model::create('XenForo_Model_Login');
				$dbUserModel = XenForo_Model::create('XenForo_Model_User');

				$userEmail = isset($userInfo['email']) ? $userInfo['email'] : $account['email'];
				$iUserID = $dbUserModel->validateAuthentication($userEmail, $account['password']);
				if (!$iUserID)
				{
					$dbLoginModel->logLoginAttempt($userInfo['email']);
				}
				else
				{
					$dbLoginModel->clearLoginAttempts($userInfo['email']);

					// user ID as logged in
					$dbUserModel->setUserRememberCookie($iUserID);

					XenForo_Model_Ip::log($iUserID, 'user', $iUserID, 'login');

					$dbUserModel->deleteSessionActivity(0, $_SERVER['REMOTE_ADDR']);

					$cSession = XenForo_Application::get('session');
					$cSession->changeUserId($iUserID);
					XenForo_Visitor::setup($iUserID);

					return true;
				}
			}
		}

		return false;
	}

	public function userLogout()
	{
		if (XenForo_Visitor::getInstance()->get('is_admin'))
		{
			$adminSession = new XenForo_Session(array('admin' => true));
			$adminSession->start();
			if ($adminSession->get('user_id') == XenForo_Visitor::getUserId())
			{
				$adminSession->delete();
			}
		}

		XenForo_Model::create('XenForo_Model_Session')->processLastActivityUpdateForLogOut(XenForo_Visitor::getUserId());
		XenForo_Application::get('session')->delete();
		XenForo_Helper_Cookie::deleteAllCookies(
			array('session'),
			array('user' => array('httpOnly' => false))
		);
		XenForo_Visitor::setup(0);

		return;
	}

	public function userUpdateInfo(array $account, array $params)
	{
		$userParams = array(
			'action' => 'editUser',
			'hash' => IA_XF_SALT,
			'user' => $account['username']
		);

		$allowedFields = array('email', 'username', 'password');
		foreach ($params as $key => $value)
		{
			if (in_array($key, $allowedFields))
			{
				$userParams[$key] = $value;
			}
		}

		// update user information
		$this->_restRequest($userParams);
	}

	public function userDelete(array $account)
	{

	}
}